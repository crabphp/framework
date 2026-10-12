<?php

declare(strict_types=1);

namespace Crab\Tests\Auth;

use Crab\App;
use Crab\Auth\AuthProvider;
use Crab\Auth\IssueTicket;
use Crab\Auth\Ticket;
use Crab\Auth\Tickets;
use Crab\Auth\TokenAuthProvider;
use Crab\Cache\ArrayStore;
use Crab\Cache\Cache;
use Crab\Cache\CacheProvider;
use Crab\Cache\FileStore;
use Crab\Config\Config;
use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Auth\Tickets as TicketsContract;
use Crab\Contracts\Auth\TokenStore;
use Crab\Contracts\Cache\Store;
use Crab\Contracts\WebSocket\Socket;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\RevoltEventLoop;
use Crab\Engine\FailureReporter;
use Crab\Http\AccessLog;
use Crab\Testing\Api;
use Crab\Testing\FrozenClock;
use Crab\Testing\WebSocketRefused;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;

/**
 * Exercises browser and header authentication through real socket pairs,
 * including refusal before the handshake and the ordinary typed user.
 */
final class TicketTest extends TestCase
{
    private App $app;
    private Api $api;
    private FrozenClock $clock;
    private TicketUser $user;
    private TicketUsers $users;
    private ?\Closure $reporter;
    private array $reports = [];

    protected function setUp(): void
    {
        EventLoop::install(RevoltEventLoop::create());
        $this->reporter = FailureReporter::handleWith(function (string $what, ?\Throwable $error): void {
            $this->reports[] = [$what, $error?->getMessage()];
        });
        $this->clock = new FrozenClock('2026-01-31 12:00:00');
        $this->user = new TicketUser('0007');
        $this->users = new TicketUsers($this->user);
        $this->app = new App(new Config(['cache' => ['store' => 'array'], 'websocket' => ['ping' => 0]]));
        $this->app->register(AuthProvider::class, CacheProvider::class, TokenAuthProvider::class);
        $this->app->container->instance(ClockInterface::class, $this->clock);
        $this->app->container->instance(IdentityResolver::class, $this->users);
        $this->app->container->instance(TokenStore::class, new TicketTokens($this->user->id));
        $this->app->router->post('/ws/ticket', IssueTicket::class, middleware: [\Crab\Auth\TokenAuth::class]);
        $this->app->router->websocket('/live', static function (Socket $socket, TicketUser $user): void {
            $socket->send(['user' => $user->id]);
            foreach ($socket as $message) {
                $socket->send($message);
            }
        }, middleware: [Ticket::class]);
        $this->api = new Api($this->app);
    }

    protected function tearDown(): void
    {
        $this->api->closeSockets();
        FailureReporter::handleWith($this->reporter);
        self::assertSame([], $this->reports);
    }

