<?php

declare(strict_types=1);

namespace Crab\Tests\Integration;

use Crab\App;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Definition;
use Crab\Contracts\Definitions;
use Crab\Contracts\Development;
use Crab\Contracts\Http\DecodePolicies;
use Crab\Contracts\Http\DecodePolicy;
use Crab\Contracts\Http\Decoding;
use Crab\Contracts\Http\ErrorRenderer;
use Crab\Contracts\Http\Errors;
use Crab\Contracts\Http\Problem;
use Crab\Contracts\Http\Representation;
use Crab\Contracts\Http\Representations;
use Crab\Contracts\Http\Request as RequestContract;
use Crab\Contracts\Invocation;
use Crab\Contracts\Plugin;
use Crab\Contracts\Routing\Entered;
use Crab\Contracts\Routing\Router;
use Crab\Http\Request;
use Crab\Http\Response;
use Crab\Routing\FromQuery;
use Crab\Validation\ListOf;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * What a package is given to declare beside the routes, to write the
 * errors of its own protocol and to have its requests read strictly,
 * and that an application using none of it behaves as before.
 */
final class PackageContractsTest extends TestCase
{
    public function testADefinitionIsFinishedOnceAfterEverythingIsDeclared(): void
    {
        $definition = new RecordingDefinition();
        $app = new App();
        $app->register(new class($definition) implements Plugin {
            public function __construct(
                private RecordingDefinition $definition,
            ) {}

            public function register(Container $container): void
            {
                $container->get(Definitions::class)->add($this->definition);
            }
        });
        $app->router->get('/late', static fn(): string => 'declared after the plugin');
        self::assertSame(0, $definition->finished);

        $app->define();
        $app->define();
        $response = $app->handle(new Request('GET', '/from-definition'));

        self::assertSame(1, $definition->finished);
        // It saw the route declared after its plugin, and added one of its own.
        self::assertSame(['GET /late'], $definition->seen);
        self::assertSame('added when finished', (string) $response->getBody());
        self::assertSame(['recorded' => ['page home /']], $app->declared());
    }

    public function testADefinitionsEntriesAreCheckedWithTheApplicationsOwn(): void
    {
        $app = new App();
        $app->container->get(Definitions::class)->add(new RecordingDefinition([
            'App\\Missing\\PageServer' => 'Page home (resources/pages/Home.psx)',
        ]));

        $problems = $app->unmade();

        self::assertCount(1, $problems);
        self::assertStringStartsWith('Page home (resources/pages/Home.psx): ', $problems[0]);
    }

    public function testADefinitionAddedAfterTheApplicationIsDefinedIsRefused(): void
    {
        $app = new App();
        $app->define();

        $this->expectException(\LogicException::class);
        $app->container->get(Definitions::class)->add(new RecordingDefinition());
    }

    public function testARepresentationWritesTheErrorsOfItsOwnRequestsAndNoOthers(): void
    {
        $app = new App();
        $app->container->get(Representations::class)->add(new class implements Representation {
            public function render(Problem $problem, RequestContract $request): ?ResponseInterface
            {
                return (
                    $request->getHeaderLine('X-Proto') === ''
                        ? null
                        : Response::json([
                            'proto' => $problem->status,
                            'login' => $problem->context['login'] ?? null,
                        ], $problem->status)
                );
            }
        });
        $app->router->get('/guarded', static fn(): string => 'in')->middleware(new Refuse());

        $own = $app->handle(new Request('GET', '/nowhere', ['X-Proto' => '1']));
        $guarded = $app->handle(new Request('GET', '/guarded', ['X-Proto' => '1']));
        $other = $app->handle(new Request('GET', '/nowhere', ['Accept' => 'application/json']));

        self::assertSame('{"proto":404,"login":null}', (string) $own->getBody());
        // What middleware gave the error is kept, and its context reaches the representation.
        self::assertSame(['proto' => 401, 'login' => '/login'], json_decode((string) $guarded->getBody(), true));
        self::assertSame('Basic', $guarded->getHeaderLine('WWW-Authenticate'));
        self::assertSame('{"error":{"status":404,"message":"Not Found"}}', (string) $other->getBody());
    }

    public function testARepresentationAndTheApplicationsRendererLiveSideBySide(): void
    {
        $app = new App();
        $app->container->singleton(ErrorRenderer::class, static fn(): ErrorRenderer => new class implements
            ErrorRenderer {
            public function render(Problem $problem, RequestContract $request): ResponseInterface
            {
                return Response::text("app {$problem->status}", $problem->status);
            }
        });
        $app->container->get(Representations::class)->add(new class implements Representation {
            public function render(Problem $problem, RequestContract $request): ?ResponseInterface
            {
                return $request->getHeaderLine('X-Proto') === '' ? null : Response::text('proto', $problem->status);
            }
        });

        self::assertSame('proto', (string) $app->handle(new Request('GET', '/x', ['X-Proto' => '1']))->getBody());
        self::assertSame('app 404', (string) $app->handle(new Request('GET', '/x'))->getBody());
    }

