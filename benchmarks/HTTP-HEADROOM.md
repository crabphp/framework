# PHP HTTP headroom — 2026-10-03

These diagnostics predate the [HTTP codec optimization](HTTP-CODEC-OPTIMIZATION.md),
which implements and measures the Date and metadata hypotheses below. Source
descriptions and tables here refer to the diagnostic snapshot.

This experiment asks how much of Crab's small-response HTTP cost comes from its
engine layers on this host. It cannot establish a language-wide PHP maximum.
The minimal responders deliberately support a smaller protocol surface than
Crab and are diagnostic fixtures, not replacement production servers.

**There is measured headroom:** the restricted PHP/JIT responder achieved
117,739–122,041 requests/s across the two connection counts, versus
66,011–68,255 for full Crab/JIT. The largest step in the diagnostic comparison
is adding Crab's real parser and response encoder. This justifies investigating
HTTP codec costs before changing the event-loop architecture. It does not mean
that every difference is removable or that full Crab already beats Bun.

## Results

Apple M1 Pro, 8 cores, 16 GiB RAM, macOS 26.7.1; PHP 8.4.19 NTS,
Revolt 1.0.9 with `StreamSelectDriver`, Bun 1.4.2, Elysia 1.4.30, wrk 4.2.0.
The main matrix used two wrk threads, a three-second warmup followed by ten
seconds of measurement, and three fresh-server trials per case. The nine-target
order rotates by three positions between trials. Rates and latency percentiles
below are separate medians of three trials.

| Target | 32 connections, req/s | 128 connections, req/s |
| --- | ---: | ---: |
| `php-select` | 106,855 | 107,956 |
| `php-select-jit` | 117,739 | 122,041 |
| `php-revolt-jit` | 111,643 | 112,076 |
| `crab-transport-jit` | 105,392 | 105,017 |
| `crab-codec-jit` | 73,152 | 72,462 |
| `crab` | 54,562 | 55,312 |
| `crab-jit` | 66,011 | 68,255 |
| `bun` | 99,810 | 100,646 |
| `elysia` | 98,459 | 98,277 |

At 128 connections:

| Target | p50 ms | p95 ms | p99 ms | Server CPU | Approx. CPU µs/request | Peak sampled RSS, MiB |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `php-select` | 1.195 | 1.330 | 2.032 | 96.3% | 9.00 | 31.6 |
| `php-select-jit` | 1.046 | 1.399 | 2.031 | 96.3% | 8.01 | 32.5 |
| `php-revolt-jit` | 1.132 | 1.320 | 2.212 | 96.2% | 8.73 | 33.3 |
| `crab-transport-jit` | 1.230 | 1.338 | 1.413 | 96.6% | 9.33 | 33.7 |
| `crab-codec-jit` | 1.753 | 1.892 | 1.969 | 96.4% | 13.48 | 34.1 |
| `crab` | 2.280 | 2.512 | 2.622 | 97.4% | 17.86 | 32.3 |
| `crab-jit` | 1.838 | 2.052 | 2.169 | 97.2% | 14.45 | 34.6 |
| `bun` | 1.257 | 1.417 | 2.249 | 97.3% | 9.86 | 39.2 |
| `elysia` | 1.294 | 1.447 | 2.277 | 97.4% | 10.07 | 50.1 |

CPU values are medians of per-trial cumulative counter measurements. RSS is
the largest sample across the three trials. The minimal PHP/JIT C128 rates
ranged from 116,349 to 124,121; full Crab/JIT ranged from 68,250 to 69,084.
Use medians rather than treating the fastest trial as a ceiling.

All **54 main trials passed**, completing 50,728,583 measured requests with
zero reported status/socket errors or timeouts. All serving processes exited
with status zero and empty logs; all 41 tracked source/configuration files
remained unchanged during the run. [Main raw results](results/compare-http-headroom.json).

### Load-generator check

Three further trials per target used four wrk threads at 128 connections,
with the same warmup, duration, serving process and source. This follow-up ran
after the main matrix, so thread effects are not isolated from host drift.

