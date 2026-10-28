<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\App;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Server;

final readonly class Start implements Command
{
    public function __construct(
        private App $app,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Start the application's server, for production

            Options:
              --address=tcp://127.0.0.1:8080   Where to listen; default from "server.address"
              --workers=1                      Worker processes; default from "server.workers"
              --single                         Serve in this process, without pcntl and posix
              --daemon, -d                     Serve in the background and return once it is up

            For production: nothing is watched. While developing, `dev` restarts the
            server when a file is saved. SIGINT or SIGTERM stops it gracefully; in the
            background, use the `stop`, `reload`, `restart` and `status` commands.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        self::requireValues($input, ['address', 'workers']);
        if ($input->option('watch') !== null || $input->flag('no-watch')) {
            $output->error('`start` does not watch files; `dev` restarts the server when a file is saved.');
            return 1;
        }
        $server = new Server($this->app);
        $address = self::address($this->app, $input);
        $single = $input->flag('single');
        $workers = self::workers($this->app, $input);
        // Before anything starts, in the foreground or the background, so that what is wrong is said here.
        $this->app->config->check();
        if ($single) {
            $workers = 1;
        }
        $daemon = $input->flag('daemon') || $input->flag('d');

        if ($input->flag('generation')) {
            // How the keeper of a server in the background starts the server proper; see Server::start().
            $server->generation(
                is_string($input->option('address')) ? $address : null,
                $workers,
                held: $input->flag('held'),
            );
        }
        if ($daemon) {
            if ($single) {
                $output->error('--single serves in this process, so it cannot be combined with --daemon.');
                return 1;
            }
            $running = $server->files()->running();
            if ($running !== null) {
                $output->error(
                    "A server is already running in the background, pid {$running}. Use `reload` to give it new code, or `stop` to end it.",
                );
                return 1;
            }
            try {
                $server->start($address, self::given($input, $address, $workers), Server::invocation('start'));
            } catch (\RuntimeException $error) {
                $output->error($error->getMessage());
                return 1;
            }
            self::announceBackground($output, $this->app, $address, $workers);
            return 0;
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
        $output->line();
        $output->table(self::ruled($rows), '  ');
        if (count($with) < count($servers->ids())) {
            $output->line();
            $output->line('  A server that is off is switched on in "server.with", or run on its own when needed.');
        }
        $output->line();
    }

    /**
     * The rows with a rule under the heading, as wide as each column.
     *
     * @param list<list<string>> $rows
     * @return list<list<string>>
     * @internal Shared by the server commands.
     */
    public static function ruled(array $rows): array
    {
        $rule = [];
        foreach ($rows[0] as $column => $heading) {
            $rule[] = str_repeat('-', max(array_map(static fn(array $row): int => strlen($row[$column]), $rows)));
        }
        array_splice($rows, 1, 0, [$rule]);
        return $rows;
    }

    private static function name(App $app): string
    {
        return (string) $app->config->get('app.name');
    }

    /**
     * Diagnose omitted values before another option or argument can obscure them.
     *
     * @param list<string> $options
     * @internal Shared by the server commands.
     */
    public static function requireValues(Input $input, array $options): void
    {
        foreach ($options as $option) {
            if ($input->option($option) === true) {
                throw new \InvalidArgumentException("--{$option} needs a value; use --{$option}=value.");
            }
        }
    }

    /**
     * Refuses a typed --address or --workers of the wrong form before the
     * running server's state is read.
     *
     * @internal Shared by the server commands.
     */
    public static function checkTyped(App $app, Input $input): void
    {
        if ($input->option('address') !== null) {
            self::address($app, $input);
        }
        if ($input->option('workers') !== null) {
            self::workers($app, $input);
        }
    }

    /**
     * The options typed on this command line, as the server keeps them for a
     * reload or restart, merged over those it was started with.
     *
     * @param array{address?: string, workers?: string} $kept
     * @return array{address?: string, workers?: int}
     * @internal Shared by the server commands.
     */
    public static function given(Input $input, string $address, int $workers, array $kept = []): array
    {
        $given = [];
        if (is_string($input->option('address')) || isset($kept['address'])) {
            $given['address'] = $address;
        }
        if (is_string($input->option('workers')) || isset($kept['workers'])) {
            $given['workers'] = $workers;
        }
        return $given;
    }

    /**
     * Where to listen: the option, else what the running server was started
     * with ($kept), else the configuration.
     */
    public static function address(App $app, Input $input, ?string $kept = null): string
    {
        $address = $input->option('address');
        if ($address === true) {
            throw new \InvalidArgumentException('--address needs a value; use --address=value.');
        }
        $address ??= $kept ?? $app->config->get('server.address');
        $valid =
            is_string($address) && preg_match('~^tcp://(\[[^\]]+\]|[^:/?#\s]+):([0-9]+)$~D', $address, $parts) === 1;
        if ($valid) {
            $host = $parts[1];
            $valid =
                (int) $parts[2] <= 65_535
                && (
                    str_starts_with($host, '[')
                        ? filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                        : filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
                );
        }
        if (!$valid) {
            throw new \InvalidArgumentException(
                '--address (server.address) must be tcp://host:port, with a port from 0 to 65535; use --address=tcp://127.0.0.1:8080.',
            );
        }
        return $address;
    }

    /**
     * How many workers: the option, else what the running server was started
     * with ($kept), else the configuration.
     */
    public static function workers(App $app, Input $input, ?string $kept = null): int
    {
        $workers = $input->option('workers');
        if ($workers === true) {
            throw new \InvalidArgumentException('--workers needs a value; use --workers=value.');
        }
        $workers ??= $kept ?? $app->config->get('server.workers');
        if (
            !is_int($workers) && (!is_string($workers) || preg_match('/^[1-9][0-9]*$/D', $workers) !== 1)
            || filter_var($workers, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
        ) {
            throw new \InvalidArgumentException(
                '--workers (server.workers) must be a positive whole number within PHP\'s integer range; use --workers=4.',
            );
        }
        return (int) $workers;
    }
}
