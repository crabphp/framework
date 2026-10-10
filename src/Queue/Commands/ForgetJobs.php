<?php

declare(strict_types=1);

namespace Crab\Queue\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Queue\Store;

final readonly class ForgetJobs implements Command
{
    public function __construct(private Store $store)
    {
    }

    public function description(): string
    {
        return <<<'TEXT'
            Delete failed jobs
            Arguments:
              id   The id of one failed job, as `queue:failed` shows it, or "all"
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $id = $input->argument(0);
        if ($id === null) {
            $output->error('Say which failed job to delete: queue:forget 12, or queue:forget all');
            return 1;
        }
        $count = $this->store->forget($id === 'all' ? null : $id);
        if ($count === 0 && $id !== 'all') {
            $output->error("No failed job has the id {$id}. `queue:failed` lists them.");
            return 1;
        }
        $output->line($count === 1 ? 'Deleted 1 failed job.' : "Deleted {$count} failed jobs.");
        return 0;
    }
}
