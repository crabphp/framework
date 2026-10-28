# Crab Framework

A minimal PHP 8.4 framework for JSON APIs, built on the Crab networking engine. It is written to be read and understood, by people and by AI agents alike: explicit types, no hidden state, one way to do each thing. It is at an early stage and has no release yet: the engine is implemented and measured, and the components an API needs are built on it, from routing and validation to the database, a cache, a queue and a scheduler. Components follow the PHP-FIG standards: PSR-7 messages, PSR-17 factories, PSR-15 handlers and middleware, a PSR-11 container, a PSR-3 logger, a PSR-14 dispatcher, a PSR-16 cache, a PSR-18 client and a PSR-20 clock.

The guide for writing an application is in [docs/](docs/documentation.md); run `composer docs` to read it in a browser.

## Hello, world

~~~php
<?php

use Crab\App;
use Crab\Route;

$app = new App();

Route::get('/', static fn (): string => "Hello, world!\n");
Route::get('/hello/{name}', static fn (string $name): array => ['hello' => $name]);

return $app;
~~~

Save it as `app.php` and run `php bin/crab app.php start --single`. `Route::get()` declares a route on the application just created; it is `$app->router->get()` under a shorter name. `{name}` matches one path segment and `{name:expression}` matches a regular expression.

## Actions

A route's action says in its signature what it takes, and the framework checks the request against it before the action runs. Each parameter says where it comes from: a name in the pattern is the path, `#[FromQuery]` the query string, and a class or described `Data` the body.

~~~php
use Crab\Http\Response;
use Crab\Routing\FromQuery;
use Crab\Validation\Data;
use Crab\Validation\Email;
use Crab\Validation\Fields;
use Crab\Validation\Length;

