<?php

declare(strict_types=1);

namespace Crab\Cache\Commands;

use Crab\Contracts\Cache\Cache;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class ClearCache implements Command
{
    public function __construct(
        private Cache $cache,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Remove every value from the cache

            Locks stay, so work that holds one keeps others out, and so do the
            entries of another application that shares the store under another
            cache.prefix. An "array" store lives inside each worker, and is
            emptied by restarting the server instead.

            Options:
              --locks   Remove the cache's locks too, for one left by a process that died
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $locks = $input->flag('locks');
        $this->cache->clear();
        if (!$locks) {
            $output->line('The cache was cleared. Its locks were kept.');
            return 0;
        }
        $this->cache->clearLocks();
        $output->line('The cache was cleared, and its locks with it.');
        return 0;
    }
}
