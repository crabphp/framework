# Crab engine architecture

Status: implemented networking foundation on PHP 8.4, with lifecycle checks and reproducible HTTP/WebSocket benchmarks. The earlier experimental source was used as a reference and replaced. The web/framework layer remains separate.

## One scheduler, explicit ownership

The engine runs on its own `EventLoop`, an abstract class: timers, stream and signal watchers, and `Suspension` for waiting fibers. Everything in the engine, and everything built on it, asks for `Crab\Engine\EventLoop` and never for a third-party type. `EventLoop\FiberEventLoop` is the engine's own scheduler, ported from Revolt with its semantics: watcher bookkeeping, timers, callback fibers, suspensions and error routing. A backend extends it with `watch()`, `unwatch()` and `wait()`; `EventLoop\StreamSelectEventLoop` is the pure-PHP one. A callback fiber whose callback suspended becomes a spare when that callback ends, instead of a new fiber being made per suspending callback; that is about 8 µs saved per suspending request. `EventLoop\RevoltEventLoop` extends `EventLoop` on a `revolt/event-loop` driver, and `src/Engine/EventLoop/` is the only code that names Revolt; `tests/EngineBoundaryTest.php` enforces that. There is one scheduler per process: `EventLoop` adds a type, not a second loop.

`StreamSelectEventLoop` is the default; `RevoltEventLoop::create('ev' | 'event' | 'uv')` selects a native one through the runtime's loop factory. That adapter exposes its driver for the one case that needs it, handing it to a library built on Revolt.

Create the loop **after fork**, install it with `EventLoop::install()`, and inject that same instance into networking and async operations. `EventLoop::current()` is the installed loop, for code that outlives a fork and so must not keep one. Installing a `RevoltEventLoop` also makes it Revolt's global driver, so libraries built on Revolt schedule on the same loop. Native extensions are optional for scheduling; supervised Unix processes separately require `pcntl` and `posix`. [Revolt drivers](https://revolt.run/extensions), [Revolt fundamentals](https://revolt.run/fundamentals)

~~~mermaid
flowchart TD
    R[Runtime] --> G[Crab: endpoints and limits]
    G --> S[Supervisor: process lifecycle]
    G -. single process .-> W[Worker: resource ownership]
    S --> W
    W --> D[One EventLoop]
    D --> SD[StreamSelectDriver default]
    D -. optional .-> ND[Native driver]
    W --> L[Listener and Connector]
    L --> C[Connection: bytes and backpressure]
    W --> U[UdpEndpoint: packets and counters]
    C --> H[HttpSession]
    H --> HA[Request handler]
    H -. upgrade with unread bytes .-> WS[WebSocketSession]
    C --> RAW[Raw TCP handler]
    C --> CS[Exclusive coroutine ConnectionStream]
    W --> T[Bounded tracked Tasks]
~~~

| Owner | Responsibility |
| --- | --- |
| `Runtime` | Validate mode, bind endpoints, launch runner, close bindings on rollback |
| `Crab` | Frozen startup configuration, endpoint and admission limits |
| `Supervisor` | Worker slots, readiness, backoff, control channels, hard deadlines |
| `Worker` | One loop, connection/task registries, admission, bootstrap and drain |
| `Listener / Connector` | Establish streams and transfer ownership once |
| `Connection` | Byte I/O, watchers, bounded FIFO output, directional close state |
| `UdpEndpoint` | Datagram I/O, packet/byte limits, drop/error counters |
| `HttpSession` | Incremental parser, request lifetime, ordered responses and upgrade |
| `Handshake / WebSocketSession` | Deferred upgrade admission, ordered messages, controls, output waits and deadlines |
| `Task / Cancellation / ConnectionStream` | Cooperative application operations on the same driver |

Server classes install protocol handlers; they do not own another runtime. HTTP and WebSocket load through ordinary autoloading when selected. There is no router, container, ORM or generic plugin registry in the core.

## Processes and lifetime

A group serves several TCP listeners and UDP endpoints, or only bootstrap tasks/timers. Processes belong to groups, not individual listeners. State is process-local. Initialize application clients during `onStart(Worker)`, after fork.

Startup binds sockets in the parent, forks workers, closes unrelated inherited descriptors, installs each worker's driver, creates endpoint owners, runs bootstrap callbacks, then reports readiness. Bind failure closes earlier bindings. Child bootstrap failure exits and enters bounded restart/backoff handling.

The parent never runs application callbacks or an application event loop. It monitors control channels and child exits independently, so replacement forks happen outside a running Fiber. Raw signal handlers set flags; lifecycle mutations happen at safe supervisor boundaries.

Admission counts accepted and pending outbound connections together, including closed connections whose service cleanup is still running. At capacity the worker disables accept watchers and resumes them when capacity returns. Tasks have a separate cap. Defaults are 512 owned connections/pending connects and 1,024 tasks per worker.

