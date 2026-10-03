<?php

declare(strict_types=1);

namespace Crab\Engine\Async;

use InvalidArgumentException;
use Revolt\EventLoop\Driver;
use Throwable;

final class Async
{
    /**
     * Suspend the current task (or main) using the worker's existing driver.
     */
    public static function sleep(Driver $loop, float $seconds, ?Cancellation $cancellation = null): void
    {
        if (!is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException('Sleep duration must be finite and nonnegative.');
        }
        $cancellation?->throwIfCancelled();
        $suspension = $loop->getSuspension();
        $settled = false;
        $timer = null;
        $unsubscribe = null;
        $finish = static function (?Throwable $error = null) use ($suspension, $loop, &$settled, &$timer, &$unsubscribe): void {
            if ($settled) {
                return;
            }
            $settled = true;
            if ($timer !== null) {
                $loop->cancel($timer);
            }
            $unsubscribe?->__invoke();
            if ($error !== null) {
                $suspension->throw($error);
            } else {
                $suspension->resume();
            }
        };
        $timer = $seconds === 0
            ? $loop->defer(static fn () => $finish())
            : $loop->delay($seconds, static fn () => $finish());
        $unsubscribe = $cancellation?->subscribe(static fn (CancelledException $error) => $finish($error));
        try {
            $suspension->suspend();
        } finally {
            $settled = true;
            $loop->cancel($timer);
            $unsubscribe?->__invoke();
        }
    }
}
