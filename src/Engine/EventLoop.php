<?php

declare(strict_types=1);

namespace Crab\Engine;

use Crab\Engine\EventLoop\RevoltEventLoop;

/**
 * The event loop the engine runs on: timers, stream and signal watchers,
 * and the suspension of fibers. Everything in the engine and above it asks
 * for this type, so the loop behind it can be replaced by extending it.
 *
 * A watcher method returns an id for enable(), disable() and cancel(). A
 * callback receives that id first; a stream callback also receives the
 * stream and a signal callback the signal number. A callback runs in its
 * own fiber and may suspend.
 */
abstract class EventLoop
{
    private static ?self $current = null;

    /**
     * The event loop of the current process; without one, a stream-select
     * loop is installed. A worker installs its own after it is forked, so
     * code that outlives a fork asks each time it needs one and does not
     * keep the answer.
     */
    final public static function current(): self
    {
        return self::$current ?? self::install(RevoltEventLoop::create());
    }

    /**
     * Makes a loop the event loop of the current process.
     */
    final public static function install(self $loop): self
    {
        $loop->activate();
        return self::$current = $loop;
    }

    /**
     * Runs until stop() is called or nothing referenced is left to wait for.
     */
    abstract public function run(): void;

    abstract public function stop(): void;

    /**
     * Runs the callback on the next turn of the loop.
     */
    abstract public function defer(\Closure $callback): string;

    /**
     * Runs the callback once, after a number of seconds.
     */
    abstract public function delay(float $seconds, \Closure $callback): string;

    /**
     * Runs the callback every interval, in seconds, until cancelled.
     */
    abstract public function repeat(float $interval, \Closure $callback): string;

    /**
     * @param resource $stream
     */
    abstract public function onReadable(mixed $stream, \Closure $callback): string;

    /**
     * @param resource $stream
     */
    abstract public function onWritable(mixed $stream, \Closure $callback): string;

    abstract public function onSignal(int $signal, \Closure $callback): string;

    abstract public function enable(string $id): void;

    /**
     * Stops a watcher from firing without forgetting it; enable() resumes it.
     */
    abstract public function disable(string $id): void;

    /**
     * Removes a watcher. Cancelling an id that is already gone does nothing.
     */
    abstract public function cancel(string $id): void;

    abstract public function reference(string $id): void;

    /**
     * Lets run() return while this watcher is still waiting.
     */
    abstract public function unreference(string $id): void;

    abstract public function isEnabled(string $id): bool;

    /**
     * @return list<string> The ids of every watcher not yet cancelled or finished.
     */
    abstract public function getIdentifiers(): array;

    /**
     * The suspension of the fiber that asks, or of the main program
     * outside any fiber, which then runs the loop while it waits.
     */
    abstract public function getSuspension(): Suspension;

    /**
     * Receives what a callback throws; without a handler such a failure
     * ends run().
     */
    abstract public function setErrorHandler(?\Closure $handler): void;

    /**
     * Called when this becomes the loop of the process, so that code which
     * finds its loop globally finds this one.
     */
    abstract public function activate(): void;
}
