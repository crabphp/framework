# HTTP codec optimization — 2026-10-03

This work follows the [HTTP layer measurements](HTTP-HEADROOM.md), which pointed
to parsing and response encoding as the largest remaining diagnostic gap.
The changes retain PHP 8.4, Revolt's select driver and the existing HTTP and
WebSocket contracts. They add no production dependency.

## Changes and safeguards

- Generated Date values are formatted once per observed wall-clock second.
  The cache refreshes on either forward or backward changes, using the sampled
  second for formatting. Explicit Date headers retain their existing behavior;
  timeout deadlines continue to use the monotonic clock.
- Missing Connection headers bypass empty token-array construction. Explicit
  close tokens, HTTP/1.0 persistence, upgrades, and the existing empty-token
  behavior remain covered by tests.
- Response header normalization is reused only after a Response has been
  successfully normalized twice. New responses avoid reference scanning and
  retained header-cache allocation. Any top-level or list-element PHP reference
  disables reuse, preserving later mutations and validation failures. Cache
  storage belongs to the Response object; there is no global object registry.
  Request-specific framing and generated headers remain outside the cache.

## Isolated checkpoints

Each checkpoint uses alternating before/after serving processes, C128, tracing
JIT, two wrk threads, a two-second warmup and three five-second measurements per
target. Both targets run the same benchmark fixture and installed dependencies.
Snapshots, reflected class paths and hashes verify which source each server loads.
These checkpoints are incremental comparisons; use the final comparison below
for the combined improvement.

| Change | Response mode | Before req/s | After req/s | Change |
| --- | --- | ---: | ---: | ---: |
| Date and absent-token work | reused | 69,414 | 74,905 | +7.9% |
| Date and absent-token work | fresh per request | 67,924 | 72,870 | +7.3% |
| Header normalization reuse | reused | 74,642 | 76,359 | +2.3% |
| Header normalization reuse | fresh per request | 73,519 | 73,761 | +0.3% |

The fresh-response header-cache result is within noise; it is not a demonstrated
gain. Its control trials ranged from 71,583 to 77,196 req/s. The reused-response
candidate was faster in each paired trial. The later experiment also evaluates
fresh responses to check the combined cost of these changes.

Raw checkpoints: [Date/tokens reused](results/compare-http-date-tokens.json),
[Date/tokens fresh](results/compare-http-date-tokens-fresh.json),
[header cache reused](results/compare-http-header-cache-reuse.json),
[header cache fresh](results/compare-http-header-cache-fresh.json).

A further candidate appended generated headers directly to the output string.
It gained only 0.17% with reused responses and 0.68% with fresh responses,
with inconsistent paired results. That implementation was rejected because
these trials did not establish a useful gain. Its wire/framing regression tests
remain. The [candidate patch](changes/http-generated-headers-candidate.patch)
and [reused](results/compare-http-generated-headers-reuse.json) /
[fresh](results/compare-http-generated-headers-fresh.json) measurements preserve
the experiment; the patch is not part of the retained production changes.

## Final matched comparison

The combined production changes were compared against the original snapshot,
with one fresh serving process per trial, two wrk threads, a two-second warmup
and three five-second measurements per case. Target order rotates between
trials. Tables report medians, except RSS which is the largest sampled value.

Environment: Apple M1 Pro (8 cores), macOS 26.7.1 arm64, PHP 8.4.19 NTS,
Revolt 1.0.9 `StreamSelectDriver`, wrk 4.2.0, Node v25.1.0, Elysia 1.4.30
and an explicitly pinned Bun 1.4.2 executable. The installed global Bun was
not replaced. PHP CLI OPcache is enabled throughout; JIT is shown separately.

### Reused responses

