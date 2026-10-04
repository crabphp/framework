<?php

declare(strict_types=1);

namespace Crab\Tests;

use Crab\App;
use Crab\Config\Config;
use Crab\Contracts\Config\Config as ConfigContract;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Http\MiddlewareStack;
use Crab\Contracts\Http\Request as RequestContract;
use Crab\Contracts\Provider;
use Crab\Contracts\Routing\Router as RouterContract;
use Crab\Contracts\WorkerProvider;
use Crab\Engine\Process\Worker;
use Crab\Engine\Http\Request as EngineRequest;
use Crab\Engine\Runtime;
use Crab\Http\EngineHandler;
use Crab\Http\ErrorHandler;
use Crab\Http\Factory;
use Crab\Http\Pipeline;
use Crab\Http\Request;
use Crab\Http\Response;
use Crab\Routing\Router;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final class Greeter
{
    public function __construct(public string $greeting)
    {
    }
}

final class GreeterProvider implements Provider
{
    public function register(Container $container): void
    {
        $container->singleton(Greeter::class, static fn() => new Greeter('Hello'));
    }
}

final class GreetHandler implements RequestHandlerInterface
{
    public function __construct(private Greeter $greeter, private ResponseFactoryInterface $responses)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->responses->createResponse();
        $response->getBody()->write($this->greeter->greeting . ', ' . $request->getAttribute('name'));
        return $response;
    }
}

final class TagMiddleware implements MiddlewareInterface
{
    public function __construct(private string $tag = 'named')
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $trail = $request->getAttribute('trail', '') . $this->tag . '>';
        $response = $handler->handle($request->withAttribute('trail', $trail));
        return $response->withAddedHeader('X-Trail', $this->tag);
    }
}

final class RecordingProvider implements WorkerProvider
{
    /**
     * @var list<string>
     */
    public static array $events = [];

    public function __construct(private string $name, private bool $failsToStop = false)
    {
    }

    public function register(Container $container): void
    {
        self::$events[] = "{$this->name} register";
    }

    public function start(Worker $worker, Container $container): void
    {
        self::$events[] = "{$this->name} start in worker {$worker->index}";
    }

    public function stop(Worker $worker, Container $container): void
    {
        self::$events[] = "{$this->name} stop";
        if ($this->failsToStop) {
            throw new \RuntimeException("{$this->name} cannot stop");
        }
    }
}

final class AppTest extends TestCase
{
    public function testApplicationHandlesRequestsAndBuildsARuntime(): void
    {
        $app = new App();
        $app->router->get('/', static fn() => "Hello, world!\n");
        self::assertSame("Hello, world!\n", (string) $app->handle(new Request('GET', '/'))->getBody());
        self::assertInstanceOf(Runtime::class, $app->runtime('tcp://127.0.0.1:0'));
        self::assertSame($app, $app->container->get(App::class));
        self::assertSame($app->router, $app->container->get(RouterContract::class));
        self::assertSame($app->router, $app->container->get(Router::class));
        self::assertInstanceOf(Factory::class, $app->container->get(ResponseFactoryInterface::class));
    }

    public function testProvidersAndNamedHandlers(): void
    {
        $app = (new App())->register(GreeterProvider::class);
        $app->router->get('/greet/{name}', GreetHandler::class);
        self::assertSame('Hello, Crab', (string) $app->handle(new Request('GET', '/greet/Crab'))->getBody());
        self::assertSame($app->container->get(GreetHandler::class), $app->container->get(GreetHandler::class));

        $app->register(new class implements Provider {
            public function register(Container $container): void
            {
                $container->singleton(Greeter::class, static fn() => new Greeter('Hi'));
                $container->bind(GreetHandler::class);
            }
        });
        self::assertSame('Hi, Crab', (string) $app->handle(new Request('GET', '/greet/Crab'))->getBody());

        try {
            (new Router())->get('/', GreetHandler::class);
            self::fail('A named handler was accepted without a container.');
        } catch (\LogicException) {
        }
        $this->expectException(\LogicException::class);
        $app->register(Greeter::class);
    }

    public function testFailuresAreAnsweredAndLoggedThroughConfiguration(): void
    {
        $path = sys_get_temp_dir() . '/crab-app-' . bin2hex(random_bytes(6)) . '.log';
        try {
            $app = new App(new Config(['app' => ['debug' => true], 'log' => ['path' => $path, 'level' => 'error']]));
            self::assertSame($app->config, $app->container->get(ConfigContract::class));
            self::assertSame($app->config, $app->container->get(Config::class));
            $app->router->get('/fail', static fn() => throw new \RuntimeException('broken'));
            $app->router->get('/wrong', Greeter::class);
            $app->middleware(new TagMiddleware('inner'));
            $app->container->get(LoggerInterface::class)->info('below the level');

            $response = $app->handle(new Request('GET', '/fail'));
            self::assertSame(500, $response->getStatusCode());
            self::assertStringStartsWith("500 broken\n\nRuntimeException", (string) $response->getBody());
            self::assertSame([], $response->getHeader('X-Trail'));
            self::assertSame(500, $app->handle(new Request('GET', '/wrong'))->getStatusCode());

            $log = file_get_contents($path);
            self::assertStringContainsString('GET /fail failed: broken', $log);
            self::assertStringContainsString('GET /wrong failed: ', $log);
            self::assertStringNotContainsString('below the level', $log);

            $app->container->instance(ErrorHandler::class, new TagMiddleware('custom'));
            $app->middleware();
            self::assertSame(['inner', 'custom'], $app->handle(new Request('GET', '/missing'))->getHeader('X-Trail'));
        } finally {
            @unlink($path);
        }
    }

