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

Command::add('hello', static function (Input $input, Output $output): int {
    $output->line('Hello, ' . $input->argument(0, 'world') . '!');

    return 0;
}, 'Say hello: php cua hello [name]');
```

```sh
php cua hello Ada
# Hello, Ada!
```

The third argument is the description shown in the command list.

### A class

Use a class when the command needs services. The container makes it when it runs:

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
Command::add('users:prune', PruneUsers::class);
```

The first line of `description()` is shown in the command list, and all of it by `--help`. `handle()` returns the exit status: `0` for success, anything else for failure. An exception is printed and the status is `1`.

A name is lower-case letters, digits, `:`, `_` and `-`. Use `area:action`, as in `users:prune`, with the area in the singular. The framework's own follow it: `route:list`, `schedule:list`, `queue:work`, `cache:clear`. Name a command that lists things `area:list`.

## Arguments and options

What follows the command's name reaches it as the input. Take a command declared as:

```php
Command::add('import', ImportUsers::class);
```

```sh
php cua import users.csv --batch=500 --force -v
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

- An option's value is text; cast it.
- `flag()` is true when the option is present and not `false`, `0` or `no`, so `--force=false` switches it off.
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
| `route:list` | Lists the routes, each with its action and middleware |
| `make:middleware` | Writes a middleware class in `app/Http/` |

Providers add theirs when you register them:

| Provider | Commands |
| --- | --- |
| `DatabaseProvider` | `migrate`, `migrate:rollback`, `migrate:status`, `make:migration` |
| `TokenAuthProvider` | `tokens:install` |
| `CacheProvider` | `cache:clear`, `cache:install` |
| `QueueProvider` | `queue:work`, `queue:failed`, `queue:retry`, `queue:forget`, `queue:install` |
| `ScheduleProvider` | `schedule:work`, `schedule:list` |

## Running a command on a schedule

Do not add a cron entry per command. Declare it on the schedule and keep one `php cua schedule:work` running:

```php
$schedule->command('users:prune', '--days=30')->daily('03:00');
```

See [Task scheduling](scheduling.md).
