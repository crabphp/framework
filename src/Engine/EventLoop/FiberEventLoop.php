<?php

declare(strict_types=1);

namespace Crab\Engine\EventLoop;

use Crab\Engine\EventLoop;
use Crab\Engine\Suspension;

/**
 * What every engine loop backend shares: watcher bookkeeping, timers, the
 * fibers that call callbacks, suspensions and error routing. A backend
 * extends it with how streams and signals are watched and how the loop
 * waits for them: watch(), unwatch() and wait().
 *
 * It keeps Revolt's semantics, from whose AbstractDriver it is ported (MIT,
 * Copyright (c) 2021-2023 Revolt (Aaron Piotrowski, Cees-Jan Kiewiet,
 * Christian Lück, Niklas Keller, and contributors), Copyright (c) 2015-2021
 * amphp (Daniel Lowrey, Aaron Piotrowski, Niklas Keller, Bob Weinand, and
 * contributors)): a watcher enabled during a turn first fires on the next
 * one, a fiber is resumed from a queue the loop runs between callbacks, and
 * the main program runs the loop while it waits on a suspension.
 *
 * What differs is cost. A callback fiber whose callback suspended returns
 * to a pool of spare fibers when that callback ends, where Revolt makes a
 * new fiber for every callback that suspends; the loop counts its enabled,
 * referenced watchers instead of scanning them every turn; and it blocks as
 * soon as nothing is ready, without a non-blocking turn after each busy one.
 */
abstract class FiberEventLoop extends EventLoop
{
    /**
     * The most spare callback fibers kept for reuse.
     */
    private const int SPARE_FIBERS = 128;

    private string $nextId = 'a';

    /**
     * @var array<string, Watcher>
     */
    private array $watchers = [];

    /**
     * Watchers enabled since the last turn began, to watch from the next.
     *
     * @var array<string, Watcher>
     */
    private array $enabling = [];

    /**
     * Deferred callbacks to call on the next turn.
     *
     * @var array<string, Watcher>
     */
    private array $deferring = [];

    /**
     * How many watchers are enabled and referenced; run() returns at none.
     */
    private int $waiting = 0;

    /**
     * @var \SplQueue<Watcher> Watchers due to be called on this turn.
     */
    private readonly \SplQueue $ready;

    /**
     * @var \SplQueue<array{\Fiber, mixed, bool}> Fibers to resume, with a value or a throwable to throw.
     */
    private readonly \SplQueue $resumptions;

    private readonly TimerHeap $timers;

    /**
     * The fiber the loop runs in, suspended to the main program on an interrupt.
     */
    private \Fiber $fiber;

    /**
     * The fiber that calls callbacks, until a callback suspends it.
     */
    private ?\Fiber $worker = null;

    /**
     * @var list<\Fiber> Callback fibers whose suspended callback has ended.
     */
    private array $spares = [];

    /**
     * What a callback fiber suspends with to hand control back to the loop,
     * as distinct from a callback suspending.
     */
    private readonly object $handBack;

    /**
     * @var (\Closure():mixed)|null What the main program is to do when the loop returns to it.
     */
    private ?\Closure $interrupt = null;

    /**
     * @var (\Closure(\Throwable):void)|null
     */
    private ?\Closure $errorHandler = null;

    private bool $stopped = false;

    /**
     * @var \WeakMap<object, \WeakReference<FiberSuspension>>
     */
    private \WeakMap $suspensions;

    /**
     * The key of the main program's suspension.
     */
    private readonly object $main;

    /**
     * @var \Closure():(\Closure():mixed)|null
     */
    private readonly \Closure $runner;
    private readonly \Closure $resumer;
    private readonly \Closure $interrupter;

