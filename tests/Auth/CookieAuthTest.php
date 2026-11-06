<?php

declare(strict_types=1);

namespace Crab\Tests\Auth;

use Crab\App;
use Crab\Auth\AuthPlugin;
use Crab\Auth\CookieAuth;
use Crab\Auth\DatabaseTokenStore;
use Crab\Auth\TokenAuthPlugin;
use Crab\Config\Config;
use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Auth\TokenStore;
use Crab\Contracts\Database\Database;
use Crab\Database\DatabasePlugin;
use Crab\Http\Request;
use Crab\Http\Response;
use Crab\Route;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class Shopper implements Identity
{
    public function __construct(
        public int $id,
    ) {}
}

final class Shoppers implements IdentityResolver
{
    public function resolve(int|string $id): ?Identity
    {
        return (int) $id === 7 ? new Shopper(7) : null;
    }
}

final class Answer implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(Identity::ATTRIBUTE);
        return Response::text($user instanceof Identity ? "user {$user->id}" : 'nobody');
    }
}

/**
 * A login action as an application writes one, password check aside.
 */
final readonly class LogIn
{
    public function __construct(
        private TokenStore $tokens,
        private CookieAuth $auth,
    ) {}

    public function handle(): Response
    {
        return Response::redirect('/account', 303)->withCookie($this->auth->cookie($this->tokens->issue(7)));
    }
}

