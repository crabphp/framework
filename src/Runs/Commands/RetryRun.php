<?php

declare(strict_types=1);

namespace Crab\Runs\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Runs\Runs;

final readonly class RetryRun implements Command
{
    public function __construct(
        private Runs $runs,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Try a failed run again, from the step that failed
            Arguments:
              id   The run's id, as `runs:list` shows it

            The run starts again with all its tries; its steps already done are not run again.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $id = $input->argument(0);
        if (count($input->arguments()) !== 1 || $id === null || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) !== 1) {
            $output->error('Say which run to try again: runs:retry 12');
            return 1;
        }
        if (!$this->runs->retry(ctype_digit($id) ? (int) $id : $id)) {
            $output->error(
                "Run {$id} is not failed, nor left running by a worker that is gone. `runs:list --status=failed` lists the failed ones.",
            );
            return 1;
        }
        $output->line("Run {$id} will be tried again.");
        return 0;
    }
}
