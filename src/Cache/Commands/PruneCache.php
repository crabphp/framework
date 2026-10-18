<?php

declare(strict_types=1);

namespace Crab\Cache\Commands;

use Crab\Contracts\Cache\Store;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Psr\Clock\ClockInterface;

/**
 * Removes expired entries so file and database caches do not grow forever.
 */
final readonly class PruneCache implements Command
{
    public function __construct(
        private Store $store,
        private ClockInterface $clock,
    ) {}

    public function description(): string
    {
        return "Remove expired entries from the cache\n\nRedis removes expired entries itself and needs no pruning.";
    }

    public function handle(Input $input, Output $output): int
    {
        $removed = $this->store->prune($this->clock->now()->getTimestamp());
        $output->line("Removed {$removed} expired cache entries.");
        return 0;
    }
}
