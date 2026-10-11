<?php

declare(strict_types=1);

namespace Crab\Tests\Auth;

use Crab\App;
use Crab\Auth\AuthProvider;
use Crab\Auth\Commands\InstallTokens;
use Crab\Auth\DatabaseTokenStore;
use Crab\Auth\PasswordHasher;
use Crab\Auth\TokenAuth;
use Crab\Auth\TokenAuthProvider;
use Crab\Config\Config;
use Crab\Console\Input;
use Crab\Console\Output;
use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Auth\PasswordHasher as PasswordHasherContract;
use Crab\Contracts\Auth\TokenStore;
use Crab\Contracts\Database\Database as DatabaseContract;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Database\Migrator;
use Crab\Database\Database;
use Crab\Database\DatabaseProvider;
use Crab\Database\Migrator as DatabaseMigrator;
use Crab\Http\ErrorResponse;
use Crab\Http\Request;
use Crab\Http\Response;
use Crab\Route;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

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
 * A way of authenticating of the application's own, written as any
 * middleware is: whoever the X-Member header names.
 */
final readonly class TrustedHeader implements Middleware
{
    public function __construct(private Members $members)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->members->resolve((int) $request->getHeaderLine('X-Member'));
        if ($user === null) {
            return ErrorResponse::make($request, 401, 'Say who you are in X-Member.');
        }
        return $handler->handle($request->withAttribute('user', $user));
    }
}

/**
 * What stands behind the middleware in a test: answers with who got through.
 */
final class Reached implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(Identity::ATTRIBUTE);
        return Response::text($user instanceof Identity ? "user {$user->id}" : 'nobody');
    }
}

/**
 * Runs against SQLite always, and against MySQL too when
 * CRAB_TEST_MYSQL_DSN names a database the tests may fill.
 */
final class AuthTest extends TestCase
{
    private string $file;
    /**
     * The application's own migrations folder.
     */
    private string $migrations;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/crab-auth-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->migrations = sys_get_temp_dir() . '/crab-auth-migrations-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        foreach (glob($this->migrations . '/*.php') ?: [] as $migration) {
            unlink($migration);
        }
        @rmdir($this->migrations);
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

        // The moment is the same whatever zone it was said in: 19:30 in Bangkok is half past twelve here.
        $elsewhere = $tokens->issue(7, expiresAt: new \DateTimeImmutable('2026-01-31 20:30:00', new \DateTimeZone('Asia/Bangkok')));
        self::assertSame(7, $tokens->owner($elsewhere));
        $clock->advance('+30 minutes');
        self::assertNull($tokens->owner($elsewhere));
        // An id that only looks like a number is given back as it was issued.
        self::assertSame(['0007', '9223372036854775808'], [$tokens->owner($tokens->issue('0007')), $tokens->owner($tokens->issue('9223372036854775808'))]);
        $tokens->revokeAll('0007');
        $tokens->revokeAll('9223372036854775808');

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

    public function testTheTokenMiddlewareReadsTheHeaderItIsToldTo(): void
    {
        $tokens = new DatabaseTokenStore($this->database('sqlite'));
        $members = new Members();
        $members->known[7] = new Member(7, 'Ada');
        $token = $tokens->issue(7);
        $orphan = $tokens->issue(99);
        $through = static function (TokenAuth $auth, array $headers): array {
            $response = $auth->process(new Request('GET', '/', $headers), new Reached());
            return [$response->getStatusCode(), trim((string) $response->getBody()), $response->getHeaderLine('WWW-Authenticate')];
        };

        $bearer = new TokenAuth($tokens, $members);
        self::assertSame([200, 'user 7', ''], $through($bearer, ['Authorization' => "Bearer {$token}"]));
        self::assertSame([200, 'user 7', ''], $through($bearer, ['Authorization' => "bearer   {$token} "]));
        foreach (['', 'Bearer', 'Bearer ', "Basic {$token}", "Bearer {$token}x", "Bearer {$orphan}", $token] as $header) {
            $refused = $through($bearer, $header === '' ? [] : ['Authorization' => $header]);
            self::assertSame([401, '401 Authentication is required.', 'Bearer'], $refused, "\"{$header}\" was accepted.");
        }

        // Another header, holding the token alone.
        $key = new TokenAuth($tokens, $members, 'X-Service-Key', '');
        self::assertSame([200, 'user 7', ''], $through($key, ['X-Service-Key' => " {$token} "]));
        self::assertSame([401, 'X-Service-Key'], array_values(array_diff_key($through($key, ['Authorization' => "Bearer {$token}"]), [1 => 0])));
        self::assertSame(401, $through($key, ['X-Service-Key' => 'wrong'])[0]);
        self::assertSame(401, $through($key, ['X-Service-Key' => ''])[0]);

        // Another word before the token.
        $scheme = new TokenAuth($tokens, $members, scheme: 'Token');
        self::assertSame([200, 'user 7', ''], $through($scheme, ['Authorization' => "Token {$token}"]));
        self::assertSame([401, 'Token'], array_values(array_diff_key($through($scheme, ['Authorization' => "Bearer {$token}"]), [1 => 0])));

        // Not required: with a token the user is known, without one the request goes on with nobody.
        $optional = new TokenAuth($tokens, $members, required: false);
        self::assertSame([200, 'user 7', ''], $through($optional, ['Authorization' => "Bearer {$token}"]));
        self::assertSame([200, 'nobody', ''], $through($optional, []));
        self::assertSame([401, '401 Authentication is required.', 'Bearer'], $through($optional, ['Authorization' => 'Bearer wrong']));
    }

    /**
     * @return list<array{array<string, string>}>
     */
    public static function requestsWithoutTokens(): array
    {
        return [[[]], [['Authorization' => 'Basic abc']], [['Authorization' => 'Bearer ']], [['Authorization' => 'Bearer']]];
    }