    public function testADecodePolicyMarksItsRequestsBeforeAnyMiddlewareReadsThem(): void
    {
        $app = new App();
        $app->container->get(DecodePolicies::class)->add(new class implements DecodePolicy {
            public function select(RequestContract $request): ?Decoding
            {
                return str_contains($request->getUri()->getPath(), '/_strict/') ? new Decoding() : null;
            }
        });
        $read = static fn(#[FromQuery] string $a): string => $a;
        $app->router->get('/_strict/read', $read)->middleware(new ReadsQuery());
        $app->router->get('/loose/read', $read)->middleware(new ReadsQuery());

        $strict = $app->handle(new Request('GET', '/_strict/read?a=1&a=2'));
        $loose = $app->handle(new Request('GET', '/loose/read?a=1&a=2'));

        self::assertSame(400, $strict->getStatusCode());
        self::assertSame('2', (string) $loose->getBody());
    }

    public function testARequestReadStrictlyKeepsAnObjectApartFromAListAndAWholeNumberExact(): void
    {
        $app = new App();
        $app->container->get(DecodePolicies::class)->add(new class implements DecodePolicy {
            public function select(RequestContract $request): ?Decoding
            {
                return str_starts_with($request->getUri()->getPath(), '/strict/') ? new Decoding() : null;
            }
        });
        $entered = 0;
        $save = static function (int $id, StrictInput $input, #[FromQuery] int $page = 1) use (&$entered): array {
            ++$entered;
            return [
                'id' => $id,
                'page' => $page,
                'child' => $input->child->name,
                'items' => $input->items,
                'count' => $input->count,
                'ratio' => $input->ratio,
                'any' => $input->any,
                'loose' => $input->loose,
            ];
        };
        $app->router->post('/strict/{id}', $save);
        $app->router->post('/loose/{id}', $save);
        $send = static fn(string $path, string $json): ResponseInterface => $app->handle(
            new Request(
                'POST',
                $path,
                [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                $json,
            ),
        );
        $fields = static fn(ResponseInterface $response): array => array_keys(
            json_decode((string) $response->getBody(), true)['error']['fields'] ?? [],
        );

        $valid = $send(
            '/strict/9007199254740991?page=-9007199254740991',
            '{"child":{},"items":[],"count":-9007199254740991,"ratio":9007199254740991,"any":{"0":{"1":[]}},"loose":{"0":"a"}}',
        );
        self::assertSame(200, $valid->getStatusCode(), (string) $valid->getBody());
        self::assertSame(
            [
                'id' => 9_007_199_254_740_991,
                'page' => -9_007_199_254_740_991,
                'child' => 'default',
                'items' => [],
                'count' => -9_007_199_254_740_991,
                // As JSON writes a number with no fraction.
                'ratio' => 9_007_199_254_740_991,
                // Code is given arrays, as it always was.
                'any' => [[1 => []]],
                'loose' => ['a'],
            ],
            json_decode((string) $valid->getBody(), true),
        );
        self::assertSame(1, $entered);

        foreach ([
            'a list for an object' => ['{"child":[]}', ['child']],
            'a list with items for an object' => ['{"child":["x"]}', ['child']],
            'an object for a list' => ['{"items":{}}', ['items']],
            'a numbered object for a list' => ['{"items":{"0":7}}', ['items']],
            'the same, deeper' => ['{"child":{"tags":{"0":"a"}}}', ['child.tags']],
            'a list for an object in a list' => ['{"children":[[]]}', ['children.0']],
            'a whole number past what is exact' => ['{"count":9007199254740992}', ['count']],
            'one below it' => ['{"count":-9007199254740992}', ['count']],
            'one for a number with a fraction' => ['{"ratio":9007199254740993}', ['ratio']],
            'one in a list' => ['{"items":[1,9007199254740993]}', ['items.1']],
        ] as $what => [$json, $expected]) {
            $refused = $send('/strict/1', $json);
            self::assertSame(422, $refused->getStatusCode(), $what);
            self::assertSame($expected, $fields($refused), $what);
        }
        self::assertSame(422, $send('/strict/9007199254740992', '{}')->getStatusCode());
        self::assertSame(422, $send('/strict/1?page=9007199254740992', '{}')->getStatusCode());
        self::assertSame(1, $entered);

        // The ordinary reading is what it was: PHP's arrays and PHP's integers.
        $loose = $send(
            '/loose/9007199254740993?page=9007199254740993',
            '{"child":[],"items":{"0":7},"count":9007199254740993}',
        );
        self::assertSame(200, $loose->getStatusCode(), (string) $loose->getBody());
        self::assertSame([7], json_decode((string) $loose->getBody(), true)['items']);
        self::assertSame(9_007_199_254_740_993, json_decode((string) $loose->getBody(), true)['count']);
        self::assertSame(2, $entered);
    }

    public function testAnEnumOfWholeNumbersIsHeldToTheSameBoundsWhenReadStrictly(): void
    {
        $app = new App();
        $app->container->get(DecodePolicies::class)->add(new class implements DecodePolicy {
            public function select(RequestContract $request): ?Decoding
            {
                return str_starts_with($request->getUri()->getPath(), '/strict') ? new Decoding() : null;
            }
        });
        $entered = 0;
        $choose = static function (StrictSizes $input, #[FromQuery] ?StrictSize $size = null) use (&$entered): array {
            ++$entered;
            return [
                'size' => $input->size?->name,
                'sizes' => array_map(static fn(StrictSize $size): string => $size->name, $input->sizes),
                'inner' => $input->inner?->size?->name,
                'query' => $size?->name,
            ];
        };
        $app->router->post('/strict', $choose);
        $app->router->post('/loose', $choose);
        $json = static fn(string $path, string $body): ResponseInterface => $app->handle(
            new Request(
                'POST',
                $path,
                [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                $body,
            ),
        );
        $form = static fn(string $path, string $body): ResponseInterface => $app->handle(
            new Request(
                'POST',
                $path,
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
                $body,
            ),
        );

        $valid = $json(
            '/strict?size=-9007199254740991',
            '{"size":9007199254740991,"sizes":[1,-9007199254740991],"inner":{"size":1}}',
        );
        self::assertSame(
            ['size' => 'Edge', 'sizes' => ['Small', 'Low'], 'inner' => 'Small', 'query' => 'Low'],
            json_decode((string) $valid->getBody(), true),
        );
        self::assertSame(200, $form('/strict', 'size=9007199254740991&sizes[0]=1')->getStatusCode());
        self::assertSame(2, $entered);

        foreach ([
            'the field' => $json('/strict', '{"size":9007199254740993}'),
            'in a list' => $json('/strict', '{"sizes":[1,9007199254740993]}'),
            'nested' => $json('/strict', '{"inner":{"size":9007199254740993}}'),
            'the query string' => $json('/strict?size=9007199254740993', '{}'),
            'a form' => $form('/strict', 'size=9007199254740993'),
            'a form\'s list' => $form('/strict', 'sizes[0]=9007199254740993'),
        ] as $what => $refused) {
            self::assertSame(422, $refused->getStatusCode(), $what);
            self::assertStringContainsString(
                'must be a whole number from -9007199254740991 to 9007199254740991',
                (string) $refused->getBody(),
                $what,
            );
        }
        self::assertSame(2, $entered);

        // Read the ordinary way, an enum is what PHP says it is.
        $loose = $json('/loose?size=9007199254740993', '{"size":9007199254740993,"sizes":[9007199254740993]}');
        self::assertSame(
            ['size' => 'Big', 'sizes' => ['Big'], 'inner' => null, 'query' => 'Big'],
            json_decode((string) $loose->getBody(), true),
        );
        self::assertSame(3, $entered);
    }

    public function testAFormReadStrictlyHoldsWholeNumbersToTheSameBounds(): void
    {
        $app = new App();
        $app->container->get(DecodePolicies::class)->add(new class implements DecodePolicy {
            public function select(RequestContract $request): ?Decoding
            {
                return new Decoding();
            }
        });
        $entered = 0;
        $app->router->post('/form', static function (StrictInput $input) use (&$entered): array {
            ++$entered;
            return ['count' => $input->count, 'items' => $input->items, 'child' => $input->child->name];
        });
        $send = static fn(string $body): ResponseInterface => $app->handle(
            new Request(
                'POST',
                '/form',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                $body,
            ),
        );

        $valid = $send('count=9007199254740991&items[0]=-9007199254740991&child[name]=x');
        self::assertSame(
            ['count' => 9_007_199_254_740_991, 'items' => [-9_007_199_254_740_991], 'child' => 'x'],
            json_decode((string) $valid->getBody(), true),
        );
        self::assertSame(422, $send('count=9007199254740992')->getStatusCode());
        self::assertSame(422, $send('items[0]=-9007199254740992')->getStatusCode());
        self::assertSame(1, $entered);
    }

    public function testWhetherTheActionWasEnteredIsRecordedForWhoeverWritesTheError(): void
    {
        $app = new App();
        $seen = new \ArrayObject();
        $app->container->get(Representations::class)->add(new class($seen) implements Representation {
            public function __construct(
                private \ArrayObject $seen,
            ) {}

            public function render(Problem $problem, RequestContract $request): ?ResponseInterface
            {
                $record = $request->getAttribute(Entered::ATTRIBUTE);
                $this->seen[] = [$problem->status, $record instanceof Entered ? $record->entered() : null];
                return null;
            }
        });
        $writes = 0;
        $write = static function (#[FromQuery] int $status = 200) use (&$writes): string {
            ++$writes;
            if ($status !== 200) {
                throw new \Crab\Http\HttpException($status, 'After the write.');
            }
            return 'written';
        };
        $app->router->post('/write', $write);
        $app->router->post('/guarded', $write)->middleware(new Refuse());
        $app->router->post('/replaced', $write)->middleware(new FailsAfter(throws: false));
        $app->router->post('/thrown', $write)->middleware(new FailsAfter(throws: true));
        $app->router->post('/early', $write)->middleware(new ThrowsBefore());

        $statuses = [];
        foreach ([
            '/nowhere',
            '/guarded',
            '/early',
            '/write?status=many',
            '/write?status=422',
            '/write?status=409',
            '/write?status=500',
            '/replaced',
            '/thrown',
        ] as $path) {
            $statuses[] = $app->handle(new Request('POST', $path))->getStatusCode();
        }

        self::assertSame([404, 401, 403, 422, 422, 409, 500, 422, 503], $statuses);
        self::assertSame(
            [
                // Nothing was called: no route, a guard's refusal, a guard that threw, input that did not bind.
                [404, false],
                [401, false],
                [403, false],
                [422, false],
                // The action was called and wrote, whatever status it then threw, or a middleware gave after it.
                [422, true],
                [409, true],
                [500, true],
                [422, true],
                [503, true],
            ],
            $seen->getArrayCopy(),
        );
        self::assertSame(5, $writes);
    }

    public function testTheRouterSaysWhichRouteARequestIsForWithoutRunningIt(): void
    {
        $app = new App();
        $ran = 0;
        $action = static function () use (&$ran): string {
            ++$ran;
            return 'ran';
        };
        $app->router->get('/orders', $action)->name('orders');
        $app->router->get('/orders/{id:\d+}', $action)->name('order');
        $app->router->post('/orders/{id:\d+}', $action)->name('save');
        $app->router->get('/files/{path:.+}', $action)->name('file');
        $app->router->get('/café', $action)->name('cafe');
        $router = $app->container->get(Router::class);

        self::assertSame('orders', $router->match('GET', '/orders')?->name);
        self::assertSame('order', $router->match('GET', '/orders/12')?->name);
        self::assertSame('order', $router->match('HEAD', '/orders/12')?->name);
        self::assertSame('save', $router->match('POST', '/orders/12')?->name);
        self::assertSame('file', $router->match('GET', '/files/a/b.txt')?->name);
        self::assertSame('cafe', $router->match('GET', '/caf%C3%A9')?->name);
        self::assertNull($router->match('GET', '/orders/twelve'));
        self::assertNull($router->match('DELETE', '/orders/12'));
        self::assertNull($router->match('GET', '/nowhere'));
        self::assertSame(0, $ran);
    }

    public function testAnApplicationWithoutThemHasNoWrapperInFrontOfItsMiddleware(): void
    {
        $app = new App();
        $app->router->get('/', static fn(): string => 'plain');

        self::assertSame('plain', (string) $app->handle(new Request('GET', '/'))->getBody());
        self::assertSame([], $app->declared());
        $entry = new \ReflectionProperty($app, 'entry');
        self::assertInstanceOf(\Crab\Http\MiddlewareStack::class, $entry->getValue($app));
    }

    public function testAPackageMakesErrorsThatCarryTheirProblem(): void
    {
        $errors = new App()->container->get(Errors::class);

        $response = $errors->make(new Request('GET', '/'), 409, 'Stale.', context: ['build' => 'b2']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('Stale.', $errors->problem($response)?->message);
        self::assertSame(['build' => 'b2'], $errors->problem($response)?->context);
        self::assertNull($errors->problem(Response::text('fine')));
    }

    public function testTheInvocationIsWhatTheConsoleSaidAndNothingOutsideIt(): void
    {
        self::assertNull(new App()->container->get(Invocation::class)->command);

        App::invoked(new Invocation('psx:build', ['--release=build/x', '--quiet'], development: true));
        try {
            $invocation = new App()->container->get(Invocation::class);
        } finally {
            App::invoked(null);
        }

        self::assertSame('psx:build', $invocation->command);
        self::assertSame('build/x', $invocation->option('release'));
        self::assertNull($invocation->option('quiet'));
        self::assertTrue($invocation->development);
    }

    public function testWhatDevIsAskedToDoIsKeptForIt(): void
    {
        $app = new App();
        $development = $app->container->get(Development::class);

        $development->watch('resources', 'psx', 'tsx');
        $development->watch('resources', 'css', 'psx');
        $development->prepare('compile', static fn(?array $changed): bool => $changed !== null);
        $development->process('vite', 'npx vite');

        self::assertSame(['resources' => ['psx', 'tsx', 'css']], $app->development->watched());
        self::assertFalse($app->development->steps()['compile'](null));
        self::assertSame('npx vite', $app->development->processes()['vite'][0]);
        $this->expectException(\InvalidArgumentException::class);
        $development->watch('resources', '.psx');
    }
}

final class RecordingDefinition implements Definition
{
    public int $finished = 0;
    /**
     * @var list<string>
     */
    public array $seen = [];

    /**
     * @param array<class-string, string> $entries
     */
    public function __construct(
        private readonly array $entries = [],
    ) {}

    public function name(): string
    {
        return 'recorded';
    }

    public function finish(): void
    {
        ++$this->finished;
        $router = App::current()->container->get(Router::class);
        foreach ($router->routes() as $route) {
            $this->seen[] = "{$route->method} {$route->pattern}";
        }
        $router->get('/from-definition', static fn(): string => 'added when finished');
    }

    public function entries(): array
    {
        return $this->entries;
    }

    public function describe(): array
    {
        return ['page home /'];
    }
}

final class Refuse implements \Crab\Contracts\Http\Middleware
{
    public function handle(RequestContract $request, \Crab\Contracts\Http\Handler $next): ResponseInterface
    {
        return \Crab\Http\ErrorResponse::make(
            $request,
            401,
            headers: ['WWW-Authenticate' => 'Basic'],
            context: ['login' => '/login'],
        );
    }
}

final class ReadsQuery implements \Crab\Contracts\Http\Middleware
{
    public function handle(RequestContract $request, \Crab\Contracts\Http\Handler $next): ResponseInterface
    {
        // Middleware that reads first gets the same reading the action does.
        $request->getQueryParams();
        return $next->handle($request);
    }
}

final class FailsAfter implements \Crab\Contracts\Http\Middleware
{
    public function __construct(
        private readonly bool $throws,
    ) {}

    public function handle(RequestContract $request, \Crab\Contracts\Http\Handler $next): ResponseInterface
    {
        $next->handle($request);
        if ($this->throws) {
            throw new \Crab\Http\HttpException(503, 'After the action.');
        }
        return \Crab\Http\ErrorResponse::make($request, 422, 'In place of what the action answered.');
    }
}

final class ThrowsBefore implements \Crab\Contracts\Http\Middleware
{
    public function handle(RequestContract $request, \Crab\Contracts\Http\Handler $next): ResponseInterface
    {
        throw new \Crab\Http\HttpException(403, 'Before the action.');
    }
}

final readonly class StrictChild
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $name = 'default',
        #[ListOf('string')]
        public array $tags = [],
    ) {}
}

final readonly class StrictInput
{
    /**
     * @param list<int> $items
     * @param list<StrictChild> $children
     * @param array<mixed> $loose
     */
    public function __construct(
        public StrictChild $child = new StrictChild(),
        #[ListOf('int')]
        public array $items = [],
        #[ListOf(StrictChild::class)]
        public array $children = [],
        public int $count = 0,
        public float $ratio = 0.0,
        public mixed $any = null,
        public array $loose = [],
    ) {}
}

enum StrictSize: int
{
    case Small = 1;
    case Low = -9_007_199_254_740_991;
    case Edge = 9_007_199_254_740_991;
    case Big = 9_007_199_254_740_993;
}

final readonly class StrictSizeInner
{
    public function __construct(
        public ?StrictSize $size = null,
    ) {}
}

final readonly class StrictSizes
{
    /**
     * @param list<StrictSize> $sizes
     */
    public function __construct(
        public ?StrictSize $size = null,
        #[ListOf(StrictSize::class)]
        public array $sizes = [],
        public ?StrictSizeInner $inner = null,
    ) {}
}
