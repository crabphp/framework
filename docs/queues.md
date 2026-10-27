# Queues

- [Introduction](#introduction)
- [Installation](#installation)
- [Configuration](#configuration)
- [Writing a job](#writing-a-job)
- [Pushing a job](#pushing-a-job)
    - [With a database write](#with-a-database-write)
    - [Delaying a job](#delaying-a-job)
    - [Named queues](#named-queues)
    - [What a job may carry](#what-a-job-may-carry)
- [Running the worker](#running-the-worker)
    - [Workers and concurrency](#workers-and-concurrency)
    - [Supervisors](#supervisors)
    - [In production](#in-production)
- [When a job fails](#when-a-job-fails)
    - [Tries and backoff](#tries-and-backoff)
    - [Failed jobs](#failed-jobs)
    - [Jobs run at least once](#jobs-run-at-least-once)
    - [A job that takes too long](#a-job-that-takes-too-long)
- [A store of your own](#a-store-of-your-own)
- [In tests](#in-tests)

## Introduction

A queue lets a request hand work to be done later, outside the request. The action pushes a job and answers at once; a separate worker process picks the job up and runs it.

Use it for anything a client should not wait for or that may need a second try: sending email, calling a slow API, resizing an image, building an export. Use it also for anything that takes more than a moment, because an action that computes for seconds holds its worker while other requests wait.

Queue workers are processes of their own, apart from the ones serving HTTP, so a slow job never delays a request.

## Installation

The queue is registered by default by `App::configure()`, so it needs nothing in `app.php`. Jobs are kept in a table of your database by default; configure a connection as described in [Database](database.md#configuration).

```sh
php cua queue:install        # writes the jobs table's migration into your migrations
php cua migrate
```

The migration is yours: it sits with your other migrations, where you can read and change it.

## Configuration

Everything has a default. To change one, add `config/queue.php`:

```php
// config/queue.php
return [
    'tries' => 5,
    'backoff' => [10, 60, 300],
    'retry_after' => 180,
    'workers' => env('QUEUE_WORKERS', 2),
];
```

| Setting | Default | |
| --- | --- | --- |
| `queue.store` | `database` | Where jobs are kept: `database`, `memory` for tests, or `redis` with the `crabphp/redis` package |
| `queue.table` | `jobs` | The table |
| `queue.database` | `null` | The name of the database it is in; the default one when null |
| `queue.queue` | `default` | The queue jobs are pushed to and taken from when none is named |
| `queue.tries` | `3` | How many times a job is started before it is given up on, unless its `#[Retry]` sets tries |
| `queue.backoff` | `[10, 60]` | Seconds to wait before the second try, the third, and so on; the last repeats. A job's `#[Retry]` may set its own |
| `queue.retry_after` | `90` | Seconds a taken job may go without its worker renewing it before another worker is given it; the handler runs again only if tries remain |
| `queue.timeout` | `null` | Seconds one try of a job may run, unless its `#[Timeout]` says otherwise; no limit when null. See [A job that takes too long](#a-job-that-takes-too-long) |
| `queue.workers` | `1` | Worker processes `queue:work` starts |
| `queue.concurrency` | `1` | Jobs one worker runs at a time |
| `queue.sleep` | `1.0` | Seconds a worker waits before looking again when no job was due |
| `queue.grace` | `30.0` | Seconds a worker is given to finish its job when it is told to stop, and a job past its timeout to stop before its process is ended |
| `queue.supervisors` | none | Named sets of workers, each with its own queues and limits; see [Supervisors](#supervisors) |

## Writing a job

A job is a class with `handle()`. Its constructor takes the services it needs; `handle()` takes the job's data as typed parameters:

```php
// app/Actions/SendWelcomeEmail.php
namespace App\Actions;

use Crab\Contracts\Database\Database;

final readonly class SendWelcomeEmail
{
    public function __construct(private Database $db, private Mailer $mailer)
    {
    }

    public function handle(int $userId, string $locale = 'en'): void
    {
        $user = $this->db->table('users')->find($userId);
        if ($user === null) {
            return;                                 // deleted since; nothing to do
        }

        $this->mailer->send($user['email'], 'welcome', $locale);
    }
}
```

This is the same shape as an action: services in the constructor, data in the signature. There is no base class and no interface to implement.

The container keeps autowired and singleton job classes per worker; a transient `bind()` makes one for each try. Like any service, it must hold nothing of one run in a property.

> [!NOTE]
> If you come from Laravel: a job is not an object you construct with its data and dispatch. The data and the services are kept apart, so the data can travel as plain JSON and the class needs no serializing.

## Pushing a job

Ask for the queue, and give the job's data by parameter name:

```php
use App\Actions\SendWelcomeEmail;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Queue\Queue;
use Crab\Http\Response;
use Crab\Validation\Data;
use Crab\Validation\Email;
use Crab\Validation\Fields;

final readonly class RegisterUser
{
    public function __construct(private Database $db, private Queue $queue)
    {
    }

    public function handle(#[Fields(['email' => ['string', new Email()], 'name' => 'string'])] Data $input): Response
    {
        $id = $this->db->table('users')->insert(['email' => $input->email, 'name' => $input->name]);

        $this->queue->push(SendWelcomeEmail::class, userId: $id);

        return Response::created(['id' => $id], "/users/{$id}");
    }
}
```

What you push is checked against the job's `handle()` on that line. A parameter that does not exist, a required one left out, or a value given by position is refused there and then, with a message naming the job, and not minutes later by a worker.

`push()` returns the id the job was stored under.

### With a database write

A job usually follows a write: the order is saved, then its confirmation is pushed. Done as two steps they can come apart. Push inside a transaction that then rolls back, and a job runs for an order that does not exist; push after it, and a crash in between loses the job.

`within()` ties the job to the transaction. Give it the connection the transaction's callback received:

```php
use Crab\Contracts\Database\Connection;

$this->db->transaction(function (Connection $db) use ($user, $total): void {
    $id = $db->table('orders')->insert(['user_id' => $user->id, 'total' => $total]);

    $this->queue->within($db)->on('mail')->push(SendOrderConfirmation::class, orderId: $id);
});
```

| Where jobs are kept | What `within()` does |
| --- | --- |
| The `database` store, in the transaction's database | The job is a row of the same transaction: committed with the order, rolled back with it. Nothing can lose it. |
| Anywhere else: `redis`, `memory`, another database | The job is pushed once the transaction has committed, and not at all when it rolls back. `push()` returns `''`, since the job has no id yet. |

In the second case a process that dies between the commit and the push still loses the job. When that must never happen, keep the jobs in the database the write goes to.

### Delaying a job

`later()` runs a job no sooner than after a delay, in seconds or as a `DateInterval`:

```php
$queue->later(600, SendReminder::class, orderId: $order['id']);                   // in ten minutes
$queue->later(new \DateInterval('P1D'), ExpireTrial::class, userId: $id);         // tomorrow
```

Use `later()` for something that follows from one event. For work at a fixed time of day, use the [schedule](scheduling.md).

### Named queues

`on()` pushes to a queue of another name, so that some work does not wait behind the rest:

```php
$queue->on('emails')->push(SendWelcomeEmail::class, userId: $id);
$queue->on('exports')->push(BuildExport::class, reportId: $reportId);
```

A worker is told which queues it takes from, the most urgent first:

```sh
php cua queue:work --queue=emails,default
php cua queue:work --queue=exports --workers=1
```

A worker empties the first queue before it looks at the second. Run a separate `queue:work` for a queue that must never be starved, as for `exports` above.

### What a job may carry

A job's data is stored as JSON: numbers, text, booleans, null, and arrays of them. Pass the id of a record, not the record:

```php
$queue->push(SendInvoice::class, invoiceId: $invoice['id']);                       // right
$queue->push(SendInvoice::class, invoice: $invoiceObject);                         // refused
```

The job then loads the record when it runs, and so sees it as it is then, not as it was when the job was pushed. One job's data may be up to 64 kB on MySQL.

## Running the worker

```sh
php cua queue:work
```

It runs jobs until you stop it with Ctrl+C.

While you develop, start it in a second terminal when you are working on jobs. To have `php cua start` start the queue's workers itself, beside the HTTP workers, and stop and reload them with the server, switch it on in `server.with`, with `SERVER_QUEUE=true` in `.env`; see [Deployment](deployment.md#the-other-processes).

| Option | |
| --- | --- |
| `--supervisor=mail` | Run one of the [supervisors](#supervisors), not all of them |
| `--queue=emails,default` | The queues to take from, the most urgent first |
| `--workers=4` | Worker processes; the default is `queue.workers` |
| `--concurrency=5` | Jobs one worker runs at a time; the default is `queue.concurrency` |
| `--single` | Run in this process, without the `pcntl` and `posix` extensions |
| `--stop-when-empty` | Run jobs due now, print how many ran, and return when none are due; future delayed jobs and retries remain stored |

> [!WARNING]
> A worker runs the code it started with. When `start` manages it, `php cua reload` replaces it after deployment. A separately started `queue:work` must be stopped and started again.

Value options are checked before consuming jobs. Worker and concurrency counts from configuration, supervisor overrides and command options must be positive whole numbers within PHP's integer range. Leave environment values uncast so invalid text can be refused. A queue list must contain a nonempty name at every comma, and `--supervisor` must name a configured supervisor.

### Workers and concurrency

Two settings decide how many jobs run at once, and they suit different work:

- **`--workers`** is processes. Each runs one job at a time unless told otherwise. Add workers for jobs that compute or use the database, since those hold a process while they run.
- **`--concurrency`** is jobs inside one process. It helps only jobs that spend their time waiting through Crab, such as calls made with the [HTTP client](http-client.md). Ten jobs that each wait two seconds for an API then take two seconds together, in one process.

When in doubt, raise `--workers` and leave concurrency at 1.

### Supervisors

One set of workers is enough while every job is alike. When they are not, a flood of one kind holds up the rest: ten thousand newsletter emails ahead of one password reset. A supervisor is a named set of workers with its own queues and its own limits, and `queue:work` runs all of them at once, each as a group of processes of its own:

```php
// config/queue.php
return [
    'supervisors' => [
        'mail' => ['queues' => ['mail'], 'workers' => 2, 'tries' => 5, 'backoff' => [30, 300]],
        'default' => ['queues' => ['high', 'default'], 'workers' => 4],
        'webhooks' => ['queues' => ['webhooks'], 'workers' => 1, 'concurrency' => 20],
    ],
];
```

```text
$ php cua queue:work
mail: running jobs from mail with 2 workers
default: running jobs from high, default with 4 workers
webhooks: running jobs from webhooks
```

| Key | |
| --- | --- |
| `queues` | The queues it takes from, the most urgent first; defaults to `[queue.queue]`. An empty list is refused |
| `workers` | Worker processes |
| `concurrency` | Jobs one worker runs at a time |
| `tries`, `backoff`, `retry_after`, `timeout` | As the settings of the same name, for this supervisor's jobs; a job's `#[Retry]` overrides tries and backoff, and its `#[Timeout]` the timeout, when given. `'timeout' => null` lifts the queue's limit for this supervisor |
| `sleep` | Seconds a worker waits before looking again when no job was due |

What a supervisor leaves out is taken from the setting of the same name, such as `queue.workers`. A key that is not in this table is refused when `queue:work` starts.

A queue no supervisor names is run by nobody, so list every queue you push to. To run supervisors on different machines, start each with `php cua queue:work --supervisor=mail`. Giving `--queue`, `--workers` or `--concurrency` runs one set of workers as the command line describes, in place of the supervisors.

> [!NOTE]
> The number of workers is fixed while the command runs. Supervisors do not yet grow and shrink with the load.

### In production

On one machine, set `SERVER_QUEUE=true` and run `php cua start --daemon`; `php cua reload` updates HTTP and queue workers together. See [Deployment](deployment.md#a-deploy-start-to-finish).

When queue workers run separately, leave that switch off on the HTTP server and keep `queue:work` running with a process manager. For example, with systemd:

```text
[Service]
ExecStart=/usr/bin/php /srv/app/cua queue:work --workers=4
Restart=always
KillSignal=SIGTERM
TimeoutStopSec=40
```

On SIGTERM each worker finishes the job it has, up to `queue.grace` seconds, and stops. A worker that dies is started again by its parent. Under Docker, run `php cua queue:work` as the command of a container of its own.

## When a job fails

A job fails by throwing. The failure is written to the [log](logging.md) with its trace, and the worker moves on to the next job.

### Tries and backoff

A failed job is tried again after a pause: with the defaults, after 10 seconds, then after 60. `queue.tries` is how many times it is started in all, and `queue.backoff` is the pauses between tries.

```php
// config/queue.php
return [
    'tries' => 5,
    'backoff' => [10, 60, 300],         // then 300 again before the fifth try
];
```

The settings are the defaults for every job. Give a payment webhook more tries, or a push notification only one, with `#[Retry]` on its class:

```php
// app/Actions/ChargeCard.php
namespace App\Actions;

use Crab\Queue\Retry;

#[Retry(tries: 10, backoff: [30, 120, 600])]
final readonly class ChargeCard
{
    public function __construct(private Payments $payments)
    {
    }

    public function handle(int $orderId): void
    {
        $this->payments->charge($orderId);
    }
}
```

The attribute wins over the consumer's or supervisor's settings. Leave either value null to keep its default: `#[Retry(tries: 1)]` keeps the default backoff, and `#[Retry(backoff: [5])]` keeps the default tries. The last pause repeats; `backoff: []` retries without a pause.

Pending jobs use the declaration in the worker's current class; it is not saved with the job's data. Change the declaration and restart workers to apply it to pending jobs. If you lower tries from 5 to 2 after a job has already been taken four times, the worker keeps it as failed without running its handler again.

Use one `#[Retry]` declaration. Set tries to an integer of at least 1 and backoff to a list of whole numbers greater than or equal to 0. Give at least one of them. Wrong argument types, repeated attributes and invalid values all throw `InvalidArgumentException` naming the job when you push it. Jobs are still delivered at least once, so make repeating their work safe.

If an already stored job has an invalid retry declaration, the worker keeps it as failed immediately, logs the error, and moves on without running it.

To give up early on a failure that a retry cannot fix, catch it in the job and return:

```php
public function handle(int $userId): void
{
    try {
        $this->mailer->send(/* ... */);
    } catch (InvalidAddress $error) {
        $this->logger->warning('Not sent: {message}', ['message' => $error->getMessage()]);
    }
}
```

### Failed jobs

A job that has used all its tries is kept as failed, with the reason:

```sh
php cua queue:failed             # lists them: id, queue, job, when, why
php cua queue:retry 12           # puts one back, with all its tries
php cua queue:retry all
php cua queue:forget 12          # deletes one
php cua queue:forget all
```

Fix the cause, deploy, then `queue:retry all`.

### Jobs run at least once

A job is delivered at least once, and occasionally more: a worker may be killed after doing the work and before saying so. Write every job so that running it twice does no harm; this example needs a mail service that honors the order's idempotency key, since a sent flag alone cannot prevent duplicate mail. Inject `Crab\Contracts\Clock\Clock` as `$clock` alongside the database and mailer to timestamp the receipt.

```php
public function handle(int $orderId): void
{
    $order = $this->db->table('orders')->find($orderId);
    if ($order === null || $order['receipt_sent_at'] !== null) {
        return;                                                 // already done
    }

    $this->mailer->sendReceipt($order, idempotencyKey: "receipt-{$orderId}");
    $this->db->table('orders')->where('id', $orderId)->update(['receipt_sent_at' => $this->clock->now()->format('Y-m-d H:i:s')]);
}
```

### A job that takes too long

A worker renews the reservation of the job it runs every third of `queue.retry_after`, so a job may run for as long as it needs, and no other worker is given it meanwhile. `retry_after` is how long a job may go unrenewed before its worker is taken to have died. Set it above the longest time a job blocks its process: a loop that computes, or a library that does its I/O without the engine, keeps the renewal from running.

A recovered job's earlier reservation already counts as a try. The worker runs its handler again only while its retry limit permits; otherwise it keeps the job as failed. A job with `#[Retry(tries: 1)]` whose worker dies is therefore failed after recovery without another handler run.

To bound a job, give it a timeout. `queue.timeout` sets one for every job, a supervisor's `timeout` for its own, and `#[Timeout]` for one job class:

```php
use Crab\Engine\Async\Cancellation;
use Crab\Queue\Retry;
use Crab\Queue\Timeout;

#[Timeout(seconds: 1300), Retry(tries: 2)]
final readonly class ImportRom
{
    public function __construct(private RomImporter $imports)
    {
    }

    public function handle(int $userId, int $romId, Cancellation $cancellation): void
    {
        $this->imports->import($userId, $romId, $cancellation);
    }
}
```

When the time is up, the `Cancellation` its `handle()` takes is cancelled. Any parameter typed `Cancellation` gets it, whatever its name; it is the worker's to give, so it is not pushed with the data. Pass it to whatever waits: an `Async::sleep()`, an HTTP call, a database query. The wait throws, and the try counts as failed with a `Crab\Contracts\Queue\TimedOut` error, tried again while tries remain. A long loop can check it with `$cancellation->throwIfCancelled()`. The same cancellation is also cancelled if the job's reservation is lost after all, so a job that was given to another worker stops and does not run beside it.

A job that ignores the cancellation, or blocks its process, cannot be stopped from inside the process. So when a timed job is still running `queue.grace` seconds after its timeout, the worker's parent process ends the worker and starts a new one. The job is then recovered after `retry_after`, as if its worker had died. Every other job that worker was running ends with it, so run jobs with a timeout under a supervisor of their own, with `concurrency` 1. A worker run with `--single` or `--stop-when-empty` has no parent to end it, and only the cancellation applies.

> [!WARNING]
> A job that runs out of time may already have done some of its work: downloaded a file, stored half an import. Its next try must be able to resume or redo that safely; see [Jobs run at least once](#jobs-run-at-least-once).

## A store of your own

For Redis, which suits more workers than a database table hands jobs to comfortably, install the `crabphp/redis` package, add its `RedisProvider` in `app.php` and set `queue.store` to `redis`. To keep jobs somewhere else again, implement `Crab\Contracts\Queue\Store` and bind it after the queue provider:

```php
use Crab\Contracts\Queue\Store;

$container->singleton(Store::class, static fn (): Store => new SqsStore(/* ... */));
```

A store keeps each job until a worker says it is done, and gives a job to one worker at a time. It renews a job for the worker that still holds it, on the try that worker was given, and ignores what a worker says of a job it no longer holds, except that a job done is done. A job travels as a `Crab\Contracts\Queue\Message`: its id, queue, class, data and attempts.

## In tests

Set the store to `memory`, run the code, and look at what was pushed:

```php
use Crab\Contracts\Clock\Clock;
use Crab\Contracts\Queue\Store;
use Crab\Http\Request;

$app = require dirname(__DIR__) . '/app.php';          // with 'queue' => ['store' => 'memory'] in the test config

$app->handle(new Request('POST', '/users', ['Content-Type' => 'application/json'], '{"email":"ada@example.com","name":"Ada"}'));

$clock = $app->container->get(Clock::class);
$waiting = $app->container->get(Store::class)->jobs('default', 'pending', $clock->now()->getTimestamp());
self::assertSame(SendWelcomeEmail::class, $waiting[0]->job);
self::assertSame(['userId' => 1], $waiting[0]->data);
```

A job is an ordinary class, so test its work by calling it:

```php
(new SendWelcomeEmail($db, $mailer))->handle(userId: 1);

self::assertCount(1, $mailer->sent);
```

See [Testing](testing.md).
