<?php

declare(strict_types=1);

namespace Crab\Tests\Auth;

use Crab\App;
use Crab\Auth\ApiKey;
use Crab\Auth\Authenticate;
use Crab\Auth\AuthProvider;
use Crab\Auth\BearerToken;
use Crab\Auth\DatabaseTokenStore;
use Crab\Auth\PasswordHasher;
use Crab\Config\Config;
use Crab\Contracts\Auth\Authenticator;
use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Auth\PasswordHasher as PasswordHasherContract;
use Crab\Contracts\Auth\TokenStore;
use Crab\Contracts\Database\Database as DatabaseContract;
use Crab\Contracts\Database\Migrator;
use Crab\Contracts\Routing\Router as RouterContract;
use Crab\Database\Database;
use Crab\Database\DatabaseProvider;
use Crab\Database\Migrator as DatabaseMigrator;
use Crab\Http\Request;
use Crab\Route;
use Crab\Routing\OpenApi;
use Crab\Testing\FrozenClock;
use Crab\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final readonly class Member implements Identity
{
    public function __construct(public int $id, public string $name)
    {
    }
}

final readonly class Robot implements Identity
{
    public function __construct(public string $id)
    {
    }
}

/**
 * The application's side: users by id.
 */
final class Members implements IdentityResolver
{
    /**
     * @var array<int|string, Identity>
     */
    public array $known = [];

    public function resolve(int|string $id): ?Identity
    {
        return $this->known[$id] ?? null;
    }
}

/**
 * A way of the application's own: whoever the X-Member header names.
 */
final readonly class TrustedHeader implements Authenticator
{
    public function __construct(private Members $members)
    {
    }

    public function authenticate(ServerRequestInterface $request): ?Identity
    {
        $id = $request->getHeaderLine('X-Member');
        return $id === '' ? null : $this->members->resolve((int) $id);
    }

    public function challenge(): string
    {
        return 'Member';
    }
}

/**
 * Runs against SQLite always, and against MySQL too when
 * CRAB_TEST_MYSQL_DSN names a database the tests may fill.
 */
