<?php

declare(strict_types=1);

namespace Crab\Tests;

use Crab\App;
use Crab\Auth\AuthProvider;
use Crab\Broadcast\BroadcastProvider;
use Crab\Cache\CacheProvider;
use Crab\Commands\Serve;
use Crab\Console\Output;
use Crab\Contracts\Auth\PasswordHasher;
use Crab\Contracts\Broadcast\Broadcaster;
use Crab\Contracts\Cache\Cache;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Events\Dispatcher;
use Crab\Contracts\HttpClient\Client;
use Crab\Contracts\Provider;
use Crab\Contracts\Queue\Queue;
use Crab\Contracts\Schedule\Schedule;
use Crab\Database\DatabaseException;
use Crab\Database\DatabaseProvider;
use Crab\Engine\Process\Worker;
use Crab\Events\EventsProvider;
use Crab\Http\ErrorHandler;
use Crab\Http\Request;
use Crab\HttpClient\HttpClientProvider;
use Crab\Queue\QueueProvider;
use Crab\Schedule\ScheduleProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Greeting
{
    public function __construct(
        public string $text,
    ) {}
}

final class GreetingProvider implements Provider
{
    public function register(Container $container): void
    {
        // Reads configuration, so it must be registered after the configuration is loaded.
        $container->instance(Greeting::class, new Greeting((string) config('app.greeting')));
    }
}

final class Mark implements MiddlewareInterface
{
    public function __construct(
        private string $mark = 'class',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        return $response->withAddedHeader('X-Mark', $this->mark);
    }
}

final class AppBuilderTest extends TestCase
{
    private string $directory;

    public function testAFrameworkProviderNamedAgainIsRegisteredOnce(): void
    {
        $additions = new class implements Provider {
            public function register(Container $container): void
            {
                $container->get(Dispatcher::class)->listen(Greeting::class, static function (Greeting $event): void {
                    $event->text .= ' heard';
                });
                $container->get(Schedule::class)->command('hello')->daily('07:00');
            }
        };
        foreach ([false, true] as $replace) {
            $providers = [
                $additions,
                EventsProvider::class,
                new EventsProvider(),
                ScheduleProvider::class,
                new ScheduleProvider(),
            ];
            $builder = App::configure();
            if ($replace) {
                $builder->withProviders(only: [EventsProvider::class, ScheduleProvider::class, ...$providers]);
            } else {
                $builder->withProviders($providers);
            }
            $app = $builder->create();
            $event = new Greeting('hello');
            $app->container->get(Dispatcher::class)->dispatch($event);
            self::assertSame('hello heard', $event->text);
            self::assertCount(1, $app->container->get(Schedule::class)->tasks());
        }
    }

    public function testAnApplicationHasTheFrameworkProvidersWithoutNamingThem(): void
    {
        file_put_contents($this->directory . '/config/queue.php', "<?php return ['store' => 'memory'];");
        file_put_contents($this->directory . '/config/cache.php', "<?php return ['store' => 'array'];");
        try {
            $app = App::configure($this->directory)->create();
            self::assertSame(
                [
                    DatabaseProvider::class,
                    AuthProvider::class,
                    CacheProvider::class,
                    EventsProvider::class,
                    QueueProvider::class,
                    ScheduleProvider::class,
                    HttpClientProvider::class,
                    BroadcastProvider::class,
                ],
                App::PROVIDERS,
            );
            foreach ([
                PasswordHasher::class,
                Queue::class,
                Cache::class,
                Schedule::class,
                Client::class,
                Dispatcher::class,
                Broadcaster::class,
            ] as $contract) {
                self::assertInstanceOf($contract, $app->container->get($contract));
            }
            self::assertSame(['queue', 'schedule'], $app->servers->ids());
            self::assertSame([], $app->with(), 'Registering a server does not switch it on.');
            foreach (['migrate', 'cache:clear', 'queue:work', 'schedule:list'] as $command) {
                self::assertTrue($app->console->has($command));
            }
            self::assertFalse($app->container->has(\Crab\Contracts\Auth\TokenStore::class));
            $direct = new App();
            foreach ([
                Database::class,
                PasswordHasher::class,
                Queue::class,
                Cache::class,
                Schedule::class,
                Client::class,
                Dispatcher::class,
                Broadcaster::class,
            ] as $contract) {
                self::assertFalse($direct->container->has($contract), 'new App() registers no providers.');
            }
        } finally {
            unlink($this->directory . '/config/queue.php');
            unlink($this->directory . '/config/cache.php');
        }
    }

