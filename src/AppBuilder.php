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
 *     return App::configure()
 *         ->withEnv(__DIR__ . '/.env')
 *         ->withConfig(__DIR__ . '/config')
 *         ->withProviders(AppProvider::class)
 *         ->withRoutes(__DIR__ . '/routes/web.php')
 *         ->withCommands(__DIR__ . '/routes/console.php')
 *         ->create();
 *
 * Nothing happens until create(), which does the steps in the order they
 * depend on one another, whatever order they were given in: environment,
 * configuration, the application, providers, middleware, then the route
 * and command files. Every path is given in full; nothing is found by
 * convention.
 */
final class AppBuilder
{
    private ?string $env = null;
    private ?string $config = null;
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

    /**
     * A .env file with this machine's settings. It may be absent, as it is
     * where the real environment holds them.
     */
    public function withEnv(string $file): static
    {
        $this->env = $file;
        return $this;
    }

    /**
     * The directory of configuration files, each keyed by its file name.
     */
    public function withConfig(string $directory): static
    {
        $this->config = $directory;
        return $this;
    }

    /**
     * Providers to register, in this order; one given by class name is
     * made by the container.
     */
    public function withProviders(Provider|string ...$providers): static
    {
        $this->providers = [...$this->providers, ...array_values($providers)];
        return $this;
    }

    /**
     * PSR-15 middleware around every route, the first given outermost.
     */
    public function withMiddleware(MiddlewareInterface|string ...$middleware): static
    {
        $this->middleware = [...$this->middleware, ...array_values($middleware)];
        return $this;
    }

    /**
     * Files that declare routes with Route::get() and the like.
     */
    public function withRoutes(string ...$files): static
    {
        $this->files = [...$this->files, ...array_values($files)];
        return $this;
    }

    /**
     * Files that declare commands with Command::add().
     */
    public function withCommands(string ...$files): static
    {
        $this->files = [...$this->files, ...array_values($files)];
        return $this;
    }

    public function create(): App
    {
        foreach ($this->files as $file) {
            if (!is_file($file)) {
                throw new \LogicException("The application was given {$file} to load, and there is no such file.");
            }
        }
        if ($this->env !== null) {
            // Before the configuration, whose files read it with env().
            Env::load($this->env);
        }
        $app = $this->config === null ? new App() : new App(Config::load($this->config));
        $app->register(...$this->providers);
        $app->middleware(...$this->middleware);
        foreach ($this->files as $file) {
            self::load($file);
        }
        return $app;
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
