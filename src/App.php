<?php

declare(strict_types=1);

namespace Crab;

use Crab\Auth\AuthPlugin;
use Crab\Auth\TokenAuthPlugin;
use Crab\Broadcast\BroadcastPlugin;
use Crab\Broadcast\Lifecycle as BroadcastLifecycle;
use Crab\Cache\CachePlugin;
use Crab\Clock\SystemClock;
use Crab\Commands\Describe;
use Crab\Commands\Dev;
use Crab\Commands\MakeCommand;
use Crab\Commands\MakeMiddleware;
use Crab\Commands\PublishConfig;
use Crab\Commands\Reload;
use Crab\Commands\Restart;
use Crab\Commands\Routes;
use Crab\Commands\Start;
use Crab\Commands\Status;
use Crab\Commands\Stop;
use Crab\Config\Config;
use Crab\Config\Env;
use Crab\Console\Console;
use Crab\Container\Container;
use Crab\Container\ServicesException;
use Crab\Contracts\Broadcast\Broadcaster;
use Crab\Contracts\Clock\Clock as ClockContract;
use Crab\Contracts\Config\Config as ConfigContract;
use Crab\Contracts\Console\Console as ConsoleContract;
use Crab\Contracts\Container\Container as ContainerContract;
use Crab\Contracts\Events\Dispatcher as DispatcherContract;
use Crab\Contracts\Http\ErrorRenderer;
use Crab\Contracts\Http\Factory as FactoryContract;
use Crab\Contracts\Http\Handler;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Http\MiddlewareStack as MiddlewareStackContract;
use Crab\Contracts\Http\Request;
use Crab\Contracts\Log\Logger as LoggerContract;
use Crab\Contracts\Plugin;
use Crab\Contracts\Routing\Router as RouterContract;
use Crab\Contracts\Routing\Urls as UrlsContract;
use Crab\Contracts\Runner;
use Crab\Contracts\Schedule\Schedule as ScheduleContract;
use Crab\Contracts\Servers as ServersContract;
use Crab\Contracts\Validation\Validator as ValidatorContract;
use Crab\Contracts\WebSocket\Channels as SocketChannels;
use Crab\Contracts\WorkerPlugin;
use Crab\Contracts\Workers;
use Crab\Database\DatabasePlugin;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\EventLoop\RevoltEventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Engine\FailureReporter;
use Crab\Engine\Process\Interpreter;
use Crab\Engine\Process\Worker;
use Crab\Engine\Runtime;
use Crab\Engine\Server\HttpServer;
use Crab\Events\EventsPlugin;
use Crab\Http\EngineHandler;
use Crab\Http\ErrorHandler;
use Crab\Http\Factory;
use Crab\Http\MiddlewareStack;
use Crab\Http\RenderedErrors;
use Crab\Http\StaticFiles;
use Crab\HttpClient\HttpClientPlugin;
use Crab\Log\Logger;
use Crab\Queue\QueuePlugin;
use Crab\Routing\Router;
use Crab\Routing\Urls;
use Crab\Schedule\SchedulePlugin;
use Crab\Schedule\Task;
use Crab\Session\SessionPlugin;
use Crab\Storage\StoragePlugin;
use Crab\Validation\Validator;
use Crab\WebSocket\Connections;
use Crab\WebSocket\Settings as SocketSettings;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * An application: configuration, a container, routes, the middleware in
 * front of them, console commands and the engine runtime that serves them.
 *
 * It reads these configuration keys: "app.name" heads the command list,
 * "app.debug" shows failure details to clients, "app.url" is where the
 * application is reached, put in front of the URLs Urls writes, "log.path" is where the
 * default logger writes (standard error when unset) and "log.level" is
 * the least severe level it writes. Of the server it serves requests
 * with, "server.max_body" is the largest request body it takes, in bytes
 * (1 MiB unless set), and "server.body_timeout" how many seconds a client
 * has to send one (30).
 */
final class App implements Handler, Runner
{
    private static ?self $current = null;

    /**
     * The storage directory of the application being built, while its
     * configuration files are read and before it exists; see storage().
     */
    private static ?string $preparing = null;

    /**
     * Where the application writes: logs, the pid file, the file cache, the
     * local disk, a SQLite file. Null for one with nowhere of its own.
     */
    private readonly ?string $storage;

