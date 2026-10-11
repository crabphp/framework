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
}