A group's `onStop(Worker)` callbacks run in a worker after it has drained or reached its deadline, in reverse order of registration, and only when its start callbacks all completed; the loop has stopped by then, so they must not suspend. `onFailure(Throwable, Worker)` receives a failed task, a throwing stop callback, and a failure that escapes a timer or watcher callback; without one the first two go to PHP's error log and the last ends the worker.

SIGINT/SIGTERM stop admission and initiate drain. HTTP finishes its active response, WebSocket starts a close handshake, raw TCP flushes accepted output, and UDP finishes its bounded local send queue. Pending connects and worker tasks receive cancellation. The worker cancels its watchers when it exits.

Worker and parent enforce the shutdown deadline, default five seconds. The parent can kill and reap a blocked child; single-process deadlines are cooperative. A worker can also ask its parent for a deadline of its own with `Worker::deadline($seconds)`, sent over the control stream as the earliest one not withdrawn; the parent kills a child still running past it, reports it, and restarts the slot as after any unexpected exit. It is the bound for work that may block PHP, such as a queue job past its timeout; alone, without a parent, it does nothing. A control/wakeup stream keeps intended live workers alive without a polling statistics timer.

Unexpected exits receive exponential backoff and at most five restarts per slot in sixty seconds. Readiness has a ten-second deadline. SIGUSR1 starts one replacement at a time, draining its predecessor only after readiness. A failed replacement preserves a healthy previous worker. Fresh-code deployment requires a new parent because loaded classes survive fork.

`Process\Keeper` replaces a parent without refusing a connection. It binds the address once, starts a parent (a generation) with the socket as descriptor 3 and a pipe to report on as descriptor 4, and on request starts another on the same socket; `Crab::listen(socket:)` and `Process\Generation` are the other side. Old and new accept from one queue, and the keeper retires the old only when `Runtime::onReady()` in the new has said every worker accepts connections. A parent that is stopping closes its own copy of the listening sockets at once, since it will start no more workers.

Two servers binding one address with `SO_REUSEPORT` would be simpler and was measured and turned down: on Linux each socket has its own queue, and connections queued on the one that closes are reset unless `net.ipv4.tcp_migrate_req` is on. A parent starting its own successor was turned down too: a process started with `proc_open` inherits every descriptor its parent has open and PHP cannot close them by number, so each generation would carry the descriptors of all before it. The keeper opens next to nothing, and generations are siblings.

A generation stops by itself when its keeper has gone, checked every two seconds through `Runtime::onSignal()` and an alarm, because a server no keeper knows of could be neither reloaded nor stopped. `onSignal()` callbacks run in the parent's own loop, not inside the signal, and workers ignore those signals.

An HTTP connection that is idle when its worker starts to drain is read for 0.2 seconds more before it is closed. A client cannot know the server is stopping, so a request may already be on its way to a connection just accepted or to a kept-alive one being used again; it is answered, with `Connection: close`, like any last request. With this and the shared queue, a client opening about 13,000 connections a second through ten reloads in a row lost none of some 100,000 requests, on Linux 7.0 and macOS 26.

`Process\Daemon` puts a process in the background and lets its launcher wait for it to report: two forks and a session of its own, with standard input closed and standard output and error appended to a file. `Process\PidFile` is the file a background server names itself in. `Process\FileWatcher` notices changed source files by looking at them again, with no extension or operating system service. None of these is used unless asked for.

## Failure reporting

Every `catch` in the engine that swallows a failure passes it to `FailureReporter::report()`; there is no bare catch and no direct `error_log()`. A worker whose group has failure callbacks installs them as the reporter for its lifetime and restores the previous one when it ends. Otherwise the report goes to standard error with its trace, which does not depend on how `php.ini` routes PHP's own log. A protocol owner reports through `Connection::reportFailure()`, so a connection with its own error handler keeps it. Client errors are answered and never reported.

Workers ignore SIGUSR1, the parent's reload signal, whose default action would end them.

## Byte transport and backpressure

TCP uses nonblocking PHP streams, direct writes when the queue is empty, and a chunk FIFO with a head offset for partial writes. Writable interest exists only while output remains. Each read/write turn has a 256 KiB budget; reads are at most 64 KiB each. A short read ends the turn while preserving readiness for subsequent data or EOF. Read interest is reconciled once when a read turn ends, so a consumer that pauses and resumes within one turn costs no driver registration work; a paused or suspended turn that sees readiness disables its watcher then. Accept batches are bounded at 64.

`tryWrite(bytes)` is atomic at admission: accepted locally, temporarily rejected without consuming a prefix, or closed. It checks byte and entry caps before writing. An individually oversized call throws. Defaults are 1 MiB and 4,096 output entries.

