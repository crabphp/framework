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
- [Passwords](#passwords)
- [A way of your own](#a-way-of-your-own)
- [Authorization](#authorization)
- [In tests](#in-tests)

## Introduction

Authentication answers "who is making this request?". In Crab that is decided by middleware, and whatever the way, it ends the same: the middleware puts your user on the request, and an action takes that user as a parameter.

One way ships, because nearly every API starts with it: a token sent in a header and kept in your database. Anything else, a JWT, a signed URL, a header set by a proxy, is a few lines of middleware of your own.

There is no `Auth::user()`. A worker serves requests that overlap, so the user travels with the request and nowhere else.

## How it works

```php
Route::post('/login', [AuthController::class, 'login']);                  // open to all

Route::group(middleware: [TokenAuth::class], routes: function () {
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

```php
// app.php
use Crab\Auth\AuthProvider;
use Crab\Auth\TokenAuthProvider;

->withProviders([
    DatabaseProvider::class,
    AuthProvider::class,
    TokenAuthProvider::class,
    AppProvider::class,
])
```

```sh
php cua tokens:install       # writes the tokens table's migration into your migrations
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
use Crab\Contracts\Auth\PasswordHasher;
use Crab\Contracts\Auth\TokenStore;

final readonly class AuthController
{
    public function __construct(private Database $db, private PasswordHasher $passwords, private TokenStore $tokens)
    {
    }

    /**
     * @return array{token: string}
     */
    public function login(#[Email] string $email, string $password): array
    {
        $row = $this->db->table('users')->where('email', $email)->first();
        if ($row === null || !$this->passwords->verify($password, $row['password'])) {
            throw new HttpException(401, 'These credentials do not match.');
        }

        return ['token' => $this->tokens->issue($row['id'], 'login')];
    }
}
```

The client then sends the token with every request:

```text
Authorization: Bearer 3f9a...
```

A token is 64 random characters. It is shown once, by `issue()`; only its hash is stored, so reading the table gives no one a token that works.

### Protecting routes

`TokenAuth` is the middleware. Put it on a group or a route:

```php
use Crab\Auth\TokenAuth;

Route::group(middleware: [TokenAuth::class], routes: function () {
    Route::get('/me', static fn (User $user): User => $user);
});
```

A request without a valid token is answered `401` before the action runs.

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

## Passwords

`AuthProvider` binds the `PasswordHasher`, over PHP's own password hashing:

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

Any middleware can authenticate. It finds the user and passes the request on with it, or answers `401`:

```php
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
Route::group(prefix: '/admin', middleware: [TokenAuth::class, new RequireRole('admin')], routes: function () {
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

    // ...
}
```

## In tests

Issue a token and send it, as a client would:

```php
$token = $app->container->get(TokenStore::class)->issue(1);

$response = $app->handle(new Request('GET', '/me', ['Authorization' => "Bearer {$token}"]));

self::assertSame(200, $response->getStatusCode());
```

To test an action alone, call it with a user: `(new ShowMe())->handle(new User(1, 'ada@example.com'))`.
