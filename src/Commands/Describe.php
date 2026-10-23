<?php

declare(strict_types=1);

namespace Crab\Commands;

use Composer\InstalledVersions;
use Crab\App;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Database\Migrators;
use Crab\Contracts\Http\MiddlewareStack;
use Crab\Database\DatabaseManager;

/**
 * Prints the application for coding agents and tools, as text or JSON: its routes with what each takes, the middleware, commands,
 * servers, databases and settings. Secrets in the settings are hidden.
 */
final readonly class Describe implements Command
{
    public const SECTIONS = ['app', 'routes', 'middleware', 'commands', 'servers', 'database', 'config'];

    /**
     * A setting whose name ends in one of these holds a secret.
     */
    private const SECRET = '/(?:password|secret|token|private|(?:^|_)key)$/i';

    public function __construct(
        private App $app,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Describe the application, for coding agents and tools

            Arguments:
              section   app, routes, middleware, commands, servers, database or config; more than one may be given. Without one, all.

            Options:
              --json    The same as JSON; each route's input is JSON Schema

            Plain text by default, with inputs in TypeScript notation, in fewer tokens than JSON. Settings named like a password, secret, token or key are hidden.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $asked = $input->arguments() === [] ? self::SECTIONS : $input->arguments();
        $unknown = array_diff($asked, self::SECTIONS);
        if ($unknown !== []) {
            $output->error(
                'Unknown section '
                . implode(', ', $unknown)
                . '. The sections are '
                . implode(', ', self::SECTIONS)
                . '.',
            );
            return 1;
        }
        $description = [];
        // In the order of SECTIONS, whatever order they were asked in.
        foreach (array_intersect(self::SECTIONS, $asked) as $section) {
            $description[$section] = $this->{$section}();
        }
        if (!$input->flag('json')) {
            $output->line(DescribeText::write($description));
            return 0;
        }
        $output->line((string) json_encode(
            $description,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function app(): array
    {
        try {
            $version = InstalledVersions::getPrettyVersion('crabphp/framework');
        } catch (\OutOfBoundsException) {
            $version = null;
        }
        return [
            'name' => $this->app->config->get('app.name'),
            'url' => $this->app->config->get('app.url'),
            'debug' => filter_var($this->app->config->get('app.debug'), FILTER_VALIDATE_BOOL),
            'path' => $this->app->basePath,
            'php' => PHP_VERSION,
            'framework' => $version,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>|\stdClass Each route by "METHOD /pattern".
     */
    private function routes(): array|\stdClass
    {
        // Read by agents, so what is empty is left out rather than spelled out.
        $routes = [];
        foreach ($this->app->router->routes() as $route) {
            $described = ['action' => $route->action];
            if ($route->name !== null) {
                $described['name'] = $route->name;
            }
            if ($route->middleware !== []) {
                $described['middleware'] = $route->middleware;
            }
            $taken = $this->app->router->input($route);
            if ($taken === null) {
                // A handler of its own: what it takes is not declared, which is not the same as nothing.
                $described['input'] = null;
            } else {
                $taken = array_filter(
                    $taken,
                    static fn(mixed $part): bool => (
                        $part !== null
                        && $part !== []
                        && (!$part instanceof \stdClass || (array) $part !== [])
                    ),
                );
                if ($taken !== []) {
                    $described['input'] = $taken;
                }
            }
            $routes["{$route->method} {$route->pattern}"] = $described;
        }
        return $routes === [] ? new \stdClass() : $routes;
    }

    /**
     * @return list<string>
     */
    private function middleware(): array
    {
        return $this->app->container->get(MiddlewareStack::class)->layers();
    }

    /**
     * @return array<string, string>
     */
    private function commands(): array
    {
        // The first line; `php cua <command> --help` has the rest.
        return array_map(
            static fn(string $description): string => strtok($description, "\n") ?: '',
            $this->app->console->descriptions(),
        );
    }

    /**
     * @return list<array{id: string, runs: string, on: bool}>
     */
    private function servers(): array
    {
        $on = $this->app->with();
        $servers = [];
        foreach ($this->app->servers->ids() as $id) {
            $servers[] = ['id' => $id, 'runs' => $this->app->servers->label($id), 'on' => in_array($id, $on, true)];
        }
        return $servers;
    }

    /**
     * @return array<string, mixed>
     */
    private function database(): array
    {
        $connections = $this->app->config->get('database.connections');
        // The folders the migrators use, defaults included, when the database provider is registered.
        // Migrators is bound only by the provider; has() of a class is true whenever it could be autowired.
        $databases = $this->app->container->has(Migrators::class)
            ? $this->app->container->get(DatabaseManager::class)
            : null;
        $described = [];
        foreach (is_array($connections) ? $connections : [] as $name => $connection) {
            $directory =
                $databases?->migrations((string) $name)
                ?? (is_array($connection) ? $connection['migrations'] ?? null : null);
            $migrations = [];
            if (is_string($directory) && $directory !== '') {
                foreach (glob($directory . '/*.php') ?: [] as $file) {
                    $migrations[] = basename($file, '.php');
                }
                sort($migrations);
            }
            $described[(string) $name] = [
                'driver' => strtok((string) (is_array($connection) ? $connection['dsn'] ?? '' : ''), ':') ?: null,
                'migrations' => is_string($directory) ? $this->relative($directory) : null,
                'files' => $migrations,
            ];
        }
        return [
            'default' => $this->app->config->get('database.default') ?? array_key_first($described),
            'connections' => $described === [] ? new \stdClass() : $described,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return self::hidden($this->app->config->all());
    }

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    private static function hidden(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = match (true) {
                is_string($key) && preg_match(self::SECRET, $key) === 1 && $value !== null && $value !== ''
                    => '(hidden)',
                is_array($value) => self::hidden($value),
                $value instanceof \Closure => '(closure)',
                is_object($value) => '(' . $value::class . ')',
                default => $value,
            };
        }
        return $values;
    }

    private function relative(string $path): string
    {
        $base = $this->app->basePath;
        return $base !== null && str_starts_with($path, $base . '/') ? substr($path, strlen($base) + 1) : $path;
    }
}