    #[DataProvider('requestsWithoutTokens')]
    public function testOptionalAuthenticationLetsARequestWithoutATokenThroughAnonymously(array $headers): void
    {
        $tokens = new DatabaseTokenStore($this->database('sqlite'));
        $optional = new TokenAuth($tokens, new Members(), required: false);
        $response = $optional->process(new Request('GET', '/', $headers), new Reached());

        self::assertSame([200, 'nobody', ''], [$response->getStatusCode(), (string) $response->getBody(), $response->getHeaderLine('WWW-Authenticate')]);
    }

    public function testOptionalAuthenticationPassesAValidTokensUserToTheHandler(): void
    {
        $tokens = new DatabaseTokenStore($this->database('sqlite'));
        $members = new Members();
        $members->known[7] = new Member(7, 'Ada');
        $token = $tokens->issue(7);
        $optional = new TokenAuth($tokens, $members, required: false);
        $response = $optional->process(new Request('GET', '/', ['Authorization' => "Bearer {$token}"]), new Reached());

        self::assertSame([200, 'user 7', ''], [$response->getStatusCode(), (string) $response->getBody(), $response->getHeaderLine('WWW-Authenticate')]);
    }

    public function testOptionalAuthenticationRefusesAnUnknownTokenWithAChallenge(): void
    {
        $tokens = new DatabaseTokenStore($this->database('sqlite'));
        $optional = new TokenAuth($tokens, new Members(), required: false);
        $response = $optional->process(new Request('GET', '/', ['Authorization' => 'Bearer unknown']), new Reached());

        self::assertSame([401, 'Bearer'], [$response->getStatusCode(), $response->getHeaderLine('WWW-Authenticate')]);
    }

    public function testOptionalAuthenticationRefusesATokenAtItsExpiry(): void
    {
        $clock = new FrozenClock('2026-01-31 12:00:00');
        $tokens = new DatabaseTokenStore($this->database('sqlite'), clock: $clock);
        $members = new Members();
        $members->known[7] = new Member(7, 'Ada');
        $token = $tokens->issue(7, expiresAt: $clock->now()->modify('+1 hour'));
        $optional = new TokenAuth($tokens, $members, required: false);
        $request = new Request('GET', '/', ['Authorization' => "Bearer {$token}"]);

        self::assertSame('user 7', (string) $optional->process($request, new Reached())->getBody());
        $clock->advance('+1 hour');
        $response = $optional->process($request, new Reached());
        self::assertSame([401, 'Bearer'], [$response->getStatusCode(), $response->getHeaderLine('WWW-Authenticate')]);
    }

    public function testOptionalAuthenticationRefusesATokenWhoseUserNoLongerExists(): void
    {
        $tokens = new DatabaseTokenStore($this->database('sqlite'));
        $token = $tokens->issue(99);
        $optional = new TokenAuth($tokens, new Members(), required: false);
        $response = $optional->process(new Request('GET', '/', ['Authorization' => "Bearer {$token}"]), new Reached());

        self::assertSame(401, $response->getStatusCode());
    }

