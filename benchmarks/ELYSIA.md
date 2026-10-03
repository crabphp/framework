# Crab versus Elysia — 2026-10-03

Profiling-led changes improved HTTP throughput by **32% with tracing JIT** and **38–44% without JIT** versus the version profiled. Elysia still leads HTTP in these tests. Crab leads the measured binary WebSocket echo cases; the near-saturated client prevents a claim about either server's maximum.

The later [HTTP headroom experiment](HTTP-HEADROOM.md) compares restricted PHP responders, Crab's transport and codecs, and raw Bun to locate remaining costs. Those diagnostic responders implement a smaller protocol surface than the complete servers compared here. The subsequent [HTTP codec optimization](HTTP-CODEC-OPTIMIZATION.md) measures the production changes and repeats the Elysia control.

Measurements use an Apple M1 Pro (8 cores, 16 GiB), macOS 26.7.1. Server and client share the development machine. These are achieved rates on short, closed-loop workloads, not production capacity or a demonstrated server ceiling.

Versions: PHP 8.4.19 NTS, Revolt 1.0.9 with explicit `StreamSelectDriver`, Elysia 1.4.30, Bun 1.4.2, Node 25.1.0, wrk 4.2.0. Bun was downloaded into an isolated temporary directory and checked against its official release checksum; [verification metadata](results/bun-1.4.2-runtime.json) preserves the URL and hashes. Elysia is pinned in [its benchmark-only package](elysia/package.json) and [lockfile](elysia/bun.lock).

Each target uses one serving process. Every trial starts a fresh server, warms it for two seconds, then measures for five seconds. Target order rotates between three trials. Tables report medians of those three trials, including separate medians for each latency percentile. A common lock prevents overlapping benchmark runs.

`crab` enables CLI OPcache and disables JIT. `crab-jit` enables CLI OPcache plus tracing JIT with a 128 MiB buffer; the environment probe verifies that JIT is on. These are benchmark process flags, not engine requirements. Elysia uses production mode with its normal AOT and routing defaults.

## HTTP

Persistent HTTP/1.1 `GET /`, status 200, exact 16-byte `Hello from Crab\n` body, and `text/plain; charset=utf-8`. Both fixtures invoke a handler on each request. Elysia's function handler follows its [documented handler API](https://elysiajs.com/essential/handler), avoiding the distinct inline static-response workload. Crab reuses its immutable response object, as its existing fixture did. Both include Date and Content-Length; Crab also emits explicit Connection headers. No compression or TLS is involved. wrk uses two threads.

Readiness checks validate status and body and capture headers. Measured wrk trials check socket/status errors and timeouts, not each response body.

| Connections | Target | Requests/s | p50 ms | p95 ms | p99 ms |
| ---: | --- | ---: | ---: | ---: | ---: |
| 32 | crab | 55,240 | 0.564 | 0.654 | 0.740 |
| 32 | crab-jit | 67,064 | 0.466 | 0.539 | 0.604 |
| 32 | elysia | 99,225 | 0.290 | 0.556 | 0.632 |
| 128 | crab | 56,209 | 2.246 | 2.470 | 2.599 |
| 128 | crab-jit | 68,774 | 1.828 | 2.050 | 2.178 |
| 128 | elysia | 100,010 | 1.266 | 1.439 | 2.333 |

Compared with [the version profiled](results/compare-elysia-lazy-body.json):

| Connections | Target | Before req/s | After req/s | Change |
| ---: | --- | ---: | ---: | ---: |
| 32 | crab | 40,022 | 55,240 | +38.0% |
| 32 | crab-jit | 50,728 | 67,064 | +32.2% |
| 128 | crab | 38,981 | 56,209 | +44.2% |
| 128 | crab-jit | 52,032 | 68,774 | +32.2% |

The corresponding Elysia control changed by less than 0.3% at each concurrency. The runs remain short and share host resources, so these are local measured gains, not universal improvement guarantees. The changes reduce extra socket reads, default cancellation exception allocation, and timer churn. [Profiles, exact patches, and correctness checks](HTTP-PROFILE.md).

## WebSocket

