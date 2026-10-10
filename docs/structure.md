# Directory structure

- [Introduction](#introduction)
- [The root](#the-root)
- [The app directory](#the-app-directory)
- [Where to put new things](#where-to-put-new-things)

## Introduction

The skeleton is small on purpose: every file in it is one you will open. Nothing is found by convention. A file is loaded because `app.php` names it, so you can always answer "why does this run?" by reading one file.

## The root

```text
app.php             describes the application: providers, middleware, routes
cua                 the command line: php cua <command>

routes/
  web.php           HTTP routes
  console.php       your own commands

app/
  Actions/         actions as classes
  Providers/        where your services are bound

config/
  app.php           name, debug
  database.php      which database, and where the migrations are
  log.php           where and how much to log
  server.php        address, worker count, what to watch, where the pid and log go

database/
  migrations/       changes to the tables, in order

storage/            what the application writes while it runs: logs, the SQLite file
tests/              tests that call the application without a server
.env                settings for this machine
```

`storage/` and `.env` are never committed. Nothing the application needs in order to start lives in `storage/`.

Everything begins in `app.php`:

```php
// app.php
use App\Providers\AppProvider;
use Crab\App;
use Crab\Database\DatabaseProvider;

return App::configure(__DIR__)
    ->withProviders([
        DatabaseProvider::class,
        AppProvider::class,
    ])
    ->withRouting(
        routes: 'routes/web.php',
        commands: 'routes/console.php',
        health: '/up',
    )
    ->create();
```

`__DIR__` is the application's base path. The `.env` file and the `config/` folder are loaded from it, and every other path is relative to it. [Configuration](configuration.md) describes each step of the chain.

## The app directory

`app/` is your code, under the `App\` namespace. It starts with two folders:

| Folder | Holds |
| --- | --- |
| `app/Actions/` | Classes that answer routes. See [Actions](actions.md). |
| `app/Providers/` | `AppProvider`, where you bind your own services. See [Services and providers](providers.md). |

## Where to put new things

The framework does not look for folders, so these are suggestions that keep applications alike. `php cua make:middleware` and `php cua make:migration` write into the folders shown.

| You are adding | Put it in | Then |
| --- | --- | --- |
| A route | `routes/web.php` | Nothing; it is loaded by `app.php` |
| An action class | `app/Actions/` | Name it in the route |
| Middleware | `app/Middleware/` | Name it on a route, a group or in `app.php` |
| A command | `routes/console.php`, or a class in `app/Commands/` | `php cua <name>` |
| A queue job | `app/Jobs/` | Push it with the queue |
| An event and its listeners | `app/Events/`, `app/Listeners/` | Add the listener in `AppProvider` |
| A scheduled task | `app/Providers/AppProvider.php` | Declare it on the schedule |
| A setting | A file in `config/`, read from `.env` | `config('file.key')` |
| A table | `php cua make:migration create_posts_table` | `php cua migrate` |
| A second routes file | `routes/api.php` | Add it to `routes:` in `app.php` |
