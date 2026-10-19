<?php

declare(strict_types=1);

namespace Crab\Tests\Support;

use Crab\Engine\Async\Async;
use Crab\Engine\EventLoop;
use PHPUnit\Framework\Assert;

/**
 * Waits for an observed event while allowing the loop to make progress.
 * The deadline diagnoses missing work; it does not measure throughput.
 */
final class Wait
{
    public static function until(EventLoop $loop, \Closure $ready, string $what): void
    {
        $deadline = hrtime(true) + 5_000_000_000;
        while (!$ready()) {
            if (hrtime(true) >= $deadline) {
                Assert::fail('Timed out waiting for ' . $what . '.');
            }
            Async::sleep($loop, 0.001);
        }
    }
}
