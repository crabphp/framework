# HTTP profiling and optimization — 2026-10-03

The profiles identified avoidable transport and lifecycle work. Three targeted changes followed: yield after a short read, allocate default cancellation exceptions only when observed, and reuse a connection's deadline timer across phase transitions. Request parsing, framing, timeout limits, cancellation, and cleanup remain active.

## Evidence

A three-second native sample of the PHP process under HTTP load had 2,462 main-thread samples. 771 were in `sendto`, 485 in `recvfrom`, and 56 in `select`. These are sampled stack observations, not exact CPU accounting. [Native sample](results/http-native-profile.txt), [run metadata](results/http-native-profile.json).

An instrumented copy then counted operations and measured nested spans during a two-second warmup and five-second load, with 32 connections and tracing JIT. It completed 130,957 requests. Approximately half its reads returned no data after a preceding short read. Socket writes and reads were the largest independently measured spans; timer allocation and cancellation exception construction were additional costs.

| Operation per completed request | Before | After |
| --- | ---: | ---: |
| Socket reads | 2.0002 | 1.0002 |
| Default cancellation exception construction | 1 | 0 |
| Driver timer creation | 3.0003 | 0.0006 |
| Driver callback cancellation | 4.0008 | 1.0011 |
| Socket writes | 1 | 1 |

The optimized profile completed 166,495 requests. The small residual read/timer/cancellation counts include startup, connection closure, and shutdown. Timers still check the current phase's absolute expiry; small creation counts describe this short keep-alive workload, not every application.

The before/after profiles used instrumented copies rather than modifying production with counters. Probe bookkeeping measured about 2.96 seconds in the initial run, versus 7.15 seconds of total process CPU. Instrumentation also affects JIT behavior. Its timings are diagnostic wall spans: inclusive spans overlap, selected self spans do not cover all CPU work, and throughput from instrumented runs is excluded from benchmark tables. Operation counts and the uninstrumented throughput trials provide the stronger before/after evidence.

[Initial profile](results/http-instrumented-profile.json), [initial source manifest](results/http-profile-manifest.json), [optimized profile](results/http-optimized-profile.json), [optimized source manifest](results/http-optimized-profile-manifest.json). The preserved [profiling tool](profiling/README.md) instruments all three cancellation-construction sites in the optimized implementation; zero means the unobserved fast path did not construct an exception.

## Changes and behavior

- **Reads:** after receiving fewer bytes than requested, return to the event loop with read interest maintained. Subsequent data and EOF are delivered by later readiness notifications. Full reads keep the existing I/O budget. [Patch](changes/short-read.patch).
- **Cancellation:** cancelled state is independent of the exception object. First cancellation still wins; explicit reasons and their previous exceptions retain their semantics, and current/late observers share one reason. The default exception's diagnostic trace now starts at first observation. [Patch](changes/lazy-cancellation.patch).
- **Deadlines:** store the current monotonic expiry and the scheduled wake-up. Keep an earlier wake-up when the phase extends the expiry, replace it when an earlier deadline is needed, and inspect the current phase when it fires. Partial body arrivals do not refresh the absolute body deadline. [Patch](changes/http-deadline-reuse.patch).
- **Empty bodies:** an earlier change delayed temporary-stream allocation until the first nonempty append. Upload spooling and read cursor behavior are preserved. [Patch](changes/http-lazy-body.patch), [allocation probe](results/lazy-body-probe.json).

The full suite passed with **148 tests and 871 assertions**, both normally and with tracing JIT plus randomized order (seed 20261003). New cases cover continued reads and half-close, cancellation identity/reentrancy/subscriber failure, later and earlier phase deadlines, slow partial bodies, response cancellation/finally cleanup, and idle expiry.

See [the runtime comparison](ELYSIA.md) for uninstrumented throughput, latency, errors, source hashes, and reproduction commands. The largest remaining measured operations include socket I/O and response production; this work does not establish a universal performance ceiling or justify a parser/scheduler rewrite without further evidence.
