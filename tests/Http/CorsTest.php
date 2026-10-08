<?php

declare(strict_types=1);

namespace Crab\Tests\Http;

use Crab\Http\Cors;
use Crab\Http\Pipeline;
use Crab\Http\Request;
use Crab\Http\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CorsTest extends TestCase
{
    public function testAPageOnAnAllowedOriginIsNamedInTheAnswer(): void
    {
        $api = self::api(new Cors(['https://app.example.com', 'https://*.example.dev'], exposed: ['X-Total']));

        $answer = $api->handle(new Request('GET', '/', ['Origin' => 'https://app.example.com']));
        self::assertSame(
            [200, 'https://app.example.com', 'Origin', 'X-Total', false, 'reached'],
            [$answer->getStatusCode(), $answer->getHeaderLine('Access-Control-Allow-Origin'), $answer->getHeaderLine('Vary'),
                $answer->getHeaderLine('Access-Control-Expose-Headers'), $answer->hasHeader('Access-Control-Allow-Credentials'), (string) $answer->getBody()],
        );
        self::assertSame('https://APP.example.com', $api->handle(new Request('GET', '/', ['Origin' => 'https://APP.example.com']))->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('https://pr-12.example.dev', $api->handle(new Request('GET', '/', ['Origin' => 'https://pr-12.example.dev']))->getHeaderLine('Access-Control-Allow-Origin'));

        // Anyone else is still answered, only without what a browser needs to show the answer to a page.
        foreach (['https://evil.example', 'http://app.example.com', 'https://app.example.com.evil.example', 'https://a.b.example.dev', 'https://x.example.dev:8443', 'https://example.dev'] as $origin) {
            $other = $api->handle(new Request('GET', '/', ['Origin' => $origin]));
            self::assertSame([200, false], [$other->getStatusCode(), $other->hasHeader('Access-Control-Allow-Origin')], $origin);
        }
        $plain = $api->handle(new Request('GET', '/'));
        self::assertSame([200, false, false], [$plain->getStatusCode(), $plain->hasHeader('Access-Control-Allow-Origin'), $plain->hasHeader('Vary')], 'A request that names no origin is left alone.');
    }

    public function testTheBrowsersQuestionIsAnsweredWithoutReachingTheRoute(): void
    {
        $api = self::api(new Cors(['https://app.example.com'], credentials: true, maxAge: 60));
        $ask = static fn (string $origin, array $more = []): Request => new Request('OPTIONS', '/users', [
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'DELETE',
            ...$more,
        ]);

        $answer = $api->handle($ask('https://app.example.com', ['Access-Control-Request-Headers' => 'authorization, content-type']));
        self::assertSame([
            204,
            'https://app.example.com',
            'GET, POST, PUT, PATCH, DELETE',
            'authorization, content-type',
            '60',
            'true',
            'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
            '',
        ], [
            $answer->getStatusCode(),
            $answer->getHeaderLine('Access-Control-Allow-Origin'),
            $answer->getHeaderLine('Access-Control-Allow-Methods'),
            $answer->getHeaderLine('Access-Control-Allow-Headers'),
            $answer->getHeaderLine('Access-Control-Max-Age'),
            $answer->getHeaderLine('Access-Control-Allow-Credentials'),
            $answer->getHeaderLine('Vary'),
            (string) $answer->getBody(),
        ]);
        self::assertFalse($api->handle($ask('https://app.example.com'))->hasHeader('Access-Control-Allow-Headers'), 'No headers were asked for.');

        $refused = $api->handle($ask('https://evil.example'));
        self::assertSame([403, false], [$refused->getStatusCode(), $refused->hasHeader('Access-Control-Allow-Origin')]);

        // An OPTIONS request that asks nothing is an ordinary request.
        $ordinary = $api->handle(new Request('OPTIONS', '/users', ['Origin' => 'https://app.example.com']));
        self::assertSame(['reached', 'true'], [(string) $ordinary->getBody(), $ordinary->getHeaderLine('Access-Control-Allow-Credentials')]);

        $listed = self::api(new Cors(['*'], methods: ['get', 'post'], headers: ['Authorization', 'X-Trace']));
        $answer = $listed->handle($ask('https://anyone.example', ['Access-Control-Request-Headers' => 'x-other']));
        self::assertSame(['*', 'GET, POST', 'Authorization, X-Trace', 'Access-Control-Request-Method, Access-Control-Request-Headers'], [
            $answer->getHeaderLine('Access-Control-Allow-Origin'),
            $answer->getHeaderLine('Access-Control-Allow-Methods'),
            $answer->getHeaderLine('Access-Control-Allow-Headers'),
            $answer->getHeaderLine('Vary'),
        ]);
        $any = $listed->handle(new Request('GET', '/', ['Origin' => 'https://anyone.example']));
        self::assertSame(['*', false], [$any->getHeaderLine('Access-Control-Allow-Origin'), $any->hasHeader('Vary')]);
    }

    public function testOriginsThatCouldNotWorkAreRefusedWhenItIsMade(): void
    {
        $mistakes = [
            'Cors needs the origins that may call' => static fn () => new Cors([]),
            'not "https://app.example.com/"' => static fn () => new Cors(['https://app.example.com/']),
            'not "app.example.com"' => static fn () => new Cors(['app.example.com']),
            'not int' => static fn () => new Cors([1]),
            'cannot allow credentials for any origin' => static fn () => new Cors(['*'], credentials: true),
            'a max age of zero or more' => static fn () => new Cors(['*'], maxAge: -1),
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

    private static function api(Cors $cors): Pipeline
    {
        $route = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::text('reached');
            }
        };
        return new Pipeline($route, $cors);
    }
}
