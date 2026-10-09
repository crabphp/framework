# Crab Engine

A PHP 8.4 networking engine with TCP, UDP, HTTP/1.1, WebSocket, coroutines, timers, and supervised worker processes.

The engine has its own event loop type, `Crab\Engine\EventLoop`, which everything is written against; `EventLoop::current()` is the loop of the running process. `RevoltEventLoop` extends it on `revolt/event-loop`, the only Composer runtime dependency, and is the only code that names Revolt. The default backend is pure PHP stream-select. Swoole and OpenSwoole are not required. A native backend is chosen through the loop factory: `new Runtime(loopFactory: fn () => RevoltEventLoop::create('uv'))`.

## Run it

~~~sh
composer install
php bin/crab examples/servers.php --single
~~~

The example starts HTTP on `127.0.0.1:8080`, WebSocket on `:8081`, TCP echo on `:8082` and UDP echo on `:8083`.

~~~sh
curl http://127.0.0.1:8080/
websocat ws://127.0.0.1:8081/
~~~

For parallel workers on Unix, enable PHP's `pcntl` and `posix` extensions and omit `--single`:

~~~sh
CRAB_WORKERS=4 php bin/crab examples/servers.php
~~~

Send SIGINT or SIGTERM to the parent for bounded graceful shutdown. SIGUSR1, sent to the parent, starts replacement workers one at a time and waits for readiness before draining their predecessors. Classes already loaded into the parent are inherited, so deploying changed PHP classes requires a new parent.

A `Process\Keeper` replaces the parent without refusing a connection. It holds the listening socket and starts each parent, a generation, on it:

~~~php
// The service: binds the address, starts "php server.php", and stays.
$keeper = new Keeper(new PidFile('/run/app.pid'), '/var/log/app.log');
exit($keeper->run('tcp://0.0.0.0:8080', ['server.php'], __DIR__, static fn () => print("up\n")));

// server.php, the generation: listens on the socket the keeper passed on.
$generation = Generation::inherit();
$runtime->group('web', workers: 2)->listen('tcp://0.0.0.0:8080', $server, socket: $generation->socket);
$generation->attach($runtime);
$runtime->run();

// A deploy: a new generation, and the old one retired once the new one accepts connections.
Keeper::request($pidFile, $pidFile->pid(), 'tcp://0.0.0.0:8080', ['server.php'], __DIR__, 20.0);
~~~

`Process\Daemon::start($logFile)` puts the calling process in the background and lets the launcher `await()` its `ready()` or `fail()`; `Process\PidFile` records and finds a background server; `Process\FileWatcher` reports changed source files for a development restart. The framework's `serve --daemon`, `reload`, `stop` and `serve --watch` commands are built from these.

## Minimal HTTP server

Save a configuration file that returns a `Runtime`:

~~~php
<?php

use Crab\Engine\Http\Request;
use Crab\Engine\Http\Response;
use Crab\Engine\Runtime;
use Crab\Engine\Server\HttpServer;

$runtime = new Runtime();
$runtime->group('web', workers: 2, maxConnections: 512)
    ->listen('tcp://127.0.0.1:8080', new HttpServer(
        static fn(Request $request) => Response::text("Hello\n"),
    ));

return $runtime;
~~~

Run `php bin/crab server.php`. A group can serve several listeners; two workers and three listeners still means two serving processes. Separate groups isolate workloads in different processes.

HTTP provides strict request framing, keep-alive, ordered pipelining, chunked bodies, streaming responses, request cancellation, and configurable deadlines. Request bodies are incrementally parsed into a bounded temporary spool **before** the handler runs; live upload consumption is not exposed yet. See [HTTP behavior](src/Http/README.md).

## WebSocket and datagrams

~~~php
use Crab\Engine\Network\UdpEndpoint;
use Crab\Engine\Network\WriteResult;
use Crab\Engine\Server\WebSocketServer;
use Crab\Engine\WebSocket\MessageType;
use Crab\Engine\WebSocket\WebSocketSession;

