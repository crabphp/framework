<?php

declare(strict_types=1);

namespace Crab\Actors\Commands;

use Crab\Actors\Actors;
use Crab\Actors\ActorsProvider;
use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Runner;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Process\Worker;

final readonly class Work implements Command
{
    public function __construct(
        private Runner $runner,
        private Container $container,
        private Config $config,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Run the actors, taking their messages from the broadcast carrier, until stopped
            Options:
              --single   Run them all in this process, without pcntl and posix

            Runs "actors.workers" processes, each with its share of the keys. It needs a
            carrier between processes, such as Redis's: with the local one, an actor runs
            in the process that sends to it. SIGINT or SIGTERM stops it.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $this->config->check('actors');
        ActorsProvider::requireCarrier($this->container);
        $workers = (int) $this->config->get('actors.workers');
        $single = $input->flag('single');
        if (!$input->flag('quiet')) {
            $output->line(
                $single ? 'Running the actors in this process' : "Running the actors in {$workers} processes",
            );
            $output->line('Press Ctrl+C to stop');
        }
        $container = $this->container;
        $this->runner->run(
            'actors',
            static function (Worker $worker, Cancellation $cancellation) use ($container, $single, $workers): void {
                $container->get(Actors::class)->serve(
                    $single ? range(0, $workers - 1) : [$worker->index],
                    $cancellation,
                );
            },
            $single ? 1 : $workers,
            single: $single,
        );
        return 0;
    }
}
