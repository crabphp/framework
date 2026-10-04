<?php

declare(strict_types=1);

namespace Crab;

use Crab\Commands\Routes;
use Crab\Commands\Serve;
use Crab\Config\Config;
use Crab\Console\Console;
use Crab\Container\Container;
use Crab\Contracts\Container\Container as ContainerContract;
use Crab\Contracts\Provider;
use Crab\Engine\Runtime;
use Crab\Engine\Server\HttpServer;
use Crab\Http\EngineHandler;
use Crab\Http\ErrorHandler;
use Crab\Http\Factory;
use Crab\Http\Pipeline;
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

    public readonly Router $router;
    public readonly Console $console;
    /**
     * @var list<MiddlewareInterface|string>
     */
    private array $middleware = [];
    private ?RequestHandlerInterface $pipeline = null;

    public function __construct(
        public readonly Config $config = new Config(),
        public readonly ContainerContract $container = new Container(),
    ) {
        self::$current = $this;
        $container->instance(self::class, $this);
        $container->instance(Config::class, $config);
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
        $container->instance(Router::class, $this->router);
        $this->console = (new Console($container))->add('serve', Serve::class)->add('routes', Routes::class);
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
     * by the container.
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
        }
        return $this;
    }

    /**
     * Adds PSR-15 middleware around every route, the first added outermost,
     * inside the error handler. One given by name is made by the container when the first request
     * arrives, in the worker that serves it.
     */
    public function middleware(MiddlewareInterface|string ...$middleware): static
    {
        array_push($this->middleware, ...$middleware);
        $this->pipeline = null;
        return $this;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->pipeline ??= $this->pipeline())->handle($request);
    }

    /**
     * A runtime serving this application over HTTP; the `serve` command
     * runs it.
     */
    public function runtime(string $address = 'tcp://127.0.0.1:8080', int $workers = 1): Runtime
    {
        $runtime = new Runtime();
        $runtime->group('http', $workers)->listen($address, new HttpServer(new EngineHandler($this)));
        return $runtime;
    }

    /**
     * The error handler is the entry bound as ErrorHandler; rebinding it to
     * another PSR-15 middleware replaces how failures are answered.
     */
    private function pipeline(): RequestHandlerInterface
    {
        $middleware = [];
        foreach ([ErrorHandler::class, ...$this->middleware] as $layer) {
            if (is_string($layer)) {
                $name = $layer;
                $layer = $this->container->get($name);
                if (!$layer instanceof MiddlewareInterface) {
                    throw new \LogicException("{$name} must implement MiddlewareInterface.");
                }
            }
            $middleware[] = $layer;
        }
        return new Pipeline($this->router, ...$middleware);
    }
}
