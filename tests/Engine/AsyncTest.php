<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Closure;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\ConnectionStream;
use Crab\Engine\Async\Task;
use Crab\Engine\Network\Connection;
use Crab\Engine\Tests\Support\TestCase;
use LogicException;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use RuntimeException;

final class AsyncTest extends TestCase
{
    public function testTaskResultsConcurrentSleepsAndCompletionCleanup(): void
    {
        $loop = $this->newLoop();
        $order = [];
        $one = $this->spawn($loop, static function (Cancellation $token) use ($loop, &$order): int {
            Async::sleep($loop, 0.03, $token);
            $order[] = 'slow';
            return 41;
        });
        $two = $this->spawn($loop, static function (Cancellation $token) use ($loop, &$order): int {
            Async::sleep($loop, 0.001, $token);
            $order[] = 'fast';
            return 1;
        });
        $completions = [];
        $one->onComplete($this->guardCallback(static function (Task $task) use (&$completions): void {
            $completions[] = [$task->isComplete(), $task->result()];
        }));
        self::assertSame(42, $this->awaitTask($loop, $one) + $this->awaitTask($loop, $two));
        self::assertSame(['fast', 'slow'], $order, 'Sleeps blocked each other.');
        self::assertSame([[true, 41]], $completions, 'Completion repeated or ran before the result settled.');
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testCancelBeforeStartAndCancellationSubscriptions(): void
    {
        $loop = $this->newLoop();
        $ran = false;
        $task = $this->spawn($loop, static function () use (&$ran): void { $ran = true; });
        $task->cancel();
        $this->assertTaskCancelled($loop, $task);
        self::assertFalse($ran, 'Cancelled task started.');
        self::assertTrue($task->isComplete());
        self::assertSame([], $loop->getIdentifiers());
        $token = new Cancellation();
        $count = 0;
        $unsubscribe = $token->subscribe(static function () use (&$count): void { $count += 100; });
        $unsubscribe();
        $token->subscribe(static function () use (&$count): void { $count++; });
        $token->cancel();
        $token->cancel();
        $token->subscribe(static function () use (&$count): void { $count++; });
        self::assertSame(2, $count, 'Unsubscribe, late subscription, or cancellation idempotence failed.');
    }

    public function testRunningCancellationUnwindsFinallyAndRemovesDeadline(): void
    {
        $loop = $this->newLoop();
        $cleaned = false;
        $task = $this->spawn($loop, static function (Cancellation $token) use ($loop, &$cleaned): void {
            try { Async::sleep($loop, 60, $token); }
            finally { $cleaned = true; }
        });
        $loop->delay(0.005, static fn () => $task->cancel());
        $this->assertTaskCancelled($loop, $task);
        self::assertTrue($cleaned, 'Task cancellation skipped finally.');
        self::assertTrue($task->isComplete());
        self::assertSame([], $loop->getIdentifiers(), 'Cancelled sleep retained its deadline.');
    }

    public function testCancelledAwaitLeavesIndependentlyOwnedTargetRunning(): void
    {
        $loop = $this->newLoop();
        $target = $this->spawn($loop, static function () use ($loop): int {
            Async::sleep($loop, 0.02);
            return 7;
        });
        $waiter = $this->spawn($loop, static fn (Cancellation $token): int => $target->await($token));
        $loop->delay(0.001, static fn () => $waiter->cancel());
        $this->assertTaskCancelled($loop, $waiter);
        self::assertFalse($target->isComplete(), 'Cancelling await cancelled its target.');
        self::assertSame(7, $this->awaitTask($loop, $target));
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testDemandReadsPreserveLeftoversAndEofAfterLocalFin(): void
    {
        $loop = $this->newLoop();
        [$stream, $connection, $peer] = $this->connectedStream($loop);
        fwrite($peer, "ab\0cdef");
        stream_socket_shutdown($peer, STREAM_SHUT_WR);
        $stream->shutdownWrite();
        $task = $this->spawn($loop, static function (Cancellation $token) use ($stream): array {
            $chunks = [];
            while (($chunk = $stream->read(2, $token)) !== null) { $chunks[] = $chunk; }
            return $chunks;
        });
        self::assertSame(['ab', "\0c", 'de', 'f'], $this->awaitTask($loop, $task), 'Sliced reads or EOF lost data.');
        self::assertTrue($connection->isClosed());
        self::assertSame([], $loop->getIdentifiers(), 'Both FIN directions did not release resources.');
    }

    public function testReadCancellationPreservesSocketForAnotherReader(): void
    {
        $loop = $this->newLoop();
        [$stream, $connection, $peer] = $this->connectedStream($loop);
        $task = $this->spawn($loop, static fn (Cancellation $token) => $stream->read(cancellation: $token));
        $loop->delay(0.002, static fn () => $task->cancel());
        $this->assertTaskCancelled($loop, $task);
        self::assertFalse($connection->isClosed(), 'Read cancellation closed the socket.');
        fwrite($peer, 'next');
        $nextReader = $this->spawn($loop, static fn () => $stream->read());
        self::assertSame('next', $this->awaitTask($loop, $nextReader), 'New reader did not acquire ownership.');
        $stream->close();
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testReadExclusionRejectsASecondPendingReader(): void
    {
        $loop = $this->newLoop();
        [$stream] = $this->connectedStream($loop);
        $first = $this->spawn($loop, static fn (Cancellation $token) => $stream->read(cancellation: $token));
        $second = $this->spawn($loop, static fn () => $stream->read());
        $rejected = false;
        try { $this->awaitTask($loop, $second); }
        catch (LogicException) { $rejected = true; }
        self::assertTrue($rejected, 'Concurrent read was accepted.');
        $first->cancel();
        $this->assertTaskCancelled($loop, $first);
    }

    public function testLargeWritesRespectSmallCapacityAndPreserveEveryByte(): void
    {
        $loop = $this->newLoop();
        [$stream, $connection, $peer] = $this->connectedStream($loop, 4096);
        $payload = str_repeat("payload\0", 131_072);
        $received = '';
        $watcher = $loop->onReadable($peer, static function () use ($peer, &$received): void {
            $received .= fread($peer, 262_144);
        });
        $writer = $this->spawn($loop, static function (Cancellation $token) use ($stream, $payload): void {
            $stream->write($payload, $token);
        });
        $this->awaitTask($loop, $writer);
        self::assertSame(0, $connection->bufferedBytes(), 'Write returned before flushing.');
        $this->until($loop, static function () use (&$received, $payload): bool { return strlen($received) >= strlen($payload); });
        self::assertSame($payload, $received);
        $loop->cancel($watcher);
        $stream->close();
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testWriteCancellationAfterSubmissionClosesConnection(): void
    {
        $loop = $this->newLoop();
        [$stream, $connection] = $this->connectedStream($loop, 4096);
        $writer = $this->spawn($loop, static function (Cancellation $token) use ($stream): void {
            $stream->write(str_repeat('x', 4_194_304), $token);
        });
        $loop->delay(0.005, static fn () => $writer->cancel());
        $this->assertTaskCancelled($loop, $writer);
        self::assertTrue($connection->isClosed(), 'Partial write cancellation did not close the socket.');
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testConnectionCloseSettlesPendingReader(): void
    {
        $loop = $this->newLoop();
        [$stream, $connection] = $this->connectedStream($loop);
        $reader = $this->spawn($loop, static fn () => $stream->read());
        $loop->delay(0.001, static fn () => $connection->close());
        $failure = null;
        try { $this->awaitTask($loop, $reader); }
        catch (RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('Connection closed.', $failure->getMessage());
        self::assertSame([], $loop->getIdentifiers());
    }

    private function newLoop(): StreamSelectDriver
    {
        $loop = new StreamSelectDriver();
        $this->deferCleanup(static function () use ($loop): void {
            foreach ($loop->getIdentifiers() as $id) { $loop->cancel($id); }
        });
        return $loop;
    }

    /**
     * @return array{ConnectionStream, Connection, resource}
     */
    private function connectedStream(StreamSelectDriver $loop, int $capacity = 65_536): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair, 'Socket pair creation failed.');
        foreach ($pair as $socket) {
            $this->deferCleanup(static function () use ($socket): void {
                if (is_resource($socket)) { fclose($socket); }
            });
        }
        stream_set_blocking($pair[1], false);
        $connection = new Connection($loop, $pair[0], '', $capacity);
        $this->deferCleanup($connection->close(...));
        return [new ConnectionStream($connection), $connection, $pair[1]];
    }

    private function spawn(Driver $loop, callable $body): Task
    {
        $task = Task::spawn($loop, $body);
        $this->deferCleanup($task->cancel(...));
        return $task;
    }

    private function awaitTask(Driver $loop, Task $task): mixed
    {
        $deadline = $loop->delay(2, static fn () => throw new RuntimeException('Async test exceeded its deadline.'));
        try { return $task->await(); }
        finally { $loop->cancel($deadline); }
    }

    private function assertTaskCancelled(Driver $loop, Task $task): void
    {
        $cancelled = false;
        try { $this->awaitTask($loop, $task); }
        catch (CancelledException) { $cancelled = true; }
        self::assertTrue($cancelled, 'Task cancellation was ignored.');
    }

    private function until(Driver $loop, Closure $complete): void
    {
        $timedOut = false;
        $poll = $loop->repeat(0.001, static function () use ($complete, $loop): void {
            if ($complete()) { $loop->stop(); }
        });
        $deadline = $loop->delay(2, static function () use (&$timedOut, $loop): void {
            $timedOut = true;
            $loop->stop();
        });
        try { $loop->run(); }
        finally {
            $loop->cancel($poll);
            $loop->cancel($deadline);
        }
        self::assertFalse($timedOut, 'Async test exceeded its deadline.');
        self::assertTrue($complete(), 'Loop exited before completion.');
    }
}
