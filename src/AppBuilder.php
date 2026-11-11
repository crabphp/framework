<?php

declare(strict_types=1);

namespace Crab;

use Crab\Config\Config;
use Crab\Config\Env;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Plugin;
use Crab\Http\Health;

/**
 * Describes an application step by step and then makes it, for app.php:
 *
 *     return App::configure(__DIR__)
 *         ->withSetup(Setup::class)
 *         ->withMiddleware(prepend: [new Cors(['https://app.example.com'])])
 *         ->withRouting(routes: 'routes/web.php', commands: 'routes/console.php', health: '/up')
 *         ->create();
 *
 * The directory given to configure() is the application's base path, and
 * a relative path given to any step is inside it. An application starts
 * with its .env file and its config folder, which are steps that
 * App::configure() takes on its behalf, and with the framework's own
 * plugins and middleware, the lists App::PLUGINS and App::MIDDLEWARE.
 *
 * Nothing happens until create(), which does the steps in the order they
 * depend on one another, whatever order they were given in: environment,
 * configuration, the application, plugins, middleware, then the route
 * and command files.
 */
final class AppBuilder
{
    private ?string $env = null;
    private ?string $storage = null;
    private ?string $config = null;
    private bool $configRequired = true;
    /**
     * @var list<Plugin|string>
     */
    private array $plugins = [];
    /**
     * The application's own setup, registered after every plugin.
     *
     * @var list<Plugin|string>
     */
    private array $setup = [];
    /**
     * Classes of the framework's plugins to leave out.
     *
     * @var list<string>
     */
    private array $withoutPlugins = [];
    /**
     * The whole list in place of the framework's, when given.
     *
     * @var list<Plugin|string>|null
     */
    private ?array $onlyPlugins = null;
    /**
     * Middleware to put after the framework's, and before it.
     *
     * @var list<Middleware|string>
     */
    private array $append = [];
    /**
     * @var list<Middleware|string>
     */
    private array $prepend = [];
    /**
     * Classes of the framework's middleware to leave out.
     *
     * @var list<string>
     */
    private array $remove = [];
    /**
     * The whole list in place of the framework's, when given.
     *
     * @var list<Middleware|string>|null
     */
    private ?array $only = null;
    /**
     * Files that declare routes and commands, in the order given.
     *
     * @var list<string>
     */
    private array $files = [];
    /**
     * The path that answers that the application is up.
     */
    private ?string $health = null;
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
     * is where the real environment holds them. A relative path is in the
     * application's folder, or beside the phar it is packed into.
     */
    public function withEnv(string $file): static
    {
        $this->env = $file;
        return $this;
    }

