<?php

declare(strict_types=1);

namespace Crab\Engine\Async;

use Crab\Engine\EventLoop;
use InvalidArgumentException;
use Throwable;

final class Async
{
    /**
     * Suspend the current task (or main) using the worker's existing driver.
     */
    public static function sleep(EventLoop $loop, float $seconds, ?Cancellation $cancellation = null): void
    {
        if (!is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException('Sleep duration must be finite and nonnegative.');
        }
        self::await(
            $loop,
            static function (\Closure $resume) use ($loop, $seconds): \Closure {
                $timer = $seconds === 0.0
                    ? $loop->defer(static fn() => $resume())
                    : $loop->delay($seconds, static fn() => $resume());
                return static fn() => $loop->cancel($timer);
            },
            $cancellation,
        );
    }

    /**
     * Suspends until what $arm sets going resumes it, fails it, or the
     * cancellation does, and returns what it was resumed with. Only the
     * first of these counts; what comes after it is ignored. $arm is given
     * resume(mixed $value = null) and fail(Throwable $error), and returns
     * what undoes it, such as cancelling its watchers, which runs however
     * the wait ends.
     *
     * @param \Closure(\Closure(mixed=): void, \Closure(Throwable): void): ((\Closure(): void)|null) $arm
     */
    public static function await(EventLoop $loop, \Closure $arm, ?Cancellation $cancellation = null): mixed
    {
        $cancellation?->throwIfCancelled();
        $suspension = $loop->getSuspension();
        $settled = false;
        $undo = null;
        $unsubscribe = null;
        // At once when the wait settles, not when the task next runs: its watchers must not outlive it.
        $release = static function () use (&$undo, &$unsubscribe): void {
            $unsubscribe?->__invoke();
            $unsubscribe = null;
            $undo?->__invoke();
            $undo = null;
        };
        $resume = static function (mixed $value = null) use ($suspension, $release, &$settled): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $release();
            $suspension->resume($value);
        };
        $fail = static function (Throwable $error) use ($suspension, $release, &$settled): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $release();
            $suspension->throw($error);
        };
        try {
            $undo = $arm($resume, $fail);
            if ($settled) {
                // Settled while being armed: what it armed is undone now.
                $release();
            } else {
                $unsubscribe = $cancellation?->subscribe(static fn(CancelledException $error) => $fail($error));
            }
            return $suspension->suspend();
        } finally {
            $settled = true;
            $release();
        }
    }
}
