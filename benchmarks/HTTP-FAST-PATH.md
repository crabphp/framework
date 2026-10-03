# HTTP fast path — 2026-10-03

This work follows the [HTTP codec optimization](HTTP-CODEC-OPTIMIZATION.md). It
removes per-request scheduling, allocation and parsing work from the common
persistent-connection path while keeping PHP 8.4, Revolt's select driver, strict
request validation, deadlines, cancellation, streaming and backpressure. It adds
no dependency.

With tracing JIT, one worker now serves the small-response workload at
**105,943 requests/s, up from 75,269 (+41%)**, against 101,418 for Elysia on
Bun in the same run. Without JIT it serves 82,193, up from 59,620. When every
request carries a different field block, Crab reaches 73,931 and Elysia stays
well ahead at 99,702; see [Request shapes](#request-shapes).

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
  Each connection retains its last request line and last field block (up to
  4 KiB each); identical bytes reuse the parsed values. Host is validated once
  per distinct value.
- **Encoder.** Small fixed-length responses without explicit framing,
  Connection, Date or Upgrade fields are encoded in one pass, producing the
  same bytes as the general encoder. Reused responses retain their encoded
  field block under the existing no-references rule. Validated response field
  lines are kept in a bounded 256-entry memo.
- **Smaller items.** `Body` relies on resource release instead of a destructor,
  requests receive an explicit cancellation token instead of an evaluated
  default, and common Connection values skip the token-list scan.

Rejected after interleaved trials showed no gain: reading into a smaller
buffer that grows on demand, assembling the response by interpolation, and a
third whole-head memo level on top of the two retained ones.

## Final matched comparison

One fresh serving process per trial, 128 connections, two wrk threads, a
two-second warmup and three five-second measurements per case, with rotating
target order. Tables report medians; ranges are the lowest and highest trial.

Environment: Apple M1 Pro (8 cores), macOS 26.7.1 arm64, PHP 8.4.19 NTS,
Revolt 1.0.9 `StreamSelectDriver`, wrk 4.2.0, Node v24.10.0, Elysia 1.4.30 on
Bun 1.4.2. CLI OPcache is enabled throughout; JIT is shown separately. Client
and server share the host.

### Fixed request

The existing workload: persistent `GET /`, 16-byte body.

| Server | Response | Req/s | Range | p50, ms | p99, ms | CPU µs/request | Peak RSS, MiB |
| --- | --- | ---: | --- | ---: | ---: | ---: | ---: |
| Before, JIT off | reused | 59,620 | 56,171–60,032 | 2.082 | 2.989 | 16.42 | 32.2 |
| After, JIT off | reused | 82,193 | 77,866–87,377 | 1.492 | 2.916 | 11.61 | 32.5 |
| Before, tracing JIT | reused | 75,269 | 69,387–76,094 | 1.667 | 2.028 | 13.07 | 35.0 |
| After, tracing JIT | reused | 105,943 | 103,961–107,383 | 1.206 | 2.319 | 9.20 | 35.0 |
| Elysia / Bun | — | 101,418 | 100,129–102,803 | 1.252 | 2.336 | 9.78 | 51.8 |
| Before, JIT off | fresh | 56,360 | 52,770–56,374 | 2.224 | 2.917 | 17.49 | 32.4 |
| After, JIT off | fresh | 79,395 | 79,113–79,508 | 1.584 | 2.376 | 12.24 | 32.4 |
| Before, tracing JIT | fresh | 68,855 | 66,705–71,084 | 1.787 | 2.680 | 14.17 | 34.8 |
| After, tracing JIT | fresh | 97,851 | 95,615–100,745 | 1.308 | 2.442 | 9.98 | 35.0 |

Every optimized trial exceeded every baseline trial in its case. The JIT-off
reused case had the widest spread in this run. Crab/JIT led Elysia by 4.5% at
the median with adjacent ranges, which is a small margin on a shared host, not
a general ranking. Crab/JIT p99 rose slightly while its median latency fell.

[Reused](results/compare-http-fast-path.json),
[fresh](results/compare-http-fast-path-fresh.json).

### Request shapes

The fixed request sends one field and never changes, which favors any reuse
of parsed input. Two further workloads use 14 browser-sized request fields
and rotate 997 query strings on the same route, with a fresh Response per
request. `browser` repeats the field block, as one client does on a persistent
connection. `browser-unique` also changes one field value on every request,
so nothing parsed can be reused.

| Workload | Server | Req/s | Range | p50, ms | p99, ms |
| --- | --- | ---: | --- | ---: | ---: |
| browser | Before, JIT off | 42,857 | 42,795–42,922 | 2.937 | 3.391 |
| browser | After, JIT off | 81,663 | 81,663–82,441 | 1.590 | 2.477 |
| browser | Before, tracing JIT | 55,464 | 55,332–55,829 | 2.259 | 2.650 |
| browser | After, tracing JIT | 99,473 | 97,649–102,347 | 1.332 | 2.368 |
| browser | Elysia / Bun | 97,366 | 96,242–98,846 | 1.320 | 2.269 |
| browser-unique | Before, JIT off | 42,193 | 41,823–42,344 | 2.987 | 3.402 |
| browser-unique | After, JIT off | 58,796 | 58,725–58,895 | 2.153 | 2.472 |
| browser-unique | Before, tracing JIT | 54,306 | 54,129–54,775 | 2.313 | 2.683 |
| browser-unique | After, tracing JIT | 73,931 | 73,724–74,517 | 1.726 | 2.038 |
| browser-unique | Elysia / Bun | 99,702 | 98,921–100,334 | 1.300 | 2.232 |

Repeated field blocks bring the browser workload level with Elysia in this
run; the ranges overlap. Without reuse, field parsing in PHP remains the
largest cost: Crab/JIT improves 36% and still delivers 74% of Elysia's rate.
Real traffic lies between these two cases.

[Browser](results/compare-http-fast-path-browser.json),
[browser-unique](results/compare-http-fast-path-browser-unique.json).

### WebSocket regression check

The transport change also serves WebSocket. Masked binary echo, one outstanding
message per connection, OPcache on and JIT off, same trial shape.

| Payload | Before messages/s | After messages/s | p99 before → after, ms |
| ---: | ---: | ---: | ---: |
| 64 bytes | 96,126 | 98,498 | 2.684 → 2.655 |
| 1,024 bytes | 78,663 | 82,236 | 3.262 → 3.123 |

No regression. The 64-byte ranges overlap, and the Node client runs near one
core, so these are not presented as gains.
[WebSocket trials](results/compare-http-fast-path-ws.json).

All **69 final trials passed**: 21,852,971 measured HTTP requests and
5,307,657 WebSocket echoes with zero reported socket, status or protocol errors
or timeouts, clean server exits, and unchanged source hashes.

## Correctness

The suite passes with **316 tests and 1,989 assertions**, normally and with
tracing JIT in random order (seed `20261003`). `HttpFastPathTest` adds cases
for request reuse, field limits and error codes, small-encoder equality with
the general encoder, referenced response fields, read-interest behavior during
a suspended turn, and responses larger than the write capacity.

A differential fuzz fed 120,000 mutated and fragmented requests, including
pipelines that change the request line and the field block independently, to
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
body. Retained request lines and field blocks cost at most about 8 KiB of
strings per connection plus their parsed arrays.

A native sample of the optimized server under load attributes roughly three
quarters of its main-thread time to `sendto`, `recvfrom` and `select`. Further
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
