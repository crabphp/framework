<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\App;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Server;

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
              --watch=app,config               What to watch instead of "server.watch"
              --no-watch                       Do not restart when a file changes
              --daemon, -d                     Serve in the background and return once it is up

            In the foreground the server restarts when a watched file changes, unless
            --no-watch is given or "server.watch" is false. SIGINT or SIGTERM stops it
            gracefully. In the background nothing is watched; use the `stop`, `reload`,
            `restart` and `status` commands.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $server = new Server($this->app);
        $address = self::address($this->app, $input);
        $single = $input->flag('single');
        $workers = $single ? 1 : self::workers($this->app, $input);
        $daemon = $input->flag('daemon') || $input->flag('d');

        if ($input->flag('generation')) {
            // How the keeper of a server in the background starts the server proper; see Server::start().
            $server->generation($address, $workers);
        }
        if ($daemon) {
            if ($single) {
                $output->error('--single serves in this process, so it cannot be combined with --daemon.');
                return 1;
            }
            if ($input->option('watch') !== null) {
                $output->error('--watch restarts the server when a file changes, which a server in the background does not do; use `reload`.');
                return 1;
            }
            $running = $server->running();
            if ($running !== null) {
                $output->error("A server is already running in the background, pid {$running}. Use `reload` to give it new code, or `stop` to end it.");
                return 1;
            }
            try {
                $pid = $server->start($address, $workers, Server::invocation('serve'));
            } catch (\RuntimeException $error) {
                $output->error($error->getMessage());
                return 1;
            }
            $output->line("Serving on {$address} with {$workers} worker(s) in the background, pid {$pid}.");
            $output->line("Its output goes to {$server->logFile()}. `stop` ends it; `reload` gives it new code without refusing a connection.");
            return 0;
        }
        $paths = $this->watched($input);
        if ($paths !== null) {
            // This process only watches; a child serves, and is started again when a file changes.
            return $server->watch([...self::withoutWatch($_SERVER['argv'] ?? []), '--no-watch'], $paths, $output->line(...));
        }
        $output->line("Serving on {$address} with " . ($single ? 'this process' : "{$workers} worker(s)") . '.');
        $server->serve($address, $workers, $single);
        return 0;
    }

    /**
     * What to watch while serving in the foreground: the paths of the
     * --watch option, else those of "server.watch", else the usual ones.
     * Null when --no-watch is given or "server.watch" is false or empty.
     *
     * @return list<string>|null
     */
    private function watched(Input $input): ?array
    {
        if ($input->flag('no-watch')) {
            return null;
        }
        $option = $input->option('watch');
        if (is_string($option)) {
            $paths = array_values(array_filter(explode(',', $option)));
            return $paths === [] ? null : $paths;
        }
        $configured = $this->app->config->get('server.watch', Server::WATCHED);
        if ($configured === false || $configured === null || $configured === []) {
            return null;
        }
        return $configured === true ? Server::WATCHED : array_values((array) $configured);
    }

    /**
     * Where to listen: the option, or the configuration.
     */
    public static function address(App $app, Input $input): string
    {
        $address = $input->option('address');
        return is_string($address) ? $address : (string) $app->config->get('server.address', 'tcp://127.0.0.1:8080');
    }

    /**
     * How many workers: the option, or the configuration.
     */
    public static function workers(App $app, Input $input): int
    {
        return (int) ($input->option('workers') ?? $app->config->get('server.workers', 1));
    }

    /**
     * The command line this process was started with, less the option
     * that made it a watcher, which is what a child must run to serve.
     *
     * @param list<string> $arguments
     * @return list<string>
     */
    private static function withoutWatch(array $arguments): array
    {
        $kept = [];
        foreach ($arguments as $argument) {
            if ($argument !== '--watch' && !str_starts_with($argument, '--watch=')) {
                $kept[] = $argument;
            }
        }
        return $kept;
    }
}