| Target | Two threads, req/s | Four threads, req/s | Change |
| --- | ---: | ---: | ---: |
| `php-select-jit` | 122,041 | 113,909 | −6.7% |
| `crab-jit` | 68,255 | 68,704 | +0.7% |
| `bun` | 100,646 | 100,498 | −0.1% |

More client threads did not produce a material throughput increase. Combined
with roughly one busy server core, this does not suggest a two-thread generator
ceiling as the main explanation for Crab's rate. It still does not establish a
server maximum. The restricted PHP fixture slowed in this follow-up; shared-host
effects matter. [Four-thread raw results](results/compare-http-headroom-threads4.json).

All nine follow-up trials passed with zero reported errors, clean server exits,
empty logs and unchanged source hashes. The main matrix and this check completed
**63 trials and 59,304,767 measured requests**. No production source changed from
the [previous optimized comparison](results/compare-elysia-optimized.json).

## What to investigate next

At 128 connections the restricted responder on Crab's transport achieved
105,017 req/s, while adding the real codecs produced 72,462. Full HTTP session
handling produced 68,255. The associated CPU time per request rose more at
the codec step than the session step. These contrasts identify a useful area
to profile, not individual removable costs.

The first small experiments should measure a per-second generated Date cache,
reuse of validated header metadata with established immutable ownership, and avoiding token
array construction when Connection headers are absent. Preserve strict framing
and validation, explicit Date values, HTTP/1.0 persistence, HEAD/bodyless
semantics, and streaming/backpressure behavior. Test both reused and newly
created Response objects so a static-handler-specific gain is visible.
These are hypotheses; this experiment changes no production engine code.
PHP `readonly` alone does not establish ownership of externally referenced
array elements; any metadata cache must account for that before reusing values.

The targeted [Actix codec notes](ACTIX-CODEC-NOTES.md) distinguish transferable
encoding/buffering practices from Rust-specific facilities. They do not propose
adding a dependency or importing Actix's architecture wholesale.

## Experiment

All targets receive persistent HTTP/1.1 `GET /` requests on loopback and return
the exact 16-byte `Hello from Crab\n` body with status 200,
`text/plain; charset=utf-8`, Content-Length and a current Date header. Each
response invokes a handler. No TLS, compression, database or application work
is included. Each target uses one serving process.

| Target | Work performed |
| --- | --- |
| `php-select` | Restricted HTTP framing and response assembly over nonblocking PHP streams and direct `stream_select`; OPcache on, JIT off. |
| `php-select-jit` | Same fixture with tracing JIT. |
| `php-revolt-jit` | Same restricted responder and socket adapter with Revolt's `StreamSelectDriver`. |
| `crab-transport-jit` | Same restricted responder over Crab's `Listener` and `Connection`, with Revolt. |
| `crab-codec-jit` | Crab's parser and response encoder over that transport, within the diagnostic fixture. |
| `crab` | Full current Crab HTTP server; OPcache on, JIT off. |
| `crab-jit` | Full current Crab HTTP server with tracing JIT. |
| `bun` | Native Bun GET route function returning a fresh Response. |
| `elysia` | Elysia GET route function returning the matching string. |

The minimal `select`, `revolt` and `transport` modes accept only bodyless
`GET / HTTP/1.1`, with one Host field and an absent or zero Content-Length.
Unsupported requests are rejected and closed. They handle fragmented headers, pipelining, connection
closure and partial writes with bounded buffers. It omits full HTTP request
body handling, streaming response producers, upgrades, asynchronous application
and cancellation lifecycle management, and phase deadlines. Codec mode does
cancel each completed request. The minimal variants cache only the Date value per second; they
assemble response bytes for each request. The codec variant retains an extra
fixture header-boundary scan before invoking Crab's real parser and encoder.
Crab's real encoder formats Date for each response, so the minimal-to-codec
difference also includes that work. Codec mode uses the real parser's header
rules but feeds it header blocks only; its surrounding fixture still does not
serve general HTTP request bodies or streaming responses.

