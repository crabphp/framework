# Task scheduling

- [Introduction](#introduction)
- [Installation](#installation)
- [Defining the schedule](#defining-the-schedule)
    - [Scheduling a command](#scheduling-a-command)
    - [Scheduling a job](#scheduling-a-job)
    - [Scheduling a class or a closure](#scheduling-a-class-or-a-closure)
- [When a task runs](#when-a-task-runs)
    - [Cron expressions](#cron-expressions)
    - [Every few seconds](#every-few-seconds)
    - [Time zones](#time-zones)
- [Running the scheduler](#running-the-scheduler)
- [Overlapping runs](#overlapping-runs)
- [Several servers](#several-servers)
- [When a task fails](#when-a-task-fails)
- [In tests](#in-tests)

## Introduction

The scheduler runs work by the clock: prune old tokens every night, send a report on the first of the month, refresh a cache every five minutes.

On most frameworks you add a cron entry on the server for this. With Crab you declare the tasks in your code, where they are reviewed and deployed with it, and keep one process running, `php cua schedule:work`. It wakes at the start of each minute and runs what is due. No cron entry is needed, and because the scheduler keeps its own time, a task can also run [every few seconds](#every-few-seconds), which cron cannot do.

## Installation

The schedule is registered by default by `App::configure()`, so it needs nothing in `app.php`; your providers can declare tasks in `register()`.

| Setting | Default | |
| --- | --- | --- |
| `schedule.timezone` | `null` | The zone the tasks' times are in, such as `Europe/Paris`; PHP's own when null |

## Defining the schedule

Tasks are declared in a provider's `register()`, on the schedule from the container:

```php
// app/Providers/AppProvider.php
use Crab\Contracts\Container\Container;
use Crab\Contracts\Schedule\Schedule;

public function register(Container $container): void
{
    $schedule = $container->get(Schedule::class);

    $schedule->command('report:send', '--month=last')->monthly(1, '06:00');
    $schedule->job(PruneTokens::class, olderThanDays: 30)->daily('03:00');
    $schedule->call(RefreshRates::class)->everyMinutes(5);
}
```

```sh
php cua schedule:list
```

shows every task, its cron expression or interval, and when it runs next. Run it after changing the schedule to see that it says what you meant.

`report:send` is an application command: its handler must accept only its documented `--month` forms (here, `last`) before reading or writing reports. `command()` passes these words to the same console handler used by hand.

### Scheduling a command

`command()` runs one of your [console commands](console.md), with its words:

```php
$schedule->command('user:prune', '--days=30')->daily('03:00');
```

An exit status other than `0` is logged as a failure. Use a command for work you also want to run by hand.

### Scheduling a job

`job()` pushes a [job](queues.md) onto the queue, exactly as `Queue::push()` does:

```php
$schedule->job(BuildDailyExport::class, format: 'csv')->daily('01:00');
```

This is the best choice for work that takes a while or may fail: the scheduler only pushes, a queue worker does the work, and the queue retries it. It uses the default queue; configure its store before pushing jobs.

### Scheduling a class or a closure

`call()` runs a class with `handle()`, made by the container with its services, or a closure:

```php
use Crab\Contracts\Cache\Cache;

$schedule->call(RefreshRates::class)->everyMinutes(5);

$schedule->call(static function () use ($container): void {
    $container->get(Cache::class)->delete('rates');
})->hourly();
```

The task runs inside the scheduler's own process. The cache is available by default; keep such tasks short and give long ones to the queue with `job()`.

## When a task runs

A task that has not been told when runs every minute.

| Method | Runs |
| --- | --- |
| `->everySeconds(5)` | [Every five seconds](#every-few-seconds), from when the scheduler starts |
| `->everyMinute()` | Every minute |
| `->everyMinutes(15)` | At :00, :15, :30 and :45 |
| `->hourly()` | At the start of every hour |
| `->hourly(20)` | At 20 past every hour |
| `->daily()` | At midnight |
| `->daily('07:30')` | Every day at 07:30 |
| `->weekly(1, '18:00')` | Mondays at 18:00; 0 is Sunday, 6 is Saturday |
| `->monthly()` | On the 1st at midnight |
| `->monthly(15, '09:30')` | On the 15th at 09:30 |
| `->cron('30 2 * * mon-fri')` | By a cron expression |

Each call replaces what was said before, so give one. A time that cannot be, such as `daily('25:00')`, is refused when the application starts.

`->name('nightly-export')` gives a task the name it is listed and logged under. Without it the name is made from what the task runs.

### Cron expressions

`cron()` takes five fields: minute, hour, day of the month, month, day of the week.

```php
->cron('0 9 * * mon-fri')        // 09:00 on working days
->cron('*/10 8-18 * * *')        // every ten minutes from 08:00 to 18:59
->cron('0 0 1,15 * *')           // midnight on the 1st and the 15th
```

Each field is a number, a list (`1,15`), a range (`1-5`), a step (`*/10`), a three-letter name (`mon`, `jan`) or `*`. As in cron, when both the day of the month and the day of the week are given, a day that fits either one fits.

### Every few seconds

`everySeconds()` runs a task by an interval instead of by the clock. Use it for work that must follow something closely: polling a payment gateway, flushing counters, checking a sensor.

```php
$schedule->call(PollGateway::class)->everySeconds(5);
$schedule->call(FlushMetrics::class)->everySeconds(0.5);     // twice a second
```

The interval may be a part of a second, down to `0.01`. Three things differ from the tasks that run by the minute:

- **It counts from when the scheduler starts**, not from the clock. `everySeconds(30)` started at 12:00:07 runs at 12:00:37, 12:01:07 and so on. For a run at an exact time, use `everyMinute()` or `cron()`.
- **A run that is still going is passed over quietly.** A task set for every second that takes three runs every third second, and no warning is logged, as several a second would fill the log.
- **`schedule:list` shows no next time** for it, as that depends on when the scheduler started.

The task runs inside the scheduler's process, so keep it short. A slow one does not delay the others unless it blocks, as a database query does for the moment it takes.

### Time zones

Times are in PHP's time zone unless `schedule.timezone` says otherwise:

```php
// config/schedule.php
return ['timezone' => 'Europe/Paris'];
```

Where clocks change in spring and autumn, a task set for an hour that is skipped does not run that day, and one set for an hour that repeats may run twice. Schedule outside 01:00 to 03:00, or set the zone to `UTC`.

## Running the scheduler

```sh
php cua schedule:work
```

To have `php cua start` run the scheduler itself, in a worker of its own beside the HTTP workers, switch it on in `server.with`, with `SERVER_SCHEDULE=true` in `.env`; see [Deployment](deployment.md#the-other-processes).

On one machine, set `SERVER_SCHEDULE=true` and run `php cua start --daemon`; `php cua reload` updates the scheduler with the HTTP and queue workers. Keep one scheduler wherever the application is deployed, unless its tasks use `onOneServer()`.

If you run the scheduler separately, leave that switch off on the HTTP server. For example, with systemd:

```text
[Service]
ExecStart=/usr/bin/php /srv/app/cua schedule:work
Restart=always
```

`--single` runs it in one process, without the `pcntl` and `posix` extensions.

> [!WARNING]
> The scheduler runs the code it started with. Use `php cua reload` when `start` manages it; otherwise stop and restart the separate `schedule:work` process after a deploy.

A minute during which the scheduler was not running is not made up for. A task due at 03:00 does not run when the scheduler starts at 03:01. For work that must not be missed, have the task check what is outstanding instead of assuming it ran yesterday.

## Overlapping runs

Each due task runs in its own fiber: waits through the engine let other tasks run, while blocking calls delay them. A task still running when it is next due is skipped; cron overlaps log a warning and interval overlaps are skipped quietly.

So a task set for every minute that takes three minutes runs every third minute. If you see the warning often, the task belongs on the queue.

## Several servers

When more than one machine runs `schedule:work`, each would run every task. Mark the tasks that must run once:

```php
$schedule->job(SendDailyDigest::class)->daily('08:00')->onOneServer();
```

The servers agree through the [cache](cache.md), which must then be one they share: the `database` store, not files. Whichever asks first runs the task. A schedule that uses `onOneServer()` without a cache is refused when `schedule:work` starts.

With a single server, or a single machine running the scheduler, you do not need it.

A task set with `everySeconds()` can be marked too. One server keeps it with a lease lasting `max(2, ceil(3 * interval))` seconds from its last renewal; after expiry, another server takes over on its next interval check.

> [!NOTE]
> For such a task every server asks the cache at every run: twice a second each for `everySeconds(0.5)`. The `database` store answers that with a query each time. If you run tasks this often on several servers, give the cache a faster shared store, such as Redis, with [a store of your own](cache.md#a-store-of-your-own).

## When a task fails

A task that throws is written to the [log](logging.md) with its trace. The scheduler keeps running, and the task runs again when it is next due. Nothing is retried in between; work that needs retrying is a `job()`.

## In tests

A task's work is a command, a job or a class, each of which you test on its own. To test the schedule itself, ask what is due at a given time:

```php
use Crab\Schedule\Schedule;
use Crab\Schedule\Scheduler;

$schedule = new Schedule();
$schedule->call(RefreshRates::class)->daily('07:00')->name('rates');
$scheduler = new Scheduler($schedule);

$due = $scheduler->due(new \DateTimeImmutable('2026-03-10 07:00'));

self::assertSame(['rates'], array_map(fn ($task) => $task->named(), $due));
self::assertSame([], $scheduler->due(new \DateTimeImmutable('2026-03-10 07:01')));
```

`$scheduler->run($task, $time)` runs one task now and returns whether it succeeded.
