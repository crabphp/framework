# Crab engine architecture

Status: implemented networking foundation on PHP 8.4, with lifecycle checks and reproducible HTTP/WebSocket benchmarks. The earlier experimental source was used as a reference and replaced. The web/framework layer remains separate.

## One scheduler, explicit ownership

Use `revolt/event-loop` directly. `StreamSelectDriver` is the explicit default; a driver factory selects an optional backend. Revolt supplies readiness callbacks, timers and Fiber suspensions. Crab has no duplicate event-loop interface or second scheduler.

Create the driver **after fork**, install it with `Revolt\EventLoop::setDriver()`, and inject that same instance into networking and async operations. Native extensions are optional for scheduling; supervised Unix processes separately require `pcntl` and `posix`. [Revolt drivers](https://revolt.run/extensions), [Revolt fundamentals](https://revolt.run/fundamentals)

~~~mermaid
flowchart TD
    R[Runtime] --> G[WorkerGroup: endpoints and limits]
    G --> S[Supervisor: process lifecycle]
    G -. single process .-> W[Worker: resource ownership]
    S --> W
    W --> D[One Revolt Driver]
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
| `WorkerGroup` | Frozen startup configuration, endpoint and admission limits |
| `Supervisor` | Worker slots, readiness, backoff, control channels, hard deadlines |
| `Worker` | One driver, connection/task registries, admission, bootstrap and drain |
| `Listener / Connector` | Establish streams and transfer ownership once |
| `Connection` | Byte I/O, watchers, bounded FIFO output, directional close state |
| `UdpEndpoint` | Datagram I/O, packet/byte limits, drop/error counters |
| `HttpSession` | Incremental parser, request lifetime, ordered responses and upgrade |
| `WebSocketSession` | Frames/messages, controls, output admission and close handshake |
| `Task / Cancellation / ConnectionStream` | Cooperative application operations on the same driver |

Server classes install protocol handlers; they do not own another runtime. HTTP and WebSocket load through ordinary autoloading when selected. There is no router, container, ORM or generic plugin registry in the core.

## Processes and lifetime

A group serves several TCP listeners and UDP endpoints, or only bootstrap tasks/timers. Processes belong to groups, not individual listeners. State is process-local. Initialize application clients during `onStart(Worker)`, after fork.

Startup binds sockets in the parent, forks workers, closes unrelated inherited descriptors, installs each worker's driver, creates endpoint owners, runs bootstrap callbacks, then reports readiness. Bind failure closes earlier bindings. Child bootstrap failure exits and enters bounded restart/backoff handling.

The parent never runs application callbacks or an application Revolt loop. It monitors control channels and child exits independently, so replacement forks happen outside a running Fiber. Raw signal handlers set flags; lifecycle mutations happen at safe supervisor boundaries.

Admission counts accepted and pending outbound connections together, including closed connections whose service cleanup is still running. At capacity the worker disables accept watchers and resumes them when capacity returns. Tasks have a separate cap. Defaults are 512 owned connections/pending connects and 1,024 tasks per worker.

A group's `onStop(Worker)` callbacks run in a worker after it has drained or reached its deadline, in reverse order of registration, and only when its start callbacks all completed; the loop has stopped by then, so they must not suspend. `onFailure(Throwable, Worker)` receives a failed task or a throwing stop callback; without one these go to PHP's error log.

SIGINT/SIGTERM stop admission and initiate drain. HTTP finishes its active response, WebSocket starts a close handshake, raw TCP flushes accepted output, and UDP finishes its bounded local send queue. Pending connects and worker tasks receive cancellation. The worker cancels its watchers when it exits.

Worker and parent enforce the shutdown deadline, default five seconds. The parent can kill and reap a blocked child; single-process deadlines are cooperative. A control/wakeup stream keeps intended live workers alive without a polling statistics timer.

Unexpected exits receive exponential backoff and at most five restarts per slot in sixty seconds. Readiness has a ten-second deadline. SIGUSR1 starts one replacement at a time, draining its predecessor only after readiness. A failed replacement preserves a healthy previous worker. Fresh-code deployment requires restarting the parent because loaded classes survive fork.

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

Bodies allocate a bounded temporary spool on the first nonempty append, spilling beyond 64 KiB; empty bodies allocate no stream. A bodyless request creates its empty body, trailer map and cancellation token only when a handler reads them. Handlers start after complete framing. This bounds upload memory, but ordinary temporary-file I/O may block the worker. `Body::chunks()` streams a completed body afterward. Live handler reads during upload are not yet exposed.

Strings receive Content-Length. Iterable responses use checked explicit lengths or chunked encoding and advance only as output capacity permits. HEAD/bodyless status semantics are explicit. Small fixed responses combine headers and body in one bounded write; larger responses retain streaming. Small responses without explicit framing, Connection, Date or Upgrade fields take a one-pass encoder that produces the same bytes as the general one. The fields of `Response::text()` and `Response::json()` are precomputed when no others are added, and a string returned by a handler is encoded directly.

Generated Date formatting is cached per observed wall-clock second, independently of monotonic deadlines. Reused Response objects retain normalized headers after two successful normalizations only when their header arrays contain no PHP references. Referenced headers are revalidated on every encode; new responses avoid reference scanning. Framing, generated headers and body iteration remain specific to each encoding.

Header, body, idle and response deadlines bound slow peers and cooperative handlers. Request completion/teardown triggers cancellation; handlers must pass its token into async waits. Timers cannot preempt blocking PHP or code ignoring cancellation.

Each HTTP session tracks its current monotonic expiry and reuses an earlier timer wake-up across phase changes. An earlier phase deadline replaces a later timer; a wake-up checks the current expiry before acting. Partial body arrivals retain the phase's absolute deadline.

Upgrade transfers the connection, unread bytes and read-interest ownership once, after the 101 response enters ordered output. HTTP retires its parser/timers before handing over. [HTTP/1.1 framing](https://www.rfc-editor.org/rfc/rfc9112.html), [HTTP semantics](https://www.rfc-editor.org/rfc/rfc9110.html)

See [the HTTP contract](Http/README.md) for precise limits and supported semantics.

### WebSocket

The server validates the HTTP handshake, with optional application authentication/Origin policy and subprotocol selection. Sessions validate masking, opcodes, RSV bits, canonical lengths, controls, fragments and UTF-8.

Frame/message size, buffered input, queued output bytes/frames and per-turn processing are bounded. Buffered work continues on a later turn; application pause also pauses buffered dispatch. Controls can run between complete outbound frames, and partial writes never interleave frame bytes.

Text/binary sends report pressure explicitly. Binary chunk sends provide message streaming. Ping payloads are echoed in Pong frames; graceful close has a handshake deadline. Automatic heartbeat scheduling and pong deadlines are application policy. Compression/extensions are not negotiated. [WebSocket](https://www.rfc-editor.org/rfc/rfc6455.html)

## Fibers, cancellation and parallelism

Revolt executes callbacks in reusable Fibers; transport adds no separate Crab Fiber per packet. `Task::spawn()` uses that scheduler. `Worker::spawn()` additionally applies admission and lifecycle ownership. Results/failures are observable and worker-owned failures are reported.

`Async::sleep()` and `ConnectionStream` use Revolt suspensions. A stream has at most one waiting reader and writer, demand-driven reads and bounded read-ahead. Writes return after bytes reach the local socket. Raw/protocol consumers cannot share its reader.

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

Separate work: TLS, async DNS, generic line/length framing helpers, live uploads, a cross-process job pool, HTTP/2 or HTTP/3, long-duration distributed-load qualification, and the web/agent framework. These are not hidden dependencies or advertised implemented features.
