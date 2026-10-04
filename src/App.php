<?php

declare(strict_types=1);

namespace Crab;

use Crab\Commands\Routes;
use Crab\Commands\Serve;
use Crab\Config\Config;
use Crab\Console\Console;
use Crab\Container\Container;
use Crab\Contracts\Config\Config as ConfigContract;
use Crab\Contracts\Console\Console as ConsoleContract;
use Crab\Contracts\Container\Container as ContainerContract;
use Crab\Contracts\Http\MiddlewareStack as MiddlewareStackContract;
use Crab\Contracts\Provider;
use Crab\Contracts\Routing\Router as RouterContract;
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
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * An application: configuration, a container, routes, the middleware in
 * front of them, console commands and the engine runtime that serves them.
 *
 * It reads three configuration keys: "app.debug" shows failure details to
 * clients, "log.path" is where the default logger writes (standard error
 * when unset) and "log.level" is the least severe level it writes.
 */
final class App implements RequestHandlerInterface
{
    private static ?self $current = null;

    public readonly RouterContract $router;
    public readonly ConsoleContract $console;
    private readonly MiddlewareStack $stack;
    /**
     * @var list<WorkerProvider>
     */
    private array $workerProviders = [];

    public function __construct(
        public readonly ConfigContract $config = new Config(),
        public readonly ContainerContract $container = new Container(),
    ) {
        self::$current = $this;
        $container->instance(self::class, $this);
        $container->instance(ConfigContract::class, $config);
        // Asking by class must not autowire a second, empty one.
        $container->instance($config::class, $config);
        $container->singleton(LoggerInterface::class, static fn(): Logger => new Logger(
            $config->get('log.path', 'php://stderr'),
            $config->get('log.level', 'debug'),
        ));
        $container->singleton(ErrorHandler::class, static fn(ContainerContract $container): ErrorHandler => new ErrorHandler(
            $container->get(LoggerInterface::class),
            (bool) $config->get('app.debug', false),
        ));
        $container->singleton(Factory::class);
        foreach ([ResponseFactoryInterface::class, ServerRequestFactoryInterface::class,
            StreamFactoryInterface::class, UriFactoryInterface::class] as $factory) {
            $container->singleton($factory, Factory::class);
        }
        $this->router = new Router($container->get(ResponseFactoryInterface::class), $container);
        $container->instance(RouterContract::class, $this->router);
        $container->instance(Router::class, $this->router);
        // The error handler is the entry bound as ErrorHandler; rebinding it to
        // another PSR-15 middleware replaces how failures are answered.
        $this->stack = (new MiddlewareStack($this->router, $container))->add(ErrorHandler::class);
        $container->instance(MiddlewareStackContract::class, $this->stack);
        $container->instance(MiddlewareStack::class, $this->stack);
        $this->console = (new Console($container))->add('serve', Serve::class)->add('routes', Routes::class);
        $container->instance(ConsoleContract::class, $this->console);
        $container->instance(Console::class, $this->console);
    }

    /**
     * The application most recently created in this process; the global
     * helpers read from it.
     */
    public static function current(): self
    {
        return self::$current ?? throw new \LogicException('No application has been created.');
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
     */
    public function runtime(string $address = 'tcp://127.0.0.1:8080', int $workers = 1): Runtime
    {
        $runtime = new Runtime();
        $runtime->group('http', $workers)
            ->listen($address, new HttpServer(new EngineHandler($this->stack)))
            ->onStart($this->start(...))
            ->onStop($this->stop(...))
            ->onFailure($this->report(...));
        return $runtime;
    }

    private function start(Worker $worker): void
    {
        try {
            $this->stack->assemble();
            foreach ($this->workerProviders as $provider) {
                $provider->start($worker, $this->container);
            }
        } catch (\Throwable $error) {
            // The supervisor reports only that a worker failed to start.
            fwrite(STDERR, "Worker {$worker->index} cannot start: {$error->getMessage()}\n");
            throw $error;
        }
    }

    private function stop(Worker $worker): void
    {
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
     * Logs a failure that happened outside a request.
     */
    private function report(\Throwable $error): void
    {
        $this->container->get(LoggerInterface::class)->error('Background work failed: {message}', [
            'message' => $error->getMessage(),
            'exception' => $error,
        ]);
    }
}