    public function __construct()
    {
        $this->ready = new \SplQueue();
        $this->resumptions = new \SplQueue();
        $this->timers = new TimerHeap();
        $this->handBack = new \stdClass();
        $this->main = new \stdClass();
        $this->suspensions = new \WeakMap();
        $this->createLoopFiber();
        $this->runner = function (): ?\Closure {
            do {
                if ($this->fiber->isTerminated()) {
                    $this->createLoopFiber();
                }
                $interrupt = $this->fiber->isStarted() ? $this->fiber->resume() : $this->fiber->start();
                if ($interrupt !== null) {
                    return $interrupt;
                }
            } while (gc_collect_cycles() > 0 && !$this->stopped);
            return null;
        };
        $this->resumer = function (\Fiber $fiber, mixed $value, bool $throw): void {
            $this->resumptions->enqueue([$fiber, $value, $throw]);
        };
        $this->interrupter = function (\Closure $interrupt): void {
            $this->interrupt = $interrupt;
        };
    }

    final public function run(): void
    {
        if ($this->fiber->isRunning()) {
            throw new \Error('The event loop is already running.');
        }
        if (\Fiber::getCurrent() !== null) {
            throw new \Error('The event loop runs from the main program, not inside a fiber.');
        }
        $interrupt = ($this->runner)();
        if ($interrupt !== null) {
            $interrupt();
            throw new \Error('An interrupt from the event loop must throw.');
        }
    }

    final public function stop(): void
    {
        $this->stopped = true;
    }

    final public function isRunning(): bool
    {
        return $this->fiber->isRunning() || $this->fiber->isSuspended();
    }

    final public function defer(\Closure $callback): string
    {
        $watcher = new Watcher($this->nextId(), Watcher::DEFER, $callback);
        $this->watchers[$watcher->id] = $watcher;
        $this->deferring[$watcher->id] = $watcher;
        $this->waiting++;
        return $watcher->id;
    }

    final public function delay(float $seconds, \Closure $callback): string
    {
        return $this->timer(Watcher::DELAY, $seconds, $callback);
    }

    final public function repeat(float $interval, \Closure $callback): string
    {
        return $this->timer(Watcher::REPEAT, $interval, $callback);
    }

    public function onReadable(mixed $stream, \Closure $callback): string
    {
        return $this->add(new Watcher($this->nextId(), Watcher::READABLE, $callback, $stream));
    }

    public function onWritable(mixed $stream, \Closure $callback): string
    {
        return $this->add(new Watcher($this->nextId(), Watcher::WRITABLE, $callback, $stream));
    }

    public function onSignal(int $signal, \Closure $callback): string
    {
        return $this->add(new Watcher($this->nextId(), Watcher::SIGNAL, $callback, $signal));
    }

    final public function enable(string $id): void
    {
        $watcher = $this->watchers[$id] ?? throw self::unknown($id);
        if ($watcher->enabled) {
            return;
        }
        $watcher->enabled = true;
        if ($watcher->referenced) {
            $this->waiting++;
        }
        if ($watcher->type === Watcher::DEFER) {
            $this->deferring[$id] = $watcher;
            return;
        }
        if ($watcher->type === Watcher::DELAY || $watcher->type === Watcher::REPEAT) {
            $watcher->expiration = self::now() + $watcher->interval;
        }
        $this->enabling[$id] = $watcher;
    }

    final public function disable(string $id): void
    {
        $watcher = $this->watchers[$id] ?? null;
        if ($watcher === null || !$watcher->enabled) {
            return;
        }
        $watcher->enabled = false;
        $watcher->invokable = false;
        if ($watcher->referenced) {
            $this->waiting--;
        }
        if ($watcher->type === Watcher::DEFER) {
            unset($this->deferring[$id]);
        } elseif (isset($this->enabling[$id])) {
            unset($this->enabling[$id]);
        } elseif ($watcher->type === Watcher::DELAY || $watcher->type === Watcher::REPEAT) {
            $this->timers->remove($watcher);
        } else {
            $this->unwatch($watcher);
        }
    }

    final public function cancel(string $id): void
    {
        $this->disable($id);
        unset($this->watchers[$id]);
    }

    final public function reference(string $id): void
    {
        $watcher = $this->watchers[$id] ?? throw self::unknown($id);
        if (!$watcher->referenced) {
            $watcher->referenced = true;
            if ($watcher->enabled) {
                $this->waiting++;
            }
        }
    }

