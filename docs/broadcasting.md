# Broadcasting

- [Introduction](#introduction)
- [Joining channels](#joining-channels)
- [Sending from a job](#sending-from-a-job)
- [Sending after commit](#sending-after-commit)
- [Configuration](#configuration)
- [Delivery and slow clients](#delivery-and-slow-clients)
- [Testing](#testing)
- [Standalone use](#standalone-use)

## Introduction

Broadcast an order update to every socket watching that order. Check ownership, join the channel, and tell the client when joining is complete:

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
        $socket->join("orders.{$id}");
        $snapshot = $this->db->table('orders')->where('id', $id)->select('status', 'version')->first();
        $socket->send(['type' => 'ready', 'id' => $id, 'status' => $snapshot['status'], 'version' => (int) $snapshot['version']]);
        foreach ($socket as $message) {
            // Handle any messages your client sends.
        }
    }
}
```

Register the route, then send updates from a class that injects the broadcaster:

```php
// routes/web.php
use App\Actions\WatchOrder;
use Crab\Contracts\Broadcast\Broadcaster;
use Crab\Route;

Route::websocket('/orders/{id}/live', WatchOrder::class);

// In a class whose constructor takes Broadcaster $broadcast:
$this->broadcast->to('orders.42')->send(['type' => 'status', 'id' => 42, 'status' => 'shipped', 'version' => $version]);
```

Read current state after `join()` returns, either in the ready snapshot above or by fetching it after readiness. Protocol `open` confirms the HTTP upgrade, not the channel join. A newer broadcast can arrive before an older snapshot. Give each order a `version` column starting at 1, increment it with every status change in the same transaction, and include it in every ready snapshot and status update. **Apply a message only if it is newer than what you hold.** For a browser watching one order, keep `let order;` outside its message handler and use these three lines inside it:

```js
const message = JSON.parse(event.data);
if (message.version <= (order?.version ?? 0)) return;
order = message;
```

Render from `order`. Keep its version when reconnecting and use the same comparison for a fetched snapshot. Follow the [ownership and authentication setup](websockets.md#checking-ownership) for `App\User` and its identity resolver.

`App::configure()` includes `Crab\Broadcast\BroadcastProvider`. An application constructed with `new App()` registers it explicitly. Use the Redis carrier when another worker or process sends updates.

## Joining channels

Check access to the order before joining, using the [WebSocket ownership checks](websockets.md#checking-ownership). Channel membership grants delivery; it does not check authorization itself.

```php
$socket->join("orders.{$id}");
$socket->join('announcements');
$socket->leave('announcements');
```

A first `join()` accepts the connection and sends the HTTP 101 before joining. Joining twice and leaving a channel you never joined are harmless. The connection leaves every channel when it ends. Channel names contain 1–200 UTF-8 bytes and no control or formatting characters. Each connection joins at most `broadcast.max_channels`; leave a channel before adding another at the limit.

## Sending from a job

Inject the broadcaster as a service. Jobs, commands, listeners and HTTP actions use the same contract:

```php
// app/Actions/AnnounceShipment.php
namespace App\Actions;

use Crab\Contracts\Broadcast\Broadcaster;

final readonly class AnnounceShipment
{
    public function __construct(private Broadcaster $broadcast)
    {
    }

    public function handle(int $orderId, int $version): void
    {
        $this->broadcast->to("orders.{$orderId}")->send(['type' => 'status', 'id' => $orderId, 'status' => 'shipped', 'version' => $version]);
    }
}
```

Pass the committed shipment's version when queuing this job.

Send to several channels with `to('orders.1', 'orders.2')->send('text')`. Selections are independent; changing one does not change another. Repeating a destination in `to()` sends to that channel once. A socket joined to two selected channels receives the message once for each channel.

Arrays travel as JSON text, encoded once before the carrier receives them. Strings must contain UTF-8 text. Binary broadcasting is not supported; send binary data directly through a socket.

## Sending after commit

Use the transaction's connection, as you do with [queue jobs](queues.md):

```php
use Crab\Contracts\Database\Connection;

$this->db->transaction(function (Connection $transaction) use ($id): void {
    $order = $transaction->table('orders')->where('id', $id)->lockForUpdate()->first();
    $version = (int) $order['version'] + 1;
    $transaction->table('orders')->where('id', $id)->update(['status' => 'shipped', 'version' => $version]);
    $this->broadcast->within($transaction)->to("orders.{$id}")->send(['type' => 'status', 'id' => $id, 'status' => 'shipped', 'version' => $version]);
});
```

The carrier receives the message after the outermost commit. Rollback discards it, including messages from a rolled-back nested transaction. Retry the whole transaction after a deadlock: messages from the failed attempt are discarded. With no active transaction, the send runs immediately. A carrier failure after commit is reported through framework logging and does not throw into the committed caller. This hand-off has no durable outbox; a process crash between commit and publication can lose the update.

## Configuration

Choose the carrier in your configuration:

```php
// config/broadcast.php
return ['carrier' => env('BROADCAST_CARRIER', 'local')];
```

| Setting | Default | Use |
| --- | --- | --- |
| `broadcast.carrier` | `local` | `local` for this process, or `redis` across processes |
| `broadcast.prefix` | `crab:broadcast:` | Prefix of Redis channels; use a different prefix for each application sharing Redis |
| `broadcast.max_channels` | `64` | Maximum joined channels per connection |
| `broadcast.batch` | `128` | Recipient writes before yielding to the event loop |
| `broadcast.max_pending_bytes` | `1048576` | Worker fan-out limit; overflow closes every joined socket in that worker with 1012, including unrelated channels |
| `broadcast.max_backlog_bytes` | `524288` | Bytes allowed behind one recipient's output; overflow closes that recipient with 1008 |
| `websocket.send_timeout` | `5.0` | Seconds a backpressured recipient has to catch up before closing with 1008 |

`local` suits one HTTP worker with sends inside that process. Startup refuses it when socket routes exist and `server.workers` exceeds one or `server.with` includes `queue` or `schedule`. A console sender, including `queue:work` and `schedule:work`, fails with an instruction to choose Redis: it cannot reach another process through local memory.

For `redis`, install `crabphp/redis` and register its `RedisProvider` after the default framework providers. Follow the [Redis package installation example](../../redis/README.md#installation).

Set `BROADCAST_CARRIER=redis` and configure `redis.url`. Joins wait for Redis confirmation; leaves do not delay socket cleanup. Unused broadcasting opens no connection, subscription or timer. Stop and reload release broadcast resources.

To add a third carrier, bind `Crab\Contracts\Broadcast\Carrier` after BroadcastProvider. It carries encoded text, confirms subscriptions, signals lost updates and releases resources without suspending during leave or stop.

## Delivery and slow clients

Broadcasts arrive at most once, with no replay. Messages sent by one sender to one channel retain their order. Success means the carrier accepted the publication, not that every client received it. Sends hand off fan-out in batches and never wait for a slow reader.

A full output buffer can be a healthy burst. Broadcast keeps that recipient's updates in order in a bounded backlog and writes them as output drains. Other recipients continue immediately. Only exceeding `broadcast.max_backlog_bytes` or failing to catch up within `websocket.send_timeout` closes that recipient with **1008**, `Read messages faster`, and increments `Crab\Broadcast\Channels::stats()`'s `slow_clients`. A recipient that keeps up has no backlog or broadcast timer. Socket message-size limits still apply; an oversized update closes the affected socket with 1011 and reports the error.

The 512 KiB backlog default holds a burst of six messages at the default 64 KiB message ceiling, even before output makes progress. Payloads and 256 bytes per queued message count toward the bound, including empty messages. With `websocket.max_connections` at 256, backlogs can account for at most **128 MiB per worker**, in addition to socket output buffers, memberships and the worker fan-out queue. Raise limits with that memory cost in mind.

A Redis connection gap is logged once and closes every joined socket in that worker with **1012**, `Reconnect and refetch state`. Subscription buffer loss does the same. Exceeding `broadcast.max_pending_bytes` also discards the worker's pending fan-out and closes **every joined socket in that worker**, including quiet sockets on unrelated channels. These clients must reconnect and refetch state. A later join uses a fresh Redis subscription after a gap.

Reconnect with jitter and fetch current state after the post-join ready message on every reconnect, including server reload. Treat broadcasts as notifications to refresh state, not a history of every change. A server crash, a Redis outage or a failure after commit may lose an update.

> [!NOTE]
> Internally, a worker shares one Redis subscription, checks gaps every 50 ms, and batches fan-out through a bounded queue. `broadcast.max_pending_bytes` charges each queued channel payload plus 256 bytes; recipient backlogs have their own bound. Blocking application code delays timers, so keep work on the loop short.

## Testing

Exercise the real in-process path through [Testing\Api](testing.md). Your test application's socket action must join the channel, and BroadcastProvider must be registered. For the ownership example, `$user` is the order's owner:

```php
use Crab\Contracts\Broadcast\Broadcaster;
use Crab\Testing\Api;

$api = (new Api($app))->actingAs($user);
$socket = $api->websocket('/orders/42/live');
$socket->assertReceived(['type' => 'ready', 'id' => 42, 'status' => 'paid', 'version' => 1]);
$broadcast = $app->container->get(Broadcaster::class);
$broadcast->to('orders.42')->send(['type' => 'status', 'id' => 42, 'status' => 'shipped', 'version' => 2]);
$socket->assertReceived(['type' => 'status', 'id' => 42, 'status' => 'shipped', 'version' => 2]);
```

Use `local` in this socket-pair test; no network or Redis is needed. To test a job without any sockets, replace the broadcaster with the fake:

```php
use App\Actions\AnnounceShipment;
use Crab\Testing\FakeBroadcaster;

$fake = new FakeBroadcaster();
$app->container->instance(Broadcaster::class, $fake);
$fake->assertNothingSent();
(new AnnounceShipment($fake))->handle(42, 2);
$fake->assertSent('orders.42', ['type' => 'status', 'id' => 42, 'status' => 'shipped', 'version' => 2]);
```

The fake shares records across selections and respects commit and rollback when used with `within()`. It records the message as encoded when `send()` is called, as the real broadcaster would send it, so changing an object afterwards does not change what `assertSent()` sees.

## Standalone use

The broadcaster and memberships work without App, a provider or a container:

```php
use Crab\Broadcast\Broadcaster;
use Crab\Broadcast\Channels;
use Crab\Broadcast\LocalCarrier;
use Crab\WebSocket\Connections;
use Crab\Routing\Router;

$carrier = new LocalCarrier();
$channels = new Channels($carrier);
$connections = new Connections(memberships: static fn () => $channels);
$router = new Router(socketFactory: static fn () => $connections);
$broadcast = new Broadcaster($carrier);
```

Declare your socket routes on `$router`. Close `$channels` when its worker stops. A standalone local carrier assumes your process is the serving process; application wiring enforces the console guard.
