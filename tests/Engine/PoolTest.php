<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Pool;
use Crab\Engine\Async\PoolExhaustedException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\RevoltEventLoop;
use Crab\Engine\Tests\Support\TestCase;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use stdClass;

final class PoolTest extends TestCase
{
    public function testResourcesAreMadeOnDemandAndReused(): void
    {
        $made = 0;
        $pool = new Pool(
            $this->newLoop(),
            static function () use (&$made): stdClass {
                $resource = new stdClass();
                $resource->number = ++$made;
                return $resource;
            },
            max: 2,
        );
        self::assertSame(['max' => 2, 'idle' => 0, 'borrowed' => 0, 'waiting' => 0], $pool->stats());

        $first = $pool->acquire();
        $second = $pool->acquire();
        self::assertSame([1, 2], [$first->number, $second->number]);
        $pool->release($first);
        self::assertSame($first, $pool->acquire(), 'An idle resource must be reused before another is made.');
        $pool->release($first);
        $pool->release($second);
        self::assertSame(2, $made);
        self::assertSame(['max' => 2, 'idle' => 2, 'borrowed' => 0, 'waiting' => 0], $pool->stats());
        self::assertSame(
            'result 2',
            $pool->run(static fn(stdClass $resource): string => 'result ' . $resource->number),
        );
        self::assertSame(2, $pool->stats()['idle']);
    }