    public function testOptionalAuthenticationUsesTheConfiguredHeaderAndScheme(): void
    {
        $tokens = new DatabaseTokenStore($this->database('sqlite'));
        $members = new Members();
        $members->known[7] = new Member(7, 'Ada');
        $token = $tokens->issue(7);
        $key = new TokenAuth($tokens, $members, 'X-Service-Key', '', required: false);

        self::assertSame('nobody', (string) $key->process(new Request('GET', '/', ['X-Service-Key' => ' ']), new Reached())->getBody());
        self::assertSame('user 7', (string) $key->process(new Request('GET', '/', ['X-Service-Key' => " {$token} "]), new Reached())->getBody());
        $refused = $key->process(new Request('GET', '/', ['X-Service-Key' => 'wrong']), new Reached());
        self::assertSame([401, 'X-Service-Key'], [$refused->getStatusCode(), $refused->getHeaderLine('WWW-Authenticate')]);

        $scheme = new TokenAuth($tokens, $members, scheme: 'Token', required: false);
        self::assertSame('nobody', (string) $scheme->process(new Request('GET', '/', ['Authorization' => "Bearer {$token}"]), new Reached())->getBody());
        self::assertSame('user 7', (string) $scheme->process(new Request('GET', '/', ['Authorization' => "token   {$token} "]), new Reached())->getBody());
        $refused = $scheme->process(new Request('GET', '/', ['Authorization' => 'Token wrong']), new Reached());
        self::assertSame([401, 'Token'], [$refused->getStatusCode(), $refused->getHeaderLine('WWW-Authenticate')]);
    }

    public function testRoutesAreProtectedAndHandlersReceiveTheUserByType(): void
    {
        $app = new App(new Config(['database' => ['connections' => ['main' => ['dsn' => 'sqlite:' . $this->file, 'migrations' => $this->migrations]]]]));
        // In either order: nothing is needed from the database until a token is.
        $app->register(AuthProvider::class, TokenAuthProvider::class, DatabaseProvider::class);
        $app->container->instance(ClockInterface::class, new FrozenClock('2026-01-31 12:00:00'));
        $members = new Members();
        $members->known[7] = new Member(7, 'Ada');
        $members->known['deploy-bot'] = new Robot('deploy-bot');
        $app->container->instance(IdentityResolver::class, $members);
        $app->container->instance(Members::class, $members);

        // The table's migration is written into the application's own folder, once.
        self::assertSame([], $app->container->get(Migrator::class)->status(), 'The provider adds no migration of its own.');
        $out = fopen('php://memory', 'w+');
        self::assertSame(0, $app->console->run(['token:install'], new Output($out, $out)));
        $written = $this->migrations . '/2026_01_31_120000_create_auth_tokens_table.php';
        self::assertFileExists($written);
        self::assertStringContainsString("\$schema->create('auth_tokens'", (string) file_get_contents($written));
        self::assertSame(0, $app->console->run(['token:install'], new Output($out, $out)));
        rewind($out);
        self::assertSame(
            "Created {$written}\nRun `migrate` to create the table.\nYour migrations already have 2026_01_31_120000_create_auth_tokens_table; nothing was written.\n",
            stream_get_contents($out),
        );
        self::assertCount(1, glob($this->migrations . '/*.php'));
        self::assertSame(['2026_01_31_120000_create_auth_tokens_table'], $app->container->get(Migrator::class)->run());

        Route::get('/public', static fn (): string => 'anyone');
        Route::get('/maybe', static fn (?Member $user = null): array => ['name' => $user?->name]);
        Route::get('/unprotected', static fn (Member $user): array => ['name' => $user->name]);
        Route::get('/alone', static fn (Member $user): array => ['alone' => $user->id], middleware: [TokenAuth::class]);
        Route::group(middleware: [TokenAuth::class], routes: static function (): void {
            Route::get('/me', static fn (Member $user): Member => $user);
            Route::get('/whoami', static fn (Identity $user, ServerRequestInterface $request): array => [$user->id, $request->getAttribute('user') === $user]);
            Route::post('/notes/{id}', static fn (int $id, Member $user, string $text): array => [$id, $user->id, $text]);
            Route::get('/members-only', static fn (Member $user): string => 'members');
        });
        Route::group(prefix: '/internal', middleware: [new TrustedHeader($members)], routes: static function (): void {
            Route::get('/me', static fn (Member $user): array => ['via' => 'header', 'id' => $user->id]);
        });

        $tokens = $app->container->get(TokenStore::class);
        $ada = ['Authorization' => 'Bearer ' . $tokens->issue(7), 'Accept' => 'application/json'];
        $robot = ['Authorization' => 'Bearer ' . $tokens->issue('deploy-bot'), 'Accept' => 'application/json'];

        self::assertSame('anyone', (string) $app->handle(new Request('GET', '/public'))->getBody());
        self::assertSame('{"id":7,"name":"Ada"}', (string) $app->handle(new Request('GET', '/me', $ada))->getBody());
        self::assertSame('{"alone":7}', (string) $app->handle(new Request('GET', '/alone', $ada))->getBody(), 'Middleware on one route.');
        self::assertSame(401, $app->handle(new Request('GET', '/alone'))->getStatusCode());
        self::assertSame('[7,true]', (string) $app->handle(new Request('GET', '/whoami', $ada))->getBody());
        self::assertSame('["deploy-bot",true]', (string) $app->handle(new Request('GET', '/whoami', $robot))->getBody(), 'An action may take any user by the contract.');
        $note = new Request('POST', '/notes/3', $ada + ['Content-Type' => 'application/json'], '{"text":"hi"}');
        self::assertSame('[3,7,"hi"]', (string) $app->handle($note)->getBody());

        $refused = $app->handle(new Request('GET', '/me', ['Accept' => 'application/json']));
        self::assertSame([401, 'Bearer', '{"error":{"status":401,"message":"Authentication is required."}}'],
            [$refused->getStatusCode(), $refused->getHeaderLine('WWW-Authenticate'), (string) $refused->getBody()]);
        self::assertSame(401, $app->handle(new Request('GET', '/me', ['Authorization' => 'Bearer wrong']))->getStatusCode());
        self::assertSame(401, $app->handle(new Request('GET', '/members-only', $robot))->getStatusCode(), 'A logged-in user of another class is not the user this action takes.');
        self::assertSame(401, $app->handle(new Request('GET', '/unprotected', $ada))->getStatusCode(), 'Without the middleware nobody is logged in, whatever the request carries.');
        self::assertSame('{"name":null}', (string) $app->handle(new Request('GET', '/maybe', $ada))->getBody(), 'A nullable user is optional.');

        $internal = $app->handle(new Request('GET', '/internal/me', ['X-Member' => '7']));
        self::assertSame('{"via":"header","id":7}', (string) $internal->getBody());
        $other = $app->handle(new Request('GET', '/internal/me', $ada + ['Accept' => 'application/json']));
        self::assertSame([401, 'Say who you are in X-Member.'], [$other->getStatusCode(), json_decode((string) $other->getBody(), true)['error']['message']], 'Each group authenticates its own way.');

        $tokens->revokeAll(7);
        self::assertSame(401, $app->handle(new Request('GET', '/me', $ada))->getStatusCode(), 'A revoked token stops working at once.');
        unset($members->known['deploy-bot']);
        self::assertSame(401, $app->handle(new Request('GET', '/whoami', $robot))->getStatusCode(), 'So does the token of a user who is gone.');

        self::assertInstanceOf(PasswordHasher::class, $app->container->get(PasswordHasherContract::class));
        self::assertInstanceOf(TokenAuth::class, $app->container->get(TokenAuth::class));
        $app->container->get(DatabaseContract::class)->close();
    }