    public readonly RouterContract $router;
    public readonly ConsoleContract $console;
    /**
     * The servers `start` can run beside HTTP, as plugins have registered them.
     */
    public readonly Servers $servers;
    private readonly MiddlewareStack $stack;
    private ?Handler $entry = null;
    /**
     * Places a deprecation has already been logged from, in this worker.
     *
     * @var array<string, true>
     */
    private array $deprecations = [];
    /**
     * @var list<WorkerPlugin>
     */
    private array $workerPlugins = [];
    /**
     * The router as the class it is, for what only the application asks of it.
     */
    private readonly Router $routes;

    /**
     * The middleware around every request unless an application says
     * otherwise: the error handler, which turns whatever is thrown beneath
     * it into a response.
     */
    public const MIDDLEWARE = [ErrorHandler::class];

    /**
     * The core plugins, which an application made with App::configure()
     * starts with, events first among those that report through them. Each
     * binds on first use, so one the application never touches opens
     * nothing. AppBuilder::withPlugins() adds the rest after them, in the
     * order given, and can leave one of these out.
     *
     * The framework's optional plugins, OPTIONAL_PLUGINS, are listed only
     * by an application that wants them.
     */
    public const PLUGINS = [
        DatabasePlugin::class,
        AuthPlugin::class,
        CachePlugin::class,
        StoragePlugin::class,
        EventsPlugin::class,
        HttpClientPlugin::class,
    ];

    /**
     * The framework's plugins an application lists in withPlugins() when it
     * wants them. A package's plugins, such as crabphp/runs' after the
     * queue's or crabphp/actors' after broadcast's, are listed the same way.
     */
    public const OPTIONAL_PLUGINS = [
        QueuePlugin::class,
        SchedulePlugin::class,
        BroadcastPlugin::class,
        TokenAuthPlugin::class,
        SessionPlugin::class,
    ];

