# Queues

- [Introduction](#introduction)
- [Installation](#installation)
- [Configuration](#configuration)
- [Writing a job](#writing-a-job)
- [Pushing a job](#pushing-a-job)
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

Jobs are kept in a table of your database. Register both providers:

```php
// app.php
use Crab\Database\DatabaseProvider;
use Crab\Queue\QueueProvider;

->withProviders([
    DatabaseProvider::class,
    QueueProvider::class,
    AppProvider::class,
])
```

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
    'workers' => (int) env('QUEUE_WORKERS', 2),
];
```

| Setting | Default | |
| --- | --- | --- |
| `queue.store` | `database` | Where jobs are kept: `database`, `memory` for tests, or `redis` with the `crabphp/redis` package |
| `queue.table` | `jobs` | The table |
| `queue.database` | `null` | The name of the database it is in; the default one when null |
| `queue.queue` | `default` | The queue jobs are pushed to and taken from when none is named |
| `queue.tries` | `3` | How many times a job is started before it is given up on |
| `queue.backoff` | `[10, 60]` | Seconds to wait before the second try, the third, and so on; the last is used for every later one |
| `queue.retry_after` | `90` | Seconds after which a job that was taken and never finished is given to another worker |
| `queue.workers` | `1` | Worker processes `queue:work` starts |
| `queue.concurrency` | `1` | Jobs one worker runs at a time |
| `queue.sleep` | `1.0` | Seconds a worker waits before looking again when no job was due |
| `queue.grace` | `30.0` | Seconds a worker is given to finish its job when it is told to stop |
| `queue.supervisors` | none | Named sets of workers, each with its own queues and limits; see [Supervisors](#supervisors) |

## Writing a job

A job is a class with `handle()`. Its constructor takes the services it needs; `handle()` takes the job's data as typed parameters:

```php
// app/Jobs/SendWelcomeEmail.php
namespace App\Jobs;

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

The container makes a job class once per worker and keeps it. Like any service, it must hold nothing of one run in a property.

> [!NOTE]
> If you come from Laravel: a job is not an object you construct with its data and dispatch. The data and the services are kept apart, so the data can travel as plain JSON and the class needs no serializing.

## Pushing a job

Ask for the queue, and give the job's data by parameter name:

```php
use App\Jobs\SendWelcomeEmail;
use Crab\Contracts\Queue\Queue;

final readonly class RegisterUser
{
    public function __construct(private Database $db, private Queue $queue)
    {
    }

    public function handle(#[Email] string $email, string $name): Response
    {
        $id = $this->db->table('users')->insert(['email' => $email, 'name' => $name]);

        $this->queue->push(SendWelcomeEmail::class, userId: $id);

        return Response::created(['id' => $id], "/users/{$id}");
    }
}
```

What you push is checked against the job's `handle()` on that line. A parameter that does not exist, a required one left out, or a value given by position is refused there and then, with a message naming the job, and not minutes later by a worker.

`push()` returns the id the job was stored under.

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

It runs jobs until you stop it with Ctrl+C. While you develop, keep it running in a second terminal beside `php cua serve`.

| Option | |
| --- | --- |
| `--supervisor=mail` | Run one of the [supervisors](#supervisors), not all of them |
| `--queue=emails,default` | The queues to take from, the most urgent first |
| `--workers=4` | Worker processes; the default is `queue.workers` |
| `--concurrency=5` | Jobs one worker runs at a time; the default is `queue.concurrency` |
| `--single` | Run in this process, without the `pcntl` and `posix` extensions |
| `--stop-when-empty` | Run what is waiting, print how many jobs ran, and return |

> [!WARNING]
> A worker runs the code it started with. After a deploy, stop `queue:work` and start it again, or it goes on running the old jobs.

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
| `queues` | The queues it takes from, the most urgent first. Required |
| `workers` | Worker processes |
| `concurrency` | Jobs one worker runs at a time |
| `tries`, `backoff`, `retry_after` | As the settings of the same name, for this supervisor's jobs |
| `sleep` | Seconds a worker waits before looking again when no job was due |

What a supervisor leaves out is taken from the setting of the same name, such as `queue.workers`. A key that is not in this table is refused when `queue:work` starts.

A queue no supervisor names is run by nobody, so list every queue you push to. To run supervisors on different machines, start each with `php cua queue:work --supervisor=mail`. Giving `--queue`, `--workers` or `--concurrency` runs one set of workers as the command line describes, in place of the supervisors.

> [!NOTE]
> The number of workers is fixed while the command runs. Supervisors do not yet grow and shrink with the load.

### In production

Keep `queue:work` running with whatever keeps your other processes running. With systemd:

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

The settings hold for every job. To give up early on a failure that a retry cannot fix, catch it in the job and return:

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

A job is delivered at least once, and occasionally more: a worker may be killed after doing the work and before saying so. Write every job so that running it twice does no harm.

```php
public function handle(int $orderId): void
{
    $order = $this->db->table('orders')->find($orderId);
    if ($order === null || $order['receipt_sent_at'] !== null) {
        return;                                                 // already done
    }

    $this->mailer->sendReceipt($order);
    $this->db->table('orders')->where('id', $orderId)->update(['receipt_sent_at' => date('Y-m-d H:i:s')]);
}
```

### A job that takes too long

A job that was taken and has neither finished nor failed after `queue.retry_after` seconds is taken to have died with its worker, and is given to another.

> [!WARNING]
> Set `queue.retry_after` above the time your longest job takes. A job still running when it is reached is started a second time, beside the first.

A job cannot be interrupted from outside. Give slow calls a timeout of their own, as `$http->timeout(10)` does, so that a job ends by throwing.

## A store of your own

For Redis, which suits more workers than a database table hands jobs to comfortably, install the `crabphp/redis` package, register its `RedisProvider` after `QueueProvider` and set `queue.store` to `redis`. To keep jobs somewhere else again, implement `Crab\Contracts\Queue\Store` and bind it after the queue provider:

```php
use Crab\Contracts\Queue\Store;

$container->singleton(Store::class, static fn (): Store => new SqsStore(/* ... */));
```

A store keeps each job until a worker says it is done, and gives a job to one worker at a time. A job travels as a `Crab\Contracts\Queue\Message`: its id, queue, class, data and attempts.

## In tests

Set the store to `memory`, run the code, and look at what was pushed:

```php
use Crab\Contracts\Queue\Store;

$app = require dirname(__DIR__) . '/app.php';          // with 'queue' => ['store' => 'memory'] in the test config

$app->handle(new Request('POST', '/users', ['Content-Type' => 'application/json'], '{"email":"ada@example.com","name":"Ada"}'));

$waiting = $app->container->get(Store::class)->waiting();
self::assertSame(SendWelcomeEmail::class, $waiting[0]->job);
self::assertSame(['userId' => 1], $waiting[0]->data);
```

A job is an ordinary class, so test its work by calling it:

```php
(new SendWelcomeEmail($db, $mailer))->handle(userId: 1);

self::assertCount(1, $mailer->sent);
```

See [Testing](testing.md).
