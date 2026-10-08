<?php

declare(strict_types=1);

namespace Crab;

use Crab\Clock\SystemClock;
use Crab\Commands\MakeMiddleware;
use Crab\Commands\OpenApi;
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
use Crab\Contracts\Config\Config as ConfigContract;
use Crab\Contracts\Console\Console as ConsoleContract;
use Crab\Contracts\Container\Container as ContainerContract;
use Crab\Contracts\Http\Factory as FactoryContract;
use Crab\Contracts\Http\MiddlewareStack as MiddlewareStackContract;
use Crab\Contracts\Log\Logger as LoggerContract;
use Crab\Contracts\Provider;
use Crab\Contracts\Routing\Router as RouterContract;
use Crab\Contracts\Validation\Validator as ValidatorContract;
use Crab\Contracts\WorkerProvider;
use Crab\Engine\Process\Worker;
use Crab\Engine\Runtime;
use Crab\Engine\Server\HttpServer;
use Crab\Http\EngineHandler;
use Crab\Http\ErrorHandler;
use Crab\Http\Factory;
use Crab\Http\MiddlewareStack;
use Crab\Log\Logger;
use Crab\Routing\Router;
use Crab\Validation\Validator;
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
 * It reads four configuration keys: "app.name" heads the command list,
 * "app.debug" shows failure details to clients, "log.path" is where the
 * default logger writes (standard error when unset) and "log.level" is
 * the least severe level it writes.
 */
final class App implements RequestHandlerInterface
{
    private static ?self $current = null;

    public readonly RouterContract $router;
    public readonly ConsoleContract $console;
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
     * @param string|null $basePath The application's own directory, which path() resolves against.
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware The middleware around every request, the first outermost; middleware() adds inside it.
     */
    public function __construct(
        public readonly ConfigContract $config = new Config(),
        public readonly ContainerContract $container = new Container(),
        public readonly ?string $basePath = null,
        array $middleware = self::MIDDLEWARE,
    ) {
        self::$current = $this;
        $container->instance(self::class, $this);
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
        $router = new Router($container, $validator);
        $this->router = $router;
        $this->routes = $router;
        $container->instance(RouterContract::class, $this->router);
        $container->instance(Router::class, $this->router);
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
        $this->console->add('routes', Routes::class);
        $this->console->add('openapi', OpenApi::class);
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

    /**
     * Adds PSR-15 middleware around every route, the first added outermost,
     * inside the error handler. One given by name is made by the container
     * in the worker that serves requests, when that worker starts.
     */
    public function middleware(MiddlewareInterface|string ...$middleware): static
    {
        $this->stack->add(...$middleware);
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
     */
    public function runtime(string $address = 'tcp://127.0.0.1:8080', int $workers = 1, mixed $socket = null): Runtime
    {
        $runtime = new Runtime();
        $runtime->group('http', $workers)
            ->listen($address, new HttpServer(new EngineHandler($this->stack)), socket: $socket)
            ->onStart($this->start(...))
            ->onStop($this->stop(...))
            ->onFailure($this->report(...));
        return $runtime;
    }

    private function start(Worker $worker): void
    {
        set_error_handler($this->raise(...));
        try {
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