    public function testAnApplicationWithNoConfigurationServesInAWorkerAndNamesTheMissingDatabaseSettings(): void
    {
        $app = App::configure()->create();
        $app->router->get('/', static fn(): string => 'ready');
        $served = false;
        $app->run(
            'test',
            static function (Worker $worker) use ($app, &$served): void {
                $response = $app->handle(new Request('GET', '/'));
                self::assertSame([200, 'ready'], [$response->getStatusCode(), (string) $response->getBody()]);
                $served = true;
                try {
                    $app->container->get(Database::class);
                    self::fail('An unconfigured database was returned.');
                } catch (DatabaseException $error) {
                    self::assertStringContainsString('No database is configured', $error->getMessage());
                    self::assertStringContainsString('database.connections', $error->getMessage());
                    self::assertStringContainsString('sqlite:/path/app.sqlite', $error->getMessage());
                }
            },
            single: true,
        );
        self::assertTrue($served, 'The worker started and served the request.');
    }

    public function testAFrameworkProviderCanBeLeftOutAndAnUnknownRemovalNamesTheList(): void
    {
        $app = App::configure()->withProviders(remove: ScheduleProvider::class)->create();
        self::assertFalse($app->container->has(Schedule::class));
        self::assertFalse($app->console->has('schedule:list'));
        self::assertSame(['queue'], $app->servers->ids());
        self::assertTrue($app->container->has(Dispatcher::class));
        try {
            App::configure()->withProviders(remove: [GreetingProvider::class]);
            self::fail('An application provider was accepted as a framework provider.');
        } catch (\LogicException $error) {
            self::assertStringContainsString(GreetingProvider::class, $error->getMessage());
            self::assertStringContainsString(implode(', ', App::PROVIDERS), $error->getMessage());
        }
    }

