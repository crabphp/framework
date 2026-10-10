<?php

declare(strict_types=1);

namespace Crab\Cache\Commands;

use Crab\Contracts\Cache\Cache;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class ClearCache implements Command
{
    public function __construct(private Cache $cache)
    {
    }

    public function description(): string
    {
        return "Remove every entry from the cache\n\nAn \"array\" store lives inside each worker, and is emptied by restarting the server instead.";
    }

    public function handle(Input $input, Output $output): int
    {
        $this->cache->clear();
        $output->line('The cache was cleared.');
        return 0;
    }
}
