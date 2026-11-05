# Engine benchmarks

These pages were written while the engine was its own repository. Its source is now `src/Engine/` and its tests `tests/Engine/`; the harnesses use those paths. Older pages and the patches in `benchmarks/changes/` still name `src/...` and `engine/`: read `src/` as `src/Engine/`, and apply a patch to a copy of `src/Engine` with `-p2`.

See [HTTP request path](benchmarks/HTTP-REQUEST-PATH.md) for the latest measurement of requests with browser-sized fields and a breakdown of where a request's time goes, [HTTP lazy request fields](benchmarks/HTTP-LAZY-FIELDS.md) for the step before it, [HTTP fast path](benchmarks/HTTP-FAST-PATH.md) for the preceding matched before/after HTTP measurements, request-shape workloads and the WebSocket regression check, [HTTP codec optimization](benchmarks/HTTP-CODEC-OPTIMIZATION.md) for the preceding codec measurements, [PHP HTTP headroom](benchmarks/HTTP-HEADROOM.md) for the earlier layer diagnostics, and [Crab versus Elysia](benchmarks/ELYSIA.md) for the earlier combined comparison. The historical snapshots below predate later docblock, request-body storage and codec changes; their hashes identify the source measured at the time.

## Waiting for a pooled connection, 2026-11-05

The same single-process setup as the sections below, JIT off, 64 connections, `GET /mysql-async/users/{id}` on MySQL 8.4 in a local container; four rounds, two with each version first, medians in requests per second. No errors.

| | Before | After | Change |
| --- | ---: | ---: | ---: |
| Pool of 10, the default | 10,110 | 11,194 | +10.7% |
| Pool of 64 | 11,372 | 11,529 | +1.4% |
| `GET /hello` | 45,799 | 45,889 | 0% |
| User CPU per request, pool of 10 | 66.5 µs | 56.9 µs | −9.6 µs |
| Kernel CPU per request, pool of 10 | 21.4 µs | 21.0 µs | −0.4 µs |

With a pool of 10, 54 of the 64 requests wait in line for a connection at any time. Each waiting request had a timer of its own for its `timeout`, made, put in the loop's timer heap and taken out again on every wait, in a heap the server's own connection timers already fill. A pool now keeps one timer for all its waiting callers. As they all wait as long, the oldest is always the first to give up: the timer is due when it does, and set again then for the next, and it is cancelled when nobody waits. In process, 64 fibers taking turns on a pool of 10 went from 7.4 to 4.6 µs a turn; in the server, a request's user CPU fell by 9.6 µs. The cycle collector ran once in either server, at its end, so it is not where the rest of the difference went.

Each wait also made a `FiberSuspension`, 510 ns against 72 for reusing one, as the loop held its suspensions weakly and nothing else held one between waits. The loop now holds each fiber's suspension for as long as the fiber lives, in the `WeakMap` it already kept by fiber. A fiber that waits with nothing left to resume it is still collected: the suspension holds the fiber only through the map's entry, and PHP's cycle collector frees an entry whose value refers to its own key, running the fiber's `finally` blocks, as before. Once a wait is over, the suspension holds neither its fiber nor an error thrown into it, so that a spare fiber keeps nothing of the request it served; a suspension whose `Fiber::suspend()` itself failed is dropped from the map with the error it keeps for a later `resume()`. This is the pool-of-64 row, where nothing waits on the pool.

With these, the default pool of 10 serves about 3% fewer requests than a pool of 64, against 11% before.

## Async MySQL, 2026-11-05

One process with JIT off, 64 connections, `GET /mysql-async/users/{id}` through the query builder on MySQL 8.4 in a local container, the default pool of 10; four rounds alternating the two versions, medians. No errors.

| | Before | After | Change |
| --- | ---: | ---: | ---: |
| Requests a second | 9,491 | 10,086 | +6.3% |
| User CPU per request | 71.8 µs | 66.8 µs | −5.0 µs |
| Kernel CPU per request | 22.4 µs | 21.5 µs | −0.9 µs |

`GET /hello` on the same process costs about 9 µs of user CPU and 8 µs of kernel CPU, so an async lookup costs about 60 µs more of its own, spread across the driver rather than in one place. A request that suspends once on its own, `Async::sleep(0)`, costs no more than `GET /hello`, so suspending is not where it goes; the `ev` loop was slower here, 7,483 requests a second against 9,391 on Crab's `select` loop. What was found and changed:

