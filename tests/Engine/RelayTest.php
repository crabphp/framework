<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Closure;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\Listener;
use Crab\Engine\Network\Relay;
use Crab\Engine\Server\TcpServer;
use Crab\Engine\Tests\Support\TestCase;

final class RelayTest extends TestCase
{
    public function testASlowDestinationPausesTheSourceAndEverythingArrivesInOrder(): void
    {
        $loop = $this->newLoop();
        [$source, $sender] = $this->connectedPair($loop);
        [$destination, $reader] = $this->connectedPair($loop, capacity: 32_768);
        $relay = new Relay($destination, $source);
        $progress = 0;
        $relay->onProgress(static function () use (&$progress): void {
            $progress++;
        });
        $relayed = 0;
        $mostHeld = 0;
        $source->onData(static function (Connection $source, string $bytes) use ($relay, &$relayed, &$mostHeld): void {
            $relay->write($bytes);
            $relayed += strlen($bytes);
            $mostHeld = max($mostHeld, $relay->heldBytes());
        });
        $payload = random_bytes(4_000_000);
        $sent = 0;
        $send = $loop->onWritable($sender, static function (string $watcher) use (
            $loop,
            $sender,
            $payload,
            &$sent,
        ): void {
            $written = @fwrite($sender, substr($payload, $sent, 65_536));
            $sent += (int) $written;
            if ($sent === strlen($payload)) {
                $loop->cancel($watcher);
            }
        });

        // Nobody reads yet: the relay must stop taking from the source.
        $stalledAt = -1;
        $this->until(
            $loop,
            static function () use (&$relayed, &$stalledAt): bool {
                $stalled = $relayed > 0 && $relayed === $stalledAt;
                $stalledAt = $relayed;
                return $stalled;
            },
            interval: 0.05,
            message: 'The relay never stopped reading its source.',
        );
        $heldWhileStalled = $relay->heldBytes();
        $relayedWhileStalled = $relayed;

        $received = '';
        $loop->onReadable($reader, static function () use ($reader, &$received): void {
            $received .= (string) fread($reader, 65_536);
        });
        $this->until(
            $loop,
            static function () use (&$received, $payload): bool {
                return strlen($received) === strlen($payload);
            },
            timeout: 10.0,
            message: 'The relayed bytes did not all arrive.',
        );
        $loop->cancel($send);

        self::assertGreaterThan(0, $heldWhileStalled, 'Nothing was held for the slow destination.');
        self::assertLessThan(strlen($payload), $relayedWhileStalled, 'The source was read to its end with no reader.');
        self::assertLessThanOrEqual(262_144, $mostHeld, 'The relay held more than one read turn of its source.');
        self::assertSame(hash('xxh128', $payload), hash('xxh128', $received));
        self::assertSame(0, $relay->heldBytes());
        self::assertGreaterThan(0, $progress);
        self::assertSame([], $this->reports);
    }

    public function testEndFlushesWhatIsHeldBeforeClosingTheDestination(): void
    {
        $loop = $this->newLoop();
        [$destination, $reader] = $this->connectedPair($loop, capacity: 8192);
        $relay = new Relay($destination);
        $payload = random_bytes(3_000_000);

        self::assertTrue($relay->write($payload));
        self::assertGreaterThan(0, $relay->heldBytes());
        $relay->end();
        self::assertTrue($relay->isEnding());
        self::assertFalse($relay->write('late'));
        self::assertFalse($destination->isClosed(), 'The destination closed before its held bytes were sent.');

        $received = '';
        $ended = false;
        $loop->onReadable($reader, static function (string $watcher) use ($loop, $reader, &$received, &$ended): void {
            $received .= (string) fread($reader, 65_536);
            if (feof($reader)) {
                $ended = true;
                $loop->cancel($watcher);
            }
        });
        $this->until(
            $loop,
            static function () use (&$ended): bool {
                return $ended;
            },
            timeout: 10.0,
            message: 'The destination was not ended after the held bytes.',
        );

        self::assertSame(hash('xxh128', $payload), hash('xxh128', $received));
        self::assertTrue($destination->isClosed());
    }

    public function testAClosedDestinationDropsWhatIsHeldAndLeavesTheSourceToItsOwner(): void
    {
        $loop = $this->newLoop();
        [$source] = $this->connectedPair($loop);
        [$destination] = $this->connectedPair($loop, capacity: 4096);
        $relay = new Relay($destination, $source);
        $source->onData(static function (): void {});

        self::assertTrue($relay->write(str_repeat('x', 1_000_000)));
        self::assertGreaterThan(0, $relay->heldBytes());
        $destination->close();

        self::assertSame(0, $relay->heldBytes());
        self::assertFalse($relay->write('more'));
        self::assertFalse($source->isClosed());
    }

