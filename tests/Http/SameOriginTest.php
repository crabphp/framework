<?php

declare(strict_types=1);

namespace Crab\Tests\Http;

use Crab\Http\ClosureHandler;
use Crab\Http\Request;
use Crab\Http\Response;
use Crab\Http\SameOrigin;
use PHPUnit\Framework\TestCase;

final class SameOriginTest extends TestCase
{
    public function testARequestThatChangesSomethingMustComeFromTheApplicationsPages(): void
    {
        $guard = new SameOrigin('https://shop.example.com', ['https://admin.example.com']);
        $next = new ClosureHandler(static fn(): Response => Response::text('in'));
        $status = static fn(string $method, array $headers): int => $guard
            ->handle(new Request($method, 'https://shop.example.com/login', $headers), $next)
            ->getStatusCode();

        self::assertSame(200, $status('GET', []));
        self::assertSame(403, $status('POST', []));
        self::assertSame(403, $status('POST', ['Origin' => 'https://evil.example']));
        self::assertSame(403, $status('POST', ['Sec-Fetch-Site' => 'cross-site']));
        self::assertSame(200, $status('POST', ['Sec-Fetch-Site' => 'same-origin']));
        self::assertSame(200, $status('POST', ['Origin' => 'https://shop.example.com']));
        self::assertSame(200, $status('DELETE', ['Origin' => 'https://admin.example.com']));
        self::assertSame(403, $status('GET', ['Upgrade' => 'websocket']));
    }

    public function testWithoutASiteTheRequestsOwnOriginIsTheApplications(): void
    {
        $guard = new SameOrigin();
        $next = new ClosureHandler(static fn(): Response => Response::text('in'));

        $own = $guard->handle(new Request('POST', 'http://localhost:8080/x', [
            'Origin' => 'http://localhost:8080',
        ]), $next);
        $other = $guard->handle(new Request('POST', 'http://localhost:8080/x', [
            'Origin' => 'http://localhost:9',
        ]), $next);

        self::assertSame(200, $own->getStatusCode());
        self::assertSame(403, $other->getStatusCode());
        self::assertNotNull($other->problem());
    }

    public function testSomethingThatIsNotAnOriginIsRefusedWhenItIsMade(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SameOrigin(origins: ['shop.example.com']);
    }
}
