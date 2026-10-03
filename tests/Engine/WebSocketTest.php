<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Network\Connection;
use Crab\Engine\Network\WriteResult;
use Crab\Engine\Server\WebSocketServer;
use Crab\Engine\WebSocket\Config;
use Crab\Engine\WebSocket\MessageType;
use Crab\Engine\WebSocket\WebSocketSession;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use Crab\Engine\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Closure;
use Revolt\EventLoop;

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
        $frames = self::serverFrames(self::read($peer));
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
     * @return array{StreamSelectDriver, Connection, resource}
     */
    private function pair(int $capacity = 1048576): array
    {
        $loop = new StreamSelectDriver();
        EventLoop::setDriver($loop);
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

    private function until(StreamSelectDriver $loop, Closure $complete, float $timeout = 2): void
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
        self::assertFalse($timedOut, 'WebSocket test deadline exceeded.');
        self::assertTrue($complete(), 'WebSocket completion condition did not hold.');
    }
}
