<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\Http\Parser;
use Crab\Engine\Http\Request;
use Crab\Engine\Server\HttpServer;
use Crab\Engine\WebSocket\Handshake;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\WriteResult;
use Crab\Engine\Server\WebSocketServer;
use Crab\Engine\WebSocket\Config;
use Crab\Engine\WebSocket\MessageType;
use Crab\Engine\WebSocket\WebSocketSession;
use Crab\Engine\EventLoop\RevoltEventLoop;
use Crab\Engine\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Closure;
use Crab\Engine\EventLoop;
use Crab\Engine\Suspension;

final class WebSocketTest extends TestCase
{
    public function testUpgradeTransfersUnreadBytesOnceAndOrdersHandshakeBeforeFrames(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $messages = [];
        $opened = 0;
        $subprotocol = null;
        $server = new WebSocketServer(
            static function (WebSocketSession $session, string $message, MessageType $type) use (&$messages): void {
                $messages[] = [$message, $type];
                $session->sendText($message);
            },
            static function (WebSocketSession $session) use (&$opened, &$subprotocol): void {
                $opened++;
                $subprotocol = $session->subprotocol;
                $session->sendText('ready');
            },
            subprotocols: ['chat'],
        );
        $server->attach($connection);
        $request = self::handshake(['Sec-WebSocket-Protocol' => 'other, chat']);
        fwrite($peer, substr($request, 0, 27));
        $loop->defer(static function () use ($peer, $request): void {
            fwrite($peer, substr($request, 27) . self::clientFrame(1, 'hello'));
        });
        $wire = '';
        $this->until($loop, static function () use ($peer, &$wire, &$messages): bool {
            $wire .= self::read($peer);
            return count($messages) === 1 && str_ends_with($wire, "\x81\x05hello");
        });
        [$headers, $frames] = explode("\r\n\r\n", $wire, 2);
        self::assertTrue(str_starts_with($headers, 'HTTP/1.1 101 '), 'Missing switching protocols response.');
        self::assertTrue(str_contains(strtolower($headers), 'sec-websocket-accept: s3pplmbitxaq9kygzzhzrbk+xoo='), 'Wrong handshake hash.');
        self::assertTrue(!str_contains(strtolower($headers), 'content-length:')
            && !str_contains(strtolower($headers), 'transfer-encoding:'), 'Upgrade response had body framing.');
        self::assertTrue(array_column(self::serverFrames($frames), 'payload') === ['ready', 'hello'], 'Upgrade output was reordered.');
        self::assertSame('chat', $subprotocol, 'Subprotocol was not negotiated.');
        self::assertTrue($opened === 1 && $messages === [['hello', MessageType::Text]], 'Upgrade delivered input more than once.');
        $connection->close();
        self::assertTrue($loop->getIdentifiers() === [], 'Upgraded connection leaked HTTP or WS timers.');
        fclose($peer);
    }

    public function testUpgradePreservesReadPauseUntilNewOwnerResumes(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $session = null;
        $received = [];
        (new WebSocketServer(
            static function ($session, $message) use (&$received): void { $received[] = $message; },
            static function ($opened) use (&$session): void {
                $session = $opened;
                $session->pauseRead();
            },
            config: new Config(maxFrameBytes: 65536, maxMessageBytes: 65536, maxBufferedInputBytes: 65550),
        ))->attach($connection);
        fwrite($peer, self::handshake());
        $this->until($loop, static function () use (&$session): bool { return $session !== null; });
        self::read($peer);

        $input = self::clientFrame(2, str_repeat('a', 60000)) . self::clientFrame(2, str_repeat('b', 60000));
        $offset = 0;
        $writer = $loop->onWritable($peer, $this->guardCallback(static function (string $id) use ($peer, $input, &$offset, $loop): void {
            $written = @fwrite($peer, substr($input, $offset));
            self::assertTrue($written !== false, 'Upgrade pause closed the peer prematurely.');
            $offset += $written;
            if ($offset === strlen($input)) {
                $loop->cancel($id);
            }
        }));
        $loop->delay(0.01, $this->guardCallback(static function () use (&$received, $connection, &$session): void {
            self::assertTrue($received === [] && !$connection->isClosed(), 'HTTP resumed the new owner and overflowed paused input.');
            $session->resumeRead();
        }));
        $this->until($loop, static function () use (&$received): bool { return count($received) === 2; });
        self::assertTrue($received === [str_repeat('a', 60000), str_repeat('b', 60000)], 'Resume lost data after upgrade.');
        $loop->cancel($writer);
        $connection->close();
        self::assertTrue($loop->getIdentifiers() === [], 'Paused upgrade leaked resources.');
        fclose($peer);
    }

    public function testParsesMaskedFragmentsBinaryAndEmptyMessagesWithInterleavedPing(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $messages = [];
        $session = new WebSocketSession($connection, static function ($session, $message, $type) use (&$messages): void {
            $messages[] = [$message, $type];
        });
        $wire = self::clientFrame(1, "a\xe2", false) . self::clientFrame(9, 'ping')
            . self::clientFrame(0, "\x82\xac") . self::clientFrame(2, "\x00\xff") . self::clientFrame(1);
        foreach (str_split($wire) as $byte) {
            $session->receive($byte);
        }
        self::assertTrue($messages === [["a€", MessageType::Text], ["\x00\xff", MessageType::Binary], ['', MessageType::Text]],
            'Fragmented messages or binary/empty payloads were decoded incorrectly.');
        $pong = self::serverFrames(self::read($peer));
        self::assertTrue($pong === [['opcode' => 10, 'fin' => true, 'payload' => 'ping']], 'Ping did not produce matching pong.');
        $connection->close();
        fclose($peer);
    }

    public function testParsingBudgetDefersBufferedFramesWithoutLosingTheirOrder(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $messages = [];
        $session = new WebSocketSession($connection, static function ($session, $message) use (&$messages): void {
            $messages[] = $message;
        }, new Config(framesPerTurn: 1));
        $session->receive(self::clientFrame(1, 'a') . self::clientFrame(1, 'b') . self::clientFrame(1, 'c'));
        self::assertTrue($messages === ['a'], 'Parser ignored its frame budget.');
        $session->resumeRead();
        self::assertTrue($messages === ['a'], 'Resume bypassed an already scheduled parsing turn.');
        $this->until($loop, static function () use (&$messages): bool { return count($messages) === 3; });
        self::assertTrue($messages === ['a', 'b', 'c'], 'Buffered continuation stalled or reordered data.');
        $connection->close();
        self::assertTrue($loop->getIdentifiers() === [], 'Parser continuation leaked.');
        fclose($peer);
    }

    public function testControlFramesOvertakeQueuedDataAtFrameBoundaries(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $session = new WebSocketSession($connection, static function (): void {}, new Config(outgoingFrameBytes: 2, framesPerTurn: 1));
        self::assertTrue($session->sendText('abcdef') === WriteResult::Accepted, 'Message was rejected.');
        $session->receive(self::clientFrame(9, 'P'));
        $first = self::serverFrames(self::read($peer));
        self::assertTrue($first === [
            ['opcode' => 1, 'fin' => false, 'payload' => 'ab'],
            ['opcode' => 10, 'fin' => true, 'payload' => 'P'],
        ], 'Control frame corrupted or failed to overtake queued data frames.');
        $wire = '';
        $this->until($loop, static function () use ($session, $peer, &$wire): bool {
            $wire .= self::read($peer);
            return $session->bufferedBytes() === 0;
        });
        self::assertTrue(self::serverFrames($wire) === [
            ['opcode' => 0, 'fin' => false, 'payload' => 'cd'],
            ['opcode' => 0, 'fin' => true, 'payload' => 'ef'],
        ], 'Outgoing continuation framing failed.');
        $connection->close();
        fclose($peer);
    }

