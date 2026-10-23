# Services and providers

- [Introduction](#introduction)
- [The container](#the-container)
    - [Asking for a service](#asking-for-a-service)
    - [Binding a service](#binding-a-service)
    - [Binding an interface](#binding-an-interface)
- [Writing a provider](#writing-a-provider)
    - [Settings with defaults](#settings-with-defaults)
    - [Reaching the application from a provider](#reaching-the-application-from-a-provider)
- [Work in each worker](#work-in-each-worker)
- [Worker processes of your own](#worker-processes-of-your-own)
- [Rules for a service](#rules-for-a-service)

## Introduction

A service is an object your application shares: a mailer, a payment client, a repository. The container makes services and hands them to the classes that ask for them. A provider is where you tell the container how a service is made.

`App::configure()` includes BroadcastProvider alongside the database, auth, cache, events, queue, schedule and HTTP client providers. It binds `Crab\Contracts\Broadcast\Broadcaster` and opens no resources until first use; see [Broadcasting](broadcasting.md).

You rarely call the container yourself. You declare what a class needs in its constructor, and the container supplies it.

## The container

### Asking for a service

Type-hint it in a constructor. This works in actions, middleware, commands, jobs, listeners and other services, because the container makes them all:

```php
use Crab\Contracts\Database\Database;

final readonly class SendInvoice
{
    public function __construct(private Database $db, private Mailer $mailer)
    {
    }
}
```

A class with no binding is made from its constructor's types, so most classes need no registration at all.

### Binding a service

Bind when a class needs something the container cannot work out, such as a value from configuration:

```php
// app/Providers/AppProvider.php
namespace App\Providers;

use Crab\Contracts\Container\Container;
use Crab\Contracts\Provider;

final class AppProvider implements Provider
{
    public function register(Container $container): void
    {
        $container->singleton(SmtpMailer::class, static fn (): SmtpMailer => new SmtpMailer(
            config('mail.host'),
            config('mail.port'),
        ));
    }
}
```

| Method | Gives | Use for |
| --- | --- | --- |
| `singleton()` | One object per worker, made on first use | Almost everything |
| `bind()` | A new object on every request for it | An object that carries the state of one piece of work |
| `instance()` | An object that already exists | A value made while the application is defined |

The closure runs later, inside a worker, the first time the service is asked for. So `register()` only declares; it opens no connection and reads no file.

### Binding an interface

Depend on an interface, and say once which class stands behind it:

```php
$container->singleton(Mailer::class, SmtpMailer::class);
```

An action that asks for `Mailer` now receives the `SmtpMailer`, and a test can bind a fake under the same name.

## Writing a provider

A provider is a class with one method, `register()`. Add it to `app.php`:

```php
// app.php
use App\Providers\AppProvider;

->withProviders([
    AppProvider::class,
])
```

`App::configure()` starts with the framework's providers, `App::PROVIDERS`, in this order: `DatabaseProvider`, `AuthProvider`, `CacheProvider`, `EventsProvider`, `QueueProvider`, `ScheduleProvider`, `HttpClientProvider`, `BroadcastProvider`. Your own providers and those of the packages you use come after them, in the order given. There is no `boot()` step: because bindings are closures that run on first use, by then every provider has registered, and order rarely matters. It matters only when a provider adds to something another made in `register()`, such as scheduled tasks or event listeners; put the provider that owns the list first.

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

Start with the one `AppProvider`. Split it when it grows, by area: a `BillingProvider`, a `MailProvider`.

### Settings with defaults

A provider that ships in a package declares its settings and their defaults once. The application's own configuration wins, and `config('mailer.port')` then reads the same value everywhere:

```php
use Crab\Contracts\Config\Config;

$container->get(Config::class)->defaults('mailer', [
    'host' => '127.0.0.1',
    'port' => 25,
]);
```

### Reaching the application from a provider

A provider does everything through contracts in the container, so a package never names a framework class:

| To | Ask for | And call |
| --- | --- | --- |
| Add routes | `Crab\Contracts\Routing\Router` | `get()`, `post()`, `prefix()`, `middleware()` |
| Add commands | `Crab\Contracts\Console\Console` | `add()` |
| Add middleware around every request | `Crab\Contracts\Http\MiddlewareStack` | `add()` |
| Listen for events | `Crab\Contracts\Events\Dispatcher` | `listen()` |
| Schedule tasks | `Crab\Contracts\Schedule\Schedule` | `call()`, `command()`, `job()` |
| Add a folder of migrations | `Crab\Contracts\Database\Migrator` | `path()` |

```php
use Crab\Contracts\Console\Console;
use Crab\Contracts\Routing\Router;

public function register(Container $container): void
{
    $container->get(Router::class)->get('/billing/status', ShowBillingStatus::class);
    $container->get(Console::class)->add('billing:sync', SyncBilling::class);
}
```

## Work in each worker

A provider that must open something in every worker, such as a connection kept for the worker's life or a timer, implements `WorkerProvider` as well:

```php
use Crab\Contracts\Container\Container;
use Crab\Contracts\WorkerProvider;
use Crab\Engine\Process\Worker;

final readonly class MetricsProvider implements WorkerProvider
{
    public function __construct(private float $interval = 10.0)
    {
    }

    public function register(Container $container): void
    {
        $container->singleton(Metrics::class);
    }

    public function start(Worker $worker, Container $container): void
    {
        $metrics = $container->get(Metrics::class);
        $worker->loop->repeat($this->interval, static fn () => $metrics->flush());
    }

    public function stop(Worker $worker, Container $container): void
    {
        $container->get(Metrics::class)->flush();
    }
}
```

This provider flushes every ten seconds by default; its constructor accepts a shorter interval for a [test](testing.md#code-that-waits). `start()` runs in each worker after it is forked and before it takes requests; throwing there stops the server from starting. `stop()` runs after the worker has finished its requests, in reverse order of registration, and must not wait on anything. What a timer throws is logged and the worker keeps running.

> [!WARNING]
> Never open a connection in `register()` or in a constructor. It would be opened once in the parent process and then shared by every forked worker, which corrupts it. Open on first use, or in `start()`.

## Worker processes of your own

The queue and the scheduler run in processes apart from the ones serving HTTP. A package that needs the same, such as a consumer of another message broker, asks for `Crab\Contracts\Runner` in a command:

```php
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Runner;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Process\Worker;

final readonly class ConsumeEvents implements Command
{
    public function __construct(private Runner $runner, private Broker $broker)
    {
    }

    public function description(): string
    {
        return 'Take events from the broker until stopped';
    }

    public function handle(Input $input, Output $output): int
    {
        $broker = $this->broker;
        $this->runner->run('broker', static function (Worker $worker, Cancellation $cancellation) use ($broker): void {
            while (!$cancellation->isCancelled()) {
                $broker->handleNext();
                Async::sleep($worker->loop, 0.1);       // lets the worker hear that it should stop
            }
        }, workers: 2);

        return 0;
    }
}
```

Each worker is started and stopped as an HTTP worker is, with every `WorkerProvider` around it and every failure logged. To run several groups under one parent process, as `queue:work` does for its supervisors, give `runAll()` a list of `Crab\Contracts\Workers`, each a group name, its work and its number of workers. The cancellation fires when the process is told to stop; finish what is in hand and return. The worker only hears it while your code waits through the engine, as `Async::sleep()` does, so a loop that never waits never stops.

### A server of your own beside HTTP

`start` runs HTTP, and beside it the servers that are registered and switched on. The queue registers `queue` and the scheduler `schedule`. Register your own through `Crab\Contracts\Servers`, each under an id:

```php
use Crab\Contracts\Servers;
use Crab\Contracts\Workers;
use Crab\Engine\Server\WebSocketServer;
use Crab\Engine\Crab;

public function register(Container $container): void
{
    $servers = $container->get(Servers::class);

    // One of your commands, run in a process of its own.
    $servers->command('prices', 'price:stream --exchange=nyse');

    // Any program.
    $servers->process('vite', 'npm run dev');

    // Workers of the server's own, here listening for WebSocket connections.
    $servers->add('chat', static fn (): array => [
        new Workers('chat', workers: 2, setup: static function (Crab $group) use ($container): void {
            $group->listen('tcp://0.0.0.0:8081', new WebSocketServer($container->get(ChatRoom::class)));
        }),
    ], runs: 'WebSocket on :8081');
}
```

`price:stream` is an application command. Validate `--exchange` against your configured exchange names before opening its feed; the console passes the value unchanged.

A program is run through the shell, so a pipeline or `a && b` works. When the server stops or reloads, every process the line started is sent `SIGTERM` and given a few seconds to finish, so a program should stay in the foreground and clean up on that signal.

Then switch each on where it should run:

```php
// config/server.php
'with' => [
    'queue' => env('SERVER_QUEUE', false),
    'chat' => env('SERVER_CHAT', true),
    'vite' => env('APP_DEBUG', false),
],
```

| Method | Runs | When the server stops or reloads |
| --- | --- | --- |
| `command($id, 'price:stream --exchange=nyse')` | That command of yours, as if typed after `php cua`, in a process of its own | It is sent SIGTERM, given time to finish, and started afresh |
| `process($id, 'any program')` | The program, through the shell | The same |
| `add($id, fn () => [new Workers(...)])` | Groups of workers inside the server itself | They finish what they have and are replaced, as the HTTP workers are |

What a command or a program prints appears in the server's output with its id in front, `[vite] ready in 300 ms`. One that ends is started again. Registering an id a second time takes the place of the first, so your provider, which comes after the framework's, can say what `queue` runs.

> [!WARNING]
> A server that listens on an address of its own cannot yet be run under `start --daemon`: on `reload` only the HTTP socket is handed from the old server to the new. `start` refuses to start it there and says so. Run `start` in the foreground, kept alive by systemd or a container, where a restart replaces the whole process.

A group given to `add()` has a `setup`, which has it listen on an address of its own, or `work`, a loop that runs until it is told to stop, or both. The closure that returns the groups runs when the server starts, so it may read the configuration and what later providers bound.

## Rules for a service

- **No request state.** One object serves overlapping requests. Pass what belongs to a request as an argument. `final readonly class` prevents property reassignment; also keep request state out of mutable objects those properties hold.
- **Open nothing in the constructor.** Connect on first use, so the object is safe to make anywhere.
- **Take values, not `config()`.** Read configuration in the provider and pass it in, so the class works without the framework.
- **Depend on contracts.** Type-hint `Crab\Contracts\...` and PSR interfaces, never a framework class, so that an implementation can be replaced.

See [Request lifecycle](lifecycle.md) for why.
