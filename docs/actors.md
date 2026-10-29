# Actors

- [Introduction](#introduction)
- [Writing an actor](#writing-an-actor)
    - [Replying](#replying)
    - [When an actor ends](#when-an-actor-ends)
- [Sending to an actor](#sending-to-an-actor)
- [Where actors run](#where-actors-run)
    - [Across processes](#across-processes)
- [What an actor is not](#what-an-actor-is-not)
- [Configuration](#configuration)
- [In tests](#in-tests)

## Introduction

An actor is a piece of your application with a key, an inbox and a memory: one runs for each key, takes its messages one at a time, in the order they were sent, and remembers what it was told while it runs. Use one for something that many requests talk to and that must see them one by one: a customer's conversation with an assistant, a game's table, a document several people edit, a device that must not be sent two commands at once.

```php
use Crab\Contracts\Actors\Inbox;

final readonly class Assistant
{
    public function __construct(private Llm $llm)
    {
    }

    public function handle(Inbox $inbox, string $key): void
    {
        $history = [];
        foreach ($inbox as $message) {
            $history[] = ['role' => 'user', 'content' => $message->data['text']];
            $answer = $this->llm->chat($history);
            $history[] = ['role' => 'assistant', 'content' => $answer];
            $message->reply(['answer' => $answer]);
        }
    }
}
```

```php
use Crab\Contracts\Actors\Actors;

$reply = $actors->ask(Assistant::class, "user:{$user->id}", ['text' => 'and in blue?']);
```

Two requests from one customer reach the same actor, which answers the second knowing the first. Two customers have an actor each, and neither waits for the other.

## Writing an actor

An actor is a class with `handle(Inbox $inbox, string $key)`, services in its constructor as a job's are. `handle()` runs once for each key, from the first message until it returns, and keeps its memory in its variables. The object itself is made by the container and may be shared between keys, so keep nothing about one key in its properties.

Iterating the inbox gives each `Crab\Contracts\Actors\Message` as it comes, and waits while there is none. `$message->data` is what was sent, as JSON reads it. `$inbox->receive()` gives one message, or null once the inbox has ended.

While an actor handles a message, the next ones wait in its inbox. It may wait on anything in between, a query, a model, another actor, and the worker serves other requests and other actors meanwhile.

### Replying

`$message->reply($value)` answers an `ask()`; `$message->asked()` says whether anyone waits for an answer. Replying to a message sent with `tell()` does nothing. A message is replied to once, and the reply travels as JSON too.

An actor may keep a message and reply to it later, after other messages, while it runs.

### When an actor ends

- **When its inbox has been empty for `actors.idle` seconds,** 60 by default, iterating it ends, and so does the actor when `handle()` returns. Its memory goes with it: the next message starts it again from the beginning. Keep what must last longer in the database.
- **When `handle()` returns or throws,** the asks it took and did not reply to are refused, and a failure is logged as an error. The messages it had not taken yet are given to its next run, which starts at once.
- **When its process stops,** its inbox ends and `$inbox->cancellation()` is cancelled. Pass that cancellation to what the actor waits on, so that a stop does not wait for it. Messages not yet taken are dropped.

## Sending to an actor

Ask for `Crab\Contracts\Actors\Actors`, in a constructor or an action's parameters:

```php
$actors->tell(Assistant::class, "user:{$id}", ['text' => 'hi']);       // returns at once

$reply = $actors->ask(Assistant::class, "user:{$id}", ['text' => 'hi'],  // waits for reply()
    timeout: 30,
    cancellation: $request->cancellation(),
);
```

- **`tell()` returns at once,** and nothing tells you the actor got it.
- **`ask()` waits for the actor's reply** and returns it. It throws `Crab\Contracts\Actors\NoReply` when none came within `timeout` seconds, or the actor ended or failed before it replied, and a `CancelledException` when its `cancellation` was cancelled first.
- **A message travels as JSON:** numbers, text, booleans, null and arrays of them. One that JSON cannot carry is refused where it is sent.
- **A message is delivered at most once.** One that cannot be, to a process that is stopping or an inbox already holding `actors.max_inbox` messages, is dropped and logged, and an ask for it is refused.

## Where actors run

With the local broadcast carrier, the default, an actor runs in the process that sends to it. That is the HTTP worker, which is the only one: the local carrier serves one HTTP process. It is how actors run in development and in tests, and nothing more is needed.

### Across processes

With several HTTP workers, or another machine, two requests from one customer may land in different processes, and each would have an actor of its own. To have one actor for each key wherever the messages come from, carry them over Redis and run the actors in a server of their own:

```php
// config/broadcast.php
return ['carrier' => 'redis'];          // with crabphp/redis and its RedisProvider registered

// config/server.php
'with' => [
    'actors' => env('SERVER_ACTORS', false),
],
```

`start` then runs the `actors` server beside HTTP, in `actors.workers` processes, 2 by default; `php cua actors:work` runs it on its own. The keys are shared out among those processes by a hash of the actor's class and key, so one key always goes to the same process, and every message and reply travels through Redis to it and back. Every process that sends to actors reads `actors.workers`, so give them all the same value.

Without the `actors` server running, nothing takes the messages: a `tell()` is lost and an `ask()` ends at its timeout. With the local carrier, the `actors` server refuses to start, as it would have nothing to do.

The `actors` server is reloaded with the rest, and a reload ends its actors as a stop does. While one is reloading, messages to it are lost.

## What an actor is not

- **Not durable.** An actor's memory is in its process, gone when it ends, crashes or is reloaded, and its messages are delivered at most once. For work that must go on after a crash, have the actor start a [run](runs.md), or keep its state in the database.
- **Not supervised.** An actor that fails is not restarted until its next message, and there is no tree of actors watching each other.
- **Not moved.** A key belongs to one process for as long as `actors.workers` is unchanged; changing it moves keys, and the actors they had end with their idle time.

## Configuration

Everything has a default. To change one, add `config/actors.php`:

| Setting | Default | |
| --- | --- | --- |
| `actors.workers` | `2` | Processes of the `actors` server, among which the keys are shared out |
| `actors.idle` | `60` | Seconds an actor's inbox may be empty before it ends |
| `actors.max_inbox` | `1000` | The most messages waiting in one actor's inbox; more are dropped |

## In tests

With the local carrier, as in tests, an actor runs in the test's process, started by the first message. `ask()` waits for it as it would in a request:

```php
use Crab\Contracts\Actors\Actors;

$actors = $app->container->get(Actors::class);

self::assertSame(['answer' => 'Hi'], $actors->ask(Assistant::class, 'user:1', ['text' => 'hello']));
```

Through the API, as a request would, with the services the actor calls bound as fakes:

```php
$response = $api->actingAs($user)->post('/assistant/chat', ['question' => 'a mug'])->assertStatus(200);
```

An actor keeps running after its test, waiting for its idle time; end them all in `tearDown()` with `$app->container->get(\Crab\Actors\Actors::class)->stop()`, so that one test's memory does not reach the next.
