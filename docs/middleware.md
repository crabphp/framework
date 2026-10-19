# Middleware

- [Introduction](#introduction)
- [Writing middleware](#writing-middleware)
- [Around every request](#around-every-request)
- [Around some routes](#around-some-routes)
- [The order they run in](#the-order-they-run-in)
- [Middleware with options](#middleware-with-options)
    - [Options and services](#options-and-services)
- [Passing data to the action](#passing-data-to-the-action)
- [Middleware that ships](#middleware-that-ships)
    - [CORS](#cors)
    - [Request ids](#request-ids)
    - [Access logging](#access-logging)
    - [Rate limiting](#rate-limiting)
    - [Limiting work in hand](#limiting-work-in-hand)

## Introduction

Middleware is code that runs around a request: before it reaches the route, after the route has answered, or instead of the route. Use it for what many routes share and none should repeat: authentication, a header on every response, a rate limit, a tenant looked up from the host name.

There is one kind, PSR-15's, so middleware from any PSR-15 library works. There are two places to use it, around every request and around some routes, and the same class works in both.

## Writing middleware

```sh
php cua make:middleware RequireJson
```

writes a pass-through stub at `app/Http/RequireJson.php`. Edit it to check JSON and add a response header:

```php
namespace App\Http;

use Crab\Contracts\Http\Middleware;
use Crab\Http\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RequireJson implements Middleware
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!str_contains($request->getHeaderLine('Content-Type'), 'json')) {
            return ErrorResponse::make($request, 415);          // answer here; the route is never reached
        }

        $response = $handler->handle($request);                 // let it through

        return $response->withHeader('X-Api-Version', '2');     // and add to what comes back
    }
}
```

`$handler->handle($request)` is "everything inside this middleware". Code before it runs on the way in, code after it on the way out, and returning without calling it stops the request here.

A middleware class is made by the container, so its constructor can ask for services. Autowired and singleton middleware are kept per worker; a transient `bind()` may make one per stack, so keep request state out of properties.

## Around every request

Set it in `app.php`. The framework starts the list with its own, `App::MIDDLEWARE`, which is the error handler, and you say how yours relate to it:

```php
// app.php
use Crab\App;
use Crab\Http\RequestId;
use App\Http\AddApiVersion;

return App::configure(__DIR__)
    ->withMiddleware([AddApiVersion::class])                // after the framework's
    ->withMiddleware(prepend: [RequestId::class])           // before them, outermost
    ->create();
```

To change the framework's own part, use one of these two, not both:

```php
->withMiddleware(remove: [ErrorHandler::class])             // without one of the framework's
->withMiddleware(only: [MyErrors::class])                   // these in place of the framework's
```

Middleware around every request also runs when no route matches. That is how a `404` still gets its CORS headers.

> [!WARNING]
> Without the error handler nothing turns an exception into a response for the client: the server answers a bare `500`. Replace it rather than remove it.

## Around some routes

Put it on a route or on a group:

```php
use Crab\Auth\TokenAuth;

Route::get('/me', [UserController::class, 'me'])->middleware(TokenAuth::class);

Route::prefix('/admin')->middleware(TokenAuth::class, RequireAdmin::class)->group(function () {
    Route::get('/users', [AdminController::class, 'users']);
});
```

A route's middleware runs only once its route has matched. The same class given by a group and again by a route inside it runs once.

`php cua route:list` shows route and group middleware; global middleware from `app.php` and providers is not shown.

## The order they run in

```text
request  →  every request's  →  the groups'  →  the route's  →  action
```

In each list the first is outermost: it meets the request first and sees the response last. So `prepend:` is the place for what must see everything, such as CORS, and a `401` from a route's middleware still passes back through the middleware around every request.

## Middleware with options

Options go to the constructor, and you give an object in place of a class name:

```php
use Crab\Contracts\Http\Middleware;
use Crab\Http\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RequireRole implements Middleware
{
    public function __construct(private string $role)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null || $user->role !== $this->role) {
            return ErrorResponse::make($request, 403);
        }

        return $handler->handle($request);
    }
}
```

```php
Route::middleware(TokenAuth::class, new RequireRole('admin'))->group(function () {
    // ...
});
```

There are no string options such as `'role:admin'`. Two objects of one class, `new RequireRole('admin')` and `new RequireRole('billing')`, are two middleware and both run.

### Options and services

An object you make with `new` gets its options and nothing else: no container is at hand in a routes file. Middleware that needs a service as well implements `UsesContainer`, and is asked for itself with the service before the first request:

```php
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Http\UsesContainer;
use Crab\Http\ErrorResponse;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RequirePlan implements Middleware, UsesContainer
{
    public function __construct(private string $plan, private ?Plans $plans = null)
    {
    }

    public function withContainer(ContainerInterface $container): static
    {
        return new self($this->plan, $container->get(Plans::class));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->plans?->allows($request->getAttribute('user'), $this->plan)) {
            return ErrorResponse::make($request, 403);
        }

        return $handler->handle($request);
    }
}
```

```php
Route::get('/exports', ListExports::class)->middleware(new RequirePlan('team'));
```

## Passing data to the action

Put it on the request as an attribute, and pass the changed request on:

```php
$tenant = $this->tenants->byHost($request->getUri()->getHost());

return $handler->handle($request->withAttribute('tenant', $tenant));
```

An action that takes the request reads it with `$request->getAttribute('tenant')`. The attribute named `user` is special: an action receives it as a typed parameter; see [Authentication](authentication.md).

## Middleware that ships

All are off until you add them, because each adds work to the requests it is around.

### CORS

A page served from another origin, such as a frontend on `https://app.example.com`, may call your API from a browser only when the API says so:

```php
use Crab\App;
use Crab\Http\Cors;

return App::configure(__DIR__)
    ->withMiddleware(prepend: [new Cors(['https://app.example.com'])])
    ->create();
```

| Argument | What it says | Default |
| --- | --- | --- |
| `origins` | Who may call. `'https://*.example.dev'` allows any one host name in that place; `['*']` allows anyone. | required |
| `methods` | The methods a page may use | `GET`, `POST`, `PUT`, `PATCH`, `DELETE` |
| `headers` | The request headers a page may send | `['*']`: whichever it asks for |
| `exposed` | Response headers a page may read beyond the few a browser always shows | none |
| `credentials` | Whether cookies and HTTP authentication may be sent. Not with `['*']`. | `false` |
| `maxAge` | Seconds a browser remembers the permission | `600` |

Put it first, with `prepend:`, so that a page can read an error's body too. It answers preflight requests from allowed origins with `204`, otherwise `403`; the methods header tells the browser which methods it may use.

> [!NOTE]
> CORS protects nothing. An ordinary request from an origin that is not listed is still handled without CORS headers; only a browser honours them. Authentication is what keeps callers out.

### Request ids

`Crab\Http\RequestId` gives each request an id: the `X-Request-Id` it arrived with when that is a plain token, otherwise a new one. The id is the request's `request_id` attribute, is returned in `X-Request-Id`, and is added to the log record of a failure.

```php
->withMiddleware(prepend: [RequestId::class])
```

Use it when requests pass through several services and you want to follow one through their logs. Pass the id along when you log: `$logger->info('Charged', ['request_id' => $request->getAttribute('request_id')])`.

### Access logging

`Crab\Http\AccessLog` records each request after its response is made. Use it to see the application's status codes and response times, including requests that match no route:

```php
use Crab\Http\AccessLog;
use Crab\Http\RequestId;

->withMiddleware(prepend: [RequestId::class, AccessLog::class])
```

Records go through the application's [PSR-3 logger](logging.md), at `info` by default:

```text
GET /users/7 200 3.2ms
```

The context includes `method`, `path`, `status`, `ms` rounded to one decimal, `ip`, and `bytes` (the response body's size, or `null` when unknown). The query string is left out because it may contain secrets. A failure that escapes the middleware is logged with `500` and rethrown; an error response made inside it is logged with that response's status.

If collecting or writing an access record fails, the engine reports that failure. The response or the handler's original exception still reaches the caller unchanged.

Skip exact paths, such as a health check, or choose another PSR-3 level:

```php
->withMiddleware(prepend: [new AccessLog(skip: ['/up'], level: 'info')])
```

`/up/more` is still logged. An unknown level is refused when the middleware is made. With `new`, the middleware gets the logger from the container before serving requests; used standalone, pass `logger: $logger`.

Put `RequestId` and any authentication middleware before `AccessLog` to include the visible `request_id` and user's id as `user`. Attributes added to an immutable request inside it cannot be seen on the way out. Behind a proxy, put [TrustedProxies](requests.md#the-clients-address) before it so `ip` is the trusted client address.

An in-process PHP 8.4 run with JIT enabled measured about 1.2 µs added per request with a logger that discards records, or 2.6 µs with Crab's logger writing to `/dev/null`; a file or stream's write cost depends on its destination.

### Rate limiting

`Crab\Http\RateLimit` lets each client make so many requests in a length of time, and answers the rest `429`:

```php
use Crab\Http\RateLimit;

Route::post('/login', Login::class)->middleware(new RateLimit(5, per: 60));

Route::middleware(TokenAuth::class, new RateLimit(120))->group(function () {
    // 120 a minute for each user, over all of these routes together
});
```

| Argument | What it says | Default |
| --- | --- | --- |
| `limit` | Requests a client may make in one window | required |
| `per` | The length of a window, in seconds | `60` |
| `by` | A function from the request to who it counts against | the user, else the address |
| `name` | Tells one limit from another | `''` |

A client is the logged-in user when the request has one, so put the limit after the middleware that authenticates. Otherwise it is [the client's address](requests.md#the-clients-address): an IPv4 address is counted individually, and IPv6 addresses in the same `/64` share a count. Behind a proxy, add `TrustedProxies` or every client shares one count.

Responses returned through `RateLimit` say where the client stands, and a refusal says when to come back; an exception answered by an outer error handler bypasses these headers:

```text
X-RateLimit-Limit: 5
X-RateLimit-Remaining: 0
Retry-After: 42
```

Counts are kept in the [cache](cache.md), which is available by default. Workers on one machine share the `file` store; several machines need the `database` or `redis` store. With the `array` store each worker counts alone.

Two limits with the same `limit`, `per` and `name` are one count, wherever they are used. Give each its own name to count apart:

```php
Route::post('/login', Login::class)->middleware(new RateLimit(5, name: 'login'));
Route::post('/register', Register::class)->middleware(new RateLimit(5, name: 'register'));
```

Each window counts from zero, so across the edge of two windows a client can get up to twice the limit through in a short time. Set the limit with that in mind.

### Limiting work in hand

`Crab\Http\Concurrency` lets each worker work on so many requests at once. The rest wait their turn, first come first served, and one that has waited too long is answered `503`:

```php
// app.php
use Crab\Http\Concurrency;

return App::configure(__DIR__)
    ->withMiddleware(new Concurrency(32))
    ->create();
```

Use it when a busy hour must slow the server down and not break it. A worker takes every request that arrives, and without a limit a burst puts all of them to work together: each asks for a database connection, most wait for one, and they fail together when the [pool's](database.md) wait runs out. With a limit, a request that waits runs none of your code and holds no connection.

| Argument | What it says | Default |
| --- | --- | --- |
| `limit` | Requests one worker works on at once | required |
| `wait` | Seconds a request waits for its turn before it is refused | `5.0` |

The count is each worker's own: eight workers with a limit of 32 work on up to 256 requests. There is no number right for every application. Start at two or three times your database connection's `pool` for an API that mostly reads and writes its database, go higher for one that mostly waits on other servers, and measure.

A refusal says when to come back:

```text
HTTP/1.1 503 Service Unavailable
Retry-After: 5
```

Give a route that is heavy a smaller limit of its own; requests for it then queue among themselves and leave the rest of the worker's places to the other routes:

```php
Route::get('/reports/{id}', BuildReport::class)->middleware(new Concurrency(2, wait: 1.0));
```

A limit does not make a request faster, and code that never waits, such as hashing a password, holds its worker however many places are free. Put it inside the error handler, which is where `withMiddleware()` adds it, and before middleware that does work of its own.

An in-process PHP 8.4 run with JIT enabled measured about 0.2 µs added per request while a place is free.
