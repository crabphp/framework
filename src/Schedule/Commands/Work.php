<?php

declare(strict_types=1);

namespace Crab\Schedule\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Runner;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Process\Worker;
use Crab\Schedule\Schedule;
use Crab\Schedule\Scheduler;

final readonly class Work implements Command
{
    public function __construct(
        private Runner $runner,
        private Scheduler $scheduler,
        private Schedule $schedule,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Run the scheduled tasks when they are due, until stopped
            Options:
              --single   Run in this process, without pcntl and posix

            Keep one of these running wherever the application is deployed; no cron
            entry is needed. SIGINT or SIGTERM stops it. It runs the code it started
            with: start it again after a deploy. `schedule:list` shows the tasks.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        // Before anything is started, so that a schedule that cannot run says so here.
        $this->scheduler->check();
        if (!$input->flag('quiet')) {
            $count = count($this->schedule->tasks());
            $output->line('Running the schedule: ' . ($count === 1 ? '1 task' : "{$count} tasks"));
            $output->line('Press Ctrl+C to stop');
        }
        $scheduler = $this->scheduler;
        $this->runner->run(
            'schedule',
            static function (Worker $worker, Cancellation $cancellation) use ($scheduler): void {
                $scheduler->work($worker, $cancellation);
            },
            single: $input->flag('single'),
        );
        return 0;
    }
}
