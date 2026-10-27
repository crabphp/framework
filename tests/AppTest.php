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
use Crab\Engine\Http\Request as EngineRequest;
use Crab\Engine\Process\Worker;
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
    public function __construct(
        public string $greeting,
    ) {}
}

final class GreeterProvider implements Provider
{
    public function register(Container $container): void
    {
        $container->singleton(Greeter::class, static fn() => new Greeter('Hello'));
    }
}

final readonly class CountActionConstruction
{
    public function __construct(
        private int $number,
    ) {}

    public function handle(): array
    {
        return ['construction' => $this->number];
    }
}

interface RomScanner {}

final readonly class ImportRom
{
    public function __construct(
        private RomScanner $scanner,
    ) {}

    public function handle(): array
    {
        return ['scanner' => $this->scanner::class];
    }
}

final readonly class IndexRoms
{
    public function __construct(
        public RomScanner $scanner,
    ) {}

    public function handle(object $event): void {}
}

final class GreetHandler implements RequestHandlerInterface
{
    public function __construct(
        private Greeter $greeter,
        private ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->responses->createResponse();
        $response->getBody()->write($this->greeter->greeting . ', ' . $request->getAttribute('name'));
        return $response;
    }
}

final class TagMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $tag = 'named',
    ) {}

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

    public function __construct(
        private string $name,
        private bool $failsToStop = false,
    ) {}

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
    public function testARouteRetainsItsResolvedActionEvenWithATransientBinding(): void
    {
        $app = new App();
        $made = 0;
        $app->container->bind(CountActionConstruction::class, static function () use (&$made): CountActionConstruction {
            return new CountActionConstruction(++$made);
        });
        $app->router->get('/count', CountActionConstruction::class);
        foreach ([1, 2] as $request) {
            $response = $app->handle(new Request('GET', '/count'));
            self::assertSame('{"construction":1}', (string) $response->getBody(), 'Request ' . $request);
        }
        self::assertSame(1, $made);
        $app->container->get(CountActionConstruction::class);
        self::assertSame(2, $made, 'The binding remains transient when asked for directly.');
    }

    public function testCoreDefaultsAreVisibleToTheApplicationAndItsConsumers(): void
    {
        $app = new App();
        self::assertSame(['name' => 'Crab', 'debug' => false, 'url' => null, 'path' => null], $app->config->get('app'));
        self::assertSame(['path' => 'php://stderr', 'level' => 'debug'], $app->config->get('log'));
        self::assertSame('tcp://127.0.0.1:8080', $app->config->get('server.address'));
        self::assertSame(1, $app->config->get('server.workers'));
        self::assertSame([], $app->config->get('server.with'));
        self::assertSame(\Crab\Server::WATCHED, $app->config->get('server.watch'));
        self::assertSame(1_048_576, $app->config->get('server.max_body'));
        self::assertSame(30, $app->config->get('server.body_timeout'));
        self::assertSame('storage/crab.pid', $app->config->get('server.pid'));
        self::assertSame('storage/logs/server.log', $app->config->get('server.log'));
        self::assertSame(
            $app->config->get('server.address'),
            \Crab\Commands\Start::address($app, new \Crab\Console\Input()),
        );
        self::assertSame(
            $app->config->get('server.workers'),
            \Crab\Commands\Start::workers($app, new \Crab\Console\Input()),
        );
    }

    public function testCoreDefaultsPreserveExplicitNullFalseAndConfiguredValues(): void
    {
        $config = new Config([
            'app' => ['name' => null, 'debug' => true, 'url' => null],
            'log' => ['path' => 'php://memory', 'level' => 'warning'],
            'server' => ['address' => 'tcp://127.0.0.1:28480', 'workers' => 3, 'watch' => null, 'with' => false],
        ]);
        $app = new App($config);
        self::assertNull($config->get('app.name'));
        self::assertTrue($config->get('app.debug'));
        self::assertNull($config->get('app.url'));
        self::assertSame(['path' => 'php://memory', 'level' => 'warning'], $config->get('log'));
        self::assertNull($config->get('server.watch'));
        self::assertFalse($config->get('server.with'));
        self::assertSame([], $app->with());
        self::assertSame('tcp://127.0.0.1:28480', \Crab\Commands\Start::address($app, new \Crab\Console\Input()));
        self::assertSame(3, \Crab\Commands\Start::workers($app, new \Crab\Console\Input()));
    }

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
        $app = new App()->register(GreeterProvider::class);
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
            new Router()->get('/', GreetHandler::class);
            self::fail('A named action was accepted without a container.');
        } catch (\LogicException) {
        }
        $this->expectException(\LogicException::class);
        $app->register(Greeter::class);
    }

    public function testServicesThatCannotBeMadeAreRefusedBeforeAWorkerStarts(): void
    {
        $app = new App()->register(
            \Crab\Events\EventsProvider::class,
            \Crab\Queue\QueueProvider::class,
            \Crab\Schedule\ScheduleProvider::class,
        );
        $app->router->post('/roms', ImportRom::class);
        $app->router->get('/greet/{name}', GreetHandler::class)->middleware(TagMiddleware::class);
        $app->container->get(\Crab\Contracts\Events\Dispatcher::class)->listen(\stdClass::class, IndexRoms::class);
        $app
            ->container
            ->get(\Crab\Contracts\Schedule\Schedule::class)
            ->job(ImportRom::class)
            ->everyMinute();
        $missing = 'Crab\\Tests\\ImportRom needs Crab\\Tests\\RomScanner, which has no binding and is not a class that can be constructed.';
        $problems = [
            "Route POST /roms: {$missing}",
            'Route GET /greet/{name}: Crab\\Tests\\GreetHandler needs Crab\\Tests\\Greeter, which takes $greeting, which cannot be autowired; bind Crab\\Tests\\Greeter with a closure.',
            'A listener for stdClass: Crab\\Tests\\IndexRoms needs Crab\\Tests\\RomScanner, which has no binding and is not a class that can be constructed.',
        ];
        self::assertSame($problems, $app->unmade(), 'The job is named by the route first; each is said once.');

        try {
            $app->runtime('tcp://127.0.0.1:0');
            self::fail('An application whose services cannot be made was given a runtime.');
        } catch (\Crab\Contracts\Container\MissingServices $error) {
            self::assertSame($problems, $error->problems());
            self::assertStringStartsWith("3 services cannot be made:\n  Route POST /roms: ", $error->getMessage());
        }
        try {
            $app->run('import', static function (): void {}, single: true);
            self::fail('Workers that serve no requests were run.');
        } catch (\Crab\Contracts\Container\MissingServices) {
        }

        $app->register(GreeterProvider::class);
        $app->container->singleton(
            RomScanner::class,
            static fn(): RomScanner => throw new \LogicException('Not made.'),
        );
        self::assertSame([], $app->unmade(), 'A closure is trusted, and not run.');
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

    public function testDisabledOrUnrecognisedDebugEnvironmentValuesKeepFailuresPrivate(): void
    {
        $directory = sys_get_temp_dir() . '/crab-debug-' . bin2hex(random_bytes(6));
        mkdir($directory . '/config', recursive: true);
        file_put_contents($directory . '/config/app.php', "<?php\nreturn ['debug' => env('APP_DEBUG', false)];\n");
        file_put_contents($directory . '/config/log.php', "<?php\nreturn ['path' => 'php://memory'];\n");
        $original = getenv('APP_DEBUG');
        try {
            foreach (['off', 'no', 'unrecognised', 'false', '0', ''] as $value) {
                putenv('APP_DEBUG=' . $value);
                $app = App::configure($directory)->create();
                $app->router->get('/fail', static fn(): never => throw new \RuntimeException('private failure detail'));
                $response = $app->handle(new Request('GET', '/fail', ['Accept' => 'application/json']));
                self::assertSame(500, $response->getStatusCode());
                self::assertSame(
                    '{"error":{"status":500,"message":"Internal Server Error"}}',
                    (string) $response->getBody(),
                    'APP_DEBUG=' . $value,
                );
            }
        } finally {
            putenv($original === false ? 'APP_DEBUG' : 'APP_DEBUG=' . $original);
            unlink($directory . '/config/app.php');
            unlink($directory . '/config/log.php');
            rmdir($directory . '/config');
            rmdir($directory);
        }
    }

    public function testTheDocumentedLoginChecksAPasswordForBothKnownAndMissingUsers(): void
    {
        $page = file_get_contents(dirname(__DIR__) . '/docs/authentication.md');
        preg_match('/```php\n([^`]*final readonly class AuthController[^`]*?)```/s', $page, $match);
        self::assertArrayHasKey(1, $match);
        $name = 'LoginExample' . bin2hex(random_bytes(6));
        $file = sys_get_temp_dir() . '/' . $name . '.php';
        file_put_contents($file, "<?php\n" . str_replace('class AuthController', 'class ' . $name, $match[1]));
        $database = new \Crab\Database\Database(static fn(): \PDO => new \PDO('sqlite::memory:'), 1);
        try {
            require $file;
            $database->execute('create table users (id integer primary key, email text, password text)');
            $database->table('users')->insert(['email' => 'known@example.com', 'password' => 'stored-hash']);
            $checked = [];
            $passwords = $this->createStub(\Crab\Contracts\Auth\PasswordHasher::class);
            $passwords
                ->method('verify')
                ->willReturnCallback(static function (#[\SensitiveParameter] string $password, string $hash) use (
                    &$checked,
                ): bool {
                    $checked[] = $hash;
                    return false;
                });
            $tokens = $this->createMock(\Crab\Contracts\Auth\TokenStore::class);
            $tokens->expects(self::never())->method('issue');
            $controller = new $name($database, $passwords, $tokens);
            foreach (['known@example.com', 'missing@example.com'] as $email) {
                try {
                    $controller->login($email, 'incorrect');
                } catch (\Crab\Http\HttpException $error) {
                    self::assertSame(401, $error->status);
                    self::assertSame('These credentials do not match.', $error->getMessage());
                    continue;
                }
                self::fail('An incorrect login was accepted.');
            }
            self::assertCount(2, $checked, 'A missing user must still reach password verification.');
            self::assertSame('stored-hash', $checked[0]);
            self::assertSame('bcrypt', password_get_info($checked[1])['algoName']);
            self::assertSame(12, password_get_info($checked[1])['options']['cost']);
        } finally {
            $database->close();
            unlink($file);
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
                public function register(Container $container): void {}

                public function start(Worker $worker, Container $container): void
                {
                    $worker->spawn(static fn() => throw new \RuntimeException('job broke'));
                    $worker->loop->delay(0.005, static fn() => throw new \RuntimeException('timer broke'));
                    $worker->loop->delay(0.02, static fn() => $worker->stop());
                }

                public function stop(Worker $worker, Container $container): void {}
            });
            $app->runtime('tcp://127.0.0.1:0')->run(singleProcess: true);

            self::assertSame(
                [
                    'first register',
                    'second register',
                    'first start in worker 0',
                    'second start in worker 0',
                    'second stop',
                    'first stop',
                ],
                RecordingProvider::$events,
            );
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
            $app = new App(new Config(['log' => [
                'path' => $path,
            ]]), middleware: [RequestId::class, ErrorHandler::class]);
            $app->router->get('/', static fn(ServerRequestInterface $request) => $request->getAttribute('request_id'));
            $app->router->get('/fail', static fn() => throw new \RuntimeException('broken'));

            $first = $app->handle(new Request('GET', '/'));
            $second = $app->handle(new Request('GET', '/'));
            self::assertMatchesRegularExpression('/^[0-9a-f]{12}-1$/', $first->getHeaderLine('X-Request-Id'));
            self::assertSame((string) $first->getBody(), $first->getHeaderLine('X-Request-Id'));
            self::assertNotSame($first->getHeaderLine('X-Request-Id'), $second->getHeaderLine('X-Request-Id'));
            self::assertSame('trace-42.a_b', $app->handle(new Request('GET', '/', [
                'X-Request-Id' => 'trace-42.a_b',
            ]))->getHeaderLine('X-Request-Id'));
            foreach (['has space', 'semi;colon', str_repeat('a', 129)] as $unsafe) {
                self::assertNotSame($unsafe, $app->handle(new Request('GET', '/', [
                    'X-Request-Id' => $unsafe,
                ]))->getHeaderLine('X-Request-Id'));
            }

            $failed = $app->handle(new Request('GET', '/fail', ['X-Request-Id' => 'trace-43']));
            self::assertSame([500, 'trace-43'], [$failed->getStatusCode(), $failed->getHeaderLine('X-Request-Id')]);
            self::assertStringContainsString('{"request_id":"trace-43"}', file_get_contents($path));
            self::assertSame('trace-44', $app->handle(new Request('GET', '/missing', [
                'X-Request-Id' => 'trace-44',
            ]))->getHeaderLine('X-Request-Id'));

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
                public function __construct(
                    private array &$seen,
                ) {}

                public function register(Container $container): void {}

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

                public function stop(Worker $worker, Container $container): void {}
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
        new App()->container->get(MiddlewareStack::class)->add(Factory::class)->handle(new Request('GET', '/'));
    }

    public function testEngineHandlerConvertsBothWays(): void
    {
        $app = new App();
        $app->router->get('/hello/{name}', static fn(RequestContract $request) => Response::json([
            'hello' => $request->getAttribute('name'),
            'page' => $request->query('page'),
        ], headers: ['X-Trace' => ['a', 'b']]));
        $handler = new EngineHandler($app);
        $response = $handler(new EngineRequest('GET', '/hello/crab?page=2', '1.1', ['host' => ['example.com']]));

        self::assertSame(200, $response->status);
        self::assertSame(['content-type' => ['application/json'], 'X-Trace' => ['a', 'b']], $response->headers);
        self::assertSame('{"hello":"crab","page":"2"}', $response->body);
        self::assertSame(404, $handler(new EngineRequest('GET', '/missing', '1.1', ['host' => [
            'example.com',
        ]]))->status);

        $text = new EngineHandler(static fn() => Response::text('hi'))(new EngineRequest('GET', '/', '1.1', []));
        self::assertSame(['Content-Type' => 'text/plain; charset=utf-8'], $text->headers);
        self::assertSame(
            'plain',
            new EngineHandler(static fn() => 'plain')(new EngineRequest('GET', '/', '1.1', []))->body,
        );
    }

    public function testEngineHandlerStreamsResourceBodies(): void
    {
        $factory = new Factory();
        $handler =
            new EngineHandler(static fn(RequestContract $request) => new Response()->withBody($factory->createStreamFromFile(
                __FILE__,
            )));
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

        $empty = new EngineHandler(static fn() => new Response(204)->withBody($factory->createStreamFromResource(fopen(
            'php://memory',
            'r+b',
        ))));
        self::assertSame('', $empty(new EngineRequest('GET', '/', '1.1', []))->body);
    }

    public function testEveryCoreServiceIsBoundByItsContractItsPsrNameAndItsClass(): void
    {
        $app = new App();
        $same = [
            [\Crab\Contracts\Log\Logger::class, \Psr\Log\LoggerInterface::class, \Crab\Log\Logger::class],
            [\Crab\Contracts\Clock\Clock::class, \Psr\Clock\ClockInterface::class, \Crab\Clock\SystemClock::class],
            [
                \Crab\Contracts\Http\Factory::class,
                \Psr\Http\Message\ResponseFactoryInterface::class,
                \Crab\Http\Factory::class,
            ],
            [
                \Crab\Contracts\Http\Factory::class,
                \Psr\Http\Message\StreamFactoryInterface::class,
                \Psr\Http\Message\UriFactoryInterface::class,
            ],
            [
                \Crab\Contracts\Container\Container::class,
                \Psr\Container\ContainerInterface::class,
                \Crab\Container\Container::class,
            ],
        ];
        foreach ($same as [$contract, $standard, $class]) {
            $service = $app->container->get($contract);
            self::assertInstanceOf($contract, $service);
            self::assertSame(
                $service,
                $app->container->get($standard),
                "{$standard} is another object than {$contract}.",
            );
            self::assertSame($service, $app->container->get($class), "{$class} is another object than {$contract}.");
        }
    }

    public function testServeRunsTheServersNamedForItBesideHttp(): void
    {
        $app = new App(new Config([
            'server' => ['with' => ' queue, schedule ,vite,'],
            'queue' => [
                'store' => 'memory',
                'supervisors' => [
                    'mail' => ['queues' => ['mail'], 'workers' => 2],
                    'rest' => ['queues' => ['default']],
                ],
            ],
        ]));
        $app->register(\Crab\Queue\QueueProvider::class, \Crab\Schedule\ScheduleProvider::class);
        $app->console->add('users:sync', static fn(): int => 0);
        $servers = $app->container->get(\Crab\Contracts\Servers::class);
        // A server of the application's own that joins: no loop of work, only somewhere to listen.
        $servers->add(
            'chat',
            static fn(): array => [
                new \Crab\Contracts\Workers(
                    'chat',
                    workers: 3,
                    setup: static function (\Crab\Engine\Crab $group): void {
                        $group->listen('tcp://127.0.0.1:0', static fn() => null);
                    },
                ),
            ],
            12.0,
            'chat:serve',
        );
        // One of the application's commands, and any program at all.
        $servers->command('sync', 'users:sync --all');
        $servers->process('vite', 'npm run dev');

        self::assertSame(['queue', 'schedule', 'chat', 'sync', 'vite'], $servers->ids());
        $named = static fn(array $ids): array => array_map(
            static fn(\Crab\Contracts\Workers $workers): string => "{$workers->group} x{$workers->workers}",
            $app->alongside($ids),
        );
        // One with workers of its own joins the server as its groups; a command or a program is one worker that looks after a process.
        self::assertSame(['queue:mail x2', 'queue:rest x1'], $named(['queue']));
        self::assertSame(
            ['chat x3', 'schedule x1', 'sync x1', 'vite x1'],
            $named(['chat', 'schedule', 'sync', 'vite']),
        );
        self::assertSame([30.0, 12.0], [$app->servers->grace(['queue', 'chat']), $app->servers->grace(['chat'])]);

        // Nothing is named, so the server is HTTP alone.
        $http = App::configure()->create();
        self::assertSame([], $http->with());
        self::assertInstanceOf(\Crab\Engine\Crab::class, $http->runtime('tcp://127.0.0.1:0')->group('queue:mail'));

        // Each id with whether it is on, as a configuration file reads it from the environment.
        $switched = new App(new Config([
            'server' => [
                'with' => [
                    'queue' => true,
                    'schedule' => false,
                    'vite' => ['enabled' => true],
                    'chat' => ['enabled' => false],
                    'sync' => 'yes',
                ],
            ],
        ]));
        foreach (['queue', 'schedule', 'vite', 'chat', 'sync'] as $id) {
            $switched->servers->process($id, 'true');
        }
        self::assertSame(['queue', 'vite'], $switched->with());

        // Or a plain list, or ids with commas between. Switched on, they are part of the runtime.
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
        self::assertInstanceOf(
            \Crab\Engine\Crab::class,
            $app->runtime('tcp://127.0.0.1:0', servers: false)->group('queue:mail'),
        );

        // A server in the background hands only the HTTP socket to the one that replaces it, so one that
        // listens on an address of its own is refused there, in words, and not left to fail at its first reload.
        $listening = new App(new Config(['server' => ['with' => ['chat' => true]]]));
        $listening->servers->add('chat', static fn(): array => [
            new \Crab\Contracts\Workers('chat', setup: static function (\Crab\Engine\Crab $group): void {
                $group->listen('tcp://127.0.0.1:0', static fn() => null);
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

        // What `start` says as it comes up: every server by its id, the ones not named shown as off.
        $stream = fopen('php://memory', 'w+');
        \Crab\Commands\Start::announceBackground(
            new \Crab\Console\Output($stream, $stream),
            $app,
            'tcp://127.0.0.1:8080',
            4,
        );
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
            'switches on "mail", and the servers registered are queue, schedule, chat, sync, vite' =>
                static function () use ($app): void {
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
                $app->servers->command('again', 'start --daemon');
                $app->alongside(['again']);
            },
            '"http" is the server\'s own' => static fn() => $servers->process('http', 'true'),
            'cannot be a server\'s id' => static fn() => $servers->process('queue:work', 'true'),
            'no command to run' => static fn() => $servers->process('empty', '  '),
            'must give a list of' => static function () use ($app): void {
                $app->servers->add('wrong', static fn(): array => ['not workers']);
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
