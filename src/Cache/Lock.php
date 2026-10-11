<?php

declare(strict_types=1);

namespace Crab\Cache;

use Crab\Contracts\Cache\Lock as LockContract;
use Crab\Contracts\Cache\Store;

/**
 * Keeps the holder's token so releasing an expired lock cannot remove
 * one another caller took in its place.
 */
final readonly class Lock implements LockContract
{
    /**
     * @param \Closure(): int $now The cache's clock, read when the lock is released.
     */
    public function __construct(
        private Store $store,
        private string $key,
        private string $token,
        private \Closure $now,
    ) {
    }

    public function release(): bool
    {
        return $this->store->deleteIf($this->key, $this->token, ($this->now)());
    }
}