Masked binary echo on `/echo`, 64-byte and 1,024-byte messages, one outstanding message per connection. The same Node client validates handshakes, framing, payload and order on both targets, then completes a close exchange. Compression is disabled. Elysia uses its [documented WebSocket API](https://elysiajs.com/patterns/websocket) with explicit binary sends and no application schema. Text/JSON parsing and application middleware are outside this workload.

The client consumed about one CPU core. These results show achieved throughput differences in this setup, not either server's maximum. Latency percentiles are histogram bucket upper bounds, with approximately 1.1% resolution. JIT did not consistently improve this client-constrained workload.

| Connections | Payload bytes | Target | Messages/s | p50 ms | p95 ms | p99 ms | Client CPU |
| ---: | ---: | --- | ---: | ---: | ---: | ---: | ---: |
| 32 | 64 | crab | 97,684 | 0.324 | 0.539 | 0.649 | 100.1% |
| 32 | 64 | crab-jit | 96,646 | 0.317 | 0.576 | 0.670 | 99.9% |
| 32 | 64 | elysia | 82,699 | 0.353 | 0.678 | 0.772 | 96.7% |
| 32 | 1024 | crab | 85,782 | 0.365 | 0.608 | 0.739 | 100.9% |
| 32 | 1024 | crab-jit | 82,780 | 0.365 | 0.663 | 0.780 | 100.5% |
| 32 | 1024 | elysia | 72,164 | 0.407 | 0.780 | 0.879 | 97.8% |
| 128 | 64 | crab | 99,234 | 1.285 | 2.092 | 2.626 | 100.5% |
| 128 | 64 | crab-jit | 100,894 | 1.217 | 2.092 | 2.570 | 100.6% |
| 128 | 64 | elysia | 83,708 | 1.528 | 1.740 | 2.803 | 97.0% |
| 128 | 1024 | crab | 85,307 | 1.463 | 2.408 | 3.090 | 101.3% |
| 128 | 1024 | crab-jit | 84,889 | 1.447 | 2.382 | 2.959 | 101.3% |
| 128 | 1024 | elysia | 71,031 | 1.778 | 2.025 | 2.357 | 98.0% |

## Verification and resource observations

All **54 final trials in this comparison** passed, with zero reported protocol/socket/status errors or timeouts, zero server exit status, empty server logs, and unchanged measured source hashes. Production source changed later in the [HTTP codec optimization](HTTP-CODEC-OPTIMIZATION.md); these results describe the recorded snapshot. The full suite at this point passed with **148 tests and 871 assertions**, normally and with tracing JIT plus randomized order (seed 20261003). Zero benchmark errors alone do not establish protocol conformance.

[Final raw comparison](results/compare-elysia-optimized.json) retains all commands, per-trial throughput, latency, errors, logs, configuration, CPU/RSS samples, source hashes, and runtime versions.

Largest sampled serving-process RSS across the final measured trials:

| Target | Peak sampled RSS |
| --- | ---: |
| crab | 32.7 MiB |
| crab-jit | 35.2 MiB |
| elysia | 51.8 MiB |

RSS and CPU are OS samples; RSS can include shared pages and is not exclusive physical memory usage. Server startup and longer-lived memory behavior are not established by these samples. HTTP uses up to two wrk threads, while WebSocket uses one Node client. Both are closed-loop workloads; percentiles can hide overload delay. Measure representative application work and controlled offered load from a separate, unsaturated host before setting capacity limits.

## Optimization checkpoints

These sequential checkpoints document the source changes. They are not a randomized isolated causal experiment for each individual patch; the final interleaved comparison above is the retained end result. Every one of the **144 trials across these six datasets** passed its recorded error checks.

| Dataset | HTTP JIT C32 req/s | HTTP JIT C128 req/s |
| --- | ---: | ---: |
| [elysia-baseline-http](results/compare-elysia-baseline-http.json) | 48,938 | 48,543 |
| [elysia-lazy-body](results/compare-elysia-lazy-body.json) | 50,728 | 52,032 |
| [http-short-read](results/compare-http-short-read.json) | 52,412 | 52,302 |
| [http-lazy-cancellation](results/compare-http-lazy-cancellation.json) | 56,162 | 55,695 |
| [http-reused-deadlines](results/compare-http-reused-deadlines.json) | 67,627 | 70,122 |
| [elysia-optimized](results/compare-elysia-optimized.json) | 67,064 | 68,774 |

The initial dataset predates lazy body allocation. The second dataset is the baseline for the profiling-led read, cancellation, and deadline changes. Later checkpoints add those changes in order. Source hashes and [patches](changes/) preserve the implementation deltas.

## Reproduce

From `engine/`, with Composer development dependencies installed, PHP 8.4, wrk and Node available, point `BUN_BIN` at Bun 1.4.2:

```sh
BUN_BIN=/absolute/path/to/bun-1.4.2
cd benchmarks/elysia
"$BUN_BIN" install --frozen-lockfile
cd ../..
python3 benchmarks/compare.py --label=elysia-repeat --protocol=all \
  --targets=crab,elysia,crab-jit --bun="$BUN_BIN" \
  --concurrency=32,128 --payloads=64,1024 \
  --duration=5 --warmup=2 --trials=3
```

Use a fresh label to retain an earlier result. The runner starts and stops only its owned process groups and fails the run on errors, failed cleanup, or source changes. It serializes with the other benchmark harnesses. The [profiler guide](profiling/README.md) describes the separate diagnostic-copy workflow; instrumented rates do not belong in throughput comparisons.