    public function testCallersBeyondTheLimitWaitInOrder(): void
    {
        $loop = $this->newLoop();
        $pool = new Pool($loop, static fn(): stdClass => new stdClass(), max: 1);
        $held = $pool->acquire();
        $order = [];
        $tasks = [];
        foreach (['first', 'second', 'third'] as $name) {
            $tasks[] = $this->spawn($loop, static function () use ($pool, $loop, $name, &$order): object {
                $resource = $pool->acquire();
                $order[] = $name;
                Async::sleep($loop, 0.001);
                $pool->release($resource);
                return $resource;
            });
        }
        $loop->defer(static function () use ($pool, $held): void {
            self::assertSame(3, $pool->stats()['waiting']);
            $pool->release($held);
        });
        foreach ($tasks as $task) {
            self::assertSame($held, $this->awaitTask($loop, $task), 'Waiters must receive the one pooled resource.');
        }
        self::assertSame(['first', 'second', 'third'], $order);
        self::assertSame(['max' => 1, 'idle' => 1, 'borrowed' => 0, 'waiting' => 0], $pool->stats());
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testAWaitTimesOutOrIsCancelledWithoutLeavingAWaiter(): void
    {
        $loop = $this->newLoop();
        $pool = new Pool($loop, static fn(): stdClass => new stdClass(), max: 1, timeout: 0.01);
        $held = $pool->acquire();
        try {
            $pool->acquire();
            self::fail('An exhausted pool lent a resource.');
        } catch (PoolExhaustedException $error) {
            self::assertStringContainsString('the pool holds 1', $error->getMessage());
        }

        $token = new Cancellation();
        $loop->delay(0.002, static fn() => $token->cancel());
        try {
            $pool->acquire($token);
            self::fail('A cancelled wait returned.');
        } catch (CancelledException) {
        }
        try {
            $pool->acquire($token);
            self::fail('A cancelled caller was served.');
        } catch (CancelledException) {
        }
        self::assertSame(0, $pool->stats()['waiting']);
        self::assertSame([], $loop->getIdentifiers());

        $pool->release($held);
        self::assertSame($held, $pool->acquire(), 'A released resource must not go to a caller that gave up.');
    }

    public function testDiscardReplacesABrokenResource(): void
    {
        $loop = $this->newLoop();
        $destroyed = [];
        $made = 0;
        $pool = new Pool(
            $loop,
            static function () use (&$made): stdClass {
                $resource = new stdClass();
                $resource->number = ++$made;
                return $resource;
            },
            max: 1,
            destroy: static function (stdClass $resource) use (&$destroyed): void {
                $destroyed[] = $resource->number;
            },
        );
        $broken = $pool->acquire();
        $pool->discard($broken);
        self::assertSame([[1], 0], [$destroyed, $pool->stats()['borrowed']]);

        $second = $pool->acquire();
        $waiter = $this->spawn($loop, static fn(): object => $pool->acquire());
        $loop->defer(static fn() => $pool->discard($second));
        $third = $this->awaitTask($loop, $waiter);
        self::assertSame([3, [1, 2]], [$third->number, $destroyed]);
        self::assertSame(1, $pool->stats()['borrowed']);
    }

    public function testAFailedCreateFreesItsPlaceAndReachesAWaiter(): void
    {
        $loop = $this->newLoop();
        $fail = true;
        $pool = new Pool(
            $loop,
            static function () use (&$fail): stdClass {
                if ($fail) {
                    throw new RuntimeException('cannot connect');
                }
                return new stdClass();
            },
            max: 1,
        );
        try {
            $pool->acquire();
            self::fail('A failed create returned a resource.');
        } catch (RuntimeException $error) {
            self::assertSame('cannot connect', $error->getMessage());
        }
        $fail = false;
        $resource = $pool->acquire();
        self::assertSame(1, $pool->stats()['borrowed'], 'The failed create must not keep a place.');

        $fail = true;
        $waiter = $this->spawn($loop, static fn(): object => $pool->acquire());
        $loop->defer(static fn() => $pool->discard($resource));
        try {
            $this->awaitTask($loop, $waiter);
            self::fail('A waiter received nothing and no failure.');
        } catch (RuntimeException $error) {
            self::assertSame('cannot connect', $error->getMessage());
        }
        self::assertSame(['max' => 1, 'idle' => 0, 'borrowed' => 0, 'waiting' => 0], $pool->stats());
    }

    public function testCloseDestroysResourcesAndRefusesCallers(): void
    {
        $loop = $this->newLoop();
        $destroyed = 0;
        $pool = new Pool($loop, static fn(): stdClass => new stdClass(), max: 2, destroy: static function () use (
            &$destroyed,
        ): void {
            ++$destroyed;
            throw new RuntimeException('cleanup failures are contained');
        });
        $idle = $pool->acquire();
        $pool->release($idle);
        $lent = [$pool->acquire(), $pool->acquire()];
        self::assertSame($idle, $lent[0]);
        $pool->release($lent[0]);
        $lent[0] = $pool->acquire();
        $waiter = $this->spawn($loop, static fn(): object => $pool->acquire());
        $loop->defer(static fn() => $pool->close());
        try {
            $this->awaitTask($loop, $waiter);
            self::fail('A waiter survived close.');
        } catch (LogicException) {
        }
        self::assertSame(0, $destroyed, 'Lent resources are not destroyed while lent.');
        $pool->release($lent[0]);
        $pool->release($lent[1]);
        self::assertSame(2, $destroyed, 'A lent resource is destroyed when it comes back.');
        $pool->close();

        $other = new Pool($loop, static fn(): stdClass => new stdClass(), destroy: static function () use (
            &$destroyed,
        ): void {
            ++$destroyed;
        });
        $other->release($other->acquire());
        $other->close();
        self::assertSame(3, $destroyed, 'Idle resources are destroyed on close.');
        self::assertSame(
            array_fill(0, 2, 'Pool resource cleanup failed: cleanup failures are contained'),
            $this->reports,
        );
        $this->expectException(LogicException::class);
        $pool->acquire();
    }

    public function testMisuseIsRejected(): void
    {
        $loop = $this->newLoop();
        $pool = new Pool($loop, static fn(): stdClass => new stdClass());
        $resource = $pool->acquire();
        $pool->release($resource);
        $rejected = 0;
        foreach ([
            static fn() => $pool->release($resource),
            static fn() => $pool->release(new stdClass()),
            static fn() => $pool->discard($resource),
        ] as $misuse) {
            try {
                $misuse();
            } catch (LogicException) {
                ++$rejected;
            }
        }
        foreach ([
            static fn() => new Pool($loop, static fn() => new stdClass(), max: 0),
            static fn() => new Pool($loop, static fn() => new stdClass(), timeout: 0.0),
            static fn() => new Pool($loop, static fn() => new stdClass(), timeout: INF),
        ] as $invalid) {
            try {
                $invalid();
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }
        self::assertSame(6, $rejected);
    }

    private function newLoop(): RevoltEventLoop
    {
        $loop = RevoltEventLoop::create();
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
        $deadline = $loop->delay(2, static fn() => throw new RuntimeException('Pool test exceeded its deadline.'));
        try {
            return $task->await();
        } finally {
            $loop->cancel($deadline);
        }
    }
}