    /**
     * @param string|null $basePath The application's own directory, which path() resolves against.
     * @param list<Middleware|class-string<Middleware>> $middleware The middleware around every request, the first outermost. A plugin adds inside it through the MiddlewareStack contract.
     * @param string|null $storagePath Where it writes; see storageDirectory() when null.
     */
    public function __construct(
        public readonly ConfigContract $config = new Config(),
        public readonly ContainerContract $container = new Container(),
        public readonly ?string $basePath = null,
        array $middleware = self::MIDDLEWARE,
        ?string $storagePath = null,
    ) {
        $this->storage = $storagePath ?? self::storageDirectory($basePath);
        $config->defaults(
            'app',
            [
                'name' => 'Crab',
                'debug' => false,
                'url' => null,
                'timezone' => 'UTC',
                'path' => $basePath,
                'storage' => $this->storage,
            ],
            [
                'name' => ['type' => 'string'],
                'debug' => ['type' => 'boolean'],
                'timezone' => ['type' => 'string', 'format' => 'timezone', 'examples' => ['UTC', 'Europe/Paris']],
                'url' => ['type' => ['string', 'null'], 'examples' => ['https://api.example.com']],
                'path' => ['type' => ['string', 'null']],
                'storage' => ['type' => ['string', 'null']],
                // The application's own settings may sit beside these.
                '*' => [],
            ],
        );
        $config->defaults(
            'log',
            ['path' => 'php://stderr', 'level' => 'debug'],
            [
                'path' => ['type' => 'string', 'minLength' => 1, 'examples' => ['storage/logs/app.log']],
                'level' => ['enum' => [
                    'debug',
                    'info',
                    'notice',
                    'warning',
                    'error',
                    'critical',
                    'alert',
                    'emergency',
                ]],
            ],
        );
        $config->defaults('server', Server::DEFAULTS, Server::RULES);
        $config->defaults('websocket', SocketSettings::DEFAULTS, SocketSettings::RULES);
        self::$current = $this;
        // Every date the process makes, writes and reads is in this zone: the clock, the
        // database and the logs alike. A zone that does not exist is said with the other
        // settings when the application starts, and PHP's own is kept until then.
        $timezone = $config->get('app.timezone');
        if (is_string($timezone) && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            date_default_timezone_set($timezone);
        }
        $container->instance(self::class, $this);
        // What runs workers that serve no requests, for a plugin's commands.
        $container->instance(Runner::class, $this);
        // What `status` and `stop` act on, where the settings put it.
        $container->singleton(ServerFiles::class, fn(): ServerFiles => new Server($this)->files());
        $this->servers = new Servers();
        $container->instance(ServersContract::class, $this->servers);
        $container->singleton(Servers::class, ServersContract::class);
        $container->instance(ConfigContract::class, $config);
        // Asking by class must not autowire a second, empty one.
        $container->singleton($config::class, ConfigContract::class);
        $container->singleton(
            LoggerContract::class,
            fn(): Logger => new Logger(
                // A relative path is inside the application's directory, not wherever the process was started.
                $basePath === null ? $config->get('log.path') : $this->path((string) $config->get('log.path')),
                $config->get('log.level'),
                $container->get(ClockContract::class),
            ),
        );
        // Each service is also reachable by its PSR name and by class, as the same object.
        $container->singleton(LoggerInterface::class, LoggerContract::class);
        $container->singleton(Logger::class, LoggerContract::class);
        $container->singleton(ClockContract::class, static fn(): SystemClock => new SystemClock());
        $container->singleton(ClockInterface::class, ClockContract::class);
        $container->singleton(SystemClock::class, ClockContract::class);
        $container->singleton(
            ErrorHandler::class,
            static fn(ContainerContract $container): ErrorHandler => new ErrorHandler(
                $container->get(LoggerInterface::class),
                filter_var($config->get('app.debug'), FILTER_VALIDATE_BOOL),
            ),
        );
        $container->singleton(FactoryContract::class, static fn(): Factory => new Factory());
        $container->singleton(Factory::class, FactoryContract::class);
        foreach ([
            ResponseFactoryInterface::class,
            ServerRequestFactoryInterface::class,
            StreamFactoryInterface::class,
            UploadedFileFactoryInterface::class,
            UriFactoryInterface::class,
        ] as $factory) {
            $container->singleton($factory, FactoryContract::class);
        }
        // Made here because routes are declared, and their inputs read, before any worker starts.
        $validator = new Validator();
        $container->instance(ValidatorContract::class, $validator);
        $container->singleton(Validator::class, ValidatorContract::class);
        $public = $config->get('server.public');
        $static =
            $basePath !== null && is_string($public) && $public !== ''
                ? new StaticFiles($this->path($public), $container->get(FactoryContract::class))
                : null;
        $router = new Router(
            $container,
            $validator,
            fn(): Connections => new Connections($this->socketSettings(), static function () use (
                $container,
            ): SocketChannels {
                if (!$container->has(SocketChannels::class)) {
                    throw new \LogicException(
                        'To join WebSocket channels, add BroadcastPlugin to withPlugins() in app.php.',
                    );
                }
                // A socket-pair application is serving in this process too.
                // Keep this work on first channel use, off the HTTP path.
                if ($container->has(Broadcaster::class)) {
                    $container->get(BroadcastLifecycle::class)->serving = true;
                }
                return $container->get(SocketChannels::class);
            }),
            $static,
        );
        $this->router = $router;
        $this->routes = $router;
        $container->instance(RouterContract::class, $this->router);
        $container->singleton(Router::class, RouterContract::class);
        $container->singleton(UrlsContract::class, fn(): Urls => new Urls($this->router, $config->get('app.url')));
        $container->singleton(Urls::class, UrlsContract::class);
        // The error handler is the entry bound as ErrorHandler; rebinding it to
        // another middleware replaces how failures are answered.
        $this->stack = new MiddlewareStack($this->router, $container);
        $this->stack->add(...$middleware);
        $container->instance(MiddlewareStackContract::class, $this->stack);
        $container->singleton(MiddlewareStack::class, MiddlewareStackContract::class);
        // A name that is not text is reported by the settings check, not by a warning here.
        $name = $config->get('app.name');
        $this->console = new Console($container, is_scalar($name) ? (string) $name : 'Crab');
        $this->console->add('dev', Dev::class);
        $this->console->add('start', Start::class);
        $this->console->add('stop', Stop::class);
        $this->console->add('reload', Reload::class);
        $this->console->add('restart', Restart::class);
        $this->console->add('status', Status::class);
        $this->console->add('route:list', Routes::class);
        $this->console->add('describe', Describe::class);
        $this->console->add('make:middleware', MakeMiddleware::class);
        $this->console->add('make:command', MakeCommand::class);
        $this->console->add('config:publish', PublishConfig::class);
        $container->instance(ConsoleContract::class, $this->console);
        $container->singleton(Console::class, ConsoleContract::class);
    }

