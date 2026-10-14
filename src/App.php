<?php

declare(strict_types=1);

namespace Crab;

use Crab\Auth\AuthProvider;
use Crab\Broadcast\BroadcastProvider;
use Crab\Broadcast\Lifecycle as BroadcastLifecycle;
use Crab\Cache\CacheProvider;
use Crab\Clock\SystemClock;
use Crab\Commands\MakeMiddleware;
use Crab\Commands\Reload;
use Crab\Commands\Restart;
use Crab\Commands\Routes;
use Crab\Commands\Serve;
use Crab\Commands\Status;
use Crab\Commands\Stop;
use Crab\Config\Config;
use Crab\Console\Console;
use Crab\Container\Container;
use Crab\Contracts\Clock\Clock as ClockContract;
use Crab\Contracts\Broadcast\Broadcaster;
use Crab\Contracts\WebSocket\Channels as SocketChannels;
use Crab\Contracts\Config\Config as ConfigContract;
use Crab\Contracts\Console\Console as ConsoleContract;
use Crab\Contracts\Container\Container as ContainerContract;
use Crab\Contracts\Http\Factory as FactoryContract;
use Crab\Contracts\Http\MiddlewareStack as MiddlewareStackContract;
use Crab\Contracts\Log\Logger as LoggerContract;
use Crab\Contracts\Provider;
use Crab\Contracts\Routing\Router as RouterContract;
use Crab\Contracts\Routing\Urls as UrlsContract;
use Crab\Contracts\Runner;
use Crab\Contracts\Servers as ServersContract;
use Crab\Contracts\Validation\Validator as ValidatorContract;
use Crab\Contracts\WorkerProvider;
use Crab\Contracts\Workers;
use Crab\Database\DatabaseProvider;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\FailureReporter;
use Crab\Engine\Process\Worker;
use Crab\Engine\Runtime;
use Crab\Engine\EventLoop\RevoltEventLoop;
use Crab\Engine\Server\HttpServer;
use Crab\Events\EventsProvider;
use Crab\Http\EngineHandler;
use Crab\Http\ErrorHandler;
use Crab\Http\Factory;
use Crab\Http\MiddlewareStack;
use Crab\HttpClient\HttpClientProvider;
use Crab\Log\Logger;
use Crab\Queue\QueueProvider;
use Crab\Routing\Router;
use Crab\Routing\Urls;
use Crab\Schedule\ScheduleProvider;
use Crab\Validation\Validator;
use Crab\WebSocket\Connections;
use Crab\WebSocket\Settings as SocketSettings;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
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
final class App implements RequestHandlerInterface, Runner
{
    private static ?self $current = null;

    public readonly RouterContract $router;
    public readonly ConsoleContract $console;
    /**
     * The servers `serve` can run beside HTTP, as providers have registered them.
     */
    public readonly Servers $servers;
    private readonly MiddlewareStack $stack;
    /**
     * Places a deprecation has already been logged from, in this worker.
     *
     * @var array<string, true>
     */
    private array $deprecations = [];
    /**
     * @var list<WorkerProvider>
     */
    private array $workerProviders = [];
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
     * The framework's own providers, which an application made with
     * App::configure() starts with, in the order they depend on one
     * another: events before the queue and the schedule, which report
     * through them. Each binds on first use, so one the application never
     * touches opens nothing. An application's own and a package's come
     * after them, and AppBuilder::withProviders() can leave one out.
     */
    public const PROVIDERS = [
        DatabaseProvider::class,
        AuthProvider::class,
        CacheProvider::class,
        EventsProvider::class,
        QueueProvider::class,
        ScheduleProvider::class,
        HttpClientProvider::class,
        BroadcastProvider::class,
    ];

    /**
     * Seconds a program run beside the server is given to finish when the server stops.
     */
    private const PROCESS_GRACE = 30.0;

