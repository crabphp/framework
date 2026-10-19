<?php

declare(strict_types=1);

namespace Crab\Queue\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Queue\Store;
use Psr\Clock\ClockInterface;

final readonly class RetryJobs implements Command
{
    public function __construct(
        private Store $store,
        private ?ClockInterface $clock = null,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Put failed jobs back in the queue
            Arguments:
              id   The id of one failed job, as `queue:failed` shows it, or "all"

            A job put back starts again with all its tries.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $id = $input->argument(0);
        if (
            count($input->arguments()) !== 1
            || $id === null
            || trim($id) === ''
            || preg_match('/[\x00-\x1f\x7f]/', $id) === 1
        ) {
            $output->error('Say which failed job to put back: queue:retry 12, or queue:retry all');
            return 1;
        }
        $count = $this->store->retry($id === 'all' ? null : $id, $this->clock?->now()->getTimestamp() ?? time());
        if ($count === 0 && $id !== 'all') {
            $output->error("No failed job has the id {$id}. `queue:failed` lists them.");
            return 1;
        }
        $output->line($count === 1 ? 'Put 1 job back in the queue.' : "Put {$count} jobs back in the queue.");
        return 0;
    }
}
