# Authentication

- [Introduction](#introduction)
- [How it works](#how-it-works)
- [Your user](#your-user)
    - [The user in middleware](#the-user-in-middleware)
- [Token authentication](#token-authentication)
    - [Installation](#installation)
    - [Finding the user](#finding-the-user)
    - [Logging in](#logging-in)
    - [Protecting routes](#protecting-routes)
    - [Managing tokens](#managing-tokens)
    - [Another header](#another-header)
- [Cookie authentication](#cookie-authentication)
    - [Logging in and out](#logging-in-and-out)
    - [Protecting pages](#protecting-pages)
    - [Requests from other sites](#requests-from-other-sites)
    - [Cookie settings](#cookie-settings)
- [Browser socket tickets](#browser-socket-tickets)
- [Passwords](#passwords)
- [A way of your own](#a-way-of-your-own)
- [Authorization](#authorization)
- [In tests](#in-tests)

## Introduction

Authentication answers "who is making this request?". In Crab that is decided by middleware, and whatever the way, it ends the same: the middleware puts your user on the request, and an action takes that user as a parameter.

A token sent in a header and kept in your database authenticates ordinary API requests. Pages a browser loads use the same token in a cookie. For a browser's WebSocket handshake, exchange that authentication for a short-lived, single-use ticket. Anything else, a JWT, a signed URL, a header set by a proxy, is a few lines of middleware of your own.

There is no `Auth::user()`. A worker serves requests that overlap, so the user travels with the request and nowhere else.

## How it works

```php
use App\User;
use Crab\Auth\TokenAuth;

Route::post('/login', [AuthController::class, 'login']);                  // open to all

Route::middleware(TokenAuth::class)->group(function () {
    Route::get('/me', static fn (User $user): User => $user);            // the logged-in user, by type
    Route::post('/posts', [PostController::class, 'store']);
});
```

- The middleware reads the request, finds the user, and passes the request on with the user as its `user` attribute, or answers `401`.
- An action parameter whose class implements `Identity` receives that user.
- A route with such a parameter and no user on the request is answered `401`. Make the parameter nullable (`?User $user`) for a route that works either way.

## Your user

Your user is a class of your own with a public `$id`:

```php
// app/User.php
namespace App;

use Crab\Auth\FromRequest;
use Crab\Contracts\Auth\Identity;

final readonly class User implements Identity
{
    use FromRequest;

    public function __construct(public int $id, public string $email, public string $role = 'member')
    {
    }
}
```

Crab keeps no user table and no user model. The table is yours.

### The user in middleware

An action takes the user as a parameter. Middleware, and an error renderer, read it from the request. `FromRequest` gives your class `fromRequest()`, which returns the user typed as your class, or `null` when there is none or it is of another class:

```php
if (User::fromRequest($request)?->isAdmin() !== true) {
    return ErrorResponse::make($request, 403);
}
```

Where only the id matters, `$request->user()` gives the user as an `Identity`: `$request->user()?->id`.

## Token authentication

### Installation

`AuthPlugin` and its password hasher are registered by default by `App::configure()`, so they need nothing in `app.php`; add `TokenAuthPlugin` when you use database tokens.

```php
// app.php
use App\Setup;
use Crab\Auth\TokenAuthPlugin;

->withPlugins([
    TokenAuthPlugin::class,
])
->withSetup(Setup::class)
```

```sh
php cua token:install       # writes the tokens table's migration into your migrations
php cua migrate
```

The migration for `auth_tokens` is yours to read and change.

### Finding the user

A token records whose it is, an id. You say how that id becomes one of your users, by binding `IdentityResolver`:

```php
// app/Users.php
namespace App;

use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Database\Database;

final readonly class Users implements IdentityResolver
{
    public function __construct(private Database $db)
    {
    }

    public function resolve(int|string $id): ?Identity
    {
        $row = $this->db->table('users')->find($id);

        return $row === null ? null : new User($row['id'], $row['email'], $row['role']);
    }
}
```

```php
// app/Setup.php
$container->singleton(IdentityResolver::class, Users::class);
```

### Logging in

Check the password and issue a token:

```php
use Crab\Contracts\Database\Database;
use Crab\Contracts\Auth\PasswordHasher;
use Crab\Contracts\Auth\TokenStore;
use Crab\Http\HttpException;
use Crab\Validation\Email;

final readonly class LoginInput
{
    public function __construct(
        #[Email]
        public string $email,
        #[\SensitiveParameter]
        public string $password,
    ) {}
}

final readonly class AuthController
{
    public function __construct(private Database $db, private PasswordHasher $passwords, private TokenStore $tokens)
    {
    }

    /**
     * @return array{token: string}
     */
    public function login(LoginInput $input): array
    {
        $row = $this->db->table('users')->where('email', $input->email)->first();
        $hash = $row['password'] ?? '$2y$12$.....................................................';
        if (!$this->passwords->verify($input->password, $hash) || $row === null) {
            throw new HttpException(401, 'These credentials do not match.');
        }

        return ['token' => $this->tokens->issue($row['id'], 'login')];
    }
}
```

The fixed dummy hash makes a missing account perform password verification too. Keep its algorithm and cost aligned with stored passwords; this example uses PHP 8.4's default bcrypt cost of 12. Do not generate the dummy hash during login. `#[\SensitiveParameter]` keeps the password out of a stack trace, such as the one the debug page shows; the log writes traces without arguments.

The client then sends the token with every request:

```text
Authorization: Bearer 3f9a...
```

A token is 64 random characters. It is shown once, by `issue()`; only its hash is stored, so reading the table gives no one a token that works.

### Protecting routes

`TokenAuth` is the middleware. Put it on a group or a route:

```php
use Crab\Auth\TokenAuth;

Route::middleware(TokenAuth::class)->group(function () {
    Route::get('/me', static fn (User $user): User => $user);
});
```

With the default `required: true`, a request without an existing `Identity` needs a valid token or receives `401`; an existing identity bypasses token parsing.

### Managing tokens

Take `Crab\Contracts\Clock\Clock $clock` in the class's constructor and use it for expiry, so tests can supply the same clock as the token store.

```php
$token = $tokens->issue($user, 'mobile app');                               // for a user or an id
$token = $tokens->issue($user, 'ci', $clock->now()->modify('+30 days'));    // one that expires
$token = $tokens->issue($user, 'deploy', expiresAt: false);                 // one that never does

$tokens->revoke($token);                // log out: this token stops working at once
$tokens->revokeAll($user);              // log out everywhere
$tokens->owner($token);                 // the id it belongs to, or null
```

Checking a token is one query by its hash.

A token issued without an expiry lives for `auth.token_lifetime` seconds, and for ever while that is unset, as it is by default. Set it so that a token taken from a client stops working on its own:

```php
// config/auth.php
return [
    'token_lifetime' => 30 * 86_400,    // thirty days
];
```

It applies when a token is issued, so tokens already issued keep the expiry they were given. An expiry passed to `issue()` is used in its place, and `expiresAt: false` issues one that never expires, such as a deploy script's.

### Another header

The header and the word before the token are arguments. Give the middleware as an object:

```php
new TokenAuth($tokens, $users);                          // Authorization: Bearer <token>
new TokenAuth($tokens, $users, 'X-Api-Key', '');         // X-Api-Key: <token>
new TokenAuth($tokens, $users, scheme: 'Token');         // Authorization: Token <token>
new TokenAuth($tokens, $users, required: false);         // lets a request without a token through, with no user
```

Use `required: false` for a route that serves both anonymous and logged-in callers. A valid token still supplies the user. An unknown or expired token, or one whose user no longer exists, is answered `401` with `WWW-Authenticate`, so the client knows to log in again. A header with another scheme, such as `Basic` when the scheme is `Bearer`, or an empty token (`Bearer `), counts as no token and goes through anonymously.

## Cookie authentication

For pages a browser loads, such as server-rendered HTML, the user's token travels in a cookie. It is the same database token that `TokenAuth` reads from a header, with the same `TokenStore` and `IdentityResolver`, so there is no session: the cookie holds the token, and the token store says whose it is. `TokenAuthPlugin` binds `CookieAuth`.

### Logging in and out

Check the password as for a token, then set the cookie on the response:

```php
use Crab\Auth\CookieAuth;
use Crab\Contracts\Auth\TokenStore;
use Crab\Http\Response;

// LoginInput is the one from Logging in, above.
final readonly class LogIn
{
    public function __construct(private Users $users, private TokenStore $tokens, private CookieAuth $auth)
    {
    }

    public function handle(LoginInput $input): Response
    {
        $user = $this->users->withPassword($input->email, $input->password)
            ?? throw new HttpException(401, 'These credentials do not match.');

        return Response::redirect('/account', 303)->withCookie($this->auth->cookie($this->tokens->issue($user, 'browser')));
    }
}
```

The cookie is `HttpOnly`, so the page's JavaScript cannot read the token, and `SameSite=Lax`. It is `Secure` unless `app.url` starts with `http://`, as while developing. A secure cookie is named `__Host-auth`: a browser keeps a cookie with that prefix only for your host, on path `/`, over HTTPS, so a page on another subdomain of your domain cannot set one of the same name to log your users in as someone else. Over plain HTTP the prefix cannot be used and the cookie is named `auth`.

To log out, revoke the token and clear the cookie in one step:

```php
public function handle(ServerRequestInterface $request): Response
{
    return Response::redirect('/', 303)->withCookie($this->auth->logout($request));
}
```

### Protecting pages

```php
use Crab\Auth\CookieAuth;

Route::post('/login', LogIn::class)->middleware(new CookieAuth(required: false));

Route::middleware(CookieAuth::class)->group(function () {
    Route::get('/account', ShowAccount::class);
    Route::post('/logout', LogOut::class);
});

Route::get('/', ShowHome::class)->middleware(new CookieAuth(required: false));    // a user when there is one
```

Actions take `User $user` as with tokens. A request without a known user is answered `401`, or, with `login: '/login'` on the middleware or `auth.cookie.login` set, a `GET` or `HEAD` request is sent to that page. A cookie whose token no longer works, because it expired or was revoked elsewhere, is cleared in the same response; with `required: false` the page is served without a user.

### Requests from other sites

A browser sends cookies with requests that other sites start, so any request that can change something must come from your own pages: one with a method other than `GET`, `HEAD`, `OPTIONS` or `TRACE`, and every WebSocket handshake, which a browser makes with the page's cookies too. `CookieAuth` checks this whether or not there is a cookie, which covers the login form too:

- the browser's `Sec-Fetch-Site` header says `same-origin`, or
- its `Origin` header is the application's own origin, or one in `auth.cookie.origins`.

The application's own origin is `app.url`'s. Without `app.url` it is the request's, as its scheme and `Host` say, which a proxy that ends TLS changes to `http`: set `app.url` to the public address in production. Anything else is answered `403`, a request with neither header included. Every current browser sends them, so forms need no hidden token. Requests that only read are not checked, so keep `GET` routes free of side effects.

Keep APIs called by other programs on `TokenAuth`: a program has no cookie and sends no `Origin`.

### Cookie settings

The bound `CookieAuth` reads `auth.cookie` from `config/auth.php`, and `app.url` for the application's own origin. An object on a route, `new CookieAuth(required: false)` or `new CookieAuth(login: '/login')`, takes everything it does not set from it. Without `TokenAuthPlugin`, a route that names `CookieAuth` stops the server from starting.

| Setting | Default | |
| --- | --- | --- |
| `auth.cookie.name` | `null` | The cookie's name; `null` is `__Host-auth` when the cookie is secure and `auth` otherwise. Keep the `__Host-` prefix in production: without it, a sibling subdomain can shadow the cookie |
| `auth.cookie.max_age` | `null` | Seconds the browser keeps it; `null` until the browser closes. The token's own expiry still applies |
| `auth.cookie.login` | `null` | Where a page that needs a user sends the browser, such as `/login` |
| `auth.cookie.origins` | `[]` | Other origins whose pages may send changing requests, such as `https://admin.example.com` |
| `auth.cookie.secure` | `null` | Whether the cookie needs HTTPS; `null` means yes unless `app.url` starts with `http://` |

## Browser socket tickets

A browser cannot set `Authorization` on a native WebSocket. Declare an authenticated ticket endpoint and use `Ticket` on the socket route:

```php
// routes/web.php
use App\Actions\WatchOrder;
use Crab\Auth\IssueTicket;
use Crab\Auth\Ticket;
use Crab\Auth\TokenAuth;
use Crab\Route;

Route::post('/ws/ticket', IssueTicket::class)->middleware(TokenAuth::class);
Route::websocket('/orders/{id}/live', WatchOrder::class)->middleware(Ticket::class);
```

`AuthPlugin` binds `Crab\Contracts\Auth\Tickets` and the same service as `Crab\Auth\Tickets`. It uses the Cache contract and your IdentityResolver; both are required. TokenAuthPlugin supplies the middleware's header fallback. IssueTicket works with any authentication middleware that supplies an Identity. `Tickets::issue(Identity $user): string` issues a ticket; `redeem(string $ticket): ?Identity` consumes it once and resolves its user. Storage holds only the user's class and id under a hash of the ticket, and a ticket is redeemed only for a user of that class: with a `Customer` and a `User` that share an id, a customer's ticket never comes back as the user.

| Setting | Default | |
| --- | --- | --- |
| `auth.ticket_ttl` | `30` | Positive whole seconds a ticket lives; set it in `config/auth.php` |
| `auth.token_lifetime` | none | Positive whole seconds a token issued without an expiry lives; never while unset. See [Managing tokens](#managing-tokens) |

Ticket middleware consumes a present `ticket` query parameter. Unknown, expired, malformed, already-used tickets and users that no longer exist all receive the same 401. A present invalid ticket never falls back to a bearer token or an existing identity. Without a ticket, TokenAuth handles the header and existing request identity as usual, including `Api::actingAs()`.

For an Origin restriction, use `new Ticket(origins: ['https://shop.example.com'])`. Values match exactly; a missing or different Origin receives 403 before authentication or upgrade. The check is off by default. Keep Ticket outside a TokenAuth group: putting TokenAuth before it would require a browser to send the header it cannot set.

See [Authenticating a browser](websockets.md#authenticating-a-browser) for the client and reconnect flow. File cache storage works across workers on one machine; use a shared database or Redis store across machines. Startup refuses an array ticket cache with multiple workers.

## Passwords

`AuthPlugin` binds the `PasswordHasher`, over PHP's own password hashing. Its PHP 8.4 bcrypt default uses only the first 72 bytes and rejects NUL bytes; validate passwords at registration and login, or bind a hasher with the limits your application needs:

```php
$hash = $passwords->hash($password);                     // store this, never the password

if ($passwords->verify($password, $row['password'])) {
    if ($passwords->needsRehash($row['password'])) {
        // The algorithm's cost was raised since: store a new hash now that you have the password.
        $this->db->table('users')->where('id', $row['id'])->update(['password' => $passwords->hash($password)]);
    }
}
```

## A way of your own

Any middleware can authenticate. It finds the user and passes the request on with it, or answers `401`; use this header example only behind a trusted proxy that overwrites `X-Authenticated-User` and prevents direct access to the application:

```php
use App\Users;
use Crab\Contracts\Http\Handler;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Http\Request;
use Crab\Http\ErrorResponse;
use Psr\Http\Message\ResponseInterface;

final readonly class ProxyAuth implements Middleware
{
    public function __construct(private Users $users)
    {
    }

    public function handle(Request $request, Handler $next): ResponseInterface
    {
        $user = $this->users->resolve($request->getHeaderLine('X-Authenticated-User'));
        if ($user === null) {
            return ErrorResponse::make($request, 401);
        }

        return $next->handle($request->withAttribute('user', $user));
    }
}
```

Actions do not change: they still take `User $user`. Middleware that runs after it reads the user from the request with `User::fromRequest($request)`, typed as your class, or `null` when there is none or it is of another class; see [The user in middleware](#the-user-in-middleware).

```php
if (User::fromRequest($request)?->isAdmin() !== true) {
    return ErrorResponse::make($request, 403);
}
```

## Authorization

What a user may do is your application's rule, and Crab ships no roles or permissions component. Two places cover most needs.

For "only admins may use these routes", a middleware with an option; see [Middleware with options](middleware.md#middleware-with-options):

```php
Route::prefix('/admin')->middleware(TokenAuth::class, new RequireRole('admin'))->group(function () {
    // ...
});
```

For "only the owner may change this post", a check in the action, where the record is at hand:

```php
public function update(int $id, User $user, #[Fields(['title' => 'string'])] Data $changes): array
{
    $post = $this->db->table('posts')->find($id) ?? throw new HttpException(404);
    if ($post['user_id'] !== $user->id) {
        throw new HttpException(403);
    }

    $this->db->table('posts')->where('id', $id)->update(['title' => $changes->title]);

    return [...$post, 'title' => $changes->title];
}
```

## In tests

Issue a token and send it, as a client would:

```php
use Crab\Http\Request;

$token = $app->container->get(TokenStore::class)->issue(1);

$response = $app->handle(new Request('GET', '/me', ['Authorization' => "Bearer {$token}"]));

self::assertSame(200, $response->getStatusCode());
```

To test an action alone, call it with a user: `(new ShowMe())->handle(new User(1, 'ada@example.com'))`.