final class AuthTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/crab-auth-' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        if (getenv('CRAB_TEST_MYSQL_DSN') !== false) {
            $this->pdo('mysql')->exec('drop table if exists auth_tokens, migrations');
        }
    }

    public static function drivers(): array
    {
        return getenv('CRAB_TEST_MYSQL_DSN') === false ? [['sqlite']] : [['sqlite'], ['mysql']];
    }

    #[DataProvider('drivers')]
    public function testTokensAreIssuedFoundExpiredAndRevoked(string $driver): void
    {
        $db = $this->database($driver);
        $clock = new FrozenClock('2026-01-31 12:00:00');
        $tokens = new DatabaseTokenStore($db, clock: $clock);

        $token = $tokens->issue(new Member(7, 'Ada'), 'laptop');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertSame(7, $tokens->owner($token));
        self::assertNull($tokens->owner('not a token'));
        self::assertNull($tokens->owner(''));
        self::assertNotSame($token, $tokens->issue(7), 'Every token is different.');

        $row = $db->table('auth_tokens')->first();
        self::assertSame(['7', 'laptop', hash('sha256', $token), null, '2026-01-31 12:00:00'], [$row['owner_id'], $row['name'], $row['token_hash'], $row['expires_at'], $row['created_at']]);
        self::assertStringNotContainsString($token, json_encode($db->table('auth_tokens')->get()), 'The token itself is not kept.');

        $short = $tokens->issue(7, expiresAt: $clock->now()->modify('+1 hour'));
        self::assertSame(7, $tokens->owner($short));
        $clock->advance('+59 minutes');
        self::assertSame(7, $tokens->owner($short));
        $clock->advance('+1 minute');
        self::assertNull($tokens->owner($short), 'A token stops working at the moment it expires.');
        self::assertSame(7, $tokens->owner($token), 'One without an expiry goes on.');

        $robot = $tokens->issue(new Robot('deploy-bot'));
        self::assertSame('deploy-bot', $tokens->owner($robot), 'An id that is not a number stays text.');
        $tokens->revoke($token);
        self::assertNull($tokens->owner($token));
        self::assertSame('deploy-bot', $tokens->owner($robot));
        $tokens->revokeAll(7);
        self::assertSame(['deploy-bot'], $db->table('auth_tokens')->pluck('owner_id'));
        $tokens->revokeAll(new Robot('deploy-bot'));
        self::assertSame(0, $db->table('auth_tokens')->count());
        $tokens->revoke('already gone');
    }

    public function testPasswordsAreHashedAndVerified(): void
    {
        $hasher = new PasswordHasher(['cost' => 4]);
        $hash = $hasher->hash('correct horse');
        self::assertNotSame('correct horse', $hash);
        self::assertNotSame($hash, $hasher->hash('correct horse'), 'The same password hashes differently each time.');
        self::assertTrue($hasher->verify('correct horse', $hash));
        self::assertFalse($hasher->verify('wrong horse', $hash));
        self::assertFalse($hasher->verify('correct horse', 'not a hash'));
        self::assertFalse($hasher->needsRehash($hash));
        self::assertTrue((new PasswordHasher(['cost' => 5]))->needsRehash($hash), 'A hash made with other settings should be replaced.');
    }

    public function testEachWayOfAuthenticatingReadsItsOwnPartOfTheRequest(): void
    {
        $tokens = new DatabaseTokenStore($this->database('sqlite'));
        $members = new Members();
        $members->known[7] = new Member(7, 'Ada');
        $token = $tokens->issue(7);
        $orphan = $tokens->issue(99);

        $bearer = new BearerToken($tokens, $members);
        self::assertSame('Bearer', $bearer->challenge());
        self::assertSame($members->known[7], $bearer->authenticate(new Request('GET', '/', ['Authorization' => "Bearer {$token}"])));
        self::assertSame($members->known[7], $bearer->authenticate(new Request('GET', '/', ['Authorization' => "bearer   {$token} "])));
        foreach (['', 'Bearer', 'Bearer ', "Basic {$token}", "Bearer {$token}x", "Bearer {$orphan}", $token] as $header) {
            self::assertNull($bearer->authenticate(new Request('GET', '/', $header === '' ? [] : ['Authorization' => $header])), "\"{$header}\" was accepted.");
        }

        $key = new ApiKey($tokens, $members);
        self::assertSame('ApiKey header="X-Api-Key"', $key->challenge());
        self::assertSame($members->known[7], $key->authenticate(new Request('GET', '/', ['X-Api-Key' => $token])));
        self::assertNull($key->authenticate(new Request('GET', '/', ['Authorization' => "Bearer {$token}"])));
        self::assertNull($key->authenticate(new Request('GET', '/', ['X-Api-Key' => 'wrong'])));
        $named = new ApiKey($tokens, $members, 'X-Service-Key');
        self::assertSame($members->known[7], $named->authenticate(new Request('GET', '/', ['X-Service-Key' => $token])));
        self::assertNull($named->authenticate(new Request('GET', '/', ['X-Api-Key' => $token])));
    }

    public function testRoutesAreProtectedAndHandlersReceiveTheUserByType(): void
    {
        $app = new App(new Config(['database' => ['dsn' => 'sqlite:' . $this->file]]));
        $app->register(DatabaseProvider::class, AuthProvider::class);
        $members = new Members();
        $members->known[7] = new Member(7, 'Ada');
        $members->known['deploy-bot'] = new Robot('deploy-bot');
        $app->container->instance(IdentityResolver::class, $members);
        $app->container->instance(Members::class, $members);
        self::assertSame(['2026_01_01_000000_create_auth_tokens_table'], $app->container->get(Migrator::class)->run(), 'The provider brings the migration for its table.');

        Route::get('/public', static fn (): string => 'anyone');
        Route::get('/maybe', static fn (?Member $user = null): array => ['name' => $user?->name]);
        Route::get('/unprotected', static fn (Member $user): array => ['name' => $user->name]);
        Route::group(middleware: [Authenticate::class], routes: static function (): void {
            Route::get('/me', static fn (Member $user): Member => $user);
            Route::get('/whoami', static fn (Identity $user, ServerRequestInterface $request): array => [$user->id, $request->getAttribute('user') === $user]);
            Route::post('/notes/{id}', static fn (int $id, Member $user, string $text): array => [$id, $user->id, $text]);
            Route::get('/members-only', static fn (Member $user): string => 'members');
        });
        Route::group(prefix: '/internal', middleware: [new Authenticate(new TrustedHeader($members))], routes: static function (): void {
            Route::get('/me', static fn (Member $user): array => ['via' => 'header', 'id' => $user->id]);
        });

        $tokens = $app->container->get(TokenStore::class);
        $ada = ['Authorization' => 'Bearer ' . $tokens->issue(7), 'Accept' => 'application/json'];
        $robot = ['Authorization' => 'Bearer ' . $tokens->issue('deploy-bot'), 'Accept' => 'application/json'];

        self::assertSame('anyone', (string) $app->handle(new Request('GET', '/public'))->getBody());
        self::assertSame('{"id":7,"name":"Ada"}', (string) $app->handle(new Request('GET', '/me', $ada))->getBody());
        self::assertSame('[7,true]', (string) $app->handle(new Request('GET', '/whoami', $ada))->getBody());
        self::assertSame('["deploy-bot",true]', (string) $app->handle(new Request('GET', '/whoami', $robot))->getBody(), 'A handler may take any user by the contract.');
        $note = new Request('POST', '/notes/3', $ada + ['Content-Type' => 'application/json'], '{"text":"hi"}');
        self::assertSame('[3,7,"hi"]', (string) $app->handle($note)->getBody());

        $refused = $app->handle(new Request('GET', '/me', ['Accept' => 'application/json']));
        self::assertSame([401, 'Bearer', '{"error":{"status":401,"message":"Authentication is required."}}'],
            [$refused->getStatusCode(), $refused->getHeaderLine('WWW-Authenticate'), (string) $refused->getBody()]);
        self::assertSame(401, $app->handle(new Request('GET', '/me', ['Authorization' => 'Bearer wrong']))->getStatusCode());
        self::assertSame(401, $app->handle(new Request('GET', '/members-only', $robot))->getStatusCode(), 'A logged-in user of another class is not the user this handler takes.');
        self::assertSame(401, $app->handle(new Request('GET', '/unprotected', $ada))->getStatusCode(), 'Without the middleware nobody is logged in, whatever the request carries.');
        self::assertSame('{"name":null}', (string) $app->handle(new Request('GET', '/maybe', $ada))->getBody(), 'A nullable user is optional.');

        $internal = $app->handle(new Request('GET', '/internal/me', ['X-Member' => '7']));
        self::assertSame('{"via":"header","id":7}', (string) $internal->getBody());
        $challenge = $app->handle(new Request('GET', '/internal/me', $ada));
        self::assertSame([401, 'Member'], [$challenge->getStatusCode(), $challenge->getHeaderLine('WWW-Authenticate')], 'Each group authenticates its own way.');

        $tokens->revokeAll(7);
        self::assertSame(401, $app->handle(new Request('GET', '/me', $ada))->getStatusCode(), 'A revoked token stops working at once.');
        unset($members->known['deploy-bot']);
        self::assertSame(401, $app->handle(new Request('GET', '/whoami', $robot))->getStatusCode(), 'So does the token of a user who is gone.');

        self::assertInstanceOf(PasswordHasher::class, $app->container->get(PasswordHasherContract::class));
        self::assertInstanceOf(BearerToken::class, $app->container->get(Authenticator::class));
        $paths = (new OpenApi($app->container->get(RouterContract::class), new Validator()))->document('A', '1')['paths'];
        self::assertSame(['description' => 'Authentication is required.'], $paths['/me']['get']['responses']['401']);
        self::assertArrayNotHasKey('401', $paths['/maybe']['get']['responses']);
        self::assertArrayNotHasKey('401', $paths['/public']['get']['responses']);
        $app->container->get(DatabaseContract::class)->close();
    }

    public function testWhatIsMissingIsNamed(): void
    {
        $bare = new App();
        try {
            $bare->register(AuthProvider::class);
            self::fail('AuthProvider registered without a database.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('register the database provider, DatabaseProvider, before it', $error->getMessage());
        }

        $app = new App(new Config(['database' => ['dsn' => 'sqlite:' . $this->file]]));
        $app->register(DatabaseProvider::class, AuthProvider::class);
        try {
            $app->container->get(Authenticate::class);
            self::fail('Authentication was set up with no way to find a user.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('bind Crab\Contracts\Auth\IdentityResolver in a provider of your own: it finds one of your users by id', $error->getMessage());
        }
    }

    private function database(string $driver): Database
    {
        $db = new Database(fn (): \PDO => $this->pdo($driver), 1);
        $db->execute('drop table if exists auth_tokens');
        $db->execute('drop table if exists migrations');
        $migrator = new DatabaseMigrator($db, dirname(__DIR__, 2) . '/src/Auth/migrations');
        $migrator->run();
        return $db;
    }

    private function pdo(string $driver): \PDO
    {
        if ($driver === 'mysql') {
            return new \PDO(getenv('CRAB_TEST_MYSQL_DSN'), getenv('CRAB_TEST_MYSQL_USER') ?: 'root', getenv('CRAB_TEST_MYSQL_PASSWORD') ?: '');
        }
        return new \PDO('sqlite:' . $this->file);
    }
}
