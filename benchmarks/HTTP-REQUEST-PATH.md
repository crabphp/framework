# HTTP request path — 2026-10-04

This work follows [lazy request fields](HTTP-LAZY-FIELDS.md) and asks how much
PHP-side work per request remains to remove on the one workload that matters
most: browser-sized request fields with a value that differs on every request.

With tracing JIT, one worker now serves that workload at **102,764 requests/s,
up from 89,185 (+15%)**. Elysia on Bun served 102,493 in the same run. The two
are level: the medians differ by 0.3% and the trial ranges overlap. This is
parity on one core, not a lead.

## Where the time goes

A native sample of each server under this load, and the harness described
below, give this picture per request:

| | Kernel (`sendto`, `recvfrom`, poll) | Own code |
| --- | ---: | ---: |
| Crab before this work | about 7.2 µs | about 3.5 µs |
| Elysia / Bun | about 7.4 µs | about 2.2 µs |

Both servers make one receive and one send call per request and spend about
three quarters of their time there, so only the remaining quarter can be
improved. This work removed about a third of Crab's share.

Two limits were measured rather than assumed:

- Strict validation of this workload's head, about 720 bytes, costs about
  0.6 µs through PCRE, the only fast byte scanner PHP offers. Six pattern
  variants, including ones that check structure only, stayed between 0.53 and
  0.63 µs.
- Revolt's dispatch costs about 0.2 µs per request. A prototype driver that ran
  all ready stream callbacks of a turn as one batch recovered about 0.06 µs and
  was discarded. Sending all responses after reading all ready sockets, and
  the sockets extension in place of streams, gave no gain either.

## Changes

- **Request.** A bodyless request no longer creates a `Body`, a trailer map or
  a cancellation token until a handler reads them. `body`, `trailers` and
  `cancellation` are computed properties like `headers`; `method`, `target`
  and `version` stay read-only. The parser passes the Connection value instead
  of nested field arrays.
- **Parser.** `receive()` combines `append()` and `next()` and matches a lone
  bodyless head without copying it into the buffer. The one-pattern path now
  covers origin-form targets only and leaves expectations to the general
  parser, which still decides every error status.
- **Session.** The data callback is the request method itself. Inside a read
  turn the transport admits no further input, so the session pauses reading
  only when output outlives the turn or the request runs from a deferred
  turn. Deadline stamps for the usual transitions are inline.
- **Responses.** `Response::text()` and `Response::json()` without extra fields
  use precomputed, already validated fields. Everything after Content-Length
  is rebuilt once per second. A string returned by a handler is encoded
  directly, without a `Response`.
- **Transport.** The read turn calls its consumer directly and reconciles
  read interest only when something changed. `enterActivity()` reports a
  closed connection instead of throwing.

## Result

One fresh serving process per trial, 128 connections, two wrk threads, a
two-second warmup and three five-second measurements per target, with rotating
target order. The `browser-unique` workload sends 15 browser-sized request
fields plus Host, rotates 997 query strings on one route and changes one field
value on every request. Tables report medians.

| Handler returns | Server | Req/s | Range | p50, ms | p95, ms | p99, ms | CPU µs/request | Peak RSS, MiB |
| --- | --- | ---: | --- | ---: | ---: | ---: | ---: | ---: |
| new `Response` | Before | 89,185 | 88,761–91,138 | 1.390 | 1.667 | 2.767 | 10.93 | 35.1 |
| new `Response` | After | 102,764 | 99,740–103,325 | 1.207 | 1.982 | 2.436 | 9.56 | 34.8 |
| string | Before | 89,522 | 84,228–89,814 | 1.423 | 1.542 | 2.671 | 11.02 | 35.2 |
| string | After | 103,354 | 98,423–104,639 | 1.210 | 1.857 | 2.402 | 9.53 | 34.9 |
| string | Elysia / Bun | 102,493 | 101,155–105,220 | 1.211 | 1.592 | 2.388 | 9.66 | 48.7 |
| string | Elysia / Bun, second run | 103,195 | 94,369–104,241 | 1.231 | 1.415 | 2.307 | 9.71 | 48.1 |

The Elysia fixture always returns a string; its two rows are the controls of
the two runs. Every optimized Crab trial exceeded every baseline trial. Crab
uses 1–2% less CPU per request than Elysia and about 14 MiB less memory;
its p95 latency is worse. A further run at 64 and 256 connections, not
retained, gave 103,214 against 102,168 and 96,722 against 104,126: select's
cost grows with the number of watched connections, so Crab falls behind at
256.

All 18 HTTP trials passed, 8,954,505 measured requests with zero reported
errors. [New Response](results/compare-http-request-path.json),
[string](results/compare-http-request-path-string.json).

### WebSocket regression check

Masked binary echo, one outstanding message per connection, OPcache on and JIT
off, same trial shape.

| Payload | Before messages/s | After messages/s |
| ---: | ---: | ---: |
| 64 bytes | 94,343 | 94,436 |
| 1,024 bytes | 80,366 | 80,433 |

No change. All 12 trials passed, 5,224,929 measured echoes.
[WebSocket trials](results/compare-http-request-path-ws.json).

Environment: Apple M1 Pro (8 cores), macOS 26.7.1 arm64, PHP 8.4.19 NTS with
CLI OPcache and tracing JIT for HTTP, Revolt 1.0.9 `StreamSelectDriver`,
wrk 4.2.0, Node v24.10.0, Elysia 1.4.30 on Bun 1.4.2. Client and server share
the host. Only this workload was measured in this round.

## In-process harness

[`request-path.php`](request-path.php) serves the same requests over a socket
pair inside one process and reports user-mode CPU per request, without a load
generator, kernel time or the event loop's dispatch. It repeats within about
2% and takes seconds, which made each step above measurable.

| Source | Engine user-mode CPU per request |
| --- | ---: |
| Before | 2.54 µs |
| After, new `Response` | 1.71 µs |
| After, string | 1.58 µs |

```sh
php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=128M \
  benchmarks/request-path.php [--source=/snapshot/src] [--http-response=fresh|string|reuse]
```

## Correctness

The suite passes with **339 tests and 2,129 assertions**, normally and with
tracing JIT in random order (seed `20261003`). New cases cover string results
on persistent connections, equality of string, factory and general encodings,
the per-second date refresh, on-demand body, trailers and cancellation, and a
handler suspended inside a read turn while further input arrives. The
differential fuzz of 150,000 mutated and fragmented requests still gives
identical requests, single-field lookups, parser phases and error statuses on
the original eager parser and this one.

## Reproduce

The [patch](changes/http-request-path.patch) is the complete source delta;
reverse it in a copy of `src` to reconstruct the baseline.

```sh
path_baseline=$(mktemp -d)
cp -R src "$path_baseline/src"
patch -R -p1 -d "$path_baseline" < benchmarks/changes/http-request-path.patch

python3 benchmarks/compare.py --label=http-request-path-repeat --protocol=http \
  --targets=crab-before-jit,crab-jit,elysia \
  --baseline-source="$path_baseline/src" --bun=/path/to/bun-1.4.2 \
  --http-response=fresh --http-request=browser-unique \
  --concurrency=128 --duration=5 --warmup=2 --trials=3
```

`--http-response=string` makes the Crab handler return a bare string. The
limits of the earlier pages apply: one development machine, loopback, short
closed-loop trials. These are achieved local rates, not a ceiling, production
capacity or a general comparison between runtimes.
