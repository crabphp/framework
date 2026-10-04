<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\App;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class Serve implements Command
{
    public function __construct(private App $app)
    {
    }

    public function description(): string
    {
        return <<<'TEXT'
            Serve the application over HTTP

            Options:
              --address=tcp://127.0.0.1:8080   Where to listen; default from "server.address"
              --workers=1                      Worker processes; default from "server.workers"
              --single                         Serve in this process, without pcntl and posix

            SIGINT or SIGTERM stops gracefully; SIGUSR1 restarts the workers one at a time.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $address = $input->option('address');
        if (!is_string($address)) {
            $address = (string) $this->app->config->get('server.address', 'tcp://127.0.0.1:8080');
        }
        $single = $input->flag('single');
        $workers = $single ? 1 : (int) ($input->option('workers') ?? $this->app->config->get('server.workers', 1));
        $output->line("Serving on {$address} with " . ($single ? 'this process' : "{$workers} worker(s)") . '.');
        $this->app->runtime($address, $workers)->run(singleProcess: $single);
        return 0;
    }
}