$group = $runtime->group('messages');
$group->listen('tcp://127.0.0.1:8081', new WebSocketServer(
    static function (WebSocketSession $session, string $data, MessageType $type): void {
        $result = $type === MessageType::Text
            ? $session->sendText($data)
            : $session->sendBinary($data);
        if ($result === WriteResult::Backpressured) {
            $session->close(1013, 'Slow receiver');
        }
    },
));
$group->udp('udp://127.0.0.1:8083',
    static fn(UdpEndpoint $socket, string $packet, string $peer) => $socket->send($packet, $peer),
);
~~~

WebSocket supports masking validation, fragmented text/binary messages, ping/pong, UTF-8 checks, close handshakes, subprotocol selection and an application admission callback. Configure bounds with `WebSocket\Config`. `sendBinaryChunk()` streams a fragmented binary message; `pauseRead()` also pauses dispatch of already-buffered messages. Protocol extensions such as per-message compression are not negotiated.

UDP preserves packet boundaries and empty packets. `send()` reports local acceptance, never delivery. Queue overflow rejects the packet; counters expose application drops and send errors. Multiple workers sharing one UDP endpoint do not guarantee peer affinity.

## Coroutines and timers

~~~php
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Process\Worker;

$runtime->group('jobs', maxTasks: 128)->onStart(
    static function (Worker $worker): void {
        $worker->spawn(static function (Cancellation $cancel) use ($worker): void {
            while (true) {
                Async::sleep($worker->loop, 1.0, $cancel);
                // Perform nonblocking work here.
            }
        });
    },
);
~~~

`Worker::spawn()` tracks tasks until they exit and requests cancellation during shutdown. A group's `onStop()` callbacks run in each worker after it drains, for releasing what `onStart()` opened, and `onFailure()` receives a task that failed, which otherwise goes to PHP's error log, and a failure that escapes a timer callback, which otherwise ends the worker. Tasks return results through `await()`; cancellation of an await only cancels that wait. Pass the supplied token into async operations. HTTP handlers receive `$request->cancellation` for the same purpose. `php bin/crab examples/coroutines.php --single` runs a complete task example.

Use the worker's loop for timers: `$worker->loop->delay($seconds, $callback)`, `repeat()`, and `cancel($id)`. It is a `Crab\Engine\EventLoop`; `EventLoop::current()` returns the same one where no worker is at hand. `Async\Pool` is a bounded set of reusable resources such as client connections: `acquire()` makes one on demand up to the limit and otherwise waits, first come first served, until `release()`, a timeout or cancellation; `discard()` replaces a broken one and `run()` lends one for a callback. `Async\ConnectionStream` gives one coroutine reader and one writer exclusive ownership of a TCP connection, with demand-driven reads and bounded writes. It must not share ownership with a protocol parser or raw data handler.

Fibers provide concurrency within a worker. Worker processes provide CPU parallelism. Blocking database clients, ordinary filesystem calls, DNS lookups made with PHP's own functions, or CPU loops still block their worker; arbitrary PHP functions do not become asynchronous.

## Failures

Nothing the engine catches is dropped. A handler, callback or task that throws is reported through `FailureReporter`, as is what the supervisor notices about its workers:

- A group with `onFailure(fn (Throwable $error, Worker $worker) => ...)` receives every failure caught in its workers: HTTP and WebSocket handlers, streamed responses that fail part-way, connection, accept and UDP callbacks, tasks, timers and stop callbacks.
- Without one, and for everything the parent process reports, the failure is written to standard error with its class, message and trace.
- A malformed request is answered (400, 413, 431 and so on) and is not a failure.

An HTTP handler that throws is answered with 500; one that fails after its response has started has its connection closed, so the client does not mistake a partial response for a complete one. `FailureReporter::handleWith()` replaces the destination for a whole process, which is how tests collect reports.

## Transport contracts

`Network\Connection` transports bytes and does not know HTTP or WebSocket. `tryWrite()` returns:

