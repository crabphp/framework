<?php

declare(strict_types=1);

namespace Crab\Queue\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Queue\Store;

final readonly class FailedJobs implements Command
{
    public function __construct(
        private Store $store,
    ) {}

    public function description(): string
    {
        return "List the jobs that were given up on\n\nEach is kept until `queue:retry` puts it back or `queue:forget` deletes it.";
    }

    public function handle(Input $input, Output $output): int
    {
        $failed = $this->store->failed();
        if ($failed === []) {
            $output->line('No job has failed.');
            return 0;
        }
        $rows = [['Id', 'Queue', 'Job', 'Failed at', 'Why']];
        foreach ($failed as $message) {
            $why = (string) strtok((string) $message->error, "\n");
            $rows[] = [
                (string) $message->id,
                $message->queue,
                $message->job,
                $message->failedAt === null ? '' : date('Y-m-d H:i:s', $message->failedAt),
                strlen($why) > 100 ? substr($why, 0, 100) . '...' : $why,
            ];
        }
        $output->table($rows);
        return 0;
    }
}
