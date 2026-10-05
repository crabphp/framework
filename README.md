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

### What a handler takes

| Parameter | Is |
| --- | --- |
| Named as in the pattern, `string`, `int`, `float` or `bool` | That route parameter, as that type |
| Any other `int`, `float`, `string`, `bool`, `array`, enum or `DateTimeImmutable` | A field of the request's data, by its name |
| One class | The input: all the fields in one object, in place of separate parameters |
| Typed as a PSR-7 server request, or not typed | The request itself |

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

A rule of your own is an attribute class that implements `Crab\Contracts\Validation\Rule`: `check()` returns what is wrong or null, and `schema()` says the same for the documentation. To validate outside a route, ask for `Crab\Contracts\Validation\Validator` and call `validate(CreateUser::class, $data)`, which returns the object or throws `Crab\Validation\ValidationException` with `$errors`.

### OpenAPI

~~~sh
php bin/crab app.php openapi                        # print the document
php bin/crab app.php openapi --output=openapi.json
~~~

The OpenAPI 3.1 document is written from the signatures. There is nothing to annotate: it is a by-product of the code, and it says as much as the code declares.

- **What goes in is always described:** path and query parameters, the request body and the `422`.
- **What comes out is described when the handler declares it:** `: User`, or `: User|Response` for a handler that builds the response around a user. A list says what it holds with `#[ListOf(User::class)]`, since PHP's `array` cannot. Without a return type the route is listed with no response body.
- **The status is always listed as 200,** or 204 for a handler that returns nothing, because a status set with `Response::created()` is decided in the code and not in the signature.

The title and version are `app.name` and `app.version`.

Reading a signature costs nothing per request, because it is read once when the route is added. Calling a closure by its signature adds about 0.05 µs to a request over a PSR-15 handler, and validated input with a typed response about 1 µs over the same checks written by hand (`benchmarks/typed-handler.php`, PHP 8.4 with JIT, Apple M1 Pro).

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
- **Uploads:** `getUploadedFiles()` returns the PSR-7 tree; `photos[]` fields nest as in PHP. A multipart body is held in memory while it is read, bounded by the HTTP server's body limit (1 MiB by default), and a form may have at most 1,000 parts.
- **Responses:** `Response::text()`, `html()` and `json()`, or `new Response($status, $headers, $body)`. `withCookie()` adds a `Set-Cookie`; with a response from another library use `withAddedHeader('Set-Cookie', (string) $cookie)`.
- **Cookies:** `new Cookie($name, $value, maxAge: ..., path: ..., domain: ..., secure: ..., httpOnly: ..., sameSite: ...)` defaults to `HttpOnly` and `SameSite=Lax`; `Cookie::forget($name)` deletes one. Read them with `$request->getCookieParams()`.
- **Unmatched routes:** 404, or 405 with an `Allow` header, with the same JSON-or-text body as any other error.

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
    ->withMiddleware(Cors::class)
    ->withRouting(
        routes: 'routes/web.php',
        commands: 'routes/console.php',
    )
    ->create();
~~~

- **The base path** is the directory given to `configure()`. Its `.env` file and `config` folder are loaded by default, because `App::configure()` begins the chain with `withEnv('.env')` and `withConfig('config', required: false)`; call either again to name another. Nothing else is found by convention.
- **Paths may be relative** to the base path, and `$app->path('storage/app.log')` gives one in full anywhere else.
- **Providers and middleware** are given one by one or as a list; `routes:` and `commands:` each take one file or a list of files.
- **Each step is optional and they may come in any order:** `create()` loads the environment, then the configuration, makes the application, registers the providers and middleware, and then includes the route and command files, which declare with `Route::` and `Command::`.

`Config::load()` reads every PHP file of a directory, keyed by file name:

~~~php
// config/log.php
return ['level' => env('LOG_LEVEL', 'info')];
~~~

Anywhere after the application is created, `config('log.level')` reads that value, and `config('log.level', 'debug')` supplies a default. `env('NAME', $default)` reads an environment variable, where the real environment wins over `.env`; the words `true`, `false` and `null` become those values. The two helpers are global functions over `Env::get()` and `$app->config->get()`. The application itself reads five keys:

| Key | Meaning | Default |
| --- | --- | --- |
| `app.name` | The application's name, shown above the command list | none |
| `app.debug` | Show failure details to clients | `false` |
| `app.request_id` | Give every request an id | `false` |
| `log.path` | Where the default logger writes | standard error |
| `log.level` | The least severe level written | `debug` |

Anything thrown while handling a request is caught by `Http\ErrorHandler`, logged with its trace and answered with a 500, as JSON when the client accepts it and plain text otherwise. Throw `Http\HttpException` to answer with a chosen status and message, for example `throw new HttpException(404, 'No such user')`; below 500 it is not logged. The logger is the container's `Psr\Log\LoggerInterface` entry.

Inside a worker, a PHP warning or notice is thrown as an `ErrorException`, so it is logged and answered like any other failure instead of being printed and ignored. Code silenced with `@` is left alone. A deprecation is logged once per place and execution continues.

With `app.request_id` on, each request gets an id: the `X-Request-Id` it arrived with when that is a plain token, otherwise a new one. It is the request's `request_id` attribute, is returned in `X-Request-Id`, and is added to the log record of a failure. It is off by default because it costs about 2 µs per request, measured in process, where a routed request otherwise costs about 3.

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

It works without the framework too: `new Database(fn () => new PDO($dsn, $user, $password))`. MySQL and SQLite are supported: the tests run on SQLite, and on MySQL when `CRAB_TEST_MYSQL_DSN` is set. PostgreSQL is not supported for now. There is no model or migration yet.

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

## Console

`bin/crab app.php` lists the application's commands and `bin/crab app.php <command>` runs one. Two are built in:

| Command | Does |
| --- | --- |
| `serve` | Serves the application. `--address=tcp://127.0.0.1:8080` and `--workers=1` default to the `server.address` and `server.workers` configuration keys; `--single` serves in one process without `pcntl` and `posix`. |
| `routes` | Lists the registered routes. |

Add your own with `Command::add('mail:send', SendMail::class)`, which is `$app->console->add()` under a shorter name. A command is a class implementing `Contracts\Console\Command` (`description()` and `handle(Input $input, Output $output): int`), made by the container when it runs, or a closure taking the input and output. `Input` gives positional arguments and `--name=value` or `--flag` options. A provider adds commands through the container's `Contracts\Console\Console` entry.

## Services, providers and middleware

~~~php
$app->register(Acme\Mailer\MailerProvider::class);   // a class with register(Container $container)
$app->middleware(Acme\Cors::class);                   // any PSR-15 middleware
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
| `src/Database/` | Pooled PDO connections, the query layer and the query builder. |
| `src/Config/` | Configuration files and environment variables. |
| `src/Log/` | The PSR-3 logger. |
| `src/Testing/` | Helpers for testing components. |
| `src/Routing/` | The router, itself a PSR-15 handler, typed handlers and the OpenAPI document. |
| `src/Validation/` | Builds typed objects from request data, with rules as attributes and JSON Schema. |
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
