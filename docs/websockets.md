# WebSockets

- [Introduction](#introduction)
- [Your first socket route](#your-first-socket-route)
- [Checking ownership](#checking-ownership)
- [Authenticating a browser](#authenticating-a-browser)
- [Configuration](#configuration)
- [Broadcasting](broadcasting.md)
- [Sending and receiving](#sending-and-receiving)
- [Closing and cancellation](#closing-and-cancellation)
- [Keeping connection state](#keeping-connection-state)
- [Database and slow work](#database-and-slow-work)
- [Limits and slow clients](#limits-and-slow-clients)
- [Stop and reload](#stop-and-reload)
- [Behind a proxy](#behind-a-proxy)
- [Testing](#testing)
- [How it works](#how-it-works)

## Introduction

A WebSocket keeps a connection open for live order updates, a progress display, or an interactive tool. It shares your API's port, routes and middleware. Waiting in one action lets other connections continue.

Use ordinary HTTP for a request and its answer. Socket messages arrive at most once, with no history or replay: fetch current state after reconnecting. Use [Broadcasting](broadcasting.md) for channels and updates from other workers, and a single-use ticket to authenticate a browser. Compression and HTTP/2 or HTTP/3 WebSockets are not supported.

## Your first socket route

Declare a public echo route in your route file. Route groups and middleware work as for HTTP, and `php cua route:list` shows `WS`:

```php
// routes/web.php
use Crab\Contracts\WebSocket\Socket;
use Crab\Route;

Route::websocket('/live', static function (Socket $socket): void {
    foreach ($socket as $message) {
        $socket->send($message);
    }
});
```

This native browser client connects to that route, sends JSON, and retries closed connections with a capped delay and jitter:

```js
let attempts = 0;
let stopped = false;
let retry;
let socket;
const url = new URL('/live', window.location.href);
url.protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';

function connect() {
    socket = new WebSocket(url);
    socket.onopen = () => {
        attempts = 0;
        socket.send(JSON.stringify({ type: 'refresh' }));
    };
    socket.onmessage = event => {
        console.log(JSON.parse(event.data));
    };
    socket.onclose = event => {
        console.log('Closed', event.code, event.reason);
        if (stopped) return;
        const cap = Math.min(30000, 500 * 2 ** Math.min(attempts++, 6));
        retry = setTimeout(connect, cap / 2 + Math.random() * cap / 2);
    };
}

function stop() {
    stopped = true;
    clearTimeout(retry);
    socket?.close(1000, 'Leaving page');
}

connect();
window.addEventListener('pagehide', stop);
```

Keep the client message format agreed with your action: this example only sends JSON. Browsers expose a refused handshake as a connection error rather than an HTTP response you can inspect. Retry policy should reflect your application; stop retrying when the user leaves the page.

## Checking ownership

Check access inside `handle()` before its first socket use. This order route uses the `App\User` identity from [Authentication](authentication.md) and a short database lookup:

```php
// routes/web.php
use App\Actions\WatchOrder;
use Crab\Auth\TokenAuth;

Route::websocket('/orders/{id}/live', WatchOrder::class)->middleware(TokenAuth::class);
```

```php
// app/Actions/WatchOrder.php
namespace App\Actions;

use App\User;
use Crab\Contracts\Database\Database;
use Crab\Contracts\WebSocket\Socket;
use Crab\Http\HttpException;

final readonly class WatchOrder
{
    public function __construct(private Database $db)
    {
    }

    public function handle(Socket $socket, int $id, User $user): void
    {
        $order = $this->db->table('orders')->where('id', $id)->first()
            ?? throw new HttpException(404, 'Order not found.');
        if ((int) $order['user_id'] !== $user->id) {
            throw new HttpException(403, 'This order belongs to another user.');
        }

        $socket->send(['status' => $order['status']]);
        foreach ($socket as $message) {
            $socket->send($message);
        }
    }
}
```

Before the first `join()`, `send()`, `sendBinary()`, `receive()`, iteration, or `accept()`, the action is still handling HTTP: `HttpException` supplies its status, validation failures receive 422, and unexpected failures receive 500 and are reported. At first use, middleware receives the bodyless 101 response; the action waits for the wire upgrade before continuing. After acceptance, an action failure is reported once with its route and closes with 1011. `Concurrency` holds its place and `AccessLog` writes its line until the middleware pipeline returns at acceptance or an HTTP refusal.

Register `TokenAuthProvider` and your identity resolver as shown in [Authentication](authentication.md#installation), and the database provider as shown in [Database](database.md). A client that can set headers sends `Authorization` during the handshake. Missing required Identity receives HTTP 401. For browsers, use a ticket as below.

Socket actions take one non-null `Socket`, typed pattern parameters, typed query fields, and optionally the request or authenticated Identity. A closure or `[WatchOrder::class, 'handle']` also works. Invalid parameters receive HTTP 422 before application code runs. Upgrades have no request body; input classes and `Validation\Data` body fields are refused at declaration. Middleware sees raw pattern attributes, so it must validate any attribute it uses itself.

## Authenticating a browser

Protect the ticket endpoint with TokenAuth, and the socket route with Ticket. Declare the socket outside a TokenAuth group, so a browser's ticket reaches it without a bearer header:

```php
// routes/web.php
use App\Actions\WatchOrder;
use Crab\Auth\IssueTicket;
use Crab\Auth\Ticket;
use Crab\Auth\TokenAuth;
use Crab\Route;

Route::post('/ws/ticket', IssueTicket::class)->middleware(TokenAuth::class);
Route::websocket('/orders/{id}/live', WatchOrder::class)->middleware(Ticket::class);
```

Fetch a new ticket before each connection, including every reconnect. This client uses the token returned by login, and retries with a capped delay and jitter:

```js
const Authorization = `Bearer ${token}`; // token returned by your login endpoint
let socket;
let attempts = 0;
let stopped = false;
let retry;

function reconnect() {
    if (stopped) return;
    const cap = Math.min(30000, 500 * 2 ** Math.min(attempts++, 6));
    retry = setTimeout(connect, cap / 2 + Math.random() * cap / 2);
}

async function connect() {
    try {
        const response = await fetch('/ws/ticket', {
            method: 'POST', headers: { Authorization },
        });
        if (response.status === 401) {
            console.log('Log in again before reconnecting.');
            return;
        }
        if (!response.ok) throw new Error('Could not fetch a socket ticket.');
        const { ticket } = await response.json();
        if (stopped) return;
        const url = new URL('/orders/42/live', window.location.href);
        url.protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        url.searchParams.set('ticket', ticket);
        socket = new WebSocket(url);
        socket.onopen = () => { attempts = 0; };
        socket.onmessage = event => { console.log(JSON.parse(event.data)); };
        socket.onclose = reconnect;
    } catch (error) {
        console.error(error);
        reconnect();
    }
}

connect();
window.addEventListener('pagehide', () => {
    stopped = true;
    clearTimeout(retry);
    socket?.close(1000, 'Leaving page');
});
```

Do not put a long-lived bearer token in the socket URL: a proxy may retain the whole query in its access log. A ticket is opaque, expires after `auth.ticket_ttl` (30 seconds by default), and is consumed at its first authentication attempt. Your resolver restores the user from the stored id; actions receive the same typed user as with TokenAuth. With no ticket, Ticket delegates to TokenAuth for clients that can send headers. See [Authentication](authentication.md#browser-socket-tickets) for the service and optional `origins:` check, and [Cache stores](cache.md#choosing-a-store) for sharing tickets between workers or machines.

## Configuration

Declaring a socket route enables this capability; no provider is needed. Defaults work without a configuration file. Override them in `config/websocket.php`:

```php
// config/websocket.php
return [
    'ping' => 25,
    'max_message' => 65536,
    'send_timeout' => 5.0,
    'max_connections' => 256,
];
```

| Setting | Default | Use |
| --- | --- | --- |
| `websocket.ping` | `25` | Seconds between pings. Two intervals bound peer silence and a full-inbox read pause. `0` disables pings and both bounds |
| `websocket.max_message` | `65536` | Maximum message bytes, including assembled fragments; must be 1–262144 (256 KiB). Larger client messages close with 1009 |
| `websocket.send_timeout` | `5.0` | Seconds one send may wait for room, or a broadcast recipient may take to catch up; a slow reader closes with 1008 |
| `websocket.max_connections` | `256` | Open or reserved socket handshakes per worker; excess receives HTTP 503 and `Retry-After: 1` before application code runs |

`server.max_connections` defaults to `512` for HTTP and upgraded connections together. When socket routes exist, their limit must not exceed it; unused socket settings do not constrain an HTTP-only app. `server.loop` defaults to `select`, whose server limit must be at most `900`, leaving descriptors for files, database and Redis. Other open descriptors still consume that budget.

For more connections, install and enable `ev`, `uv` or `event`, choose it with `server.loop`, and raise `server.max_connections`. Missing extensions and unsafe limits are refused before binding a port. A native loop does not remove descriptor or memory limits.

## Sending and receiving

Send arrays as JSON text, strings as text, and `Binary` as binary frames:

```php
use Crab\WebSocket\Binary;

$socket->send(['status' => 'paid']);
$socket->send('ready');
$socket->send(new Binary("\x00\xff"));
$socket->sendBinary("\x00\xff");

$message = $socket->receive();
if ($message instanceof Binary) {
    $bytes = $message->bytes; // Also available as (string) $message.
}
$socket->send($message ?? 'finished');
```

`receive()` waits and returns `array|string|Binary|null`. JSON objects and arrays become arrays; scalars, invalid JSON and ordinary text remain strings. Binary frames always become readonly `Binary` values, even if their bytes look like JSON. The echo loop preserves both kinds without inspecting the type. The engine validates message size and text UTF-8.

Each decoded JSON object may have at most 1,000 members, including repeated names and objects nested inside lists. Exceeding this bound closes the socket with `1009` before the message reaches the action. Lists of smaller objects remain allowed within `websocket.max_message`; ordinary text and binary messages keep their existing behavior.

`null` means the socket ended. Iteration repeats `receive()` until `null`; only one consumer may wait at a time. The readonly `$socket->request` holds the handshake headers, query, route parameters, authenticated user and `ip()`. Call `$socket->accept()` to upgrade without sending; it is harmless after acceptance.

## Closing and cancellation

Close with a protocol code and a UTF-8 reason of at most **123 bytes**, including any multibyte characters:

```php
$socket->close();
$socket->close(1008, 'This subscription ended');
```

Returning normally upgrades if needed, then closes with 1000. Calling `close()` before acceptance also upgrades first, so the browser receives your close code. Sending after close or while closing throws `Crab\WebSocket\SocketClosed` before validating the payload. Let that exception end a send loop; it is an ordinary end.

Pass the socket's token to work that should stop when the connection ends:

```php
use Crab\Contracts\HttpClient\Client;
use Crab\Contracts\WebSocket\Socket;

final readonly class RefreshOrder
{
    public function __construct(private Client $http)
    {
    }

    public function handle(Socket $socket, int $id): void
    {
        $socket->accept();
        $response = $this->http->request(
            'GET',
            "https://orders.example.com/orders/{$id}",
            cancellation: $socket->cancellation(),
        );
        $socket->send($response->json());
    }
}
```

`cancellation()` is cancelled on peer loss, transport cut, your own close, and physical closure during drain. Cancellation from that token is an ordinary action end; cancellation from another token remains a failure. Currently drain initiation itself has no framework notification: cancellation waits for physical closure, sharing `server.drain` with cleanup. The handshake request's token stays live before acceptance and is cancelled when HTTP completes; use the socket token after acceptance.

## Keeping connection state

One action object is shared by every connection of its route in a worker. Use readonly services in its constructor and local variables for connection state:

```php
// Unsafe: another invocation overwrites the shared object property.
$this->id = $id;
foreach ($socket as $message) {
    $socket->send(['order' => $this->id]);
}

// Safe: this invocation keeps its own local parameter.
foreach ($socket as $message) {
    $socket->send(['order' => $id]);
}
```

## Database and slow work

Receive first, then make a short statement or transaction. A pool statement releases its borrow before the next receive:

```php
foreach ($socket as $message) {
    $order = $this->db->table('orders')->where('id', $id)->first();
    $socket->send(['status' => $order['status'] ?? 'missing']);
}
```

Never put the receive loop inside `Database::run()` or `transaction()`; both hold their connection until the callback returns, and a parked socket can exhaust the pool.

Awaited work lets other fibers run but fills this socket's inbox until reads pause and the pause deadline applies. Blocking I/O or CPU work stops the whole worker and its timers; no timeout preempts PHP code. Queue long work and acknowledge it at once, using the [Queue](queues.md) provider and a job whose `handle()` accepts `orderId`:

```php
// In an action with a Queue service in its constructor.
use App\Actions\RebuildOrder;
use Crab\Contracts\Queue\Queue;

foreach ($socket as $message) {
    $job = $this->queue->push(RebuildOrder::class, orderId: $id);
    $socket->send(['queued' => $job]);
}
```

## Limits and slow clients

A message sent by the server has the same `websocket.max_message` byte limit. Oversized outgoing messages throw `LengthException`; invalid text throws `InvalidArgumentException`, and JSON encoding errors throw `JsonException`. An uncaught error after acceptance closes with 1011. Oversized client input instead closes with 1009.

`send()` and `sendBinary()` wait on bounded output. Reaching `websocket.send_timeout` closes with 1008 and throws `SocketClosed`. Success means local acceptance, not client delivery.

An inbox left full pauses reads, including processing pongs and Close. The engine stops its peer-silence clock during that intentional pause. If the action does not consume enough messages to resume within two ping intervals, the framework closes with 1011, cancels the socket token, and logs one warning naming the route. With `websocket.ping = 0`, there is no pause deadline. A send-only action never pauses reads and retains ordinary silent-peer retirement with 1001. Keep awaited work bounded and queue longer work.

## Stop and reload

On `stop`, `reload` or `restart`, old clients receive 1001. Closes spread across at most one second, capped by half the remaining worker allowance. Reconnect with jitter, then fetch current state again.

The receive loop ends when the peer completes Close or the transport closes. The connection owns code after the loop, and the worker waits up to `server.drain`, measured from drain initiation. Upgraded sockets do not count as cut HTTP requests. Allow time for the close handshake and cleanup; an unresponsive peer can use most of that shared allowance.

## Behind a proxy

Terminate TLS at your reverse proxy: clients use `wss://`, and the proxy talks HTTP/1.1 to Crab. Keep its read timeout longer than `websocket.ping`; these examples allow 90 seconds with the default 25-second ping.

For nginx, place the map in its `http` block and the location in your TLS server block. Upgrade headers must be passed explicitly. See [nginx WebSocket proxying](https://nginx.org/en/docs/http/websocket.html).

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    '' close;
}

server {
    listen 443 ssl;
    server_name api.example.com;
    ssl_certificate /path/to/certificate.pem;
    ssl_certificate_key /path/to/private-key.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;
        proxy_read_timeout 90s;
    }
}
```

Caddy handles the Upgrade and Connection headers automatically. Its HTTP transport accepts a backend read timeout. See [Caddy reverse proxy](https://caddyserver.com/docs/caddyfile/directives/reverse_proxy).

```caddyfile
api.example.com {
    reverse_proxy 127.0.0.1:8080 {
        # WebSocket upgrade headers are forwarded automatically.
        transport http {
            versions 1.1
            read_timeout 90s
        }
    }
}
```

nginx's usual request log and Caddy's enabled access log can include the original query string, including `ticket`. Configure proxy logs to omit the query or filter that parameter; consuming it in Crab does not change the proxy's copy. Crab's AccessLog writes only the path, and socket route reports use the declared route pattern. See [Caddy log filtering](https://caddyserver.com/docs/caddyfile/directives/log#filter).

## Testing

Keep `Testing\Api` as your test fixture. It opens the real middleware, action and protocol over socket pairs with no listening port. Its header and user variants share ownership of sockets:

```php
use Crab\Testing\Api;
use Crab\WebSocket\Binary;

$api = new Api($app);
$socket = $api->websocket('/live');
$socket->send(['type' => 'refresh']);
$socket->assertReceived(['type' => 'refresh']);
$socket->send(new Binary("\x00\xff"));
$socket->assertReceived(new Binary("\x00\xff"));
$socket->assertNothingReceived();
$socket->close();
$socket->assertClosed(1000);
```

No `finally` is needed: the Api fixture closes every socket when it leaves scope, even if an assertion fails. A test case holding Api in a property can call `$this->api->closeSockets()` in `tearDown()` to reset it between tests. Keep the fixture alive while using its sockets; an Api temporary ends its sockets when that temporary leaves scope.

`close()` completes or cuts the transport, then waits up to one second for the action and asynchronous cleanup to settle. A failure to settle is a test failure naming the route. It finishes the main-context event loop without cancelling another open test socket's work. `receive(timeout: 1.0)` returns an array, string, Binary, or `null` on timeout or closure; a timeout leaves the connection open. `sendBinary()` and optional close-code assertions work too.

Use `actingAs()`, `withToken()` and `withHeaders()` as for HTTP. An ownership refusal inside `handle()` is carried by `WebSocketRefused`:

```php
use Crab\Testing\WebSocketRefused;

try {
    $api->actingAs($otherUser)->websocket('/orders/42/live');
    self::fail('An ownership refusal upgraded.');
} catch (WebSocketRefused $error) {
    $error->response->assertStatus(403);
}
```

Only an actual wire 101 produces a TestSocket. Use `$api->get()` with handshake headers to assert an HTTP response directly. See [Testing](testing.md) for the API helpers.

## How it works

Each action runs in one connection-local fiber, alongside the request until it offers acceptance. The engine takes over after 101 and owns its protocol and output queues. The framework inbox holds at most 16 raw messages or 256 KiB, pauses before another maximum message could exceed that bound, and resumes below half both bounds. Only its own waiting consumer wakes; one pause timer exists while reads are paused, and one send timer exists only while output is backpressured.
