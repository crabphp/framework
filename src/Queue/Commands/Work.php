<?php

declare(strict_types=1);

namespace Crab\Queue\Commands;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Runner;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Process\Worker;
use Crab\Queue\Consumer;
use Crab\Queue\Supervisor;

final readonly class Work implements Command
{
    public function __construct(
        private Runner $runner,
        private Consumer $consumer,
        private Config $config,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Run the jobs in the queue, until stopped
            Options:
              --supervisor=name      Run one of the supervisors in "queue.supervisors", not all of them
              --queue=default        The queues to take from, the most urgent first: --queue=emails,default
              --workers=1            Worker processes; default from "queue.workers"
              --concurrency=1        Jobs one worker runs at a time; default from "queue.concurrency"
              --single               Run in this process, without pcntl and posix
              --stop-when-empty      Run what is waiting and return; runs in this process

            With supervisors declared in "queue.supervisors", this runs them all, each
            as a group of workers of its own. --queue, --workers and --concurrency run
            one set of workers as described on the command line instead.

            SIGINT or SIGTERM lets each worker finish the job it has and stops it. A
            worker runs the code it started with: start the command again after a
            deploy. More than one job at a time helps only jobs that wait, on another
            server or on a timer; a job that computes, or talks to the database, holds
            its worker until it is done.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        // Every setting, before the supervisors are read from them: the workers run with all of them.
        $this->config->check();
        $supervisors = $this->supervisors($input);
        $drain = $input->flag('stop-when-empty');
        $single = $drain || $input->flag('single');
        if (!$drain && !$input->flag('quiet')) {
            foreach ($supervisors as $supervisor) {
                $workers = $single ? 1 : $supervisor->workers;
                $output->line(
                    (count($supervisors) > 1 ? "{$supervisor->name}: running" : 'Running')
                    . ' jobs from '
                    . implode(', ', $supervisor->queues)
                    . ($workers > 1 ? " with {$workers} workers" : ''),
                );
            }
            $output->line('Press Ctrl+C to stop');
        }
        $grace = (float) $this->config->get('queue.grace');
        $ran = 0;
        if ($single) {
            // One process does the work of every supervisor, each within its own limits.
            // Named after the supervisors it does the work of, "queue:mail+default", so that whoever
            // watches the workers knows these are served; the one of the queue's own settings is "queue".
            $names = array_map(static fn(Supervisor $supervisor): string => $supervisor->name, $supervisors);
            $group = $names === ['queue'] ? 'queue' : 'queue:' . implode('+', $names);
            $this->runner->run(
                $group,
                function (Worker $worker, Cancellation $cancellation) use ($supervisors, $drain, &$ran): void {
                    $tasks = [];
                    foreach ($supervisors as $supervisor) {
                        $tasks[] = $worker->spawn(function () use (
                            $worker,
                            $cancellation,
                            $supervisor,
                            $drain,
                            &$ran,
                        ): void {
                            $ran += $supervisor->consume($this->consumer, $worker, $cancellation, $drain);
                        });
                    }
                    foreach ($tasks as $task) {
                        $task->await();
                    }
                },
                shutdownTimeout: $grace,
                single: true,
            );
        } else {
            $groups = Supervisor::groups($supervisors, $this->consumer);
            $this->runner->runAll($groups, $grace);
        }
        if ($drain) {
            $output->line($ran === 1 ? 'Ran 1 job.' : "Ran {$ran} jobs.");
        }
        return 0;
    }

    /**
     * The supervisors this run is for: the one the command line describes
     * or names, or else every one the application declares, or else one
     * made of the queue's own settings.
     *
     * @return list<Supervisor>
     */
    private function supervisors(Input $input): array
    {
        foreach (['queue', 'workers', 'concurrency', 'supervisor'] as $option) {
            if ($input->option($option) === true) {
                throw new \InvalidArgumentException("--{$option} needs a value; use --{$option}=value.");
            }
        }
        $described = array_filter(
            [
                'queues' => $input->option('queue'),
                'workers' => $input->option('workers'),
                'concurrency' => $input->option('concurrency'),
            ],
            is_string(...),
        );
        if (isset($described['queues'])) {
            $described['queues'] = explode(',', $described['queues']);
            foreach ($described['queues'] as $queue) {
                if (trim($queue) === '' || preg_match('/[\x00-\x1f\x7f]/', $queue) === 1) {
                    throw new \InvalidArgumentException(
                        '--queue needs a nonempty name for every comma-separated queue; use --queue=mail,default.',
                    );
                }
            }
        }
        // Options and configuration reach the same validation before either can start work.
        $selected = $described === [] ? null : Supervisor::from($this->config, 'queue', $described);
        $declared = Supervisor::all($this->config);
        $name = $input->option('supervisor');
        if (is_string($name)) {
            if ($described !== []) {
                throw new \LogicException(
                    '--supervisor runs a supervisor as it is declared; leave out --'
                    . implode(', --', array_map(static fn(string $key): string => $key === 'queues'
                        ? 'queue'
                        : $key, array_keys($described)))
                    . ', or leave out --supervisor.',
                );
            }
            return [
                $declared[$name] ?? throw new \LogicException(
                    "There is no queue supervisor \"{$name}\". "
                    . (
                        $declared === []
                            ? '"queue.supervisors" declares none; configure one before using --supervisor=name.'
                            : 'The supervisors are '
                            . implode(', ', array_keys($declared))
                            . '. Use --supervisor=name to choose one.'
                    ),
                ),
            ];
        }
        if ($described === [] && $declared !== []) {
            return array_values($declared);
        }
        return [$selected ?? Supervisor::from($this->config, 'queue')];
    }
}
