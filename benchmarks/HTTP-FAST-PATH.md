# HTTP fast path — 2026-10-04

This work follows the [HTTP codec optimization](HTTP-CODEC-OPTIMIZATION.md). It
removes per-request scheduling, allocation and parsing work from the common
persistent-connection path while keeping PHP 8.4, Revolt's select driver, strict
request validation, deadlines, cancellation, streaming and backpressure. It adds
no dependency. Every request is parsed in full; no parsed request data is
reused between requests.

With tracing JIT, one worker now serves the small-response workload at
**97,675 requests/s, up from 78,150 (+25%)**, against 104,591 for Elysia on
Bun in the same run. Without JIT it serves 81,342, up from 60,877 (+34%).
With browser-sized request fields Crab/JIT reaches 72,161, up from 51,003,
and Elysia stays well ahead at 94,068; see [Request shapes](#request-shapes).

## Changes

- **Read interest.** `Connection` no longer disables and re-enables its read
  watcher around every read. A read turn reconciles interest once when it ends;
  a paused or suspended turn that sees readiness disables the watcher then.
- **Session.** The first request of a read turn runs inside that turn instead
  of a deferred callback. Pipelined successors keep their own deferred turns.
  Activity scopes, output completion and small responses use no per-request
  closures or generators. A complete persistent response that the transport
  accepts at once finishes without entering the output pump.
- **Parser.** One anchored scan validates and splits all fields, replacing one
  regular expression per field. A lone request is parsed without copying it.
  Host is validated once per distinct value on a connection.
- **Encoder.** Small fixed-length responses without explicit framing,
  Connection, Date or Upgrade fields are encoded in one pass, producing the
  same bytes as the general encoder. Reused responses retain their encoded
  field block under the existing no-references rule. Validated response field
  lines are kept in a bounded 256-entry memo.
- **Smaller items.** `Body` relies on resource release instead of a destructor,
  requests receive an explicit cancellation token instead of an evaluated
  default, and common Connection values skip the token-list scan.

Tried and not kept:

- Reusing the parsed request line and field block when a connection repeats
  them byte for byte. It raised the fixed-request rate to about 106,000 with
  JIT, but identical requests are what a load generator sends, and connections
  behind a proxy rarely repeat a field block. It was removed so that the
  results measure parsing.
- Reading into a smaller buffer that grows on demand, and assembling the
  response by interpolation. Interleaved trials showed no gain.

## Final matched comparison

One fresh serving process per trial, 128 connections, two wrk threads, a
two-second warmup and three five-second measurements per case, with rotating
target order. Tables report medians; ranges are the lowest and highest trial.

Environment: Apple M1 Pro (8 cores), macOS 26.7.1 arm64, PHP 8.4.19 NTS,
Revolt 1.0.9 `StreamSelectDriver`, wrk 4.2.0, Node v24.10.0, Elysia 1.4.30 on
Bun 1.4.2. CLI OPcache is enabled throughout; JIT is shown separately. Client
and server share the host, which also runs desktop applications.

### Fixed request

The existing workload: persistent `GET /`, 16-byte body.

| Server | Response | Req/s | Range | p50, ms | p99, ms | CPU µs/request | Peak RSS, MiB |
| --- | --- | ---: | --- | ---: | ---: | ---: | ---: |
| Before, JIT off | reused | 60,877 | 60,508–60,975 | 2.064 | 2.411 | 16.23 | 32.3 |
| After, JIT off | reused | 81,342 | 81,088–82,182 | 1.560 | 1.737 | 12.03 | 32.1 |
| Before, tracing JIT | reused | 78,150 | 76,186–78,478 | 1.619 | 1.831 | 12.67 | 35.0 |
| After, tracing JIT | reused | 97,675 | 97,653–98,930 | 1.303 | 2.407 | 9.99 | 35.3 |
| Elysia / Bun | — | 104,591 | 102,921–105,314 | 1.225 | 2.172 | 9.50 | 49.7 |
| Before, JIT off | fresh | 57,587 | 57,440–58,415 | 2.183 | 2.466 | 17.12 | 32.3 |
| After, JIT off | fresh | 76,439 | 75,115–76,686 | 1.663 | 1.827 | 12.85 | 32.2 |
| Before, tracing JIT | fresh | 73,304 | 72,611–75,009 | 1.717 | 1.978 | 13.50 | 34.7 |
| After, tracing JIT | fresh | 91,859 | 91,052–92,163 | 1.381 | 2.546 | 10.62 | 34.9 |

Every optimized trial exceeded every baseline trial in its case. Crab/JIT
delivers 93% of Elysia's rate here, up from 75%. Its p99 rose while its median
latency fell.

[Reused](results/compare-http-fast-path.json),
[fresh](results/compare-http-fast-path-fresh.json).

### Request shapes

The fixed request carries one field. The `browser-unique` workload sends 15
browser-sized request fields, rotates 997 query strings on the same route and
changes one field value on every request, with a fresh Response per request.
It is the closer model of traffic that arrives through a proxy.

| Server | Req/s | Range | p50, ms | p99, ms | CPU µs/request |
| --- | ---: | --- | ---: | ---: | ---: |
| Before, JIT off | 40,785 | 39,630–41,340 | 3.060 | 4.113 | 24.01 |
| After, JIT off | 60,881 | 57,577–61,277 | 2.084 | 2.411 | 16.03 |
| Before, tracing JIT | 51,003 | 50,385–54,634 | 2.413 | 3.448 | 19.03 |
| After, tracing JIT | 72,161 | 70,386–72,279 | 1.718 | 3.521 | 13.34 |
| Elysia / Bun | 94,068 | 72,380–98,904 | 1.311 | 2.792 | 10.40 |

Every optimized trial exceeded every baseline trial. Crab/JIT improves 41% and
delivers 77% of Elysia's median rate. The host was noisier during this case:
one Elysia trial fell to 72,380, and an earlier attempt at the same case was
repeated after one Crab trial was disturbed. Splitting 16 fields into the
header map in PHP is the largest remaining cost, about 3 µs per request in an
isolated measurement against 0.7 µs for the one-field request.

[Browser-unique](results/compare-http-fast-path-browser-unique.json).

### WebSocket regression check

The transport change also serves WebSocket. Masked binary echo, one outstanding
message per connection, OPcache on and JIT off, same trial shape.

| Payload | Before messages/s | After messages/s | p99 before → after, ms |
| ---: | ---: | ---: | ---: |
| 64 bytes | 92,929 | 93,120 | 2.684 → 2.803 |
| 1,024 bytes | 79,291 | 82,647 | 3.227 → 3.090 |

No material regression. The 64-byte ranges overlap, and the Node client runs
near one core, so these are not presented as gains.
[WebSocket trials](results/compare-http-fast-path-ws.json).

All **54 final trials passed**: 15,779,670 measured HTTP requests and
5,230,812 WebSocket echoes with zero reported socket, status or protocol errors
or timeouts, clean server exits, and unchanged source hashes.

## Correctness

The suite passes with **316 tests and 1,989 assertions**, normally and with
tracing JIT in random order (seed `20261003`). `HttpFastPathTest` adds cases
for repeated and changing requests on one parser, field limits and error codes, small-encoder equality with
the general encoder, referenced response fields, read-interest behavior during
a suspended turn, and responses larger than the write capacity.

A differential fuzz fed 120,000 mutated and fragmented requests, including
pipelines that repeat and change the request line and field block, to
the original and the new parser. Every case produced identical requests,
parser phases and error statuses. That check found one defect during
development (an empty Host accepted on a fresh parser), fixed before
measurement and now covered by a test. The fuzz script is not part of the
repository.

## Scope and limits

Same limits as the earlier pages: one development machine, loopback, short
closed-loop trials, client and server sharing CPUs. These are achieved local
rates, not a PHP ceiling, production capacity or a general comparison between
runtimes. wrk checks status and socket errors during measurement, not each
body.

A native sample of the server under the fixed-request load attributed roughly
three quarters of its main-thread time to `sendto`, `recvfrom` and `select`. Further
single-worker gains from PHP code are therefore limited; additional workers
scale throughput (111,601 requests/s with two workers and JIT off, measured
before this work).

## Reproduce

The [patch](changes/http-fast-path.patch) is the complete source delta.
Reversing it in a copy reconstructs the baseline:

```sh
fast_baseline=$(mktemp -d)
cp -R src "$fast_baseline/src"
patch -R -p1 -d "$fast_baseline" < benchmarks/changes/http-fast-path.patch

python3 benchmarks/compare.py --label=http-fast-path-repeat --protocol=http \
  --targets=crab-before,crab-jit,elysia,crab,crab-before-jit \
  --baseline-source="$fast_baseline/src" --bun=/path/to/bun-1.4.2 \
  --http-response=reuse --concurrency=128 --duration=5 --warmup=2 --trials=3

python3 benchmarks/compare.py --label=http-fast-path-repeat-browser --protocol=http \
  --targets=crab-before,crab-jit,elysia,crab,crab-before-jit \
  --baseline-source="$fast_baseline/src" --bun=/path/to/bun-1.4.2 \
  --http-response=fresh --http-request=browser-unique \
  --concurrency=128 --duration=5 --warmup=2 --trials=3
```

`--http-request` accepts `fixed`, `varied`, `browser` and `browser-unique`.
Add `--http-response=fresh` for the fresh-response rows and `--protocol=ws
--targets=crab-before,crab --payloads=64,1024` for the WebSocket check.
