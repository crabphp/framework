<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Closure;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\Connector;
use Crab\Engine\Network\Listener;
use Crab\Engine\Network\WriteResult;
use Crab\Engine\Tests\Support\TestCase;
use InvalidArgumentException;
use LengthException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\RevoltEventLoop;
use RuntimeException;
use Throwable;

final class TransportTest extends TestCase
{
    public function testBinaryReadPauseResumeAndReaderReplacement(): void
    {
        [$loop, $connection, $peer] = $this->connectedPair();
        $received = '';
        $oldReaderCalls = 0;
        $connection->onData(static function () use (&$oldReaderCalls): void { $oldReaderCalls++; });
        $connection->onData(static function (Connection $connection, string $bytes) use (&$received): void {
            $received .= $bytes;
        });
        $connection->pauseRead();
        fwrite($peer, "\0binary\xff");
        $loop->delay(0.01, $this->guardCallback(static function () use (&$received, $connection): void {
            self::assertSame('', $received, 'Paused reader received bytes.');
            $connection->resumeRead();
        }));
        $this->until($loop, static function () use (&$received): bool { return strlen($received) === 8; });
        self::assertSame("\0binary\xff", $received);
        self::assertSame(0, $oldReaderCalls, 'Reader ownership was not replaced.');
    }

    public function testShortReadsContinueUntilDataAndHalfCloseAreDelivered(): void
    {
        [$loop, $connection, $peer] = $this->connectedPair();
        $events = [];
        $connection->onData(static function (Connection $connection, string $bytes) use ($loop, $peer, &$events): void {
            $events[] = $bytes;
            if (count($events) === 1) {
                $loop->defer(static function () use ($peer): void {
                    fwrite($peer, 'second');
                    stream_socket_shutdown($peer, STREAM_SHUT_WR);
                });
            }
        });
        $connection->onEof(static function (Connection $connection) use (&$events): void {
            $events[] = 'EOF';
            $connection->close();
        });
        fwrite($peer, 'first');
        $this->until($loop, static fn () => $connection->isClosed());
        self::assertSame(['first', 'second', 'EOF'], $events);
    }

    public function testPartialWritesRejectAtomicallyAndNotifyBelowWatermark(): void
    {
        [$loop, $connection, $peer] = $this->connectedPair(65_536);
        $accepted = 0;
        for ($attempt = 0; $attempt < 10_000 && $connection->bufferedBytes() === 0; $attempt++) {
            self::assertSame(WriteResult::Accepted, $connection->tryWrite(str_repeat('A', 8192)));
            $accepted += 8192;
        }
        self::assertGreaterThan(0, $connection->bufferedBytes(), 'Could not fill socket.');
        self::assertLessThanOrEqual(32_768, $connection->bufferedBytes(), 'Pressure must remain below watermark.');
        self::assertSame(WriteResult::Backpressured, $connection->tryWrite(str_repeat('B', 65_536)));
        $retried = false;
        $notifications = 0;
        $connection->onDrain(static function (Connection $connection) use (&$retried, &$notifications): void {
            $notifications++;
            if (!$retried) {
                $retried = $connection->tryWrite(str_repeat('B', 65_536)) === WriteResult::Accepted;
            }
        });
        $received = '';
        $watcher = $loop->onReadable($peer, static function () use ($peer, &$received): void {
            $received .= fread($peer, 262_144);
        });
        $this->until($loop, static function () use (&$retried, &$received, $accepted, $connection): bool {
            return $retried && strlen($received) === $accepted + 65_536 && $connection->bufferedBytes() === 0;
        });
        self::assertSame(str_repeat('A', $accepted).str_repeat('B', 65_536), $received, 'Output was duplicated, dropped, or reordered.');
        self::assertGreaterThan(0, $notifications, 'Rejected writer never received capacity notification.');
        $loop->cancel($watcher);
    }

