<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\App;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Server;

final readonly class Restart implements Command
{
    public function __construct(
        private App $app,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Stop the server in the background and start it again

            Options:
              --address=tcp://127.0.0.1:8080   Where to listen; default from "server.address"
              --workers=1                      Worker processes; default from "server.workers"
              --timeout=15                     Graceful wait in seconds; if omitted, read from the running server
              --force                          Cut requests and end every old server process now

            Connections are refused between the stop and the start. `reload` replaces
            the server without that gap.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        Start::requireValues($input, ['address', 'workers', 'timeout']);
        $server = new Server($this->app);
        $address = Start::address($this->app, $input);
        $workers = Start::workers($this->app, $input);
        $seconds = Stop::timeout($input);
        try {
            $old = $server->stop($seconds, $input->flag('force'));
            if ($old !== null) {
                $output->line("Stopped the server, pid {$old}.");
            }
            $server->start($address, $workers, Server::invocation('restart'));
        } catch (\RuntimeException $error) {
            $output->error($error->getMessage());
            return 1;
        }
        Start::announceBackground($output, $this->app, $address, $workers);
        return 0;
    }
}
