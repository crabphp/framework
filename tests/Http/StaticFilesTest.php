<?php

declare(strict_types=1);

namespace Crab\Tests\Http;

use Crab\Http\Factory;
use Crab\Http\Request;
use Crab\Http\StaticFiles;
use Crab\Routing\Router;
use PHPUnit\Framework\TestCase;

final class StaticFilesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = (string) realpath(sys_get_temp_dir()) . '/crab-static-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testItServesFilesWithTypeLengthAndCacheHeaders(): void
    {
        file_put_contents($this->directory . '/favicon.svg', '<svg></svg>');
        $static = new StaticFiles($this->directory, new Factory());

        $response = $static->response(new Request('GET', '/favicon.svg'));

        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/svg+xml', $response->getHeaderLine('Content-Type'));
        self::assertSame('11', $response->getHeaderLine('Content-Length'));
        self::assertSame('public, max-age=3600', $response->getHeaderLine('Cache-Control'));
        self::assertSame('<svg></svg>', (string) $response->getBody());
    }

    public function testItAnswersConditionalRequestsAndHead(): void
    {
        file_put_contents($this->directory . '/app.css', 'body {}');
        $static = new StaticFiles($this->directory, new Factory());
        $request = new Request('GET', '/app.css');
        $first = $static->response($request);

        self::assertNotNull($first);
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(7, strlen((string) $first->getBody()));
        self::assertSame(304, $static->response(new Request('GET', '/app.css', [
            'If-None-Match' => $first->getHeaderLine('ETag'),
        ]))?->getStatusCode());
        self::assertSame(200, $static->response(new Request('HEAD', '/app.css'))?->getStatusCode());
    }

    public function testItDoesNotEscapeThePublicRoot(): void
    {
        file_put_contents(dirname($this->directory) . '/outside.txt', 'outside');
        file_put_contents($this->directory . '/inside.txt', 'inside');
        $static = new StaticFiles($this->directory, new Factory());

        self::assertNull($static->response(new Request('GET', '/%2e%2e/outside.txt')));
        self::assertNull($static->response(new Request('GET', '/%2e%2e%2Foutside.txt')));
        self::assertSame('inside', (string) $static->response(new Request('GET', '/inside.txt'))?->getBody());
    }

    public function testRouterUsesStaticFilesOnlyAfterRoutesAndReturnsStatic405(): void
    {
        file_put_contents($this->directory . '/app.css', 'body {}');
        file_put_contents($this->directory . '/route.txt', 'file');
        $static = new StaticFiles($this->directory, new Factory());
        $router = new Router(null, null, null, $static);
        $router->get('/route.txt', static fn() => 'route');
        $router->post('/reserved.txt', static fn() => 'reserved');

        self::assertSame('route', (string) $router->handle(new Request('GET', '/route.txt'))->getBody());
        self::assertSame('body {}', (string) $router->handle(new Request('GET', '/app.css'))->getBody());
        self::assertSame(405, $router->handle(new Request('POST', '/app.css'))->getStatusCode());
        self::assertSame('GET, HEAD', $router->handle(new Request('POST', '/app.css'))->getHeaderLine('Allow'));
        self::assertSame(405, $router->handle(new Request('GET', '/reserved.txt'))->getStatusCode());
        self::assertSame('POST', $router->handle(new Request('GET', '/reserved.txt'))->getHeaderLine('Allow'));
    }
}