    public function testASourceNamedLaterIsPausedWhileBytesAreHeldAndReadAgainAfterwards(): void
    {
        $loop = $this->newLoop();
        [$destination, $reader] = $this->connectedPair($loop, capacity: 4096);
        [$source, $sender] = $this->connectedPair($loop);
        $relay = new Relay($destination);
        $fromSource = '';
        $source->onData(static function (Connection $source, string $bytes) use (&$fromSource): void {
            $fromSource .= $bytes;
        });

        self::assertTrue($relay->write(str_repeat('a', 500_000)));
        $relay->from($source);
        fwrite($sender, 'held back');
        $ticks = 0;
        $this->until($loop, static function () use (&$ticks): bool {
            return ++$ticks > 20;
        });
        self::assertSame('', $fromSource, 'The source was read while the destination was behind.');

        $received = 0;
        $loop->onReadable($reader, static function () use ($reader, &$received): void {
            $received += strlen((string) fread($reader, 65_536));
        });
        $this->until(
            $loop,
            static function () use (&$fromSource): bool {
                return $fromSource === 'held back';
            },
            message: 'The source was not read again once the destination caught up.',
        );
        // Handed to the socket is not yet read from it: the reader may still have some to take.
        $this->until(
            $loop,
            static function () use (&$received): bool {
                return $received >= 500_000;
            },
            message: 'Not everything held was delivered to the destination.',
        );
        self::assertSame(500_000, $received);
    }

    public function testATcpServerGivesEachConnectionToItsConnectCallbackBeforeItsBytes(): void
    {
        $loop = $this->newLoop();
        $events = [];
        $server = new TcpServer(static function (Connection $connection, string $bytes) use (&$events): void {
            $events[] = "data:{$bytes}";
            $connection->tryWrite(strtoupper($bytes));
        }, static function (Connection $connection) use (&$events): void {
            $events[] = 'connect:' . $connection->maxWriteSize();
            $connection->onClose(static function () use (&$events): void {
                $events[] = 'close';
            });
        });
        $stream = Listener::bind('tcp://127.0.0.1:0');
        $address = (string) stream_socket_get_name($stream, false);
        $listener = new Listener($loop, $stream, $server, 2048, 1.5);
        $this->deferCleanup($listener->close(...));
        $listener->attach();

        $client = stream_socket_client("tcp://{$address}", $code, $reason, 2.0);
        self::assertIsResource($client, (string) $reason);
        $this->deferCleanup(static function () use ($client): void {
            if (is_resource($client)) {
                fclose($client);
            }
        });
        stream_set_blocking($client, false);
        fwrite($client, 'ping');
        $answer = '';
        $this->until(
            $loop,
            static function () use ($client, &$answer): bool {
                $answer .= (string) fread($client, 16);
                return $answer === 'PING';
            },
            message: 'The TCP server did not answer.',
        );
        fclose($client);
        $this->until(
            $loop,
            static function () use (&$events): bool {
                return in_array('close', $events, true);
            },
            message: 'The connection callback did not hear of the close.',
        );

        self::assertSame(['connect:2048', 'data:ping', 'close'], $events);
    }

    public function testATcpServerLeavesAConnectionItsConnectCallbackClosed(): void
    {
        $loop = $this->newLoop();
        [$connection, $peer] = $this->connectedPair($loop);
        $read = 0;
        $server = new TcpServer(
            static function () use (&$read): void {
                $read++;
            },
            static fn(Connection $connection) => $connection->close(),
        );
        fwrite($peer, 'ignored');

        $server->attach($connection);
        $ticks = 0;
        $this->until($loop, static function () use (&$ticks): bool {
            return ++$ticks > 5;
        });

        self::assertTrue($connection->isClosed());
        self::assertSame(0, $read);
    }

    private function newLoop(): StreamSelectEventLoop
    {
        $loop = new StreamSelectEventLoop();
        $this->deferCleanup(static function () use ($loop): void {
            foreach ($loop->getIdentifiers() as $id) {
                $loop->cancel($id);
            }
        });
        return $loop;
    }

    /**
     * @return array{Connection, resource}
     */
    private function connectedPair(EventLoop $loop, int $capacity = 1_048_576): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair, 'Socket pair creation failed.');
        stream_set_blocking($pair[1], false);
        $connection = new Connection($loop, $pair[0], '', $capacity);
        $this->deferCleanup(static function () use ($connection, $pair): void {
            $connection->close();
            if (is_resource($pair[1])) {
                fclose($pair[1]);
            }
        });
        return [$connection, $pair[1]];
    }

    private function until(
        EventLoop $loop,
        Closure $complete,
        float $timeout = 2.0,
        float $interval = 0.001,
        string $message = 'Relay test exceeded its deadline.',
    ): void {
        $timedOut = false;
        $poll = $loop->repeat($interval, static function () use ($complete, $loop): void {
            if ($complete()) {
                $loop->stop();
            }
        });
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
    }
}