| Connections | JIT | Before req/s | After req/s | Change | p99 before → after, ms |
| ---: | --- | ---: | ---: | ---: | ---: |
| 32 | off | 55,224 | 60,859 | +10.2% | 0.697 → 0.638 |
| 32 | tracing | 67,404 | 72,798 | +8.0% | 0.582 → 0.543 |
| 128 | off | 55,664 | 60,668 | +9.0% | 2.538 → 2.350 |
| 128 | tracing | 69,942 | 75,253 | +7.6% | 2.050 → 1.911 |

Every optimized trial exceeded every baseline trial within its corresponding
case. The final combined gains are measured independently from the incremental
checkpoints; they should not be calculated by multiplying checkpoint gains.

At C128, the complete-server control and serving-process observations were:

| Server | Req/s | p50, ms | p95, ms | p99, ms | CPU | CPU µs/request | Peak RSS, MiB |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Before, JIT off | 55,664 | 2.268 | 2.447 | 2.538 | 96.3% | 17.78 | 32.0 |
| After, JIT off | 60,668 | 2.075 | 2.256 | 2.350 | 96.6% | 16.31 | 32.2 |
| Before, tracing JIT | 69,942 | 1.802 | 1.968 | 2.050 | 96.4% | 14.15 | 34.7 |
| After, tracing JIT | 75,253 | 1.674 | 1.833 | 1.911 | 95.5% | 13.12 | 34.9 |
| Elysia / Bun | 102,100 | 1.264 | 1.418 | 2.170 | 96.7% | 9.71 | 49.0 |

CPU percentages use cumulative serving-process CPU time divided by load-process
wall time; 100% represents one core. CPU time per request is an estimate with
OS counter resolution and launch/cleanup overhead, not a function profile.
Small RSS differences are snapshots, not evidence of long-term memory growth.

Elysia remains faster: optimized Crab/JIT delivers 73.7% of its C128 throughput,
up from 68.5% for the original source in this same run. Elysia reached 99,466
req/s at C32. Neither the improvement nor the lower Crab/JIT p99 at C128
establishes a general performance or latency advantage across applications.

[All 30 reused-response trials](results/compare-http-codec-final.json).

### Fresh responses

Allocating a new Response for every request still improves throughput. This
avoids depending on response reuse for the overall result; the header cache
itself is not credited with improving this case.

| Connections | JIT | Before req/s | After req/s | Change | p99 before → after, ms |
| ---: | --- | ---: | ---: | ---: | ---: |
| 128 | off | 54,888 | 57,840 | +5.4% | 2.592 → 2.454 |
| 128 | tracing | 69,404 | 72,586 | +4.6% | 2.071 → 1.969 |

Every optimized trial exceeded every baseline trial within its corresponding
case. Peak sampled RSS was 32.0 → 32.1 MiB without JIT and 34.6 → 34.6 MiB
with tracing JIT. [All 12 fresh-response trials](results/compare-http-codec-final-fresh.json).

### WebSocket regression check

The same before/after snapshots were compared at C128 with OPcache enabled and
JIT disabled. Masked binary echoes use one outstanding message per connection;
the client validates every echoed payload. Each trial has a two-second warmup
and five-second measured interval, with three trials per case.

| Payload | Before messages/s | After messages/s | Change | p99 before → after, ms |
| ---: | ---: | ---: | ---: | ---: |
| 64 bytes | 100,984 | 101,922 | +0.9% | 2.626 → 2.626 |
| 1,024 bytes | 87,433 | 87,042 | −0.4% | 3.024 → 2.991 |

The small differences fall within the overlapping trial ranges; this check
found no material regression and establishes no WebSocket optimization gain.
The unchanged echo path is limited in part by a Node client using about one
CPU core (100.6–101.3%). Peak sampled server RSS remained 32.3–32.5 MiB.
Latency percentiles use logarithmic bucket upper bounds with about 1.1%
resolution. [All 12 WebSocket trials](results/compare-http-codec-final-ws.json).

