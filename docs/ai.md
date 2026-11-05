# AI applications

- [Introduction](#introduction)
- [Which to use](#which-to-use)
- [Relaying a model's reply](#relaying-a-models-reply)
    - [Before the first word](#before-the-first-word)
    - [When the reader leaves](#when-the-reader-leaves)
- [Long work and conversations](#long-work-and-conversations)
- [In tests](#in-tests)

## Introduction

An application built on a language model spends most of its time waiting: for the model's next word, for a tool it called, for a person to approve what it did. Crab waits on the event loop, so a worker serves other requests meanwhile, and gives each kind of waiting its own shape:

- **A reply that comes word by word** is an action that returns a generator. Each value it yields reaches the browser as a server-sent event at once. See [Streaming](responses.md#streaming).
- **Another server's reply that comes word by word,** a model's API, is read with the HTTP client's `stream()`, an event at a time. See [Streaming answers](http-client.md#streaming-answers).
- **Work of many steps that must not start over,** an agent that plans, searches and writes, is a run, from [crabphp/runs](https://github.com/crabphp/runs). Its steps are kept, so a crash, a deploy or a failed call goes on from where it was, and it may wait days for a person without holding a worker.
- **A conversation that remembers,** an assistant each customer talks to over many requests, is an actor, from [crabphp/actors](https://github.com/crabphp/actors): one for each customer, taking their messages one at a time and keeping the conversation in memory while it runs.

This page shows when to use which, and how they fit together.

## Which to use

| The work | Use | Why |
| --- | --- | --- |
| A chat reply, a summary, one model call the user watches | A generator action relaying `stream()` | Seconds long; the user sees each word as it is written, and leaving stops the call |
| One model call nobody watches: tagging, a translation | A [job](queues.md) | One step; if it fails, doing it again is the whole of the work |
| Several model or tool calls in a row | A [run](https://github.com/crabphp/runs), each call a step | A failure at the fifth call does not pay for the first four again |
| Anything that waits for a person, or for hours | A run with `await()` or `sleep()` | Waiting holds no worker, and survives deploys |
| A run the user watches | A run, and a generator action that follows it | The work goes on when the browser closes; the page shows how far it got |
| A conversation over many requests | An [actor](https://github.com/crabphp/actors) for each user | One at a time, in order, with the conversation in memory; nothing to load and save for each message |

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

## Long work and conversations

Work of many steps that must not start over, and a conversation that remembers, come in packages of their own, each with a section on AI applications:

- **[Runs](https://github.com/crabphp/runs#in-ai-applications):** an agent's plan, searches and report as steps that are kept, so that a failure at the seventh search does not pay for the six before it again; a person in the loop with `await()`; and a generator action that shows a run as it goes.
- **[Actors](https://github.com/crabphp/actors#in-ai-applications):** an assistant for each customer, taking their messages one at a time, in the order sent, and keeping the conversation in memory.

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

A run is tested by running the queue's jobs, with a `FrozenClock` to end its sleeps; see [its package's tests](https://github.com/crabphp/runs#in-tests).