    final public function unreference(string $id): void
    {
        $watcher = $this->watchers[$id] ?? null;
        if ($watcher !== null && $watcher->referenced) {
            $watcher->referenced = false;
            if ($watcher->enabled) {
                $this->waiting--;
            }
        }
    }

    final public function isEnabled(string $id): bool
    {
        return ($this->watchers[$id] ?? throw self::unknown($id))->enabled;
    }

    final public function getIdentifiers(): array
    {
        return array_keys($this->watchers);
    }

    final public function getSuspension(): Suspension
    {
        $key = \Fiber::getCurrent() ?? $this->main;
        $suspension = ($this->suspensions[$key] ?? null)?->get();
        if ($suspension !== null) {
            return $suspension;
        }
        $suspension = new FiberSuspension(
            $this->runner,
            $this->resumer,
            $this->interrupter,
            $this->suspensions,
            $this->main,
        );
        $this->suspensions[$key] = \WeakReference::create($suspension);
        return $suspension;
    }

    final public function setErrorHandler(?\Closure $handler): void
    {
        $this->errorHandler = $handler;
    }

    public function activate(): void {}

    /**
     * Starts watching an enabled stream or signal watcher.
     */
    abstract protected function watch(Watcher $watcher): void;

    /**
     * Stops watching a stream or signal watcher that was being watched.
     */
    abstract protected function unwatch(Watcher $watcher): void;

    /**
     * Waits up to $timeout seconds, or forever when negative, and passes
     * each stream or signal watcher that fired to ready().
     */
    abstract protected function wait(float $timeout): void;

    /**
     * Queues a watcher to be called on this turn.
     */
    final protected function ready(Watcher $watcher): void
    {
        $this->ready->enqueue($watcher);
    }

    private function timer(int $type, float $seconds, \Closure $callback): string
    {
        if ($seconds < 0) {
            throw new \Error('A timer cannot be set to a negative number of seconds.');
        }
        $watcher = new Watcher($this->nextId(), $type, $callback, null, $seconds);
        $watcher->expiration = self::now() + $seconds;
        return $this->add($watcher);
    }

    private function add(Watcher $watcher): string
    {
        $this->watchers[$watcher->id] = $watcher;
        $this->enabling[$watcher->id] = $watcher;
        $this->waiting++;
        return $watcher->id;
    }

    private function nextId(): string
    {
        $id = $this->nextId;
        $this->nextId = str_increment($id);
        return $id;
    }

    private static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    private static function unknown(string $id): \Error
    {
        return new \Error("Cannot find a watcher with id \"{$id}\".");
    }

    private function createLoopFiber(): void
    {
        $this->fiber = new \Fiber(function (): void {
            $this->stopped = false;
            // Resumptions queued before run().
            $this->runCallbacks();
            while (!$this->stopped) {
                if ($this->interrupt !== null) {
                    $this->returnToMain();
                }
                if ($this->waiting === 0) {
                    return;
                }
                $this->tick();
                $this->runCallbacks();
            }
        });
    }

    /**
     * Starts watching what was enabled, then waits for streams, timers and
     * signals, without blocking while a deferred callback is due.
     */
    private function tick(): void
    {
        foreach ($this->enabling as $watcher) {
            $watcher->invokable = true;
            if ($watcher->type === Watcher::DELAY || $watcher->type === Watcher::REPEAT) {
                $this->timers->insert($watcher);
            } else {
                $this->watch($watcher);
            }
        }
        $this->enabling = [];
        foreach ($this->deferring as $watcher) {
            $watcher->invokable = true;
            $this->ready->enqueue($watcher);
        }
        $this->deferring = [];

        $timeout = 0.0;
        if ($this->ready->isEmpty() && !$this->stopped) {
            $expiration = $this->timers->peek();
            $timeout = $expiration === null ? -1.0 : max(0.0, $expiration - self::now());
        }
        $this->wait($timeout);

        $now = self::now();
        while (($timer = $this->timers->extract($now)) !== null) {
            $this->ready->enqueue($timer);
        }
    }