    /**
     * Where the application writes, in place of "storage" in its folder:
     * logs, the pid file, the file cache, the local disk, a SQLite file. A
     * relative path is in the application's folder, or beside the phar it
     * is packed into. The CRAB_STORAGE environment variable does the same
     * without a change to the code, and this takes precedence over it.
     */
    public function withStorage(string $directory): static
    {
        $this->storage = rtrim($directory, '/\\');
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
     * The framework's optional plugins and those of the packages the
     * application uses. It starts with the core ones, App::PLUGINS, and
     * these are registered after them, in the order given; its own setup,
     * withSetup(), comes after all of them:
     *
     *     ->withPlugins([QueuePlugin::class, RedisPlugin::class])     after the core plugins
     *     ->withPlugins(remove: [CachePlugin::class])                  without one of the core plugins
     *     ->withPlugins(only: [DatabasePlugin::class])                 these and no others
     *
     * One given by class name is made by the container. Called again, it
     * adds to what was said before, except that only: replaces an earlier
     * only:. One of the framework's named again is registered once, in the
     * framework's place.
     *
     * @param Plugin|class-string<Plugin>|list<Plugin|class-string<Plugin>> $plugins
     * @param class-string<Plugin>|list<class-string<Plugin>> $remove Classes of the framework's plugins to leave out.
     * @param list<Plugin|class-string<Plugin>>|null $only The whole list in place of the framework's.
     */
    public function withPlugins(
        Plugin|string|array $plugins = [],
        string|array $remove = [],
        ?array $only = null,
    ): static {
        $this->plugins = [...$this->plugins, ...self::plugins(is_array($plugins) ? $plugins : [$plugins])];
        foreach ((array) $remove as $class) {
            if (!is_string($class) || !in_array($class, App::PLUGINS, true)) {
                $given = is_string($class) ? $class : get_debug_type($class);
                throw new \LogicException(
                    "withPlugins(remove:) leaves out one of the framework's plugins, and {$given} is not one of them: "
                    . implode(', ', App::PLUGINS)
                    . '. A plugin of your own is left out where you added it.',
                );
            }
            $this->withoutPlugins[] = $class;
        }
        if ($only !== null) {
            $this->onlyPlugins = self::plugins($only);
        }
        if ($this->onlyPlugins !== null && $this->withoutPlugins !== []) {
            throw new \LogicException(
                "withPlugins() was given both only: and remove:. only: already replaces the framework's plugins, so leave out of it what you do not want.",
            );
        }
        return $this;
    }

    /**
     * The application's own setup: its bindings, listeners and scheduled
     * tasks, in a class of its own, app/Setup.php in the skeleton. It is
     * a Plugin in all but name, registered after every plugin, so the
     * services it builds on are there:
     *
     *     ->withSetup(Setup::class)
     *
     * Called again, or given a list, it adds to what was said before, in
     * that order.
     *
     * @param Plugin|class-string<Plugin>|list<Plugin|class-string<Plugin>> $setup
     */
    public function withSetup(Plugin|string|array $setup): static
    {
        $this->setup = [...$this->setup, ...self::plugins(is_array($setup) ? $setup : [$setup], 'withSetup')];
        return $this;
    }

    /**
     * The middleware around every request. The application starts with
     * the framework's, App::MIDDLEWARE, and this says how its own relate
     * to them:
     *
     *     ->withMiddleware([AddApiVersion::class])                after the framework's
     *     ->withMiddleware(prepend: [RequestId::class])           before them, outermost
     *     ->withMiddleware(remove: [ErrorHandler::class])         without one of the framework's
     *     ->withMiddleware(only: [MyErrors::class])               these and no others
     *
     * Within each list the first is outermost. Called again, it adds to
     * what was said before, except that only: replaces an earlier only:.
     * Middleware for some routes only belongs on the route or its group.
     *
     * @param Middleware|class-string<Middleware>|list<Middleware|class-string<Middleware>> $append
     * @param Middleware|class-string<Middleware>|list<Middleware|class-string<Middleware>> $prepend
     * @param class-string<Middleware>|list<class-string<Middleware>> $remove Classes of the framework's middleware to leave out.
     * @param list<Middleware|class-string<Middleware>>|null $only The whole list in place of the framework's.
     */
    public function withMiddleware(
        Middleware|string|array $append = [],
        Middleware|string|array $prepend = [],
        string|array $remove = [],
        ?array $only = null,
    ): static {
        // Each takes one or a list; only: is always the whole list.
        $this->append = [...$this->append, ...self::middleware(is_array($append) ? $append : [$append])];
        $this->prepend = [...$this->prepend, ...self::middleware(is_array($prepend) ? $prepend : [$prepend])];
        foreach ((array) $remove as $class) {
            if (!is_string($class)) {
                throw new \LogicException(
                    'withMiddleware(remove:) takes the class names of middleware to leave out, not '
                    . get_debug_type($class)
                    . '.',
                );
            }
            if (!in_array($class, App::MIDDLEWARE, true)) {
                throw new \LogicException(
                    "withMiddleware(remove:) leaves out one of the framework's middleware, and {$class} is not one of them: "
                    . implode(', ', App::MIDDLEWARE)
                    . '. Middleware of your own is left out where you added it.',
                );
            }
            $this->remove[] = $class;
        }
        if ($only !== null) {
            $this->only = self::middleware($only);
        }
        if ($this->only !== null && $this->remove !== []) {
            throw new \LogicException(
                "withMiddleware() was given both only: and remove:. only: already replaces the framework's middleware, so leave out of it what you do not want.",
            );
        }
        return $this;
    }

    /**
     * The files that declare the application's routes, with Route::get()
     * and the like, and its commands, with Command::add(). Each is one
     * file or a list of files:
     *
     *     ->withRouting(routes: 'routes/web.php', commands: 'routes/console.php', health: '/up')
     *
     * With health:, a GET of that path is answered 200 and {"status":"up"}
     * by Http\Health, for whatever needs to know the server takes requests.
     *
     * @param string|list<string> $routes
     * @param string|list<string> $commands
     * @param string|null $health The path that says the application is up; none when not given.
     */
    public function withRouting(string|array $routes = [], string|array $commands = [], ?string $health = null): static
    {
        $this->health = $health ?? $this->health;
        foreach ([...((array) $routes), ...((array) $commands)] as $file) {
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
            Env::load($this->outside($this->env));
        }
        // Read after the .env, which may set CRAB_STORAGE, and named before the configuration, whose files may call storage_path().
        $storage = $this->storage === null ? App::storageDirectory($this->basePath) : $this->outside($this->storage);
        App::preparing($storage);
        try {
            $config = $this->config();
        } finally {
            App::preparing(null);
        }
        $app = new App(
            $config,
            basePath: $this->basePath,
            middleware: $this->globalMiddleware(),
            storagePath: $storage,
        );
        $app->register(...$this->allPlugins(), ...$this->setup);
        if ($this->health !== null) {
            $app->router->get($this->health, new Health());
        }
        foreach ($files as $file) {
            self::load($file);
        }
        return $app;
    }

    /**
     * The middleware around every request, outermost first: what was
     * prepended, then the framework's or what replaces it, then what was
     * appended.
     *
     * @return list<Middleware|string>
     */
    private function globalMiddleware(): array
    {
        $middle = $this->only;
        if ($middle === null) {
            $middle = [];
            foreach (App::MIDDLEWARE as $class) {
                if (in_array($class, $this->remove, true)) {
                    continue;
                }

                $middle[] = $class;
            }
        }
        return [...$this->prepend, ...$middle, ...$this->append];
    }

    /**
     * The plugins to register, in order: the core ones or what replaces
     * them, then the application's. A framework plugin, core or optional,
     * named more than once is registered once, where it was first named.
     *
     * @return list<Plugin|string>
     */
    private function allPlugins(): array
    {
        $first = $this->onlyPlugins ?? array_values(array_diff(App::PLUGINS, $this->withoutPlugins));
        $plugins = [];
        $seen = [];
        foreach ([...$first, ...$this->plugins] as $plugin) {
            $class = is_string($plugin) ? $plugin : $plugin::class;
            if (in_array($class, App::PLUGINS, true) || in_array($class, App::OPTIONAL_PLUGINS, true)) {
                if (isset($seen[$class])) {
                    continue;
                }
                $seen[$class] = true;
            }
            $plugins[] = $plugin;
        }
        return $plugins;
    }

    /**
     * @param array<mixed> $list
     * @return list<Plugin|string>
     */
    private static function plugins(array $list, string $method = 'withPlugins'): array
    {
        foreach ($list as $plugin) {
            if (!is_string($plugin) && !$plugin instanceof Plugin) {
                throw new \LogicException(
                    "{$method}() takes classes implementing "
                    . Plugin::class
                    . ', or their names, not '
                    . get_debug_type($plugin)
                    . '.',
                );
            }
        }
        return array_values($list);
    }

    /**
     * @param array<mixed> $list
     * @return list<Middleware|string>
     */
    private static function middleware(array $list): array
    {
        foreach ($list as $layer) {
            if (!is_string($layer) && !$layer instanceof Middleware) {
                throw new \LogicException(
                    'withMiddleware() takes middleware or their class names, not ' . get_debug_type($layer) . '.',
                );
            }
        }
        return array_values($list);
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
        $absolute =
            $path !== '' && ($path[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]|^[a-z][a-z0-9+.-]*://~i', $path) === 1);
        return $absolute || $this->basePath === null ? $path : $this->basePath . '/' . $path;
    }

    /**
     * A path to something that is not the application's code, which is
     * never inside a phar: as path(), but beside the phar when the
     * application is packed into one. See App::outside().
     */
    private function outside(string $path): string
    {
        if ($this->basePath === null || !str_starts_with($this->basePath, 'phar://')) {
            return $this->path($path);
        }
        $absolute = $path !== '' && ($path[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1);
        return $absolute ? $path : App::outside($this->basePath) . '/' . $path;
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
