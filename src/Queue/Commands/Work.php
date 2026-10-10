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
              --queue=default        The queues to take from, the most urgent first: --queue=emails,default
              --workers=1            Worker processes; default from "queue.workers"
              --concurrency=1        Jobs one worker runs at a time; default from "queue.concurrency"
              --single               Run in this process, without pcntl and posix
              --stop-when-empty      Run what is waiting and return; runs in this process

            SIGINT or SIGTERM lets each worker finish the job it has and stops it. A
            worker runs the code it started with: start the command again after a
            deploy. More than one job at a time helps only jobs that wait, on another
            server or on a timer; a job that computes, or talks to the database, holds
            its worker until it is done.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $queues = array_values(array_filter(explode(',', (string) $input->option('queue', (string) $this->config->get('queue.queue')))));
        $drain = $input->flag('stop-when-empty');
        $single = $drain || $input->flag('single');
        $workers = $single ? 1 : max(1, (int) $input->option('workers', (string) $this->config->get('queue.workers')));
        $concurrency = max(1, (int) $input->option('concurrency', (string) $this->config->get('queue.concurrency')));
        $sleep = (float) $this->config->get('queue.sleep');
        $consumer = $this->consumer;
        if (!$drain && !$input->flag('quiet')) {
            $output->line('Running jobs from ' . implode(', ', $queues) . ($workers > 1 ? " with {$workers} workers" : ''));
            $output->line('Press Ctrl+C to stop');
        }
        $ran = 0;
        $this->runner->run('queue', static function (Worker $worker, Cancellation $cancellation) use ($consumer, $queues, $sleep, $drain, $concurrency, &$ran): void {
            $others = [];
            for ($extra = 1; $extra < $concurrency; ++$extra) {
                $others[] = $worker->spawn(static function (Cancellation $own) use ($consumer, $worker, $queues, $sleep, $drain, &$ran): void {
                    $ran += $consumer->work($worker->loop, $own, $queues, $sleep, $drain);
                });
            }
            $ran += $consumer->work($worker->loop, $cancellation, $queues, $sleep, $drain);
            foreach ($others as $other) {
                $other->await();
            }
        }, $workers, (float) $this->config->get('queue.grace'), $single);
        if ($drain) {
            $output->line($ran === 1 ? 'Ran 1 job.' : "Ran {$ran} jobs.");
        }
        return 0;
    }
}