    public function testApplicationPauseStopsBufferedDeliveryUntilResume(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $messages = [];
        $session = new WebSocketSession($connection, static function ($session, $message) use (&$messages): void {
            $messages[] = $message;
            if ($message === 'a') {
                $session->pauseRead();
            }
        });
        $session->receive(self::clientFrame(1, 'a') . self::clientFrame(1, 'b'));
        self::assertTrue($messages === ['a'], 'Pause did not stop delivery from the current read batch.');
        $session->resumeRead();
        self::assertTrue($messages === ['a', 'b'], 'Resume lost already buffered input.');
        $connection->close();
        fclose($peer);
    }

    public function testBackpressureRejectsAtomicallyAndNotifiesCapacityForBinaryStreaming(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $session = new WebSocketSession($connection, static function (): void {},
            new Config(maxQueuedOutputBytes: 28, outgoingFrameBytes: 2, framesPerTurn: 1));
        $drained = 0;
        $session->onDrain(static function () use (&$drained): void { $drained++; });
        self::assertTrue($session->sendBinary('abcdefghijkl') === WriteResult::Accepted, 'Initial fragmented write rejected.');
        self::assertTrue($session->sendBinary('12345678') === WriteResult::Backpressured, 'Full queue did not reject atomically.');
        $wire = '';
        $this->until($loop, static function () use ($peer, &$wire, $session): bool {
            $wire .= self::read($peer);
            return $session->bufferedBytes() === 0;
        });
        self::assertTrue(implode('', array_column(self::serverFrames($wire), 'payload')) === 'abcdefghijkl' && $drained > 0,
            'Rejected output leaked bytes or capacity notification was lost.');
        self::assertTrue($session->sendBinaryChunk('AB') === WriteResult::Accepted
            && $session->sendBinaryChunk('CD', true) === WriteResult::Accepted, 'Binary chunks rejected.');
        self::assertTrue(self::serverFrames(self::read($peer)) === [
            ['opcode' => 2, 'fin' => false, 'payload' => 'AB'],
            ['opcode' => 0, 'fin' => true, 'payload' => 'CD'],
        ], 'Binary streaming did not preserve message boundaries.');
        $connection->close();
        fclose($peer);
    }

    public function testSlowReadersPreservePartialFramesAndRetryRejectedChunks(): void
    {
        [$loop, $connection, $peer] = $this->pair(8192);
        $session = new WebSocketSession($connection, static function (): void {},
            new Config(maxQueuedOutputBytes: 32768, outgoingFrameBytes: 4096, framesPerTurn: 4));
        $accepted = 0;
        for ($attempt = 0; $attempt < 900; $attempt++) {
            if ($session->sendBinaryChunk(str_repeat('x', 4096)) === WriteResult::Backpressured) {
                break;
            }
            $accepted++;
        }
        self::assertTrue($accepted > 0 && $accepted < 900 && $connection->bufferedBytes() > 0,
            'Could not establish bounded transport pressure.');
        $retried = false;
        $session->onDrain(static function ($session) use (&$retried): void {
            if (!$retried) {
                $retried = $session->sendBinaryChunk('END', true) === WriteResult::Accepted;
            }
        });
        $wire = '';
        $this->until($loop, static function () use ($peer, &$wire, &$retried, $accepted, $session): bool {
            $wire .= self::read($peer);
            return $retried && $session->bufferedBytes() === 0 && strlen($wire) === $accepted * 4100 + 5;
        });
        $frames = self::serverFrames($wire);
        self::assertTrue(count($frames) === $accepted + 1 && $frames[0]['opcode'] === 2,
            'Pressure duplicated or dropped frames.');
        foreach ($frames as $index => $frame) {
            self::assertTrue($frame['opcode'] === ($index === 0 ? 2 : 0)
                && $frame['fin'] === ($index === $accepted)
                && $frame['payload'] === ($index === $accepted ? 'END' : str_repeat('x', 4096)),
                'A partial write corrupted a frame or message boundary.');
        }
        $connection->close();
        self::assertTrue($loop->getIdentifiers() === [], 'Pressure continuations leaked.');
        fclose($peer);
    }

    #[DataProvider('invalidHandshakeProvider')]
    public function testRejectsInvalidHandshakesAndApplicationPolicies(array $headers, int $status, bool $deny = false, string $body = ''): void
    {
        $request = self::handshake($headers) . $body;
        $accept = $deny ? static fn() => false : null;
        [$loop, $connection, $peer] = $this->pair();
        $opened = false;
        (new WebSocketServer(static function (): void {}, static function () use (&$opened): void {
            $opened = true;
        }, accept: $accept))->attach($connection);
        fwrite($peer, $request);
        $wire = '';
        $this->until($loop, static function () use ($peer, &$wire, $connection): bool {
            $wire .= self::read($peer);
            return $connection->isClosed();
        });
        self::assertTrue(str_starts_with($wire, "HTTP/1.1 {$status} ") && !$opened, 'Invalid handshake was upgraded.');
        if ($status === 426) {
            self::assertTrue(str_contains(strtolower($wire), 'sec-websocket-version: 13'), '426 omitted supported version.');
        }
        self::assertTrue($loop->getIdentifiers() === [], 'Failed handshake leaked resources.');
        fclose($peer);
    }

    public static function invalidHandshakeProvider(): iterable
    {
        yield 'invalid-key' => [['Sec-WebSocket-Key' => 'bad'], 400];
        yield 'unsupported-version' => [['Sec-WebSocket-Version' => '12'], 426];
        yield 'wrong-upgrade-protocol' => [['Upgrade' => 'other'], 400];
        yield 'duplicate-subprotocol' => [['Sec-WebSocket-Protocol' => 'chat, chat'], 400];
        yield 'nonempty-request-body' => [['Content-Length' => '1'], 400, false, 'x'];
        yield 'application-rejection' => [[], 403, true];
    }