- Each command probed its connection with a one-byte read and `feof()`, which polls: two system calls. A plain connection now asks with one peek, `false` meaning a live idle socket, `''` a closed one and a byte something unasked; a TLS connection keeps the read, as OpenSSL may hold bytes the socket no longer shows. An empty read waited only after `feof()` had polled; a close also wakes the wait, so only an empty read after waking is asked.
- A result of one row and four columns is eight packets, and each was read with two `read()` calls through a closure, a header and a payload, each checking its deadline and cancellation and copying. A packet is now read from the buffer in one call, its header unpacked in place. A command of one packet, nearly all of them, is sent without a generator.

With 64 requests at a time and a pool of 10, most requests wait in line for a connection; a pool of 64 served 10,191 requests a second against 9,391. [Database](docs/database.md#not-blocking-the-worker) says how to size `pool`. Not changed: what each wait costs, a `FiberSuspension` made for every wait and a timer for every request waiting on the pool, as the pool and the loop's suspensions are where the care for abandoned fibers lives.

## JSON and text responses, 2026-11-05

The same single-process setup, JIT off, six rounds with the order of the two versions alternating, medians in requests per second, no errors:

| Request | Before | After | Change |
| --- | ---: | ---: | ---: |
| `GET /hello` | 43,891 | 46,624 | +6.2% |
| `POST /users`, validated body | 23,970 | 24,680 | +3.0% |
| `GET /users/{id}`, SQLite | 19,305 | 19,348 | 0% |

In process, the framework's layer over the engine was taken apart one step at a time on `GET /hello` (user CPU per request, JIT off): the engine alone answers in about 5.6 µs and the framework adds about 3.4 µs, of which the request wrapper is about 0.4 µs, handing a ready response to the engine 0.6, making a JSON response 0.8, routing and the action 1.0 and the middleware stack 0.6. Making a response was the step with work to spare: the constructor ran for every response and three small arrays were made for its one field, `['content-type' => ['application/json']]` and the engine's `['Content-Type' => 'application/json']`. A typed response is now copied from a blank one, its fields are a constant array, which PHP shares rather than copies, and `toEngine()` recognises that array by identity and gives the engine its own constant, whose encoded block the engine already holds. A response a middleware changes takes the general path as before.

## Database reads and validation, 2026-11-05

The same single-process setup as the section below (`benchmarks/single-process/`), comparing `main` before these changes with the changes, six alternating rounds, JIT off, medians in requests per second. No trial had an error.

| Request | Before | After | Change |
| --- | ---: | ---: | ---: |
| `GET /hello` | 43,584 | 43,552 | 0% |
| `GET /users/{id}`, SQLite | 15,353 | 19,301 | +25.7% |
| `GET /mysql/users/{id}`, PDO | 6,771 | 7,310 | +7.9% |
| `GET /mysql-async/users/{id}`, `async` driver | 8,947 | 9,476 | +5.9% |
| `POST /users`, validated body | 19,683 | 24,156 | +22.7% |

Where the time went, measured in process (user CPU per request through the real engine and framework over a socket pair, the best of nine trials): the SQLite read fell from 33.3 to 21.8 µs and the `POST` from 21.9 to 16.0 µs.

- A bound `LIMIT ?` made SQLite plan the statement again on every run: 7.4 µs a lookup against 3.2 µs with the limit written in. A bound offset costs 0.2 µs, so it stays bound, and a page keeps one statement.
- `filter_var(FILTER_VALIDATE_EMAIL)` took 3.5 µs on an everyday address; a strict pattern that it accepts in full takes 0.4 µs, and anything else is still left to it. A test holds the two to the same answer.
- A header field was found with a pattern made for its name: 961 ns, against 368 ns for a case-insensitive search.
- The query builder made a grammar for every query and quoted every name again; `Database` made a closure for every query.

Tried and dropped: making the engine's response when a JSON response is made, which made the JSON path slower (725 to 823 ns), and trims in the validator's loop, which did not measure. Removing PSR-7 was measured too: on `GET /hello` it would save at most 2.3 µs of a 21 µs request, about 8–11%, and is not pursued.

## After the 0.4 review fixes, and against Elysia, 2026-11-05

Two single-process checks on an **Intel i5-9400F, 6 cores, Fedora 44, PHP 8.4.22**, with OPcache on. Every server is one process; wrk runs at 64 connections for 8 seconds after a warmup. The database reads cycle through 10,000 users, in SQLite and in a disposable MySQL 8.4 on the same machine. `benchmarks/single-process/setup.sh` builds everything, `run.sh` and `compare.sh` run the two checks, and the raw lines are in [its results](benchmarks/single-process/results). No trial had an error. These are single-process figures on one machine, not comparable with the tables below.

**v0.4.0 against `main`**, three alternating rounds, medians:

| Request | JIT off, v0.4.0 → `main` | Change | Tracing JIT, v0.4.0 → `main` | Change |
| --- | ---: | ---: | ---: | ---: |
| `GET /hello` | 43,097 → 43,187 | +0.2% | 49,413 → 48,027 | −2.8% |
| `GET /users/{id}`, SQLite | 14,836 → 15,208 | +2.5% | 18,129 → 17,663 | −2.6% |
| `GET /mysql/users/{id}`, PDO | 6,826 → 6,768 | −0.9% | 7,568 → 7,757 | +2.5% |
| `GET /mysql-async/users/{id}`, `async` driver | 8,898 → 9,094 | +2.2% | 11,520 → 11,638 | +1.0% |
| `POST /users`, validated body | 20,465 → 19,829 | −3.1% | 24,839 → 24,741 | −0.4% |

Nothing that runs per request changed between them: since v0.4.0, `src/` changed in runs, actors, the HTTP client and the connection pool, none of which these requests use. The `GET /hello` gap with JIT was the order of the runs: over six more rounds with `main` first in each, `main` measured 0.5% ahead. Nothing measured got slower.

**Crab (`main`) against Elysia 1.4.30 on Bun 1.4.2**, three rounds with the order of the three servers rotated, medians:

| Request | Crab, JIT off | Crab, tracing JIT | Elysia | Crab with JIT, of Elysia |
| --- | ---: | ---: | ---: | ---: |
| `GET /hello` | 43,675 | 46,605 | 81,483 | 57% |
| `GET /users/{id}`, SQLite | 15,077 | 17,739 | 44,133 | 40% |
| MySQL read, Crab's PDO driver | 6,865 | 7,732 | 26,821 | 29% |
| MySQL read, Crab's `async` driver | 9,172 | 11,541 | 26,821 | 43% |
| `POST /users`, validated body | 20,158 | 25,120 | 39,959 | 63% |

Elysia's p99 latency is about half of Crab's throughout: 1.54 against 2.69 ms for `GET /hello`, 3.60 against 6.80 ms for MySQL with the `async` driver. Its SQLite and MySQL clients are part of the Bun runtime, in native code, and its SQLite route runs a statement prepared once; Crab's MySQL `async` driver is written in PHP, and each Crab read goes through the query builder. Bun's MySQL client does not block, so Crab's `async` driver is the one to compare it with; with PDO, a query holds up the whole process. Validation is the closest case.

## The 0.4 refactor, 2026-11-04

A matched check that the 0.4 refactor (plugins, `Async::await`, the shared HTTP client rules, the Validator split and the rest) did not slow requests down. Both versions served the same application, the [framework fixture](benchmarks/frameworks/crab), as one process with OPcache on and JIT off, on an **Intel i5-9400F, 6 cores, Fedora 44, PHP 8.4.22**. Load came from wrk 4.x at 64 connections for 8 seconds, after a 3-second warmup, and the two versions alternated every round so the machine's noise fell on both alike. These are single-process figures on another machine, not comparable with the tables below.

| Request | v0.3.0 | 0.4 (`main`) | Change |
| --- | ---: | ---: | ---: |
| `GET /hello`, JSON | 43,121 req/s | 43,406 req/s | +0.7% |
| `GET /users/7`, one SQLite query | 15,063 req/s | 15,103 req/s | +0.3% |
| `POST /users`, a validated body (6 rounds) | 20,035 req/s | 19,836 req/s | −1.0% |
| `GET /hello`, `$app->handle()` in process | 3.12 µs | 3.12 µs | 0 |
| `POST /users`, in process, 5 × 200,000 | 17.30 µs | 17.39 µs | +0.5% |

Medians. The `POST` difference is within the spread of the trials (17.09–17.64 µs before, 17.19–17.55 µs after), and no code that runs per request changed on that path. Nothing measured got slower.

These local measurements show useful HTTP and WebSocket throughput with PHP streams and a select loop. They measure small, persistent-connection workloads on one development machine; they do not establish production capacity or performance for database-backed applications.

Measured on **Apple M1 Pro, 8 cores, 16 GiB RAM, macOS 26.7.1 arm64**, with **PHP 8.4.19 NTS** and **Revolt 1.0.9 `StreamSelectDriver`**. HTTP used wrk 4.2.0; WebSocket used the included Node.js v24.10.0 client. Server and client shared the host. Harnesses acquire an exclusive lock at `/tmp/crab-engine-benchmark.lock` to prevent overlapping benchmark runs.

All tables report **medians of three measured trials**. All **42 HTTP trials and 66 WebSocket trials recorded zero protocol/socket/status errors or timeouts**. All benchmark servers exited with status zero and empty logs. Correctness checks run separately; zero benchmark errors alone do not establish protocol conformance.

## HTTP

The workload is persistent HTTP/1.1 `GET /`, returning the same 16-byte text body. Each case has a one-second warmup followed by three four-second trials, with one or two wrk threads and no application database calls. **CLI OPcache is enabled; JIT is disabled** throughout the HTTP comparisons.

Two specific changes were measured independently:

| Change | Workers | Connections | Before requests/s | After requests/s | Change | p99 before → after, ms |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Combine headers and a small fixed body into one output segment | 1 | 1 | 19,674 | 20,253 | +2.9% | 0.095 → 0.090 |
| Same change | 1 | 32 | 34,671 | 39,369 | +13.6% | 1.123 → 1.000 |
| Same change | 1 | 128 | 33,857 | 38,527 | +13.8% | 4.349 → 3.782 |
| Skip deferred parsing when no pipelined bytes or EOF remain | 1 | 32 | 37,672 | 39,080 | +3.7% | 1.048 → 1.061 |
| Same change | 2 | 32 | 70,792 | 73,768 | +4.2% | 0.659 → 0.702 |

The first change reduces two output segments to one for bounded small responses; streamed and large bodies keep bounded chunks. The second avoids scheduling a parser turn with no work. Its higher throughput came with slightly higher p99 latency in that matched comparison. Request cancellation and lifecycle changes landed between the two comparisons, so their gains should not be compounded.

Raw comparisons: [small-response baseline](benchmarks/results/http-baseline.json), [small-response optimized](benchmarks/results/http-optimized.json), [idle-defer baseline](benchmarks/results/http-final.json), [idle-defer optimized](benchmarks/results/http-no-idle-defer.json). Exact optimization deltas: [small-response patch](benchmarks/changes/http-small-response.patch), [idle-defer patch](benchmarks/changes/http-idle-defer.patch).

The final HTTP source retains service cleanup after a socket closes, including asynchronous `finally` blocks, until that work exits. With this lifecycle accounting, it produced:

| Workers | Connections | Requests/s | p50, ms | p95, ms | p99, ms | Peak process RSS |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | 32 | 39,088 | 0.798 | 0.945 | 1.037 | 30.8 MiB |
| 2 | 32 | 73,193 | 0.466 | 0.628 | 0.730 | 48.8 MiB |

These are [final-source measurements](benchmarks/results/http-tracked-release.json), not another isolated optimization claim. The [previous lifecycle snapshot](benchmarks/results/http-release.json) remains as historical data. Sampled server CPU was about one and two cores respectively; the load generator remained below one core. Multi-worker RSS sums the supervisor and children and can count shared pages more than once.

## WebSocket

The workload sends **masked binary messages**, validates every echoed payload, and allows **one outstanding message per connection**. Each trial uses fresh connections, a 0.5-second warmup, and a three-second measurement. The client validates the handshake, server masking direction and payload order, then finishes with a close exchange. Latencies use a bounded logarithmic histogram with approximately 1.1% resolution; reported percentiles are bucket upper bounds.

The code comparison uses **one PHP worker, CLI OPcache disabled, and JIT disabled** in both versions. The change sends a complete single frame directly to the transport when both WebSocket queues are empty, avoiding a temporary frame list and queue round trip. Transport still owns partial writes; capacity checks, control ordering, and the queued path remain intact.

| Payload | Connections | Before messages/s | After messages/s | Change | p99 before → after, ms |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 64 bytes | 1 | 22,848 | 23,909 | +4.6% | 0.077 → 0.076 |
| 64 bytes | 32 | 83,309 | 89,565 | +7.5% | 0.557 → 0.551 |
| 64 bytes | 128 | 83,538 | 91,566 | +9.6% | 1.877 → 1.837 |
| 1,024 bytes | 1 | 20,765 | 21,132 | +1.8% | 0.082 → 0.080 |
| 1,024 bytes | 32 | 75,449 | 80,351 | +6.5% | 0.628 → 0.663 |
| 1,024 bytes | 128 | 76,359 | 81,650 | +6.9% | 2.092 → 2.626 |

At high concurrency the Node client approached or exceeded one CPU core. In particular, the larger-payload throughput gains came with worse tail latency. These are achieved rates with this load generator, not a demonstrated server ceiling. Raw data: [baseline](benchmarks/results/ws-baseline.json), [optimized](benchmarks/results/ws-optimized.json), [summary](benchmarks/results/ws-summary.json). The [exact source delta](benchmarks/changes/ws-single-frame.patch) also includes a separate buffered-read continuation guard that the normal echo workload does not exercise.

A separate configuration comparison then enabled **CLI OPcache with JIT still disabled**, leaving the optimized WebSocket implementation unchanged:

| Payload | Connections | OPcache off messages/s | OPcache on messages/s | Change | OPcache on p99, ms |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 64 bytes | 1 | 23,909 | 24,633 | +3.0% | 0.074 |
| 64 bytes | 32 | 89,565 | 92,781 | +3.6% | 0.582 |
| 64 bytes | 128 | 91,566 | 93,792 | +2.4% | 2.232 |
| 1,024 bytes | 1 | 21,132 | 21,811 | +3.2% | 0.078 |
| 1,024 bytes | 32 | 80,351 | 81,052 | +0.9% | 0.723 |
| 1,024 bytes | 128 | 81,650 | 81,915 | +0.3% | 2.864 |

The small high-concurrency gains are close to the client ceiling and should not be treated as reliable server-side improvement estimates. Maximum end-of-trial serving-process RSS was 28.4 MiB with OPcache off and 31.8 MiB with it on. [OPcache trial data](benchmarks/results/ws-optimized-opcache.json)

After the connection/service cleanup changes, the four high-concurrency cases were repeated on the then-current source with OPcache enabled and JIT disabled:

| Payload | Connections | Messages/s | p50, ms | p95, ms | p99, ms |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 64 bytes | 32 | 92,554 | 0.338 | 0.479 | 0.563 |
| 64 bytes | 128 | 94,215 | 1.356 | 1.759 | 2.161 |
| 1,024 bytes | 32 | 81,818 | 0.373 | 0.601 | 0.685 |
| 1,024 bytes | 128 | 82,649 | 1.479 | 2.382 | 2.803 |

This [final-source snapshot](benchmarks/results/ws-tracked-release.json) contains 12 additional error-free trials; its source hashes identify that implementation. Maximum end-of-trial serving-process RSS was 31.8 MiB. The client still used about one CPU core, so the same load-generator limitation applies. This snapshot does not establish another optimization gain.

## Reproduce and interpret

Run from `engine/`, after installing its Composer dependencies. The HTTP harness requires `wrk`; the WebSocket harness requires Node.js. Both Python harnesses require a Unix-like host and start and stop only their own server process groups.

```sh
python3 benchmarks/http.py --label=http-repeat --workers=1,2 --concurrency=32 --duration=4 --trials=3 --warmup=1
python3 benchmarks/ws-run.py --label=ws-repeat --workers=1 --concurrency=1,32,128 --payloads=64,1024 --duration=3 --trials=3 --warmup=0.5
python3 benchmarks/ws-run.py --label=ws-opcache-repeat --opcache-cli --workers=1 --concurrency=1,32,128 --payloads=64,1024 --duration=3 --trials=3 --warmup=0.5
```

The commands measure the current source. Raw JSON preserves commands, versions, configuration, per-trial errors, throughput, p50/p95/p99, CPU/RSS observations, and source hashes. Patches preserve the individual optimization deltas; unrelated lifecycle changes mean reversing one patch is not a complete historical checkout. The WebSocket baseline explicitly records that its hashes were captured after its unchanged WebSocket source had finished running. Later datasets capture hashes before server startup.

Both workloads are closed-loop: a client waits for a response before offering more work. Their latency distributions do not describe a server under a fixed external arrival rate, and they can hide overload delay. CPU measurements also differ: HTTP uses sampled OS percentages; WebSocket server CPU deltas include handshake and warmup, while its Node CPU counters cover the measured interval. Short local trials and RSS snapshots do not establish long-running memory stability.

Use a separate, unsaturated load host, controlled offered load, longer runs, and representative application work before setting deployment limits. The historical tables on this page do not imply a cross-runtime comparison, Internet latency claim, or universal website capacity.
