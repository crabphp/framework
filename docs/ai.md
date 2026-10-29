# AI applications

- [Introduction](#introduction)
- [Which to use](#which-to-use)
- [Relaying a model's reply](#relaying-a-models-reply)
    - [Before the first word](#before-the-first-word)
    - [When the reader leaves](#when-the-reader-leaves)
- [Work that outlasts a request](#work-that-outlasts-a-request)
    - [Calling a model in a step](#calling-a-model-in-a-step)
    - [A person in the loop](#a-person-in-the-loop)
- [Showing a run as it goes](#showing-a-run-as-it-goes)
- [A conversation that remembers](#a-conversation-that-remembers)
- [In tests](#in-tests)

## Introduction

An application built on a language model spends most of its time waiting: for the model's next word, for a tool it called, for a person to approve what it did. Crab waits on the event loop, so a worker serves other requests meanwhile, and gives each kind of waiting its own shape:

- **A reply that comes word by word** is an action that returns a generator. Each value it yields reaches the browser as a server-sent event at once. See [Streaming](responses.md#streaming).
- **Another server's reply that comes word by word,** a model's API, is read with the HTTP client's `stream()`, an event at a time. See [Streaming answers](http-client.md#streaming-answers).
- **Work of many steps that must not start over,** an agent that plans, searches and writes, is a [run](runs.md). Its steps are kept, so a crash, a deploy or a failed call goes on from where it was, and it may wait days for a person without holding a worker.
- **A conversation that remembers,** an assistant each customer talks to over many requests, is an [actor](actors.md): one for each customer, taking their messages one at a time and keeping the conversation in memory while it runs.

This page shows when to use which, and how they fit together.

## Which to use

| The work | Use | Why |
| --- | --- | --- |
| A chat reply, a summary, one model call the user watches | A generator action relaying `stream()` | Seconds long; the user sees each word as it is written, and leaving stops the call |
| One model call nobody watches: tagging, a translation | A [job](queues.md) | One step; if it fails, doing it again is the whole of the work |
| Several model or tool calls in a row | A run, each call a step | A failure at the fifth call does not pay for the first four again |
| Anything that waits for a person, or for hours | A run with `await()` or `sleep()` | Waiting holds no worker, and survives deploys |
| A run the user watches | A run, and a generator action that follows it | The work goes on when the browser closes; the page shows how far it got |
| A conversation over many requests | An actor for each user | One at a time, in order, with the conversation in memory; nothing to load and save for each message |

## Relaying a model's reply

An action that streams a model's reply asks the model with `stream()`, reads its events, and yields what the browser needs from each:

```php
use Crab\Contracts\HttpClient\Client;
use Crab\Http\HttpException;

final readonly class AskAssistant
{
    public function __construct(private Client $http)
    {
    }

    public function handle(#[Fields(['question' => 'string'])] Data $input, Request $request): \Generator
    {
        $reply = $this->http->timeout(120)->stream('POST', 'https://api.example.com/v1/chat',
            json: ['messages' => [['role' => 'user', 'content' => $input->question]], 'stream' => true],
            cancellation: $request->cancellation(),
        );
        if (!$reply->ok()) {
            throw new HttpException(502, 'The assistant is not answering.');
        }

        return (function () use ($reply): \Generator {
            foreach ($reply->events() as $event) {
                if ($event->data === '[DONE]') {
                    break;
                }
                yield ['token' => $event->json()['choices'][0]['delta']['content'] ?? ''];
            }
            yield 'done' => [];
        })();
    }
}
```

The browser reads it with `EventSource` for a `GET`, or with `fetch()` and a reader for a `POST`:

```sh
curl -N -X POST localhost:8080/assistant -H 'Content-Type: application/json' -d '{"question":"a blue mug"}'
# data: {"token":"Try "}
# data: {"token":"the "}
# ...
# event: done
# data: []
```

Words go out as fast as the browser takes them and no faster: when it reads slowly, the generator waits, and the model's reply waits in turn, a quarter of a megabyte ahead at most. Nothing piles up in memory, however many replies a worker relays.

### Before the first word

A generator runs only when the response is sent, after its status has gone, so an error it throws cannot become an error response; it ends the stream early. Do what may fail before the first `yield`. Above, `handle()` is not itself a generator: it asks the model, answers `502` if the model is down, and only then returns the generator that relays the reply. The [validation](validation.md) of the question happens before any of it, so an empty one is a `422` as usual.

Once streaming, report a failure as an event of your own, such as `yield 'error' => ['message' => ...]`, so the page can say so.

### When the reader leaves

When the browser goes away, the generator is stopped at its `yield`, and `$request->cancellation()` is cancelled, so the call to the model stops too and the model's tokens are no longer paid for. Pass that cancellation to every call the generator waits on.

A stream may last as long as it keeps moving. One quiet for 30 seconds is cut off, so a model that thinks for longer before its first word needs something yielded meanwhile, such as `yield 'thinking' => []`.

## Work that outlasts a request

An agent's work, a plan, ten searches and a report, takes minutes, costs money at each step, and fails at some step now and then. Make it a run, so that what was done is kept:

```php
use Crab\Contracts\Runs\Run;

final readonly class ResearchTopic
{
    public function __construct(private Llm $llm, private Search $search)
    {
    }

    public function handle(Run $run, string $topic): array
    {
        $questions = $run->step('plan', fn () => $this->llm->plan($topic));

        $notes = [];
        foreach ($questions as $i => $question) {
            $notes[] = $run->step("search.{$i}", fn () => $this->search->find($question));
        }

        return $run->step('report', fn () => $this->llm->report($topic, $notes));
    }
}
```

Start it from an action and answer at once with its id; a [queue](queues.md) worker carries it out:

```php
final readonly class StartResearch
{
    public function __construct(private Runs $runs)
    {
    }

    public function handle(#[Fields(['topic' => 'string'])] Data $input): array
    {
        return ['run' => $this->runs->start(ResearchTopic::class, topic: $input->topic)];
    }
}
```

If the seventh search fails, the run is tried again after a pause, from the seventh search; the plan and the six searches before it are not paid for again.

### Calling a model in a step

A run is run again from the top each time it is resumed, so everything that is slow, costly or different each time goes in a step: each call to a model, each tool it calls, the time. The code between steps only arranges what steps returned. See [The rules a run follows](runs.md#the-rules-a-run-follows).

A step's result is kept as JSON, so return a model's text, or the array its JSON decodes to, rather than a response object.

An agent that decides its own next step, a model that answers with a tool to call until it answers with text, is a loop of steps numbered as they go:

```php
$messages = [['role' => 'user', 'content' => $task]];
for ($turn = 0; $turn < 20; $turn++) {
    $answer = $run->step("model.{$turn}", fn () => $this->llm->chat($messages, tools: $this->tools->schema()));
    $messages[] = $answer;
    if ($answer['tool'] === null) {
        return $answer['content'];
    }
    $messages[] = $run->step("tool.{$turn}", fn () => $this->tools->call($answer['tool']));
}
```

Resumed after a failure, the loop goes again through the turns already taken, each step returning what it returned before, so the messages come out the same and the agent goes on from the turn that failed.

### A person in the loop

`await()` puts the run aside until a person, or anything else, gives it a value. Nothing is held while it waits, for a minute or for a week:

```php
$draft = $run->step('draft', fn () => $this->llm->draft($ticket));
$review = $run->await('review');                 // until signal()
if ($review['approved'] !== true) {
    return 'dropped';
}
$run->step('send', fn () => $this->mailer->send($ticket['email'], $review['text'] ?? $draft));
```

The action behind the reviewer's button gives it, with the run's id:

```php
$this->runs->signal($id, 'review', ['approved' => true, 'text' => $input->text]);
```

See [Waiting for a signal](runs.md#waiting-for-a-signal).

## Showing a run as it goes

The browser that started a run may watch it with a generator action that follows the run's state. The run goes on whether anyone watches or not, so the page may be closed and opened again:

```php
use Crab\Contracts\Runs\Runs;
use Crab\Contracts\Runs\Status;
use Crab\Engine\Async\Async;
use Crab\Engine\EventLoop;
use Crab\Http\HttpException;

final readonly class WatchResearch
{
    public function __construct(private Runs $runs)
    {
    }

    public function handle(int $id, Request $request): \Generator
    {
        $state = $this->runs->find($id) ?? throw new HttpException(404);

        return (function () use ($id, $state, $request): \Generator {
            $seen = 0;
            while (true) {
                foreach (array_slice($state->steps, $seen) as $step) {
                    yield 'step' => ['name' => $step];
                }
                $seen = count($state->steps);

                if ($state->status === Status::Completed) {
                    yield 'done' => ['result' => $state->result];
                    return;
                }
                if ($state->status === Status::Failed) {
                    yield 'failed' => ['error' => $state->error];
                    return;
                }

                Async::sleep(EventLoop::current(), 1.0, $request->cancellation());
                $state = $this->runs->find($id);
            }
        })();
    }
}
```

It reads the run once a second, which is cheap at the scale of a person watching a page. For many watchers, have the run's steps [broadcast](broadcasting.md) as they finish, and let the page listen for them.

## A conversation that remembers

A chat whose every request loads the conversation from the database, calls the model and saves it back works, until a customer sends two messages at once and one answer is written over the other. An actor for each customer takes their messages one at a time, in the order sent, and keeps the conversation in its variables:

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
            $answer = $this->llm->chat($history, cancellation: $inbox->cancellation());
            $history[] = ['role' => 'assistant', 'content' => $answer];
            $message->reply(['answer' => $answer]);
        }
    }
}
```

The action asks the customer's actor and answers with its reply:

```php
use Crab\Contracts\Actors\Actors;
use Crab\Contracts\Actors\NoReply;
use Crab\Http\HttpException;

final readonly class Chat
{
    public function __construct(private Actors $actors)
    {
    }

    public function handle(User $user, #[Fields(['text' => 'string'])] Data $input, Request $request): array
    {
        try {
            return $this->actors->ask(Assistant::class, "user:{$user->id}", ['text' => $input->text],
                timeout: 60,
                cancellation: $request->cancellation(),
            );
        } catch (NoReply) {
            throw new HttpException(503, 'The assistant is not available.');
        }
    }
}
```

The conversation lasts while the actor runs, and it ends after `actors.idle` seconds without a message. To keep a conversation for good, save each turn to the database as well, and have the actor load it when it starts. With several HTTP workers, carry the messages over Redis so that one customer has one actor; see [Across processes](actors.md#across-processes).

An actor and a run go together: the actor takes the conversation, and starts a run for work that must not be lost, such as an order the customer asked it to place.

## In tests

`FakeClient` answers for the model, with a reply in server-sent events, and the test's `events()` reads the action's stream back:

```php
use Crab\Contracts\HttpClient\Client;
use Crab\HttpClient\FakeClient;
use Crab\Testing\Api;

$this->app->container->instance(Client::class, new FakeClient([
    'POST https://api.example.com/v1/chat' => FakeClient::response(200,
        "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\ndata: [DONE]\n\n",
        ['Content-Type' => 'text/event-stream'],
    ),
]));

$response = (new Api($this->app))->post('/assistant', ['question' => 'a blue mug'])->assertStatus(200);

self::assertSame([
    ['event' => 'message', 'data' => ['token' => 'Hi']],
    ['event' => 'done', 'data' => []],
], $response->events());
```

A run is tested by running the queue's jobs, with a `FrozenClock` to end its sleeps; see [Runs in tests](runs.md#in-tests).