    #[DataProvider('malformedFrameProvider')]
    public function testRejectsMalformedFramesAndMessageLimitViolations(string $input, int $expected): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $delivered = false;
        $session = new WebSocketSession($connection, static function () use (&$delivered): void {
            $delivered = true;
        }, new Config(maxFrameBytes: 1024, maxMessageBytes: 1200));
        $session->receive($input);
        self::assertFalse($delivered, 'Malformed input reached the handler.');
        $frames = self::serverFrames(self::read($peer));
        self::assertTrue(count($frames) === 1 && $frames[0]['opcode'] === 8
            && unpack('n', $frames[0]['payload'])[1] === $expected, 'Wrong close for malformed input: ' . bin2hex(substr($input, 0, 16)));
        self::assertTrue($connection->isClosed() && $loop->getIdentifiers() === [], 'Protocol error leaked connection resources.');
        fclose($peer);
    }

    public static function malformedFrameProvider(): iterable
    {
        yield 'reserved-bits' => ["\xc1\x80abcd", 1002];
        yield 'unmasked-client' => ["\x81\x00", 1002];
        yield 'reserved-opcode' => [self::clientFrame(3), 1002];
        yield 'orphan-continuation' => [self::clientFrame(0, 'x'), 1002];
        yield 'interleaved-data-message' => [self::clientFrame(1, 'a', false) . self::clientFrame(2, 'b'), 1002];
        yield 'fragmented-control' => [self::clientFrame(9, 'x', false), 1002];
        yield 'oversized-control' => [self::clientFrame(9, str_repeat('x', 126)), 1002];
        yield 'nonminimal-16bit-length' => ["\x81\xfe\x00\x01", 1002];
        yield 'nonminimal-64bit-length' => ["\x81\xff" . pack('NN', 0, 65535), 1002];
        yield 'negative-64bit-length' => ["\x81\xff" . pack('NN', 0x80000000, 0), 1002];
        yield 'oversized-64bit-length' => ["\x81\xff" . pack('NN', 1, 0), 1009];
        yield 'frame-limit' => ["\x81\xfe" . pack('n', 1025), 1009];
        yield 'message-limit' => [self::clientFrame(1, str_repeat('a', 800), false) . self::clientFrame(0, str_repeat('b', 800)), 1009];
        yield 'invalid-text-utf8' => [self::clientFrame(1, "\xff"), 1007];
        yield 'one-byte-close' => [self::clientFrame(8, 'x'), 1002];
        yield 'forbidden-close-code' => [self::clientFrame(8, pack('n', 1005)), 1002];
        yield 'invalid-close-reason-utf8' => [self::clientFrame(8, pack('n', 1000) . "\xff"), 1007];
    }

    public function testAcceptsValidExtendedPayloadLengths(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $sizes = [];
        $session = new WebSocketSession($connection, static function ($session, $message) use (&$sizes): void {
            $sizes[] = strlen($message);
        });
        $session->receive(self::clientFrame(2, str_repeat('a', 126)) . self::clientFrame(2, str_repeat('b', 65536)));
        self::assertTrue($sizes === [126, 65536], 'Valid extended lengths failed.');
        $connection->close();
        fclose($peer);
    }

    public function testAFailingMessageHandlerClosesWith1011AndIsReported(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $session = new WebSocketSession($connection, static function (): void {
            throw new \RuntimeException('message handler failure');
        });
        $session->receive(self::clientFrame(1, 'hello'));
        $frames = self::serverFrames(self::read($peer));
        self::assertSame([['opcode' => 8, 'fin' => true, 'payload' => pack('n', 1011) . 'Handler failed']], $frames);
        self::assertSame(['WebSocket message handler failed: message handler failure'], $this->reports);
        $connection->close();
        fclose($peer);
    }

    public function testCloseHandshakeEchoesStatusAndNotifiesExactlyOnce(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $closed = [];
        $session = new WebSocketSession($connection, static function (): void {}, onClose: static function ($session, $code, $reason) use (&$closed): void {
            $closed[] = [$code, $reason];
        });
        $session->receive(self::clientFrame(8, pack('n', 1000) . 'bye'));
        $frames = self::serverFrames(self::read($peer));
        self::assertTrue($frames === [['opcode' => 8, 'fin' => true, 'payload' => pack('n', 1000) . 'bye']], 'Close was not echoed.');
        self::assertTrue($closed === [[1000, 'bye']] && $loop->getIdentifiers() === [], 'Close callback or cleanup failed.');
        $connection->close();
        self::assertTrue(count($closed) === 1, 'Close callback ran twice.');
        fclose($peer);
    }

    public function testDrainClosesApplicationWritesAndEnforcesCloseDeadline(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $session = new WebSocketSession($connection, static function (): void {}, new Config(closeTimeout: 0.01));
        $connection->drain();
        self::assertTrue($session->isClosing() && $session->sendText('late') === WriteResult::Closed, 'Drain did not close application writes.');
        $wire = '';
        $this->until($loop, static function () use ($peer, &$wire): bool {
            $wire .= self::read($peer);
            return $wire !== '';
        });
        $frames = self::serverFrames($wire);
        self::assertTrue(unpack('n', $frames[0]['payload'])[1] === 1001, 'Drain did not send going-away close.');
        $this->until($loop, static fn () => $session->isClosed());
        self::assertTrue($loop->getIdentifiers() === [], 'Close deadline leaked.');
        fclose($peer);
    }

    public function testAbnormalEofClosesSessionAndReleasesResources(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $code = 0;
        new WebSocketSession($connection, static function (): void {}, onClose: static function ($session, $status) use (&$code): void { $code = $status; });
        stream_socket_shutdown($peer, STREAM_SHUT_WR);
        $this->until($loop, static fn () => $connection->isClosed());
        self::assertTrue($code === 1006 && $loop->getIdentifiers() === [], 'Abnormal EOF did not clean up.');
        fclose($peer);
    }

    public function testAHandshakeCanBeCheckedBeforeAdmissionFinishesAndUpgradedLater(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $events = [];
        $held = null;
        $this->deferCleanup(static function () use (&$held): void { self::resumeHandler($held); });
        (new HttpServer(function (Request $request) use ($loop, &$events, &$held) {
            $handshake = new Handshake($request, ['chat']);
            self::assertSame(101, $handshake->response->status);
            self::assertSame('chat', $handshake->subprotocol);
            self::assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $handshake->response->headers['Sec-WebSocket-Accept']);
            $events[] = 'checked';
            $held = $loop->getSuspension();
            $held->suspend();
            $events[] = 'admitted';
            return $handshake->upgrade(
                static function ($session, $message) use (&$events): void { $events[] = $message; },
                static function ($session) use (&$events): void {
                    $events[] = 'opened';
                    $session->sendText('ready');
                },
            );
        }))->attach($connection);
        fwrite($peer, self::handshake(['Sec-WebSocket-Protocol' => 'other, chat']) . self::clientFrame(1, 'hello'));
        $this->until($loop, static function () use (&$held): bool { return $held !== null; });
        self::assertSame(['checked'], $events, 'Admission finished before its wait was released.');
        self::assertSame('', self::read($peer), 'The handshake was sent before admission finished.');
        self::resumeHandler($held);
        $wire = '';
        $this->until($loop, static function () use ($peer, &$wire, &$events): bool {
            $wire .= self::read($peer);
            return count($events) === 4 && str_ends_with($wire, "\x81\x05ready");
        });
        self::assertSame(['checked', 'admitted', 'opened', 'hello'], $events);
        self::assertSame([], $this->reports);
        $connection->close();
        self::assertSame([], $loop->getIdentifiers());
    }

    #[DataProvider('invalidHandshakeProvider')]
    public function testTheReusableHandshakeKeepsTheExistingRefusalHeadersAndBodies(array $headers, int $status, bool $deny = false, string $body = ''): void
    {
        $request = (new Parser())->receive(self::handshake($headers) . $body);
        self::assertInstanceOf(Request::class, $request);
        $handshake = new Handshake($request, accept: $deny ? static fn () => false : null);
        self::assertSame($status, $handshake->response->status);
        self::assertSame($status === 426 ? ['Connection' => 'close', 'Sec-WebSocket-Version' => '13'] : ['Connection' => 'close'], $handshake->response->headers);
        $expected = match (true) {
            isset($headers['Content-Length']), isset($headers['Upgrade']) => 'Invalid WebSocket upgrade.',
            isset($headers['Sec-WebSocket-Key']) => 'Invalid WebSocket key.',
            isset($headers['Sec-WebSocket-Protocol']) => 'Invalid WebSocket subprotocol.',
            default => '',
        };
        self::assertSame($expected, $handshake->response->body);
        self::assertNull($handshake->subprotocol);
        $this->expectException(\LogicException::class);
        $handshake->upgrade(static function (): void {});
    }

    public function testServerPingsWaitForSilenceAndASilentPeerClosesThenIsCut(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $session = new WebSocketSession($connection, static function (): void {}, new Config(
            pingInterval: 0.2, idleTimeout: 0.8, closeTimeout: 0.2,
        ));
        $loop->delay(0.02, $this->guardCallback(static function () use ($peer): void {
            self::assertSame('', self::read($peer), 'Ping was sent before its interval.');
        }));
        $wire = '';
        $this->until($loop, static function () use ($peer, &$wire): bool {
            $wire .= self::read($peer);
            return $wire !== '';
        });
        self::assertSame([['opcode' => 9, 'fin' => true, 'payload' => '']], self::serverFrames($wire));
        $wire = '';
        $this->until($loop, static function () use ($peer, &$wire, $session): bool {
            $wire .= self::read($peer);
            return $session->isClosing();
        });
        $frames = self::serverFrames($wire);
        self::assertSame(['opcode' => 8, 'fin' => true, 'payload' => pack('n', 1001) . 'Idle timeout'], array_pop($frames));
        foreach ($frames as $frame) {
            self::assertSame(['opcode' => 9, 'fin' => true, 'payload' => ''], $frame);
        }
        self::assertFalse($session->isClosed(), 'Peer was cut before the close allowance.');
        $this->until($loop, static fn () => $session->isClosed());
        self::assertSame([], $loop->getIdentifiers());
        self::assertSame([], $this->reports);
    }

    #[DataProvider('peerActivityProvider')]
    public function testPeerActivityPostponesPingsAndTheIdleDeadline(int $opcode, string $payload): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $session = new WebSocketSession($connection, static function (): void {}, new Config(
            pingInterval: 0.2, idleTimeout: 0.8, closeTimeout: 0.2,
        ));
        $activity = $loop->repeat(0.02, static function () use ($peer, $opcode, $payload): void {
            fwrite($peer, self::clientFrame($opcode, $payload));
        });
        // Activity has ten times the scheduling headroom of the ping deadline.
        $loop->delay(1.0, $this->guardCallback(static function () use ($loop, $activity, $session, $peer): void {
            $loop->cancel($activity);
            self::assertFalse($session->isClosing(), 'Received activity did not keep the session alive.');
            $frames = self::serverFrames(self::read($peer));
            foreach ($frames as $frame) {
                self::assertSame(10, $frame['opcode'], 'Activity did not postpone the server ping.');
            }
            $session->close();
        }));
        $this->until($loop, static fn () => $session->isClosed());
        self::assertSame([], $loop->getIdentifiers());
        self::assertSame([], $this->reports);
    }

    public static function peerActivityProvider(): iterable
    {
        yield 'pong' => [10, ''];
        yield 'ping' => [9, 'life'];
        yield 'message' => [1, 'life'];
        yield 'fragment' => [2, ''];
    }

    public function testActivityReusesTheDeadlineRegistrationAndAnIdleTimeoutWorksWithoutPings(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $session = new WebSocketSession($connection, static function (): void {}, new Config(idleTimeout: 0.4, closeTimeout: 0.1));
        $identifiers = $loop->getIdentifiers();
        self::assertCount(2, $identifiers, 'Expected one reader and one session deadline.');
        // Timer identity can be checked synchronously; no race with its expiry is needed.
        for ($i = 0; $i < 4; ++$i) {
            $session->receive(self::clientFrame(10));
            self::assertSame($identifiers, $loop->getIdentifiers(), 'Activity replaced the reusable deadline.');
        }
        self::assertFalse($session->isClosing());
        self::assertSame('', self::read($peer), 'Idle-only sessions must not ping.');
        $this->until($loop, static fn () => $session->isClosed());
        $frames = self::serverFrames(self::read($peer));
        self::assertSame(pack('n', 1001) . 'Idle timeout', $frames[0]['payload']);
        self::assertSame([], $loop->getIdentifiers());
        self::assertSame([], $this->reports);
    }

    public function testASuspendedHandlerKeepsBufferedAndLaterMessagesInOrder(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $events = [];
        $busy = false;
        $held = null;
        $this->deferCleanup(static function () use (&$held): void { self::resumeHandler($held); });
        $session = new WebSocketSession($connection, $this->guardCallback(function ($session, $message) use ($loop, &$events, &$busy, &$held): void {
            self::assertFalse($busy, 'Handler was entered while the previous message was suspended.');
            $busy = true;
            $events[] = 'start ' . $message;
            if ($message === 'a') {
                // Explicit resume must not bypass an in-progress handler.
                $session->resumeRead();
                $held = $loop->getSuspension();
                $held->suspend();
            }
            $events[] = 'end ' . $message;
            $busy = false;
        }), new Config(framesPerTurn: 1, maxFrameBytes: 8, maxMessageBytes: 8, maxBufferedInputBytes: 22));
        fwrite($peer, self::clientFrame(1, 'a') . self::clientFrame(1, 'b'));
        $this->until($loop, static function () use (&$held): bool { return $held !== null; });
        fwrite($peer, self::clientFrame(1, 'c'));
        self::assertSame(['start a'], $events, 'Later messages ran before the first handler was released.');
        self::resumeHandler($held);
        $this->until($loop, static function () use (&$events): bool { return count($events) === 6; });
        self::assertSame(['start a', 'end a', 'start b', 'end b', 'start c', 'end c'], $events);
        self::assertSame([], $this->reports);
        $connection->close();
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testSuspendedDeliveryAcceptsExactlyTheInputBoundAndRejectsOneMoreByte(): void
    {
        foreach ([false, true] as $overflow) {
            [$loop, $connection, $peer] = $this->pair();
            $suspension = null;
            $messages = [];
            $session = new WebSocketSession($connection, function ($session, $message) use ($loop, &$suspension, &$messages): void {
                $messages[] = $message;
                if ($message === 'a') {
                    $suspension = $loop->getSuspension();
                    $suspension->suspend();
                }
            }, new Config(maxFrameBytes: 8, maxMessageBytes: 8, maxBufferedInputBytes: 22));
            $task = Task::spawn($loop, static fn () => $session->receive(self::clientFrame(1, 'a')));
            $this->until($loop, static function () use (&$suspension): bool { return $suspension !== null; });
            // Three complete frames total exactly 22 bytes.
            $session->receive(self::clientFrame(1, 'bb') . self::clientFrame(1, 'c') . self::clientFrame(1, 'd') . ($overflow ? 'x' : ''));
            self::assertSame(['a'], $messages, 'Receive re-entered a suspended handler.');
            $suspension->resume();
            $this->until($loop, $task->isComplete(...));
            $task->result();
            if ($overflow) {
                self::assertTrue($session->isClosed());
                self::assertSame(pack('n', 1009) . 'Input limit', self::serverFrames(self::read($peer))[0]['payload']);
            } else {
                self::assertSame(['a', 'bb', 'c', 'd'], $messages);
            }
            self::assertSame([], $this->reports);
            $connection->close();
            self::assertSame([], $loop->getIdentifiers());
        }
    }

    public function testWritableWaitersWakeAtTheirThresholdWithoutARejectedSend(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $session = new WebSocketSession($connection, static function (): void {}, new Config(outgoingFrameBytes: 2, framesPerTurn: 1));
        $session->sendText('abcdefgh');
        self::assertSame(12, $session->bufferedBytes());
        $tasks = [];
        foreach ([8, 4, 1] as $threshold) {
            $tasks[] = Task::spawn($loop, static fn () => [$session->awaitWritable(belowBytes: $threshold), $session->bufferedBytes()]);
        }
        $this->until($loop, static fn () => $tasks[2]->isComplete());
        foreach ($tasks as $index => $task) {
            [$writable, $bytes] = $task->result();
            self::assertTrue($writable);
            self::assertLessThan([8, 4, 1][$index], $bytes);
        }
        self::assertSame('abcdefgh', implode('', array_column(self::serverFrames(self::read($peer)), 'payload')));
        self::assertSame([], $this->reports);
        $connection->close();
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testWritableWaitersWakeOnPartialTransportProgressBelowItsLowWatermark(): void
    {
        [$loop, $connection, $peer] = $this->pair(4_194_304);
        $session = new WebSocketSession($connection, static function (): void {}, new Config(maxQueuedOutputBytes: 4_194_304));
        for ($i = 0; $i < 256; ++$i) {
            self::assertSame(WriteResult::Accepted, $session->sendBinaryChunk(str_repeat('x', 4096)));
        }
        $before = $connection->bufferedBytes();
        self::assertGreaterThan(262_144, $before, 'The fixture must leave more than one write turn queued.');
        self::assertLessThan(2_097_152, $before, 'The fixture must start below the transport low watermark.');
        self::assertSame($before, $session->bufferedBytes(), 'Only transport bytes should remain queued.');
        $started = false;
        $waiter = Task::spawn($loop, static function () use ($session, $before, &$started): bool {
            $started = true;
            return $session->awaitWritable(belowBytes: $before);
        });
        $this->until($loop, static function () use (&$started): bool { return $started; });
        self::assertFalse($waiter->isComplete());
        // Read until the transport moves, rather than assuming one read makes
        // the socket writable. Keep remaining output queued on every OS.
        $reader = $loop->onReadable($peer, static function () use ($connection, $peer, $before): void {
            if ($connection->bufferedBytes() === $before) {
                fread($peer, 65_536);
            }
        });
        try {
            $this->until($loop, static fn () => $connection->bufferedBytes() < $before,
                message: 'Peer reading did not trigger a transport write turn before the deadline.');
        } finally {
            $loop->cancel($reader);
        }
        self::assertGreaterThan(0, $session->bufferedBytes(), 'The peer must stop before draining all output.');
        self::assertLessThan($before, $session->bufferedBytes(), 'Transport progress did not cross the requested threshold.');
        $this->until($loop, $waiter->isComplete(...),
            message: 'The writable waiter stayed asleep after partial transport progress.');
        self::assertTrue($waiter->isComplete(), 'The writable waiter stayed asleep after partial transport progress.');
        self::assertTrue($waiter->result());
        $connection->close();
        self::assertSame([], $loop->getIdentifiers());
        self::assertSame([], $this->reports);
    }

    public function testCancelledWritableWaitCleanupCannotEraseAnImmediateReplacement(): void
    {
        [$loop, $connection, $peer] = $this->pair(8192);
        $session = new WebSocketSession($connection, static function (): void {}, new Config(maxQueuedOutputBytes: 32768, outgoingFrameBytes: 4096));
        for ($i = 0; $i < 900; ++$i) {
            if ($session->sendBinaryChunk(str_repeat('x', 4096)) === WriteResult::Backpressured) { break; }
        }
        $tokens = [new Cancellation(), new Cancellation()];
        $started = $retried = 0;
        $tasks = [];
        foreach ($tokens as $token) {
            $tasks[] = Task::spawn($loop, static function () use ($session, $token, &$started, &$retried): bool {
                ++$started;
                try {
                    return $session->awaitWritable($token, 1);
                } catch (CancelledException) {
                    ++$retried;
                    return $session->awaitWritable(belowBytes: 1);
                }
            });
        }
        $this->until($loop, static function () use (&$started): bool { return $started === 2; });
        $tokens[1]->cancel();
        $tokens[0]->cancel();
        $this->until($loop, static function () use (&$retried): bool { return $retried === 2; });
        $connection->close();
        $this->until($loop, static fn () => $tasks[0]->isComplete() && $tasks[1]->isComplete());
        foreach ($tasks as $task) {
            self::assertTrue($task->isComplete(), 'Old cancellation cleanup erased a replacement writable wait.');
            self::assertFalse($task->result());
        }
        self::assertSame([], $loop->getIdentifiers());
        self::assertSame([], $this->reports);
    }

    public function testSixtyFourWritersCanWaitAndTheSixtyFifthIsRefused(): void
    {
        [$loop, $connection, $peer] = $this->pair(8192);
        $session = new WebSocketSession($connection, static function (): void {}, new Config(maxQueuedOutputBytes: 32768, outgoingFrameBytes: 4096));
        for ($i = 0; $i < 900; ++$i) {
            if ($session->sendBinaryChunk(str_repeat('x', 4096)) === WriteResult::Backpressured) { break; }
        }
        $started = 0;
        $tasks = [];
        for ($i = 0; $i < 65; ++$i) {
            $tasks[] = Task::spawn($loop, static function () use ($session, &$started): bool {
                ++$started;
                return $session->awaitWritable(belowBytes: 1);
            });
        }
        $this->until($loop, static function () use (&$started): bool { return $started === 65; });
        foreach (array_slice($tasks, 0, 64) as $task) { self::assertFalse($task->isComplete()); }
        self::assertTrue($tasks[64]->isComplete());
        try {
            $tasks[64]->result();
            self::fail('A sixty-fifth writer exceeded the default waiter limit.');
        } catch (\OverflowException $error) {
            self::assertStringContainsString('maxWritableWaiters', $error->getMessage());
        }
        $connection->close();
        $this->until($loop, static fn (): bool => $tasks[63]->isComplete());
        foreach (array_slice($tasks, 0, 64) as $task) { self::assertFalse($task->result()); }
        self::assertSame([], $loop->getIdentifiers());
        self::assertSame([], $this->reports);
    }

    public function testASuspendedHandlerStopsTheSilenceClockDespiteImmediatePeerPongs(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $finishedAt = null;
        $sent = null;
        $frames = [];
        $firstPingAt = null;
        $held = null;
        $this->deferCleanup(static function () use (&$held): void { self::resumeHandler($held); });
        $session = new WebSocketSession($connection, static function ($session) use ($loop, &$finishedAt, &$sent, &$held): void {
            $held = $loop->getSuspension();
            $held->suspend();
            $sent = $session->sendText('reply');
            $finishedAt = hrtime(true) / 1_000_000_000;
        }, new Config(pingInterval: 0.1, idleTimeout: 0.4, closeTimeout: 0.1));
        $reader = $loop->onReadable($peer, static function () use ($peer, &$frames, &$firstPingAt): void {
            foreach (self::serverFrames(self::read($peer)) as $frame) {
                $frames[] = $frame;
                if ($frame['opcode'] === 9) {
                    $firstPingAt ??= hrtime(true) / 1_000_000_000;
                    @fwrite($peer, self::clientFrame(10, $frame['payload']));
                }
            }
        });
        $this->deferCleanup(static fn () => $loop->cancel($reader));
        $handler = Task::spawn($loop, static fn () => $session->receive(self::clientFrame(1, 'work')));
        $this->until($loop, static function () use (&$held): bool { return $held !== null; });
        // Hold the handler for four complete idle allowances, independent of scheduling.
        $loop->delay(1.6, $loop->stop(...));
        $loop->run();
        self::assertSame([false, false, []], [$session->isClosing(), $session->isClosed(), $frames], 'A server-paused reader was pinged or timed out.');
        self::resumeHandler($held);
        $this->until($loop, static function () use (&$finishedAt): bool { return $finishedAt !== null; });
        self::assertTrue($handler->isComplete());
        $handler->result();
        self::assertSame(WriteResult::Accepted, $sent, 'A suspended handler lost its reply to an idle timeout.');
        $this->until($loop, static function () use (&$firstPingAt): bool { return $firstPingAt !== null; });
        self::assertGreaterThanOrEqual($finishedAt + 0.1, $firstPingAt, 'Resume did not start a fresh silence interval.');
        $loop->cancel($reader);
        $connection->close();
        self::assertSame([], $loop->getIdentifiers());
        self::assertSame([], $this->reports);
    }

    #[DataProvider('pausedHeartbeatProvider')]
    public function testApplicationReadPauseStopsIdleExpiryUntilAFullIntervalAfterResume(bool $pings): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $session = new WebSocketSession($connection, static function (): void {}, new Config(
            pingInterval: $pings ? 0.1 : null, idleTimeout: 0.4, closeTimeout: 0.1,
        ));
        $session->pauseRead();
        $loop->delay(1.6, $loop->stop(...));
        $loop->run();
        $paused = [$session->isClosing(), $session->isClosed(), self::read($peer)];
        $resumedAt = hrtime(true) / 1_000_000_000;
        $session->resumeRead();
        $loop->delay(0.02, $this->guardCallback(static function () use ($loop, $session, $peer): void {
            self::assertSame('', self::read($peer), 'Resume sent a ping before a full silence interval.');
            self::assertFalse($session->isClosing());
            $loop->stop();
        }));
        $loop->run();
        self::assertSame([false, false, ''], $paused, 'Application backpressure consumed the peer silence allowance.');
        $this->until($loop, $session->isClosing(...));
        self::assertGreaterThanOrEqual(0.4, hrtime(true) / 1_000_000_000 - $resumedAt);
        $frames = self::serverFrames(self::read($peer));
        $opcodes = array_column($frames, 'opcode');
        self::assertSame(8, array_pop($opcodes));
        self::assertSame($pings, $opcodes !== [], 'The configured ping policy was not observed.');
        foreach ($opcodes as $opcode) { self::assertSame(9, $opcode); }
        self::assertSame(pack('n', 1001) . 'Idle timeout', $frames[array_key_last($frames)]['payload']);
        $this->until($loop, $session->isClosed(...));
        self::assertSame([], $loop->getIdentifiers());
        self::assertSame([], $this->reports);
    }

    public static function pausedHeartbeatProvider(): iterable
    {
        yield 'server pings and idle expiry' => [true];
        yield 'idle expiry without pings' => [false];
    }

    #[DataProvider('waiterTerminationProvider')]
    public function testEveryWritableWaiterWakesWhenTheSessionClosesOrFails(string $operation): void
    {
        [$loop, $connection, $peer] = $this->pair(8192);
        $session = new WebSocketSession($connection, static function (): void {}, new Config(maxQueuedOutputBytes: 32768, outgoingFrameBytes: 4096, maxMessageBytes: 8_000_000));
        for ($i = 0; $i < 900; ++$i) {
            if ($session->sendBinaryChunk(str_repeat('x', 4096)) === WriteResult::Backpressured) {
                break;
            }
        }
        self::assertGreaterThan(0, $session->bufferedBytes());
        $started = 0;
        $tasks = [];
        for ($i = 0; $i < 3; ++$i) {
            $tasks[] = Task::spawn($loop, static function () use ($session, &$started): bool {
                ++$started;
                return $session->awaitWritable(belowBytes: 1);
            });
        }
        $this->until($loop, static function () use (&$started): bool { return $started === 3; });
        match ($operation) {
            'close' => $session->close(),
            'transport' => $connection->close(),
            'failure' => $session->receive("\x81\x00"),
        };
        $this->until($loop, static fn () => $tasks[2]->isComplete());
        foreach ($tasks as $task) { self::assertFalse($task->result()); }
        self::assertFalse($session->awaitWritable());
        self::assertSame([], $this->reports);
        $connection->close();
        self::assertSame([], $loop->getIdentifiers());
    }

    public static function waiterTerminationProvider(): iterable
    {
        yield 'local close' => ['close'];
        yield 'transport close' => ['transport'];
        yield 'protocol failure' => ['failure'];
    }

    public function testCancellationRemovesOnlyItsWritableWaitAndTheWaiterLimitIsBounded(): void
    {
        [$loop, $connection, $peer] = $this->pair(8192);
        $session = new WebSocketSession($connection, static function (): void {}, new Config(maxQueuedOutputBytes: 32768, outgoingFrameBytes: 4096, maxMessageBytes: 8_000_000, maxWritableWaiters: 2));
        for ($i = 0; $i < 900; ++$i) {
            if ($session->sendBinaryChunk(str_repeat('x', 4096)) === WriteResult::Backpressured) { break; }
        }
        $cancel = new Cancellation();
        $started = 0;
        $tasks = [];
        foreach ([$cancel, null, null] as $token) {
            $tasks[] = Task::spawn($loop, static function () use ($session, $token, &$started): bool {
                ++$started;
                return $session->awaitWritable($token, 1);
            });
        }
        $this->until($loop, static function () use (&$started): bool { return $started === 3; });
        self::assertTrue($tasks[2]->isComplete());
        try {
            $tasks[2]->result();
            self::fail('A third waiter exceeded the configured limit.');
        } catch (\OverflowException $error) {
            self::assertStringContainsString('maxWritableWaiters', $error->getMessage());
        }
        $cancel->cancel();
        $this->until($loop, $tasks[0]->isComplete(...));
        try {
            $tasks[0]->result();
            self::fail('Cancelled wait returned normally.');
        } catch (CancelledException) {
            self::assertFalse($session->isClosing());
        }
        $replacementStarted = false;
        $replacement = Task::spawn($loop, static function () use ($session, &$replacementStarted): bool {
            $replacementStarted = true;
            return $session->awaitWritable(belowBytes: 1);
        });
        $this->until($loop, static function () use (&$replacementStarted): bool { return $replacementStarted; });
        self::assertFalse($replacement->isComplete(), 'Cancellation did not free the waiter slot.');
        $session->close();
        $this->until($loop, static fn () => $tasks[1]->isComplete() && $replacement->isComplete());
        self::assertFalse($tasks[1]->result());
        self::assertFalse($replacement->result());
        self::assertSame([], $this->reports);
        $connection->close();
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testAnOpenWebSocketIsNotCountedAsACutHttpRequest(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        (new WebSocketServer(static function (): void {}))->attach($connection);
        fwrite($peer, self::handshake());
        $wire = '';
        $this->until($loop, static function () use ($peer, &$wire): bool {
            $wire .= self::read($peer);
            return str_contains($wire, "\r\n\r\n");
        });
        self::assertFalse($connection->hasPendingWork());
        $connection->drain(0.01);
        self::assertFalse($connection->hasPendingWork());
        $this->until($loop, $connection->isClosed(...));
        self::assertSame([], $this->reports);
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testManyDrainClosesAreSpreadOverTurnsAndFinishWithinTheirDeadline(): void
    {
        $loop = RevoltEventLoop::create();
        EventLoop::install($loop);
        $this->deferCleanup(static function () use ($loop): void {
            foreach ($loop->getIdentifiers() as $id) { $loop->cancel($id); }
        });
        $sessions = [];
        $peers = [];
        $connections = [];
        for ($i = 0; $i < 12; ++$i) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertIsArray($pair);
            stream_set_blocking($pair[1], false);
            $connection = new Connection($loop, $pair[0]);
            $sessions[] = new WebSocketSession($connection, static function (): void {}, new Config(closeTimeout: 0.1));
            $connections[] = $connection;
            $peers[] = $pair[1];
            $this->deferCleanup($connection->close(...));
            $this->deferCleanup(static function () use ($pair): void {
                if (is_resource($pair[1])) { fclose($pair[1]); }
            });
        }
        $started = hrtime(true) / 1_000_000_000;
        foreach ($connections as $connection) { $connection->drain(0.8); }
        foreach ($peers as $peer) { self::assertSame('', self::read($peer), 'Drain wrote in the requesting loop turn.'); }
        $closedAt = [];
        $this->until($loop, static function () use ($peers, $sessions, &$closedAt, $started): bool {
            foreach ($peers as $index => $peer) {
                $wire = self::read($peer);
                if ($wire !== '') {
                    $frames = self::serverFrames($wire);
                    self::assertSame(pack('n', 1001) . 'Server shutdown', $frames[0]['payload']);
                    $closedAt[$index] = hrtime(true) / 1_000_000_000 - $started;
                    $sessions[$index]->receive(self::clientFrame(8, pack('n', 1001)));
                }
            }
            return count($closedAt) === count($sessions);
        });
        self::assertLessThan(0.4, max($closedAt));
        self::assertGreaterThan(0.02, max($closedAt) - min($closedAt), 'Closes were not spread across the window.');
        self::assertSame([], $loop->getIdentifiers());
        self::assertSame([], $this->reports);
    }

    public function testTheTransportDeadlineCancelsADrainStaggerBeforeItCanWriteLate(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $session = new WebSocketSession($connection, static function (): void {}, new Config(closeTimeout: 2));
        $started = hrtime(true) / 1_000_000_000;
        $connection->drain(0.05);
        $this->until($loop, $connection->isClosed(...));
        self::assertLessThan(0.2, hrtime(true) / 1_000_000_000 - $started);
        self::assertFalse($session->awaitWritable());
        self::assertSame([], $loop->getIdentifiers(), 'A delayed Close outlived the worker allowance.');
        self::assertSame([], $this->reports);
    }

    public function testAShortWorkerDrainStillSendsGoingAwayBeforeItsDeadline(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $session = new WebSocketSession($connection, static function (): void {}, new Config(closeTimeout: 2));
        $started = hrtime(true) / 1_000_000_000;
        $connection->drain(0.1);
        $this->until($loop, $connection->isClosed(...));
        $frames = self::serverFrames(self::read($peer));
        self::assertNotEmpty($frames, 'A short drain cut the peer before sending Close 1001.');
        self::assertSame([['opcode' => 8, 'fin' => true, 'payload' => pack('n', 1001) . 'Server shutdown']], $frames);
        self::assertLessThan(0.4, hrtime(true) / 1_000_000_000 - $started);
        self::assertFalse($session->awaitWritable());
        self::assertSame([], $loop->getIdentifiers());
        self::assertSame([], $this->reports);
    }

    public function testResumingASuspendedDeferredHandlerDoesNotOverflowItsBoundedInput(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $messages = [];
        $secondFinished = false;
        $held = null;
        $wroteWhilePaused = false;
        $this->deferCleanup(static function () use (&$held): void { self::resumeHandler($held); });
        $session = new WebSocketSession($connection, $this->guardCallback(function ($session, $message) use ($loop, $peer, &$messages, &$secondFinished, &$held, &$wroteWhilePaused): void {
            $messages[] = $message;
            if ($message === 'b') {
                // This handler runs from a parser continuation, outside a transport read turn.
                $session->resumeRead();
                $input = self::clientFrame(2, str_repeat('c', 60000)) . self::clientFrame(2, str_repeat('d', 60000));
                $offset = 0;
                $writer = $loop->onWritable($peer, $this->guardCallback(static function (string $id) use ($peer, $input, &$offset, $loop, $session, &$wroteWhilePaused): void {
                    if ($session->isClosing()) {
                        $loop->cancel($id);
                        return;
                    }
                    $written = fwrite($peer, substr($input, $offset));
                    self::assertNotFalse($written, 'The peer failed while sending bounded input.');
                    $offset += $written;
                    $wroteWhilePaused = $offset > 0;
                    if ($offset === strlen($input)) {
                        $loop->cancel($id);
                    }
                }));
                $this->deferCleanup(static fn () => $loop->cancel($writer));
                $held = $loop->getSuspension();
                $held->suspend();
                $secondFinished = true;
                self::assertFalse($session->isClosing(), 'Resume admitted more input while a deferred handler was suspended.');
            }
        }), new Config(framesPerTurn: 1, maxFrameBytes: 65536, maxMessageBytes: 65536, maxBufferedInputBytes: 65550));
        $session->receive(self::clientFrame(1, 'a') . self::clientFrame(1, 'b'));
        $this->until($loop, static function () use (&$held, &$wroteWhilePaused): bool { return $held !== null && $wroteWhilePaused; });
        self::assertSame(['a', 'b'], $messages, 'Input was read while the deferred handler was suspended.');
        self::resumeHandler($held);
        $this->until($loop, static function () use (&$messages, &$secondFinished, $session): bool {
            return count($messages) === 4 || ($secondFinished && $session->isClosed());
        });
        self::assertCount(4, $messages, 'The suspended handler lost later bounded messages.');
        self::assertSame(['a', 'b', str_repeat('c', 60000), str_repeat('d', 60000)], $messages);
        self::assertSame([], $this->reports);
        $connection->close();
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testASuspendedHandlerKeepsItsCleanupOwnedAfterTheTransportCloses(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $held = null;
        $finished = false;
        $settled = false;
        $this->deferCleanup(static function () use (&$held): void { self::resumeHandler($held); });
        $connection->onSettled(static function () use (&$settled): void { $settled = true; });
        new WebSocketSession($connection, static function () use ($loop, &$held, &$finished): void {
            try {
                $held = $loop->getSuspension();
                $held->suspend();
            } finally {
                $finished = true;
            }
        });
        fwrite($peer, self::clientFrame(1, 'hello'));
        $this->until($loop, static function () use (&$held): bool { return $held !== null; });
        $connection->close();
        self::assertFalse($settled, 'Suspended handler cleanup was released before it returned.');
        self::resumeHandler($held);
        $this->until($loop, static function () use (&$settled): bool { return $settled; });
        self::assertTrue($finished);
        self::assertSame([], $loop->getIdentifiers());
        self::assertSame([], $this->reports);
    }

    public function testACancelledWritableWaitDoesNotRegisterOrDiscardOutput(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $session = new WebSocketSession($connection, static function (): void {});
        $cancel = new Cancellation();
        $cancel->cancel();
        $identifiers = $loop->getIdentifiers();
        try {
            $session->awaitWritable($cancel);
            self::fail('A cancelled wait returned room.');
        } catch (CancelledException) {
            self::assertSame($identifiers, $loop->getIdentifiers());
            self::assertSame(WriteResult::Accepted, $session->sendText('still open'));
        }
        self::assertSame('still open', self::serverFrames(self::read($peer))[0]['payload']);
        $connection->close();
        self::assertSame([], $this->reports);
    }

    public function testHeartbeatIsOffByDefaultAndWritableThresholdsAreChecked(): void
    {
        [$loop, $connection, $peer] = $this->pair();
        $session = new WebSocketSession($connection, static function (): void {}, new Config(maxQueuedOutputBytes: 8));
        self::assertCount(1, $loop->getIdentifiers(), 'Default sessions installed a heartbeat timer.');
        self::assertTrue($session->awaitWritable(belowBytes: 1));
        foreach ([0, 9] as $threshold) {
            try {
                $session->awaitWritable(belowBytes: $threshold);
                self::fail('An invalid threshold was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('threshold', $error->getMessage());
            }
        }
        $connection->close();
        self::assertSame([], $loop->getIdentifiers());
    }

    #[DataProvider('invalidHeartbeatProvider')]
    public function testHeartbeatAndWaiterConfigurationRefusesUnboundedValues(array $settings): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(...$settings);
    }

    public static function invalidHeartbeatProvider(): iterable
    {
        foreach (['pingInterval', 'idleTimeout'] as $option) {
            foreach ([0, -1, INF, NAN] as $value) { yield $option . ' ' . (string) $value => [[$option => $value]]; }
        }
        yield 'overflowing implicit idle timeout' => [['pingInterval' => PHP_FLOAT_MAX]];
        yield 'no waiter slots' => [['maxWritableWaiters' => 0]];
    }

    private static function read(mixed $peer): string
    {
        $result = '';
        while (($chunk = fread($peer, 65_536)) !== '' && $chunk !== false) {
            $result .= $chunk;
        }
        return $result;
    }

    private static function clientFrame(int $opcode, string $payload = '', bool $fin = true): string
    {
        $length = strlen($payload);
        $header = chr(($fin ? 128 : 0) | $opcode);
        $header .= match (true) {
            $length < 126 => chr(128 | $length),
            $length <= 65535 => chr(128 | 126) . pack('n', $length),
            default => chr(128 | 127) . pack('NN', 0, $length),
        };
        $mask = "\x37\xfa\x21\x3d";
        return $header . $mask . ($payload ^ str_repeat($mask, intdiv($length + 3, 4)));
    }

    /**
     * @return list<array{opcode:int,fin:bool,payload:string}>
     */
    private static function serverFrames(string $wire): array
    {
        $result = [];
        while ($wire !== '') {
            self::assertTrue(strlen($wire) >= 2, 'Incomplete server frame header.');
            $first = ord($wire[0]);
            $second = ord($wire[1]);
            self::assertTrue(($second & 128) === 0, 'Server masked its frame.');
            $length = $second & 127;
            $offset = 2;
            if ($length === 126) {
                self::assertTrue(strlen($wire) >= 4, 'Incomplete extended frame header.');
                $length = unpack('n', $wire, 2)[1];
                $offset = 4;
            } elseif ($length === 127) {
                self::assertTrue(strlen($wire) >= 10, 'Incomplete 64-bit frame header.');
                $length = unpack('N', $wire, 6)[1];
                $offset = 10;
            }
            self::assertTrue(strlen($wire) >= $offset + $length, 'Incomplete server payload.');
            $result[] = ['opcode' => $first & 15, 'fin' => ($first & 128) !== 0,
                'payload' => substr($wire, $offset, $length)];
            $wire = substr($wire, $offset + $length);
        }
        return $result;
    }

    private static function handshake(array $replace = []): string
    {
        $headers = array_replace([
            'Host' => 'localhost',
            'Upgrade' => 'websocket',
            'Connection' => 'keep-alive, Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '13',
        ], $replace);
        $wire = "GET /chat HTTP/1.1\r\n";
        foreach ($headers as $name => $value) {
            if ($value !== null) {
                $wire .= $name . ': ' . $value . "\r\n";
            }
        }
        return $wire . "\r\n";
    }

    /**
     * @return array{RevoltEventLoop, Connection, resource}
     */
    private function pair(int $capacity = 1048576): array
    {
        $loop = RevoltEventLoop::create();
        EventLoop::install($loop);
        $this->deferCleanup(static function () use ($loop): void {
            foreach ($loop->getIdentifiers() as $id) { $loop->cancel($id); }
        });
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        $this->deferCleanup(static function () use ($pair): void {
            foreach ($pair as $stream) { if (is_resource($stream)) { fclose($stream); } }
        });
        stream_set_blocking($pair[1], false);
        $connection = new Connection($loop, $pair[0], 'test-peer', $capacity);
        $this->deferCleanup($connection->close(...));
        return [$loop, $connection, $pair[1]];
    }

    /**
     * Release an owned handler once, including when a test fails before release.
     */
    private static function resumeHandler(?Suspension &$held): void
    {
        $suspension = $held;
        $held = null;
        $suspension?->resume();
    }

    private function until(RevoltEventLoop $loop, Closure $complete, float $timeout = 5, string $message = 'WebSocket test deadline exceeded.'): void
    {
        $timedOut = false;
        $poll = $loop->repeat(0.001, $this->guardCallback(static function () use ($complete, $loop): void {
            if ($complete()) { $loop->stop(); }
        }));
        $deadline = $loop->delay($timeout, static function () use (&$timedOut, $loop): void {
            $timedOut = true;
            $loop->stop();
        });
        try {
            $loop->run();
        } finally {
            $loop->cancel($poll);
            $loop->cancel($deadline);
        }
        self::assertFalse($timedOut, $message);
        self::assertTrue($complete(), 'WebSocket completion condition did not hold.');
    }
}