    public function testOversizeRejectionAndAdditiveCloseCleanup(): void
    {
        [$loop, $connection, $peer] = $this->connectedPair(4);
        $oversize = false;
        try { $connection->tryWrite('12345'); }
        catch (LengthException) { $oversize = true; }
        self::assertTrue($oversize, 'Oversized write must be rejected.');
        self::assertSame('', fread($peer, 10), 'Oversized write sent a prefix.');
        $closed = [];
        $connection->onClose(static function (Connection $connection) use (&$closed, $loop): void {
            $closed[] = $connection->isClosed() && $connection->bufferedBytes() === 0 && $loop->getIdentifiers() === [];
        });
        $connection->onClose(static function () use (&$closed): void { $closed[] = true; });
        $connection->close();
        $connection->close();
        self::assertSame([true, true], $closed, 'Close callbacks ran before cleanup, were lost, or repeated.');
        self::assertSame(WriteResult::Closed, $connection->tryWrite('a'));
    }

    public function testServiceActivityRetainsOwnershipUntilAllCleanupReturns(): void
    {
        [$loop, $connection] = $this->connectedPair();
        $releaseOne = $connection->beginActivity();
        $releaseTwo = $connection->beginActivity();
        $this->deferCleanup($releaseOne);
        $this->deferCleanup($releaseTwo);
        $events = [];
        $connection->onClose(static function () use (&$events, $releaseOne): void {
            $events[] = 'close';
            $releaseOne();
        });
        $connection->onClose(static function () use (&$events): void { $events[] = 'close-after-release'; });
        $connection->onSettled(static function () use (&$events): void { $events[] = 'settled'; });
        $connection->close();
        self::assertTrue($connection->isClosed(), 'Activity must not keep the socket open.');
        self::assertSame(['close', 'close-after-release'], $events, 'Settlement ran before activity cleanup.');
        $releaseOne();
        $releaseTwo();
        $releaseTwo();
        self::assertSame(['close', 'close-after-release', 'settled'], $events, 'Release must be idempotent.');
        $connection->onSettled(static function () use (&$events): void { $events[] = 'late'; });
        self::assertSame('late', $events[3]);
        $this->expectException(LogicException::class);
        $connection->beginActivity();
    }

    public function testPeerHalfClosePermitsResponseBeforeFin(): void
    {
        [$loop, $connection, $peer] = $this->connectedPair();
        $request = '';
        $connection->onData(static function (Connection $connection, string $bytes) use (&$request): void { $request .= $bytes; });
        $connection->onEof(static function (Connection $connection): void {
            $connection->tryWrite('after EOF');
            $connection->shutdownWrite();
        });
        fwrite($peer, 'request');
        stream_socket_shutdown($peer, STREAM_SHUT_WR);
        $reply = '';
        $watcher = $loop->onReadable($peer, static function () use ($peer, &$reply): void { $reply .= fread($peer, 1024); });
        $this->until($loop, static function () use ($connection, &$reply): bool { return $connection->isClosed() && $reply === 'after EOF'; });
        self::assertSame('request', $request, 'Request preceding EOF was lost.');
        $loop->cancel($watcher);
    }

    public function testLocalHalfCloseContinuesReceiving(): void
    {
        [$loop, $connection, $peer] = $this->connectedPair();
        $received = '';
        $connection->onData(static function (Connection $connection, string $bytes) use (&$received): void { $received .= $bytes; });
        $connection->tryWrite('out');
        $connection->shutdownWrite();
        self::assertFalse($connection->isClosed(), 'shutdownWrite closed the read direction.');
        self::assertSame('out', fread($peer, 1024));
        fwrite($peer, 'in');
        stream_socket_shutdown($peer, STREAM_SHUT_WR);
        $this->until($loop, static fn () => $connection->isClosed());
        self::assertSame('in', $received);
    }

    public function testHandlerFailureCleansUpAndReportsOnce(): void
    {
        [$loop, $connection, $peer] = $this->connectedPair();
        $reported = [];
        $closed = 0;
        $connection->onClose(static function () use (&$closed): void { $closed++; });
        $connection->onError(static function (Throwable $error, Connection $connection) use (&$reported): void {
            $reported[] = [$connection->isClosed(), $error->getMessage()];
        });
        $connection->onData(static function (): void { throw new RuntimeException('expected failure'); });
        fwrite($peer, 'x');
        $this->until($loop, static fn () => $connection->isClosed());
        self::assertSame([[true, 'expected failure']], $reported, 'Reporting must follow cleanup and occur once.');
        self::assertSame(1, $closed);
    }

