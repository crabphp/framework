<?php

declare(strict_types=1);

namespace Crab;

use Crab\Config\Config;
use Crab\Config\Env;
use Crab\Contracts\Provider;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Describes an application step by step and then makes it, for app.php:
 *
 *     return App::configure(__DIR__)
 *         ->withProviders(AppProvider::class)
 *         ->withRouting(routes: 'routes/web.php', commands: 'routes/console.php')
 *         ->create();
 *
 * The directory given to configure() is the application's base path, and
 * a relative path given to any step is inside it. The builder itself has
 * no defaults: the ones an application starts with, its .env file and its
 * config folder, are steps that App::configure() takes on its behalf, and
 * can be read there.
 *
 * Nothing happens until create(), which does the steps in the order they
 * depend on one another, whatever order they were given in: environment,
 * configuration, the application, providers, middleware, then the route
 * and command files.
 */
final class AppBuilder
{
    private ?string $env = null;
    private ?string $config = null;
    private bool $configRequired = true;
    /**
     * @var list<Provider|string>
     */
    private array $providers = [];
    /**
     * @var list<MiddlewareInterface|string>
     */
    private array $middleware = [];
    /**
     * Files that declare routes and commands, in the order given.
     *
     * @var list<string>
     */
    private array $files = [];
    private readonly ?string $basePath;

    /**
     * @param string|null $basePath The application's own directory, usually `__DIR__` in app.php.
     */
    public function __construct(?string $basePath = null)
    {
        if ($basePath !== null && !is_dir($basePath)) {
            throw new \LogicException("The application's base path, {$basePath}, is not a directory.");
        }
        $this->basePath = $basePath === null ? null : rtrim($basePath, '/\\');
    }

    /**
     * The .env file with this machine's settings. It may be absent, as it
     * is where the real environment holds them.
     */
    public function withEnv(string $file): static
    {
        $this->env = $file;
        return $this;
    }

    /**
     * The directory of configuration files, each keyed by its name.
     *
     * @param bool $required Whether a directory that is not there is a mistake; when not, the application has no configuration.
     */
    public function withConfig(string $directory, bool $required = true): static
    {
        $this->config = $directory;
        $this->configRequired = $required;
        return $this;
    }

    /**
     * Providers to register, in this order; one given by class name is
     * made by the container. They may be given one by one or as a list.
     *
     * @param Provider|class-string<Provider>|list<Provider|class-string<Provider>> ...$providers
     */
    public function withProviders(Provider|string|array ...$providers): static
    {
        foreach (self::flat($providers) as $provider) {
            if (!is_string($provider) && !$provider instanceof Provider) {
                throw new \LogicException('withProviders() takes providers or their class names, not ' . get_debug_type($provider) . '.');
            }
            $this->providers[] = $provider;
        }
        return $this;
    }

    /**
     * PSR-15 middleware around every route, the first given outermost.
     * They may be given one by one or as a list.
     *
     * @param MiddlewareInterface|class-string<MiddlewareInterface>|list<MiddlewareInterface|class-string<MiddlewareInterface>> ...$middleware
     */
    public function withMiddleware(MiddlewareInterface|string|array ...$middleware): static
    {
        foreach (self::flat($middleware) as $layer) {
            if (!is_string($layer) && !$layer instanceof MiddlewareInterface) {
                throw new \LogicException('withMiddleware() takes PSR-15 middleware or their class names, not ' . get_debug_type($layer) . '.');
            }
            $this->middleware[] = $layer;
        }
        return $this;
    }

    /**
     * The files that declare the application's routes, with Route::get()
     * and the like, and its commands, with Command::add(). Each is one
     * file or a list of files:
     *
     *     ->withRouting(routes: 'routes/web.php', commands: 'routes/console.php')
     *
     * @param string|list<string> $routes
     * @param string|list<string> $commands
     */
    public function withRouting(string|array $routes = [], string|array $commands = []): static
    {
        foreach ([...(array) $routes, ...(array) $commands] as $file) {
            if (!is_string($file)) {
                throw new \LogicException('withRouting() takes file paths, not ' . get_debug_type($file) . '.');
            }
            $this->files[] = $file;
        }
        return $this;
    }

    public function create(): App
    {
        $files = array_map($this->path(...), $this->files);
        foreach ($files as $file) {
            if (!is_file($file)) {
                throw new \LogicException("The application was given {$file} to load, and there is no such file.");
            }
        }
        if ($this->env !== null) {
            // Before the configuration, whose files read it with env().
            Env::load($this->path($this->env));
        }
        $app = new App($this->config(), basePath: $this->basePath);
        $app->register(...$this->providers);
        $app->middleware(...$this->middleware);
        foreach ($files as $file) {
            self::load($file);
        }
        return $app;
    }

    private function config(): Config
    {
        if ($this->config === null) {
            return new Config();
        }
        $directory = $this->path($this->config);
        if (!$this->configRequired && !is_dir($directory)) {
            return new Config();
        }
        return Config::load($directory);
    }

    /**
     * A path as given when it is absolute or there is no base path;
     * otherwise inside the base path.
     */
    private function path(string $path): string
    {
        $absolute = $path !== '' && ($path[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]|^[a-z][a-z0-9+.-]*://~i', $path) === 1);
        return $absolute || $this->basePath === null ? $path : $this->basePath . '/' . $path;
    }

    /**
     * Arguments given one by one, as a list, or both, as one list.
     *
     * @param array<mixed> $arguments
     * @return list<mixed>
     */
    private static function flat(array $arguments): array
    {
        $flat = [];
        foreach ($arguments as $argument) {
            if (is_array($argument)) {
                array_push($flat, ...array_values($argument));
            } else {
                $flat[] = $argument;
            }
        }
        return $flat;
    }

    /**
     * Includes a file on its own, so that it sees no variable it did not
     * define: none of create()'s, and, by taking its argument unnamed, not
     * even the path.
     */
    private static function load(): void
    {
        require func_get_arg(0);
    }
}
