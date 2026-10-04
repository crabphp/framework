# Crab Framework

A minimal PHP 8.4 web framework built on the Crab networking engine. It is at an early stage: the engine is implemented and measured, and the first framework components serve routed HTTP requests. Components follow the PHP-FIG standards: PSR-7 messages, PSR-17 factories, PSR-15 handlers and middleware, a PSR-11 container and a PSR-3 logger.

## Hello, world

~~~php
<?php

use Crab\App;
use Crab\Contracts\Http\Request;
use Crab\Http\Response;

$app = new App();
$app->router
    ->get('/', static fn() => "Hello, world!\n")
    ->get('/hello/{name}', static fn(Request $request) => Response::json(['hello' => $request->getAttribute('name')]));

return $app;
~~~

Save it as `app.php` and run `php bin/crab app.php serve --single`. A route handler is a closure or any PSR-15 `RequestHandlerInterface`; a closure returns a PSR-7 response, or a string that is sent as plain text. Route parameters arrive as request attributes. `{name}` matches one path segment and `{name:expression}` matches a regular expression.

## Requests and responses

A closure handler receives `Contracts\Http\Request`, a PSR-7 server request with four additions:

~~~php
$app->router->post('/users/{id}/avatar', static function (Request $request) {
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

## Console

`bin/crab app.php` lists the application's commands and `bin/crab app.php <command>` runs one. Two are built in:

| Command | Does |
| --- | --- |
| `serve` | Serves the application. `--address=tcp://127.0.0.1:8080` and `--workers=1` default to the `server.address` and `server.workers` configuration keys; `--single` serves in one process without `pcntl` and `posix`. |
| `routes` | Lists the registered routes. |

Add your own with `$app->console->add('mail:send', SendMail::class)`. A command is a class implementing `Contracts\Console\Command` (`description()` and `handle(Input $input, Output $output): int`), made by the container when it runs, or a closure taking the input and output. `Input` gives positional arguments and `--name=value` or `--flag` options. A provider adds commands through the container's `Contracts\Console\Console` entry.

## Services, providers and middleware

~~~php
$app->register(Acme\Mailer\MailerProvider::class);   // a class with register(Container $container)
$app->middleware(Acme\Cors::class);                   // any PSR-15 middleware
$app->router->get('/users/{id}', ShowUser::class);     // a PSR-15 handler made by the container
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
| `src/Config/` | Configuration files and environment variables. |
| `src/Log/` | The PSR-3 logger. |
| `src/Testing/` | Helpers for testing components. |
| `src/Routing/` | The router, itself a PSR-15 handler. |
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
