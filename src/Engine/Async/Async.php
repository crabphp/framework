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
     * what undoes it, such as cancelling its watchers. That runs the
     * moment the wait settles, so nothing it watched outlives it. One that
     * settles while $arm is still running, before there is anything to
     * suspend, is returned or thrown without suspending.
     *
     * @param \Closure(\Closure(mixed=): void, \Closure(Throwable): void): ((\Closure(): void)|null) $arm
     */
    public static function await(EventLoop $loop, \Closure $arm, ?Cancellation $cancellation = null): mixed
    {
        $cancellation?->throwIfCancelled();
        $suspension = $loop->getSuspension();
        $settled = false;
        $suspended = false;
        // What settled it before it suspended: [value] or [null, error].
        $early = null;
        $undo = null;
        $unsubscribe = null;
        $release = static function () use (&$undo, &$unsubscribe): void {
            $unsubscribe?->__invoke();
            $unsubscribe = null;
            $undo?->__invoke();
            $undo = null;
        };
        $resume = static function (mixed $value = null) use (
            $suspension,
            $release,
            &$settled,
            &$suspended,
            &$early,
        ): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $release();
            if ($suspended) {
                $suspension->resume($value);
            } else {
                $early = [$value];
            }
        };
        $fail = static function (Throwable $error) use ($suspension, $release, &$settled, &$suspended, &$early): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $release();
            if ($suspended) {
                $suspension->throw($error);
            } else {
                $early = [null, $error];
            }
        };
        try {
            $undo = $arm($resume, $fail);
            if (!$settled) {
                $unsubscribe = $cancellation?->subscribe(static fn(CancelledException $error) => $fail($error));
            }
            if ($early !== null) {
                return isset($early[1]) ? throw $early[1] : $early[0];
            }
            $suspended = true;
            return $suspension->suspend();
        } finally {
            $settled = true;
            $release();
        }
    }
}
