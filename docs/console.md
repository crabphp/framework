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
php cua serve --help         # describes one without running it; -h does the same
php cua help serve           # so does this
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

Use a class when the command needs services. The container makes it when its description is first needed or when it runs, so listing commands or asking for help can construct it:

```php
// app/Console/PruneUsers.php
namespace App\Console;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Database\Database;

final readonly class PruneUsers implements Command
{
    public function __construct(private Database $db)
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
        $before = date('Y-m-d H:i:s', time() - 86400 * (int) $input->option('days', '30'));
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

- An option's value is text; write `--batch=500` and cast it, since `--batch 500` makes `batch` a flag and `500` an argument. The server’s `--address`, `--workers` and `--watch`, and the migration commands’ `--database`, reject a missing `=` value before doing any work. Commands of your own should reject `true` for an option that needs a value.
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
| `serve` | Serves the application; see [Deployment](deployment.md) |
| `stop`, `reload`, `restart`, `status` | Control a server running in the background |
| `route:list` | Lists routes, actions and route and group middleware; global middleware is not shown |
| `make:middleware` | Writes a middleware class in `app/Http/` |

`stop` and the stop phase of `restart` use the running server's remaining allowance, even after its configuration changes. `--timeout` overrides the graceful wait. To cut in-flight requests and end every server process immediately, use:

```sh
php cua stop --force
```

Success means the background server, including registered programs, has ended; a keeper that does not lead its own session is ended alone, with a non-zero result saying its workers may remain and must be ended by whatever started it. Foreground `serve` writes no background PID file: stop its terminal job or process manager. See [Deployment](deployment.md#finishing-requests-on-stop) for drain limits.

The default framework providers add their commands automatically; `TokenAuthProvider` adds its command when you register it:

| Provider | Commands |
| --- | --- |
| `DatabaseProvider` | `migrate`, `migrate:rollback`, `migrate:status`, `make:migration` |
| `TokenAuthProvider` | `token:install` |
| `CacheProvider` | `cache:clear`, `cache:prune`, `cache:install` |
| `QueueProvider` | `queue:work`, `queue:failed`, `queue:retry`, `queue:forget`, `queue:install` |
| `ScheduleProvider` | `schedule:work`, `schedule:list` |

## Running a command on a schedule

Do not add a cron entry per command. Declare it on the schedule and keep one `php cua schedule:work` running:

```php
$schedule->command('user:prune', '--days=30')->daily('03:00');
```

See [Task scheduling](scheduling.md).
