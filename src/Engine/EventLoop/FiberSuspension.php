<?php

declare(strict_types=1);

namespace Crab\Engine\EventLoop;

use Crab\Engine\Suspension;

/**
 * The suspension of one fiber, or of the main program, on a
 * StreamSelectEventLoop. A fiber is resumed from the loop's queue and not
 * inside the resume() call; the main program runs the loop while it waits.
 * Ported from Revolt's DriverSuspension (MIT).
 *
 * @internal
 */
final class FiberSuspension implements Suspension
{
    /**
     * Holds the fiber while it waits, so that it is not collected.
     */
    private ?\Fiber $waiting = null;

    /**
     * @var \WeakReference<\Fiber>|null Null for the main program.
     */
    private readonly ?\WeakReference $fiber;

    private bool $pending = false;

    /**
     * A main-program suspension the loop ended without resuming.
     */
    private bool $dead = false;

    private ?\FiberError $error = null;

    /**
     * @param \Closure():(\Closure():mixed)|null $run Runs the loop until it is interrupted or ends.
     * @param \Closure(\Fiber, mixed, bool):void $queue Queues a fiber's resumption, with a value or with a throwable.
     * @param \Closure(\Closure():mixed):void $interrupt Interrupts the loop to return to the main program.
     * @param \WeakMap<object, \WeakReference<self>> $suspensions Every suspension of the loop, by fiber.
     * @param object $main The key of the main program's suspension.
     */
    public function __construct(
        private readonly \Closure $run,
        private readonly \Closure $queue,
        private readonly \Closure $interrupt,
        private readonly \WeakMap $suspensions,
        private readonly object $main,
    ) {
        $fiber = \Fiber::getCurrent();
        $this->fiber = $fiber === null ? null : \WeakReference::create($fiber);
    }

    public function suspend(): mixed
    {
        if ($this->dead) {
            throw new \Error(
                'A suspension of the main program cannot suspend after the event loop ended with an uncaught failure.',
            );
        }
        if ($this->pending) {
            throw new \Error('Must call resume() or throw() before calling suspend() again.');
        }
        $fiber = $this->fiber?->get();
        if ($fiber !== \Fiber::getCurrent()) {
            throw new \Error('Must not call suspend() from another fiber.');
        }
        $this->pending = true;
        $this->error = null;
        if ($fiber !== null) {
            $this->waiting = $fiber;
            try {
                $value = \Fiber::suspend();
            } catch (\FiberError $error) {
                $this->pending = false;
                $this->waiting = null;
                $this->error = $error;
                throw $error;
            }
            // Not in a finally block: a fiber destroyed by the cycle collector would clear it too.
            $this->waiting = null;
            return $value;
        }

        $result = ($this->run)();
        if ($this->pending) {
            $this->dead = true;
            unset($this->suspensions[$this->main]);
            // A failure that ended the loop is the more useful error.
            if ($result !== null) {
                $result();
            }
            gc_collect_cycles();
            throw new \Error(
                'Event loop ended without resuming the current suspension (the cause is either a fiber deadlock, or an incorrectly unreferenced or cancelled watcher):'
                    . $this->waitingTraces(),
            );
        }
        return $result();
    }

    public function resume(mixed $value = null): void
    {
        if ($this->settle()) {
            $fiber = $this->fiber?->get();
            if ($fiber !== null) {
                ($this->queue)($fiber, $value, false);
            } else {
                ($this->interrupt)(static fn(): mixed => $value);
            }
        }
    }

    public function throw(\Throwable $error): void
    {
        if ($this->settle()) {
            $fiber = $this->fiber?->get();
            if ($fiber !== null) {
                ($this->queue)($fiber, $error, true);
            } else {
                ($this->interrupt)(static fn(): never => throw $error);
            }
        }
    }

    /**
     * Ends the wait; false for a spurious resumption of a dead main-program suspension.
     */
    private function settle(): bool
    {
        if ($this->dead) {
            return false;
        }
        if (!$this->pending) {
            throw $this->error ?? new \Error('Must call suspend() before calling resume() or throw().');
        }
        $this->pending = false;
        return true;
    }

    private function waitingTraces(): string
    {
        $info = '';
        foreach ($this->suspensions as $reference) {
            $fiber = $reference->get()?->fiber?->get();
            if ($fiber === null) {
                continue;
            }
            $lines = [];
            foreach (new \ReflectionFiber($fiber)->getTrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $i => $frame) {
                $line = "#{$i} ";
                if (isset($frame['file'])) {
                    $line .= $frame['file'] . ':' . ($frame['line'] ?? 0) . ' ';
                }
                if (isset($frame['class'], $frame['type'])) {
                    $line .= $frame['class'] . $frame['type'];
                }
                $lines[] = $line . $frame['function'] . '()';
            }
            $info .= "\n\n" . implode("\n", $lines);
        }
        return $info;
    }
}
