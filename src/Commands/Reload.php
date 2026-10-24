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
    public function __construct(
        private App $app,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Give the server in the background new code, without refusing a connection

            Options:
              --address=tcp://127.0.0.1:8080   Where to listen; default as the server was started
              --workers=1                      Worker processes; default as the server was started

            The server starts a new process, with the code and configuration as they are
            now, on the socket it already listens on. When that process accepts
            connections, the old one finishes the requests it has and stops. If the new
            one cannot start, the old one goes on serving and this command fails.

            An --address or --workers given to `start`, or to an earlier reload, is kept;
            one left to "server.address" or "server.workers" follows it as it is now.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        Start::requireValues($input, ['address', 'workers']);
        Start::checkTyped($this->app, $input);
        $server = new Server($this->app);
        // As the server was started, but for what is typed now.
        $kept = $server->given();
        $address = Start::address($this->app, $input, $kept['address'] ?? null);
        $workers = Start::workers($this->app, $input, $kept['workers'] ?? null);
        $this->app->config->check();
        try {
            [$pid, $new, $old] = $server->reload(
                $address,
                Start::given($input, $address, $workers, $kept),
                Server::invocation('reload'),
            );
        } catch (\RuntimeException $error) {
            $output->error($error->getMessage());
            return 1;
        }
        $output->line(
            "Reloaded the server, pid {$pid}, on "
            . Server::describe($address, $workers)
            . '. It now runs the code as it is.',
        );
        $output->line("Process {$new} took over from process {$old}, which finishes the requests it has and stops.");
        return 0;
    }
}
