<?php

declare(strict_types=1);

namespace Crab\Tests\Routing;

use Crab\Http\Request;
use Crab\Http\Response;
use Crab\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RouterTest extends TestCase
{
    public function testStaticAndParameterRoutes(): void
    {
        $router = new Router();
        $router->get('/', static fn() => 'home');
        $router->get('/users/new', static fn() => 'new user');
        $router->get('/users/{id}', static fn(ServerRequestInterface $request) => 'user ' . $request->getAttribute('id'));
        $router->get('/users/{id}/posts/{post}', static fn(ServerRequestInterface $request) => json_encode($request->getAttributes()));
        $router->post('/users', static fn() => new Response(201));

        self::assertSame('home', self::body($router, 'GET', '/'));
        self::assertSame('new user', self::body($router, 'GET', '/users/new'));
        self::assertSame('user 7', self::body($router, 'GET', '/users/7?page=2'));
        self::assertSame('{"id":"7","post":"a b"}', self::body($router, 'GET', '/users/7/posts/a%20b'));
        self::assertSame(201, $router->handle(new Request('POST', '/users'))->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $router->handle(new Request('GET', '/'))->getHeaderLine('content-type'));
    }

    public function testParameterExpressions(): void
    {
        $router = new Router();
        $router->get('/posts/{id:\d+}', static fn(ServerRequestInterface $request) => 'post ' . $request->getAttribute('id'));
        $router->get('/posts/{slug}', static fn(ServerRequestInterface $request) => 'slug ' . $request->getAttribute('slug'));
        $router->get('/files/{path:.+}', static fn(ServerRequestInterface $request) => 'file ' . $request->getAttribute('path'));
        $router->get('/dates/{year:\d{4}}-{month:(?:0[1-9]|1[0-2])}', static fn(ServerRequestInterface $request) => implode('/', $request->getAttributes()));
        $router->get('/a.b/{x:[~]+}', static fn() => 'quoted');

        self::assertSame('post 42', self::body($router, 'GET', '/posts/42'));
        self::assertSame('slug hello', self::body($router, 'GET', '/posts/hello'));
        self::assertSame('file a/b/c.txt', self::body($router, 'GET', '/files/a/b/c.txt'));
        self::assertSame('2026/10', self::body($router, 'GET', '/dates/2026-10'));
        self::assertSame(404, $router->handle(new Request('GET', '/dates/2026-13'))->getStatusCode());
        self::assertSame('quoted', self::body($router, 'GET', '/a.b/~~'));
        self::assertSame(404, $router->handle(new Request('GET', '/aXb/~'))->getStatusCode());
    }

    public function testUnmatchedRequests(): void
    {
        $router = new Router();
        $router->get('/users/{id}', static fn() => 'show');
        $router->delete('/users/{id}', static fn() => 'delete');
        $router->post('/users', static fn() => 'create');

        $missing = $router->handle(new Request('GET', '/nothing'));
        self::assertSame([404, "404 Not Found\n"], [$missing->getStatusCode(), (string) $missing->getBody()]);
        $json = $router->handle(new Request('PUT', '/users/7', ['Accept' => 'application/json']));
        self::assertSame('{"error":{"status":405,"message":"Method Not Allowed"}}', (string) $json->getBody());
        self::assertSame('GET, DELETE, HEAD', $json->getHeaderLine('Allow'));
        self::assertSame(404, $router->handle(new Request('GET', '/users/7/'))->getStatusCode());

        $wrongMethod = $router->handle(new Request('PUT', '/users/7'));
        self::assertSame([405, 'GET, DELETE, HEAD'], [$wrongMethod->getStatusCode(), $wrongMethod->getHeaderLine('Allow')]);
        self::assertSame('POST', $router->handle(new Request('GET', '/users'))->getHeaderLine('Allow'));
        self::assertSame('show', self::body($router, 'HEAD', '/users/7'));
    }

    public function testManyRoutesSpanSeveralCombinedExpressions(): void
    {
        $router = new Router();
        for ($index = 0; $index < 100; ++$index) {
            $router->get("/r{$index}/{value}", static fn(ServerRequestInterface $request) => $index . ':' . $request->getAttribute('value'));
        }
        self::assertSame('0:a', self::body($router, 'GET', '/r0/a'));
        self::assertSame('31:b', self::body($router, 'GET', '/r31/b'));
        self::assertSame('32:c', self::body($router, 'GET', '/r32/c'));
        self::assertSame('99:d', self::body($router, 'GET', '/r99/d'));
        $router->get('/late/{value}', static fn() => 'late');
        self::assertSame('late', self::body($router, 'GET', '/late/x'));
    }

    public function testHandlerObjectsAndOtherMethods(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::text($request->getMethod() . ' ' . $request->getAttribute('id'));
            }
        };
        $router = new Router();
        $router->put('/items/{id}', $handler);
        $router->patch('/items/{id}', $handler);
        $router->add('PURGE', '/items/{id}', $handler);
        self::assertSame('PUT 1', self::body($router, 'PUT', '/items/1'));
        self::assertSame('PATCH 2', self::body($router, 'PATCH', '/items/2'));
        self::assertSame('PURGE 3', self::body($router, 'PURGE', '/items/3'));
    }

    #[DataProvider('invalidPatternProvider')]
    public function testInvalidPatternsAreRejected(string $pattern): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Router())->get($pattern, static fn() => '');
    }

    public static function invalidPatternProvider(): array
    {
        return [
            'no leading slash' => ['users'],
            'empty' => [''],
            'repeated parameter' => ['/{id}/{id}'],
            'unclosed parameter' => ['/users/{id'],
            'invalid name' => ['/users/{1d}'],
            'capturing expression' => ['/users/{id:(\d+)}'],
            'invalid expression' => ['/users/{id:[a-}'],
        ];
    }

    public function testDuplicateRoutesAreRejected(): void
    {
        $router = new Router();
        $router->get('/', static fn() => 'a');
        $router->get('/users/{id}', static fn() => 'a');
        $router->post('/', static fn() => 'another method');
        $router->get('/users/{id}/posts', static fn() => 'another pattern');
        foreach (['/', '/users/{id}'] as $pattern) {
            try {
                $router->get($pattern, static fn() => 'b');
                self::fail("{$pattern} was defined twice.");
            } catch (\LogicException) {
            }
        }
        self::assertCount(4, $router->routes());
    }

    public function testAnAbsoluteTargetWithoutAPathIsTheRoot(): void
    {
        $router = new Router();
        $router->get('/', static fn() => 'home');
        self::assertSame('home', (string) $router->handle(new Request('GET', 'http://example.com'))->getBody());
    }

    private static function body(Router $router, string $method, string $target): string
    {
        $response = $router->handle(new Request($method, $target));
        self::assertSame(200, $response->getStatusCode());
        return (string) $response->getBody();
    }

    public function testGroupsSharePrefixAndMiddleware(): void
    {
        $mark = static fn (string $name): \Psr\Http\Server\MiddlewareInterface => new class($name) implements \Psr\Http\Server\MiddlewareInterface {
            public function __construct(private string $name)
            {
            }

            public function process(\Psr\Http\Message\ServerRequestInterface $request, \Psr\Http\Server\RequestHandlerInterface $handler): \Psr\Http\Message\ResponseInterface
            {
                return $handler->handle($request)->withAddedHeader('X-Mark', $this->name);
            }
        };
        $container = new \Crab\Container\Container();
        $container->instance('named', $mark('named'));
        $router = new Router($container);
        $router->get('/', static fn (): string => 'home');
        $router->prefix('/admin')->middleware($mark('outer'), 'named')->group(static function (Router $router) use ($mark): void {
            $router->get('/', static fn (): string => 'admin home');
            $router->get('/users/{id}', static fn (int $id): array => ['id' => $id]);
            $router->prefix('/reports/{year}')->middleware($mark('inner'))->group(static function (Router $router): void {
                $router->get('/sales', static fn (int $year): array => ['year' => $year]);
            });
            $router->middleware()->group(static function (Router $router): void {
                $router->post('/plain', static fn (): string => 'no prefix of its own');
            });
        });
        $router->middleware($mark('only'))->group(static fn (Router $router) => $router->get('/after', static fn (): string => 'after'));
        // On one route, and on one route inside a group: the route's own is innermost.
        $router->get('/alone', static fn (): string => 'alone')->middleware($mark('route'), 'named');
        $router->prefix('/both')->middleware($mark('group'))->group(static function (Router $router) use ($mark): void {
            $router->post('/', static fn (): string => 'both')->middleware($mark('route'));
            $router->add('OPTIONS', '/', static fn (): string => 'options')->middleware($mark('added'));
        });
        $router->assemble();

        $answers = [];
        foreach ([['GET', '/'], ['GET', '/admin'], ['GET', '/admin/users/7'], ['GET', '/admin/reports/2026/sales'], ['POST', '/admin/plain'], ['GET', '/after'], ['GET', '/alone'], ['POST', '/both'], ['OPTIONS', '/both']] as [$method, $path]) {
            $response = $router->handle(new Request($method, $path));
            $answers[] = [(string) $response->getBody(), $response->getHeaderLine('X-Mark')];
        }
        self::assertSame([
            ['home', ''],
            ['admin home', 'named, outer'],
            ['{"id":7}', 'named, outer'],
            ['{"year":2026}', 'inner, named, outer'],
            ['no prefix of its own', 'named, outer'],
            ['after', 'only'],
            ['alone', 'named, route'],
            ['both', 'route, group'],
            ['options', 'added, group'],
        ], $answers, 'The first middleware given is outermost, and a group\'s end is the end of its prefix and middleware.');
        self::assertSame(['/', '/admin', '/admin/users/{id}', '/admin/reports/{year}/sales', '/admin/plain', '/after', '/alone', '/both', '/both'], array_column($router->routes(), 'pattern'));
        self::assertSame(404, $router->handle(new Request('GET', '/users/7'))->getStatusCode());
        self::assertSame(422, $router->handle(new Request('GET', '/admin/users/x'))->getStatusCode());
        self::assertSame('named, outer', $router->handle(new Request('GET', '/admin/users/x'))->getHeaderLine('X-Mark'), 'A refusal passes back through the middleware.');

        $mistakes = [
            'starts with "/" and does not end with one' => static fn () => $router->prefix('admin'),
            'does not end with one: "/admin", not "/admin/"' => static fn () => $router->prefix('/admin/'),
        ];
        foreach ($mistakes as $expected => $mistake) {
            try {
                $mistake();
                self::fail("Not refused: {$expected}");
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
        try {
            $router->prefix('/broken')->group(static function (): void {
                throw new \DomainException('inside the group');
            });
        } catch (\DomainException) {
        }
        $router->get('/outside', static fn (): string => 'outside');
        self::assertSame('outside', (string) $router->handle(new Request('GET', '/outside'))->getBody(), 'A group that failed does not leave its prefix behind.');
    }

    public function testMiddlewareThatCouldNeverRunIsRefusedWhereItIsDeclared(): void
    {
        $router = new Router(new \Crab\Container\Container());
        $home = static fn (): string => 'home';
        $mistakes = [
            'Route GET /me names App\\Missing\\Middleware as middleware, and there is no such class' => static fn () => $router->get('/me', $home)->middleware('App\\Missing\\Middleware'),
            'Route POST /me names stdClass as middleware, and it is not one' => static fn () => $router->post('/me', $home)->middleware(\stdClass::class),
            'The group /admin names App\\Missing\\Middleware as middleware' => static fn () => $router->prefix('/admin')->middleware('App\\Missing\\Middleware')->group(static fn () => null),
        ];
        foreach ($mistakes as $expected => $mistake) {
            try {
                $mistake();
                self::fail("Not refused: {$expected}");
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
        // A route is declared before what is chained onto it, so one whose middleware is refused stays; the refusal stops the application from starting.
        self::assertSame(['GET /me', 'POST /me'], array_map(static fn ($route): string => "{$route->method} {$route->pattern}", $router->routes()));
    }

    public function testMiddlewareThatCannotBeMadeStopsTheWorkerFromStarting(): void
    {
        $router = new Router(new \Crab\Container\Container());
        $router->get('/x', static fn (): string => 'x')->middleware(NeedsAnArgument::class);
        $this->expectException(\Throwable::class);
        $router->assemble();
    }

    public function testARouteSaysWhatMiddlewareIsAroundIt(): void
    {
        $router = new Router(new \Crab\Container\Container());
        $router->get('/', static fn (): string => 'home');
        $router->middleware(NeedsAnArgument::class)->group(static function (Router $router): void {
            $router->get('/in', static fn (): string => 'in')->middleware(Passes::class);
        });
        [$home, $in] = $router->routes();
        self::assertSame([], $home->middleware);
        self::assertSame([NeedsAnArgument::class, Passes::class], $in->middleware, "Its groups' first, then its own.");
    }

    public function testAMiddlewareGivenAgainIsUsedOnceAsItWasGivenLast(): void
    {
        $router = new Router(new \Crab\Container\Container());
        $home = static fn (): string => 'home';
        $shared = new NeedsAnArgument('shared');
        $router->middleware($shared, Passes::class)->group(static function (Router $router) use ($home, $shared): void {
            $router->get('/group', $home);
            $router->get('/again', $home)->middleware($shared, Passes::class);
            $router->get('/other', $home)->middleware(new NeedsAnArgument('other'));
        });
        $router->assemble();
        $names = static fn (string $path): array => $router->handle(new Request('GET', $path))->getHeader('X-Name');
        self::assertSame(['shared'], $names('/group'));
        self::assertSame(['shared'], $names('/again'), 'The same object and the same name run once.');
        self::assertSame([NeedsAnArgument::class, Passes::class], $router->routes()[1]->middleware);
        // Another object of the class is another middleware, with options of its own.
        self::assertSame(['other', 'shared'], $names('/other'));
    }

    public function testARouteDeclaredAsItReadsAnswersARequestThatNamesItEncoded(): void
    {
        $router = new Router();
        $router->get('/café', static fn (): string => 'the café');
        $router->get('/{slug}', static fn (string $slug): string => "page {$slug}");

        self::assertSame('the café', (string) $router->handle(new Request('GET', '/caf%C3%A9'))->getBody());
        self::assertSame('page tea', (string) $router->handle(new Request('GET', '/tea'))->getBody());

        // A whole number past what an integer holds is not quietly made another.
        $router->get('/users/{id}', static fn (int $id): array => [$id]);
        self::assertSame('[7]', (string) $router->handle(new Request('GET', '/users/007'))->getBody());
        self::assertSame(422, $router->handle(new Request('GET', '/users/9223372036854775808'))->getStatusCode());
        $router->get('/list', static fn (int $page = 1, ?\DateTimeImmutable $since = null): array => [$page, $since?->format('Y-m-d')]);
        self::assertSame(422, $router->handle(new Request('GET', '/list?page=9223372036854775808'))->getStatusCode());
        self::assertSame('[2,"2026-02-28"]', (string) $router->handle(new Request('GET', '/list?page=2&since=2026-02-28'))->getBody());
        // A rule on a part of the address holds as it would on a field.
        $router->get('/items/{id}/{code}', static fn (#[\Crab\Validation\Range(min: 1)] int $id, #[\Crab\Validation\Pattern('^[a-z]{3}$')] string $code): array => [$id, $code]);
        self::assertSame('[5,"abc"]', (string) $router->handle(new Request('GET', '/items/5/abc'))->getBody());
        $refused = $router->handle(new Request('GET', '/items/-2/ABCD', ['Accept' => 'application/json']));
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['id', 'code'], array_keys(json_decode((string) $refused->getBody(), true)['error']['fields']));
        // The calendar has no February 31st, and it is not read as a day in March.
        self::assertSame(422, $router->handle(new Request('GET', '/list?since=2026-02-31'))->getStatusCode());
    }

    public function testANamedRouteIsFoundAndItsPathWritten(): void
    {
        $router = new Router();
        $router->get('/orders/{id:\d+}', static fn() => 'order')->name('orders.show');
        $router->get('/files/{path:.+}', static fn() => 'file')->name('files.show');
        $router->get('/tags/{tag}', static fn() => 'tag')->name('tags.show');
        $router->get('/plain', static fn() => 'plain');
        $router->prefix('/admin')->group(static function (Router $router): void {
            $router->get('/users/{id}', static fn() => 'user')->name('admin.users.show');
        });

        self::assertSame(['GET', '/orders/{id:\d+}', 'orders.show'], [$router->route('orders.show')?->method, $router->route('orders.show')?->pattern, $router->route('orders.show')?->name]);
        self::assertNull($router->route('nothing'));
        self::assertNull($router->routes()[3]->name);
        self::assertSame('/orders/12', $router->path('orders.show', ['id' => 12]));
        self::assertSame('/orders/12?page=2&q=a%20b', $router->path('orders.show', ['id' => '12'], ['page' => 2, 'q' => 'a b']));
        self::assertSame('/admin/users/7', $router->path('admin.users.show', ['id' => 7]));
        // One segment: a slash is encoded, and the route reads it back as it was given.
        self::assertSame('/tags/a%2Fb%20c', $router->path('tags.show', ['tag' => 'a/b c']));
        self::assertSame('tag', self::body($router, 'GET', $router->path('tags.show', ['tag' => 'a/b c'])));
        // An expression of its own may span segments.
        self::assertSame('/files/docs/read%20me.txt', $router->path('files.show', ['path' => 'docs/read me.txt']));
        self::assertSame('file', self::body($router, 'GET', $router->path('files.show', ['path' => 'docs/read me.txt'])));
        self::assertSame('/tags/kitchen', $router->path('tags.show', ['tag' => Category::Kitchen]));
    }

    public function testAPathThatCouldNotBeWrittenIsRefusedNamingTheRoute(): void
    {
        $router = new Router();
        $router->get('/orders/{id:\d+}', static fn() => 'order')->name('orders.show');
        $router->get('/tags/{tag}', static fn() => 'tag')->name('tags.show');

        $refused = static function (callable $write): string {
            try {
                $write();
            } catch (\InvalidArgumentException $error) {
                return $error->getMessage();
            }
            self::fail('The path was written.');
        };
        self::assertStringContainsString('no route named "nothing"', $refused(static fn() => $router->path('nothing')));
        self::assertStringContainsString('needs the parameter id', $refused(static fn() => $router->path('orders.show')));
        self::assertStringContainsString('cannot take "abc" as its parameter id', $refused(static fn() => $router->path('orders.show', ['id' => 'abc'])));
        self::assertStringContainsString('cannot take "" as its parameter tag', $refused(static fn() => $router->path('tags.show', ['tag' => ''])));
        self::assertStringContainsString('has no parameter page', $refused(static fn() => $router->path('orders.show', ['id' => 1, 'page' => 2])));
        self::assertStringContainsString('not float', $refused(static fn() => $router->path('orders.show', ['id' => 1.5])));
    }

    public function testWhatIsChainedOntoARouteSaysMoreAboutIt(): void
    {
        $order = [];
        $mark = static function (string $name) use (&$order): MiddlewareInterface {
            return new class ($name, $order) implements MiddlewareInterface {
                public function __construct(private string $name, private array &$order)
                {
                }

                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    $this->order[] = $this->name;
                    return $handler->handle($request);
                }
            };
        };
        $router = new Router();
        $router->middleware($mark('group'))->group(static function (Router $router) use ($mark): void {
            $router->get('/report', static fn (): string => 'report')
                ->name('report')
                ->middleware($mark('first'))
                ->middleware($mark('second'));
        });
        $router->assemble();

        self::assertSame('report', self::body($router, 'GET', '/report'));
        self::assertSame(['group', 'first', 'second'], $order, 'A group is outside its routes, and what is chained later is inside.');
        self::assertSame('/report', $router->path('report'));
        self::assertCount(3, $router->route('report')?->middleware ?? []);
        $this->expectExceptionMessage('Route GET /twice is named "report-twice" already. A route has one name.');
        $declared = $router->get('/twice', static fn (): string => '')->name('report-twice');
        $declared->name('other');
    }

    public function testAGroupIsDescribedAStepAtATimeAndEachStepIsNew(): void
    {
        $router = new Router();
        $admin = $router->prefix('/admin');
        $reports = $admin->prefix('/reports');
        $admin->group(static fn (Router $router) => $router->get('/users', static fn (): string => 'users'));
        $reports->group(static fn (Router $router) => $router->get('/sales', static fn (): string => 'sales'));

        self::assertSame(['/admin/users', '/admin/reports/sales'], array_map(static fn ($route): string => $route->pattern, $router->routes()));
    }

    public function testRouteNamesAreUniqueAndNotEmpty(): void
    {
        $router = new Router();
        $router->get('/a', static fn() => 'a')->name('same');
        try {
            $router->post('/b', static fn() => 'b')->name('same');
            self::fail('A second route took the name.');
        } catch (\LogicException $error) {
            self::assertSame('Route POST /b is named "same", which route GET /a already is. Give each route its own name.', $error->getMessage());
        }
        self::assertNull($router->route('same') === null ? 'missing' : null);
        self::assertSame('/a', $router->path('same'));
        $this->expectException(\InvalidArgumentException::class);
        $router->get('/c', static fn() => 'c')->name(' ');
    }
}

final class NeedsAnArgument implements \Psr\Http\Server\MiddlewareInterface
{
    public function __construct(public string $name)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withAddedHeader('X-Name', $this->name);
    }
}

final class Passes implements \Psr\Http\Server\MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

enum Category: string
{
    case Kitchen = 'kitchen';
}
