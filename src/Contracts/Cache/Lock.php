<?php

declare(strict_types=1);

namespace Crab\Contracts\Cache;

/**
 * A lock held by one caller until it expires or its holder releases it.
 */
interface Lock
{
    /**
     * Removes the lock only while it is still held by this caller.
     * Returns false after release or expiry, so a later holder keeps its lock.
     */
    public function release(): bool;

    /**
     * Gives the lock this long to live from now, only while this caller
     * still holds it, so work that runs longer than it first said keeps
     * it. Returns false when the lock has expired or was released, and
     * then whoever took it since keeps it.
     *
     * @throws \InvalidArgumentException When the time to live is zero or less.
     */
    public function refresh(int|\DateInterval $ttl): bool;
}