    public function testGracefulCloseDeadlineBoundsSlowReaders(): void
    {
        [$loop, $connection] = $this->connectedPair(65_536, 0.02);
        for ($attempt = 0; $attempt < 10_000 && $connection->bufferedBytes() === 0; $attempt++) {
            $connection->tryWrite(str_repeat('x', 8192));
        }
        self::assertGreaterThan(0, $connection->bufferedBytes(), 'Could not fill socket.');
        $connection->end();
        $this->until($loop, static fn () => $connection->isClosed());
        self::assertSame(0, $connection->bufferedBytes());
        self::assertSame([], $loop->getIdentifiers(), 'Close deadline leaked resources.');
    }

    public function testDrainingUsesTheWorkersAllowanceInsteadOfAnEarlierCloseTimer(): void
    {
        [$loop, $connection] = $this->connectedPair(closeTimeout: 0.01);
        $connection->onDrainRequest(static function (): void {});
        $connection->shutdownWrite();
        $connection->drain(0.1);
        $checked = false;
        $loop->delay(0.04, $this->guardCallback(static function () use ($connection, &$checked): void {
            self::assertFalse($connection->isClosed(), 'The transport retained its earlier deadline.');
            $checked = true;
        }));
        $this->until($loop, static fn (): bool => $connection->isClosed());
        self::assertTrue($checked);
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testDrainingWithoutAnOverrideKeepsTheTransportCloseAllowance(): void
    {
        [$loop, $connection] = $this->connectedPair(closeTimeout: 0.02);
        $connection->onDrainRequest(static function (): void {});
        $connection->drain();
        $this->until($loop, static fn (): bool => $connection->isClosed());
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testDrainingRejectsInvalidAllowancesWithoutClosingTheConnection(): void
    {
        [, $connection] = $this->connectedPair();
        foreach ([0.0, -1.0, INF, NAN] as $timeout) {
            try {
                $connection->drain($timeout);
                self::fail('An invalid drain allowance was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('drain timeout', $error->getMessage());
                self::assertFalse($connection->isClosed());
            }
        }
    }

    public function testTheProtocolOwnerDeterminesWhetherAnOpenConnectionHasPendingWork(): void
    {
        [, $connection] = $this->connectedPair();
        self::assertTrue($connection->hasPendingWork(), 'An unclassified stream may carry work.');
        $pending = false;
        $connection->onDrainRequest(static function (): void {}, static function () use (&$pending): bool {
            return $pending;
        });
        self::assertFalse($connection->hasPendingWork());
        $pending = true;
        self::assertTrue($connection->hasPendingWork());
        $connection->onDrainRequest(static function (): void {});
        self::assertTrue($connection->hasPendingWork(), 'Replacing the owner must release its predicate.');
        $connection->close();
        self::assertFalse($connection->hasPendingWork());
    }

    public function testSuccessfulConnectCleansUpBeforeCallbackAndIgnoresLaterCancellation(): void
    {
        $loop = $this->newLoop();
        $stream = $this->ownStream(Listener::bind('tcp://127.0.0.1:0'));
        $address = 'tcp://'.stream_socket_get_name($stream, false);
        $serverConnection = null;
        $listener = new Listener($loop, $stream, function (Connection $connection) use (&$serverConnection): void {
            $serverConnection = $this->ownConnection($connection);
            $connection->onData(static function (Connection $connection, string $bytes): void { $connection->tryWrite($bytes); });
        });
        $this->deferCleanup($listener->close(...));
        $listener->attach();
        $completions = 0;
        $response = '';
        $client = null;
        $cancel = Connector::connect($loop, $address, $this->guardCallback(function (?Connection $connection, ?Throwable $error) use (&$completions, &$response, &$client): void {
            $completions++;
            self::assertNull($error);
            self::assertInstanceOf(Connection::class, $connection);
            $client = $this->ownConnection($connection);
            $connection->onData(static function (Connection $connection, string $bytes) use (&$response): void { $response .= $bytes; });
            $connection->tryWrite('ping');
        }));
        $this->deferCleanup($cancel);
        $this->until($loop, static function () use (&$response): bool { return $response === 'ping'; });
        $cancel();
        self::assertSame(1, $completions);
        self::assertFalse($client->isClosed(), 'Cancellation changed an established connection.');
        $client->close();
        $serverConnection->close();
        $listener->close();
        self::assertSame([], $loop->getIdentifiers(), 'Connect leaked its watcher or deadline.');
    }

    public function testConnectRefusalAndCancellationCompleteOnce(): void
    {
        $loop = $this->newLoop();
        $stream = $this->ownStream(Listener::bind('tcp://127.0.0.1:0'));
        $address = 'tcp://'.stream_socket_get_name($stream, false);
        fclose($stream);
        $failures = [];
        $cancel = Connector::connect($loop, $address, static function (?Connection $connection, ?Throwable $error) use (&$failures): void {
            $failures[] = [$connection, $error];
        });
        $this->deferCleanup($cancel);
        $this->until($loop, static function () use (&$failures): bool { return count($failures) === 1; });
        $cancel();
        self::assertCount(1, $failures);
        self::assertNull($failures[0][0], 'Refused connection was published as established.');
        self::assertInstanceOf(Throwable::class, $failures[0][1]);
        self::assertSame([], $loop->getIdentifiers(), 'Refusal did not clean up.');
        $stream = $this->ownStream(Listener::bind('tcp://127.0.0.1:0'));
        $address = 'tcp://'.stream_socket_get_name($stream, false);
        $cancel = Connector::connect($loop, $address, static function (?Connection $connection, ?Throwable $error) use (&$failures): void {
            $failures[] = [$connection, $error];
        });
        $this->deferCleanup($cancel);
        $cancel();
        $cancel();
        self::assertCount(2, $failures);
        self::assertNull($failures[1][0]);
        self::assertStringContainsString('cancelled', $failures[1][1]?->getMessage() ?? '');
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testListenerRejectsUnsupportedTlsScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Listener::bind('tls://127.0.0.1:0');
    }

    #[DataProvider('unsupportedConnectAddresses')]
    public function testConnectorRejectsBlockingDnsCredentialsAndPaths(string $address): void
    {
        $this->expectException(InvalidArgumentException::class);
        Connector::connect($this->newLoop(), $address, static function (): void {});
    }

    public static function unsupportedConnectAddresses(): iterable
    {
        yield 'blocking DNS' => ['tcp://localhost:80'];
        yield 'credentials' => ['tcp://name@127.0.0.1:80'];
        yield 'path' => ['tcp://127.0.0.1:80/path'];
    }

    private function newLoop(): RevoltEventLoop
    {
        $loop = RevoltEventLoop::create();
        $this->deferCleanup(static function () use ($loop): void {
            foreach ($loop->getIdentifiers() as $id) { $loop->cancel($id); }
        });
        return $loop;
    }

    private function ownStream(mixed $stream): mixed
    {
        self::assertIsResource($stream);
        $this->deferCleanup(static function () use ($stream): void {
            if (is_resource($stream)) { fclose($stream); }
        });
        return $stream;
    }

    private function ownConnection(Connection $connection): Connection
    {
        $this->deferCleanup($connection->close(...));
        return $connection;
    }

    /**
     * @return array{RevoltEventLoop, Connection, resource}
     */
    private function connectedPair(int $capacity = 1_048_576, float $closeTimeout = 5.0): array
    {
        $loop = $this->newLoop();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair, 'Socket pair creation failed.');
        $server = $this->ownStream($pair[0]);
        $peer = $this->ownStream($pair[1]);
        stream_set_blocking($peer, false);
        return [$loop, $this->ownConnection(new Connection($loop, $server, '', $capacity, $closeTimeout)), $peer];
    }

    private function until(EventLoop $loop, Closure $complete, float $timeout = 2.0): void
    {
        $timedOut = false;
        $poll = $loop->repeat(0.001, static function () use ($complete, $loop): void {
            if ($complete()) { $loop->stop(); }
        });
        $deadline = $loop->delay($timeout, static function () use (&$timedOut, $loop): void {
            $timedOut = true;
            $loop->stop();
        });
        try { $loop->run(); }
        finally {
            $loop->cancel($poll);
            $loop->cancel($deadline);
        }
        self::assertFalse($timedOut, 'Transport test exceeded its deadline.');
        self::assertTrue($complete(), 'EventLoop exited before completion.');
    }
}
