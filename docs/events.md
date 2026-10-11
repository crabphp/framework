# Events

- [Introduction](#introduction)
- [Configuration](#configuration)
- [Defining an event](#defining-an-event)
- [Listening](#listening)
    - [A listener class](#a-listener-class)
    - [A closure](#a-closure)
    - [Listening for a family of events](#listening-for-a-family-of-events)
- [Dispatching](#dispatching)
- [How listeners run](#how-listeners-run)
    - [Slow listeners](#slow-listeners)
    - [Stopping an event](#stopping-an-event)
- [Events the framework dispatches](#events-the-framework-dispatches)
- [In tests](#in-tests)

## Introduction

An event says that something happened: a user registered, an order was paid. The code that makes it happen dispatches the event and knows nothing of who reacts. Listeners react: send the welcome email, update a counter, tell another service.

Use events to keep one piece of code from knowing every consequence of what it does. When registering a user starts to mean six things, the action dispatches `UserRegistered` and the six things are six listeners. Do not use an event for a single consequence that the code may as well call directly.

It is the PSR-14 dispatcher, so a library that asks for `Psr\EventDispatcher\EventDispatcherInterface` is given the same one.

## Configuration

Register the provider before the providers that add listeners:

```php
// app.php
use Crab\Events\EventsProvider;

->withProviders([
    EventsProvider::class,
    AppProvider::class,
])
```

There are no settings.

## Defining an event

An event is any object. Give it the facts a listener needs, as public readonly properties:

```php
// app/Events/UserRegistered.php
namespace App\Events;

final readonly class UserRegistered
{
    public function __construct(public int $userId, public string $email)
    {
    }
}
```

There is nothing to extend or implement.

## Listening

Listeners are added in a provider's `register()`. That is the one place, so a reader can find every reaction to an event by searching for its class name.

```php
// app/Providers/AppProvider.php
use App\Events\UserRegistered;
use App\Actions\SendWelcomeEmail;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Events\Dispatcher;

public function register(Container $container): void
{
    $events = $container->get(Dispatcher::class);
    $events->listen(UserRegistered::class, SendWelcomeEmail::class);
    $events->listen(UserRegistered::class, CountSignup::class);
}
```

> [!NOTE]
> Nothing is discovered. A listener class that is not named in a `listen()` call never runs, however it is named or wherever it lives.

### A listener class

A class with `handle()` that takes the event. Its constructor takes services, and the container makes it when its first event arrives:

```php
// app/Actions/SendWelcomeEmail.php
namespace App\Actions;

use App\Events\UserRegistered;

final readonly class SendWelcomeEmail
{
    public function __construct(private Mailer $mailer)
    {
    }

    public function handle(UserRegistered $event): void
    {
        $this->mailer->send($event->email, 'Welcome');
    }
}
```

### A closure

For a line or two:

```php
use Crab\Contracts\Log\Logger;

$events->listen(UserRegistered::class, static function (UserRegistered $event) use ($container): void {
    $container->get(Logger::class)->info('User {id} registered', ['id' => $event->userId]);
});
```

### Listening for a family of events

A listener for an interface or a parent class hears every event that implements or extends it:

```php
interface Audited
{
}

final readonly class OrderPaid implements Audited { /* ... */ }
final readonly class OrderRefunded implements Audited { /* ... */ }

$events->listen(Audited::class, WriteAuditLog::class);       // hears both
```

## Dispatching

Ask for the dispatcher and hand it the event:

```php
use App\Events\UserRegistered;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Events\Dispatcher;
use Crab\Http\Response;
use Crab\Validation\Email;

final readonly class RegisterUser
{
    public function __construct(private Database $db, private Dispatcher $events)
    {
    }

    public function handle(#[Email] string $email, string $name): Response
    {
        $id = $this->db->table('users')->insert(['email' => $email, 'name' => $name]);

        $this->events->dispatch(new UserRegistered($id, $email));

        return Response::created(['id' => $id], "/users/{$id}");
    }
}
```

`dispatch()` returns the event, so a listener can leave an answer on an event that has a property for one.

## How listeners run

Listeners run at once, one after another, in the order they were added, before `dispatch()` returns. What a listener throws reaches the code that dispatched, and the listeners after it do not run.

So a failing listener fails the request. That is right when the reaction is part of the work (reserve stock when an order is placed) and wrong when it is a side effect (send an email).

### Slow listeners

A listener that sends an email or calls another API makes the client wait for it. Have the listener push a [job](queues.md) and return:

```php
use App\Events\UserRegistered;
use Crab\Contracts\Queue\Queue;

final readonly class SendWelcomeEmail
{
    public function __construct(private Queue $queue)
    {
    }

    public function handle(UserRegistered $event): void
    {
        $this->queue->push(SendWelcomeEmailJob::class, userId: $event->userId);
    }
}
```

The job then runs in a queue worker, is retried when it fails, and the client has its answer already. There are no "queued listeners": a listener that queues is three lines, and what runs where stays visible.

### Stopping an event

An event that implements PSR-14's `StoppableEventInterface` is offered to no further listener once `isPropagationStopped()` is true. Use it for a question the first listener able to answer should settle:

```php
use Psr\EventDispatcher\StoppableEventInterface;

final class PriceRequested implements StoppableEventInterface
{
    public ?int $price = null;

    public function __construct(public readonly string $sku)
    {
    }

    public function isPropagationStopped(): bool
    {
        return $this->price !== null;
    }
}
```

## Events the framework dispatches

With `EventsProvider` registered, the queue and the scheduler tell you what they ran. Listen for these to measure, alert or keep a record of your own:

| Event | Dispatched | Carries |
| --- | --- | --- |
| `Crab\Contracts\Queue\JobRan` | After each try of a job | `message` (the job, its queue, data and attempts), `seconds`, `error` (what it threw, or null), `gaveUp` (whether it is now kept as failed) |
| `Crab\Contracts\Schedule\TaskRan` | After each run of a scheduled task | `task` (its name), `seconds`, `error` |

```php
use Crab\Contracts\Queue\JobRan;

$events->listen(JobRan::class, static function (JobRan $event) use ($alerts): void {
    if ($event->gaveUp) {
        $alerts->send("{$event->message->job} failed for good: {$event->error?->getMessage()}");
    }
});
```

They are dispatched in the worker that did the work: a queue worker or the scheduler, not a worker serving HTTP. A listener here that throws is logged and changes nothing for the job or the task. Register `EventsProvider` before `QueueProvider` and `ScheduleProvider`.

## In tests

The dispatcher works on its own, with no application:

```php
use Crab\Events\Dispatcher;

$events = new Dispatcher();
$heard = [];
$events->listen(UserRegistered::class, static function (UserRegistered $event) use (&$heard): void {
    $heard[] = $event->userId;
});

(new RegisterUser($db, $events))->handle('ada@example.com', 'Ada');

self::assertSame([1], $heard);
```

To test a listener, call it: `(new SendWelcomeEmail($queue))->handle(new UserRegistered(7, 'ada@example.com'))`.
