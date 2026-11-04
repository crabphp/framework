# Runs

- [Introduction](#introduction)
- [Installation](#installation)
- [Writing a run](#writing-a-run)
    - [Steps](#steps)
    - [Sleeping](#sleeping)
    - [Waiting for a signal](#waiting-for-a-signal)
- [Starting a run](#starting-a-run)
    - [With a database write](#with-a-database-write)
- [How a run goes on](#how-a-run-goes-on)
    - [The rules a run follows](#the-rules-a-run-follows)
- [When a run fails](#when-a-run-fails)
- [Looking at runs](#looking-at-runs)
- [Configuration](#configuration)
- [In tests](#in-tests)

## Introduction

A run is work of many steps that goes on from where it was. A crash, a deploy or an error in the middle does not start it over: the steps already done are kept, and the run continues from the first one that is not. Between two steps it may wait minutes or days, for a time or for a signal, without holding a worker.

Use a run for work that is too long or too fragile for one job: an AI agent that plans, searches and writes a report, an onboarding that sends a mail today and another in three days, an order that waits for a person to approve it.

```php
final readonly class ResearchTopic
{
    public function __construct(private Llm $llm, private Search $search)
    {
    }

    public function handle(Run $run, string $topic): string
    {
        $questions = $run->step('plan', fn () => $this->llm->plan($topic));

        $answers = [];
        foreach ($questions as $i => $question) {
            $answers[] = $run->step("search.{$i}", fn () => $this->search->find($question));
        }

        $run->sleep('cool-down', 300);              // five minutes, holding no worker
        $approved = $run->await('approval');        // until someone approves

        return $run->step('report', fn () => $this->llm->report($answers, $approved));
    }
}
```

If the search for the third question fails, the run is tried again after a pause, and the plan and the first two searches are not done again. If the server is deployed during the five minutes, the run wakes up on the new code when they are over.

Runs are kept in two tables of your database and carried out by [queue](queues.md) workers, so they need nothing to be running that the queue does not.

## Installation

Runs are an optional plugin that needs the queue's. Add both in `app.php`, the queue first:

```php
// app.php
use Crab\Queue\QueuePlugin;
use Crab\Runs\RunsPlugin;

->withPlugins([
    QueuePlugin::class,
    RunsPlugin::class,
])
->withSetup(Setup::class)
```

Then write the migration for their tables and run it:

```sh
php cua runs:install         # writes the runs and run_steps tables' migration into your migrations
php cua migrate
```

A queue worker carries runs out: the queue's workers `php cua start` runs with `QueuePlugin` listed, or `php cua queue:work`. See [Running the worker](queues.md#running-the-worker).

## Writing a run

A run is a class with `handle()`, as a job is: services in its constructor, its data as typed parameters of `handle()`. It also takes a `Crab\Contracts\Runs\Run`, which is how it keeps what it does:

```php
use Crab\Contracts\Runs\Run;

final readonly class OnboardUser
{
    public function __construct(private Database $db, private Mailer $mailer)
    {
    }

    public function handle(Run $run, int $userId): void
    {
        $email = $run->step('email', fn () => $this->db->table('users')->where('id', $userId)->value('email'));

        $run->step('welcome', fn () => $this->mailer->send($email, 'welcome'));
        $run->sleep('three-days', 3 * 86400);
        $run->step('tips', fn () => $this->mailer->send($email, 'tips'));
    }
}
```

What `handle()` returns is the run's result, kept with it. A `Cancellation` parameter, as a [job's](queues.md#a-job-that-takes-too-long), is given by the worker.

### Steps

`$run->step($name, $work)` runs `$work` once for the run and keeps what it returns. Each time the run is resumed after that, the step returns what was kept, without running `$work`.

- **A step's name is unique within its run.** In a loop, put the index in it: `"search.{$i}"`. A name used twice in one try fails the run with a message that says so.
- **What it returns is kept as JSON,** and handed back as JSON reads it, the first time too: numbers, text, booleans, null and arrays of them. An object comes back as an array of its public properties, so return a date as text, `->format(DATE_ATOM)`, and a record's id rather than the record.
- **A step that throws is not kept,** and runs again on the run's next try.
- **A step runs at least once.** A step that finished just before its worker died is run again, as a job would be. Make what it does safe to repeat, as for a [job](queues.md#jobs-run-at-least-once).

### Sleeping

`$run->sleep($name, $seconds)` puts the run aside until the time has passed. No worker is held meanwhile: the run is stored as sleeping, and a job to resume it is pushed for when the time comes. A sleep's end is kept, so a run resumed for another reason does not sleep again from the start.

### Waiting for a signal

`$run->await($name)` puts the run aside until something gives it a value under that name, and returns that value:

```php
$decision = $run->await('approval');
if ($decision['approved'] !== true) {
    return 'rejected';
}
```

Give it from anywhere, with the run's id, such as an action:

```php
final readonly class ApproveResearch
{
    public function __construct(private Runs $runs)
    {
    }

    public function handle(int $id, User $user, #[Fields(['approved' => 'bool'])] Data $input): array
    {
        $kept = $this->runs->signal($id, 'approval', ['approved' => $input->approved, 'by' => $user->id]);

        return ['kept' => $kept];
    }
}
```

The first signal under a name is the one kept; `signal()` returns false for a later one, for a run that has ended and for one that does not exist. Sending one again is safe: if the run's waking did not reach the queue, as when the queue was down, the run is still waiting, and a repeated signal wakes it. A signal may come before the run reaches its `await()`: it is kept for it, and the run does not wait.

## Starting a run

```php
use Crab\Contracts\Runs\Runs;

$id = $runs->start(ResearchTopic::class, topic: 'tides');
```

The data is given by the names of `handle()`'s parameters, and checked against them on that line, as a pushed job's is: a parameter that does not exist, a required one left out, or a value that does not come back the same from JSON is refused there and then. `start()` returns the run's id.

### With a database write

`within()` starts the run with a write made in a transaction: the run exists when the write is committed, and not at all when it is rolled back.

```php
$this->db->transaction(function (Connection $db) use ($user): void {
    $db->table('users')->where('id', $user->id)->update(['plan' => 'pro']);
    $this->runs->within($db)->start(OnboardUser::class, userId: $user->id);
});
```

Keep the runs in the database the write goes to, as by default, for this to hold.

## How a run goes on

A run is resumed by running its `handle()` again from the top. Each step already done returns what it returned before, a sleep that has ended returns at once, and a signal already given is returned, so the run goes quickly through what it did before and on from where it was. It is resumed:

- when it is started;
- after a try that failed, once its backoff is over;
- when a sleep ends;
- when a signal it waits for is given;
- after the worker running it died, once its lease runs out.

One worker runs a run at a time. It holds a lease on it, renewed every third of `runs.lease` while it runs. A resumption that finds the run held by another worker does nothing, and looks again when the lease would have run out, so a run whose worker died is taken up again. Each claim carries a token of its own: a worker that stalled past its lease and was overtaken can no longer renew the run, keep a step, or say how it ended, and what it came to is logged as a warning and dropped.

### A run whose job was lost

Each of those resumptions is a job on the queue. A run's state is kept first and its job pushed after, so a process that dies between the two, or a queue that loses the job, leaves the run without one: pending, or sleeping past its end, with nothing to carry it on. `runs:recover` gives each such run a job again, once its time came more than `runs.lease` seconds ago. A run still marked running whose lease ran out more than a lease ago, with no job taking it up, had its worker die: it counts that as a failed try, and the run is tried again after its backoff, or failed when no try is left. Schedule it:

```php
$schedule->command('runs:recover')->everyMinute()->onOneServer();
```

A job that is only late, behind others in a busy queue, may be pushed again this way, at most once a lease; the run is still run once, by whichever job claims it first, and never before its time: a job that comes during a backoff or a sleep waits for its end. A run waiting for a signal is woken by giving the signal again, and a failed one by retrying it. `$runs->recover()` does the same from code and says how many runs it gave a job.

### The rules a run follows

Because a run is run again from the top, the code between its steps must come out the same every time:

- **Put anything slow, random or outside the process in a step:** a query, an API call, the time, a random number. Code between steps only arranges what steps return.
- **Take the same steps in the same order** for the same data and the same step results. A run whose code changes between two of its tries may find a name kept as another kind; it fails with a message that says so. Change a long-lived run's code only by adding steps after the ones it has done.
- **Do not catch every `Throwable` around a sleep or an await.** They end a try by throwing an exception of their own, which must reach the worker.

## When a run fails

A try fails when `handle()` throws. The run is tried again after `runs.backoff` seconds, `runs.tries` times in all; each failed try is logged as a warning with its error. When no try is left the run is failed, logged as an error, and kept with its error until it is retried:

```sh
php cua runs:list --status=failed
php cua runs:retry 12              # with all its tries, from the step that failed
```

`$runs->retry($id)` does the same from code. A run left running by a worker that died, whose job the queue then gave up on, is retried the same way once its lease has run out, or taken up by `runs:recover`. The run stays failed until a worker takes it up, which then starts its tries again: retrying it twice meanwhile is harmless, even if the run fails again before the second is taken up, and a retry whose job did not reach the queue can simply be asked again.

## Looking at runs

```sh
php cua runs:list                  # the latest 50: id, run, status, steps done, tries, when, why
php cua runs:list --status=waiting --limit=10
```

`$runs->find($id)` returns a `Crab\Contracts\Runs\RunState`, or null:

| | |
| --- | --- |
| `status` | A `Crab\Contracts\Runs\Status`: `Pending`, `Running`, `Sleeping`, `Waiting`, `Completed` or `Failed` |
| `result` | What `handle()` returned, once completed |
| `error` | Why the last try failed |
| `attempts` | Tries failed since it was started or retried |
| `steps` | The names of the steps, sleeps and signals done, in order |
| `data`, `run`, `createdAt`, `updatedAt` | What it was started with, its class, and when |

## Configuration

Everything has a default. To change one, add `config/runs.php`:

| Setting | Default | |
| --- | --- | --- |
| `runs.table` | `runs` | The runs' table |
| `runs.steps` | `run_steps` | The table of their steps |
| `runs.database` | `null` | The name of the database they are in; the default one when null |
| `runs.queue` | `null` | The queue their work is pushed to; `queue.queue` when null |
| `runs.tries` | `3` | How many times a run is tried before it is failed |
| `runs.backoff` | `[10, 60]` | Seconds before the second try, the third, and so on; the last repeats |
| `runs.lease` | `90` | Seconds a worker's hold on a run lasts unless renewed |

## In tests

With the queue's store set to `memory`, a run is carried out by running the queue's jobs, as a worker would. With a [`FrozenClock`](testing.md#time) bound as the clock, moving it on ends sleeps and backoffs:

```php
use Crab\Contracts\Clock\Clock;
use Crab\Contracts\Runs\Runs;
use Crab\Contracts\Runs\Status;
use Crab\Queue\Consumer;
use Crab\Testing\FrozenClock;

$clock = new FrozenClock('2026-01-31 12:00:00');
$app->container->instance(Clock::class, $clock);
$runs = $app->container->get(Runs::class);
$worker = $app->container->get(Consumer::class);

$id = $runs->start(OnboardUser::class, userId: 1);
while ($worker->runNext()) {
}
self::assertSame(Status::Sleeping, $runs->find($id)->status);

$clock->advance('+3 days');
while ($worker->runNext()) {
}
self::assertSame(Status::Completed, $runs->find($id)->status);
```

See [Testing](testing.md).
