<?php

declare(strict_types=1);

namespace Crab\Tests\Console;

use Crab\App;
use Crab\Config\Config;
use Crab\Console\Output;
use Crab\Contracts\Auth\Identity;
use Crab\Http\Cors;
use Crab\Validation\Data;
use Crab\Validation\Email;
use Crab\Validation\Fields;
use Crab\Validation\FileSize;
use Crab\Validation\Length;
use Crab\Validation\ListOf;
use Crab\Validation\MediaType;
use Crab\Validation\Range;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

final class DescribeTest extends TestCase
{
    public function testRoutesAreDescribedWithTheirInputAsJsonSchema(): void
    {
        $app = new App();
        $app->router->get('/posts/{id}', static fn(#[Range(min: 1)] int $id, ?DescribedUser $user): array => []);
        $app
            ->router
            ->post('/users', static fn(
                #[Email]
                string $email,
                #[Length(min: 2)]
                string $name,
                DescribedRole $role = DescribedRole::Reader,
            ): array => [])
            ->name('users.create');
        $app->router->post('/orders', static fn(DescribedOrder $order, DescribedUser $user): array => []);
        $app->router->get('/search', static fn(
            #[Fields(['q' => 'string', 'page?' => 'int'])]
            Data $search,
        ): array => []);

        $routes = $this->describe($app, 'routes')['routes'];

        self::assertSame(
            [
                'parameters' => ['id' => ['type' => 'integer', 'minimum' => 1]],
                'user' => ['class' => DescribedUser::class, 'required' => false],
            ],
            $routes['GET /posts/{id}']['input'],
        );
        self::assertSame('users.create', $routes['POST /users']['name']);
        self::assertSame(
            [
                'type' => 'object',
                'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'name' => ['type' => 'string', 'minLength' => 2],
                    'role' => [
                        'type' => 'string',
                        'enum' => ['reader', 'editor'],
                        'x-class' => DescribedRole::class,
                        'default' => 'reader',
                    ],
                ],
                'required' => ['email', 'name'],
            ],
            $routes['POST /users']['input']['body'],
        );
        self::assertSame(
            [
                'x-class' => DescribedOrder::class,
                'type' => 'object',
                'properties' => [
                    'lines' => [
                        'type' => 'array',
                        'items' => [
                            'x-class' => DescribedLine::class,
                            'type' => 'object',
                            'properties' => [
                                'sku' => ['type' => 'string'],
                                'quantity' => ['type' => 'integer', 'minimum' => 1],
                            ],
                            'required' => ['sku', 'quantity'],
                        ],
                        'minItems' => 1,
                    ],
                    'note' => ['type' => ['string', 'null'], 'default' => null],
                ],
                'required' => ['lines'],
            ],
            $routes['POST /orders']['input']['body'],
        );
        self::assertSame(
            ['class' => DescribedUser::class, 'required' => true],
            $routes['POST /orders']['input']['user'],
        );
        self::assertSame(
            [
                'type' => 'object',
                'properties' => ['q' => ['type' => 'string'], 'page' => ['type' => ['integer', 'null']]],
                'required' => ['q'],
            ],
            $routes['GET /search']['input']['query'],
        );
    }

    public function testTheRestOfTheApplicationIsDescribedAndSecretsAreHidden(): void
    {
        $app = new App(new Config([
            'app' => ['name' => 'Shop'],
            'database' => [
                'connections' => ['main' => ['dsn' => 'mysql:host=db;dbname=shop', 'password' => 'hunter2']],
            ],
            'mail' => ['api_key' => 'sk-live', 'key' => 'base64:abc', 'keyboard' => 'qwerty', 'token' => null],
        ]));
        $app->container->get(\Crab\Contracts\Http\MiddlewareStack::class)->add(new Cors(['https://shop.example']));
        $app->console->add('shop:sync', static fn(): int => 0, "Sync the shop\n\nRuns nightly.");
        $app->register(\Crab\Events\EventsProvider::class, \Crab\Schedule\ScheduleProvider::class);
        $schedule = $app->container->get(\Crab\Contracts\Schedule\Schedule::class);
        $schedule->command('shop:sync')->daily('03:00')->onOneServer()->withoutOverlapping();
        $schedule
            ->call(static fn() => null)
            ->name('heartbeat')
            ->everySeconds(5);
        $events = $app->container->get(\Crab\Contracts\Events\Dispatcher::class);
        $events->listen(DescribedOrder::class, DescribedListener::class);
        $events->listen(DescribedOrder::class, static fn() => null);

        $described = $this->describe($app);

        self::assertSame(
            ['app', 'routes', 'middleware', 'commands', 'schedule', 'listeners', 'servers', 'database', 'config'],
            array_keys($described),
        );
        self::assertSame(
            [
                [
                    'task' => 'command shop:sync',
                    'when' => '0 3 * * *',
                    'one_server' => true,
                    'without_overlapping' => true,
                ],
                ['task' => 'heartbeat', 'when' => 'every 5s', 'one_server' => false, 'without_overlapping' => false],
            ],
            $described['schedule'],
        );
        self::assertSame([DescribedOrder::class => [DescribedListener::class, 'Closure']], $described['listeners']);
        self::assertSame('Shop', $described['app']['name']);
        self::assertSame(Cors::class, $described['middleware'][array_key_last($described['middleware'])]);
        self::assertSame('Sync the shop', $described['commands']['shop:sync'], 'The first line; --help has the rest.');
        self::assertSame(
            [
                'default' => 'main',
                'connections' => ['main' => ['driver' => 'mysql', 'migrations' => null, 'files' => []]],
            ],
            $described['database'],
        );
        self::assertSame(
            ['dsn' => 'mysql:host=db;dbname=shop', 'password' => '(hidden)'],
            $described['config']['database']['connections']['main'],
        );
        self::assertSame(
            ['api_key' => '(hidden)', 'key' => '(hidden)', 'keyboard' => 'qwerty', 'token' => null],
            $described['config']['mail'],
            'A secret that is not set is shown as not set.',
        );
    }

    public function testTheDefaultIsTextWithInputsInTypeScriptNotation(): void
    {
        $app = new App();
        $app->router->get('/posts/{id}', static fn(#[Range(min: 1)] int $id, ?DescribedUser $user): array => []);
        $app
            ->router
            ->post('/orders', static fn(DescribedOrder $order, DescribedUser $user): array => [])
            ->name('orders.place');
        $app->router->post('/photos', static fn(string $title, #[ListOf(UploadedFileInterface::class)]
            #[Length(max: 5)]
            #[FileSize(max: 1_048_576)]
            #[MediaType('image/*')] array $photos): null => null);
        $app->router->get('/', static fn(): string => 'hi');
        $stream = fopen('php://memory', 'w+b');
        self::assertSame(0, $app->console->run(['describe', 'routes'], new Output($stream, $stream)));
        rewind($stream);
        $user = DescribedUser::class;

        $text = (string) stream_get_contents($stream);
        self::assertStringStartsWith(
            '# The application, from `php cua describe`. Types are TypeScript notation',
            $text,
        );
        self::assertStringEndsWith(<<<TEXT
            routes
              GET /posts/{id} -> Closure
                {id}: int (minimum=1)
                user: {$user} | null
              POST /orders -> Closure name=orders.place
                body { lines: Array<{ sku: string; quantity: int (minimum=1) }> (minItems=1); note?: string | null (default=null) }
                user: {$user}
              POST /photos -> Closure
                body { title: string; photos: Array<file (maxBytes=1048576, mediaTypes=["image/*"])> (maxItems=5) }
              GET / -> Closure
                takes nothing

            TEXT, $text);
    }

    public function testSettingsThatDoNotFitAreSaidFirst(): void
    {
        $app = new App(new Config(['server' => ['workers' => 0], 'log' => ['level' => 'loud']]));
        self::assertSame(
            [
                'log.level is "loud"; it takes "debug", "info", "notice", "warning", "error", "critical", "alert" or "emergency".',
                'server.workers is 0; it takes a whole number, at least 1.',
            ],
            $this->describe($app, 'app')['app']['problems'],
        );
        self::assertArrayNotHasKey('problems', $this->describe(new App(), 'app')['app']);
        $stream = fopen('php://memory', 'w+b');
        self::assertSame(0, $app->console->run(['describe', 'app'], new Output($stream, $stream)));
        rewind($stream);
        self::assertStringContainsString(
            "path none\n  does not fit: log.level is \"loud\"; it takes",
            (string) stream_get_contents($stream),
        );
    }

    public function testAnUnknownSectionIsRefused(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertSame(1, new App()->console->run(['describe', 'routes', 'nope'], new Output($stream, $stream)));
        rewind($stream);
        self::assertStringContainsString('Unknown section nope', (string) stream_get_contents($stream));
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(App $app, string ...$sections): array
    {
        $stream = fopen('php://memory', 'w+b');
        try {
            self::assertSame(0, $app->console->run(['describe', ...$sections, '--json'], new Output($stream, $stream)));
            rewind($stream);
            return json_decode((string) stream_get_contents($stream), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            fclose($stream);
        }
    }
}

final readonly class DescribedUser implements Identity
{
    public function __construct(
        public int|string $id,
    ) {}
}

enum DescribedRole: string
{
    case Reader = 'reader';
    case Editor = 'editor';
}

final readonly class DescribedLine
{
    public function __construct(
        public string $sku,
        #[Range(min: 1)]
        public int $quantity,
    ) {}
}

final readonly class DescribedOrder
{
    /**
     * @param list<DescribedLine> $lines
     */
    public function __construct(
        #[ListOf(DescribedLine::class), Length(min: 1)]
        public array $lines,
        public ?string $note = null,
    ) {}
}

final readonly class DescribedListener
{
    public function handle(DescribedOrder $order): void {}
}
