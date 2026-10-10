<?php

declare(strict_types=1);

namespace Crab\Queue\Commands;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Runner;
use Crab\Contracts\Workers;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Process\Worker;
use Crab\Queue\Consumer;
use Crab\Queue\Supervisor;

final readonly class Work implements Command
{
    public function __construct(private Runner $runner, private Consumer $consumer, private Config $config)
    {
    }

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
        $supervisors = $this->supervisors($input);
        $drain = $input->flag('stop-when-empty');
        $single = $drain || $input->flag('single');
        if (!$drain && !$input->flag('quiet')) {
            foreach ($supervisors as $supervisor) {
                $workers = $single ? 1 : $supervisor->workers;
                $output->line(
                    (count($supervisors) > 1 ? "{$supervisor->name}: running" : 'Running')
                    . ' jobs from ' . implode(', ', $supervisor->queues)
                    . ($workers > 1 ? " with {$workers} workers" : ''),
                );
            }
            $output->line('Press Ctrl+C to stop');
        }
        $grace = (float) $this->config->get('queue.grace');
        $ran = 0;
        if ($single) {
            // One process does the work of every supervisor, each within its own limits.
            $this->runner->run('queue', function (Worker $worker, Cancellation $cancellation) use ($supervisors, $drain, &$ran): void {
                $tasks = [];
                foreach ($supervisors as $supervisor) {
                    $tasks[] = $worker->spawn(function () use ($worker, $cancellation, $supervisor, $drain, &$ran): void {
                        $ran += $this->consume($worker, $cancellation, $supervisor, $drain);
                    });
                }
                foreach ($tasks as $task) {
                    $task->await();
                }
            }, shutdownTimeout: $grace, single: true);
        } else {
            $groups = [];
            foreach ($supervisors as $supervisor) {
                $groups[] = new Workers(
                    count($supervisors) > 1 ? "queue:{$supervisor->name}" : 'queue',
                    function (Worker $worker, Cancellation $cancellation) use ($supervisor): void {
                        $this->consume($worker, $cancellation, $supervisor, false);
                    },
                    $supervisor->workers,
                );
            }
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
        $declared = Supervisor::all($this->config);
        $described = array_filter([
            'queues' => $input->option('queue'),
            'workers' => $input->option('workers'),
            'concurrency' => $input->option('concurrency'),
        ], is_string(...));
        $name = $input->option('supervisor');
        if (is_string($name)) {
            if ($described !== []) {
                throw new \LogicException('--supervisor runs a supervisor as it is declared; leave out --' . implode(', --', array_map(static fn (string $key): string => $key === 'queues' ? 'queue' : $key, array_keys($described))) . ', or leave out --supervisor.');
            }
            return [$declared[$name] ?? throw new \LogicException(
                "There is no queue supervisor \"{$name}\". " . ($declared === [] ? '"queue.supervisors" declares none.' : 'The supervisors are ' . implode(', ', array_keys($declared)) . '.'),
            )];
        }
        if ($described === [] && $declared !== []) {
            return array_values($declared);
        }
        if (isset($described['queues'])) {
            $described['queues'] = array_values(array_filter(explode(',', $described['queues'])));
        }
        return [Supervisor::from($this->config, 'queue', $described)];
    }

    /**
     * Runs a supervisor's jobs in one worker, as many at a time as its
     * concurrency says, until the cancellation fires or, when draining,
     * until none is waiting.
     *
     * @return int How many jobs were run.
     */
    private function consume(Worker $worker, Cancellation $cancellation, Supervisor $supervisor, bool $drain): int
    {
        $consumer = $this->consumer->within($supervisor);
        $ran = 0;
        $others = [];
        for ($extra = 1; $extra < $supervisor->concurrency; ++$extra) {
            $others[] = $worker->spawn(static function () use ($consumer, $worker, $cancellation, $supervisor, $drain, &$ran): void {
                $ran += $consumer->work($worker->loop, $cancellation, $supervisor->queues, $supervisor->sleep, $drain);
            });
        }
        $ran += $consumer->work($worker->loop, $cancellation, $supervisor->queues, $supervisor->sleep, $drain);
        foreach ($others as $other) {
            $other->await();
        }
        return $ran;
    }
}