    /**
     * @param string|null $basePath The application's own directory, which path() resolves against.
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware The middleware around every request, the first outermost. A provider adds inside it through the MiddlewareStack contract.
     */
    public function __construct(
        public readonly ConfigContract $config = new Config(),
        public readonly ContainerContract $container = new Container(),
        public readonly ?string $basePath = null,
        array $middleware = self::MIDDLEWARE,
    ) {
        $config->defaults('server', ['drain' => 5.0, 'max_connections' => 512, 'loop' => 'select']);
        $config->defaults('websocket', SocketSettings::DEFAULTS);
        self::$current = $this;
        $container->instance(self::class, $this);
        // What runs workers that serve no requests, for a provider's commands.
        $container->instance(Runner::class, $this);
        $this->servers = new Servers();
        $container->instance(ServersContract::class, $this->servers);
        $container->instance(Servers::class, $this->servers);
        $container->instance(ConfigContract::class, $config);
        // Asking by class must not autowire a second, empty one.
        $container->instance($config::class, $config);
        $container->singleton(LoggerContract::class, fn(): Logger => new Logger(
            // A relative path is inside the application's directory, not wherever the process was started.
            $basePath === null ? $config->get('log.path', 'php://stderr') : $this->path((string) $config->get('log.path', 'php://stderr')),
            $config->get('log.level', 'debug'),
        ));
        // Each service is also reachable by its PSR name and by class, as the same object.
        $container->singleton(LoggerInterface::class, LoggerContract::class);
        $container->singleton(Logger::class, LoggerContract::class);
        $container->singleton(ClockContract::class, static fn(): SystemClock => new SystemClock());
        $container->singleton(ClockInterface::class, ClockContract::class);
        $container->singleton(SystemClock::class, ClockContract::class);
        $container->singleton(ErrorHandler::class, static fn(ContainerContract $container): ErrorHandler => new ErrorHandler(
            $container->get(LoggerInterface::class),
            (bool) $config->get('app.debug', false),
        ));
        $container->singleton(FactoryContract::class, static fn(): Factory => new Factory());
        $container->singleton(Factory::class, FactoryContract::class);
        foreach ([ResponseFactoryInterface::class, ServerRequestFactoryInterface::class,
            StreamFactoryInterface::class, UploadedFileFactoryInterface::class, UriFactoryInterface::class] as $factory) {
            $container->singleton($factory, FactoryContract::class);
        }
        // Made here because routes are declared, and their inputs read, before any worker starts.
        $validator = new Validator();
        $container->instance(ValidatorContract::class, $validator);
        $container->instance(Validator::class, $validator);
        $router = new Router($container, $validator, fn (): Connections => new Connections(
            $this->socketSettings(),
            static function () use ($container): SocketChannels {
                if (!$container->has(SocketChannels::class)) {
                    throw new \LogicException('To join WebSocket channels, register BroadcastProvider with the application.');
                }
                // A socket-pair application is serving in this process too.
                // Keep this work on first channel use, off the HTTP path.
                if ($container->has(Broadcaster::class)) {
                    $container->get(BroadcastLifecycle::class)->serving = true;
                }
                return $container->get(SocketChannels::class);
            },
        ));
        $this->router = $router;
        $this->routes = $router;
        $container->instance(RouterContract::class, $this->router);
        $container->instance(Router::class, $this->router);
        $container->singleton(UrlsContract::class, fn (): Urls => new Urls($this->router, $config->get('app.url')));
        $container->singleton(Urls::class, UrlsContract::class);
        // The error handler is the entry bound as ErrorHandler; rebinding it to
        // another PSR-15 middleware replaces how failures are answered.
        $this->stack = new MiddlewareStack($this->router, $container);
        $this->stack->add(...$middleware);
        $container->instance(MiddlewareStackContract::class, $this->stack);
        $container->instance(MiddlewareStack::class, $this->stack);
        $this->console = new Console($container, (string) $config->get('app.name', ''));
        $this->console->add('serve', Serve::class);
        $this->console->add('stop', Stop::class);
        $this->console->add('reload', Reload::class);
        $this->console->add('restart', Restart::class);
        $this->console->add('status', Status::class);
        $this->console->add('route:list', Routes::class);
        $this->console->add('make:middleware', MakeMiddleware::class);
        $container->instance(ConsoleContract::class, $this->console);
        $container->instance(Console::class, $this->console);
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
        return $builder
            ->withEnv('.env')
            ->withConfig('config', required: false);
    }

