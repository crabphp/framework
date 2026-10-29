<?php

declare(strict_types=1);

namespace Crab\Runs\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Runs\Status;
use Crab\Runs\Runs;

final readonly class ListRuns implements Command
{
    public function __construct(
        private Runs $runs,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            List the latest runs and where each is
            Options:
              --status=failed   Only those in one state: pending, running, sleeping, waiting, completed or failed
              --limit=50        How many, the newest first

            A failed run is tried again with `runs:retry`.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $given = $input->option('status');
        $status = is_string($given) ? Status::tryFrom($given) : null;
        if (is_string($given) && $status === null) {
            $output->error(
                "\"{$given}\" is no state of a run: "
                    . implode(', ', array_map(static fn(Status $status): string => $status->value, Status::cases())),
            );
            return 1;
        }
        $limit = $input->option('limit', '50');
        if (!is_string($limit) || preg_match('/^[1-9][0-9]{0,4}$/D', $limit) !== 1) {
            $output->error('--limit is how many runs to list: a whole number from 1.');
            return 1;
        }
        $runs = $this->runs->latest($status, (int) $limit);
        if ($runs === []) {
            $output->line($status === null ? 'No run has been started.' : "No run is {$status->value}.");
            return 0;
        }
        $rows = [['Id', 'Run', 'Status', 'Steps', 'Tries', 'Updated', 'Why']];
        foreach ($runs as $run) {
            $why = (string) strtok((string) $run->error, "\n");
            $rows[] = [
                (string) $run->id,
                $run->run,
                $run->status->value,
                (string) count($run->steps),
                (string) $run->attempts,
                $run->updatedAt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'),
                strlen($why) > 80 ? substr($why, 0, 80) . '...' : $why,
            ];
        }
        $output->table($rows);
        return 0;
    }
}
