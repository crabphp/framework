# Routing

- [Introduction](#introduction)
- [Basic routes](#basic-routes)
- [Route parameters](#route-parameters)
    - [Constraining a parameter](#constraining-a-parameter)
- [Route groups](#route-groups)
- [Middleware on a route](#middleware-on-a-route)
- [Named routes](#named-routes)
- [Socket routes](#socket-routes)
- [When no route matches](#when-no-route-matches)
- [Listing routes](#listing-routes)
- [Several route files](#several-route-files)

## Introduction

A route joins a method and a path to the code that answers it. Routes are declared in `routes/web.php` with `Route::`, once, before the server starts its workers. A route that cannot work, such as one naming a class that does not exist, stops the server from starting and says which route it was.

## Basic routes

```php
// routes/web.php
use App\Actions\ShowUser;
use App\Actions\UserController;
use Crab\Route;

Route::get('/ping', static fn (): string => 'pong');

Route::get('/users/{id}', ShowUser::class);                     // a class with handle()
Route::post('/users', [UserController::class, 'store']);        // a class and a method
Route::put('/users/{id}', [UserController::class, 'replace']);
Route::patch('/users/{id}', [UserController::class, 'update']);
Route::delete('/users/{id}', [UserController::class, 'destroy']);

Route::add('OPTIONS', '/users', [UserController::class, 'options']);    // any other method
```

The second argument is the action: a closure, the name of a class with `handle()`, or a class and the name of one of its methods. [Actions](actions.md) describes what an action takes and returns.

Use a closure for a line or two. Use a class as soon as the action needs a service, such as the database: the container makes the class and gives its constructor what it asks for.

> [!NOTE]
> `Route::get()` is `$app->router->get()` under a shorter name. It is static because routes are fixed before any request arrives. There is no static name for the request or the user, which differ per request.

## Route parameters

A `{name}` in the path matches one segment and reaches the action as the parameter of that name:

```php
Route::get('/users/{id}/posts/{slug}', static function (int $id, string $slug): array {
    return ['user' => $id, 'post' => $slug];
});
```

The parameter's type is checked. With `int $id`, a request for `/users/abc/posts/x` is answered `422` before the action runs. A parameter may be `string`, `int`, `float` or `bool`.

### Constraining a parameter

`{name:expression}` matches a regular expression in place of one segment. Use it when two routes would otherwise overlap, or to match across slashes:

```php
Route::get('/files/{path:.+}', ShowFile::class);              // "docs/2026/report.pdf"
Route::get('/posts/{year:\d{4}}', ListPostsOfYear::class);    // only four digits
```

## Route groups

Routes that share a path prefix, middleware, or both go in a group:

```php
use Crab\Auth\TokenAuth;

Route::prefix('/admin')->middleware(TokenAuth::class)->group(function () {
    Route::get('/users', [AdminController::class, 'users']);          // GET /admin/users
    Route::get('/users/{id}', [AdminController::class, 'user']);      // GET /admin/users/{id}
});
```

A group starts with `Route::prefix()` or `Route::middleware()`, takes the other as a further step if it needs both, and ends with `group()`. Groups may be nested: an inner group's prefix follows the outer one's, and its middleware runs inside the outer one's.

```php
Route::prefix('/api')->group(function () {
    Route::get('/status', ShowStatus::class);                          // open to all

    Route::middleware(TokenAuth::class)->group(function () {
        Route::get('/me', ShowMe::class);                              // GET /api/me, with a token
    });
});
```

## Middleware on a route

`->middleware()` on a route runs around that route alone, inside its groups' middleware. Each route is declared in one statement, and what follows it on that line says more about it:

```php
Route::get('/me', [UserController::class, 'me'])->middleware(TokenAuth::class);
Route::post('/reports', CreateReport::class)->middleware(TokenAuth::class, new RequireRole('analyst'));
```

Give a class name, or an object when the middleware takes options. For `TokenAuth`, add `TokenAuthProvider` as shown in [Authentication](authentication.md#installation); see [Middleware](middleware.md#around-some-routes).

## Named routes

`->name()` gives a route a name, so that code can write its path or its full URL without repeating the pattern:

```php
Route::get('/orders/{id}', ShowOrder::class)->name('orders.show');
```

Ask for `Crab\Contracts\Routing\Urls` to write a full URL. It works anywhere, in an action, a job, a listener or a command, because it reads the application's address from the `app.url` setting rather than from a request:

```php
use Crab\Contracts\Routing\Urls;

final readonly class SendOrderConfirmation
{
    public function __construct(private Urls $urls, private Mailer $mailer)
    {
    }

    public function handle(int $orderId): void
    {
        $link = $this->urls->to('orders.show', ['id' => $orderId]);   // https://api.example.com/orders/12
        // ...
    }
}
```

```ini
# .env
APP_URL=https://api.example.com
```

The router writes the path alone, with no setting needed:

```php
$router->path('orders.show', ['id' => 12]);                       // /orders/12
$router->path('orders.show', ['id' => 12], ['tab' => 'items']);         // /orders/12?tab=items
$router->route('orders.show');                                     // its RouteInfo, or null
```

- **Names are unique.** A second route with a name already taken stops the application from starting, naming both routes. A route has one name.
- **Both chain:** `Route::get('/orders/{id}', ShowOrder::class)->name('orders.show')->middleware(TokenAuth::class);`
- **Parameters are checked.** One that is missing, one the pattern does not have, or a value its expression refuses, such as `abc` for `{id:\d+}`, throws and names the route. What goes after `?` is the third argument.
- **Values are encoded.** A parameter matches one path segment, so a `/` in its value is encoded; one with an expression of its own, such as `{path:.+}`, keeps its slashes. A value may be text, a whole number or a backed enum.
- **Groups do not name.** A route in a group writes its whole name: `admin.users.show`.
- **`route:list` shows the names** once a route has one.

## Socket routes

A socket action keeps a connection open, with the same groups and middleware as an HTTP route:

```php
use App\Actions\WatchOrder;

Route::websocket('/orders/{id}/live', WatchOrder::class)->middleware(TokenAuth::class);
```

The action declares `handle(Socket $socket, int $id, ...)`. The action starts inside the HTTP pipeline. Its first socket use offers the upgrade to middleware, and it continues after the wire 101. Checks before that use can still answer HTTP errors. `route:list` lists it as `WS`. See [WebSockets](websockets.md) for sending, receiving, authentication and testing.

## When no route matches

A path no route has is answered `404`. A path that exists for another method is answered `405` with an `Allow` header listing the methods it has. `HEAD` falls back to a matching `GET` route unless a `HEAD` route exists, and `GET` adds `HEAD` to `Allow`. Both errors have the same body as any other error, JSON when the client accepts it; see [Error handling](errors.md).

Both pass back through the middleware around every request, which is how a `404` still carries its CORS headers.

## Listing routes

```sh
php cua route:list
```

It prints every route with its name, when routes have one, its action and route and group middleware; global middleware from `app.php` and providers is not shown.

## Several route files

When `routes/web.php` grows, split it and name each file in `app.php`:

```php
// app.php
->withRouting(
    routes: ['routes/web.php', 'routes/admin.php'],
    commands: 'routes/console.php',
)
```

A package adds routes from its provider, through the router in the container; see [Services and providers](providers.md#reaching-the-application-from-a-provider).