    /**
     * Has callback fibers call what is ready and resume what was queued,
     * taking a spare fiber whenever a callback suspends the current one.
     */
    private function runCallbacks(): void
    {
        while (!$this->resumptions->isEmpty() || !$this->ready->isEmpty()) {
            $worker = $this->worker ??= array_pop($this->spares) ?? new \Fiber($this->work(...));
            $handedBack = $worker->isStarted() ? $worker->resume() : $worker->start();
            if ($handedBack !== $this->handBack) {
                // A callback suspended it; it becomes a spare when that callback ends.
                $this->worker = null;
            }
            if ($this->interrupt !== null) {
                $this->returnToMain();
            }
        }
    }

    /**
     * The body of a callback fiber.
     */
    private function work(): void
    {
        $fiber = \Fiber::getCurrent();
        while (true) {
            $this->resumeFibers();
            while (!$this->ready->isEmpty()) {
                $watcher = $this->ready->dequeue();
                if (!$watcher->invokable || !isset($this->watchers[$watcher->id])) {
                    continue;
                }
                if ($watcher->type === Watcher::DEFER || $watcher->type === Watcher::DELAY) {
                    $this->cancel($watcher->id);
                } elseif ($watcher->type === Watcher::REPEAT) {
                    // Rescheduled from now, so it does not fire again on this turn.
                    $this->disable($watcher->id);
                    $this->enable($watcher->id);
                }
                $callback = $watcher->callback;
                try {
                    $result = match ($watcher->type) {
                        Watcher::READABLE, Watcher::WRITABLE, Watcher::SIGNAL => $callback(
                            $watcher->id,
                            $watcher->subject,
                        ),
                        default => $callback($watcher->id),
                    };
                    if ($result !== null) {
                        throw new \Error(
                            "The callback of watcher \"{$watcher->id}\" returned a value; callbacks return nothing.",
                        );
                    }
                } catch (\Throwable $error) {
                    $this->fail($error);
                }
                unset($watcher, $callback, $result);
                if ($this->worker !== $fiber) {
                    // The callback suspended this fiber and has now ended; another fiber took over.
                    if (count($this->spares) >= self::SPARE_FIBERS) {
                        return;
                    }
                    $this->spares[] = $fiber;
                    \Fiber::suspend($this->handBack);
                    continue 2;
                }
                if ($this->interrupt !== null) {
                    \Fiber::suspend($this->handBack);
                }
                $this->resumeFibers();
            }
            \Fiber::suspend($this->handBack);
        }
    }

    private function resumeFibers(): void
    {
        while (!$this->resumptions->isEmpty()) {
            [$fiber, $value, $throw] = $this->resumptions->dequeue();
            try {
                // The cycle collector may have destroyed it with its suspension.
                if (!$fiber->isTerminated()) {
                    $throw ? $fiber->throw($value) : $fiber->resume($value);
                }
            } catch (\Throwable $error) {
                $this->fail($error);
            }
            unset($fiber, $value);
            if ($this->interrupt !== null) {
                \Fiber::suspend($this->handBack);
            }
        }
    }

    /**
     * Gives what a callback threw to the error handler, in a fiber of its
     * own; without a handler, or when the handler throws, run() throws it.
     */
    private function fail(\Throwable $error): void
    {
        $handler = $this->errorHandler;
        if ($handler === null) {
            // Replaces an earlier interrupt: hiding the failure is worse.
            $this->interrupt = static fn(): never => throw $error;
            return;
        }
        new \Fiber(function () use ($handler, $error): void {
            try {
                $handler($error);
            } catch (\Throwable $failure) {
                $this->interrupt = static fn(): never => throw $failure;
            }
        })->start();
    }

    private function returnToMain(): void
    {
        $interrupt = $this->interrupt;
        $this->interrupt = null;
        \Fiber::suspend($interrupt);
    }
}