All **54 final trials passed**, totaling **14,926,746 measured HTTP requests**
and **5,671,589 measured WebSocket echoes**, with zero reported socket/status/
protocol errors or timeouts. Every server exited with status zero, logs had
only expected source metadata, and current plus baseline source hashes remained
unchanged. A final audit rechecked those hashes against the files on disk and
verified the reflected source roots. [Audit summary](results/http-codec-final-audit.json).

## Benchmark scope

Persistent HTTP/1.1 `GET /`, exact 16-byte `Hello from Crab\n` body, current Date,
Content-Length and `text/plain; charset=utf-8`. Each request invokes a handler.
`reuse` retains the original immutable-body Response; `fresh` creates a new
Response for every request. Neither disables request validation, cancellation,
phase deadlines, streaming or backpressure in the server.

One serving process per target on the shared Apple M1 Pro/macOS development
host. These small closed-loop workloads are achieved local rates, not a PHP
ceiling or production capacity estimate. Readiness checks validate exact bytes
and connection behavior; wrk checks status/socket errors and timeouts during
measurement, not each response body. Raw artifacts retain all trials, latency,
CPU counters, RSS samples, runtime configuration, commands, startup source
metadata and source hashes before/after each run.

The benchmark-only `--baseline-source` option loads a saved `src/` snapshot
through Composer's PSR-4 mapping and class map. It does not change production
autoloading. Only the expected `CRAB_SERVER_METADATA` startup line is emitted
by these fixtures; benchmark results retain it and verify its paths.

## Correctness and reproduction

The complete suite passed with **234 tests and 1,087 assertions**, both normally
and with tracing JIT plus randomized order (seed `20261003`). The added cases
cover Date refresh in both clock directions, explicit header behavior, absent
tokens, external references and later invalid mutations, validation timing,
copy-on-write isolation, framing, streaming and exact header-size boundaries.
An independent source review found no correctness blockers.

The [retained production patch](changes/http-codec-optimization.patch) changes
only Request, Response and ResponseEncoder. Reversing it in a copy of the final
source reconstructs all 29 PHP files in the
[original source manifest](results/http-codec-before-manifest.json), with exact
SHA-256 matches; see the [reconstruction check](results/http-codec-reproduction-check.json).
The saved temporary baseline path in raw results is local to this run. To
reconstruct it elsewhere, run from `engine/` at this measured source revision:

```sh
codec_baseline=$(mktemp -d)
cp -R src "$codec_baseline/src"
patch -R -p1 -d "$codec_baseline" < benchmarks/changes/http-codec-optimization.patch
```

With PHP/Composer dependencies, wrk, Node and the Elysia fixture installed,
use a Bun 1.4.2 binary for the matching Elysia control:

```sh
python3 benchmarks/compare.py --label=http-codec-repeat --protocol=http \
  --targets=crab-before,crab-jit,elysia,crab,crab-before-jit \
  --baseline-source="$codec_baseline/src" --bun=/path/to/bun-1.4.2 \
  --http-response=reuse --concurrency=32,128 --duration=5 --warmup=2 --trials=3

python3 benchmarks/compare.py --label=http-codec-repeat-fresh --protocol=http \
  --targets=crab-before,crab-jit,crab,crab-before-jit \
  --baseline-source="$codec_baseline/src" --bun=/path/to/bun-1.4.2 \
  --http-response=fresh --concurrency=128 --duration=5 --warmup=2 --trials=3

python3 benchmarks/compare.py --label=http-codec-repeat-ws --protocol=ws \
  --targets=crab-before,crab --baseline-source="$codec_baseline/src" \
  --bun=/path/to/bun-1.4.2 --concurrency=128 --payloads=64,1024 \
  --duration=5 --warmup=2 --trials=3
```

`crab` and `crab-before` both enable CLI OPcache with JIT disabled. Their `-jit`
variants use tracing JIT with a 128 MiB buffer. Before/after targets share the
same fixture, dependency tree and runtime configuration. No production
autoloading or default runtime setting is changed by the benchmark options.
