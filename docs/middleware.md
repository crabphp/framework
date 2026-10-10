# Middleware

- [Introduction](#introduction)
- [Writing middleware](#writing-middleware)
- [Around every request](#around-every-request)
- [Around some routes](#around-some-routes)
- [The order they run in](#the-order-they-run-in)
- [Middleware with options](#middleware-with-options)
- [Passing data to the action](#passing-data-to-the-action)
- [Middleware that ships](#middleware-that-ships)
    - [CORS](#cors)
    - [Request ids](#request-ids)

## Introduction

Middleware is code that runs around a request: before it reaches the route, after the route has answered, or instead of the route. Use it for what many routes share and none should repeat: authentication, a header on every response, a rate limit, a tenant looked up from the host name.

There is one kind, PSR-15's, so middleware from any PSR-15 library works. There are two places to use it, around every request and around some routes, and the same class works in both.

## Writing middleware

```sh
php cua make:middleware RequireJson
```

writes `app/Middleware/RequireJson.php`:

```php
namespace App\Middleware;

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

A middleware class is made by the container, so its constructor can ask for services. It is made once per worker and shared by every request, so it must not keep anything about one request in a property.

## Around every request

Set it in `app.php`. The framework starts the list with its own, `App::MIDDLEWARE`, which is the error handler, and you say how yours relate to it:

```php
// app.php
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
Route::get('/me', [UserController::class, 'me'], middleware: [TokenAuth::class]);

Route::group(prefix: '/admin', middleware: [TokenAuth::class, RequireAdmin::class], routes: function () {
    Route::get('/users', [AdminController::class, 'users']);
});
```

A route's middleware runs only once its route has matched. The same class given by a group and again by a route inside it runs once.

`php cua route:list` shows the middleware around each route.

## The order they run in

```text
request  →  every request's  →  the groups'  →  the route's  →  action
```

In each list the first is outermost: it meets the request first and sees the response last. So `prepend:` is the place for what must see everything, such as CORS, and a `401` from a route's middleware still passes back through the middleware around every request.

## Middleware with options

Options go to the constructor, and you give an object in place of a class name:

```php
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
Route::group(middleware: [TokenAuth::class, new RequireRole('admin')], routes: function () {
    // ...
});
```

There are no string options such as `'role:admin'`. Two objects of one class, `new RequireRole('admin')` and `new RequireRole('billing')`, are two middleware and both run.

## Passing data to the action

Put it on the request as an attribute, and pass the changed request on:

```php
$tenant = $this->tenants->byHost($request->getUri()->getHost());

return $handler->handle($request->withAttribute('tenant', $tenant));
```

An action that takes the request reads it with `$request->getAttribute('tenant')`. The attribute named `user` is special: an action receives it as a typed parameter; see [Authentication](authentication.md).

## Middleware that ships

Both are off until you add them, because each adds work to every request.

### CORS

A page served from another origin, such as a frontend on `https://app.example.com`, may call your API from a browser only when the API says so:

```php
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

Put it first, with `prepend:`, so that a page can read an error's body too. It answers the browser's `OPTIONS` question itself, with `204`.

> [!NOTE]
> CORS protects nothing. A request from an origin that is not listed is still handled; only a browser honours the missing headers. Authentication is what keeps callers out.

### Request ids

`Crab\Http\RequestId` gives each request an id: the `X-Request-Id` it arrived with when that is a plain token, otherwise a new one. The id is the request's `request_id` attribute, is returned in `X-Request-Id`, and is added to the log record of a failure.

```php
->withMiddleware(prepend: [RequestId::class])
```

Use it when requests pass through several services and you want to follow one through their logs. Pass the id along when you log: `$logger->info('Charged', ['request_id' => $request->getAttribute('request_id')])`.
