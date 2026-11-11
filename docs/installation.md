# Installation

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Creating an application](#creating-an-application)
- [Developing on Windows](#developing-on-windows)
    - [Installing WSL](#installing-wsl)
    - [Installing PHP and Composer](#installing-php-and-composer)
    - [Creating the application](#creating-the-application)
- [Your first route](#your-first-route)
- [One file is enough](#one-file-is-enough)
- [Next steps](#next-steps)

## Introduction

Crab is a PHP framework focused on JSON APIs and services. It runs your application as a long-lived server: the code is loaded once, and worker processes then answer requests for as long as they live, without PHP-FPM, Nginx or Apache in front.

The framework provides routing, typed actions with validation, middleware, a database layer with a query builder and migrations, token and cookie authentication, a cache, events, a queue, a scheduler, an HTTP client and streaming responses, with durable runs and actors as packages of their own. There is no ORM or session component.

> [!WARNING]
> [Views with Arc](views.md) are experimental, may change without backward compatibility, and must not be used in production. Arc is an optional package, not part of the API-focused core.

> [!NOTE]
> Crab's latest release is v0.7.0, on [Packagist](https://packagist.org/packages/crabphp/framework). Its packages, such as `crabphp/runs` or `crabphp/redis`, are installed the same way, with `composer require`.

## Requirements

- PHP 8.4 or newer.
- The `mbstring` extension, which comes with most installations of PHP.
- For development, the `dom` and `xmlwriter` extensions. The skeleton's tests run on PHPUnit, a development dependency that needs them, and `composer create-project` checks for them even with `--no-dev`. A production install, `composer install --no-dev` from the lock file, does not need them.
- The `pcntl` and `posix` extensions to run more than one worker or a server in the background. Without them, `start --single` runs HTTP alone; run `queue:work --single` and `schedule:work --single` separately when needed.
- `pdo_sqlite` or `pdo_mysql` for the database, `openssl` for calling `https` APIs.
- Linux or macOS. On Windows, work inside WSL 2; see [Developing on Windows](#developing-on-windows).

## Creating an application

The starting point is the skeleton, `crabphp/crab`. Create an application from it with Composer:

```sh
composer create-project crabphp/crab shop
cd shop
php cua dev
```

`create-project` installs the framework and writes `.env` from `.env.example`. To add Crab to a project of your own instead, require it with `composer require crabphp/framework`.

Open `http://127.0.0.1:8080`. `cua` is the application's command line; `php cua` on its own lists every command.

`dev` restarts the server whenever you save a file under `app/`, `config/` or `routes/`, or `.env`, so there is nothing to restart by hand while you work. In production the server is run with `start`; see [Deployment](deployment.md).

## Developing on Windows

Develop on Windows inside WSL 2, the Windows Subsystem for Linux: a real Linux system that runs beside Windows. Crab's server is built on Unix processes and signals, through PHP's `pcntl` and `posix` extensions, which PHP for Windows does not have. So `php cua dev` and `php cua start` do not run in PowerShell or the Command Prompt. Inside WSL, Crab runs as it does on Linux, with every command.

WSL 2 needs Windows 10 version 2004 (build 19041) or later, or Windows 11.

### Installing WSL

Open PowerShell as administrator (right-click it and choose "Run as administrator"), run this, then restart Windows:

```sh
wsl --install
```

It installs Ubuntu. Open "Ubuntu" from the Start menu; the first time, it asks you to choose a Linux user name and password. Every command from here on is typed in that Ubuntu terminal, not in PowerShell.

### Installing PHP and Composer

Ubuntu's own packages carry an older PHP, so install PHP 8.4 from the `ondrej/php` repository:

```sh
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.4-cli php8.4-mbstring php8.4-xml php8.4-sqlite3 unzip
```

| Package | What it is for |
| --- | --- |
| `php8.4-cli` | PHP itself, with `pcntl` and `posix` |
| `php8.4-mbstring` | `mbstring`, which Crab requires |
| `php8.4-xml` | `dom` and `xmlwriter`, which PHPUnit, the skeleton's test runner, requires; without them `create-project` stops |
| `php8.4-sqlite3` | `pdo_sqlite`, for the skeleton's default SQLite database |
| `unzip` | Lets Composer unpack downloads |

Add `php8.4-mysql` when the application uses MySQL. Check the result with `php -v`, which should print `PHP 8.4`.

Install Composer with the commands on [getcomposer.org/download](https://getcomposer.org/download/), then make it a command you can run from any folder:

```sh
sudo mv composer.phar /usr/local/bin/composer
```

### Creating the application

Create the application in your Linux home folder and start it:

```sh
cd ~
composer create-project crabphp/crab shop
cd shop
php cua dev
```

Open `http://localhost:8080` in your browser on Windows; WSL passes the port through. Stop the server with Ctrl+C.

Edit the code with an editor that works inside WSL. With VS Code and its WSL extension, run `code .` in the application's folder; the editor opens on Windows and works on the files in Linux.

> [!WARNING]
> Keep the application in the Linux home folder, such as `~/shop`, not on the Windows drive under `/mnt/c`. Microsoft [recommends against it](https://learn.microsoft.com/en-us/windows/wsl/filesystems#file-storage-and-performance-across-file-systems): reading files across the two systems is much slower, and `dev`, which looks at the watched files again and again to notice a change, slows with it.

## Your first route

Routes are declared in `routes/web.php`:

```php
// routes/web.php
use App\Actions\SayHello;
use Crab\Route;

Route::get('/', static fn (): string => "Hello from Crab\n");

Route::get('/hello/{name}', SayHello::class);
```

An action is a closure or a class. The class takes the route's parameter by name and returns data, which is sent as JSON:

```php
// app/Actions/SayHello.php
namespace App\Actions;

final class SayHello
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
php vendor/bin/crab app.php start --single
```

`bin/crab` takes the file that returns the application, then the command. Use this for a small service or to try something out; move to the skeleton's layout when the file grows.

## Next steps

- [Directory structure](structure.md) shows where things go.
- [Request lifecycle](lifecycle.md) explains what a long-running server changes about how you write PHP. Read it before writing your first service.
- [Routing](routing.md) and [Actions](actions.md) cover the code you will write most.