Differences between adjacent rows are diagnostic evidence, not an exact CPU
cost decomposition: scheduling, generated JIT code, allocation and caching
can interact. In particular, the gap from a restricted responder to full Crab
includes useful production behavior as well as potentially avoidable work.

## Method and limitations

The runner starts a fresh server for every trial, verifies the response and
connection behavior, warms it, then invokes wrk. Targets rotate between trials
to reduce ordering bias. The benchmark lock prevents overlapping owned runs.
Raw files retain executable versions, PHP configuration, commands, source
hashes before and after, all trial rates and latency percentiles, errors,
server logs, CPU counters and sampled RSS. A source change or failed trial
fails the run.

Server CPU time is a cumulative `ps` counter difference across load launch,
execution and cleanup, divided by measured wall time. It is an approximation
of serving-process utilization; the interval extends slightly beyond wrk's
measurement. Sampled client CPU is an OS estimate. RSS includes shared pages.

Client and server share this Apple M1 Pro development machine, which also runs
desktop applications. macOS scheduling, shared CPU resources, thermal changes
and loopback networking affect results. Closed-loop wrk latency does not measure
queueing under a fixed externally offered request rate. Checks validate body bytes before
measurement; measured wrk checks status/socket errors and timeouts, not every
response body. The result does not establish production capacity, overload
behavior, large-connection scalability, Linux performance, or multicore worker
scaling. More client threads are a local bottleneck check, not a substitute
for an independent load host.

## Validation

Bounded checks cover exact body and headers, persistent connections, fragmented
headers, pipelines, closing, ten explicit PHP rejection cases, disconnects, and
a 2,048-response slow-reader batch for each PHP backend. The batch verifies byte
preservation; it does not prove a particular socket write was partial.
The four PHP backends passed with JIT off and tracing enabled; Bun and Elysia
passed the shared supported-request checks. The codec was rechecked after
removing a duplicate keep-alive calculation before the measured run.
[Initial off report](results/baseline-validation-off.json),
[initial JIT report](results/baseline-validation-jit.json),
[final codec off](results/baseline-validation-codec-off.json),
[final codec JIT](results/baseline-validation-codec-jit.json).

The [nine-target smoke run](results/compare-http-headroom-smoke-integer.json)
passed. An [earlier setup attempt](results/compare-http-headroom-smoke.json)
stopped before measurement because wrk rejected a fractional-second warmup;
the runner now rejects fractional durations at argument parsing. That setup
failure is retained and excluded from performance summaries. PHP/Python syntax
checks and `git diff --check` passed. The production suite was not rerun because
the production source is unchanged; new fixture behavior was checked directly.

## Reproduce

From `engine/`, use PHP 8.4, Composer development dependencies, wrk and the
benchmark's pinned Elysia package. Set `BUN_BIN` to Bun 1.4.2; the existing
[runtime metadata](results/bun-1.4.2-runtime.json) records the verified binary.

```sh
python3 benchmarks/baselines/verify.py --jit=off --bun="$BUN_BIN" \
  --output=benchmarks/results/baseline-validation-repeat-off.json
python3 benchmarks/baselines/verify.py --jit=tracing \
  --output=benchmarks/results/baseline-validation-repeat-jit.json
python3 benchmarks/compare.py --label=http-headroom-repeat --protocol=http \
  --targets=php-select,crab-jit,bun,php-revolt-jit,elysia,crab-transport-jit,php-select-jit,crab,crab-codec-jit \
  --bun="$BUN_BIN" --concurrency=32,128 --threads=2 \
  --duration=10 --warmup=3 --trials=3
python3 benchmarks/compare.py --label=http-headroom-repeat-threads4 --protocol=http \
  --targets=php-select-jit,crab-jit,bun --bun="$BUN_BIN" \
  --concurrency=128 --threads=4 --duration=10 --warmup=3 --trials=3
```

Use a new label to preserve prior results. The PHP and Bun baseline fixtures
live in [baselines/](baselines/); no baseline is loaded by the production engine.
