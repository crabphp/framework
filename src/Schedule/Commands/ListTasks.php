<?php

declare(strict_types=1);

namespace Crab\Schedule\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Schedule\Schedule;
use Crab\Schedule\Scheduler;

final readonly class ListTasks implements Command
{
    public function __construct(private Schedule $schedule, private Scheduler $scheduler)
    {
    }

    public function description(): string
    {
        return 'List the scheduled tasks and when each runs next';
    }

    public function handle(Input $input, Output $output): int
    {
        $tasks = $this->schedule->tasks();
        if ($tasks === []) {
            $output->line('Nothing is scheduled. Declare tasks on Contracts\Schedule\Schedule in a provider\'s register().');
            return 0;
        }
        $now = $this->scheduler->now();
        $rows = [['When', 'Task', 'Next']];
        foreach ($tasks as $task) {
            $rows[] = [
                $task->expression(),
                $task->named() . ($task->runsOnOneServer() ? ' (one server)' : ''),
                // A task that runs by an interval counts from when the scheduler starts, which is not known here.
                $task->interval() === null ? $task->next($now)->format('Y-m-d H:i T') : 'while schedule:work runs',
            ];
        }
        $output->table($rows);
        return 0;
    }
}
