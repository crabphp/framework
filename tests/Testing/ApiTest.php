<?php

declare(strict_types=1);

namespace Crab\Tests\Testing;

use Crab\App;
use Crab\Auth\TokenAuth;
use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Auth\TokenStore;
use Crab\Contracts\Clock\Clock;
use Crab\Http\HttpException;
use Crab\Testing\Api;
use Crab\Testing\FrozenClock;
use Crab\Validation\Email;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final readonly class Member implements Identity
{
    public function __construct(public int|string $id, public string $name)
    {
    }
}

final class ApiTest extends TestCase
{
    public function testRequestsAreSentAsAClientWouldAndAnswersAreEasyToCheck(): void
    {
        $app = new App();
        $app->router->get('/items', static fn (int $page = 1, string $q = ''): array => ['page' => $page, 'q' => $q, 'data' => [['name' => 'mug']]]);
        $app->router->post('/users', static fn (#[Email] string $email): array => ['email' => $email]);
        $app->router->patch('/users/{id}', static fn (int $id, string $name): array => [$id, $name]);
        $app->router->delete('/users/{id}', static function (int $id): void {
        });
        $app->router->get('/echo', static fn (\Psr\Http\Message\ServerRequestInterface $request): array => [$request->getHeaderLine('X-Tenant'), $request->getHeaderLine('Accept')]);
        $app->router->get('/broken', static fn () => throw new HttpException(409, 'Already taken.'));
        $api = new Api($app);

        self::assertSame(['page' => 2, 'q' => 'a b'], array_slice($api->get('/items', ['page' => 2, 'q' => 'a b'])->assertStatus(200)->json(), 0, 2));
        self::assertSame('mug', $api->get('/items')->json('data.0.name'));
        self::assertSame(['email' => 'ada@example.com'], $api->post('/users', ['email' => 'ada@example.com'])->json());
        self::assertSame([7, 'Ada'], $api->patch('/users/7', ['name' => 'Ada'])->json());
        self::assertSame(204, $api->delete('/users/7')->status);
        self::assertSame(['acme', 'application/json'], $api->withHeaders(['X-Tenant' => 'acme'])->get('/echo')->json());

        // What is refused comes back as JSON, with the field that was wrong.
        $refused = $api->post('/users', ['email' => 'nope']);
        self::assertSame([422, ['must be an email address']], [$refused->status, $refused->json('error.fields.email')]);
        self::assertSame('Already taken.', $api->get('/broken')->assertStatus(409)->json('error.message'));

        // A status that is not the one expected fails the test, and says what was answered.
        try {
            $api->post('/users', ['email' => 'nope'])->assertStatus(200);
            self::fail('A 422 passed for a 200.');
        } catch (AssertionFailedError $error) {
            self::assertStringContainsString('POST /users answered 422, not 200', $error->getMessage());
            self::assertStringContainsString('must be an email address', $error->getMessage());
        }
        try {
            $api->get('/items')->json('data.3.name');
            self::fail('A value that is not there was read.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('answered without "data.3.name"', $error->getMessage());
        }
    }

    public function testARequestIsMadeAsAUserWithoutAToken(): void
    {
        $app = new App();
        // Authentication that would let no token through: there is none to find.
        $tokens = new class implements TokenStore {
            public function issue(Identity|int|string $for, string $name = '', ?\DateTimeInterface $expiresAt = null): string
            {
                return '';
            }

            public function owner(string $token): int|string|null
            {
                return null;
            }

            public function revoke(string $token): void
            {
            }

            public function revokeAll(Identity|int|string $of): void
            {
            }
        };
        $users = new class implements IdentityResolver {
            public function resolve(int|string $id): ?Identity
            {
                return null;
            }
        };
        $app->router->get('/me', static fn (Member $user): array => ['id' => $user->id, 'name' => $user->name], middleware: [new TokenAuth($tokens, $users)]);
        $api = new Api($app);

        self::assertSame(401, $api->get('/me')->status);
        self::assertSame(401, $api->get('/me', headers: ['Authorization' => 'Bearer nothing'])->status);
        self::assertSame(['id' => 7, 'name' => 'Ada'], $api->actingAs(new Member(7, 'Ada'))->get('/me')->assertStatus(200)->json());
        // The one made before is as it was.
        self::assertSame(401, $api->get('/me')->status);
    }

    public function testAServiceReplacedInATestIsReplacedUnderEveryNameItHas(): void
    {
        $app = new App();
        // Asked for by its PSR name first, as a service made before the test would have.
        $real = $app->container->get(ClockInterface::class);
        $frozen = new FrozenClock('2026-01-31 12:00:00');
        $app->container->instance(Clock::class, $frozen);

        self::assertNotSame($real, $app->container->get(ClockInterface::class));
        self::assertSame($frozen, $app->container->get(ClockInterface::class));
        self::assertSame($frozen, $app->container->get(Clock::class));
    }

    public function testARequestIsKnownByItsClientsAddressAndAProxysWordIsTakenOnlyFromAProxy(): void
    {
        $from = static fn (string $peer, string $forwarded = ''): \Crab\Http\Request => new \Crab\Http\Request('GET', '/', $forwarded === '' ? [] : ['X-Forwarded-For' => $forwarded], serverParams: ['REMOTE_ADDR' => $peer]);
        $seen = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return \Crab\Http\Response::text($request->ip());
            }
        };
        $ip = static fn (\Crab\Http\Request $request, array $proxies): string => (string) (new \Crab\Http\TrustedProxies($proxies))->process($request, $seen)->getBody();

        // With no proxy to trust, the address is the connection's, whatever the header claims.
        self::assertSame('203.0.113.7', $from('203.0.113.7', '1.2.3.4')->ip());
        self::assertSame('', (new \Crab\Http\Request('GET', '/'))->ip());
        // From a proxy that is trusted: the first address from the right that is not a proxy's.
        self::assertSame('198.51.100.9', $ip($from('10.0.0.5', '1.2.3.4, 198.51.100.9, 10.0.0.8'), ['10.0.0.0/8']));
        self::assertSame('198.51.100.9', $ip($from('127.0.0.1', '198.51.100.9'), ['127.0.0.1']));
        self::assertSame('2001:db8::7', $ip($from('fd00::1', '2001:db8::7'), ['fd00::/8']));
        // From anyone else, the header is someone's claim and nothing more.
        self::assertSame('203.0.113.7', $ip($from('203.0.113.7', '1.2.3.4'), ['10.0.0.0/8']));
        // A proxy that sent no header, or nonsense, is itself the client as far as can be told.
        self::assertSame('10.0.0.5', $ip($from('10.0.0.5'), ['10.0.0.0/8']));
        self::assertSame('10.0.0.5', $ip($from('10.0.0.5', 'not-an-address'), ['10.0.0.0/8']));

        $this->expectException(\InvalidArgumentException::class);
        new \Crab\Http\TrustedProxies(['ten.zero']);
    }
}
