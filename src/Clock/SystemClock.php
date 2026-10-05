<?php

declare(strict_types=1);

namespace Crab\Clock;

use Crab\Contracts\Clock\Clock;

/**
 * The real time. Code that needs "now" asks for the Clock contract and
 * calls now(), so that a test can give it a clock that stands still.
 */
final readonly class SystemClock implements Clock
{
    /**
     * @param \DateTimeZone|null $timezone The zone times are given in; PHP's default when left out.
     */
    public function __construct(private ?\DateTimeZone $timezone = null)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->timezone);
    }
}