    /**
     * A path inside the application's directory: path('storage/app.log').
     * A path that is already absolute is returned as it is.
     */
    public function path(string $path = ''): string
    {
        if ($path !== '' && ($path[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]|^[a-z][a-z0-9+.-]*://~i', $path) === 1)) {
            return $path;
        }
        if ($this->basePath === null) {
            throw new \LogicException('This application has no base path. Give it one with App::configure(__DIR__).');
        }
        return $path === '' ? $this->basePath : $this->basePath . '/' . $path;
    }

    /**
     * The application most recently created in this process; the global
     * helpers read from it, and Route and Command declare on it.
     */
    public static function current(): self
    {
        return self::$current ?? throw new \LogicException('No application has been created. Create the App before declaring routes or commands, or reading config().');
    }

    /**
     * Lets providers add their bindings; one given by class name is made
     * by the container. A WorkerProvider is also started and stopped in
     * each worker.
     */
    public function register(Provider|string ...$providers): static
    {
        foreach ($providers as $provider) {
            if (is_string($provider)) {
                $name = $provider;
                $provider = $this->container->get($name);
                if (!$provider instanceof Provider) {
                    throw new \LogicException("{$name} must implement " . Provider::class . '.');
                }
            }
            $provider->register($this->container);
            if ($provider instanceof WorkerProvider) {
                $this->workerProviders[] = $provider;
            }
        }
        return $this;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->stack->handle($request);
    }

    /**
     * A runtime serving this application over HTTP; the `serve` command
     * runs it. Each worker assembles its middleware and starts the worker
     * providers as it starts, so a misconfigured application fails then
     * and not on its first request. A task or timer that fails in a
     * worker is logged.
     *
     * @param resource|null $socket A socket that already listens on the address, inherited from the server this one takes over from.
     * @param bool $servers Whether the servers "server.with" names are run beside HTTP; false to serve in one process.
     */
    public function runtime(string $address = 'tcp://127.0.0.1:8080', int $workers = 1, mixed $socket = null, bool $servers = true): Runtime
    {
        $drain = $this->drain();
        $maxConnections = $this->positiveInteger('server.max_connections');
        $backend = $this->config->get('server.loop');
        if (!in_array($backend, ['select', 'ev', 'uv', 'event'], true)) {
            throw new \InvalidArgumentException('Set "server.loop" to "select", "ev", "uv" or "event".');
        }
        if ($backend !== 'select' && !extension_loaded($backend)) {
            throw new \InvalidArgumentException("The server.loop backend {$backend} needs the {$backend} PHP extension. Install and enable it, or set server.loop to select.");
        }
        if ($this->routes->hasWebSockets() && $this->socketSettings()->maxConnections > $maxConnections) {
            throw new \InvalidArgumentException('websocket.max_connections exceeds server.max_connections. Reduce the socket limit or increase the server limit.');
        }
        if ($backend === 'select' && $maxConnections > 900) {
            throw new \InvalidArgumentException('The select loop supports server.max_connections up to 900, leaving descriptors for files, database and Redis. Reduce the limit or install a native extension and select it with server.loop.');
        }
        // Beside HTTP, the servers "server.with" switches on: none unless it says so. In one
        // process there is room for HTTP alone.
        $with = $servers ? $this->with() : [];
        if (max($workers, (int) $this->config->get('server.workers', 1)) > 1) {
            $tickets = $this->stack->has(\Crab\Auth\Ticket::class);
            foreach ($this->routes->routes() as $route) {
                if ($route->action === \Crab\Auth\IssueTicket::class || in_array(\Crab\Auth\Ticket::class, $route->middleware, true)) {
                    $tickets = true;
                    break;
                }
            }
            if ($tickets && $this->container->get(\Crab\Contracts\Cache\Store::class) instanceof \Crab\Cache\ArrayStore) {
                throw new \LogicException('An array cache keeps tickets in one worker only. Set cache.store to file on one machine, or to database or redis with a shared store, before serving multiple workers.');
            }
        }
        if ($this->routes->hasWebSockets() && $this->container->has(Broadcaster::class)
            && $this->config->get('broadcast.carrier') === 'local'
            && (max($workers, (int) $this->config->get('server.workers', 1)) > 1
                || array_intersect($with, ['queue', 'schedule']) !== [])) {
            throw new \LogicException('The local broadcast carrier reaches one HTTP process only. Set broadcast.carrier to redis and register RedisProvider before serving multiple workers or queue/schedule servers.');
        }
        $groups = $this->alongside($with);
        // Long enough for the slowest of them to finish what it has in hand.
        $runtime = new Runtime(loopFactory: static fn () => RevoltEventLoop::create($backend), shutdownTimeout: max($drain, $this->servers->grace($with)));
        foreach ($groups as $group) {
            $this->group($runtime, $group, background: $socket !== null);
        }
        $runtime->group('http', $workers, maxConnections: $maxConnections, shutdownTimeout: $drain)
            ->listen($address, new HttpServer(
                new EngineHandler($this->stack),
                maxBodyBytes: (int) $this->config->get('server.max_body', 1048576),
                bodyTimeout: (float) $this->config->get('server.body_timeout', 30),
            ), socket: $socket)
            ->onStart(fn (Worker $worker) => $this->start($worker, serving: true))
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
                                $fallback = new Logger();
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

    /**
     * Settings are read where socket routes are declared and again at
     * startup; invalid configuration is refused before binding a port.
     */
    private function socketSettings(): SocketSettings
    {
        $ping = $this->config->get('websocket.ping');
        $timeout = $this->config->get('websocket.send_timeout');
        if (!is_numeric($ping) || !is_numeric($timeout)) {
            throw new \InvalidArgumentException('Set websocket.ping and websocket.send_timeout to numbers of seconds.');
        }
        return new SocketSettings(
            ping: (float) $ping,
            maxMessage: $this->positiveInteger('websocket.max_message'),
            sendTimeout: (float) $timeout,
            maxConnections: $this->positiveInteger('websocket.max_connections'),
            // The engine spreads drain closes over min(1, closeTimeout).
            // Keep that window inside even a short worker allowance.
            closeTimeout: min(5.0, $this->drain()),
        );
    }

    /**
     * Environment settings may be strings, but fractional or malformed
     * connection and byte limits must not quietly become another number.
     */
    private function positiveInteger(string $key): int
    {
        $value = filter_var($this->config->get($key), FILTER_VALIDATE_INT);
        if ($value === false || $value < 1) {
            throw new \InvalidArgumentException("Set {$key} to a positive whole number.");
        }
        return $value;
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
     * The ids of the servers `serve` runs with HTTP, as "server.with"
     * says: each id with whether it is on, usually from the environment,
     *
     *     'with' => ['queue' => env('SERVER_QUEUE', false), 'schedule' => env('SERVER_SCHEDULE', false)],
     *
     * or a plain list of ids, or ids with commas between.
     *
     * @return list<string>
     */
    public function with(): array
    {
        $setting = $this->config->get('server.with', []);
        if ($setting === null || $setting === false || $setting === '') {
            return [];
        }
        $ids = [];
        foreach (is_array($setting) ? $setting : explode(',', (string) $setting) as $key => $value) {
            if (is_int($key)) {
                // In a list, a server named is a server on.
                $ids[] = trim((string) $value);
            } elseif ((is_array($value) ? ($value['enabled'] ?? false) : $value) === true) {
                $ids[] = $key;
            }
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
        foreach ($ids as $id) {
            if (!$this->servers->has($id)) {
                $known = $this->servers->ids() === [] ? 'none is registered' : 'the servers registered are ' . implode(', ', $this->servers->ids());
                throw new \LogicException("\"server.with\" switches on \"{$id}\", and {$known}.");
            }
        }
        return $ids;
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
                $groups[] = new Workers($id, self::process($id, ['/bin/sh', '-c', $line]));
                continue;
            }
            $words = self::words($line);
            if ($words === [] || $words[0] === 'serve' || !$this->console->has($words[0])) {
                throw new \LogicException("The server \"{$id}\" runs `{$line}`, and " . (($words[0] ?? '') === 'serve' ? 'the server cannot run itself.' : 'there is no command "' . ($words[0] ?? '') . '". Run `list` to see the commands.'));
            }
            $groups[] = new Workers($id, self::process($id, [PHP_BINARY, ...$this->invocation(), ...$words]));
        }
        return $groups;
    }

    /**
     * How this application's command line was run, less the command and
     * what followed it: the script, and before the command whatever it
     * was given, as in `bin/crab app.php serve`. A command run beside
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
     * A command line as its words, as a shell would split it: quotes
     * keep what is inside them together, and a backslash the character
     * after it.
     *
     * @return list<string>
     */
    private static function words(string $line): array
    {
        $words = [];
        $word = null;
        $quote = null;
        $length = strlen($line);
        for ($i = 0; $i < $length; ++$i) {
            $character = $line[$i];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                } elseif ($character === '\\' && $quote === '"' && $i + 1 < $length) {
                    $word .= $line[++$i];
                } else {
                    $word .= $character;
                }
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
                $word ??= '';
            } elseif ($character === '\\' && $i + 1 < $length) {
                $word = ($word ?? '') . $line[++$i];
            } elseif (ctype_space($character)) {
                if ($word !== null) {
                    $words[] = $word;
                    $word = null;
                }
            } else {
                $word = ($word ?? '') . $character;
            }
        }
        if ($word !== null) {
            $words[] = $word;
        }
        return $words;
    }

