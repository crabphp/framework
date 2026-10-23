# Configuration

- [Introduction](#introduction)
- [Environment variables](#environment-variables)
- [Configuration files](#configuration-files)
- [Reading configuration](#reading-configuration)
- [Describing the application](#describing-the-application)
    - [Providers](#providers)
    - [Middleware](#middleware)
    - [Routes and commands](#routes-and-commands)
    - [A health check](#a-health-check)
- [The application's own settings](#the-applications-own-settings)

## Introduction

Configuration has two layers. Values that differ per machine (a database password, the debug switch) live in the environment or in `.env`. Everything else lives in PHP files under `config/`, which read the environment where they need to. Your code reads only `config()`, so there is one place to look for any setting.

## Environment variables

`.env` holds this machine's values, one per line:

```text
APP_NAME=Orders
APP_DEBUG=true

DB_DSN=mysql:host=127.0.0.1;dbname=orders
DB_USERNAME=root
DB_PASSWORD=
```

Read one with `env()`, giving the value to use when it is not set:

```php
// config/app.php
return [
    'name' => env('APP_NAME', 'Crab'),
    'debug' => env('APP_DEBUG', false),
];
```

- The real environment wins over `.env`, so a container or a deploy tool can set values without a file.
- The words `true`, `false` and `null` become those values; everything else is text.
- The file may be absent, as it is in production when the environment is set another way.

> [!WARNING]
> Call `env()` only inside configuration files. Everywhere else read `config()`. Two sources for one setting is how a value ends up different in two places.

## Configuration files

Every PHP file in `config/` returns an array, and is keyed by its file name:

```php
// config/mail.php
return [
    'host' => env('MAIL_HOST', '127.0.0.1'),
    'port' => (int) env('MAIL_PORT', 25),
    'from' => ['address' => 'hello@example.com', 'name' => 'Orders'],
];
```

To add a setting of your own, add a key to a file, or add a file. There is nothing to register.

A framework section works without a file, from its defaults. To see every setting of one and change it, write its complete file, each setting at its default with what it does:

```sh
php cua config:publish              # lists the sections
php cua config:publish server queue
# Created config/server.php
# Created config/queue.php
```

An existing file is left alone; `--force` replaces it, losing what it set.

## Reading configuration

| Setting | Default | Use |
| --- | --- | --- |
| `async`, on a database connection | `false` | Use the nonblocking MySQL driver inside workers; console commands and SQLite use PDO. See [Database](database.md#not-blocking-the-worker). |

`config()` reads a value by its file name and key, with dots between the levels:

```php
config('mail.host');                    // "127.0.0.1"
config('mail.from.address');            // "hello@example.com"
config('mail.timeout', 10);             // 10, because the key is not set
```

It works anywhere after the application is created. In a class, you may prefer to take the values in the constructor and read `config()` in the provider that makes it, which keeps the class usable without the framework:

```php
// app/Providers/AppProvider.php
$container->singleton(Mailer::class, static fn (): Mailer => new SmtpMailer(
    config('mail.host'),
    config('mail.port'),
));
```

The core and providers declare their defaults before your application runs, so `config()` exposes the same values their consumers use. Values you supply, including explicit `null` and `false`, take precedence.

Configuration is fixed once the application is defined. Do not change it at run time: every worker has its own copy, and a change in one is seen by no other.

## Describing the application

`app.php` describes the application as one chain and returns it. `php cua` runs it and your tests load it.

```php
// app.php
use App\Providers\AppProvider;
use Crab\App;
use Crab\Http\Cors;

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
```

Every step is optional and they may come in any order. Nothing happens until `create()`, which loads the environment, then the configuration, makes the application, registers the providers and the middleware, and then includes the route and command files.

`App::configure(__DIR__)` begins the chain with two steps on your behalf, `withEnv('.env')` and `withConfig('config', required: false)`. The framework providers are also registered by default. Call either file step yourself to name another file or folder:

```php
use Crab\App;

return App::configure(__DIR__)
    ->withEnv('.env.testing')
    ->withConfig('settings')
    ->create();
```

### Providers

`App::configure()` starts with the framework's providers, `App::PROVIDERS`, in this order: `DatabaseProvider`, `AuthProvider`, `CacheProvider`, `EventsProvider`, `QueueProvider`, `ScheduleProvider`, `HttpClientProvider`, `BroadcastProvider`. Add your own and your packages' providers with `withProviders()`, as one provider or a list, by class name or as objects. They come after the framework's, in the order given, so a provider that listens to events or adds scheduled tasks finds them already bound.

To leave out a framework provider or replace the framework's list, use one of these two forms:

```php
// app.php — choose one
use App\Providers\AppProvider;
use Crab\Schedule\ScheduleProvider;

->withProviders(remove: [ScheduleProvider::class])          // without one of the framework's
->withProviders(only: [AppProvider::class])                 // these in place of the framework's
```

`remove:` accepts only classes in `App::PROVIDERS` and names the list when you give another. `only: []` leaves out all framework providers. `only:` and `remove:` cannot be combined, even across calls. Providers given as the first argument are still added after the replacement list. Calling `withProviders()` again adds providers; another `only:` replaces the previous replacement.

A framework provider named again, by class or as an object, is registered once at its first place. `TokenAuthProvider` and every package, including `crabphp/redis` and `crabphp/clockwerk`, stay opt-in. Nothing is found by convention. `new App()` used directly registers no providers.

See [Services and providers](providers.md).

### Middleware

`withMiddleware()` sets what runs around every request. See [Middleware](middleware.md#around-every-request).

### Routes and commands

`routes:` and `commands:` each take one file or a list of files. A route file declares with `Route::`, a command file with `Command::add()`:

```php
->withRouting(
    routes: ['routes/web.php', 'routes/admin.php'],
    commands: 'routes/console.php',
)
```

### A health check

`health: '/up'` makes `GET /up` answer `200` and `{"status":"up"}`, for a load balancer or a deploy script that needs to know the server takes requests.

It says that this worker answers, and asks nothing of the database. That is deliberate: a slow database should not take every server out of rotation at once. To check more, leave `health:` out and route the path to an action of your own.

## The application's own settings

| Setting | Default | |
| --- | --- | --- |
| `app.name` | `Crab` | Shown above the command list and when the server starts |
| `app.debug` | `false` | Show a failure's details to the client. Never in production. |
| `app.url` | none | Where the application is reached, such as `https://api.example.com`; the start of every URL `Urls` writes. See [Named routes](routing.md#named-routes) |
| `auth.ticket_ttl` | `30` | Positive whole seconds a single-use browser socket ticket lives; see [Authentication](authentication.md#browser-socket-tickets) |
| `log.path` | `php://stderr` | Where the log is written; see [Logging](logging.md) |
| `log.level` | `debug` | The least severe level written |
| `server.address` | `tcp://127.0.0.1:8080` | Where the server listens |
| `server.workers` | `1` | Worker processes |
| `server.with` | `[]` | The servers `start` runs beside HTTP, each id with whether it is on: `['queue' => env('SERVER_QUEUE', false)]`. A plain list of ids, or ids with commas between, also works |
| `server.watch` | `app`, `app.php`, `config`, `routes`, `.env` | What `dev` watches |
| `server.max_connections` | `512` | Total HTTP and upgraded connections per worker; at most `900` on the select backend |
| `server.loop` | `select` | `select`, `ev`, `uv` or `event`; a native backend needs its PHP extension |
| `server.max_body` | `1048576` | The largest request body taken, in bytes |
| `server.body_timeout` | `30` | Seconds a client has to send a body |
| `server.drain` | `5.0` | Seconds an HTTP worker has to finish in-flight requests on stop, reload or restart; unfinished requests at the limit are cut and counted in one warning per worker. Idle keep-alive connections do not count. Must be a finite number greater than zero |
| `server.pid` | `storage/crab.pid` | The process id of a server in the background |
| `server.log` | `storage/logs/server.log` | What a server in the background prints |

Each component lists its own settings on its page. [Broadcasting](broadcasting.md#configuration) lists `broadcast.carrier` and channel limits; choose Redis when multiple processes send updates. [WebSockets](websockets.md#configuration) lists the socket limits; when socket routes are declared, `websocket.max_connections` must not exceed `server.max_connections`.
