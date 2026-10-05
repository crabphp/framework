<?php

declare(strict_types=1);

namespace Crab\Tests;

use Crab\App;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Provider;
use Crab\Http\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Greeting
{
    public function __construct(public string $text)
    {
    }
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
    public function __construct(private string $mark = 'class')
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        return $response->withAddedHeader('X-Mark', $this->mark);
    }
}

final class AppBuilderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crab-builder-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/config', recursive: true);
        file_put_contents($this->directory . '/.env', "CRAB_BUILDER_GREETING=\"Hello from env\"\n");
        file_put_contents($this->directory . '/config/app.php', "<?php return ['name' => 'Built', 'greeting' => env('CRAB_BUILDER_GREETING', 'unset')];");
        file_put_contents($this->directory . '/web.php', "<?php use Crab\\Route; Route::get('/', static fn (): string => config('app.name'));");
        file_put_contents($this->directory . '/api.php', "<?php use Crab\\Route; Route::get('/api', static fn (): array => ['leaked' => isset(\$app) || isset(\$this) || isset(\$file)]);");
        file_put_contents($this->directory . '/console.php', "<?php use Crab\\Command; Command::add('hello', static fn (): int => 0, 'Say hello');");
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
            ->withRouting(commands: $this->directory . '/console.php', routes: [$this->directory . '/web.php', $this->directory . '/api.php'])
            ->withMiddleware(Mark::class)
            ->withMiddleware(new Mark('object'))
            ->withProviders(GreetingProvider::class)
            ->withConfig($this->directory . '/config')
            ->withEnv($this->directory . '/.env')
            ->create();

        self::assertSame($app, App::current());
        self::assertSame('Hello from env', $app->container->get(Greeting::class)->text);
        $home = $app->handle(new Request('GET', '/'));
        self::assertSame(['Built', 'object, class'], [(string) $home->getBody(), $home->getHeaderLine('X-Mark')], 'The first middleware given is outermost.');
        self::assertSame('{"leaked":false}', (string) $app->handle(new Request('GET', '/api'))->getBody(), 'A route file sees no variable it did not define.');
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
        self::assertSame('Hello from env', $app->container->get(Greeting::class)->text, '.env and config/ in the base path are loaded without being named.');
        $home = $app->handle(new Request('GET', '/'));
        self::assertSame(['Built', 'object, class'], [(string) $home->getBody(), $home->getHeaderLine('X-Mark')]);
        self::assertSame(200, $app->handle(new Request('GET', '/api'))->getStatusCode());
        self::assertTrue($app->console->has('hello'));
        self::assertSame(404, App::configure($this->directory)->withRouting(commands: 'console.php')->create()->handle(new Request('GET', '/'))->getStatusCode(), 'Either may be left out.');

        self::assertSame($this->directory, $app->path());
        self::assertSame($this->directory . '/storage/app.log', $app->path('storage/app.log'));
        self::assertSame(['/var/log/app.log', 'php://stderr', 'C:\\logs\\app.log'], [$app->path('/var/log/app.log'), $app->path('php://stderr'), $app->path('C:\\logs\\app.log')]);

        mkdir($this->directory . '/settings');
        file_put_contents($this->directory . '/settings/app.php', "<?php return ['name' => 'Other'];");
        file_put_contents($this->directory . '/other.env', "CRAB_BUILDER_OTHER=1\n");
        $other = App::configure($this->directory)->withConfig('settings')->withEnv('other.env')->create();
        self::assertSame(['Other', true], [$other->config->get('app.name'), env('CRAB_BUILDER_OTHER') === 1 || env('CRAB_BUILDER_OTHER') === '1']);
        unlink($this->directory . '/settings/app.php');
        unlink($this->directory . '/other.env');
        rmdir($this->directory . '/settings');

        $bare = sys_get_temp_dir() . '/crab-bare-' . bin2hex(random_bytes(4));
        mkdir($bare);
        self::assertNull(App::configure($bare)->create()->config->get('app.name'), 'A base path with no .env and no config folder is still an application.');
        rmdir($bare);

        $mistakes = [
            'is not a directory' => static fn () => App::configure('/no/such/app'),
            'This application has no base path. Give it one with App::configure(__DIR__)' => static fn () => (new App())->path('x'),
            'withProviders() takes providers or their class names, not int' => static fn () => App::configure()->withProviders([1]),
            'withMiddleware() takes PSR-15 middleware or their class names, not stdClass' => static fn () => App::configure()->withMiddleware([new \stdClass()]),
            'withRouting() takes file paths, not int' => static fn () => App::configure()->withRouting(routes: [1]),
            'withRouting() takes file paths, not null' => static fn () => App::configure()->withRouting(commands: [null]),
        ];
        foreach ($mistakes as $expected => $mistake) {
            try {
                $mistake();
                self::fail("Not refused: {$expected}");
            } catch (\LogicException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
        self::assertSame('/x', (new App())->path('/x'), 'An absolute path needs no base path.');
    }

    public function testEveryStepIsOptional(): void
    {
        $app = App::configure()->create();
        self::assertSame(404, $app->handle(new Request('GET', '/'))->getStatusCode());
        self::assertNull($app->config->get('app.name'));

        $withoutEnvFile = App::configure()->withEnv($this->directory . '/absent.env')->withConfig($this->directory . '/config')->create();
        self::assertSame('Built', $withoutEnvFile->config->get('app.name'), 'A missing .env is not an error; the real environment may hold the settings.');
    }

    public function testAFileThatIsNotThereIsNamedBeforeAnythingIsBuilt(): void
    {
        $before = App::current();
        try {
            App::configure()->withConfig($this->directory . '/config')->withRouting(routes: $this->directory . '/missing.php')->create();
            self::fail('A missing route file was ignored.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('/missing.php to load, and there is no such file', $error->getMessage());
        }
        self::assertSame($before, App::current(), 'No half-built application was left as the current one.');

        $this->expectException(\InvalidArgumentException::class);
        App::configure()->withConfig($this->directory . '/nowhere')->create();
    }
}
