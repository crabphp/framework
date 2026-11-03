<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Closure;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Engine\Tests\Support\TestCase;
use Crab\Tests\Support\Wait;
use RuntimeException;

final class AsyncTest extends TestCase
{
    public function testTaskResultsConcurrentSleepsAndCompletionCleanup(): void
    {
        $loop = $this->newLoop();
        $order = [];
        $release = false;
        $one = $this->spawn($loop, static function (#[\SensitiveParameter] Cancellation $token) use (
            $loop,
            &$order,
            &$release,
        ): int {
            Wait::until(
                $loop,
                static function () use (&$release): bool {
                    return $release;
                },
                'the other task to finish its concurrent sleep',
            );
            $order[] = 'slow';
            return 41;
        });
        $two = $this->spawn($loop, static function (#[\SensitiveParameter] Cancellation $token) use (
            $loop,
            &$order,
            &$release,
        ): int {
            Async::sleep($loop, 0.001, $token);
            $order[] = 'fast';
            $release = true;
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
        $task = $this->spawn($loop, static function () use (&$ran): void {
            $ran = true;
        });
        $task->cancel();
        $this->assertTaskCancelled($loop, $task);
        self::assertFalse($ran, 'Cancelled task started.');
        self::assertTrue($task->isComplete());
        self::assertSame([], $loop->getIdentifiers());
        $token = new Cancellation();
        $count = 0;
        $unsubscribe = $token->subscribe(static function () use (&$count): void {
            $count += 100;
        });
        $unsubscribe();
        $token->subscribe(static function () use (&$count): void {
            $count++;
        });
        $token->cancel();
        $token->cancel();
        $token->subscribe(static function () use (&$count): void {
            $count++;
        });
        self::assertSame(2, $count, 'Unsubscribe, late subscription, or cancellation idempotence failed.');
    }

    public function testRunningCancellationUnwindsFinallyAndRemovesDeadline(): void
    {
        $loop = $this->newLoop();
        $cleaned = false;
        $task = $this->spawn($loop, static function (#[\SensitiveParameter] Cancellation $token) use (
            $loop,
            &$cleaned,
        ): void {
            try {
                Async::sleep($loop, 60, $token);
            } finally {
                $cleaned = true;
            }
        });
        $loop->delay(0.005, static fn() => $task->cancel());
        $this->assertTaskCancelled($loop, $task);
        self::assertTrue($cleaned, 'Task cancellation skipped finally.');
        self::assertTrue($task->isComplete());
        self::assertSame([], $loop->getIdentifiers(), 'Cancelled sleep retained its deadline.');
    }

    public function testCancelledAwaitLeavesIndependentlyOwnedTargetRunning(): void
    {
        $loop = $this->newLoop();
        $held = null;
        $target = $this->spawn($loop, static function () use ($loop, &$held): int {
            $held = $loop->getSuspension();
            $held->suspend();
            return 7;
        });
        $waiter = $this->spawn($loop, static fn(#[\SensitiveParameter] Cancellation $token): int => $target->await(
            $token,
        ));
        Wait::until(
            $loop,
            static function () use (&$held): bool {
                return $held !== null;
            },
            'the independently owned task to reach its barrier',
        );
        $waiter->cancel();
        $this->assertTaskCancelled($loop, $waiter);
        self::assertFalse($target->isComplete(), 'Cancelling await cancelled its target.');
        $held->resume();
        self::assertSame(7, $this->awaitTask($loop, $target));
        self::assertSame([], $loop->getIdentifiers());
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

    private function spawn(EventLoop $loop, callable $body): Task
    {
        $task = Task::spawn($loop, $body);
        $this->deferCleanup($task->cancel(...));
        return $task;
    }

    private function awaitTask(EventLoop $loop, Task $task): mixed
    {
        $deadline = $loop->delay(2, static fn() => throw new RuntimeException('Async test exceeded its deadline.'));
        try {
            return $task->await();
        } finally {
            $loop->cancel($deadline);
        }
    }

    private function assertTaskCancelled(EventLoop $loop, Task $task): void
    {
        $cancelled = false;
        try {
            $this->awaitTask($loop, $task);
        } catch (CancelledException) {
            $cancelled = true;
        }
        self::assertTrue($cancelled, 'Task cancellation was ignored.');
    }

    private function until(EventLoop $loop, Closure $complete): void
    {
        $timedOut = false;
        $poll = $loop->repeat(0.001, static function () use ($complete, $loop): void {
            if ($complete()) {
                $loop->stop();
            }
        });
        $deadline = $loop->delay(2, static function () use (&$timedOut, $loop): void {
            $timedOut = true;
            $loop->stop();
        });
        try {
            $loop->run();
        } finally {
            $loop->cancel($poll);
            $loop->cancel($deadline);
        }
        self::assertFalse($timedOut, 'Async test exceeded its deadline.');
        self::assertTrue($complete(), 'EventLoop exited before completion.');
    }
}
