# Responses

- [Introduction](#introduction)
- [Returning data](#returning-data)
- [Response factories](#response-factories)
- [Headers](#headers)
- [Cookies](#cookies)
- [Redirects](#redirects)
- [Files](#files)
- [Streaming](#streaming)
    - [Server-sent events](#server-sent-events)
- [Errors](#errors)

## Introduction

An action returns data, and Crab sends it as JSON with `200`. Build a response yourself only when the status, a header or the body needs saying. There is one place where status and headers are set, your code, and no attribute or configuration that says them a second time.

## Returning data

```php
return ['id' => 7, 'name' => 'Ada'];     // JSON, 200
return $user;                            // an object: JSON from its public properties
return 'pong';                           // a string: text/plain
return;                                  // nothing: 204 No Content
```

A `DateTimeInterface` is written as ISO 8601 text, a backed enum as its value, and a `JsonSerializable` as it says. A [page of query results](queries.md#pagination) is written with its items and its counts.

Give the shape of what you return a type when clients depend on it:

```php
final readonly class UserView
{
    public function __construct(public int $id, public string $name, public \DateTimeImmutable $joinedAt)
    {
    }
}
```

## Response factories

```php
use Crab\Http\Response;

Response::json($data);                                   // 200
Response::json($data, 202);                              // another status
Response::created($user, "/users/{$user->id}");          // 201, with a Location header
Response::noContent();                                   // 204
Response::text('pong');                                  // text/plain
Response::html('<h1>Hello</h1>');                        // text/html
Response::redirect('/login');                            // 302
Response::download($path, 'invoice.pdf');                // a file
Response::attachment($csv, 'orders.csv', 'text/csv');    // text made here, saved as a file
new Response(418, ['X-Reason' => 'teapot'], 'no coffee');
```

`Response` is a PSR-7 response, so every `with...()` method of the standard works on it.

## Headers

```php
return Response::json($user)
    ->withHeader('Cache-Control', 'max-age=60')
    ->withAddedHeader('Vary', 'Accept-Language');
```

A header every response should carry belongs in [middleware](middleware.md), not in each action.

## Cookies

```php
use Crab\Http\Cookie;

return Response::json(['ok' => true])
    ->withCookie(new Cookie('theme', 'dark', maxAge: 86400 * 30));

return Response::noContent()->withCookie(Cookie::forget('theme'));
```

A cookie is `HttpOnly` and `SameSite=Lax` unless you say otherwise:

```php
new Cookie('session', $id, maxAge: 3600, path: '/', domain: 'example.com', secure: true, httpOnly: true, sameSite: 'Strict');
```

Read cookies with `$request->getCookieParams()`.

## Redirects

```php
return Response::redirect('/login');                     // 302
return Response::redirect('/v2/users', 301);             // 301, 303, 307 or 308
```

## Files

```php
return Response::download('/var/reports/q3.pdf', 'q3-report.pdf', 'application/pdf');
```

The file is read as it is sent, not loaded into memory. The second argument is the name the client saves it under.

For content the action makes, such as a CSV built from a query, send it as it is, with no file in between:

```php
return Response::attachment($csv, 'orders.csv', 'text/csv');
```

## Streaming

An action that returns a generator streams. Each value it yields goes to the client as soon as it is yielded, as a [server-sent event](#server-sent-events), so a reply an AI model writes word by word reaches the browser word by word:

```php
use Crab\Contracts\HttpClient\Client;

final readonly class ReplyToChat
{
    public function __construct(private Client $http) {}

    public function handle(int $id, #[Fields(['text' => 'string'])] Data $prompt, Request $request): \Generator
    {
        $reply = $this->http->timeout(300)->stream('POST', 'https://api.example.com/v1/chat',
            json: ['messages' => [['role' => 'user', 'content' => $prompt->text]]],
            cancellation: $request->cancellation(),
        )->throw();

        foreach ($reply->events() as $event) {
            yield ['token' => $event->json()['delta']];
        }
        yield 'done' => ['id' => $id];
    }
}
```

Between two values the generator may wait on anything, a query or another API, and the worker serves other requests meanwhile. While the client reads more slowly than the generator yields, the generator waits, so nothing piles up in memory. If the client goes away, the generator is stopped at its `yield` and its `finally` blocks run. Pass `$request->cancellation()` to whatever it waits on, as above, so that the call stops too. Reading another server's answer as it arrives is [`stream()` on the HTTP client](http-client.md#streaming-answers).

A response may last as long as it keeps moving. The server cuts off a response that sends nothing for 30 seconds, so a generator that may be quiet for longer yields something in between.

The generator starts when the response is sent, after the action and its middleware have returned. An exception it throws then cannot become an error response, because the status has already gone: it is logged and the connection is closed, so the client sees the stream end without its last event. Check what can fail before the first `yield`, or yield an error event of your own.

For a body that is not events, such as a large CSV made from a query, give the chunks and their type to `Response::stream()`:

```php
return Response::stream((function () {
    yield "id,name\n";
    $cursor = null;
    do {
        $page = $this->db->table('products')->orderBy('id')->cursorPaginate(500, $cursor);
        foreach ($page->items as $row) {
            yield "{$row['id']},{$row['name']}\n";
        }
        $cursor = $page->nextCursor;
    } while ($cursor !== null);
})(), 'text/csv');
```

### Server-sent events

A browser reads server-sent events with `EventSource`, or with `fetch()` when it must send a body or a token. Each value is one event:

| Yielded | Sent |
| --- | --- |
| `yield ['token' => 'Hi'];` | `data: {"token":"Hi"}` |
| `yield 'done' => ['id' => 7];` | `event: done` then `data: {"id":7}` |
| `yield 'plain text';` | `data: plain text` |

A value is written as JSON, as a returned one would be, and a string as it is, one `data:` line for each of its lines. A string key names the event. `Response::events($iterable)` makes the same response from any iterable, for adding headers. It is sent with `Cache-Control: no-cache` and `X-Accel-Buffering: no`, so that a proxy such as nginx passes each event on at once.

## Errors

Do not build an error response by hand. Throw `HttpException`, and the error handler answers in the same form as every other error:

```php
use Crab\Http\HttpException;

throw new HttpException(404, 'No such user.');
throw new HttpException(429, 'Slow down.', ['Retry-After' => '30']);
```

See [Error handling](errors.md).