    /**
     * Starts describing an application; see AppBuilder. Given the
     * application's directory, `App::configure(__DIR__)`, paths may be
     * relative to it and the defaults below apply: its .env file and its
     * config folder. They are ordinary steps, so calling one again in
     * app.php replaces it.
     */
    public static function configure(?string $basePath = null): AppBuilder
    {
        $builder = new AppBuilder($basePath);
        if ($basePath === null) {
            // With no directory to look in, there is nothing to load by default.
            return $builder;
        }
        return $builder->withEnv('.env')->withConfig('config', required: false);
    }

    /**
     * A path inside the application's directory: path('routes/web.php').
     * One that starts with "storage/" is inside the storage directory,
     * wherever that is: path('storage/logs/app.log'). A path that is
     * already absolute is returned as it is.
     */
    public function path(string $path = ''): string
    {
        if (
            $path !== ''
            && ($path[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]|^[a-z][a-z0-9+.-]*://~i', $path) === 1)
        ) {
            return $path;
        }
        if ($path === 'storage' || str_starts_with($path, 'storage/')) {
            return $this->storagePath(substr($path, strlen('storage/')));
        }
        if ($this->basePath === null) {
            throw new \LogicException('This application has no base path. Give it one with App::configure(__DIR__).');
        }
        return $path === '' ? $this->basePath : $this->basePath . '/' . $path;
    }

    /**
     * A path inside the storage directory: storagePath('logs/app.log').
     */
    public function storagePath(string $path = ''): string
    {
        if ($this->storage === null) {
            throw new \LogicException(
                'This application has nowhere to write. Give it a base path with App::configure(__DIR__), or a storage directory with withStorage() or CRAB_STORAGE.',
            );
        }
        return $path === '' ? $this->storage : $this->storage . '/' . ltrim($path, '/');
    }

    /**
     * The storage directory an application has unless told otherwise: the
     * CRAB_STORAGE environment variable, relative to the working directory
     * when it is not absolute; else "storage" in the folder outside() names.
     */
    public static function storageDirectory(?string $basePath): ?string
    {
        $given = Env::get('CRAB_STORAGE');
        if (is_string($given) && $given !== '') {
            return $given[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $given) === 1
                ? $given
                : getcwd() . '/' . $given;
        }
        return $basePath === null ? null : self::outside($basePath) . '/storage';
    }

    /**
     * The folder of the files that are not the application's code: what it
     * writes, and its .env. That is its own folder, but for an application
     * packed into a phar, such as a single executable, the folder the phar
     * is in, since nothing can be written inside it.
     */
    public static function outside(string $basePath): string
    {
        if (!str_starts_with($basePath, 'phar://')) {
            return $basePath;
        }
        // phar:///srv/app/crab.phar/app: the archive is the deepest of these that is a file.
        $archive = substr($basePath, strlen('phar://'));
        while (!is_file($archive) && dirname($archive) !== $archive) {
            $archive = dirname($archive);
        }
        return dirname($archive);
    }

    /**
     * A path in the storage directory of the application being built, or
     * else of the current one: what storage_path() returns.
     */
    public static function storage(string $path = ''): string
    {
        if (self::$preparing !== null) {
            return $path === '' ? self::$preparing : self::$preparing . '/' . ltrim($path, '/');
        }
        return self::current()->storagePath($path);
    }

    /**
     * Names the storage directory of the application about to be built,
     * for the configuration files it reads first.
     *
     * @internal For AppBuilder.
     */
    public static function preparing(?string $storage): void
    {
        self::$preparing = $storage;
    }

    /**
     * The application most recently created in this process; the global
     * helpers read from it, and Route and Command declare on it.
     */
    public static function current(): self
    {
        return (
            self::$current ?? throw new \LogicException(
                'No application has been created. Create the App before declaring routes or commands, or reading config().',
            )
        );
    }

    /**
     * Lets plugins add their bindings; one given by class name is made
     * by the container. A WorkerPlugin is also started and stopped in
     * each worker.
     */
    public function register(Plugin|string ...$plugins): static
    {
        foreach ($plugins as $plugin) {
            if (is_string($plugin)) {
                $name = $plugin;
                $plugin = $this->container->get($name);
                if (!$plugin instanceof Plugin) {
                    throw new \LogicException("{$name} must implement " . Plugin::class . '.');
                }
            }
            $plugin->register($this->container);
            if ($plugin instanceof WorkerPlugin) {
                $this->workerPlugins[] = $plugin;
            }
        }
        return $this;
    }

    public function handle(Request $request): ResponseInterface
    {
        return $this->entry()->handle($request);
    }

    /**
     * What a request enters: the middleware stack, behind the
     * application's ErrorRenderer when it binds one.
     */
    private function entry(): Handler
    {
        return $this->entry ??= $this->container->has(ErrorRenderer::class)
            ? new RenderedErrors($this->stack, $this->container)
            : $this->stack;
    }

    /**
     * What the application names for the container to make and the
     * container could not make, each said with where it is named: "Route
     * GET /roms: App\Actions\ImportRom needs App\RomScanner, which has no
     * binding and is not a class that can be constructed." It covers what
     * runs with no one watching: each route's action and the middleware
     * given by name, every listener, and every scheduled task, job and
     * command. A command typed at the console says its own failure to
     * whoever typed it. Nothing is made, so a constructor's work is not done
     * here, and an entry bound to a closure is taken as one that can be
     * made. A job pushed only from code is not named until it is pushed,
     * and is not covered.
     *
     * @return list<string>
     */
    public function unmade(): array
    {
        if (!$this->container instanceof Container) {
            // Another container keeps its own counsel.
            return [];
        }
        // Each entry once, where it is first named.
        $named = [];
        foreach ($this->stack->named() as $middleware) {
            $named[$middleware] ??= 'The middleware around every request';
        }
        foreach ($this->routes->entries() as $route => $entries) {
            foreach ($entries as $entry) {
                $named[$entry] ??= "Route {$route}";
            }
        }
        if ($this->container->has(DispatcherContract::class)) {
            foreach ($this->container->get(DispatcherContract::class)->listeners() as $event => $listeners) {
                foreach ($listeners as $listener) {
                    if ($listener === 'Closure') {
                        continue;
                    }
                    $named[$listener] ??= "A listener for {$event}";
                }
            }
        }
        if ($this->container->has(ScheduleContract::class)) {
            $commands = $this->console instanceof Console ? $this->console->entries() : [];
            foreach ($this->container->get(ScheduleContract::class)->tasks() as $task) {
                if (!$task instanceof Task) {
                    continue;
                }
                $entry = $task->entry() ?? $commands[(string) $task->command()] ?? null;
                if ($entry !== null) {
                    $named[$entry] ??= "The scheduled task {$task->named()}";
                }
            }
        }
        $problems = [];
        foreach ($named as $entry => $where) {
            $problem = $this->container->problem((string) $entry);
            if ($problem !== null) {
                $problems[] = "{$where}: {$problem}";
            }
        }
        return $problems;
    }

    /**
     * A runtime serving this application over HTTP; the `start` command
     * runs it. Each worker assembles its middleware and starts the worker
     * plugins as it starts, so a misconfigured application fails then
     * and not on its first request. A task or timer that fails in a
     * worker is logged.
     *
     * @param resource|null $socket A socket that already listens on the address, inherited from the server this one takes over from.
     * @param bool $servers Whether the registered servers are run beside HTTP; false to serve in one process.
     * @param bool|null $held Which of those servers: null for all of them, as in the foreground; false for those a reload replaces, with HTTP, as the server process in the background runs them; true for those kept across reloads alone, without HTTP, as the keeper's held process runs them.
     */
    public function runtime(
        string $address = 'tcp://127.0.0.1:8080',
        int $workers = 1,
        mixed $socket = null,
        bool $servers = true,
        ?bool $held = null,
    ): Runtime {
        // Before anything starts, so that every setting that does not fit is said at once.
        $this->config->check();
        $drain = $this->drain();
        $maxConnections = (int) $this->config->get('server.max_connections');
        $backend = $this->config->get('server.loop');
        if ($backend !== 'select' && !extension_loaded($backend)) {
            throw new \InvalidArgumentException(
                "The server.loop backend {$backend} needs the {$backend} PHP extension. Install and enable it, or set server.loop to select.",
            );
        }
        if ($this->routes->hasWebSockets() && $this->socketSettings()->maxConnections > $maxConnections) {
            throw new \InvalidArgumentException(
                'websocket.max_connections exceeds server.max_connections. Reduce the socket limit or increase the server limit.',
            );
        }
        if ($backend === 'select' && $maxConnections > 900) {
            throw new \InvalidArgumentException(
                'The select loop supports server.max_connections up to 900, leaving descriptors for files, database and Redis. Reduce the limit or install a native extension and select it with server.loop.',
            );
        }
        // Beside HTTP, every server registered. In one
        // process there is room for HTTP alone.
        $with = $servers ? $this->with() : [];
        if ($held !== null) {
            $with = array_values(array_filter($with, fn(string $id): bool => $this->servers->reloads($id) !== $held));
        }
        if (
            max($workers, (int) $this->config->get('server.workers')) > 1
            && $this->container->has(\Crab\Contracts\Session\Driver::class)
            && $this->config->get('session.driver') === 'memory'
        ) {
            throw new \LogicException(
                'The memory session driver keeps sessions in one worker, and a browser\'s next request may reach another. Set session.driver to file on one machine, or to redis with crabphp/redis, before serving multiple workers.',
            );
        }
        if (max($workers, (int) $this->config->get('server.workers')) > 1) {
            $tickets = $this->stack->has(\Crab\Auth\Ticket::class);
            foreach ($this->routes->routes() as $route) {
                if (
                    !(
                        $route->action === \Crab\Auth\IssueTicket::class
                        || in_array(\Crab\Auth\Ticket::class, $route->middleware, true)
                    )
                ) {
                    continue;
                }

                $tickets = true;
                break;
            }
            if (
                $tickets
                && $this->container->get(\Crab\Contracts\Cache\Store::class) instanceof \Crab\Cache\ArrayStore
            ) {
                throw new \LogicException(
                    'An array cache keeps tickets in one worker only. Set cache.store to file on one machine, or to database or redis with a shared store, before serving multiple workers.',
                );
            }
        }
        if (
            $this->routes->hasWebSockets()
            && $this->container->has(Broadcaster::class)
            && $this->config->get('broadcast.carrier') === 'local'
            && (
                max($workers, (int) $this->config->get('server.workers')) > 1
                || array_intersect($with, ['queue', 'schedule']) !== []
            )
        ) {
            throw new \LogicException(
                'The local broadcast carrier reaches one HTTP process only. Set broadcast.carrier to redis and register RedisPlugin before serving multiple workers or queue/schedule servers.',
            );
        }
        // Every service that cannot be made, at once, after what is said more precisely above.
        $this->checkServices();
        $groups = [];
        foreach ($with as $id) {
            foreach ($this->alongside([$id]) as $group) {
                $groups[] = [$group, $this->servers->reloads($id)];
            }
        }
        // Long enough for the slowest of them to finish what it has in hand.
        $runtime = new Runtime(
            loopFactory: static fn() => $backend === 'select'
                ? new StreamSelectEventLoop()
                : RevoltEventLoop::create($backend),
            shutdownTimeout: max($drain, $this->servers->grace($with)),
        );
        foreach ($groups as [$group, $reloads]) {
            $this->group($runtime, $group, background: $socket !== null && $reloads, reloads: $reloads);
        }
        if ($held === true) {
            // The keeper's held process runs what is kept across reloads, and HTTP is the other one's.
            return $runtime;
        }
        $handler = new EngineHandler($this->entry());
        $runtime
            ->group('http', $workers, maxConnections: $maxConnections, shutdownTimeout: $drain)
            ->listen(
                $address,
                new HttpServer(
                    $handler,
                    maxBodyBytes: (int) $this->config->get('server.max_body'),
                    bodyTimeout: (float) $this->config->get('server.body_timeout'),
                ),
                socket: $socket,
            )
            ->onStart(function (Worker $worker) use ($handler): void {
                $handler->countOn($worker);
                $this->start($worker, serving: true);
            })
            ->onStop(function (Worker $worker): void {
                try {
                    $cut = $worker->stats()['cutConnections'];
                    if ($cut > 0) {
                        $message = 'HTTP worker {pid} reached server.drain; cut {connections} connections.';
                        $context = ['pid' => getmypid(), 'connections' => $cut];
                        try {
                            $this->container->get(LoggerContract::class)->warning($message, $context);
                        } catch (\Throwable $error) {
                            try {
                                $fallback = new Logger(clock: $this->container->get(ClockContract::class));
                                $fallback->warning($message, $context);
                            } finally {
                                FailureReporter::report('HTTP drain warning logger failed', $error);
                            }
                        }
                    }
                } finally {
                    $this->stop($worker);
                }
            })
            ->onFailure($this->report(...));
        return $runtime;
    }

    private function checkServices(): void
    {
        $problems = $this->unmade();
        if ($problems !== []) {
            throw new ServicesException($problems);
        }
    }

    /**
     * Settings are read where socket routes are declared and again at
     * startup; invalid configuration is refused before binding a port.
     */
    private function socketSettings(): SocketSettings
    {
        $this->config->check('websocket');
        return new SocketSettings(
            ping: (float) $this->config->get('websocket.ping'),
            maxMessage: (int) $this->config->get('websocket.max_message'),
            sendTimeout: (float) $this->config->get('websocket.send_timeout'),
            maxConnections: (int) $this->config->get('websocket.max_connections'),
            // The engine spreads drain closes over min(1, closeTimeout).
            // Keep that window inside even a short worker allowance.
            closeTimeout: min(5.0, $this->drain()),
        );
    }

    /**
     * Seconds HTTP workers have to finish requests after they stop
     * accepting. Validate before starting any process or binding a socket.
     */
    public function drain(): float
    {
        $drain = $this->config->get('server.drain');
        if (!is_numeric($drain) || !is_finite((float) $drain) || (float) $drain <= 0) {
            throw new \InvalidArgumentException('Set "server.drain" to a finite number of seconds greater than zero.');
        }
        return (float) $drain;
    }

    /**
     * The ids of the servers `start` runs with HTTP: every one registered.
     * A plugin listed in app.php runs its server, as QueuePlugin runs the
     * queue's workers; one that is not listed registers none.
     *
     * @return list<string>
     */
    public function with(): array
    {
        return $this->servers->ids();
    }

    /**
     * The ids of the servers `start` runs with HTTP that are kept across
     * reloads, registered with `reloads: false`.
     *
     * @return list<string>
     */
    public function held(): array
    {
        return array_values(array_filter($this->with(), fn(string $id): bool => !$this->servers->reloads($id)));
    }

    /**
     * The groups of workers that run some servers beside HTTP. One
     * registered with its own workers joins the server; a command or a
     * program is run in a process a worker starts and looks after.
     *
     * @param list<string> $ids
     * @return list<Workers>
     */
    public function alongside(array $ids): array
    {
        $groups = [];
        foreach ($ids as $id) {
            $kind = $this->servers->kind($id);
            if ($kind === 'groups') {
                array_push($groups, ...$this->servers->groups($id));
                continue;
            }
            $line = $this->servers->line($id);
            if ($kind === 'process') {
                $groups[] = new Workers($id, ProgramWorker::for($id, ['/bin/sh', '-c', $line]));
                continue;
            }
            $words = ProgramWorker::words($line);
            if ($words === [] || in_array($words[0], ['start', 'dev'], true) || !$this->console->has($words[0])) {
                throw new \LogicException(
                    "The server \"{$id}\" runs `{$line}`, and "
                    . (
                        in_array($words[0] ?? '', ['start', 'dev'], true)
                            ? 'the server cannot run itself.'
                            : 'there is no command "' . ($words[0] ?? '') . '". Run `list` to see the commands.'
                    ),
                );
            }
            $groups[] = new Workers($id, ProgramWorker::for($id, [
                ...Interpreter::prefix(),
                ...$this->invocation(),
                ...$words,
            ]));
        }
        return $groups;
    }

    /**
     * How this application's command line was run, less the command and
     * what followed it: the script, and before the command whatever it
     * was given, as in `bin/crab app.php start`. A command run beside
     * the server is run the same way.
     *
     * @return list<string>
     */
    private function invocation(): array
    {
        $invocation = [];
        foreach (array_values((array) ($_SERVER['argv'] ?? [])) as $index => $argument) {
            $argument = (string) $argument;
            if ($index > 0 && $this->console->has($argument)) {
                break;
            }
            $path = realpath($argument);
            $invocation[] = $path !== false && is_file($path) ? $path : $argument;
        }
        return $invocation;
    }

    /**
     * Runs work in worker processes that serve no requests; see
     * Contracts\Runner. Each worker starts and stops as an HTTP worker
     * does, and what fails in it is logged.
     */
    public function run(
        string $group,
        \Closure $work,
        int $workers = 1,
        float $shutdownTimeout = 30.0,
        bool $single = false,
    ): void {
        if ($single && $workers !== 1) {
            throw new \InvalidArgumentException('Work that runs in this one process has one worker.');
        }
        // Before anything starts, so that every setting that does not fit, and every service that
        // cannot be made, is said at once.
        $this->config->check();
        $this->checkServices();
        $runtime = new Runtime(shutdownTimeout: $shutdownTimeout);
        $this->group($runtime, new Workers($group, $work, $workers));
        new Server($this)->keepStats($runtime, ['mode' => $single ? 'single' : 'foreground']);
        $runtime->run(singleProcess: $single);
    }

    public function runAll(array $groups, float $shutdownTimeout = 30.0): void
    {
        if ($groups === []) {
            throw new \InvalidArgumentException('There is no group of workers to run.');
        }
        // Before anything starts, so that every setting that does not fit, and every service that
        // cannot be made, is said at once.
        $this->config->check();
        $this->checkServices();
        $runtime = new Runtime(shutdownTimeout: $shutdownTimeout);
        foreach ($groups as $workers) {
            $this->group($runtime, $workers);
        }
        new Server($this)->keepStats($runtime, ['mode' => 'foreground']);
        $runtime->run();
    }

    /**
     * Adds a group of workers that serve no requests to a runtime.
     */
    private function group(Runtime $runtime, Workers $workers, bool $background = false, bool $reloads = true): void
    {
        $work = $workers->work;
        $group = $runtime->group($workers->group, $workers->workers, reloads: $reloads);
        if ($workers->setup !== null) {
            ($workers->setup)($group);
            if ($background && $group->listeners() !== []) {
                // The keeper hands the HTTP socket alone from one server process to the next. Another
                // listener would be bound afresh by each, and the new one refused while the old holds it.
                throw new \LogicException(
                    "The server \"{$workers->group}\" listens on an address of its own, which a server in the background cannot yet hand over when it reloads. Register it with `reloads: false` to keep it running across reloads, run `start` in the foreground, under systemd or a container, or run this server on its own.",
                );
            }
        }
        $group
            ->onStart(function (Worker $worker) use ($work): void {
                $this->start($worker);
                if ($work === null) {
                    // It serves what its setup had it listen for, as the HTTP workers do.
                    return;
                }
                $worker->spawn(static function (Cancellation $cancellation) use ($worker, $work): void {
                    try {
                        $work($worker, $cancellation);
                    } finally {
                        // Work that has returned leaves nothing to wait for; under a supervisor the worker is started again.
                        $worker->loop->defer(static fn() => $worker->stop());
                    }
                });
            })
            ->onStop($this->stop(...))
            ->onFailure($this->report(...));
    }

    private function start(Worker $worker, bool $serving = false): void
    {
        set_error_handler($this->raise(...));
        try {
            if ($this->container->has(Broadcaster::class)) {
                $this->container->get(BroadcastLifecycle::class)->serving = $serving;
            }
            $this->stack->assemble();
            $this->routes->assemble();
            foreach ($this->workerPlugins as $plugin) {
                $plugin->start($worker, $this->container);
            }
        } catch (\Throwable $error) {
            // stop() will not run for a worker that did not start.
            restore_error_handler();
            throw $error;
        }
    }

    private function stop(Worker $worker): void
    {
        restore_error_handler();
        foreach (array_reverse($this->workerPlugins) as $plugin) {
            try {
                $plugin->stop($worker, $this->container);
            } catch (\Throwable $error) {
                // One plugin's failure must not keep the others open.
                $this->report($error);
            }
        }
    }

    /**
     * PHP's error handler inside a worker: a warning or notice becomes an
     * exception, so it reaches the same error path as any other failure;
     * a deprecation is logged once per place and execution continues.
     */
    private function raise(int $level, string $message, string $file, int $line): bool
    {
        if ((error_reporting() & $level) === 0) {
            // Not reported, or silenced with @.
            return false;
        }
        if ($level === E_DEPRECATED || $level === E_USER_DEPRECATED) {
            if (!isset($this->deprecations[$file . ':' . $line])) {
                $this->deprecations[$file . ':' . $line] = true;
                $this->container->get(LoggerInterface::class)->warning('Deprecated: {message} in {file}:{line}', [
                    'message' => $message,
                    'file' => $file,
                    'line' => $line,
                ]);
            }
            return true;
        }
        throw new \ErrorException($message, 0, $level, $file, $line);
    }

    /**
     * Logs a failure the engine caught in a worker: a task, a timer, a
     * plugin that could not stop, or a request failure that escaped the
     * error handler.
     */
    private function report(\Throwable $error): void
    {
        $this->container->get(LoggerInterface::class)->error('Unhandled failure: {message}', [
            'message' => $error->getMessage(),
            'exception' => $error,
        ]);
    }
}
