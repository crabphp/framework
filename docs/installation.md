# Installation

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Creating an application](#creating-an-application)
- [Your first route](#your-first-route)
- [One file is enough](#one-file-is-enough)
- [Next steps](#next-steps)

## Introduction

Crab is a PHP framework for JSON APIs. It runs your application as a long-lived server: the code is loaded once, and worker processes then answer requests for as long as they live, without PHP-FPM, Nginx or Apache in front.

Reach for it when you are building an API and want it to start fast, answer fast and stay small enough to read. It is not a full-stack framework: there are no views, no sessions and no ORM. What you get is routing, typed actions with validation, middleware, a database layer with a query builder and migrations, authentication by token, a cache, events, a queue, a scheduler and an HTTP client.

> [!NOTE]
> Crab has no release yet. Until it has one, an application reads the framework from a folder beside it, as shown below.

## Requirements

- PHP 8.4 or newer.
- The `mbstring` extension, which comes with most installations of PHP.
- The `pcntl` and `posix` extensions to run more than one worker or a server in the background. Both come with PHP on Linux and macOS. Without them, everything works in a single process with `--single`.
- `pdo_sqlite` or `pdo_mysql` for the database, `openssl` for calling `https` APIs.

## Creating an application

The starting point is the skeleton, `crabphp/crab`. Put it beside the framework and install it:

```sh
git clone <the skeleton> crab
git clone <the framework> framework
cd crab
composer install
cp .env.example .env
php cua serve
```

Open `http://127.0.0.1:8080`. `cua` is the application's command line; `php cua` on its own lists every command.

`serve` restarts the server whenever you save a file under `app/`, `config/` or `routes/`, or `.env`, so there is nothing to restart by hand while you work. See [Deployment](deployment.md) for running it in production.

## Your first route

Routes are declared in `routes/web.php`:

```php
// routes/web.php
use App\Actions\Hello;
use Crab\Route;

Route::get('/', static fn (): string => "Hello from Crab\n");

Route::get('/hello/{name}', Hello::class);
```

An action is a closure or a class. The class takes the route's parameter by name and returns data, which is sent as JSON:

```php
// app/Actions/Hello.php
namespace App\Actions;

final class Hello
{
    /**
     * @return array{hello: string}
     */
    public function handle(string $name): array
    {
        return ['hello' => $name];
    }
}
```

```sh
curl http://127.0.0.1:8080/hello/Ada
# {"hello":"Ada"}
```

## One file is enough

The skeleton is a convenience, not a requirement. A whole application can be one file:

```php
// app.php
use Crab\App;
use Crab\Route;

$app = new App();

Route::get('/', static fn (): string => "Hello, world!\n");
Route::get('/hello/{name}', static fn (string $name): array => ['hello' => $name]);

return $app;
```

```sh
php vendor/bin/crab app.php serve --single
```

`bin/crab` takes the file that returns the application, then the command. Use this for a small service or to try something out; move to the skeleton's layout when the file grows.

## Next steps

- [Directory structure](structure.md) shows where things go.
- [Request lifecycle](lifecycle.md) explains what a long-running server changes about how you write PHP. Read it before writing your first service.
- [Routing](routing.md) and [Actions](actions.md) cover the code you will write most.