    public function testWorkerProvidersStartAndStopWithTheWorkerAndTaskFailuresAreLogged(): void
    {
        $path = sys_get_temp_dir() . '/crab-app-' . bin2hex(random_bytes(6)) . '.log';
        RecordingProvider::$events = [];
        try {
            $app = new App(new Config(['log' => ['path' => $path]]));
            $app->register(new RecordingProvider('first'), new RecordingProvider('second', failsToStop: true));
            $app->register(new class implements WorkerProvider {
                public function register(Container $container): void
                {
                }

                public function start(Worker $worker, Container $container): void
                {
                    $worker->spawn(static fn() => throw new \RuntimeException('job broke'));
                    $worker->loop->delay(0.005, static fn() => throw new \RuntimeException('timer broke'));
                    $worker->loop->delay(0.02, static fn() => $worker->stop());
                }

                public function stop(Worker $worker, Container $container): void
                {
                }
            });
            $app->runtime('tcp://127.0.0.1:0')->run(singleProcess: true);

            self::assertSame(['first register', 'second register', 'first start in worker 0', 'second start in worker 0',
                'second stop', 'first stop'], RecordingProvider::$events);
            $log = file_get_contents($path);
            self::assertStringContainsString('ERROR Background work failed: job broke', $log);
            self::assertStringContainsString('ERROR Background work failed: timer broke', $log);
            self::assertStringContainsString('ERROR Background work failed: second cannot stop', $log);
        } finally {
            @unlink($path);
        }
    }

    public function testAProviderAddsMiddlewareAndDeclaresDefaults(): void
    {
        $app = new App(new Config(['tags' => ['outer' => 'set by the app']]));
        $app->router->get('/', static fn(ServerRequestInterface $request) => $request->getAttribute('trail'));
        $app->register(new class implements Provider {
            public function register(Container $container): void
            {
                $config = $container->get(ConfigContract::class);
                $config->defaults('tags', ['outer' => 'default outer', 'inner' => 'default inner']);
                $container->get(MiddlewareStack::class)->add(
                    new TagMiddleware($config->get('tags.outer')),
                    new TagMiddleware($config->get('tags.inner')),
                );
            }
        });
        self::assertSame('set by the app>default inner>', (string) $app->handle(new Request('GET', '/'))->getBody());
        self::assertSame('default inner', config('tags.inner'));
    }

    public function testMiddlewareWrapsRoutesInOrder(): void
    {
        $app = new App();
        $app->router->get('/', static fn(ServerRequestInterface $request) => $request->getAttribute('trail'));
        $app->middleware(new TagMiddleware('outer'), TagMiddleware::class);
        $response = $app->handle(new Request('GET', '/'));
        self::assertSame('outer>named>', (string) $response->getBody());
        self::assertSame(['named', 'outer'], $response->getHeader('X-Trail'));
        self::assertSame(['named', 'outer'], $app->handle(new Request('GET', '/missing'))->getHeader('X-Trail'));

        $app->middleware(new TagMiddleware('late'));
        self::assertSame('outer>named>late>', (string) $app->handle(new Request('GET', '/'))->getBody());

        $bare = new Pipeline($app->router);
        self::assertSame(404, $bare->handle(new Request('GET', '/missing'))->getStatusCode());

        $this->expectException(\LogicException::class);
        (new App())->middleware(Factory::class)->handle(new Request('GET', '/'));
    }

    public function testEngineHandlerConvertsBothWays(): void
    {
        $app = new App();
        $app->router->get('/hello/{name}', static fn(RequestContract $request) => Response::json(
            ['hello' => $request->getAttribute('name'), 'page' => $request->query('page')],
            headers: ['X-Trace' => ['a', 'b']],
        ));
        $handler = new EngineHandler($app);
        $response = $handler(new EngineRequest('GET', '/hello/crab?page=2', '1.1', ['host' => ['example.com']]));

        self::assertSame(200, $response->status);
        self::assertSame(['content-type' => ['application/json'], 'X-Trace' => ['a', 'b']], $response->headers);
        self::assertSame('{"hello":"crab","page":"2"}', $response->body);
        self::assertSame(404, $handler(new EngineRequest('GET', '/missing', '1.1', ['host' => ['example.com']]))->status);

        $text = (new EngineHandler(static fn() => Response::text('hi')))(new EngineRequest('GET', '/', '1.1', []));
        self::assertSame(['Content-Type' => 'text/plain; charset=utf-8'], $text->headers);
        self::assertSame('plain', (new EngineHandler(static fn() => 'plain'))(new EngineRequest('GET', '/', '1.1', []))->body);
    }

    public function testEngineHandlerStreamsResourceBodies(): void
    {
        $factory = new Factory();
        $handler = new EngineHandler(static fn(RequestContract $request) => (new Response())->withBody($factory->createStreamFromFile(__FILE__)));
        $response = $handler(new EngineRequest('GET', '/', '1.1', []));
        self::assertSame(['Content-Length' => [(string) filesize(__FILE__)]], $response->headers);
        self::assertSame(file_get_contents(__FILE__), implode('', iterator_to_array($response->body, false)));

        $written = new EngineHandler(static function () {
            $response = new Response(202);
            $response->getBody()->write('written');
            return $response;
        });
        $response = $written(new EngineRequest('GET', '/', '1.1', []));
        self::assertSame([202, 'written'], [$response->status, implode('', iterator_to_array($response->body, false))]);

        $empty = new EngineHandler(static fn() => (new Response(204))->withBody($factory->createStreamFromResource(fopen('php://memory', 'r+b'))));
        self::assertSame('', $empty(new EngineRequest('GET', '/', '1.1', []))->body);
    }
}
