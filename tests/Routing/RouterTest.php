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
use Psr\Http\Server\RequestHandlerInterface;

final class RouterTest extends TestCase
{
    public function testStaticAndParameterRoutes(): void
    {
        $router = (new Router())
            ->get('/', static fn() => 'home')
            ->get('/users/new', static fn() => 'new user')
            ->get('/users/{id}', static fn(ServerRequestInterface $request) => 'user ' . $request->getAttribute('id'))
            ->get('/users/{id}/posts/{post}', static fn(ServerRequestInterface $request) => json_encode($request->getAttributes()))
            ->post('/users', static fn() => new Response(201));

        self::assertSame('home', self::body($router, 'GET', '/'));
        self::assertSame('new user', self::body($router, 'GET', '/users/new'));
        self::assertSame('user 7', self::body($router, 'GET', '/users/7?page=2'));
        self::assertSame('{"id":"7","post":"a b"}', self::body($router, 'GET', '/users/7/posts/a%20b'));
        self::assertSame(201, $router->handle(new Request('POST', '/users'))->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $router->handle(new Request('GET', '/'))->getHeaderLine('content-type'));
    }

    public function testParameterExpressions(): void
    {
        $router = (new Router())
            ->get('/posts/{id:\d+}', static fn(ServerRequestInterface $request) => 'post ' . $request->getAttribute('id'))
            ->get('/posts/{slug}', static fn(ServerRequestInterface $request) => 'slug ' . $request->getAttribute('slug'))
            ->get('/files/{path:.+}', static fn(ServerRequestInterface $request) => 'file ' . $request->getAttribute('path'))
            ->get('/dates/{year:\d{4}}-{month:(?:0[1-9]|1[0-2])}', static fn(ServerRequestInterface $request) => implode('/', $request->getAttributes()))
            ->get('/a.b/{x:[~]+}', static fn() => 'quoted');

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
        $router = (new Router())
            ->get('/users/{id}', static fn() => 'show')
            ->delete('/users/{id}', static fn() => 'delete')
            ->post('/users', static fn() => 'create');

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
        $router = (new Router())->put('/items/{id}', $handler)->patch('/items/{id}', $handler)->add('PURGE', '/items/{id}', $handler);
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
        $router = (new Router())->get('/', static fn() => 'a')->get('/users/{id}', static fn() => 'a');
        $router->post('/', static fn() => 'another method')->get('/users/{id}/posts', static fn() => 'another pattern');
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
        $router = (new Router())->get('/', static fn() => 'home');
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
        $returned = $router->group(prefix: '/admin', middleware: [$mark('outer'), 'named'], routes: static function (Router $router) use ($mark): void {
            $router->get('/', static fn (): string => 'admin home');
            $router->get('/users/{id}', static fn (int $id): array => ['id' => $id]);
            $router->group(prefix: '/reports/{year}', middleware: [$mark('inner')], routes: static function (Router $router): void {
                $router->get('/sales', static fn (int $year): array => ['year' => $year]);
            });
            $router->group(static function (Router $router): void {
                $router->post('/plain', static fn (): string => 'no prefix of its own');
            });
        });
        $router->group(middleware: [$mark('only')], routes: static fn (Router $router) => $router->get('/after', static fn (): string => 'after'));
        self::assertSame($router, $returned);
        $router->assemble();

        $answers = [];
        foreach ([['GET', '/'], ['GET', '/admin'], ['GET', '/admin/users/7'], ['GET', '/admin/reports/2026/sales'], ['POST', '/admin/plain'], ['GET', '/after']] as [$method, $path]) {
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
        ], $answers, 'The first middleware given is outermost, and a group\'s end is the end of its prefix and middleware.');
        self::assertSame(['/', '/admin', '/admin/users/{id}', '/admin/reports/{year}/sales', '/admin/plain', '/after'], array_column($router->routes(), 'pattern'));
        self::assertSame(404, $router->handle(new Request('GET', '/users/7'))->getStatusCode());
        self::assertSame(422, $router->handle(new Request('GET', '/admin/users/x'))->getStatusCode());
        self::assertSame('named, outer', $router->handle(new Request('GET', '/admin/users/x'))->getHeaderLine('X-Mark'), 'A refusal passes back through the middleware.');

        $mistakes = [
            'starts with "/" and does not end with one' => static fn () => $router->group(static fn () => null, 'admin'),
            'does not end with one: "/admin", not "/admin/"' => static fn () => $router->group(static fn () => null, '/admin/'),
            'PSR-15 middleware or their class names, not int' => static fn () => $router->group(static fn () => null, middleware: [1]),
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
            $router->group(prefix: '/broken', routes: static function (): void {
                throw new \DomainException('inside the group');
            });
        } catch (\DomainException) {
        }
        $router->get('/outside', static fn (): string => 'outside');
        self::assertSame('outside', (string) $router->handle(new Request('GET', '/outside'))->getBody(), 'A group that failed does not leave its prefix behind.');
    }

    public function testMiddlewareThatCannotBeMadeStopsTheWorkerFromStarting(): void
    {
        $router = new Router(new \Crab\Container\Container());
        $router->group(middleware: ['App\\Missing\\Middleware'], routes: static fn (Router $router) => $router->get('/x', static fn (): string => 'x'));
        $this->expectException(\Throwable::class);
        $router->assemble();
    }
}
