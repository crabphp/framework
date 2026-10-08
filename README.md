# Crab Framework

A minimal PHP 8.4 framework for JSON APIs, built on the Crab networking engine. It is written to be read and understood, by people and by AI agents alike: explicit types, no hidden state, one way to do each thing. It is at an early stage: the engine is implemented and measured, and the first framework components serve routed HTTP requests. Components follow the PHP-FIG standards: PSR-7 messages, PSR-17 factories, PSR-15 handlers and middleware, a PSR-11 container and a PSR-3 logger.

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

Save it as `app.php` and run `php bin/crab app.php serve --single`. `Route::get()` declares a route on the application just created; it is `$app->router->get()` under a shorter name. `{name}` matches one path segment and `{name:expression}` matches a regular expression.

## Handlers

A route's handler says in its signature what it takes, and the framework checks the request against it before the handler runs.

~~~php
use Crab\Http\Response;
use Crab\Validation\Email;
use Crab\Validation\Length;

// A few fields: take them as parameters.
Route::get('/users', static fn (int $page = 1, ?string $search = null): array => $users->page($page, $search));

Route::post('/users', static function (#[Email] string $email, #[Length(min: 2, max: 50)] string $name): Response {
    $user = $users->create($email, $name);

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

    public function store(CreateOrder $input): Order               // many fields: one class
    {
        ...
    }
}
~~~

A handler is a closure, a class and the name of its method, or the name of a class with `__invoke()`. A class is made by the container, once per worker, and extends nothing.

### Groups

Routes that share a path prefix, middleware, or both are declared in a group:

~~~php
Route::group(prefix: '/admin', middleware: [TokenAuth::class], routes: function () {
    Route::get('/users', [AdminController::class, 'users']);          // GET /admin/users
    Route::get('/users/{id}', [AdminController::class, 'user']);
});
~~~

Either may be left out, and groups may be nested. See [Middleware](#middleware) for what goes in `middleware:`.

### What a handler takes

| Parameter | Is |
| --- | --- |
| Named as in the pattern, `string`, `int`, `float` or `bool` | That route parameter, as that type |
| Any other `int`, `float`, `string`, `bool`, `array`, enum or `DateTimeImmutable` | A field of the request's data, by its name |
| One class | The input: all the fields in one object, in place of separate parameters |
| Typed as a PSR-7 server request, or not typed | The request itself |
| A class that implements `Contracts\Auth\Identity` | The logged-in user; see [Authentication](#authentication) |

The request's data is the JSON body, or the query string for `GET`, `HEAD` and `DELETE`. Fields the handler does not name are ignored and never reach it; to read them, or the raw body, take the request as well and call `getParsedBody()`.

Take a few flat fields as parameters. Use a class when there are many, when they nest, or when several routes take the same ones. Services are not parameters; they belong in the constructor of a handler class. Anything else in a signature is refused when the route is added, so a mistake stops the application from starting.

### What a handler returns

~~~php
return $user;                                             // an object or array: JSON, 200
return 'pong';                                            // a string: plain text
return;                                                   // nothing: 204
return Response::created($user, "/users/{$user->id}");    // 201, with a Location header
return Response::noContent();                             // 204
return Response::redirect('/login');                      // 302; pass 301, 303, 307 or 308 for another
return Response::download($path, 'invoice.pdf');          // a file, read as it is sent
return Response::json($user)->withHeader('Cache-Control', 'max-age=60');
~~~

Return the data when 200 is right, and a response when the status, a header or the body needs saying. Dates are written as ISO 8601 text.

### Validation

The type of a parameter says what is accepted, and attributes add rules. This holds for a handler's own parameters and for the constructor of an input class alike:

| Declaration | Accepts |
| --- | --- |
| `int`, `float`, `string`, `bool` | That value. JSON is not converted: `"36"` is not a whole number. A query string is text, so there `page=3` is read as `3`. |
| `?type`, or a default value | May be left out |
| A backed enum | One of its values |
| `DateTimeImmutable` | A date and time as text, such as `2026-01-31T12:00:00Z` |
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

Reading a signature costs nothing per request, because it is read once when the route is added. Calling a closure by its signature adds about 0.05 µs to a request over a PSR-15 handler, and validated input with a typed response about 1 µs over the same checks written by hand (`benchmarks/typed-handler.php`, PHP 8.4 with JIT, Apple M1 Pro).

## Middleware

Middleware is code that runs around a request: before it reaches the route, after the route has answered, or instead of the route.

~~~php
use Crab\Contracts\Http\Middleware;

final readonly class RequireJson implements Middleware
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!str_contains($request->getHeaderLine('Content-Type'), 'json')) {
            return ErrorResponse::make($request, 415);          // answer here, and the route is never reached
        }

        $response = $handler->handle($request);                 // let it through

        return $response->withHeader('X-Api-Version', '2');     // and add to what comes back
    }
}
~~~

