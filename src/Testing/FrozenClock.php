<?php

declare(strict_types=1);

namespace Crab\Testing;

use Crab\Contracts\Clock\Clock;

/**
 * A clock for tests: it shows the time it was given until it is moved.
 */
final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    /**
     * @param \DateTimeImmutable|string $now A time, or text that describes one, such as "2026-01-31 12:00:00".
     */
    public function __construct(\DateTimeImmutable|string $now = 'now')
    {
        $this->set($now);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(\DateTimeImmutable|string $now): void
    {
        $this->now = is_string($now) ? new \DateTimeImmutable($now) : $now;
    }

    /**
     * Moves the clock by an amount such as "+1 hour" or "-30 seconds".
     */
    public function advance(string $by): void
    {
        $this->now = $this->now->modify($by);
    }
}
