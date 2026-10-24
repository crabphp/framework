# Console

- [Introduction](#introduction)
- [Running commands](#running-commands)
- [Writing a command](#writing-a-command)
    - [A closure](#a-closure)
    - [A class](#a-class)
- [Arguments and options](#arguments-and-options)
- [Output](#output)
- [Built-in commands](#built-in-commands)
- [Running a command on a schedule](#running-a-command-on-a-schedule)

## Introduction

`cua` is your application's command line. It runs the server, the migrations and the queue, and it runs commands of your own: an import, a cleanup, a one-off fix. A command has the whole application behind it, the container and your services included, without a web request.

## Running commands

```sh
php cua                      # lists every command
php cua route:list           # runs one
php cua start --help         # describes one without running it; -h does the same
php cua help start           # so does this
```

## Writing a command

### A closure

For a few lines, declare it in `routes/console.php`:

```php
// routes/console.php
use Crab\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

Command::add('hello:say', static function (Input $input, Output $output): int {
    $output->line('Hello, ' . $input->argument(0, 'world') . '!');

    return 0;
}, 'Say hello: php cua hello:say [name]');
```

```sh
php cua hello:say Ada
# Hello, Ada!
```

The third argument is the description shown in the command list.

### A class

Use a class when the command needs services. `php cua make:command PruneUsers` writes one in `app/Console/` and registers it in `routes/console.php` as `user:prune`; `--command=` names it otherwise. The container makes it when its description is first needed or when it runs, so listing commands or asking for help can construct it:

```php
// app/Console/PruneUsers.php
namespace App\Console;

use Crab\Contracts\Clock\Clock;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Database\Database;

final readonly class PruneUsers implements Command
{
    public function __construct(private Database $db, private Clock $clock)
    {
    }

    public function description(): string
    {
        return <<<'TEXT'
            Delete users who never confirmed their email
            Options:
              --days=30    How old an unconfirmed account must be
              --dry-run    Count them and delete nothing
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $days = $input->option('days', '30');
        if ($days === true) {
            throw new \InvalidArgumentException('--days needs a value; use --days=30.');
        }
        $now = $this->clock->now();
        $maximum = intdiv(PHP_INT_MAX, 86400);
        if (
            preg_match('/^(0|[1-9][0-9]*)$/D', $days) !== 1
            || filter_var($days, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $maximum]]) === false
        ) {
            throw new \InvalidArgumentException('--days must be a nonnegative whole number that fits a timestamp; use --days=30.');
        }
        $seconds = (int) $days * 86400;
        if ($now->getTimestamp() < PHP_INT_MIN + $seconds) {
            throw new \InvalidArgumentException('--days is too large for this cutoff; use --days=30.');
        }
        $before = $now->setTimestamp($now->getTimestamp() - $seconds)->format('Y-m-d H:i:s');
        $query = $this->db->table('users')->whereNull('confirmed_at')->where('created_at', '<', $before);

        if ($input->flag('dry-run')) {
            $output->line($query->count() . ' users would be deleted.');

            return 0;
        }

        $output->line($query->delete() . ' users deleted.');

        return 0;
    }
}
```

```php
// routes/console.php
Command::add('user:prune', PruneUsers::class);
```

The first line of `description()` is shown in the command list, and all of it by `--help`. `handle()` returns the exit status: `0` for success, anything else for failure. An exception is printed and the status is `1`.

A name starts with a lower-case letter or digit and contains only lower-case letters, digits, `:`, `_` and `-`; `list` and `help` are reserved. Use `area:action`, as in `user:prune`, with the area in the singular. The framework's own follow it: `route:list`, `schedule:list`, `queue:work`, `cache:clear`. Name a command that lists things `area:list`.

## Arguments and options

What follows the command's name reaches it as the input. Take a command declared as:

```php
Command::add('user:import', ImportUsers::class);
```

```sh
php cua user:import users.csv --batch=500 --force -v
```

```php
$input->argument(0);                 // "users.csv"
$input->argument(1, 'default');      // "default": there is no second argument
$input->arguments();                 // ["users.csv"]

$input->option('batch');             // "500"
$input->option('batch', '100');      // with a default
$input->flag('force');               // true
$input->flag('v');                   // true: each letter of -abc is a flag
$input->options();                   // ["batch" => "500", "force" => true, "v" => true]
```

Before importing, check the batch size and source file:

```php
$batch = $input->option('batch', '100');
if (
    !is_string($batch) || preg_match('/^[1-9][0-9]*$/D', $batch) !== 1
    || filter_var($batch, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
) {
    throw new \InvalidArgumentException('--batch must be a positive whole number; use --batch=500.');
}
$file = $input->argument(0);
if ($file === null || str_contains($file, "\0") || str_contains($file, '://') || !is_file($file) || !is_readable($file)) {
    throw new \InvalidArgumentException('Give a readable local CSV file: user:import users.csv --batch=500.');
}
$batch = (int) $batch;
```

- An option's value is text; write `--batch=500`, since `--batch 500` makes `batch` a flag and `500` an argument. Check every value-taking option for `true` first and name a missing value before checking other option values or positional arguments. Then validate each entire value before casting or doing any work. Check numeric form and range, configured names, and usable paths/classes. For a positive integer such as `batch`, require decimal digits and `FILTER_VALIDATE_INT` with `['options' => ['min_range' => 1]]`; reject empty, fractional, trailing-text and overflowing values. `user:prune` above also bounds its timestamp arithmetic and uses the injected clock; `--days=0` deliberately includes every older unconfirmed account.
- `flag()` is true when the option is present and not `false`, `0`, `no` or an empty value, so `--force=false` and `--force=` switch it off.
- Everything after `--` is an argument, even when it starts with a dash.
- `--help` and `-h` are taken by the console; do not use `h` for an option of your own.

## Output

```php
$output->line('Done.');                          // a line on standard output
$output->write('Working');                       // without the line break
$output->error('The file does not exist.');      // a line on standard error
$output->table([
    ['Id', 'Email'],
    ['1', 'ada@example.com'],
    ['2', 'grace@example.com'],
]);                                              // columns padded to their widest cell
```

Print results with `line()` and problems with `error()`, so that a script can pipe one and still see the other.

## Built-in commands

| Command | Does |
| --- | --- |
| `dev` | Serves the application while developing, restarting it when a file is saved; see [Deployment](deployment.md) |
| `start` | Serves the application, for production; see [Deployment](deployment.md) |
| `stop`, `reload`, `restart` | Control a server running in the background |
| `status` | Shows the servers that are running, their workers and what each has handled; see [Deployment](deployment.md#what-a-server-is-doing) |
| `route:list` | Lists routes, actions and route and group middleware; global middleware is not shown |
| `make:middleware` | Writes a middleware class in `app/Http/` |
| `make:command` | Writes a command class in `app/Console/` and registers it in `routes/console.php` |
| `config:publish` | Writes a framework section's complete configuration file into `config/` |
| `describe` | Prints the whole application for coding agents, as text or `--json` |

`stop` and the stop phase of `restart` use the running server's remaining allowance, even after its configuration changes. `--timeout` overrides the graceful wait. To cut in-flight requests and end every server process immediately, use:

```sh
php cua stop --force
```

Success means the background server, including registered programs, has ended; a keeper that does not lead its own session is ended alone, with a non-zero result saying its workers may remain and must be ended by whatever started it. Foreground `start` writes no background PID file: stop its terminal job or process manager. See [Deployment](deployment.md#finishing-requests-on-stop) for drain limits.

The default framework providers add their commands automatically; `TokenAuthProvider` adds its command when you register it:

| Provider | Commands |
| --- | --- |
| `DatabaseProvider` | `migrate`, `migrate:rollback`, `migrate:fresh`, `migrate:status`, `make:migration` |
| `TokenAuthProvider` | `token:install` |
| `CacheProvider` | `cache:clear`, `cache:prune`, `cache:install` |
| `QueueProvider` | `queue:work`, `queue:failed`, `queue:retry`, `queue:forget`, `queue:install` |
| `ScheduleProvider` | `schedule:work`, `schedule:list` |

## Describing the application

`php cua describe` prints the application for a coding agent to read instead of its files: its routes with what each takes, the middleware around every request, the commands, the scheduled tasks, the event listeners, the servers `start` can run, the databases with their migration files, and every setting.

```sh
php cua describe                  # everything, as text
php cua describe routes commands  # some sections
php cua describe --json           # the same as JSON
```

The text is one thing to a line, with each route's input in TypeScript notation and its limits by JSON Schema's names. Agents read it as well as JSON or TOML, in fewer tokens:

```text
routes
  POST /orders -> App\Actions\PlaceOrder via Crab\Auth\TokenAuth
    body { lines: Array<{ productId: int; quantity?: int | null (minimum=1, maximum=20, default=1) }> (minItems=1, maxItems=20) }
    user: App\User
  GET /products/{id} -> App\Actions\ShowProduct
    {id}: int
```

A field written `name?:` may be left out, and `| null` may be null. Limits are inclusive and apply to what they follow: `(minItems=1, maxItems=20)` after `Array<...>` is how many lines there may be. A route that takes nothing says `takes nothing`, and one whose action is a PSR-15 handler of its own says `input not declared`. A command shows the first line of its description; `php cua <command> --help` has the rest.

With `--json`, a route's input is JSON Schema: `parameters` for those of its pattern, `body` or `query` for the fields of the request's data, and `user` for the logged-in user, with whether one is required. Rules are given in JSON Schema's words, `#[Range(min: 1)]` as `"minimum": 1` and `#[Email]` as `"format": "email"`; a rule of your own is named under `x-rules`.

Settings whose names end in `password`, `secret`, `token`, `private` or `key` are printed as `(hidden)` when they are set. Any other value is printed as it is, so a secret kept under another name is printed too.

## Running a command on a schedule

Do not add a cron entry per command. Declare it on the schedule and keep one `php cua schedule:work` running:

```php
$schedule->command('user:prune', '--days=30')->daily('03:00');
```

See [Task scheduling](scheduling.md).