`php cua make:middleware RequireJson` writes that class for you, in `app/Middleware/`. `Middleware` is PSR-15's `MiddlewareInterface` under Crab's name, so middleware from any PSR-15 library works too. A middleware class is made by the container, once per worker, so its constructor can ask for services; like them, it must not keep anything about one request.

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
Route::get('/me', [UserController::class, 'me'], middleware: [TokenAuth::class]);

Route::group(prefix: '/admin', middleware: [TokenAuth::class, RequireAdmin::class], routes: function () {
    Route::get('/users', [AdminController::class, 'users']);
});
~~~

They run from the outside in, and the response travels back out the same way:

~~~text
request  →  every request's  →  the group's  →  the route's  →  handler
~~~

- **In each list the first is outermost.** So `prepend:` is what a request meets first and what sees the response last.
- **Middleware around every request also runs when no route matches**, which is how a 404 still gets its CORS headers. A route's middleware runs only once its route has matched.
- **A refusal passes back through everything outside it.** A 401 from a route's middleware is still seen by the middleware around every request.
- **To hand something to the handler, put it on the request:** `$handler->handle($request->withAttribute('tenant', $tenant))`, read with `$request->getAttribute('tenant')`.
- **Middleware given by class name is made when a worker starts**, not on the first request, so one that cannot be made stops the server from starting. A route that names a class that does not exist, or one that is not middleware, is refused as soon as it is declared, with the route's name.
- **The same middleware given twice runs once.** `TokenAuth::class` on a group and again on a route inside it is one check, not two. Two objects of one class, `new RequireRole('admin')` and `new RequireRole('billing')`, are two middleware and both run.
- **`php cua routes` shows the middleware around each route**, its groups' included.
- **With options, give an object:** `middleware: [new TokenAuth($tokens, $users, 'X-Api-Key', '')]`.
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
- **It answers the browser's question itself.** Before most requests a browser sends an `OPTIONS` request asking permission; that is answered with 204 and never reaches a route.
- **It protects nothing.** A request from an origin that is not listed is still handled, without the headers a browser needs; anything that is not a browser ignores them. Authentication is what keeps callers out.
- **It costs about 0.1 µs** for a request that names no origin and about 0.9 µs for one that does, measured in process.

### Request ids

`Http\RequestId` is a middleware that ships but is not on by default. It gives each request an id: the `X-Request-Id` it arrived with when that is a plain token, otherwise a new one. The id is the request's `request_id` attribute, is returned in `X-Request-Id`, and is added to the log record of a failure. Add it with `prepend:` so that it is outside the error handler. It costs about 2 µs per request, measured in process, where a routed request otherwise costs about 3.

## Authentication

Who makes a request is decided by middleware. Whatever the way, it ends the same: the middleware puts your user on the request, and a handler takes that user as a parameter.

~~~php
use Crab\Contracts\Auth\Identity;

final readonly class User implements Identity          // your user: anything with a public $id
{
    public function __construct(public int $id, public string $email)
    {
    }
}

Route::post('/login', [AuthController::class, 'login']);                  // open to all

Route::group(middleware: [TokenAuth::class], routes: function () {
    Route::get('/me', static fn (User $user): User => $user);            // the logged-in user, by type
    Route::post('/posts', [PostController::class, 'store']);
});
~~~

