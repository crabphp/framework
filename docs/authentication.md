# Authentication

- [Introduction](#introduction)
- [How it works](#how-it-works)
- [Your user](#your-user)
- [Token authentication](#token-authentication)
    - [Installation](#installation)
    - [Finding the user](#finding-the-user)
    - [Logging in](#logging-in)
    - [Protecting routes](#protecting-routes)
    - [Managing tokens](#managing-tokens)
    - [Another header](#another-header)
- [Browser socket tickets](#browser-socket-tickets)
- [Passwords](#passwords)
- [A way of your own](#a-way-of-your-own)
- [Authorization](#authorization)
- [In tests](#in-tests)

## Introduction

Authentication answers "who is making this request?". In Crab that is decided by middleware, and whatever the way, it ends the same: the middleware puts your user on the request, and an action takes that user as a parameter.

A token sent in a header and kept in your database authenticates ordinary API requests. For a browser's WebSocket handshake, exchange that authentication for a short-lived, single-use ticket. Anything else, a JWT, a signed URL, a header set by a proxy, is a few lines of middleware of your own.

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

use Crab\Contracts\Auth\Identity;

final readonly class User implements Identity
{
    public function __construct(public int $id, public string $email, public string $role = 'member')
    {
    }
}
```

Crab keeps no user table and no user model. The table is yours.

## Token authentication

### Installation

`AuthProvider` and its password hasher are registered by default by `App::configure()`, so they need nothing in `app.php`; add `TokenAuthProvider` when you use database tokens.

```php
// app.php
use App\Providers\AppProvider;
use Crab\Auth\TokenAuthProvider;

->withProviders([
    TokenAuthProvider::class,
    AppProvider::class,
])
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
// app/Providers/AppProvider.php
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

final readonly class AuthController
{
    public function __construct(private Database $db, private PasswordHasher $passwords, private TokenStore $tokens)
    {
    }

    /**
     * @return array{token: string}
     */
    public function login(#[Email] string $email, #[\SensitiveParameter] string $password): array
    {
        $row = $this->db->table('users')->where('email', $email)->first();
        $hash = $row['password'] ?? '$2y$12$.....................................................';
        if (!$this->passwords->verify($password, $hash) || $row === null) {
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

```php
$token = $tokens->issue($user, 'mobile app');                               // for a user or an id
$token = $tokens->issue($user, 'ci', new \DateTimeImmutable('+30 days'));    // one that expires

$tokens->revoke($token);                // log out: this token stops working at once
$tokens->revokeAll($user);              // log out everywhere
$tokens->owner($token);                 // the id it belongs to, or null
```

Checking a token is one query by its hash.

### Another header

The header and the word before the token are arguments. Give the middleware as an object:

```php
new TokenAuth($tokens, $users);                          // Authorization: Bearer <token>
new TokenAuth($tokens, $users, 'X-Api-Key', '');         // X-Api-Key: <token>
new TokenAuth($tokens, $users, scheme: 'Token');         // Authorization: Token <token>
new TokenAuth($tokens, $users, required: false);         // lets a request without a token through, with no user
```

Use `required: false` for a route that serves both anonymous and logged-in callers. A valid token still supplies the user. An unknown or expired token, or one whose user no longer exists, is answered `401` with `WWW-Authenticate`, so the client knows to log in again. A header with another scheme, such as `Basic` when the scheme is `Bearer`, or an empty token (`Bearer `), counts as no token and goes through anonymously.

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

`AuthProvider` binds `Crab\Contracts\Auth\Tickets` and the same service as `Crab\Auth\Tickets`. It uses the Cache contract and your IdentityResolver; both are required. TokenAuthProvider supplies the middleware's header fallback. IssueTicket works with any authentication middleware that supplies an Identity. `Tickets::issue(Identity $user): string` issues a ticket; `redeem(string $ticket): ?Identity` consumes it once and resolves its user. Storage holds only the identity id under a hash of the ticket.

| Setting | Default | |
| --- | --- | --- |
| `auth.ticket_ttl` | `30` | Positive whole seconds a ticket lives; set it in `config/auth.php` |

Ticket middleware consumes a present `ticket` query parameter. Unknown, expired, malformed, already-used tickets and users that no longer exist all receive the same 401. A present invalid ticket never falls back to a bearer token or an existing identity. Without a ticket, TokenAuth handles the header and existing request identity as usual, including `Api::actingAs()`.

For an Origin restriction, use `new Ticket(origins: ['https://shop.example.com'])`. Values match exactly; a missing or different Origin receives 403 before authentication or upgrade. The check is off by default. Keep Ticket outside a TokenAuth group: putting TokenAuth before it would require a browser to send the header it cannot set.

See [Authenticating a browser](websockets.md#authenticating-a-browser) for the client and reconnect flow. File cache storage works across workers on one machine; use a shared database or Redis store across machines. Startup refuses an array ticket cache with multiple workers.

## Passwords

`AuthProvider` binds the `PasswordHasher`, over PHP's own password hashing. Its PHP 8.4 bcrypt default uses only the first 72 bytes and rejects NUL bytes; validate passwords at registration and login, or bind a hasher with the limits your application needs:

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
use Crab\Contracts\Http\Middleware;
use Crab\Http\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ProxyAuth implements Middleware
{
    public function __construct(private Users $users)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->users->resolve($request->getHeaderLine('X-Authenticated-User'));
        if ($user === null) {
            return ErrorResponse::make($request, 401);
        }

        return $handler->handle($request->withAttribute('user', $user));
    }
}
```

Actions do not change: they still take `User $user`.

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
public function update(int $id, User $user, string $title): array
{
    $post = $this->db->table('posts')->find($id) ?? throw new HttpException(404);
    if ($post['user_id'] !== $user->id) {
        throw new HttpException(403);
    }

    $this->db->table('posts')->where('id', $id)->update(['title' => $title]);

    return [...$post, 'title' => $title];
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