final class CookieAuthTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/crab-cookie-' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testTheCookiesTokenAuthenticatesAndAMissingOneIsRefused(): void
    {
        [$tokens, $auth] = $this->auth();
        $token = $tokens->issue(7);
        self::assertSame([200, 'user 7', ''], $this->through($auth, 'GET', ['Cookie' => "theme=dark; auth={$token}"]));
        self::assertSame(401, $this->through($auth, 'GET', [])[0]);
        self::assertSame(401, $this->through($auth, 'GET', ['Cookie' => 'auth='])[0], 'An empty cookie is no token.');

        $login = new CookieAuth(login: '/login', tokens: $tokens, users: new Shoppers());
        $response = $login->process(new Request('GET', 'http://shop.test/account'), new Answer());
        self::assertSame([303, '/login'], [$response->getStatusCode(), $response->getHeaderLine('Location')]);
        self::assertSame(401, $login->process(new Request('POST', 'http://shop.test/orders', [
            'Sec-Fetch-Site' => 'same-origin',
        ]), new Answer())->getStatusCode(), 'Only a page is sent to log in; a form post is refused.');
    }

    public function testATokenThatNoLongerWorksIsRefusedAndItsCookieCleared(): void
    {
        [$tokens, $auth] = $this->auth();
        $revoked = $tokens->issue(7);
        $tokens->revoke($revoked);
        $orphan = $tokens->issue(99);
        foreach ([$revoked, $orphan, 'made-up'] as $token) {
            [$status, , $cookie] = $this->through($auth, 'GET', ['Cookie' => "auth={$token}"]);
            self::assertSame(401, $status);
            self::assertStringStartsWith('auth=; Max-Age=0', $cookie);
        }

        $optional = new CookieAuth(required: false, tokens: $tokens, users: new Shoppers());
        self::assertSame([200, 'nobody', ''], $this->through($optional, 'GET', []));
        [$status, $body, $cookie] = $this->through($optional, 'GET', ['Cookie' => 'auth=made-up']);
        self::assertSame([200, 'nobody'], [$status, $body], 'A page that works either way is still served.');
        self::assertStringStartsWith('auth=; Max-Age=0', $cookie);
    }

    public function testAChangingRequestMustComeFromTheApplicationsOwnPages(): void
    {
        [$tokens] = $this->auth();
        $token = $tokens->issue(7);
        $auth = new CookieAuth(origins: ['https://admin.shop.test'], tokens: $tokens, users: new Shoppers());
        $cases = [
            [['Sec-Fetch-Site' => 'same-origin'], 200],
            [['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://evil.test'], 403],
            [['Sec-Fetch-Site' => 'same-site', 'Origin' => 'http://blog.shop.test'], 403],
            [['Origin' => 'http://shop.test'], 200],
            [['Origin' => 'http://SHOP.test:80'], 200],
            [['Origin' => 'http://shop.test:8080'], 403],
            [['Origin' => 'https://shop.test'], 403],
            [['Origin' => 'https://admin.shop.test'], 200],
            [['Origin' => 'null'], 403],
            [['Origin' => 'not a url'], 403],
            [[], 403],
        ];
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            foreach ($cases as [$headers, $status]) {
                self::assertSame($status, $this->through($auth, $method, [
                    'Cookie' => "auth={$token}",
                    ...$headers,
                ])[0], $method . ' ' . json_encode($headers));
            }
        }
        self::assertSame(
            403,
            $this->through($auth, 'POST', [])[0],
            'Checked without a cookie too, as a login form is.',
        );
        self::assertSame(200, $this->through($auth, 'GET', [
            'Cookie' => "auth={$token}",
        ])[0], 'Reading is not checked.');
    }

    public function testAWebSocketHandshakeMustComeFromTheApplicationsOwnPages(): void
    {
        [$tokens, $auth] = $this->auth();
        $token = $tokens->issue(7);
        $handshake = ['Cookie' => "auth={$token}", 'Upgrade' => 'websocket', 'Connection' => 'Upgrade'];
        self::assertSame(403, $this->through($auth, 'GET', [...$handshake, 'Origin' => 'https://evil.test'])[0]);
        self::assertSame(403, $this->through($auth, 'GET', [...$handshake, 'Sec-Fetch-Site' => 'same-site'])[0]);
        self::assertSame(403, $this->through($auth, 'GET', $handshake)[0], 'A handshake without an Origin is refused.');
        self::assertSame([200, 'user 7', ''], $this->through($auth, 'GET', [
            ...$handshake,
            'Origin' => 'http://shop.test',
        ]));
    }

    public function testWithAPublicAddressOnlyItsOriginIsTheApplicationsOwn(): void
    {
        [$tokens] = $this->auth();
        // Behind a proxy that ends TLS the request says http://shop.test, and anyone can send any Host.
        $auth = new CookieAuth(tokens: $tokens, users: new Shoppers(), site: 'https://shop.test');
        self::assertSame(403, $this->through($auth, 'POST', ['Origin' => 'http://shop.test'])[0]);
        $evil = $auth->process(new Request('POST', 'http://evil.test/login', [
            'Origin' => 'http://evil.test',
        ]), new Answer());
        self::assertSame(403, $evil->getStatusCode());
        // Past the origin check, to authentication, which has no cookie to read.
        self::assertSame(401, $this->through($auth, 'POST', ['Origin' => 'https://shop.test'])[0]);
        self::assertSame(401, $this->through($auth, 'POST', ['Origin' => 'https://shop.test:443'])[0]);
    }

    public function testALoginOverAStaleCookieKeepsItsNewCookie(): void
    {
        [$tokens] = $this->auth();
        $auth = new CookieAuth(required: false, tokens: $tokens, users: new Shoppers());
        $login = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::redirect('/', 303)->withCookie(new \Crab\Http\Cookie('auth', 'new-token'));
            }
        };
        $response = $auth->process(new Request('POST', 'http://shop.test/login', [
            'Cookie' => 'auth=stale',
            'Sec-Fetch-Site' => 'same-origin',
        ]), $login);
        self::assertCount(1, $response->getHeader('Set-Cookie'));
        self::assertStringStartsWith('auth=new-token;', $response->getHeaderLine('Set-Cookie'));
    }

    public function testTheCookieLogsInAndLogoutRevokesTheToken(): void
    {
        [$tokens, $auth] = $this->auth();
        $token = $tokens->issue(7);
        self::assertSame("auth={$token}; Path=/; Secure; HttpOnly; SameSite=Lax", (string) $auth->cookie($token));
        $remembered = new CookieAuth(tokens: $tokens, users: new Shoppers(), name: 'shop', secure: false, maxAge: 3600);
        self::assertSame(
            "shop={$token}; Max-Age=3600; Path=/; HttpOnly; SameSite=Lax",
            (string) $remembered->cookie($token),
        );

        $forget = $auth->logout(new Request('POST', '/logout', ['Cookie' => "auth={$token}"]));
        self::assertNull($tokens->owner($token), 'The token stops working at once.');
        self::assertStringStartsWith('auth=; Max-Age=0;', (string) $forget);
        self::assertStringEndsWith('; Path=/; Secure; HttpOnly; SameSite=Lax', (string) $forget);
        self::assertSame(
            (string) $forget,
            (string) $auth->logout(new Request('POST', '/logout')),
            'Without a cookie it still clears one.',
        );

        foreach ([['name' => 'a b'], ['maxAge' => 0]] as $wrong) {
            try {
                new CookieAuth(...$wrong);
                self::fail('Accepted ' . json_encode($wrong));
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testThePluginBindsItFromTheSettingsForRoutesAndActions(): void
    {
        $app = new App(new Config([
            'app' => ['url' => 'http://127.0.0.1:8080'],
            'auth' => ['cookie' => ['name' => 'shop', 'login' => '/login']],
            'database' => ['connections' => ['main' => ['dsn' => 'sqlite:' . $this->file]]],
        ]));
        $app->register(AuthPlugin::class, TokenAuthPlugin::class, DatabasePlugin::class);
        $app->container->instance(IdentityResolver::class, new Shoppers());
        $this->createTokensTable($app->container->get(Database::class));
        $token = $app->container->get(TokenStore::class)->issue(7);

        Route::get('/account', static fn(Shopper $user): string => "account {$user->id}")->middleware(
            CookieAuth::class,
        );
        Route::get('/home', static fn(?Shopper $user = null): string => $user === null
            ? 'hello'
            : "hello {$user->id}")->middleware(new CookieAuth(required: false));
        Route::post('/login', LogIn::class)->middleware(new CookieAuth(required: false));

        $get = static fn(string $path, array $headers = []): ResponseInterface => $app->handle(
            new Request('GET', "http://127.0.0.1:8080{$path}", $headers),
        );
        self::assertSame('account 7', (string) $get('/account', ['Cookie' => "shop={$token}"])->getBody());
        self::assertSame(
            '/login',
            $get('/account')->getHeaderLine('Location'),
            'auth.cookie.login sends a page to log in.',
        );
        self::assertSame('hello', (string) $get('/home')->getBody());
        self::assertSame('hello 7', (string) $get('/home', ['Cookie' => "shop={$token}"])->getBody());

        $login = $app->handle(new Request('POST', 'http://127.0.0.1:8080/login', [
            'Origin' => 'http://127.0.0.1:8080',
        ]));
        self::assertSame(303, $login->getStatusCode());
        self::assertMatchesRegularExpression(
            '/^shop=[0-9a-f]{64}; Path=\/; HttpOnly; SameSite=Lax$/',
            $login->getHeaderLine('Set-Cookie'),
            'Not Secure: app.url says the application is served over plain HTTP.',
        );
        self::assertSame(403, $app->handle(new Request('POST', 'http://127.0.0.1:8080/login', [
            'Origin' => 'https://evil.test',
        ]))->getStatusCode());
    }

    public function testARoutesOptionsWinOverTheBoundOnesAndAMissingPluginStopsStartup(): void
    {
        [$tokens] = $this->auth();
        $bound = new CookieAuth(
            tokens: $tokens,
            users: new Shoppers(),
            name: 'bound',
            secure: false,
            origins: ['https://bound.test'],
        );
        $container = new \Crab\Container\Container();
        $container->instance(CookieAuth::class, $bound);
        $route = new CookieAuth(name: 'route', secure: true, origins: ['https://route.test'])->withContainer(
            $container,
        );
        self::assertStringStartsWith('route=t; Path=/; Secure;', (string) $route->cookie('t'));
        $post = static fn(CookieAuth $auth, string $origin): int => $auth->process(new Request(
            'POST',
            'http://shop.test/orders',
            ['Origin' => $origin],
        ), new Answer())->getStatusCode();
        self::assertSame(401, $post($route, 'https://route.test'), 'Allowed through to authentication.');
        self::assertSame(403, $post($route, 'https://bound.test'));
        $inherits = new CookieAuth(required: false)->withContainer($container);
        self::assertStringStartsWith('bound=t; Path=/; HttpOnly', (string) $inherits->cookie('t'));

        $this->expectExceptionMessage('list TokenAuthPlugin in app.php');
        new CookieAuth()->withContainer(new \Crab\Container\Container());
    }

    public function testANamedCookieAuthWithoutThePluginStopsAssembly(): void
    {
        $app = new \Crab\App();
        $app->router->get('/account', static fn(): string => 'account')->middleware(CookieAuth::class);
        $this->expectExceptionMessage('list TokenAuthPlugin in app.php');
        $app->router->assemble();
    }

    public function testSettingsWrittenAsEnvironmentTextWork(): void
    {
        $app = new App(new Config([
            'app' => ['url' => 'https://shop.test'],
            'auth' => ['cookie' => ['secure' => '0', 'max_age' => '3600']],
            'database' => ['connections' => ['main' => ['dsn' => 'sqlite:' . $this->file]]],
        ]));
        $app->register(AuthPlugin::class, TokenAuthPlugin::class, DatabasePlugin::class);
        $app->container->instance(IdentityResolver::class, new Shoppers());
        self::assertSame(
            'auth=t; Max-Age=3600; Path=/; HttpOnly; SameSite=Lax',
            (string) $app->container->get(CookieAuth::class)->cookie('t'),
        );
    }

    /**
     * @return array{DatabaseTokenStore, CookieAuth}
     */
    private function auth(): array
    {
        $database = new \Crab\Database\Database(fn(): \PDO => new \PDO('sqlite:' . $this->file), 1);
        $this->createTokensTable($database);
        $tokens = new DatabaseTokenStore($database);
        return [$tokens, new CookieAuth(tokens: $tokens, users: new Shoppers())];
    }

    private function createTokensTable(Database $database): void
    {
        // As token:install's migration makes it.
        $database->execute(
            'create table auth_tokens (id integer primary key autoincrement, owner_id varchar(64) not null, '
            . "name varchar(255) not null default '', token_hash varchar(64) not null unique, "
            . 'expires_at datetime null, created_at datetime not null)',
        );
    }

    /**
     * @param array<string, string> $headers
     * @return array{int, string, string} The status, the body and the Set-Cookie header.
     */
    private function through(CookieAuth $auth, string $method, array $headers): array
    {
        $response = $auth->process(new Request($method, 'http://shop.test/orders', $headers), new Answer());
        return [
            $response->getStatusCode(),
            trim((string) $response->getBody()),
            $response->getHeaderLine('Set-Cookie'),
        ];
    }
}