- **The user reaches a handler by type.** A parameter whose class implements `Identity` receives the user that middleware put on the request's `user` attribute; a request with none is answered with `401`. Make the parameter nullable for a route that works either way.
- **There is no `Auth::user()`.** A worker serves requests that overlap, so the user travels with the request and nowhere else.
- **Any middleware can be a way of authenticating.** It finds the user, and either calls `$handler->handle($request->withAttribute('user', $user))` or answers `401`. A session cookie, a signed address, a JWT, a header a proxy sets: each is a few lines of middleware of your own, and needs nothing more from Crab.
- **`AuthProvider`** binds what every way shares, the `PasswordHasher`: `hash()`, `verify()` and `needsRehash()`, over PHP's own password hashing.

### Tokens

One way ships, because nearly every API starts with it: a token in a header, kept in your database.

~~~php
// app.php
->withProviders([DatabaseProvider::class, AuthProvider::class, TokenAuthProvider::class, AppProvider::class])
~~~

~~~sh
php cua tokens:install       # writes the tokens table's migration into your own migrations
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
    public function login(#[Email] string $email, string $password): array
    {
        $row = $this->db->table('users')->where('email', $email)->first();
        if ($row === null || !$this->passwords->verify($password, $row['password'])) {
            throw new HttpException(401, 'These credentials do not match.');
        }

        return ['token' => $this->tokens->issue($row['id'], 'login')];
    }
}
~~~

The client then sends `Authorization: Bearer <token>`, and `TokenAuth` is the middleware that reads it.

- **You say how a user is found.** A token records whose it is, not who that is, so bind `Contracts\Auth\IdentityResolver` in your own provider; its `resolve($id)` returns your user or null.
- **The table is yours.** `tokens:install` writes the migration for `auth_tokens` into your migrations folder, where you can read and change it; the framework ships no migration of its own.
- **`TokenStore`** has `issue($user, $name, $expiresAt)`, `owner($token)`, `revoke($token)` and `revokeAll($user)`. A token is 64 random characters, shown once; only its hash is stored. Checking one is one query, and a revoked token stops working at once.
- **Another header, or another word before the token,** is an argument, given as an object:

~~~php
new TokenAuth($tokens, $users);                          // Authorization: Bearer <token>
new TokenAuth($tokens, $users, 'X-Api-Key', '');         // X-Api-Key: <token>
new TokenAuth($tokens, $users, scheme: 'Token');         // Authorization: Token <token>
new TokenAuth($tokens, $users, required: false);         // lets a request without a token through, with no user
~~~

Roles and permissions, and sessions with cookies, are not built.

## Requests and responses

A handler that asks for the request receives `Contracts\Http\Request`, a PSR-7 server request with four additions. A PSR-15 `RequestHandlerInterface` can be a route's handler too; it receives the request, with the route parameters as attributes.

~~~php
Route::post('/users/{id}/avatar', static function (Request $request) {
    $id = $request->getAttribute('id');          // route parameter
    $page = $request->query('page', 1);           // query string
    $name = $request->input('name');              // form field or JSON key
    $file = $request->file('avatar');             // PSR-7 UploadedFileInterface, or null
    $file?->moveTo("/var/uploads/{$id}.png");

    return Response::json(['saved' => $file !== null])
        ->withCookie(new Cookie('last_upload', (string) time(), maxAge: 3600));
});
~~~

- **Parsed body:** `input()` and `getParsedBody()` read urlencoded forms, `multipart/form-data` and JSON objects or arrays. A body that claims one of these and is malformed is answered with 400.
- **Uploads:** `getUploadedFiles()` returns the PSR-7 tree; `photos[]` fields nest as in PHP. A form may have at most 1,000 parts, and its fields together 1 MiB; a file may be as large as a body may.
- **Large uploads:** a request body may be 1 MiB unless `server.max_body` says more, in bytes, and a client has `server.body_timeout` seconds to send it (30). A file is never held in memory: the engine keeps a large body on disk, the file is read from where it lies in it, and `moveTo()` copies it out. A 40 MiB upload is read and stored within a few megabytes. Copying a large file takes the worker's time while it lasts, as any reading or writing of a file does.
- **Responses:** `Response::text()`, `html()` and `json()`, or `new Response($status, $headers, $body)`. `withCookie()` adds a `Set-Cookie`; with a response from another library use `withAddedHeader('Set-Cookie', (string) $cookie)`.
- **Cookies:** `new Cookie($name, $value, maxAge: ..., path: ..., domain: ..., secure: ..., httpOnly: ..., sameSite: ...)` defaults to `HttpOnly` and `SameSite=Lax`; `Cookie::forget($name)` deletes one. Read them with `$request->getCookieParams()`.
- **Unmatched routes:** 404, or 405 with an `Allow` header, with the same JSON-or-text body as any other error.