    public function testWhatIsMissingIsNamed(): void
    {
        // The general part needs nothing: no database, no tokens, no way of finding a user.
        $general = new App();
        $general->register(AuthProvider::class);
        self::assertInstanceOf(PasswordHasher::class, $general->container->get(PasswordHasherContract::class));
        self::assertFalse($general->console->has('token:install'));
        self::assertFalse($general->container->has(TokenStore::class));

        $bare = new App();
        $bare->register(TokenAuthProvider::class);
        self::assertTrue($bare->console->has('token:install'));
        try {
            $bare->container->get(TokenStore::class);
            self::fail('Tokens were offered without a database to keep them in.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('register the database provider, DatabaseProvider, as well as TokenAuthProvider', $error->getMessage());
        }

        $app = new App(new Config(['database' => ['connections' => ['main' => ['dsn' => 'sqlite:' . $this->file]]]]));
        $app->register(DatabaseProvider::class, TokenAuthProvider::class);
        try {
            $app->container->get(TokenAuth::class);
            self::fail('Token authentication was set up with no way to find a user.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('bind Crab\Contracts\Auth\IdentityResolver in a provider of your own: it finds one of your users by id', $error->getMessage());
        }
    }

    private function database(string $driver): Database
    {
        $db = new Database(fn (): \PDO => $this->pdo($driver), 1);
        $db->execute('drop table if exists auth_tokens');
        $db->execute('drop table if exists migrations');
        // As an application gets the table: the command writes the migration, and it is run.
        $migrator = new DatabaseMigrator($db, $this->migrations);
        $out = fopen('php://memory', 'w+');
        (new InstallTokens($migrator))->handle(new Input(), new Output($out, $out));
        self::assertCount(1, $migrator->run());
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
