# Engine benchmarks

See [HTTP codec optimization](benchmarks/HTTP-CODEC-OPTIMIZATION.md) for the latest matched before/after HTTP measurements and WebSocket regression checks, [PHP HTTP headroom](benchmarks/HTTP-HEADROOM.md) for the earlier layer diagnostics, and [Crab versus Elysia](benchmarks/ELYSIA.md) for the earlier combined comparison. The historical snapshots below predate later docblock, request-body storage and codec changes; their hashes identify the source measured at the time.

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