    /**
     * Work that keeps a program running in a process of its own: started
     * with the worker, told to stop (SIGTERM) when the worker stops, and
     * given time to finish. What it prints is passed on line by line
     * with its id in front, so that several can be told apart. The
     * worker ends when the program does, and is then started again, as
     * any worker is.
     *
     * Under an isolated daemon keeper the program stays in the server's
     * group, so force reaches it even when its worker cannot clean up.
     * Foreground programs retain their own group for ordinary cleanup.
     *
     * @param list<string> $command The program and its arguments.
     * @return \Closure(Worker, Cancellation): void
     */
    private static function process(string $id, array $command): \Closure
    {
        $keeper = function_exists('posix_getppid') ? posix_getppid() : 0;
        $sharedGroup = in_array('--generation', $_SERVER['argv'] ?? [], true)
            && $keeper > 1 && posix_getsid($keeper) === $keeper && posix_getpgid(0) === $keeper;
        return static function (Worker $worker, Cancellation $cancellation) use ($id, $command, $sharedGroup): void {
            $detach = $sharedGroup ? '' : 'posix_setsid(); ';
            $launcher = [PHP_BINARY, '-r', $detach . 'pcntl_exec($argv[1], array_slice($argv, 2)); fwrite(STDERR, "could not run {$argv[1]}\n"); exit(127);', '--', ...$command];
            $process = proc_open($launcher, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            if ($process === false) {
                throw new \RuntimeException("Could not start the server \"{$id}\".");
            }
            $group = (int) proc_get_status($process)['pid'];
            // Each line it prints, as it comes, under its id.
            $watchers = [];
            $pending = ['', ''];
            $pass = static function (int $which, bool $all = false) use ($id, $pipes, $worker, &$pending, &$watchers): void {
                $pending[$which] .= (string) stream_get_contents($pipes[$which + 1]);
                while (($break = strpos($pending[$which], "\n")) !== false || ($all && $pending[$which] !== '')) {
                    $line = $break === false ? $pending[$which] : substr($pending[$which], 0, $break);
                    $pending[$which] = $break === false ? '' : substr($pending[$which], $break + 1);
                    fwrite($which === 0 ? STDOUT : STDERR, "[{$id}] " . rtrim($line, "\r") . "\n");
                }
                // Closed at the other end: it stays "readable" for ever, so it is no longer watched.
                if (feof($pipes[$which + 1]) && isset($watchers[$which])) {
                    $worker->loop->cancel($watchers[$which]);
                    unset($watchers[$which]);
                }
            };
            foreach ([0, 1] as $which) {
                stream_set_blocking($pipes[$which + 1], false);
                $watchers[$which] = $worker->loop->onReadable($pipes[$which + 1], static function () use ($pass, $which): void {
                    $pass($which);
                });
            }
            $signal = static function (int $number) use ($group, $sharedGroup): void {
                // An isolated foreground program owns a group; a daemon
                // program shares the keeper's and must be signalled by pid.
                if ($sharedGroup || $group <= 1 || !function_exists('posix_kill') || !@posix_kill(-$group, $number)) {
                    @posix_kill($group, $number);
                }
            };
            $started = microtime(true);
            try {
                while (proc_get_status($process)['running']) {
                    Async::sleep($worker->loop, 0.2, $cancellation);
                }
                // It ended by itself. Not at once again, if it cannot stay up.
                if (microtime(true) - $started < 2.0) {
                    Async::sleep($worker->loop, 2.0, $cancellation);
                }
            } catch (CancelledException) {
                // The loop is stopping, so this waits the plain way, still passing on what the
                // program says: one that cannot finish writing cannot finish.
                // A shell does not forward TERM. Signal its current tree,
                // children before parents, then the shell itself.
                foreach (self::descendants($group) as $pid) {
                    @posix_kill($pid, SIGTERM);
                }
                @posix_kill($group, SIGTERM);
                $until = microtime(true) + self::PROCESS_GRACE;
                // A compound shell can finish before its children's TERM cleanup.
                // Their inherited output stays open until that cleanup finishes.
                while ((proc_get_status($process)['running'] || !feof($pipes[1]) || !feof($pipes[2])) && microtime(true) < $until) {
                    $pass(0);
                    $pass(1);
                    usleep(20_000);
                }
            } finally {
                // Whatever of the group is left, the program or what it started.
                $signal(9);
                foreach ($watchers as $watcher) {
                    $worker->loop->cancel($watcher);
                }
                foreach ([0, 1] as $which) {
                    $pass($which, true);
                    fclose($pipes[$which + 1]);
                }
                proc_close($process);
            }
        };
    }

    /**
     * Descendants sampled at shutdown, deepest first so their parents
     * cannot exit before they receive TERM. Nothing is kept for later.
     *
     * @return list<int>
     */
    private static function descendants(int $pid): array
    {
        $children = [];
        if (PHP_OS_FAMILY === 'Linux') {
            foreach (glob('/proc/[0-9]*/stat') ?: [] as $file) {
                $stat = @file_get_contents($file);
                $end = $stat === false ? false : strrpos($stat, ')');
                if ($end === false) {
                    continue;
                }
                // The command name may itself contain spaces or parentheses.
                $fields = explode(' ', trim(substr($stat, $end + 2)));
                if (isset($fields[1])) {
                    $children[(int) $fields[1]][] = (int) basename(dirname($file));
                }
            }
        } elseif (function_exists('exec')) {
            @exec('ps -axo pid=,ppid= 2>/dev/null', $lines, $status);
            if ($status === 0) {
                foreach ($lines as $line) {
                    $fields = preg_split('/\s+/', trim($line));
                    if (count($fields) === 2) {
                        $children[(int) $fields[1]][] = (int) $fields[0];
                    }
                }
            }
        }
        $descendants = $children[$pid] ?? [];
        // Walking breadth first and reversing puts every child before its parent.
        for ($index = 0; $index < count($descendants); ++$index) {
            array_push($descendants, ...($children[$descendants[$index]] ?? []));
        }
        return array_reverse($descendants);
    }

    /**
     * Runs work in worker processes that serve no requests; see
     * Contracts\Runner. Each worker starts and stops as an HTTP worker
     * does, and what fails in it is logged.
     */
    public function run(string $group, \Closure $work, int $workers = 1, float $shutdownTimeout = 30.0, bool $single = false): void
    {
        if ($single && $workers !== 1) {
            throw new \InvalidArgumentException('Work that runs in this one process has one worker.');
        }
        $runtime = new Runtime(shutdownTimeout: $shutdownTimeout);
        $this->group($runtime, new Workers($group, $work, $workers));
        $runtime->run(singleProcess: $single);
    }

    public function runAll(array $groups, float $shutdownTimeout = 30.0): void
    {
        if ($groups === []) {
            throw new \InvalidArgumentException('There is no group of workers to run.');
        }
        $runtime = new Runtime(shutdownTimeout: $shutdownTimeout);
        foreach ($groups as $workers) {
            $this->group($runtime, $workers);
        }
        $runtime->run();
    }

    /**
     * Adds a group of workers that serve no requests to a runtime.
     */
    private function group(Runtime $runtime, Workers $workers, bool $background = false): void
    {
        $work = $workers->work;
        $group = $runtime->group($workers->group, $workers->workers);
        if ($workers->setup !== null) {
            ($workers->setup)($group);
            if ($background && ($group->listeners() !== [] || $group->datagrams() !== [])) {
                // The keeper hands the HTTP socket alone from one server process to the next. Another
                // listener would be bound afresh by each, and the new one refused while the old holds it.
                throw new \LogicException("The server \"{$workers->group}\" listens on an address of its own, which a server in the background cannot yet hand over when it reloads. Run `serve` in the foreground, under systemd or a container, or run this server on its own.");
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
                        $worker->loop->defer(static fn () => $worker->stop());
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
            foreach ($this->workerProviders as $provider) {
                $provider->start($worker, $this->container);
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
        foreach (array_reverse($this->workerProviders) as $provider) {
            try {
                $provider->stop($worker, $this->container);
            } catch (\Throwable $error) {
                // One provider's failure must not keep the others open.
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
                    'message' => $message, 'file' => $file, 'line' => $line,
                ]);
            }
            return true;
        }
        throw new \ErrorException($message, 0, $level, $file, $line);
    }

    /**
     * Logs a failure the engine caught in a worker: a task, a timer, a
     * provider that could not stop, or a request failure that escaped the
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