    public function testOnlyReplacesTheFrameworkProvidersAndCannotBeCombinedWithRemove(): void
    {
        $app = App::configure()->withProviders(only: [EventsProvider::class])->create();
        self::assertTrue($app->container->has(Dispatcher::class));
        self::assertFalse($app->container->has(Schedule::class));
        self::assertFalse($app->console->has('migrate'));
        self::assertSame([], $app->servers->ids());
        $empty = App::configure()->withProviders(only: [EventsProvider::class])->withProviders(only: [])->create();
        self::assertFalse($empty->container->has(Dispatcher::class));
        foreach ([
            static fn() => App::configure()->withProviders(only: [], remove: ScheduleProvider::class),
            static fn() => App::configure()->withProviders(only: [])->withProviders(remove: ScheduleProvider::class),
            static fn() => App::configure()->withProviders(remove: ScheduleProvider::class)->withProviders(only: []),
        ] as $mistake) {
            try {
                $mistake();
                self::fail('only: and remove: were accepted together.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('was given both only: and remove:', $error->getMessage());
            }
        }
    }

    public function testTheBackgroundAnnouncementIsOneLineWhenOnlyHttpIsSwitchedOn(): void
    {
        $stream = fopen('php://memory', 'w+');
        try {
            Serve::announceBackground(new Output($stream, $stream), App::configure()->create(), 'tcp://127.0.0.1:9000');
            rewind($stream);
            self::assertSame("Crab server running on http://127.0.0.1:9000\n", stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crab-builder-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/config', recursive: true);
        file_put_contents($this->directory . '/.env', "CRAB_BUILDER_GREETING=\"Hello from env\"\n");
        file_put_contents(
            $this->directory . '/config/app.php',
            "<?php return ['name' => 'Built', 'greeting' => env('CRAB_BUILDER_GREETING', 'unset')];",
        );
        file_put_contents(
            $this->directory . '/web.php',
            "<?php use Crab\\Route; Route::get('/', static fn (): string => config('app.name'));",
        );
        file_put_contents(
            $this->directory . '/api.php',
            "<?php use Crab\\Route; Route::get('/api', static fn (): array => ['leaked' => isset(\$app) || isset(\$this) || isset(\$file)]);",
        );
        file_put_contents(
            $this->directory . '/console.php',
            "<?php use Crab\\Command; Command::add('hello', static fn (): int => 0, 'Say hello');",
        );
    }

    protected function tearDown(): void
    {
        foreach (['/.env', '/config/app.php', '/web.php', '/api.php', '/console.php'] as $file) {
            unlink($this->directory . $file);
        }
        rmdir($this->directory . '/config');
        rmdir($this->directory);
    }

    public function testAnApplicationIsDescribedInAnyOrderAndBuiltInTheRightOne(): void
    {
        // Given backwards on purpose: routes need the application, providers the configuration, configuration the environment.
        $app = App::configure()
            ->withRouting(commands: $this->directory . '/console.php', routes: [
                $this->directory . '/web.php',
                $this->directory . '/api.php',
            ])
            ->withMiddleware(Mark::class)
            ->withMiddleware(new Mark('object'))
            ->withProviders(GreetingProvider::class)
            ->withConfig($this->directory . '/config')
            ->withEnv($this->directory . '/.env')
            ->create();

        self::assertSame($app, App::current());
        self::assertSame('Hello from env', $app->container->get(Greeting::class)->text);
        $home = $app->handle(new Request('GET', '/'));
        self::assertSame(
            ['Built', 'object, class'],
            [(string) $home->getBody(), $home->getHeaderLine('X-Mark')],
            'The first middleware given is outermost.',
        );
        self::assertSame(
            '{"leaked":false}',
            (string) $app->handle(new Request('GET', '/api'))->getBody(),
            'A route file sees no variable it did not define.',
        );
        self::assertTrue($app->console->has('hello'));
    }

    public function testABasePathLoadsEnvAndConfigAndResolvesRelativePaths(): void
    {
        $app = App::configure($this->directory . '/')
            ->withProviders([GreetingProvider::class])
            ->withMiddleware([Mark::class, new Mark('object')])
            ->withRouting(routes: ['web.php', $this->directory . '/api.php'], commands: 'console.php')
            ->create();

        self::assertSame($this->directory, $app->basePath);
        self::assertSame(
            'Hello from env',
            $app->container->get(Greeting::class)->text,
            '.env and config/ in the base path are loaded without being named.',
        );
        $home = $app->handle(new Request('GET', '/'));
        self::assertSame(['Built', 'object, class'], [(string) $home->getBody(), $home->getHeaderLine('X-Mark')]);
        self::assertSame(200, $app->handle(new Request('GET', '/api'))->getStatusCode());
        self::assertTrue($app->console->has('hello'));
        self::assertSame(
            404,
            App::configure($this->directory)
                ->withRouting(commands: 'console.php')
                ->create()
                ->handle(new Request('GET', '/'))
                ->getStatusCode(),
            'Either may be left out.',
        );

        self::assertSame($this->directory, $app->path());
        self::assertSame($this->directory . '/storage/app.log', $app->path('storage/app.log'));
        self::assertSame(['/var/log/app.log', 'php://stderr', 'C:\\logs\\app.log'], [
            $app->path('/var/log/app.log'),
            $app->path('php://stderr'),
            $app->path('C:\\logs\\app.log'),
        ]);

        mkdir($this->directory . '/settings');
        file_put_contents($this->directory . '/settings/app.php', "<?php return ['name' => 'Other'];");
        file_put_contents($this->directory . '/other.env', "CRAB_BUILDER_OTHER=1\n");
        $other = App::configure($this->directory)->withConfig('settings')->withEnv('other.env')->create();
        self::assertSame(['Other', true], [
            $other->config->get('app.name'),
            env('CRAB_BUILDER_OTHER') === 1 || env('CRAB_BUILDER_OTHER') === '1',
        ]);
        unlink($this->directory . '/settings/app.php');
        unlink($this->directory . '/other.env');
        rmdir($this->directory . '/settings');

        $bare = sys_get_temp_dir() . '/crab-bare-' . bin2hex(random_bytes(4));
        mkdir($bare);
        self::assertNull(
            App::configure($bare)->create()->config->get('app.name'),
            'A base path with no .env and no config folder is still an application.',
        );
        rmdir($bare);

        $mistakes = [
            'is not a directory' => static fn() => App::configure('/no/such/app'),
            'This application has no base path. Give it one with App::configure(__DIR__)' =>
                static fn() => new App()->path('x'),
            'withProviders() takes providers or their class names, not int' =>
                static fn() => App::configure()->withProviders([1]),
            'withMiddleware() takes PSR-15 middleware or their class names, not stdClass' =>
                static fn() => App::configure()->withMiddleware([new \stdClass()]),
            'withRouting() takes file paths, not int' => static fn() => App::configure()->withRouting(routes: [1]),
            'withRouting() takes file paths, not null' => static fn() => App::configure()->withRouting(commands: [
                null,
            ]),
        ];
        foreach ($mistakes as $expected => $mistake) {
            try {
                $mistake();
                self::fail("Not refused: {$expected}");
            } catch (\LogicException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
        self::assertSame('/x', new App()->path('/x'), 'An absolute path needs no base path.');
    }

    public function testTheGlobalMiddlewareIsAddedToRemovedFromOrReplaced(): void
    {
        $marks = static function (App $app, string $path = '/'): string {
            return $app->handle(new Request('GET', $path))->getHeaderLine('X-Mark');
        };
        $route = static fn() => \Crab\Route::get('/', static fn(): string => 'home');
        self::assertSame(
            [ErrorHandler::class],
            App::MIDDLEWARE,
            'What the framework puts around every request can be read.',
        );

        // Outermost first: prepended, the framework's, appended. The outermost marks the response last.
        $app = App::configure()
            ->withMiddleware([new Mark('appended'), new Mark('appended too')])
            ->withMiddleware(prepend: new Mark('prepended'))
            ->withMiddleware(new Mark('one alone'))
            ->create();
        // Before the first request, which is when the error handler is made with its logger.
        $app->container->instance(\Psr\Log\LoggerInterface::class, new \Psr\Log\NullLogger());
        $route();
        self::assertSame('one alone, appended too, appended, prepended', $marks($app));
        self::assertSame(
            'one alone, appended too, appended, prepended',
            $marks($app, '/missing'),
            'It runs for a request no route matches, too.',
        );

        // A failure beneath the framework's error handler is answered by it, inside what was prepended.
        \Crab\Route::get('/fail', static fn() => throw new \RuntimeException('broken'));
        $failed = $app->handle(new Request('GET', '/fail'));
        self::assertSame([500, 'prepended'], [$failed->getStatusCode(), $failed->getHeaderLine('X-Mark')]);

        // Without the framework's: nothing turns a failure into a response here.
        foreach ([
            App::configure()->withMiddleware(remove: ErrorHandler::class),
            App::configure()->withMiddleware(only: []),
        ] as $builder) {
            $bare = $builder->create();
            \Crab\Route::get('/fail', static fn() => throw new \RuntimeException('nothing catches this'));
            try {
                $bare->handle(new Request('GET', '/fail'));
                self::fail('The error handler was still there.');
            } catch (\RuntimeException $error) {
                self::assertSame('nothing catches this', $error->getMessage());
            }
        }

        // The whole list in place of the framework's, with the others still around it.
        $own = App::configure()
            ->withMiddleware(
                only: [new Mark('mine'), ErrorHandler::class],
                prepend: [new Mark('first')],
                append: [new Mark('last')],
            )
            ->create();
        $route();
        self::assertSame('last, mine, first', $marks($own));

        $mistakes = [
            'takes PSR-15 middleware or their class names, not int' => static fn() => App::configure()->withMiddleware([
                1,
            ]),
            'middleware or their class names, not stdClass' =>
                static fn() => App::configure()->withMiddleware(prepend: [new \stdClass()]),
            'their class names, not null' => static fn() => App::configure()->withMiddleware(only: [null]),
            'withMiddleware(remove:) takes the class names of middleware to leave out, not ' =>
                static fn() => App::configure()->withMiddleware(remove: [new Mark()]),
            'and ' . Mark::class . ' is not one of them: ' . ErrorHandler::class =>
                static fn() => App::configure()->withMiddleware(remove: [Mark::class]),
            'was given both only: and remove:' => static fn() => App::configure()
                ->withMiddleware(only: [])
                ->withMiddleware(remove: [ErrorHandler::class]),
        ];
        foreach ($mistakes as $expected => $mistake) {
            try {
                $mistake();
                self::fail("Not refused: {$expected}");
            } catch (\LogicException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
    }

    public function testAMiddlewareClassIsWrittenWhereItIsAskedFor(): void
    {
        $app = App::configure($this->directory)->create();
        $out = fopen('php://memory', 'w+');
        $run = static function (string ...$arguments) use ($app, $out): array {
            ftruncate($out, 0);
            rewind($out);
            $status = $app->console->run(['make:middleware', ...$arguments], new \Crab\Console\Output($out, $out));
            rewind($out);
            return [$status, stream_get_contents($out)];
        };
        $file = $this->directory . '/app/Http/RequireAdmin.php';
        [$status, $said] = $run('RequireAdmin');
        self::assertSame(0, $status, $said);
        self::assertStringStartsWith("Created {$file}\n", $said);
        $source = (string) file_get_contents($file);
        self::assertStringContainsString("namespace App\\Http;\n", $source);
        self::assertStringContainsString('final readonly class RequireAdmin implements Middleware', $source);

        // What was written is middleware that works: it lets the request through.
        require $file;
        $made = new \App\Http\RequireAdmin();
        self::assertInstanceOf(\Crab\Contracts\Http\Middleware::class, $made);
        self::assertInstanceOf(MiddlewareInterface::class, $made);
        $router = new \Crab\Routing\Router();
        $router->get('/', static fn(): string => 'through')->middleware($made);
        self::assertSame('through', (string) $router->handle(new Request('GET', '/'))->getBody());

        self::assertSame([1, "{$file} already exists.\n"], $run('RequireAdmin'));
        self::assertSame(1, $run()[0]);
        self::assertSame(1, $run('not a class')[0]);
        [$status] = $run('Cors', '--path=src/Http/Middleware', '--namespace=Shop\\Http\\Middleware');
        self::assertSame(0, $status);
        self::assertStringContainsString(
            "namespace Shop\\Http\\Middleware;\n",
            (string) file_get_contents($this->directory . '/src/Http/Middleware/Cors.php'),
        );

        unlink($file);
        unlink($this->directory . '/src/Http/Middleware/Cors.php');
        foreach (['/app/Http', '/app', '/src/Http/Middleware', '/src/Http', '/src'] as $folder) {
            rmdir($this->directory . $folder);
        }
    }

    public function testTheApplicationSaysItIsUpWhereItIsAskedTo(): void
    {
        $app = App::configure()->withRouting(health: '/up')->create();
        $up = $app->handle(new Request('GET', '/up'));
        self::assertSame([200, '{"status":"up"}', 'application/json'], [
            $up->getStatusCode(),
            (string) $up->getBody(),
            $up->getHeaderLine('Content-Type'),
        ]);
        self::assertSame(405, $app->handle(new Request('POST', '/up'))->getStatusCode());
        self::assertSame(['GET', '/up', \Crab\Http\Health::class], [
            $app->router->routes()[0]->method,
            $app->router->routes()[0]->pattern,
            $app->router->routes()[0]->action,
        ]);

        self::assertSame(
            404,
            App::configure()->create()->handle(new Request('GET', '/up'))->getStatusCode(),
            'Only where it is asked to.',
        );
        self::assertSame(
            200,
            App::configure()
                ->withRouting(health: '/up')
                ->withRouting(health: '/healthz')
                ->create()
                ->handle(new Request('GET', '/healthz'))
                ->getStatusCode(),
        );
    }

    public function testEveryStepIsOptional(): void
    {
        $app = App::configure()->create();
        self::assertSame(404, $app->handle(new Request('GET', '/'))->getStatusCode());
        self::assertNull($app->config->get('app.name'));

        $withoutEnvFile = App::configure()
            ->withEnv($this->directory . '/absent.env')
            ->withConfig($this->directory . '/config')
            ->create();
        self::assertSame(
            'Built',
            $withoutEnvFile->config->get('app.name'),
            'A missing .env is not an error; the real environment may hold the settings.',
        );
    }

    public function testAFileThatIsNotThereIsNamedBeforeAnythingIsBuilt(): void
    {
        $before = App::current();
        try {
            App::configure()
                ->withConfig($this->directory . '/config')
                ->withRouting(routes: $this->directory . '/missing.php')
                ->create();
            self::fail('A missing route file was ignored.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('/missing.php to load, and there is no such file', $error->getMessage());
        }
        self::assertSame($before, App::current(), 'No half-built application was left as the current one.');

        $this->expectException(\InvalidArgumentException::class);
        App::configure()
            ->withConfig($this->directory . '/nowhere')
            ->create();
    }
}