## Calling other APIs

~~~php
->withProviders([HttpClientProvider::class])
~~~

~~~php
use Crab\Contracts\HttpClient\Client;

final readonly class ShowWeather
{
    public function __construct(private Client $http)
    {
    }

    public function __invoke(string $city): array
    {
        $weather = $this->http->get('https://api.weather.example/v1/now', query: ['city' => $city]);

        return $weather->ok() ? $weather->json() : ['error' => $weather->getStatusCode()];
    }
}
~~~

While a request waits for its answer the worker serves other requests, which a call with `file_get_contents()` or cURL does not allow: either holds the whole worker until the other server answers.

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
- **Connections are kept and used again,** up to 8 requests on their way to one server at once; another waits its turn.
- **Redirects are followed,** up to 5, as a browser follows them, and what proves who is asking is not sent on to another server.
- **An answer compressed with gzip or deflate** is handed over as what it says. Brotli is not asked for.
- **It is PSR-18's client** under the contract `Contracts\HttpClient\Client`; `sendRequest()` takes any PSR-7 request, and a library that asks for `Psr\Http\Client\ClientInterface` is given the same object.

### Timeouts

~~~php
$http->timeout(2.0)->get($url);
~~~

`timeout()` gives a client that allows each exchange that many seconds, redirects and waiting for a free connection included, and is otherwise the same: it uses the same connections. The client it was asked of is left as it was, which matters because every request a worker serves shares it.

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
$stripe = new Client('https://api.stripe.com/v1', headers: ['Authorization' => "Bearer {$key}"]);
$stripe->post('/charges', json: ['amount' => 500])->throw();
~~~

Make it in a provider and bind it under a name of your own. It takes the same arguments as the settings below, among them `proxy:`.

### Cookies

~~~php
$session = $http->cookies(new CookieJar());
$session->post('https://shop.example/login', form: [...]);
$session->get('https://shop.example/orders');            // sent with the cookie the login set
~~~

A client keeps no cookies unless it is given a jar, because one jar on a client every request shares would hand one caller's cookies to the next. Make a jar for each caller. A cookie goes back only where a browser would send it: to the host that set it or the hosts under the domain it named, on its paths, over an encrypted connection when it said so, and until it expires.

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
| `http_client.timeout` | `30.0` | Seconds a whole exchange may take |
| `http_client.connect_timeout` | `5.0` | Seconds connecting may take |
| `http_client.redirects` | `5` | `0` returns the redirect itself |
| `http_client.connections` | `8` | Requests on their way to one server at once |
| `http_client.base_url`, `http_client.headers` | none | Added to every request of the default client |
| `http_client.proxy` | none | `http://host:port`, with `user:password@` when it asks |
| `http_client.tls` | `[]` | PHP's `ssl` context options, such as `['cafile' => '/path/ca.pem']` |

What it does not do: HTTP/2, and looking a name up without holding the worker. Finding a server's address takes as long as the system's resolver does, usually a millisecond or two, and the address is then remembered for a minute. `https` needs PHP's `openssl` extension, and a compressed answer its `zlib`.

## Configuration, errors and logging

~~~php
use Crab\App;
use Crab\Config\Config;
use Crab\Config\Env;

Env::load(__DIR__ . '/.env');
$app = new App(Config::load(__DIR__ . '/config'));
~~~

An application with more than one file is usually described in one chain instead, which does the same and loads the route files too:

~~~php
return App::configure(__DIR__)
    ->withProviders([
        DatabaseProvider::class,
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
- **Providers** are given one by one or as a list; `routes:` and `commands:` each take one file or a list of files. `withMiddleware()` is described under [Middleware](#middleware).
- **`health: '/up'`** makes `GET /up` answer 200 and `{"status":"up"}`, for a load balancer or a deploy script that needs to know the server takes requests. It says that this worker answers and asks nothing of the database, so that a slow store does not take every server out of rotation at once; route the path to a handler of your own to check more.
- **Each step is optional and they may come in any order:** `create()` loads the environment, then the configuration, makes the application, registers the providers and middleware, and then includes the route and command files, which declare with `Route::` and `Command::`.

`Config::load()` reads every PHP file of a directory, keyed by file name:

~~~php
// config/log.php
return ['level' => env('LOG_LEVEL', 'info')];
~~~

Anywhere after the application is created, `config('log.level')` reads that value, and `config('log.level', 'debug')` supplies a default. `env('NAME', $default)` reads an environment variable, where the real environment wins over `.env`; the words `true`, `false` and `null` become those values. The two helpers are global functions over `Env::get()` and `$app->config->get()`. The application itself reads four keys:

| Key | Meaning | Default |
| --- | --- | --- |
| `app.name` | The application's name, shown above the command list | none |
| `app.debug` | Show failure details to clients | `false` |
| `log.path` | Where the default logger writes | standard error |
| `log.level` | The least severe level written | `debug` |

Anything thrown while handling a request is caught by `Http\ErrorHandler`, logged with its trace and answered with a 500, as JSON when the client accepts it and plain text otherwise. Throw `Http\HttpException` to answer with a chosen status and message, for example `throw new HttpException(404, 'No such user')`; below 500 it is not logged. The logger is the container's `Psr\Log\LoggerInterface` entry.

Inside a worker, a PHP warning or notice is thrown as an `ErrorException`, so it is logged and answered like any other failure instead of being printed and ignored. Code silenced with `@` is left alone. A deprecation is logged once per place and execution continues.

## Database

~~~php
$app->register(Crab\Database\DatabaseProvider::class);
~~~

~~~php
// config/database.php
return [
    'dsn' => env('DB_DSN', 'sqlite:' . __DIR__ . '/../database.sqlite'),   // or mysql:host=127.0.0.1;dbname=app
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
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
- **Connections are pooled per worker.** Each statement on `$db` borrows one and returns it; `$db->run(fn (Connection $c) => ...)` keeps one for several statements. A worker opens them on first use, at most `database.pool` of them (10, or 1 for SQLite, which allows one writer), and a caller waits up to `database.timeout` seconds (5) for a free one.
- **A dropped connection is replaced.** When the server had already closed it, the statement runs once on a new connection. When it was lost mid-statement or inside a transaction, the failure is reported and nothing is retried, so nothing is applied twice.
- **Failures** throw `Crab\Database\DatabaseException`, whose message and `$sql` hold the SQL but never the bound values.
- **PDO blocks.** While a statement runs, its worker serves nothing else; run more workers for more concurrent queries.

It works without the framework too: `new Database(fn () => new PDO($dsn, $user, $password))`. MySQL and SQLite are supported: the tests run on SQLite, and on MySQL when `CRAB_TEST_MYSQL_DSN` is set. PostgreSQL is not supported for now. There is no model yet.

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

Describe each under a name, and say which is the default:

~~~php
// config/database.php
return [
    'default' => 'main',
    'connections' => [
        'main' => [
            'dsn' => env('DB_DSN'),
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

A migration is a file that changes the tables and knows how to take the change back. Set `database.migrations` to the folder that holds them, then:

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
- **On SQLite a migration that fails leaves nothing behind**, because it runs in a transaction. MySQL commits each change to a table as it is made, so there a migration that fails halfway has done the first half: keep each one small.
- **Rows can be moved in a migration** with `$schema->connection()->table('users')->...`.
- **SQLite checks foreign keys** on the connections the provider opens.
- **A column's type cannot be changed** in `$schema->table()`; SQLite has no statement for it. Add a column, copy the values and drop the old one.
- A package adds its own folder through `Crab\Contracts\Database\Migrator::path()`. To build tables outside a migration, ask for `Crab\Contracts\Database\Schema`.

## Running the server

~~~sh
php cua serve                # in the foreground, restarting when a file changes; Ctrl-C stops it
php cua serve --no-watch     # in the foreground, as it is: for a container
php cua serve --daemon       # in the background, returning once it is up: for a machine you deploy to
~~~

`--address=tcp://0.0.0.0:8080` and `--workers=4` override the `server.address` and `server.workers` settings. `--single` serves in one process, without PHP's `pcntl` and `posix` extensions.

### In development

Plain `serve` is what you want while writing code. It runs the server as a child process and starts it again whenever a PHP file under `app`, `config` or `routes`, or `.env`, is added, changed or removed. Set `server.watch` to a list of other paths, or pass them: `--watch=app,config,packages`. Folders named `vendor`, `node_modules`, `storage` and `.git` are never looked into.

When the code does not load, the server stays down and the error stays on the screen; saving a fix starts it again. Files are checked a few times a second by their time and size, so no extension is needed. Options given to PHP itself (`php -d ...`) are not passed on to the child.

Where nothing should restart by itself, such as a container, pass `--no-watch` or set `server.watch` to `false`. A server in the background never watches; it is given new code with `reload`.

### In production

~~~sh
php cua serve --daemon       # start
php cua status               # is it running? exit status 0 when it is
php cua reload               # new code, without refusing a connection
php cua restart              # stop, then start: a short gap
php cua stop                 # finish the requests in hand, then stop
~~~

- **`serve --daemon`** (or `-d`) returns when the server accepts connections, and fails with the reason when it cannot start, so a deploy script can rely on its exit status. The server leaves the terminal behind: closing it does not stop the server.
- **`reload`** is how new code goes live. The server starts a new process, with the code and configuration as they are now, on the socket it already listens on. When every worker of the new process accepts connections, the old process finishes the requests it has and stops. If the new one cannot start, because of a syntax error or a service that cannot be made, the old one goes on serving and `reload` fails, showing what went wrong first.
- **`stop`** waits up to `--timeout=15` seconds for requests to finish. `--force` ends a server that has not stopped by then.
- **Files.** The process id is in `server.pid` (`storage/crab.pid`) and everything the server prints goes to `server.log` (`storage/logs/server.log`); both are settings. The log is opened for appending, so it can be rotated by copying and truncating.
- **A deploy** is then: put the new code in place, run the migrations, `php cua reload`.

How it holds together:

~~~text
keeper            holds the listening socket; its process id is the server's and never changes
└─ server         the application, loaded once; a reload starts a new one beside it and retires this one
   ├─ worker      serves requests
   └─ worker
~~~

- **No connection is refused or cut short by a reload.** The old and the new server process accept from the same socket, so a connection always has someone to take it, and a process that is stopping still answers a request that was already on its way. Measured on Linux and macOS with a client opening about 13,000 new connections a second through ten reloads in a row: none of some 100,000 requests failed.
- **A server process that dies is started again** by the keeper, on the socket that never closed, so connections made meanwhile wait and are served. Six deaths within a minute and the keeper gives up and stops.
- **The keeper runs the code it was started with** until a `restart`. It does very little, so that rarely matters; restart after upgrading the framework itself.
- **The new process starts in the directory `reload` was run from**, with the script it was run with, so deploying into a new folder and reloading from there works. `storage/` must then be shared between the folders, since the process id file is how `reload` finds the server.

Things to know:

- A server in the foreground is not controlled by these commands. Under Docker, or systemd with `Type=simple`, run `php cua serve --no-watch` and let the manager restart it; a container is replaced, not reloaded.
- For systemd with reloads, use the background server: `Type=forking`, `PIDFile=` the `server.pid` path, `ExecStart=php cua serve --daemon`, `ExecReload=php cua reload`, `ExecStop=php cua stop`.
- Running in the background needs PHP's `pcntl` and `posix` extensions, and so Linux or macOS.
- The server has no TLS. Put it behind a proxy that does.
- Sending the server SIGUSR1 replaces its workers one at a time with the code it already has. It does not load new code; `reload` does.

## Console

`bin/crab app.php` lists the application's commands and `bin/crab app.php <command>` runs one. `<command> --help`, or `-h`, describes a command without running it, as `help <command>` does. These are built in:

| Command | Does |
| --- | --- |
| `serve` | Serves the application; see [Running the server](#running-the-server). |
| `stop`, `reload`, `restart`, `status` | Control a server running in the background. |
| `routes` | Lists the registered routes, each with its handler and the middleware around it. |
| `make:middleware` | Writes a new middleware class in `app/Middleware/`. |

The database provider adds `migrate`, `migrate:rollback`, `migrate:status` and `make:migration`, and the token provider `tokens:install`.

Add your own with `Command::add('mail:send', SendMail::class)`, which is `$app->console->add()` under a shorter name. A command is a class implementing `Contracts\Console\Command` (`description()` and `handle(Input $input, Output $output): int`), made by the container when it runs, or a closure taking the input and output. `Input` gives positional arguments and `--name=value` or `--flag` options. A provider adds commands through the container's `Contracts\Console\Console` entry.

## Services, providers and middleware

~~~php
$app->register(Acme\Mailer\MailerProvider::class);   // a class with register(Container $container)
->withMiddleware([Acme\Tracing::class])              // in app.php: any PSR-15 middleware, around every request
Route::get('/users/{id}', ShowUser::class);            // a handler class made by the container
~~~

A provider reaches the application through contracts in the container: `Contracts\Routing\Router` to add routes, `Contracts\Console\Console` to add commands, `Contracts\Http\MiddlewareStack` to add middleware, and `Contracts\Config\Config` to declare its settings with `defaults('cache', [...])`, which the application's own configuration overrides.

A provider that needs something open in every worker, such as a connection or a timer, implements `Contracts\WorkerProvider` too: `start(Worker $worker, Container $container)` runs in each worker after the engine forks and `stop(Worker $worker, Container $container)` after it has drained. A task or timer that fails in a worker is written to the log and the worker keeps running. `Testing\WorkerHarness::run()` runs such a provider through a worker's life inside a test.

`$app->container` autowires constructors by type and keeps one object of each class per worker; use `bind()` for a new one each time, `singleton()` to say how one is made and `instance()` for an existing value. Because those objects are shared by every request a worker serves, they must not hold the state of one request. Middleware given by class name is made inside each worker as it starts, so a misconfigured application fails at startup; a handler given by class name is made when its route is first requested.

## Layout

| Path | Contents |
| --- | --- |
| `src/Engine/` | The networking engine: TCP, UDP, HTTP/1.1, WebSocket, coroutines, timers and supervised workers. It has its own `composer.json` and is also published as `crabphp/engine`. See its [README](src/Engine/README.md) and [architecture](src/Engine/ARCHITECTURE.md). |
| `src/App.php` | The application: configuration, container, router, middleware and the engine runtime that serves them. |
| `src/Http/` | PSR-7 request, response, stream and URI, the PSR-17 factory, the middleware pipeline, the error handler, and `EngineHandler`, which runs a PSR-15 handler on the engine's HTTP server. |
| `src/Console/` | The command runner, with its input and output. |
| `src/Commands/` | The application's built-in commands. |
| `src/Container/` | The PSR-11 container. |
| `src/Auth/` | Authentication: the user a middleware puts on the request, password hashing, and tokens in the database with the middleware that reads them. |
| `src/Clock/` | The PSR-20 clock. |
| `src/Database/` | Pooled PDO connections, the query layer, the query builder, the schema builder and migrations. |
| `src/Config/` | Configuration files and environment variables. |
| `src/Log/` | The PSR-3 logger. |
| `src/Testing/` | Helpers for testing components. |
| `src/Server.php` | Runs the server in the foreground, in the background and under a watcher, and controls a running one. |
| `src/Routing/` | The router, itself a PSR-15 handler, and typed handlers. |
| `src/HttpClient/` | The HTTP client: PSR-18, on the event loop, with kept connections. |
| `src/Validation/` | Builds typed objects from request data, with rules as attributes. |
| `src/Contracts/` | Every interface another package implements or type-hints; they extend the PSR ones where Crab adds methods. |
| `tests/` | Framework tests beside their components; engine tests in `tests/Engine/`. `tests/EngineBoundaryTest.php` keeps the engine free of framework dependencies. |
| `benchmarks/` | Engine benchmarks and their recorded results; start at [BENCHMARKS.md](BENCHMARKS.md). |
| `examples/` | Runnable engine examples. |

## Develop

~~~sh
composer install
composer test
php bin/crab examples/hello.php serve --single   # the framework
php bin/crab examples/servers.php --single   # the engine alone
~~~

The design is described in [ARCHITECTURE.md](ARCHITECTURE.md), the flow for building a component in [COMPONENTS.md](COMPONENTS.md) and the conventions in [AGENTS.md](AGENTS.md).

`composer test:linux` and `composer soak` are manual checks, described in the engine README.
