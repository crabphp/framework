<?php

declare(strict_types=1);

namespace Crab\Engine\Async;

use Closure;
use InvalidArgumentException;
use LogicException;
use Crab\Engine\EventLoop;
use Crab\Engine\FailureReporter;
use Throwable;

/**
 * A bounded set of reusable resources, such as connections. A resource is
 * made when one is wanted and none is idle, up to the limit; beyond it the
 * caller waits, first come first served, until one is released, the wait
 * times out or its cancellation fires.
 */
final class Pool
{
    /**
     * @var list<object>
     */
    private array $idle = [];
    /**
     * Held by reference so that an object id cannot be reused while lent.
     *
     * @var array<int, object>
     */
    private array $borrowed = [];
    /**
     * Resources being made, which already count against the limit.
     */
    private int $creating = 0;
    /**
     * Each takes (?object, ?Throwable) and settles one waiting caller.
     *
     * @var array<int, Closure>
     */
    private array $waiting = [];
    private int $nextWaiter = 0;
    private bool $closed = false;

    /**
     * @param Closure(): object $create Makes one resource; it may throw.
     * @param float $timeout Seconds a caller waits for a resource.
     * @param (Closure(object): void)|null $destroy Releases what a resource holds.
     */
    public function __construct(
        private readonly EventLoop $loop,
        private readonly Closure $create,
        private readonly int $max = 10,
        private readonly float $timeout = 5.0,
        private readonly ?Closure $destroy = null,
    ) {
        if ($max < 1 || !is_finite($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException('A pool needs a positive limit and a finite, positive timeout.');
        }
    }

    public function acquire(?Cancellation $cancellation = null): object
    {
        if ($this->closed) {
            throw new LogicException('The pool is closed.');
        }
        $cancellation?->throwIfCancelled();
        if ($this->idle !== []) {
            return $this->lend(array_pop($this->idle));
        }
        if (count($this->borrowed) + $this->creating < $this->max) {
            return $this->lend($this->create());
        }
        $suspension = $this->loop->getSuspension();
        $id = $this->nextWaiter++;
        $settled = false;
        $timer = null;
        $unsubscribe = null;
        $finish = function (?object $resource, ?Throwable $error = null) use ($suspension, $id, &$settled, &$timer, &$unsubscribe): void {
            if ($settled) {
                return;
            }
            $settled = true;
            unset($this->waiting[$id]);
            if ($timer !== null) {
                $this->loop->cancel($timer);
            }
            $unsubscribe?->__invoke();
            if ($error !== null) {
                $suspension->throw($error);
            } else {
                $suspension->resume($resource);
            }
        };
        $this->waiting[$id] = $finish;
        $timer = $this->loop->delay($this->timeout, fn () => $finish(null, new PoolExhaustedException(
            "No resource became free within {$this->timeout} seconds; the pool holds {$this->max}.",
        )));
        $unsubscribe = $cancellation?->subscribe(static fn (CancelledException $error) => $finish(null, $error));
        try {
            return $suspension->suspend();
        } finally {
            $settled = true;
            unset($this->waiting[$id]);
            $this->loop->cancel($timer);
            $unsubscribe?->__invoke();
        }
    }

    /**
     * Returns a resource; the longest-waiting caller receives it at once.
     */
    public function release(object $resource): void
    {
        $this->assertBorrowed($resource);
        if ($this->closed) {
            $this->drop($resource);
            return;
        }
        if ($this->waiting !== []) {
            // It stays lent, now to the waiter.
            $this->waiting[array_key_first($this->waiting)]($resource);
            return;
        }
        unset($this->borrowed[spl_object_id($resource)]);
        $this->idle[] = $resource;
    }

    /**
     * Gives up a resource that can no longer be used. Its place goes to a
     * new one, made at once when a caller is waiting.
     */
    public function discard(object $resource): void
    {
        $this->assertBorrowed($resource);
        $this->drop($resource);
        if ($this->closed || $this->waiting === []) {
            return;
        }
        $waiter = $this->waiting[array_key_first($this->waiting)];
        try {
            $replacement = $this->lend($this->create());
        } catch (Throwable $error) {
            $waiter(null, $error);
            return;
        }
        $waiter($replacement);
    }

    /**
     * Lends a resource for the length of one callback.
     */
    public function run(Closure $callback, ?Cancellation $cancellation = null): mixed
    {
        $resource = $this->acquire($cancellation);
        try {
            return $callback($resource);
        } finally {
            $this->release($resource);
        }
    }

    /**
     * Destroys idle resources now and lent ones as they come back, and
     * refuses further callers, the waiting ones included.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->waiting as $waiter) {
            $waiter(null, new LogicException('The pool is closed.'));
        }
        $idle = $this->idle;
        $this->idle = [];
        foreach ($idle as $resource) {
            $this->destroy($resource);
        }
    }

    /**
     * @return array{max: int, idle: int, borrowed: int, waiting: int}
     */
    public function stats(): array
    {
        return ['max' => $this->max, 'idle' => count($this->idle), 'borrowed' => count($this->borrowed), 'waiting' => count($this->waiting)];
    }

    private function create(): object
    {
        ++$this->creating;
        try {
            return ($this->create)();
        } finally {
            --$this->creating;
        }
    }

    private function lend(object $resource): object
    {
        return $this->borrowed[spl_object_id($resource)] = $resource;
    }

    private function drop(object $resource): void
    {
        unset($this->borrowed[spl_object_id($resource)]);
        $this->destroy($resource);
    }

    private function destroy(object $resource): void
    {
        if ($this->destroy === null) {
            return;
        }
        try {
            ($this->destroy)($resource);
        } catch (Throwable $error) {
            FailureReporter::report('Pool resource cleanup failed', $error);
        }
    }

    private function assertBorrowed(object $resource): void
    {
        if (($this->borrowed[spl_object_id($resource)] ?? null) !== $resource) {
            throw new LogicException('The resource is not on loan from this pool.');
        }
    }
}
