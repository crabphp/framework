<?php

declare(strict_types=1);

namespace Crab\Tests\Http;

use Crab\App;
use Crab\Cache\ArrayStore;
use Crab\Cache\Cache;
use Crab\Cache\CacheProvider;
use Crab\Config\Config;
use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Clock\Clock;
use Crab\Http\RateLimit;
use Crab\Http\Request;
use Crab\Testing\Api;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\TestCase;

final readonly class Caller implements Identity
{
    public function __construct(
        public int|string $id,
    ) {}
}

final class RateLimitTest extends TestCase
{
    public function testAClientIsLetThroughUpToTheLimitAndToldWhenToTryAgain(): void
    {
        [$app, $clock] = self::app();
        $app->router->post('/login', static fn(): array => ['ok' => true])->middleware(new RateLimit(3, per: 60));
        $app->router->get('/open', static fn(): array => ['ok' => true]);
        $api = new Api($app);

        $remaining = [];
        for ($i = 0; $i < 3; ++$i) {
            $remaining[] = $api->post('/login')->assertStatus(200)->header('X-RateLimit-Remaining');
        }
        self::assertSame(['2', '1', '0'], $remaining);

        // 12:00:00 is the start of a window, so the next is a minute away.
        $refused = $api->post('/login');
        self::assertSame([429, '60', '3', '0', 'Too many requests. Try again in 60 seconds.'], [
            $refused->status,
            $refused->header('Retry-After'),
            $refused->header('X-RateLimit-Limit'),
            $refused->header('X-RateLimit-Remaining'),
            $refused->json('error.message'),
        ]);
        $api->get('/open')->assertStatus(200);

        $clock->advance('+59 seconds');
        self::assertSame('1', $api->post('/login')->header('Retry-After'));
        $clock->advance('+1 second');
        self::assertSame('2', $api->post('/login')->assertStatus(200)->header('X-RateLimit-Remaining'));
    }

    public function testEachUserAndEachAddressIsCountedApart(): void
    {
        [$app] = self::app();
        $limit = new RateLimit(1, per: 60);
        $app->router->get('/a', static fn(): array => [])->middleware($limit);
        $app->router->get('/b', static fn(): array => [])->middleware($limit);
        $app->router->get('/named', static fn(): array => [])->middleware(new RateLimit(1, per: 60, name: 'other'));
        $app->router->get('/tenant', static fn(): array => [])->middleware(new RateLimit(
            1,
            per: 60,
            name: 'tenant',
            by: static fn($request): string => $request->getHeaderLine('X-Tenant'),
        ));
        $api = new Api($app);
        $from = static fn(string $address, string $path = '/a'): int => $app->handle(new Request(
            'GET',
            $path,
            serverParams: ['REMOTE_ADDR' => $address],
        ))->getStatusCode();

        self::assertSame([200, 429, 200], [$from('203.0.113.7'), $from('203.0.113.7'), $from('203.0.113.8')]);
        // One limit on two routes is one count; a limit of another name is another.
        self::assertSame([429, 200], [$from('203.0.113.8', '/b'), $from('203.0.113.8', '/named')]);

        // A user is counted as the user, whatever address they come from.
        self::assertSame([200, 429, 200], [
            $api->actingAs(new Caller(1))->get('/a')->status,
            $api->actingAs(new Caller(1))->get('/a')->status,
            $api->actingAs(new Caller(2))->get('/a')->status,
        ]);

        self::assertSame([200, 429, 200], [
            $api->withHeaders(['X-Tenant' => 'acme'])->get('/tenant')->status,
            $api->withHeaders(['X-Tenant' => 'acme'])->get('/tenant')->status,
            $api->withHeaders(['X-Tenant' => 'other'])->get('/tenant')->status,
        ]);
    }

    public function testItSaysWhatItNeedsWhenItHasNoCacheOrImpossibleNumbers(): void
    {
        $app = new App();
        $app->router->get('/a', static fn(): array => [])->middleware(new RateLimit(1));
        try {
            // As a worker does when it starts.
            $app->router->assemble();
            self::fail('A rate limit without a cache was made.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('CacheProvider', $error->getMessage());
        }

        // Given a cache, it needs no container.
        $own = new RateLimit(1, cache: new Cache(new ArrayStore()));
        self::assertSame($own, $own->withContainer($app->container));

        $this->expectException(\InvalidArgumentException::class);
        new RateLimit(0);
    }

    /**
     * @return array{App, FrozenClock}
     */
    private static function app(): array
    {
        $app = new App(new Config(['cache' => ['store' => 'array']]));
        $app->register(CacheProvider::class);
        $clock = new FrozenClock('2026-01-31 12:00:00');
        $app->container->instance(Clock::class, $clock);
        return [$app, $clock];
    }
}