| Result | Meaning |
| --- | --- |
| `WriteResult::Accepted` | All supplied bytes were accepted locally, possibly queued |
| `WriteResult::Backpressured` | No bytes accepted; pause production and retry after `onDrain()` |
| `WriteResult::Closed` | No more writes are accepted |

An individually oversized write throws `LengthException`. `onDrain()` signals a chance to retry; it does not reserve capacity. `shutdownWrite()` flushes then sends FIN while retaining the read direction; `end()` flushes and closes; `close()` releases resources immediately. Low-level application connection, UDP, cancellation and WebSocket callbacks must not suspend. Use tracked tasks for asynchronous application work.

`onClose()` runs after socket cleanup. `onSettled()` additionally waits for service activity to finish, including asynchronous `finally` cleanup. HTTP owns these activity scopes automatically. Custom protocol owners can use `beginActivity()` and call its returned release closure in `finally`; a closed connection remains in worker admission accounting until all activities release it.

`Worker::connect()` establishes outbound TCP connections with cancellation, timeouts, admission accounting and exactly-once completion. It accepts numeric IPv4/IPv6 endpoints. `Network\DnsResolver::resolve($loop, $host)` finds a name's addresses first, without holding the worker: it reads the hosts file, keeps answers for as long as they hold, asks the system's name servers over UDP, and only when they give nothing falls back on the system's blocking resolver.

## Bounds and scope

Defaults include 512 owned TCP connections/pending connects (including closed connections still running service cleanup) and 1,024 tracked tasks per worker; 1 MiB and 4,096 entries per TCP output queue; and bounded protocol input/output buffers. Configure lower limits for the application's memory budget. These bounds do not cap memory allocated independently by application code.

The select backend has platform descriptor limits and scanning costs. Linux/macOS CLI are the intended targets; this change was exercised on macOS. Single-process operation does not require process extensions, but cannot forcibly interrupt blocked PHP code. Optional backends are selected through `new Runtime(loopFactory: ...)`, and a loop of your own is any class that extends `Crab\Engine\EventLoop`.

TLS, HTTP/2, HTTP/3, generic framed-protocol helpers, a cross-process job pool, and the later web framework are outside this implementation. HTTP and WebSocket are protocol servers without routing, middleware containers, ORM, templates or agent-specific features.

This replaces the experimental `Crab::run()/Server::listen()` API. The sibling application scaffold is a separate future layer.

## Manual checks

Two checks are run by hand, never as part of `composer test`.

**Linux.** Builds a Linux image from the working tree with Docker and runs the suite inside it, once as shipped and once with tracing JIT in random order:

~~~sh
composer test:linux
~~~

**Soak.** Cycles mixed traffic against one HTTP and one WebSocket server for as long as asked: persistent requests, one connection per request, request bodies, streamed megabyte responses, WebSocket echoes and misbehaving clients. After each cycle it samples resting memory and open descriptors, and it fails if a server exits, answers with a wrong status, keeps descriptors open or keeps growing. It needs `wrk` and Node.js. Minutes prove little; run it for hours:

~~~sh
composer soak -- --label=overnight --minutes=480
~~~

The report is written to `benchmarks/results/soak-<label>.json`.

## Verify and measure

~~~sh
composer test
composer test -- --filter HttpTest
python3 benchmarks/http.py --label local-http
python3 benchmarks/ws-run.py --label local-ws
~~~

PHPUnit 13 is a development dependency, omitted by `composer install --no-dev`. Tests exercise real sockets, malformed frames, partial writes, cancellation, all four servers together, worker failure, rolling restart, and shutdown. Individual cases are discoverable and filterable through PHPUnit. Process-specific tests skip when their required extensions are unavailable; the single-process tests still run. Benchmarks preserve raw results and environment metadata and serialize load generation on this host.

See [architecture](ARCHITECTURE.md) and [measurements and methodology](BENCHMARKS.md). Performance claims refer to measured workloads; this is a tested foundation, not an assertion of universal maximum throughput or completed production qualification.
