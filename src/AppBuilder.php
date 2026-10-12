<?php

declare(strict_types=1);

namespace Crab;

use Crab\Config\Config;
use Crab\Config\Env;
use Crab\Contracts\Provider;
use Crab\Http\Health;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Describes an application step by step and then makes it, for app.php:
 *
 *     return App::configure(__DIR__)
 *         ->withProviders(AppProvider::class)
 *         ->withMiddleware(prepend: [new Cors(['https://app.example.com'])])
 *         ->withRouting(routes: 'routes/web.php', commands: 'routes/console.php', health: '/up')
 *         ->create();
 *
 * The directory given to configure() is the application's base path, and
 * a relative path given to any step is inside it. An application starts
 * with its .env file and its config folder, which are steps that
 * App::configure() takes on its behalf, and with the framework's own
 * providers and middleware, the lists App::PROVIDERS and App::MIDDLEWARE.
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
     * Classes of the framework's providers to leave out.
     *
     * @var list<string>
     */
    private array $withoutProviders = [];
    /**
     * The whole list in place of the framework's, when given.
     *
     * @var list<Provider|string>|null
     */
    private ?array $onlyProviders = null;
    /**
     * Middleware to put after the framework's, and before it.
     *
     * @var list<MiddlewareInterface|string>
     */
    private array $append = [];
    /**
     * @var list<MiddlewareInterface|string>
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
     * @var list<MiddlewareInterface|string>|null
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
     * The application's own providers and those of the packages it uses.
     * The application starts with the framework's, App::PROVIDERS, and
     * these are registered after them, in the order given:
     *
     *     ->withProviders([RedisProvider::class, AppProvider::class])     after the framework's
     *     ->withProviders(remove: [ScheduleProvider::class])              without one of the framework's
     *     ->withProviders(only: [AppProvider::class])                     these and no others
     *
     * One given by class name is made by the container. Called again, it
     * adds to what was said before, except that only: replaces an earlier
     * only:. One of the framework's named again is registered once, in the
     * framework's place.
     *
     * @param Provider|class-string<Provider>|list<Provider|class-string<Provider>> $providers
     * @param class-string<Provider>|list<class-string<Provider>> $remove Classes of the framework's providers to leave out.
     * @param list<Provider|class-string<Provider>>|null $only The whole list in place of the framework's.
     */
    public function withProviders(Provider|string|array $providers = [], string|array $remove = [], ?array $only = null): static
    {
        $this->providers = [...$this->providers, ...self::providers(is_array($providers) ? $providers : [$providers])];
        foreach ((array) $remove as $class) {
            if (!is_string($class) || !in_array($class, App::PROVIDERS, true)) {
                $given = is_string($class) ? $class : get_debug_type($class);
                throw new \LogicException("withProviders(remove:) leaves out one of the framework's providers, and {$given} is not one of them: " . implode(', ', App::PROVIDERS) . '. A provider of your own is left out where you added it.');
            }
            $this->withoutProviders[] = $class;
        }
        if ($only !== null) {
            $this->onlyProviders = self::providers($only);
        }
        if ($this->onlyProviders !== null && $this->withoutProviders !== []) {
            throw new \LogicException("withProviders() was given both only: and remove:. only: already replaces the framework's providers, so leave out of it what you do not want.");
        }
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
     * @param MiddlewareInterface|class-string<MiddlewareInterface>|list<MiddlewareInterface|class-string<MiddlewareInterface>> $append
     * @param MiddlewareInterface|class-string<MiddlewareInterface>|list<MiddlewareInterface|class-string<MiddlewareInterface>> $prepend
     * @param class-string<MiddlewareInterface>|list<class-string<MiddlewareInterface>> $remove Classes of the framework's middleware to leave out.
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>>|null $only The whole list in place of the framework's.
     */
    public function withMiddleware(
        MiddlewareInterface|string|array $append = [],
        MiddlewareInterface|string|array $prepend = [],
        string|array $remove = [],
        ?array $only = null,
    ): static {
        // Each takes one or a list; only: is always the whole list.
        $this->append = [...$this->append, ...self::middleware(is_array($append) ? $append : [$append])];
        $this->prepend = [...$this->prepend, ...self::middleware(is_array($prepend) ? $prepend : [$prepend])];
        foreach ((array) $remove as $class) {
            if (!is_string($class)) {
                throw new \LogicException('withMiddleware(remove:) takes the class names of middleware to leave out, not ' . get_debug_type($class) . '.');
            }
            if (!in_array($class, App::MIDDLEWARE, true)) {
                throw new \LogicException("withMiddleware(remove:) leaves out one of the framework's middleware, and {$class} is not one of them: " . implode(', ', App::MIDDLEWARE) . '. Middleware of your own is left out where you added it.');
            }
            $this->remove[] = $class;
        }
        if ($only !== null) {
            $this->only = self::middleware($only);
        }
        if ($this->only !== null && $this->remove !== []) {
            throw new \LogicException("withMiddleware() was given both only: and remove:. only: already replaces the framework's middleware, so leave out of it what you do not want.");
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
        $app = new App($this->config(), basePath: $this->basePath, middleware: $this->globalMiddleware());
        $app->register(...$this->allProviders());
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
     * @return list<MiddlewareInterface|string>
     */
    private function globalMiddleware(): array
    {
        $middle = $this->only;
        if ($middle === null) {
            $middle = [];
            foreach (App::MIDDLEWARE as $class) {
                if (!in_array($class, $this->remove, true)) {
                    $middle[] = $class;
                }
            }
        }
        return [...$this->prepend, ...$middle, ...$this->append];
    }

    /**
     * The providers to register, in order: the framework's or what
     * replaces them, then the application's. A class of the framework's
     * that the application names as well is registered once.
     *
     * @return list<Provider|string>
     */
    private function allProviders(): array
    {
        $first = $this->onlyProviders ?? array_values(array_diff(App::PROVIDERS, $this->withoutProviders));
        $providers = [];
        $seen = [];
        foreach ([...$first, ...$this->providers] as $provider) {
            $class = is_string($provider) ? $provider : $provider::class;
            if (in_array($class, App::PROVIDERS, true)) {
                if (isset($seen[$class])) {
                    continue;
                }
                $seen[$class] = true;
            }
            $providers[] = $provider;
        }
        return $providers;
    }

    /**
     * @param array<mixed> $list
     * @return list<Provider|string>
     */
    private static function providers(array $list): array
    {
        foreach ($list as $provider) {
            if (!is_string($provider) && !$provider instanceof Provider) {
                throw new \LogicException('withProviders() takes providers or their class names, not ' . get_debug_type($provider) . '.');
            }
        }
        return array_values($list);
    }

    /**
     * @param array<mixed> $list
     * @return list<MiddlewareInterface|string>
     */
    private static function middleware(array $list): array
    {
        foreach ($list as $layer) {
            if (!is_string($layer) && !$layer instanceof MiddlewareInterface) {
                throw new \LogicException('withMiddleware() takes PSR-15 middleware or their class names, not ' . get_debug_type($layer) . '.');
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
        $absolute = $path !== '' && ($path[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]|^[a-z][a-z0-9+.-]*://~i', $path) === 1);
        return $absolute || $this->basePath === null ? $path : $this->basePath . '/' . $path;
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
