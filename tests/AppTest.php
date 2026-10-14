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
use Crab\Http\RequestId;
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
            self::fail('A named action was accepted without a container.');
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
            $app->container->get(MiddlewareStack::class)->add(new TagMiddleware('inner'));
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
            $app->container->get(MiddlewareStack::class)->add();
            $missing = $app->handle(new Request('GET', '/missing'));
            self::assertSame([404, ['inner', 'custom']], [$missing->getStatusCode(), $missing->getHeader('X-Trail')]);
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
            self::assertStringContainsString('ERROR Unhandled failure: job broke', $log);
            self::assertStringContainsString('ERROR Unhandled failure: timer broke', $log);
            self::assertStringContainsString('ERROR Unhandled failure: second cannot stop', $log);
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

    public function testRequestIdsWhenTheirMiddlewareIsAdded(): void
    {
        $path = sys_get_temp_dir() . '/crab-app-' . bin2hex(random_bytes(6)) . '.log';
        try {
            // Outside the error handler, so that the record of a failure carries the id too.
            $app = new App(new Config(['log' => ['path' => $path]]), middleware: [RequestId::class, ErrorHandler::class]);
            $app->router->get('/', static fn(ServerRequestInterface $request) => $request->getAttribute('request_id'));
            $app->router->get('/fail', static fn() => throw new \RuntimeException('broken'));

            $first = $app->handle(new Request('GET', '/'));
            $second = $app->handle(new Request('GET', '/'));
            self::assertMatchesRegularExpression('/^[0-9a-f]{12}-1$/', $first->getHeaderLine('X-Request-Id'));
            self::assertSame((string) $first->getBody(), $first->getHeaderLine('X-Request-Id'));
            self::assertNotSame($first->getHeaderLine('X-Request-Id'), $second->getHeaderLine('X-Request-Id'));
            self::assertSame('trace-42.a_b', $app->handle(new Request('GET', '/', ['X-Request-Id' => 'trace-42.a_b']))->getHeaderLine('X-Request-Id'));
            foreach (['has space', 'semi;colon', str_repeat('a', 129)] as $unsafe) {
                self::assertNotSame($unsafe, $app->handle(new Request('GET', '/', ['X-Request-Id' => $unsafe]))->getHeaderLine('X-Request-Id'));
            }

            $failed = $app->handle(new Request('GET', '/fail', ['X-Request-Id' => 'trace-43']));
            self::assertSame([500, 'trace-43'], [$failed->getStatusCode(), $failed->getHeaderLine('X-Request-Id')]);
            self::assertStringContainsString('{"request_id":"trace-43"}', file_get_contents($path));
            self::assertSame('trace-44', $app->handle(new Request('GET', '/missing', ['X-Request-Id' => 'trace-44']))->getHeaderLine('X-Request-Id'));

            // An object made before the workers are forked is the same object in each of them.
            $made = new RequestId();
            $pass = new Pipeline($app->router);
            $one = $made->process(new Request('GET', '/'), $pass)->getHeaderLine('X-Request-Id');
            $other = (clone $made)->process(new Request('GET', '/'), $pass)->getHeaderLine('X-Request-Id');
            self::assertNotSame($one, $other, 'Two workers do not give out the same ids.');

            $off = new App();
            $off->router->get('/', static fn() => 'home');
            self::assertFalse($off->handle(new Request('GET', '/'))->hasHeader('X-Request-Id'));
        } finally {
            @unlink($path);
        }
    }

    public function testInAWorkerWarningsAreFailuresAndDeprecationsAreLoggedOnce(): void
    {
        $path = sys_get_temp_dir() . '/crab-app-' . bin2hex(random_bytes(6)) . '.log';
        $seen = [];
        try {
            $app = new App(new Config(['log' => ['path' => $path]]));
            $app->register(new class($seen) implements WorkerProvider {
                public function __construct(private array &$seen)
                {
                }

                public function register(Container $container): void
                {
                }

                public function start(Worker $worker, Container $container): void
                {
                    $worker->spawn(function () use ($worker): void {
                        try {
                            $missing = [];
                            $this->seen[] = $missing['key'];
                        } catch (\ErrorException $error) {
                            $this->seen[] = $error->getMessage();
                        }
                        $this->seen[] = @file_get_contents('/no/such/file');
                        for ($i = 0; $i < 3; ++$i) {
                            trigger_error('old way', E_USER_DEPRECATED);
                        }
                        $this->seen[] = 'continued';
                        $worker->loop->defer(static fn() => $worker->stop());
                    });
                }

                public function stop(Worker $worker, Container $container): void
                {
                }
            });
            $before = set_error_handler(null);
            restore_error_handler();
            // PHPUnit lowers the level while a test runs, which reads as "silenced".
            $level = error_reporting(E_ALL);
            try {
                $app->runtime('tcp://127.0.0.1:0')->run(singleProcess: true);
            } finally {
                error_reporting($level);
            }
            $after = set_error_handler(null);
            restore_error_handler();

            self::assertSame(['Undefined array key "key"', false, 'continued'], $seen);
            self::assertSame(1, substr_count(file_get_contents($path), 'WARNING Deprecated: old way in '));
            self::assertSame($before, $after, 'The worker must put back the error handler it replaced.');
        } finally {
            @unlink($path);
        }
    }

    public function testMiddlewareWrapsRoutesInOrder(): void
    {
        $app = new App();
        $app->router->get('/', static fn(ServerRequestInterface $request) => $request->getAttribute('trail'));
        $app->container->get(MiddlewareStack::class)->add(new TagMiddleware('outer'), TagMiddleware::class);
        $response = $app->handle(new Request('GET', '/'));
        self::assertSame('outer>named>', (string) $response->getBody());
        self::assertSame(['named', 'outer'], $response->getHeader('X-Trail'));
        self::assertSame(['named', 'outer'], $app->handle(new Request('GET', '/missing'))->getHeader('X-Trail'));

        $app->container->get(MiddlewareStack::class)->add(new TagMiddleware('late'));
        self::assertSame('outer>named>late>', (string) $app->handle(new Request('GET', '/'))->getBody());

        $bare = new Pipeline($app->router);
        self::assertSame(404, $bare->handle(new Request('GET', '/missing'))->getStatusCode());

        $this->expectException(\LogicException::class);
        (new App())->container->get(MiddlewareStack::class)->add(Factory::class)->handle(new Request('GET', '/'));
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

    public function testEveryCoreServiceIsBoundByItsContractItsPsrNameAndItsClass(): void
    {
        $app = new App();
        $same = [
            [\Crab\Contracts\Log\Logger::class, \Psr\Log\LoggerInterface::class, \Crab\Log\Logger::class],
            [\Crab\Contracts\Clock\Clock::class, \Psr\Clock\ClockInterface::class, \Crab\Clock\SystemClock::class],
            [\Crab\Contracts\Http\Factory::class, \Psr\Http\Message\ResponseFactoryInterface::class, \Crab\Http\Factory::class],
            [\Crab\Contracts\Http\Factory::class, \Psr\Http\Message\StreamFactoryInterface::class, \Psr\Http\Message\UriFactoryInterface::class],
            [\Crab\Contracts\Container\Container::class, \Psr\Container\ContainerInterface::class, \Crab\Container\Container::class],
        ];
        foreach ($same as [$contract, $standard, $class]) {
            $service = $app->container->get($contract);
            self::assertInstanceOf($contract, $service);
            self::assertSame($service, $app->container->get($standard), "{$standard} is another object than {$contract}.");
            self::assertSame($service, $app->container->get($class), "{$class} is another object than {$contract}.");
        }
    }

    public function testServeRunsTheServersNamedForItBesideHttp(): void
    {
        $app = new App(new Config(['queue' => ['store' => 'memory', 'supervisors' => [
            'mail' => ['queues' => ['mail'], 'workers' => 2],
            'rest' => ['queues' => ['default']],
        ]]]));
        $app->register(\Crab\Queue\QueueProvider::class, \Crab\Schedule\ScheduleProvider::class);
        $app->console->add('users:sync', static fn (): int => 0);
        $servers = $app->container->get(\Crab\Contracts\Servers::class);
        // A server of the application's own that joins: no loop of work, only somewhere to listen.
        $servers->add('chat', static fn (): array => [
            new \Crab\Contracts\Workers('chat', workers: 3, setup: static function (\Crab\Engine\Crab $group): void {
                $group->listen('tcp://127.0.0.1:0', static fn () => null);
            }),
        ], 12.0, 'chat:serve');
        // One of the application's commands, and any program at all.
        $servers->command('sync', 'users:sync --all');
        $servers->process('vite', 'npm run dev');

        self::assertSame(['queue', 'schedule', 'chat', 'sync', 'vite'], $servers->ids());
        $named = static fn (array $ids): array => array_map(
            static fn (\Crab\Contracts\Workers $workers): string => "{$workers->group} x{$workers->workers}",
            $app->alongside($ids),
        );
        // One with workers of its own joins the server as its groups; a command or a program is one worker that looks after a process.
        self::assertSame(['queue:mail x2', 'queue:rest x1'], $named(['queue']));
        self::assertSame(['chat x3', 'schedule x1', 'sync x1', 'vite x1'], $named(['chat', 'schedule', 'sync', 'vite']));
        self::assertSame([30.0, 12.0], [$app->servers->grace(['queue', 'chat']), $app->servers->grace(['chat'])]);

        // Nothing is named, so the server is HTTP alone.
        self::assertSame([], $app->with());
        self::assertInstanceOf(\Crab\Engine\Crab::class, $app->runtime('tcp://127.0.0.1:0')->group('queue:mail'));

        // Each id with whether it is on, as a configuration file reads it from the environment.
        $switched = new App(new Config(['server' => ['with' => ['queue' => true, 'schedule' => false, 'vite' => ['enabled' => true], 'chat' => ['enabled' => false], 'sync' => 'yes']]]));
        foreach (['queue', 'schedule', 'vite', 'chat', 'sync'] as $id) {
            $switched->servers->process($id, 'true');
        }
        self::assertSame(['queue', 'vite'], $switched->with());

        // Or a plain list, or ids with commas between. Switched on, they are part of the runtime.
        $app->config->defaults('server', ['with' => ' queue, schedule ,vite,']);
        self::assertSame(['queue', 'schedule', 'vite'], $app->with());
        $runtime = $app->runtime('tcp://127.0.0.1:0');
        self::assertSame(30.0, $runtime->shutdownTimeout);
        try {
            $runtime->group('queue:mail');
            self::fail('The queue\'s workers were not part of the runtime.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('unique', $error->getMessage());
        }
        // In one process there is room for HTTP alone.
        self::assertInstanceOf(\Crab\Engine\Crab::class, $app->runtime('tcp://127.0.0.1:0', servers: false)->group('queue:mail'));

        // A server in the background hands only the HTTP socket to the one that replaces it, so one that
        // listens on an address of its own is refused there, in words, and not left to fail at its first reload.
        $listening = new App(new Config(['server' => ['with' => ['chat' => true]]]));
        $listening->servers->add('chat', static fn (): array => [
            new \Crab\Contracts\Workers('chat', setup: static function (\Crab\Engine\Crab $group): void {
                $group->listen('tcp://127.0.0.1:0', static fn () => null);
            }),
        ]);
        self::assertInstanceOf(\Crab\Engine\Runtime::class, $listening->runtime('tcp://127.0.0.1:0'));
        $inherited = stream_socket_server('tcp://127.0.0.1:0');
        try {
            $listening->runtime('tcp://127.0.0.1:0', socket: $inherited);
            self::fail('A server with a listener of its own was accepted in the background.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('"chat" listens on an address of its own', $error->getMessage());
        } finally {
            fclose($inherited);
        }

        // What `serve` says as it comes up: every server by its id, the ones not named shown as off.
        $stream = fopen('php://memory', 'w+');
        \Crab\Commands\Serve::announceBackground(new \Crab\Console\Output($stream, $stream), $app, 'tcp://127.0.0.1:8080', 4);
        rewind($stream);
        self::assertSame(<<<'TEXT'
            Crab server running on http://127.0.0.1:8080

              Server    Workers  Runs
              --------  -------  ----------------------------
              http      4        http://127.0.0.1:8080
              queue     3        queue:work: mail x2, rest x1
              schedule  1        schedule:work
              vite      1        npm run dev
              chat      off      chat:serve
              sync      off      php cua users:sync --all

              A server that is off is switched on in "server.with", or run on its own when needed.


            TEXT, stream_get_contents($stream));

        // Registered again, an id runs what was said last: the application's word over the framework's.
        $servers->command('queue', 'users:sync --queue');
        self::assertSame(['queue x1'], $named(['queue']));
        self::assertSame(['queue', 'schedule', 'chat', 'sync', 'vite'], $servers->ids());

        foreach ([
            'switches on "mail", and the servers registered are queue, schedule, chat, sync, vite' => static function () use ($app): void {
                $fresh = new Config(['server' => ['with' => 'mail']]);
                $other = new App($fresh);
                foreach (['queue', 'schedule', 'chat', 'sync', 'vite'] as $id) {
                    $other->servers->process($id, 'true');
                }
                $other->with();
            },
            'there is no command "mail:work"' => static function () use ($app): void {
                $app->servers->command('mailer', 'mail:work');
                $app->alongside(['mailer']);
            },
            'the server cannot run itself' => static function () use ($app): void {
                $app->servers->command('again', 'serve --daemon');
                $app->alongside(['again']);
            },
            '"http" is the server\'s own' => static fn () => $servers->process('http', 'true'),
            'cannot be a server\'s id' => static fn () => $servers->process('queue:work', 'true'),
            'no command to run' => static fn () => $servers->process('empty', '  '),
            'must give a list of' => static function () use ($app): void {
                $app->servers->add('wrong', static fn (): array => ['not workers']);
                $app->alongside(['wrong']);
            },
        ] as $says => $mistake) {
            try {
                $mistake();
                self::fail("A mistake that should say \"{$says}\" was accepted.");
            } catch (\LogicException $error) {
                self::assertStringContainsString($says, $error->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has nothing to do');
        new \Crab\Contracts\Workers('idle');
    }
}
