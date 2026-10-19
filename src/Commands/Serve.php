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
    public function __construct(
        private App $app,
    ) {}

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
        $workers = self::workers($this->app, $input);
        if ($single) {
            $workers = 1;
        }
        if ($input->option('watch') === true) {
            throw new \InvalidArgumentException('--watch needs a value; use --watch=value.');
        }
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
                $output->error(
                    '--watch restarts the server when a file changes, which a server in the background does not do; use `reload`.',
                );
                return 1;
            }
            $running = $server->running();
            if ($running !== null) {
                $output->error(
                    "A server is already running in the background, pid {$running}. Use `reload` to give it new code, or `stop` to end it.",
                );
                return 1;
            }
            try {
                $server->start($address, $workers, Server::invocation('serve'));
            } catch (\RuntimeException $error) {
                $output->error($error->getMessage());
                return 1;
            }
            self::announceBackground($output, $this->app, $address, $workers);
            return 0;
        }
        $paths = $this->watched($input);
        if ($paths !== null) {
            // This process only watches; a child serves, and is started again when a file changes.
            return $server->watch(
                [...self::withoutWatch($_SERVER['argv'] ?? []), '--no-watch'],
                $paths,
                $output->line(...),
            );
        }
        if (!$input->flag('quiet')) {
            $this->announce($output, $address, $workers, $single);
        }
        try {
            $server->serve($address, $workers, $single);
        } catch (\RuntimeException $error) {
            if (!str_starts_with($error->getMessage(), 'Could not bind ')) {
                throw $error;
            }
            $output->error(
                $error->getMessage() . '. Free the address or choose another --address=tcp://127.0.0.1:8081.',
            );
            return 1;
        }
        return 0;
    }

    /**
     * What a developer wants to know when the server comes up: where to
     * open it, what runs with it, and how to stop it.
     */
    private function announce(Output $output, string $address, int $workers, bool $single = false): void
    {
        $output->line('Starting ' . self::name($this->app) . ' server on ' . Server::describe($address));
        if ($single) {
            $left = $this->app->with();
            if ($left !== []) {
                // One process has room for HTTP alone.
                $output->line('  --single serves HTTP alone; not started: ' . implode(', ', $left));
            }
        } else {
            self::announceServers($output, $this->app, $address, $workers);
        }
        $output->line('Press Ctrl+C to stop');
    }

    /**
     * For a server that was put in the background: it is up, where, and
     * what runs with it. `status`, `reload` and `stop` say the rest when asked.
     */
    public static function announceBackground(Output $output, App $app, string $address, int $workers = 1): void
    {
        $output->line(self::name($app) . ' server running on ' . Server::describe($address));
        self::announceServers($output, $app, $address, $workers);
    }

    /**
     * The servers running under this one, each by its id: "http", then
     * every server registered, such as "queue" and "schedule", with the
     * ones "server.with" does not name shown as off. Nothing is drawn
     * for a server that runs HTTP alone, as most do while developing.
     */
    private static function announceServers(Output $output, App $app, string $address, int $workers): void
    {
        $servers = $app->servers;
        $with = $app->with();
        if ($with === []) {
            return;
        }
        $rows = [['Server', 'Workers', 'Runs'], ['http', (string) $workers, Server::describe($address)]];
        foreach ($with as $id) {
            $groups = $app->alongside([$id]);
            $runs = $servers->label($id);
            if ($servers->kind($id) === 'groups' && count($groups) > 1) {
                // Which groups of workers it brought: "queue:mail" is "mail".
                $runs .=
                    ($runs === '' ? '' : ': ')
                    . implode(', ', array_map(
                        static fn($group): string => (
                            preg_replace('/^[^:]+:/', '', $group->group) . ' x' . $group->workers
                        ),
                        $groups,
                    ));
            }
            $rows[] = [$id, (string) array_sum(array_map(static fn($group): int => $group->workers, $groups)), $runs];
        }
        foreach (array_diff($servers->ids(), $with) as $id) {
            $rows[] = [$id, 'off', $servers->label($id)];
        }
        // A rule under the heading, as wide as each column.
        $rule = [];
        foreach ($rows[0] as $column => $heading) {
            $rule[] = str_repeat('-', max(array_map(static fn(array $row): int => strlen($row[$column]), $rows)));
        }
        array_splice($rows, 1, 0, [$rule]);
        $output->line();
        $output->table($rows, '  ');
        if (count($with) < count($servers->ids())) {
            $output->line();
            $output->line('  A server that is off is switched on in "server.with", or run on its own when needed.');
        }
        $output->line();
    }

    private static function name(App $app): string
    {
        return (string) $app->config->get('app.name');
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
        $configured = $this->app->config->get('server.watch');
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
        if ($address === true) {
            throw new \InvalidArgumentException('--address needs a value; use --address=value.');
        }
        return is_string($address) ? $address : (string) $app->config->get('server.address');
    }

    /**
     * How many workers: the option, or the configuration.
     */
    public static function workers(App $app, Input $input): int
    {
        $workers = $input->option('workers');
        if ($workers === true) {
            throw new \InvalidArgumentException('--workers needs a value; use --workers=value.');
        }
        return (int) ($workers ?? $app->config->get('server.workers'));
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
            if (!($argument !== '--watch' && !str_starts_with($argument, '--watch='))) {
                continue;
            }

            $kept[] = $argument;
        }
        return $kept;
    }
}
