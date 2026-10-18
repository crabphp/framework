<?php

declare(strict_types=1);

namespace Crab\WebSocket;

/**
 * A reserved worker slot, shared by immutable response clones. A response
 * discarded by middleware releases it; an upgrade retains it until close.
 */
final class Admission
{
    private bool $released = false;

    public function __construct(
        private readonly \Closure $release,
    ) {}

    public function release(): void
    {
        if (!$this->released) {
            $this->released = true;
            ($this->release)();
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