    public function testAnAuthenticatedHttpRequestIssuesATicketThatConnectsAsTheTypedUser(): void
    {
        $ticket = $this->api->withToken('known')->post('/ws/ticket')->assertStatus(200)->json('ticket');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $ticket);
        $this->api->websocket('/live', query: ['ticket' => $ticket])->assertReceived(['user' => '0007']);
        self::assertSame(401, $this->refusal($this->api, ['ticket' => $ticket]));
        $this->api->post('/ws/ticket')->assertStatus(401);
    }

    public function testATicketExpiresAtThirtySecondsAndEveryAuthenticationFailureHasTheSameAnswer(): void
    {
        $ticket = $this->issue();
        $this->clock->advance('+30 seconds');
        foreach ([[], ['ticket' => $ticket], ['ticket' => ''], ['ticket' => str_repeat('a', 64)], ['ticket' => ['nested']], ['ticket' => null]] as $query) {
            self::assertSame(401, $this->refusal($this->api, $query));
        }
    }

    public function testNoTicketDelegatesToTheHeaderAndActingAsKeepsWorking(): void
    {
        $this->api->withToken('known')->websocket('/live')->assertReceived(['user' => '0007']);
        $this->api->actingAs($this->user)->websocket('/live')->assertReceived(['user' => '0007']);
        $ticket = $this->api->actingAs($this->user)->post('/ws/ticket')->assertStatus(200)->json('ticket');
        $this->api->websocket('/live', query: ['ticket' => $ticket])->assertReceived(['user' => '0007']);
    }

    public function testAPresentInvalidTicketNeverFallsBackToAHeaderOrAnExistingIdentity(): void
    {
        self::assertSame(401, $this->refusal($this->api->withToken('known'), ['ticket' => 'bad']));
        self::assertSame(401, $this->refusal($this->api->actingAs($this->user), ['ticket' => 'bad']));
    }

    public function testADeletedUserCannotBeRestoredAndTheTicketIsStillConsumed(): void
    {
        $ticket = $this->issue();
        $this->users->user = null;
        self::assertSame(401, $this->refusal($this->api, ['ticket' => $ticket]));
        $this->users->user = $this->user;
        self::assertSame(401, $this->refusal($this->api, ['ticket' => $ticket]));
    }

    public function testOnlyTheIdentityIdIsStoredUnderTheTicketsHash(): void
    {
        $ticket = $this->issue();
        $store = $this->app->container->get(Store::class);
        self::assertSame(serialize('0007'), $store->get('auth.ticket.' . hash('sha256', $ticket), $this->clock->now()->getTimestamp()));
        self::assertNull($store->get($ticket, $this->clock->now()->getTimestamp()));
        self::assertSame($this->app->container->get(TicketsContract::class), $this->app->container->get(Tickets::class));
        self::assertNotSame($ticket, $this->issue());
    }

    public function testOriginsAreMatchedExactlyBeforeATicketIsConsumedOrAHeaderIsChecked(): void
    {
        $this->app->router->websocket('/restricted', static function (Socket $socket, TicketUser $user): void {
            $socket->send(['user' => $user->id]);
        }, middleware: [new Ticket(origins: ['https://shop.example.com'])]);
        $ticket = $this->issue();
        foreach (['', 'https://shop.example.com/', 'https://SHOP.example.com', 'https://other.example.com'] as $origin) {
            try {
                $this->api->websocket('/restricted', query: ['ticket' => $ticket], headers: ['Origin' => $origin]);
                self::fail('A disallowed origin upgraded.');
            } catch (WebSocketRefused $error) {
                $error->response->assertStatus(403);
            }
        }
        $this->api->websocket('/restricted', query: ['ticket' => $ticket], headers: ['Origin' => 'https://shop.example.com'])->assertReceived(['user' => '0007']);
        $this->api->withToken('known')->websocket('/restricted', headers: ['Origin' => 'https://shop.example.com'])->assertReceived(['user' => '0007']);
        // The ordinary route permits a caller with no Origin header.
        $this->api->websocket('/live', query: ['ticket' => $this->issue()])->assertReceived(['user' => '0007']);
    }

    public function testAnAccessLogAndTheRouteListNeverContainTheTicket(): void
    {
        $records = [];
        $logger = new class ($records) extends AbstractLogger {
            public function __construct(private array &$records) {}
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $this->app->router->websocket('/logged', static function (Socket $socket): void {
            $socket->send('ready');
        }, middleware: [new AccessLog(logger: $logger), Ticket::class]);
        $ticket = $this->issue();
        $this->api->websocket('/logged', query: ['ticket' => $ticket, 'page' => 2])->assertReceived('ready');
        self::assertCount(1, $records);
        self::assertStringContainsString('GET /logged 101 ', $records[0][0]);
        self::assertSame('/logged', $records[0][1]['path']);
        self::assertStringNotContainsString($ticket, json_encode($records, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($ticket, json_encode($this->app->router->routes(), JSON_THROW_ON_ERROR));
    }

    public function testTwoConcurrentRedemptionsHaveExactlyOneWinner(): void
    {
        $directory = sys_get_temp_dir() . '/crab-ticket-' . bin2hex(random_bytes(6));
        $store = new FileStore($directory);
        try {
            $first = new Tickets(new Cache($store), $this->users);
            $second = new Tickets(new Cache(new FileStore($directory)), $this->users);
            $ticket = $first->issue($this->user);
            $loop = EventLoop::current();
            $a = Task::spawn($loop, static fn (): ?Identity => $first->redeem($ticket));
            $b = Task::spawn($loop, static fn (): ?Identity => $second->redeem($ticket));
            $results = [$a->await(), $b->await()];
            $loop->run();
            self::assertSame(1, count(array_filter($results, static fn ($user): bool => $user instanceof Identity)));
            self::assertSame(1, count(array_filter($results, static fn ($user): bool => $user === null)));
        } finally {
            $store->clear();
            rmdir($directory);
        }
    }

    public function testMultipleWorkersRefuseAnArrayTicketStoreBeforeBindingAPort(): void
    {
        foreach ([1, 2] as $requested) {
            $app = new App(new Config(['server' => ['workers' => $requested === 1 ? 2 : 1], 'cache' => ['store' => 'array']]));
            $app->register(CacheProvider::class);
            $app->router->post('/ws/ticket', IssueTicket::class);
            try {
                $app->runtime('tcp://127.0.0.1:8291', workers: $requested);
                self::fail('An array ticket store was accepted for multiple workers.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('Set cache.store to file', $error->getMessage());
            }
        }
        $this->app->runtime('tcp://127.0.0.1:8291');
        $directory = sys_get_temp_dir() . '/crab-ticket-startup-' . bin2hex(random_bytes(6));
        $this->app->container->instance(Store::class, new FileStore($directory));
        $this->app->runtime('tcp://127.0.0.1:8291', workers: 2);
    }

    public function testTheConfiguredTicketLifetimeIsUsedAndInvalidLifetimesAreRefused(): void
    {
        $app = new App(new Config(['auth' => ['ticket_ttl' => 2], 'cache' => ['store' => 'array']]));
        $app->register(AuthProvider::class, CacheProvider::class);
        $app->container->instance(ClockInterface::class, $this->clock);
        $app->container->instance(IdentityResolver::class, $this->users);
        $tickets = $app->container->get(TicketsContract::class);
        $ticket = $tickets->issue($this->user);
        $this->clock->advance('+2 seconds');
        self::assertNull($tickets->redeem($ticket));
        $this->expectException(\InvalidArgumentException::class);
        new Tickets(new Cache(new ArrayStore()), $this->users, ttl: 0);
    }

    public function testGlobalTicketMiddlewareRefusesAnArrayCacheWithMultipleWorkers(): void
    {
        foreach ([Ticket::class, new Ticket()] as $middleware) {
            foreach ([[1, 2], [2, 1]] as [$configured, $requested]) {
                $app = new App(new Config(['cache' => ['store' => 'array'], 'server' => ['workers' => $configured]]));
                $app->register(AuthProvider::class, CacheProvider::class, TokenAuthProvider::class);
                $app->container->instance(IdentityResolver::class, $this->users);
                $app->container->instance(TokenStore::class, new TicketTokens($this->user->id));
                $app->container->get(\Crab\Contracts\Http\MiddlewareStack::class)->add($middleware);
                // Neither route names IssueTicket or declares route middleware.
                $app->router->post('/ws/ticket', static function (Identity $user) use ($app): array {
                    return ['ticket' => $app->container->get(TicketsContract::class)->issue($user)];
                });
                $app->router->websocket('/live', static function (Socket $socket): void {
                    $socket->send('ready');
                });
                try {
                    $app->runtime('tcp://127.0.0.1:8291', workers: $requested);
                    self::fail('Global Ticket middleware accepted an array cache for multiple workers.');
                } catch (\LogicException $error) {
                    self::assertStringContainsString('An array cache keeps tickets in one worker only.', $error->getMessage());
                    self::assertStringContainsString('Set cache.store to file', $error->getMessage());
                }
                $directory = sys_get_temp_dir() . '/crab-global-ticket-' . bin2hex(random_bytes(6));
                $app->container->instance(Store::class, new FileStore($directory));
                $app->runtime('tcp://127.0.0.1:8291', workers: $requested);
            }
        }
    }

    private function issue(): string
    {
        return $this->app->container->get(TicketsContract::class)->issue($this->user);
    }

    private function refusal(Api $api, array $query): int
    {
        try {
            $api->websocket('/live', query: $query);
            self::fail('Invalid authentication upgraded.');
        } catch (WebSocketRefused $error) {
            $error->response->assertJson('error.message', 'Authentication is required.')->assertHeader('WWW-Authenticate', 'Bearer');
            return $error->response->status;
        }
    }
}

final readonly class TicketUser implements Identity
{
    public function __construct(public int|string $id) {}
}

final class TicketUsers implements IdentityResolver
{
    public function __construct(public ?TicketUser $user) {}
    public function resolve(int|string $id): ?Identity
    {
        return $this->user?->id === $id ? $this->user : null;
    }
}

final readonly class TicketTokens implements TokenStore
{
    public function __construct(private int|string $id) {}
    public function issue(Identity|int|string $for, string $name = '', ?\DateTimeInterface $expiresAt = null): string { return 'known'; }
    public function owner(string $token): int|string|null { return $token === 'known' ? $this->id : null; }
    public function revoke(string $token): void {}
    public function revokeAll(Identity|int|string $of): void {}
}