Capacity notifications are deferred after queue mutation and coalesced. They follow progress even when a rejected producer never crossed the half-capacity threshold. Producers must retry and check the result; notification does not reserve capacity. Local write acceptance is not peer acknowledgement.

Read EOF and write FIN are independent. `shutdownWrite()` preserves the read direction; `end()` flushes then closes; `close()` releases registrations/resources before notifications. Half-close and drain have deadlines.

There is one data consumer, additive close/capacity observers, and one protocol drain hook. Low-level application callbacks must not suspend. Asynchronous services use the coroutine layer. Transport has no protocol-specific parser or routing logic.

Physical close and service completion are separate: `onClose()` follows socket cleanup; `onSettled()` waits until all `beginActivity()` scopes have released as well. HTTP brackets handlers and response producers in these scopes, so asynchronous `finally` blocks remain owned after socket closure. Worker admission and drain retain the connection until settlement; the worker deadline bounds cleanup that does not finish.

## Protocol modules

### UDP

Datagrams preserve packet boundaries and valid empty payloads. A full-sized receive precedes application size validation, so oversized packets are dropped whole. Receive/send work is bounded at 64 packets per turn. Pending output has byte and packet caps.

Destinations are numeric IPv4/IPv6 addresses and valid ports. `send()` means local acceptance. Queue rejection, oversized input and failed sends appear in counters. Pausing reception does not backpressure senders, counters cannot detect all network loss, and shared sockets do not establish peer affinity. [UDP](https://www.rfc-editor.org/rfc/rfc768.html)

### HTTP/1.1

Incremental parsing validates request lines, fields and framing: fixed lengths, chunked bodies, bounded extensions and trailers. Duplicate Content-Length, TE/CL ambiguity, invalid Host, obsolete folding and prohibited trailer fields are rejected. Persistent and pipelined requests execute sequentially per connection. The first request of a read turn runs inside that turn; pipelined successors each take a later scheduler turn. A read turn admits no further input until it ends, even while its handler is suspended, so the session pauses reading only when output outlives the turn or the request runs from a later turn. Every head is validated in full before its handler runs; the parser extracts only the fields that decide framing and connection handling, and the complete field map is built when a handler first reads it.

Bodies of up to 64 KiB are kept in a string, so the usual small body opens no stream; a larger one moves to a temporary spool that spills to disk, as does any body whose `stream()` is asked for. Empty bodies allocate nothing. A bodyless request creates its empty body, trailer map and cancellation token only when a handler reads them. Handlers start after complete framing. This bounds upload memory, but ordinary temporary-file I/O may block the worker. `Body::chunks()` streams a completed body afterward. Live handler reads during upload are not yet exposed.

Strings receive Content-Length. Iterable responses use checked explicit lengths or chunked encoding and advance only as output capacity permits. HEAD/bodyless status semantics are explicit. Small fixed responses combine headers and body in one bounded write; larger responses retain streaming. Small responses without explicit framing, Connection, Date or Upgrade fields take a one-pass encoder that produces the same bytes as the general one. The fields of `Response::text()` and `Response::json()` are precomputed when no others are added, and a string returned by a handler is encoded directly.

Generated Date formatting is cached per observed wall-clock second, independently of monotonic deadlines. Reused Response objects retain normalized headers after two successful normalizations only when their header arrays contain no PHP references. Referenced headers are revalidated on every encode; new responses avoid reference scanning. Framing, generated headers and body iteration remain specific to each encoding.

Header, body, idle and response deadlines bound slow peers and cooperative handlers. The response deadline is restarted by each write a streamed body makes, so it bounds a stall, not a stream's length. Request completion/teardown triggers cancellation; handlers must pass its token into async waits. Timers cannot preempt blocking PHP or code ignoring cancellation.

Each HTTP session tracks its current monotonic expiry and reuses an earlier timer wake-up across phase changes. An earlier phase deadline replaces a later timer; a wake-up checks the current expiry before acting. Partial body arrivals retain the phase's absolute deadline.

Upgrade transfers the connection, unread bytes and read-interest ownership once, after the 101 response enters ordered output. HTTP retires its parser/timers before handing over. [HTTP/1.1 framing](https://www.rfc-editor.org/rfc/rfc9112.html), [HTTP semantics](https://www.rfc-editor.org/rfc/rfc9110.html)

See [the HTTP contract](Http/README.md) for precise limits and supported semantics.

### WebSocket

`WebSocket\Handshake` validates the HTTP handshake, with optional application authentication/Origin policy and subprotocol selection. Its immutable response can be checked before application admission finishes; `upgrade()` transfers the connection later. The standalone server uses that same handshake. Sessions validate masking, opcodes, RSV bits, canonical lengths, controls, fragments and UTF-8.

Frame/message size, buffered input, queued output bytes/frames and per-turn processing are bounded. One message handler runs per connection at a time and may suspend. Parsing pauses transport reads until it returns, retains buffered successors in order, and owns a service activity through cleanup. Buffered work continues on a later turn; application pause also pauses buffered dispatch. Controls can run between complete outbound frames, and partial writes never interleave frame bytes.

Text/binary sends report pressure explicitly. Binary chunk sends provide message streaming. `awaitWritable()` adds cancellable, bounded threshold waits without polling or permanent callback subscriptions; closing wakes every waiter. While waiters exist, the session observes every partial transport flush by replacing its writable callback with a progress wrapper. The final waiter restores the ordinary callback. Connections without observers keep their read, direct-write and queued-flush paths unchanged. Ping payloads are echoed in Pong frames. Optional server pings and idle expiry share one reusable timer with the close handshake and drain stagger; received bytes move the silence deadline, sent pings never do. Silence advances only while reading. Application pauses and suspended handlers stop heartbeat expiry; resuming begins a new interval. The caller bounds these pauses, which are not timed out. An earlier timer may wake once while paused, without sending a ping or closing, and no heartbeat timer is rearmed until resume. Pings and idle expiry are off by default; enabling only pings gives an idle timeout of two intervals. Drain rejects writes immediately and staggers 1001 over at most one second (or a shorter close timeout), capped at half the remaining transport worker allowance to leave time for the handshake. The transport worker deadline cancels any remaining stagger; no delayed close survives it. WebSockets report no pending HTTP request for drain accounting. Compression/extensions are not negotiated. [WebSocket](https://www.rfc-editor.org/rfc/rfc6455.html)

## Fibers, cancellation and parallelism

The loop executes callbacks in reusable Fibers; transport adds no separate Crab Fiber per packet. `Task::spawn()` uses that scheduler. `Worker::spawn()` additionally applies admission and lifecycle ownership. Results/failures are observable and worker-owned failures are reported.

`Async::sleep()` and `ConnectionStream` use loop suspensions. A stream has at most one waiting reader and writer, demand-driven reads and bounded read-ahead. Writes return after bytes reach the local socket. Raw/protocol consumers cannot share its reader.

`Pool` bounds a set of reusable resources. A caller beyond the limit suspends in a FIFO queue; release hands the resource straight to the longest waiter, so it never becomes idle in between. A place is reserved while a resource is being made, a failed creation frees it, and a timed-out or cancelled waiter leaves no registration behind.

Cancellation removes operation registrations before completion. Connect cancellation closes the pending socket. Read cancellation preserves the connection and buffered data. Cancelling a write after bytes have committed closes the connection because transmission cannot be withdrawn. Cancelling an await does not cancel its independently owned target.

Cancellation state is independent of its exception. A default exception is created on first observation and then shared by observers; its trace starts at that observation. Explicit reasons retain their identity or original cause. Ordinary unobserved request completion avoids allocating an exception.

Cancellation is cooperative. Worker tasks remain tracked until they finish, including cleanup. Blocking PHP operations still block a worker. Fibers create concurrency; processes create CPU parallelism. Arbitrary cross-process job execution is not implemented. [PHP Fibers](https://www.php.net/manual/en/language.fibers.php)

## Performance and evidence

The fast path is readiness → bounded read → one protocol owner → handler → capacity check → direct write or bounded queue, without a deferred callback, closure or generator per request. It has no service-container lookup, per-packet logging or parent-process coordination. Header-cache eligibility uses PHP's array-reference inspection when a Response is reused; ordinary fresh responses and established caches skip that inspection.

Keep optimizations only after real socket checks and matched measurements. HTTP/WebSocket harnesses preserve raw trials, latency, errors, environment and source hashes, and serialize host load. Exact change patches make initial before/after experiments inspectable.

[BENCHMARKS.md](../../BENCHMARKS.md) records results and limits. Short closed-loop trials with client/server on the same machine do not establish production saturation, internet tail latency, or superiority over other engines.

`Worker::stats()` exposes active/pending connections, tasks, queued transport bytes, PHP memory and drain state. UDP exposes packet/error/drop counters. Queries stay outside the packet path; no metrics exporter is required.

Select retains platform descriptor limits and scanning costs; application files and body spools consume descriptors too. Native drivers are the extension point when a measured workload outgrows select.

## Acceptance and further scope

Tests cover TCP partial writes/half-close; connector refusal/cancellation; UDP boundaries/accounting; coroutine exclusion/cancellation/cleanup; HTTP framing/order/pressure/deadlines/upgrade; WebSocket handshake/fragments/malformed input/pressure/close; worker admission/bootstrap rollback/restart/blocked-child termination/reaping; and all four servers sharing one runtime.

Separate work: TLS, generic line/length framing helpers, live uploads, a cross-process job pool, HTTP/2 or HTTP/3, long-duration distributed-load qualification, and the web/agent framework. These are not hidden dependencies or advertised implemented features.
