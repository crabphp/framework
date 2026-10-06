<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\App;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Server;

final readonly class Reload implements Command
{
    public function __construct(private App $app)
    {
    }

    public function description(): string
    {
        return <<<'TEXT'
            Give the server in the background new code, without refusing a connection

            Options:
              --address=tcp://127.0.0.1:8080   Where to listen; default from "server.address"
              --workers=1                      Worker processes; default from "server.workers"

            The server starts a new process, with the code and configuration as they are
            now, on the socket it already listens on. When that process accepts
            connections, the old one finishes the requests it has and stops. If the new
            one cannot start, the old one goes on serving and this command fails.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $server = new Server($this->app);
        $address = Serve::address($this->app, $input);
        $workers = Serve::workers($this->app, $input);
        try {
            [$pid, $new, $old] = $server->reload($address, $workers, Server::invocation('reload'));
        } catch (\RuntimeException $error) {
            $output->error($error->getMessage());
            return 1;
        }
        $output->line("Reloaded the server, pid {$pid}, on " . Server::describe($address, $workers) . '. It now runs the code as it is.');
        $output->line("Process {$new} took over from process {$old}, which finishes the requests it has and stops.");
        return 0;
    }
}