// Fields of the query string: one parameter each, marked.
Route::get('/users', static fn (#[FromQuery] int $page = 1, #[FromQuery] ?string $search = null): array => $users->page($page, $search));

// A small body: its fields described where they are used.
Route::post('/users', static function (
    #[Fields(['email' => ['string', new Email()], 'name' => ['string', new Length(min: 2, max: 50)]])]
    Data $input,
) use ($users): Response {
    $user = $users->create($input->email, $input->name);

    return Response::created($user, "/users/{$user->id}");
});

// Several routes that share services: a class, one method per route.
Route::get('/orders/{id}', [OrderController::class, 'show']);
Route::post('/orders', [OrderController::class, 'store']);
~~~

~~~php
final readonly class OrderController
{
    public function __construct(private Database $db)             // services, from the container
    {
    }

    public function show(int $id): Order                           // a route parameter
    {
        $row = $this->db->table('orders')->find($id) ?? throw new HttpException(404);

        return new Order($row['id'], $row['total']);
    }

    public function store(CreateOrder $input): Order               // the body, as one class
    {
        ...
    }
}
~~~

An action is a closure, a class and the name of its method, or the name of a class with `handle()`. A class extends nothing; its route retains the callable resolved from the container for the worker’s life, even with a transient binding.

### Groups

Routes that share a path prefix, middleware, or both are declared in a group:

~~~php
Route::prefix('/admin')->middleware(TokenAuth::class)->group(function () {
    Route::get('/users', [AdminController::class, 'users']);          // GET /admin/users
    Route::get('/users/{id}', [AdminController::class, 'user']);
});
~~~

`Route::prefix()` or `Route::middleware()` starts a group, the other may follow, and `group()` declares its routes. Groups may be nested. A route says more about itself on its own line: `Route::get('/me', ShowMe::class)->name('me')->middleware(TokenAuth::class)`. See [Middleware](#middleware).

### Named routes

~~~php
Route::get('/orders/{id}', ShowOrder::class)->name('orders.show');

$urls->to('orders.show', ['id' => 12]);        // https://api.example.com/orders/12, from Contracts\Routing\Urls and app.url
$router->path('orders.show', ['id' => 12]);    // /orders/12
~~~

A name is unique, and a missing or refused parameter throws naming the route. `Urls` reads the address from `app.url`, not from a request, so a job or a command writes the same links an action does. See [Named routes](docs/routing.md#named-routes).

### What an action takes

| Parameter | Is |
| --- | --- |
| Named as in the pattern, `string`, `int`, `float` or `bool` | That path parameter, as that type |
| Marked `#[FromQuery]`: an `int`, `float`, `string`, `bool`, `array`, enum or `DateTimeImmutable` | That field of the query string, by its name |
| A class, or `Data` described with `#[Fields]` | The body, JSON or a form with its files, in one object |
| Marked `#[FromQuery]`: a class, or described `Data` | The whole query string, in one object |
| Typed as a PSR-7 server request | The request itself |
| A class that implements `Contracts\Auth\Identity` | The logged-in user; see [Authentication](#authentication) |

A field of the body is never a parameter of its own, and a value is read only from the part of the request its parameter names: a plain `string $email` that is not in the pattern is refused, with what to write instead. `GET`, `HEAD` and `DELETE` have no body. Fields the action does not name are ignored and never reach it; to read them, or the raw body, take the request as well and call `getParsedBody()`.

Services are not parameters; they belong in the constructor of an action class. Anything else in a signature is refused when the route is added, so a mistake stops the application from starting. See [Actions](docs/actions.md#what-an-action-takes).

### What an action returns

~~~php
return $user;                                             // an object or array: JSON, 200
return 'pong';                                            // a string: plain text
return;                                                   // nothing: 204
return Response::created($user, "/users/{$user->id}");    // 201, with a Location header
return Response::noContent();                             // 204
return Response::redirect('/login');                      // 302; pass 301, 303, 307 or 308 for another
return Response::download($path, 'invoice.pdf');          // a file, read as it is sent
return Response::attachment($csv, 'orders.csv', 'text/csv'); // text made here, saved as a file
return Response::json($user)->withHeader('Cache-Control', 'max-age=60');
~~~

Return the data when 200 is right, and a response when the status, a header or the body needs saying. Dates are written as ISO 8601 text.

### Validation

The type of a parameter says what is accepted, and attributes add rules. This holds for an action's own parameters and for the constructor of an input class alike:

| Declaration | Accepts |
| --- | --- |
| `int`, `float`, `string`, `bool` | That value. JSON is not converted: `"36"` is not a whole number. A query string is text, so there `page=3` is read as `3`. |
| `?type`, or a default value | May be left out |
| A backed enum | One of its values |
| `DateTimeImmutable` | A date, or a date and time with an offset, such as `2026-01-31` or `2026-01-31T12:00:00Z`; not a phrase such as `now` |
| Another class | An object, checked the same way |
| `#[ListOf(Tag::class)] array` | A list of those; `ListOf('int')` for plain values |
| `#[Email]`, `#[Url]`, `#[Length(min:, max:)]`, `#[Range(min:, max:)]`, `#[Pattern('^[a-z]+$')]`, `#[OneOf('a', 'b')]` | The rule |

A request that does not fit is answered with `422` and every problem, by field:

~~~json
{"error": {"status": 422, "message": "The request is invalid.", "fields": {
    "email": ["must be an email address"],
    "address.city": ["is required"]
}}}
~~~

A rule of your own is an attribute class that implements `Crab\Contracts\Validation\Rule`: `check()` returns what is wrong or null. To validate outside a route, ask for `Crab\Contracts\Validation\Validator` and call `validate(CreateUser::class, $data)`, which returns the object or throws `Crab\Validation\ValidationException` with `$errors`.

Reading a signature costs nothing per request, because it is read once when the route is added. Calling a closure by its signature adds about 0.05 µs to a request over a PSR-15 handler, and validated input with a typed response about 1 µs over the same checks written by hand (`benchmarks/typed-action.php`, PHP 8.4 with JIT, Apple M1 Pro).

## Middleware

Middleware is code that runs around a request: before it reaches the route, after the route has answered, or instead of the route.

~~~php
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
            return ErrorResponse::make($request, 415, 'Send Content-Type: application/json.');
        }

        $response = $handler->handle($request);                 // let it through

        return $response->withHeader('X-Api-Version', '2');     // and add to what comes back
    }
}
~~~

`php cua make:middleware RequireJson` writes a pass-through stub in `app/Http/`. Edit it into the implementation above to check JSON and add the response header. `Middleware` is PSR-15's `MiddlewareInterface` under Crab's name, so middleware from any PSR-15 library works too. A middleware class is made by the container, once per worker, so its constructor can ask for services; like them, it must not keep anything about one request.

There are two places to use one, and the same class works in both.

**Around every request**, in `app.php`. The framework starts the list with its own, `App::MIDDLEWARE`, which today is the error handler, and you say how yours relate to it:

~~~php
return App::configure(__DIR__)
    ->withMiddleware([AddApiVersion::class])                // after the framework's
    ->withMiddleware(prepend: [RequestId::class])           // before them, outermost
    ->create();
~~~

To change the framework's own part of the list, use one of these two, not both:

~~~php
    ->withMiddleware(remove: [ErrorHandler::class])         // without one of the framework's
    ->withMiddleware(only: [MyErrors::class])               // these in place of the framework's
~~~

`remove:` takes only classes from `App::MIDDLEWARE`; a name that is not there is refused, so a typing mistake is not silently ignored.

**Around some routes**, on a route or on a group:

~~~php
Route::get('/me', [UserController::class, 'me'])->middleware(TokenAuth::class);

Route::prefix('/admin')->middleware(TokenAuth::class, RequireAdmin::class)->group(function () {
    Route::get('/users', [AdminController::class, 'users']);
});
~~~

They run from the outside in, and the response travels back out the same way:

~~~text
request  →  every request's  →  the group's  →  the route's  →  action
~~~

- **In each list the first is outermost.** So `prepend:` is what a request meets first and what sees the response last.
- **Middleware around every request also runs when no route matches**, which is how a 404 still gets its CORS headers. A route's middleware runs only once its route has matched.
- **A refusal passes back through everything outside it.** A 401 from a route's middleware is still seen by the middleware around every request.
- **To hand something to the action, put it on the request:** `$handler->handle($request->withAttribute('tenant', $tenant))`, read with `$request->getAttribute('tenant')`.
- **Middleware given by class name is made when a worker starts**, not on the first request, so one that cannot be made stops the server from starting. A route that names a class that does not exist, or one that is not middleware, is refused as soon as it is declared, with the route's name.
- **The same middleware given twice runs once.** `TokenAuth::class` on a group and again on a route inside it is one check, not two. Two objects of one class, `new RequireRole('admin')` and `new RequireRole('billing')`, are two middleware and both run.
- **`php cua route:list` shows the middleware around each route**, its groups' included.
- **With options, give an object:** `->middleware(new TokenAuth($tokens, $users, 'X-Api-Key', ''))`.
- **Without the error handler** nothing turns an exception into a response for the client; the engine answers 500 and the failure is logged. Replace it rather than remove it.

### CORS

A page served from another origin, such as a frontend on `https://app.example.com`, can call the API from a browser only when the API says it may. `Http\Cors` says so:

~~~php
use Crab\Http\Cors;

return App::configure(__DIR__)
    ->withMiddleware(prepend: [new Cors(['https://app.example.com'])])
    ->create();
~~~

| Argument | What it says | Default |
| --- | --- | --- |
| `origins` | Who may call. `'https://*.example.dev'` allows any one host name in that place; `['*']` allows anyone. | required |
| `methods` | The methods a page may use | `GET`, `POST`, `PUT`, `PATCH`, `DELETE` |
| `headers` | The request headers a page may send | `['*']`: whichever it asks for |
| `exposed` | Response headers a page may read beyond the few a browser always shows | none |
| `credentials` | Whether cookies and HTTP authentication may be sent. Not with `['*']`. | `false` |
| `maxAge` | Seconds a browser remembers the permission | `600` |

- **Put it first, with `prepend:`,** outside the error handler, so that a page can read an error's body too.
- **It answers the browser's question itself.** A preflight `OPTIONS` request from an allowed origin receives 204; a refused origin receives 403. Neither reaches a route.
- **It protects nothing.** A request from an origin that is not listed is still handled, without the headers a browser needs; anything that is not a browser ignores them. Authentication is what keeps callers out.
- **It costs about 0.1 µs** for a request that names no origin and about 0.9 µs for one that does, measured in process.

### Request ids

`Http\RequestId` is a middleware that ships but is not on by default. It gives each request an id: the `X-Request-Id` it arrived with when that is a plain token, otherwise a new one. The id is the request's `request_id` attribute, is returned in `X-Request-Id`, and is added to the log record of a failure. Add it with `prepend:` so that it is outside the error handler. It costs about 2 µs per request, measured in process, where a routed request otherwise costs about 3.

## Authentication

Who makes a request is decided by middleware. Whatever the way, it ends the same: the middleware puts your user on the request, and an action takes that user as a parameter.

~~~php
use Crab\Contracts\Auth\Identity;

final readonly class User implements Identity          // your user: anything with a public $id
{
    public function __construct(public int $id, public string $email)
    {
    }
}

Route::post('/login', [AuthController::class, 'login']);                  // open to all

Route::middleware(TokenAuth::class)->group(function () {
    Route::get('/me', static fn (User $user): User => $user);            // the logged-in user, by type
    Route::post('/posts', [PostController::class, 'store']);
});
~~~

- **The user reaches an action by type.** A parameter whose class implements `Identity` receives the user that middleware put on the request's `user` attribute; a request with none is answered with `401`. Make the parameter nullable for a route that works either way.
- **There is no `Auth::user()`.** A worker serves requests that overlap, so the user travels with the request and nowhere else.
- **Any middleware can be a way of authenticating.** It finds the user, and either calls `$handler->handle($request->withAttribute('user', $user))` or answers `401`. A session cookie, a signed address, a JWT, a header a proxy sets: each is a few lines of middleware of your own, and needs nothing more from Crab.
- **`AuthProvider`, registered by default,** binds what every way shares, the `PasswordHasher`: `hash()`, `verify()` and `needsRehash()`, over PHP's own password hashing.

### Tokens

One way ships, because nearly every API starts with it: a token in a header, kept in your database.

~~~php
// app.php
->withProviders([TokenAuthProvider::class, AppProvider::class])
~~~

~~~sh
php cua token:install       # writes the tokens table's migration into your own migrations
php cua migrate
~~~

~~~php
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
~~~

The fixed dummy hash makes a missing account perform password verification too. Keep its algorithm and cost aligned with stored passwords; this example uses PHP 8.4's default bcrypt cost of 12. Do not generate the dummy hash during login. See [Logging in](docs/authentication.md#logging-in).

The client then sends `Authorization: Bearer <token>`, and `TokenAuth` is the middleware that reads it.

- **You say how a user is found.** A token records whose it is, not who that is, so bind `Contracts\Auth\IdentityResolver` in your own provider; its `resolve($id)` returns your user or null.
- **The table is yours.** `token:install` writes the migration for `auth_tokens` into your migrations folder, where you can read and change it; the framework ships no migration of its own.
- **`TokenStore`** has `issue($user, $name, $expiresAt)`, `owner($token)`, `revoke($token)` and `revokeAll($user)`. A token is 64 random characters, shown once; only its hash is stored. Checking one is one query, and a revoked token stops working at once.
- **Another header, or another word before the token,** is an argument, given as an object:

~~~php
new TokenAuth($tokens, $users);                          // Authorization: Bearer <token>
new TokenAuth($tokens, $users, 'X-Api-Key', '');         // X-Api-Key: <token>
new TokenAuth($tokens, $users, scheme: 'Token');         // Authorization: Token <token>
new TokenAuth($tokens, $users, required: false);         // lets a request without a token through, with no user
~~~

Roles and permissions, and sessions with cookies, are not built and not planned: each is a few lines of middleware over what an application already has.

## WebSockets

A socket route shares the API's port and middleware. Its action starts inside HTTP; ownership checks can still answer 403 or 404 until the first socket use accepts the connection:

~~~php
use Crab\Contracts\WebSocket\Socket;
use Crab\Route;

Route::websocket('/orders/{id}/live', static function (Socket $socket, int $id): void {
    $socket->send(['order' => $id]);
    foreach ($socket as $message) {
        $socket->send($message);
    }
});
~~~

Arrays travel as JSON text; `WebSocket\Binary` preserves binary frames through the echo loop, and `sendBinary()` sends bytes. Returning closes with 1000, and stop or reload sends 1001. Use `accept()` to upgrade without sending and `cancellation()` to stop awaited work on socket closure. Header-based authentication and typed query fields use the same HTTP checks before upgrading. Browser clients exchange their authentication for a short-lived, single-use ticket; see [Authenticating a browser](docs/websockets.md#authenticating-a-browser). Delivery is at most once.

Defaults are `websocket.ping = 25`, `websocket.max_message = 65536`, `websocket.send_timeout = 5.0`, and `websocket.max_connections = 256` per worker. `server.max_connections = 512` bounds all connections, and `server.loop = 'select'` can be changed to an installed `ev`, `uv` or `event` backend. `Testing\Api::websocket()` exercises the real action and protocol over a socket pair. See [WebSockets](docs/websockets.md) for limits, authentication, proxies and tests.

## Broadcasting

Join in a socket action, then inject `Crab\Contracts\Broadcast\Broadcaster` into an action, job, listener or command:

```php
$socket->join("orders.{$id}");
$this->broadcast->to("orders.{$id}")->send(['status' => 'shipped']);
$this->broadcast->within($transaction)->to("orders.{$id}")->send(['status' => 'shipped']);
```

`BroadcastProvider` is included by `App::configure()`; with `new App()`, register it explicitly. `broadcast.carrier = 'local'` reaches one HTTP process. Choose `'redis'` and register `Crab\Redis\RedisProvider` for multiple workers or console senders. Defaults are `broadcast.prefix = 'crab:broadcast:'`, `broadcast.max_channels = 64`, `broadcast.batch = 128`, and `broadcast.max_pending_bytes = 1048576`. Slow readers close with 1008; Redis gaps close joined sockets with 1012 so clients reconnect and refetch state. Delivery is at most once, with no replay. `Testing\FakeBroadcaster` records sends without sockets. See [Broadcasting](docs/broadcasting.md).

## Requests and responses

An action that asks for the request receives `Contracts\Http\Request`, an extended PSR-7 server request. A PSR-15 `RequestHandlerInterface` can be a route's action too; it receives the request, with the route parameters as attributes.

~~~php
use Crab\Contracts\Clock\Clock;
use Crab\Contracts\Http\Request;
use Crab\Http\Cookie;
use Crab\Http\Response;
use Crab\Route;

final readonly class UploadAvatar
{
    public function __construct(private Clock $clock)
    {
    }

    public function handle(Request $request): Response
    {
        $id = $request->getAttribute('id');          // route parameter
        $page = $request->query('page', 1);           // query string
        $name = $request->input('name');              // form field or JSON key
        $file = $request->file('avatar');             // PSR-7 UploadedFileInterface, or null
        $file?->moveTo("/var/uploads/{$id}.png");

        return Response::json(['saved' => $file !== null])
            ->withCookie(new Cookie('last_upload', (string) $this->clock->now()->getTimestamp(), maxAge: 3600));
    }
}

Route::post('/users/{id}/avatar', UploadAvatar::class);
~~~

- **Parsed body:** `input()` and `getParsedBody()` read urlencoded forms, `multipart/form-data` and JSON objects or arrays. A body that claims one of these and is malformed is answered with 400.
- **Uploads:** a file is a field of the body's input class, `#[FileSize(max: 2 * 1024 * 1024)] #[MediaType('image/png')] public UploadedFileInterface $avatar`, checked before the action runs, with the kind read from the content. `getUploadedFiles()` returns the PSR-7 tree; `photos[]` fields nest as in PHP. A form may have at most 1,000 parts, and its fields together 1 MiB; a file may be as large as a body may.
- **Large uploads:** a request body may be 1 MiB unless `server.max_body` says more, in bytes, and a client has `server.body_timeout` seconds to send it (30). A file is never held in memory: the engine keeps a large body on disk, the file is read from where it lies in it, and `moveTo()` copies it out. A 40 MiB upload is read and stored within a few megabytes. Copying a large file takes the worker's time while it lasts, as any reading or writing of a file does.
- **Responses:** `Response::text()`, `html()` and `json()`, or `new Response($status, $headers, $body)`. `withCookie()` adds a `Set-Cookie`; with a response from another library use `withAddedHeader('Set-Cookie', (string) $cookie)`.
- **Cookies:** `new Cookie($name, $value, maxAge: ..., path: ..., domain: ..., secure: ..., httpOnly: ..., sameSite: ...)` defaults to `HttpOnly` and `SameSite=Lax`; `Cookie::forget($name)` deletes one. Read one with `$request->cookie('theme', 'light')`.
- **Unmatched routes:** 404, or 405 with an `Allow` header, with the same JSON-or-text body as any other error.

## Calling other APIs

The HTTP client is registered by default by `App::configure()`; nothing is needed in `app.php`.

~~~php
use Crab\Contracts\HttpClient\Client;

final readonly class ShowWeather
{
    public function __construct(private Client $http)
    {
    }

    public function handle(string $city): array
    {
        $weather = $this->http->get('https://api.weather.example/v1/now', query: ['city' => $city]);

        return $weather->ok() ? $weather->json() : ['error' => $weather->getStatusCode()];
    }
}

Route::get('/weather/{city}', ShowWeather::class);
~~~

While a request waits for its answer the worker serves other requests, which a call with `file_get_contents()` or cURL does not allow: either holds the whole worker until the other server answers. That holds for every step: finding the server's address, connecting, agreeing on encryption, sending and waiting.

~~~php
$http->get($url, query: ['page' => 2], headers: ['Accept' => 'application/json']);
$http->post($url, json: ['name' => 'Ada']);                                // also put() and patch()
$http->post($url, form: ['user' => 'ada', 'pass' => $secret]);
$http->delete($url);
$http->request('GET', $url, cancellation: $request->cancellation());        // given up when the caller has gone
$http->request('PUT', $url, body: $csv, headers: ['Content-Type' => 'text/csv']);
~~~

- **The answer** is a PSR-7 response with `ok()`, whether the status is 200 to 299, and `json()`, the body decoded.
- **An error status is an answer.** A 404 or a 500 is returned like any other, unless you ask otherwise: `$http->get($url)->throw()->json()` throws `HttpClient\ResponseException`, which names the request and the status and holds the answer in `$error->response`.
- **No answer always throws:** `HttpClient\NetworkException` when the server could not be reached or took too long, `HttpClient\RequestException` when the request itself cannot be sent. All three are a `Psr\Http\Client\ClientExceptionInterface`, and no message holds the query string or a header.
- **HTTP/2 is spoken with every server that offers it** over `https`, which is most: all the requests to one server then share one connection and run at once, as many as the server allows. With the others it is HTTP/1.1, on connections that are kept and used again, up to 8 to one server; a request beyond that waits its turn.
- **Redirects are followed,** up to 5. A cross-origin redirect drops only `Authorization` and `Cookie`; other credential headers remain. Use `redirects: 0` for those credentials, or follow redirects only from a base URL you trust. See [A client for one API](docs/http-client.md#a-client-for-one-api).
- **An answer compressed with gzip or deflate** is handed over as what it says, unless `decode: false` asks for the bytes as sent. Brotli is not asked for.
- **No body may be longer than `http_client.max_bytes`,** 64 MiB, checked as it arrives; `maxBytes:` changes it for one request, and a longer answer throws `HttpClient\TooLargeException`. `sink: $path` streams a download into a file that appears only once it is all there. See [Downloading files](docs/http-client.md#downloading-files).
- **It is PSR-18's client** under the contract `Contracts\HttpClient\Client`; `sendRequest()` takes any PSR-7 request, and a library that asks for `Psr\Http\Client\ClientInterface` is given the same object.

### Timeouts

~~~php
$http->timeout(2.0)->get($url);
~~~

`timeout()` bounds the whole call: finding the address, connecting, waiting for a free connection, redirects, retries and their pauses share one deadline. Each later try gets the time left. It returns another client over the same connections and leaves the original unchanged, which matters because every request a worker serves shares it. See [Timeouts](docs/http-client.md#timeouts).

### Trying again

~~~php
$http->retry(3)->get($url);                 // up to three more times
$http->retry(3, delay: 0.5)->get($url);     // random pauses: 0.375–0.5 s, 0.75–1 s, 1.5–2 s
~~~

A request is tried again only when that cannot do it twice:

| Request | Tried again when |
| --- | --- |
| `GET`, `HEAD`, `OPTIONS`, `PUT`, `DELETE` | It got no answer, or 429, 502, 503 or 504 |
| `POST`, `PATCH` | None of it was sent, or it was answered 429 or 503, with which a server says it did not act on it |

A server's `Retry-After` replaces a shorter delay and must fit within the time left before the whole-call deadline. A pause that cannot fit ends the call with the last response or failure. A 500 is never tried again: a server that failed may fail the same way. `http_client.retries` sets it for the default client, which by itself tries once. See [Trying again](docs/http-client.md#trying-again).

### Sending files

~~~php
use Crab\Contracts\HttpClient\File;

$http->post($url, form: ['title' => 'Q3'], files: [
    'report' => File::fromPath('/tmp/q3.pdf', type: 'application/pdf'),
    'notes' => File::fromContents($text, 'notes.txt', 'text/plain'),
    'avatar' => $request->file('avatar'),               // an upload you received, passed on
    'photos' => [File::fromPath($one), File::fromPath($two)],
]);
~~~

The body is a form with files (`multipart/form-data`). A file is read from disk as it is written to the body, never into memory whole.

### A client for one API

~~~php
$stripe = new \Crab\HttpClient\Client('https://api.stripe.com/v1', headers: ['Authorization' => "Bearer {$key}"]);
$stripe->post('/charges', json: ['amount' => 500])->throw();
~~~

Make it in a provider and bind it under a name of your own. It takes the same arguments as the settings below, among them `proxy:`.

### Cookies

~~~php
$session = $http->cookies(new CookieJar());
$session->post('https://shop.example/login', form: [...]);
$session->get('https://shop.example/orders');            // sent with the cookie the login set
~~~

A client keeps no cookies unless it is given a jar, because one jar on a client every request shares would hand one caller's cookies to the next. Give each caller a separate jar for each site. A jar accepts parent-domain cookies and has no public suffix check, so sharing it across unrelated sites can send one site's cookie to another. Host/domain, path, `Secure` and expiry still limit where a cookie is sent. See [Cookies](docs/http-client.md#cookies).

### A proxy

`proxy: 'http://user:password@proxy.example:8080'` on a client, or the `http_client.proxy` setting, sends every request through an HTTP proxy. An `https://` request goes through a tunnel the proxy makes and cannot read. Proxies reached over TLS and SOCKS proxies are not supported.

### In tests

`FakeClient` is the client with replies written in advance in place of the network:

~~~php
$http = new FakeClient([
    'GET https://api.example.com/users/7' => ['id' => 7, 'name' => 'Ada'],                       // JSON, 200
    'POST https://api.example.com/charges' => FakeClient::response(402, ['error' => 'declined']),
    'https://slow.example.com/*' => new RuntimeException('it timed out'),                         // thrown
    'https://echo.example.com/*' => fn (RequestInterface $request) => (string) $request->getBody(),
]);
$app->container->instance(Client::class, $http);

// ... run the code under test ...

self::assertCount(1, $http->sent('POST https://api.example.com/charges'));
self::assertSame('{"amount":500}', (string) $http->requests[0]->getBody());
~~~

- **A reply is found by method and URL,** the first that matches. `*` stands for anything, the method may be left out, and the query string counts only when the pattern has one.
- **A request nothing matches throws,** naming it, so a test never waits on the network.
- **Everything else is the real client:** base URL, headers, redirects, cookies and `throw()` behave as they do over the network.

### Settings

| Setting | Default | |
| --- | --- | --- |
| `http_client.timeout` | `30.0` | Seconds a whole call may take, retries included |
| `http_client.connect_timeout` | `5.0` | Seconds connecting may take |
| `http_client.redirects` | `5` | `0` returns the redirect itself |
| `http_client.connections` | `8` | HTTP/1.1 connections to one server; HTTP/2 stream concurrency follows the peer's settings |
| `http_client.base_url`, `http_client.headers` | none | Added to every request of the default client |
| `http_client.proxy` | none | `http://host:port`, with `user:password@` when it asks |
| `http_client.retries` | `0` | More tries for a request that may be tried again |
| `http_client.http2` | `true` | `false` to speak HTTP/1.1 with every server |
| `http_client.tls` | `[]` | PHP's `ssl` context options, such as `['cafile' => '/path/ca.pem']` |

A server's address is found by its name without holding the worker: the name servers are asked over UDP on the event loop, after the hosts file, and an answer is kept for as long as it says it holds. Only when they give nothing is the system's own resolver asked, which does hold the worker, since it knows names they may not, such as those of the local network. `https` needs PHP's `openssl` extension, and a compressed answer its `zlib`.

What it does not do: HTTP/3, HTTP/2 without encryption, and server push.

## Configuration, errors and logging

~~~php
use Crab\App;
use Crab\Config\Config;
use Crab\Config\Env;

Env::load(__DIR__ . '/.env');
$app = new App(Config::load(__DIR__ . '/config'));
~~~

An application with more than one file is usually described in one chain instead, which also registers the framework providers and loads the route files:

~~~php
return App::configure(__DIR__)
    ->withProviders([
        AppProvider::class,
    ])
    ->withMiddleware(prepend: [new Cors(['https://app.example.com'])])
    ->withRouting(
        routes: 'routes/web.php',
        commands: 'routes/console.php',
        health: '/up',
    )
    ->create();
~~~

- **The base path** is the directory given to `configure()`. Its `.env` file and `config` folder are loaded by default, because `App::configure()` begins the chain with `withEnv('.env')` and `withConfig('config', required: false)`; call either again to name another. Nothing else is found by convention.
- **Paths may be relative** to the base path, and `$app->path('storage/app.log')` gives one in full anywhere else.
- **Providers** start with `App::PROVIDERS`: Database, Auth, Cache, Events, Queue, Schedule, HttpClient and Broadcast, in that order. Add your own and your packages' providers as one provider or a list; `routes:` and `commands:` each take one file or a list of files. `withMiddleware()` is described under [Middleware](#middleware).
- **`health: '/up'`** makes `GET /up` answer 200 and `{"status":"up"}`, for a load balancer or a deploy script that needs to know the server takes requests. It says that this worker answers and asks nothing of the database, so that a slow store does not take every server out of rotation at once; route the path to an action of your own to check more.
- **Each step is optional and they may come in any order:** `create()` loads the environment, then the configuration, makes the application, registers the providers and middleware, and then includes the route and command files, which declare with `Route::` and `Command::`.

To leave out a framework provider or replace the default list, choose one form:

~~~php
->withProviders(remove: [ScheduleProvider::class])
->withProviders(only: [AppProvider::class])
~~~

`remove:` accepts only classes from `App::PROVIDERS`; `only: []` leaves them all out. Do not combine `remove:` and `only:`. A framework provider named again is registered once. `TokenAuthProvider` and every package stay opt-in. `new App()` used directly registers no providers. See [Services and providers](docs/providers.md).

`Config::load()` reads every PHP file of a directory, keyed by file name:

~~~php
// config/log.php
return ['level' => env('LOG_LEVEL', 'info')];
~~~

Anywhere after the application is created, `config('log.level')` reads that value, and `config('log.level', 'debug')` supplies a default. `env('NAME', $default)` reads an environment variable, where the real environment wins over `.env`; the words `true`, `false` and `null` become those values. The two helpers are global functions over `Env::get()` and `$app->config->get()`. The application itself reads five keys:

| Key | Meaning | Default |
| --- | --- | --- |
| `app.name` | The application's name, shown above the command list | `Crab` |
| `app.debug` | Show failure details to clients | `false` |
| `app.url` | Where the application is reached, the start of each URL `Urls` writes | none |
| `log.path` | Where the default logger writes | standard error |
| `log.level` | The least severe level written | `debug` |

Anything thrown while handling a request is caught by `Http\ErrorHandler`, logged with its trace and answered with a 500, as JSON when the client accepts it and plain text otherwise. Throw `Http\HttpException` to answer with a chosen status and message, for example `throw new HttpException(404, 'No such user')`; below 500 it is not logged. The logger is the container's `Psr\Log\LoggerInterface` entry.

Inside a worker, a PHP warning or notice is thrown as an `ErrorException`, so it is logged and answered like any other failure instead of being printed and ignored. Code silenced with `@` is left alone. A deprecation is logged once per place and execution continues.

## Database

The database provider is registered by default by `App::configure()`; nothing is needed in `app.php`. An application with no database configuration starts, and asking for the database then says what to configure.

~~~php
// config/database.php
return [
    'default' => 'main',
    'connections' => [
        'main' => [
            'driver' => env('DB_DRIVER', 'sqlite'),                           // or mysql, with host and port
            'database' => env('DB_DATABASE', dirname(__DIR__) . '/storage/database.sqlite'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD'),
        ],
    ],
];
~~~

Ask for `Crab\Contracts\Database\Database` in a constructor, then:

~~~php
$users = $db->select('select * from users where active = ?', [true]);      // every row
$user  = $db->first('select * from users where id = :id', ['id' => 7]);     // one row or null
$id    = $db->insert('insert into users (name) values (?)', ['Ada']);       // the new id
$count = $db->execute('update users set name = ? where id = ?', ['Ada L.', $id]);   // rows affected

$db->transaction(function (Connection $tx) use ($from, $to, $amount) {
    $tx->execute('update accounts set balance = balance - ? where id = ?', [$amount, $from]);
    $tx->execute('update accounts set balance = balance + ? where id = ?', [$amount, $to]);
});
~~~

- **Values are always bound.** Write `?` or `:name` and pass the values; never build them into the SQL. Integers, booleans, null, strings, floats and dates are bound with their type.
- **Transactions** commit when the callback returns and roll back when it throws. Inside the callback use the connection it is given, `$tx`, not `$db`: `$db` would run on a different connection, outside the transaction. A transaction inside a transaction is a savepoint.
- **Connections are pooled per worker.** Each statement on `$db` borrows one and returns it; `$db->run(fn (Connection $c) => ...)` keeps one for several statements. A worker opens them on first use, at most the connection's `pool` of them (10, or 1 for SQLite, which allows one writer), and a caller waits up to `database.connections.<name>.timeout` seconds (5) for a free one. See [Database configuration](docs/database.md#configuration).
- **A dropped connection is replaced.** When the server had already closed it, the statement runs once on a new connection. When it was lost mid-statement or inside a transaction, the failure is reported and nothing is retried, so nothing is applied twice.
- **Failures** throw `Crab\Database\DatabaseException`, whose message and `$sql` hold the SQL but never the bound values.
- **PDO blocks.** While a statement runs, its worker serves nothing else; run more workers for more concurrent queries.

It works without the framework too: `new Database(fn () => new PDO($dsn, $user, $password))`. MySQL and SQLite are supported: the tests run on SQLite, and on MySQL when `CRAB_TEST_MYSQL_DSN` is set. PostgreSQL is not supported for now. There is no model: rows are arrays, or with `->into(Order::class)` objects of a readonly class of yours, its property types saying how each column is read and written.

### Query builder

`$db->table('users')` starts a query that is built with methods instead of SQL, and written for the database it runs on.

~~~php
$users = $db->table('users');

$adults = $db->table('users')->where('age', '>=', 18)->orderBy('name')->get();    // every row
$ada    = $db->table('users')->where('email', 'ada@example.com')->first();        // one row or null
$user   = $users->find(7);                                                        // by id
$email  = $db->table('users')->where('id', 7)->value('email');                    // one value
$names  = $db->table('users')->pluck('name', 'id');                               // [id => name]
$total  = $db->table('users')->where('active', true)->count();                    // also sum, avg, min, max, exists

$id = $users->insert(['name' => 'Ada', 'email' => 'ada@example.com']);            // the new id
$users->insertMany([['name' => 'Grace'], ['name' => 'Linus']]);
$users->upsert(['email' => 'ada@example.com', 'name' => 'Ada L.'], 'email');      // insert, or update the row with that email
$db->table('users')->where('id', $id)->update(['name' => 'Ada L.']);              // rows affected
$db->table('posts')->where('id', 3)->increment('views');
$db->table('users')->where('active', false)->delete();
~~~

Conditions read as they are written:

~~~php
$db->table('users')
    ->where('active', true)                                   // equals
    ->where('age', '>', 18)                                   // = != < > <= >= like
    ->where('deleted_at', null)                               // is null
    ->whereIn('role', ['admin', 'editor'])
    ->whereBetween('age', 18, 65)
    ->where(function (Query $group) {                         // (name like ? or email like ?)
        $group->where('name', 'like', 'A%')->orWhere('email', 'like', 'a%');
    })
    ->when($search !== '', fn (Query $query) => $query->where('name', 'like', "%{$search}%"))
    ->get();

$db->table('posts')
    ->join('users', 'users.id', 'posts.user_id')
    ->select('posts.title', 'users.name as author')
    ->latest('posts.created_at')
    ->forPage(2, 20)
    ->get();
~~~

`Query` there is `Crab\Contracts\Database\Query`, which lists every method.

- **Values are bound and names are quoted**, so both may come from a request. Only the methods ending in `Raw` (`selectRaw`, `whereRaw`, `havingRaw`, `orderByRaw`) put SQL in as written; give them placeholders, never a user's text.
- **Building changes the query; running does not.** `get()`, `count()` and the rest leave it as it was, so one query can be counted and then fetched. `clone` it to branch into two.
- **In a transaction, start from the transaction's connection**: `$tx->table('users')`, not `$db->table('users')`.
- **Mistakes are refused when they are made**, not by the database later: an unknown comparison, `update()` on a query with a limit, rows with different columns.
- **Pages:** `paginate(20, $page)` returns a `Crab\Contracts\Database\Page` with `items`, `total`, `perPage`, `currentPage`, `lastPage`, `hasMore`, `from` and `to`. It can be looped over, and returned as JSON with the keys Laravel's paginator uses. `simplePaginate()` skips the count and knows only `hasMore`. The page number is passed in, since nothing reads the request behind your back: `paginate(20, (int) $request->query('page', 1))`.
- **Large tables** are read with `chunkById(500, fn (array $rows) => ...)`, which is not thrown off by rows added or removed meanwhile.
- `toSql()` and `bindings()` show what would run. A query can be the list of another: `whereIn('id', $db->table('posts')->select('user_id'))`.

### Several databases

A database is always described under `connections`, by name; add another under a name of its own:

~~~php
// config/database.php
return [
    'default' => 'main',
    'connections' => [
        'main' => [
            'driver' => 'mysql',
            'database' => env('DB_DATABASE'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD'),
            'migrations' => dirname(__DIR__) . '/database/migrations',
        ],
        'reports' => [
            'dsn' => env('REPORTS_DSN'),
            'migrations' => dirname(__DIR__) . '/database/reports',
        ],
    ],
];
~~~

~~~php
$db->table('users')->count();                                  // the default
$db->connection('reports')->table('daily_totals')->get();      // another, by name
~~~

- **Asking for `Database` gives the default**, so code that uses one database does not change.
- **The database is told by its `dsn`**, `mysql:` or `sqlite:`; there is no separate driver setting to keep in step with it.
- **Each database has its own connections**, opened in each worker when first used, and its own `pool` and `timeout`.
- **A transaction is on one database.** Neither MySQL nor SQLite can commit across two.
- **Each has its own migrations:** a folder, and a `migrations` table inside it. The commands take `--database=reports`.

### Migrations

A migration is a file that changes the tables and knows how to take the change back. Set the connection's `migrations` to the folder that holds them, then:

~~~sh
php bin/crab app.php make:migration create_users_table    # writes database/migrations/2026_10_06_101500_create_users_table.php
php bin/crab app.php migrate                              # runs those that have not run
php bin/crab app.php migrate:status
php bin/crab app.php migrate:rollback                     # takes back the last run
~~~

~~~php
use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;
use Crab\Contracts\Database\Table;

return new class implements Migration {
    public function up(Schema $schema): void
    {
        $schema->create('posts', function (Table $table) {
            $table->id();
            $table->foreignId('user_id')->references('users', onDelete: 'cascade');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('body')->nullable();
            $table->boolean('published')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'published']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('posts');
    }
};
~~~

| Columns | `id`, `foreignId`, `integer`, `bigInteger`, `string`, `text`, `boolean`, `decimal`, `float`, `date`, `dateTime`, `json`, `binary`, `timestamps` |
| --- | --- |
| On a column | `nullable()`, `default($value)`, `useCurrent()`, `unsigned()`, `unique()`, `index()`, `references($table, $column, onDelete:)` |
| On the table | `index($columns)`, `unique($columns)`, `primary($columns)` |
| Changing a table, in `$schema->table()` | new columns and indexes, `renameColumn()`, `dropColumn()`, `dropIndex()`, `dropUnique()` |
| On the schema | `create()`, `table()`, `drop()`, `dropIfExists()`, `rename()`, `hasTable()`, `hasColumn()`, `connection()` |

- **A column must have a value** unless it is `nullable()` or has a default.
- **The same file runs on MySQL and SQLite.** The schema is written for the database in use.
- **Migrations run in the order of their file names** and each run is a batch; `migrate:rollback` takes back the last batch, newest first.
- **On SQLite a migration that fails before commit rolls back its changes.** A failed commit callback leaves the changes and migration record already committed. MySQL commits each change to a table as it is made, so there a migration that fails halfway has done the first half: keep each one small. See [MySQL and SQLite](docs/migrations.md#mysql-and-sqlite).
- **Rows can be moved in a migration** with `$schema->connection()->table('users')->...`.
- **SQLite checks foreign keys** on the connections the provider opens.
- **A column's type cannot be changed** in `$schema->table()`; SQLite has no statement for it. Add a column, copy the values and drop the old one.
- A package adds its own folder through `Crab\Contracts\Database\Migrator::path()`. To build tables outside a migration, ask for `Crab\Contracts\Database\Schema`.

## Cache

The cache is registered by default by `App::configure()`; nothing is needed in `app.php`.

~~~php
use Crab\Contracts\Cache\Cache;

$rates = $cache->remember('rates', 3600, fn () => $this->bank->rates());   // made once an hour
$cache->set('user.7', $user, 600);
$cache->get('user.7');
$cache->add('welcome.sent.7', true, 86400);                                 // true for the first caller only

if ($lock = $cache->lock('report', 300)) {                                  // null when another caller holds it
    try { $this->build(); } finally { $lock->release(); }
}
~~~

It is PSR-16's cache under the contract `Contracts\Cache\Cache`, which adds `remember()`, `add()`, `lock()` and `increment()`, a count that loses nothing between workers. A lock is released only by the caller that took it, and frees itself after its seconds if that caller dies. `Http\RateLimit` is middleware built on that count: `new RateLimit(5, per: 60)` on a route. `cache.store` chooses where entries are kept:

| Store | Kept | For |
| --- | --- | --- |
| `file` (default) | Files under `cache.directory` | One machine; every worker on it shares them |
| `database` | The `cache.table` table; `php cua cache:install` writes its migration | Several machines |
| `array` | The worker's memory | Tests |

`cache.prefix` keeps two applications apart in one store and `cache.ttl` is the seconds an entry lives when none is given, forever unless set. `php cua cache:clear` empties it, locks included, and `php cua cache:prune` removes what has outlived its time. A store of your own implements `Contracts\Cache\Store` and is bound under that name. See [docs/cache.md](docs/cache.md).

## File storage

Disks are registered by default; `local`, the folder `storage/files`, needs no settings.

~~~php
use Crab\Contracts\Storage\Disk;

$disk->write("avatars/{$user->id}.png", $upload->getStream(), 'image/png');  // whole or not at all
$disk->read('avatars/7.png');                                                // a stream
$disk->files('avatars/');                                                    // a page at a time
$disk->temporaryUrl('reports/7.csv', $clock->now()->modify('+10 minutes'));  // signed, on S3
~~~

`storage.disks` names each disk with its driver: `local`, `memory` for tests, or `s3` from `crabphp/s3`, for Amazon S3, Cloudflare R2, MinIO and other services that speak its API, through the HTTP client so a request never holds up the worker. `storage.default` is the one `Contracts\Storage\Disk` gives; `Contracts\Storage\Storage` gives any by name. A path is checked before a disk is touched, so one built from user input cannot leave the disk. See [docs/storage.md](docs/storage.md).

## Events

Events are registered by default by `App::configure()`; add your listeners in your own provider.

~~~php
use Crab\Contracts\Events\Dispatcher;

// In a provider's register(): who hears what.
$container->get(Dispatcher::class)->listen(UserRegistered::class, SendWelcomeEmail::class);

// Anywhere a Dispatcher is asked for: say that it happened.
$this->events->dispatch(new UserRegistered($user->id));
~~~

An event is any object and a listener is a closure or a class with `handle()`, made by the container when its first event arrives. It is PSR-14's dispatcher under `Contracts\Events\Dispatcher`. Listeners run at once, in the order added, in the fiber that dispatched; a listener with slow work pushes a job. See [docs/events.md](docs/events.md).

## Queue

The queue is registered by default by `App::configure()`; configure a database connection for its default store.

~~~sh
php cua queue:install        # writes the jobs table's migration into your own migrations
php cua migrate
php cua queue:work           # runs jobs until stopped; --workers=4, --queue=emails,default
~~~

~~~php
final readonly class SendWelcomeEmail
{
    public function __construct(private Mailer $mailer, private Database $db)     // services
    {
    }

    public function handle(int $userId): void                                    // the job's data
    {
        $user = $this->db->table('users')->find($userId);
        $this->mailer->send($user['email'], 'Welcome');
    }
}

$queue->push(SendWelcomeEmail::class, userId: $user->id);                // as soon as a worker is free
$queue->later(600, SendReminder::class, orderId: 12);                    // in ten minutes
$queue->on('emails')->push(SendWelcomeEmail::class, userId: 7);          // on a queue of its own
~~~

- **A job is a class with `handle()`**, as an action is: services in its constructor, its data as typed parameters, given by name when it is pushed. A wrong name or a missing value is refused by the line that pushed it.
- **The data is JSON:** numbers, text, booleans, null and arrays. Pass an id, not the record.
- **A job runs at least once.** One that throws is tried again, `queue.tries` times in all (3), after `queue.backoff` seconds (10, then 60); then it is kept as failed. A job sets its own with `#[Retry(tries: 5, backoff: [5, 30])]`. `queue:failed` lists those, `queue:retry <id|all>` puts them back and `queue:forget <id|all>` deletes them.
- **Workers are processes of their own**, so a slow job never holds up a request. Each is started and stopped as an HTTP worker is, and every failure reaches the log.
- **Supervisors keep kinds of work apart.** `queue.supervisors` names sets of workers, each with its own queues, number of workers and retries, and `queue:work` runs them all: `'mail' => ['queues' => ['mail'], 'workers' => 2]`. A flood of one kind of job then cannot take the workers of another.
- **A running job stays its worker's:** the worker renews it, and `queue.retry_after` (90) is how long it may go unrenewed, its worker dead or blocked, before another is given it.
- **A timeout bounds a try:** `queue.timeout`, or `#[Timeout(seconds: 300)]` on the job, cancels the `Cancellation` its `handle()` takes; a job still running `queue.grace` later has its worker's process ended and replaced.

See [docs/queues.md](docs/queues.md).

## Scheduling

The schedule is registered by default by `App::configure()`; declare tasks in your own provider.

~~~php
use Crab\Contracts\Schedule\Schedule;

// In a provider's register():
$schedule = $container->get(Schedule::class);
$schedule->command('report:send', '--month=last')->monthly(1, '06:00');
$schedule->job(PruneTokens::class, olderThanDays: 30)->daily('03:00');
$schedule->call(Heartbeat::class)->everyMinutes(5);
$schedule->call(PollGateway::class)->everySeconds(0.5);
$schedule->call(fn () => $container->get(Cache::class)->delete('rates'))->cron('0 9 * * mon-fri');
~~~

~~~sh
php cua schedule:work        # runs each task when it is due, until stopped
php cua schedule:list        # the tasks, and when each runs next
~~~

Keep one `schedule:work` running and no cron entry is needed. Switched on in `server.with`, as in production, the scheduler and the queue's workers are run by `php cua start` itself, beside HTTP, and reloaded with the server. `everySeconds()` runs a task by an interval, down to a hundredth of a second, counted from when the scheduler starts. A task that fails is logged and runs again when next due; one still running is not started twice. `->onOneServer()` lets one of several machines run a task, agreed through a shared cache. `schedule.timezone` is the zone the times are in. See [docs/scheduling.md](docs/scheduling.md).

## Running the server

~~~sh
php cua dev                  # while developing: restarts when a file is saved; Ctrl-C stops it
php cua start                # in the foreground, as it is: for a container
php cua start --daemon       # in the background, returning once it is up: for a machine you deploy to
~~~

`--address=tcp://0.0.0.0:8080` and `--workers=4` override the `server.address` and `server.workers` settings. `--single` serves in one process, without PHP's `pcntl` and `posix` extensions.

### In development

`dev` is what you want while writing code. It runs `start` as a child process and starts it again whenever a PHP file under `app`, `config` or `routes`, or `app.php` or `.env`, is added, changed or removed. Set `server.watch` to a list of other paths, or pass them: `--watch=app,config,packages`. Folders named `vendor`, `node_modules`, `storage` and `.git` are never looked into.

When the code does not load, the server stays down and the error stays on the screen; saving a fix starts it again. Files are checked a few times a second by their time and size, so no extension is needed. Options given to PHP itself (`php -d ...`) are not passed on to the child.

`start` never watches, in the foreground or the background, so nothing restarts by itself in production; a server in the background is given new code with `reload`.

### In production

~~~sh
php cua start --daemon       # start
php cua status               # is it running? exit status 0 when it is
php cua reload               # new code, without refusing a connection
php cua restart              # stop, then start: a short gap
php cua stop                 # finish the requests in hand, then stop
~~~

- **`start --daemon`** (or `-d`) returns when the server accepts connections, and fails with the reason when it cannot start, so a deploy script can rely on its exit status. The server leaves the terminal behind: closing it does not stop the server.
- **`reload`** is how new code goes live. The server starts a new process, with the code and configuration as they are now, on the socket it already listens on. When every worker of the new process accepts connections, the old process finishes the requests it has and stops. If the new one cannot start, because of a syntax error or a service that cannot be made, the old one goes on serving and `reload` fails, showing what went wrong first.
- **`stop`** uses the running server’s recorded stop allowance; an explicit `--timeout` overrides that wait. `--force` cuts requests and ends the server. See [Finishing requests on stop](docs/deployment.md#finishing-requests-on-stop).
- **Files.** The process id is in `server.pid` (`storage/crab.pid`) and everything the server prints goes to `server.log` (`storage/logs/server.log`); both are settings. The log is opened for appending, so it can be rotated by copying and truncating.
- **A deploy** installs dependencies and runs migrations successfully before `php cua reload`. For HTTP, queue and scheduler under one daemon, follow [A deploy, start to finish](docs/deployment.md#a-deploy-start-to-finish), including the production worker count and queue/schedule switches.

How it holds together:

~~~text
keeper            holds the listening socket; its process id is the server's and never changes
└─ server         the application, loaded once; a reload starts a new one beside it and retires this one
   ├─ worker      serves requests
   └─ worker
~~~

- **New connections remain accepted during reload.** The old and new server processes accept from the same socket. In-flight requests have the old worker’s `server.drain` allowance to finish; remaining connections are cut at the deadline. See [Finishing requests on stop](docs/deployment.md#finishing-requests-on-stop).
- **A server process that dies is started again** by the keeper, on the socket that never closed, so connections made meanwhile wait and are served. Six deaths within a minute and the keeper gives up and stops.
- **The keeper runs the code it was started with** until a `restart`. It does very little, so that rarely matters; restart after upgrading the framework itself.
- **The new process starts in the directory `reload` was run from**, with the script it was run with, so deploying into a new folder and reloading from there works. `storage/` must then be shared between the folders, since the process id file is how `reload` finds the server.

Things to know:

- A server in the foreground is not controlled by these commands. Under Docker, or systemd with `Type=simple`, run `php cua start` and let the manager restart it; a container is replaced, not reloaded.
- For systemd with reloads, use the background server: `Type=forking`, `PIDFile=` the `server.pid` path, `ExecStart=php cua start --daemon`, `ExecReload=php cua reload`, `ExecStop=php cua stop`.
- Running in the background needs PHP's `pcntl` and `posix` extensions, and so Linux or macOS.
- The server has no TLS. Put it behind a proxy that does.
- Sending the server SIGUSR1 replaces its workers one at a time with the code it already has. It does not load new code; `reload` does.

## Console

`bin/crab app.php` lists the application's commands and `bin/crab app.php <command>` runs one. `<command> --help`, or `-h`, describes a command without running it, as `help <command>` does. These are built in:

| Command | Does |
| --- | --- |
| `dev` | Serves the application while developing, restarting it when a file is saved; see [Running the server](#running-the-server). |
| `start` | Serves the application, for production; see [Running the server](#running-the-server). |
| `stop`, `reload`, `restart` | Control a server running in the background. |
| `status` | Shows the servers that are running, their workers and what each has handled. |
| `route:list` | Lists the registered routes, each with its action and the middleware around it. |
| `make:middleware` | Writes a new middleware class in `app/Http/`. |

Providers add theirs:

| Provider | Commands |
| --- | --- |
| `DatabaseProvider` | `migrate`, `migrate:rollback`, `migrate:status`, `make:migration` |
| `TokenAuthProvider` | `token:install` |
| `CacheProvider` | `cache:clear`, `cache:prune`, `cache:install` |
| `QueueProvider` | `queue:work`, `queue:failed`, `queue:retry`, `queue:forget`, `queue:install` |
| `ScheduleProvider` | `schedule:work`, `schedule:list` |

Add your own with `Command::add('mail:send', SendMail::class)`, which is `$app->console->add()` under a shorter name. A command is a class implementing `Contracts\Console\Command` (`description()` and `handle(Input $input, Output $output): int`), made by the container when its description or execution is first needed, or a closure taking the input and output. `Input` gives positional arguments and `--name=value` or `--flag` options. Validate a value's entire form and range before casting or doing work; see [Arguments and options](docs/console.md#arguments-and-options). A provider adds commands through the container's `Contracts\Console\Console` entry.

## Services, providers and middleware

~~~php
$app->register(Acme\Mailer\MailerProvider::class);   // a class with register(Container $container)
->withMiddleware([Acme\Tracing::class])              // in app.php: any PSR-15 middleware, around every request
Route::get('/users/{id}', ShowUser::class);            // an action class made by the container
~~~

A provider reaches the application through contracts in the container: `Contracts\Routing\Router` to add routes, `Contracts\Console\Console` to add commands, `Contracts\Http\MiddlewareStack` to add middleware, `Contracts\Runner` to run worker processes of its own, as the queue and the scheduler do, and `Contracts\Config\Config` to declare its settings with `defaults('cache', [...], $rules)`, which the application's own configuration overrides and which are checked against their rules at startup; see [docs/configuration.md](docs/configuration.md#checked-at-startup).

A provider that needs something open in every worker, such as a connection or a timer, implements `Contracts\WorkerProvider` too: `start(Worker $worker, Container $container)` runs in each worker after the engine forks and `stop(Worker $worker, Container $container)` after it has drained. A task or timer that fails in a worker is written to the log and the worker keeps running. `Testing\WorkerHarness::run()` runs such a provider through a worker's life inside a test.

`$app->container` autowires constructors by type and keeps one object of each class per worker; use `bind()` for a new one each time, `singleton()` to say how one is made and `instance()` for an existing value. Because those objects are shared by every request a worker serves, they must not hold the state of one request. Middleware given by class name is made inside each worker as it starts, so a misconfigured application fails at startup; an action given by class name is made when its route is first requested.

## Layout

| Path | Contents |
| --- | --- |
| `src/Engine/` | The networking engine: TCP, UDP, HTTP/1.1, WebSocket, coroutines, timers and supervised workers. It has its own `composer.json`, so it can later be published alone as `crabphp/engine`; for now it ships inside the framework. See its [README](src/Engine/README.md) and [architecture](src/Engine/ARCHITECTURE.md). |
| `src/App.php` | The application: configuration, container, router, middleware and the engine runtime that serves them. |
| `src/Http/` | PSR-7 request, response, stream and URI, the PSR-17 factory, the middleware pipeline, the error handler, and `EngineHandler`, which runs a PSR-15 handler on the engine's HTTP server. |
| `src/Console/` | The command runner, with its input and output. |
| `src/Commands/` | The application's built-in commands. |
| `src/Container/` | The PSR-11 container. |
| `src/Auth/` | Authentication: the user a middleware puts on the request, password hashing, and tokens in the database with the middleware that reads them. |
| `src/Cache/` | The PSR-16 cache, with file, database and in-memory stores. |
| `src/Clock/` | The PSR-20 clock. |
| `src/Events/` | The PSR-14 event dispatcher. |
| `src/Queue/` | Jobs: the queue, its stores, and the consumer that runs them in workers of their own. |
| `src/Schedule/` | Tasks run by the clock: the schedule, cron expressions and the scheduler. |
| `src/Database/` | Pooled PDO connections, the query layer, the query builder, the schema builder and migrations. |
| `src/Config/` | Configuration files and environment variables. |
| `src/Log/` | The PSR-3 logger. |
| `src/Testing/` | Helpers for testing components. |
| `src/Server.php` | Runs the server in the foreground, in the background and under a watcher, and controls a running one. |
| `src/Routing/` | The router, itself a PSR-15 handler, and `ActionHandler`, which calls a route's action by its signature. |
| `src/Broadcast/` | Channel memberships, bounded fan-out and local or package carriers behind contracts. |
| `src/HttpClient/` | The HTTP client: PSR-18, on the event loop, HTTP/2 and HTTP/1.1. |
| `src/Validation/` | Builds typed objects from request data, with rules as attributes. |
| `src/Contracts/` | Every interface another package implements or type-hints; they extend the PSR ones where Crab adds methods. |
| `tests/` | Framework tests beside their components; engine tests in `tests/Engine/`. `tests/EngineBoundaryTest.php` keeps the engine free of framework dependencies. |
| `benchmarks/` | Engine benchmarks and their recorded results; start at [BENCHMARKS.md](BENCHMARKS.md). |
| `examples/` | Runnable engine examples. |
| `docs/` | The guide for application developers, one page per topic; `composer docs` serves it for reading. |

## Develop

~~~sh
composer install
composer test
php bin/crab examples/hello.php start --single   # the framework
php bin/crab examples/servers.php --single   # the engine alone
~~~

The design is described in [ARCHITECTURE.md](ARCHITECTURE.md), the flow for building a component in [COMPONENTS.md](COMPONENTS.md) and the conventions in [AGENTS.md](AGENTS.md).

`composer test:linux` and `composer soak` are manual checks, described in the engine README. [CONTRIBUTING.md](CONTRIBUTING.md) says how to set up, test, format and lint, and [RELEASING.md](RELEASING.md) is the release checklist.
