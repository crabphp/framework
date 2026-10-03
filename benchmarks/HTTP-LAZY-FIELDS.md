# HTTP lazy request fields — 2026-10-04

This work follows the [HTTP fast path](HTTP-FAST-PATH.md) and targets its
weakest case: requests with browser-sized fields that differ on every request.
With tracing JIT, one worker now serves that workload at **92,170 requests/s,
up from 75,673 (+22%)**, against 103,723 for Elysia on Bun in the same run:
89% of Elysia's rate, up from 73%.

## Changes

- **Validate eagerly, split lazily.** Every head is still validated in full
  before a handler runs, with the same limits and error statuses. The complete
  `Request::$headers` map is built on first access. `header()` and
  `hasToken()` read one field from the validated head without building the
  map. The parser extracts only Host, Connection, Expect, Content-Length and
  Transfer-Encoding, which decide framing and connection handling.
- **One pattern for the usual request.** A buffer holding exactly one bodyless
  head is validated, and its framing fields captured, by a single anchored
  regular expression. A body, pipelined input, a repeated framing field or a
  malformed head fails that match and takes the general parser, which remains
  the only place that decides error statuses. The pattern is tried once per
  head, so a trickled head is not rescanned.
- **API.** `Request` is no longer a `readonly` class, because the field map is
  now a computed property. Its properties remain read-only to callers, and the
  constructor is unchanged.

A handler that reads the complete map still pays for the split, about 2.5 µs
for 16 fields in an isolated measurement. A handler that reads a few fields by
name, or none, does not. The benchmark handler reads none, so this result is
the upper end of the gain.

## Result

One fresh serving process per trial, 128 connections, two wrk threads, a
two-second warmup and three five-second measurements per target, with rotating
target order. The `browser-unique` workload sends 15 browser-sized request
fields plus Host, rotates 997 query strings on one route and changes one field
value on every request; each request receives a fresh Response.

| Server | Req/s | Range | p50, ms | p99, ms | CPU µs/request | Peak RSS, MiB |
| --- | ---: | --- | ---: | ---: | ---: | ---: |
| Before, tracing JIT | 75,673 | 75,637–75,711 | 1.669 | 1.948 | 12.96 | 34.8 |
| After, tracing JIT | 92,170 | 91,191–92,247 | 1.380 | 2.661 | 10.67 | 35.3 |
| Elysia / Bun | 103,723 | 103,664–104,527 | 1.218 | 2.288 | 9.62 | 50.0 |

Every optimized trial exceeded every baseline trial. Crab's p99 rose while its
median latency fell. All 9 trials passed, 4,154,502 measured requests with zero
reported errors and unchanged source hashes.
[Raw trials](results/compare-http-lazy-fields.json).

Parsing this 725-byte head fell from 2.96 µs to 1.14 µs in an isolated
measurement. A native sample of the optimized server attributes about two
thirds of its main-thread time to `sendto`, `recvfrom` and `select`.

Environment: Apple M1 Pro (8 cores), macOS 26.7.1 arm64, PHP 8.4.19 NTS with
CLI OPcache and tracing JIT, Revolt 1.0.9 `StreamSelectDriver`, wrk 4.2.0,
Elysia 1.4.30 on Bun 1.4.2. Client and server share the host. Only this one
workload and JIT configuration were measured in this round.

## Discarded runs

Two earlier attempts at this comparison stopped because one trial of the new
build reported a single wrk timeout. Repeating the workload outside the
harness gave 0 of 46 runs with a timeout for the new build and 1 of 30 for the
previous one, and ten consecutive harness trials of each build passed. The
timeouts are treated as a rare host or load-generator artifact that affects
both builds, not as lost requests; they are noted here because they were not
explained.

## Correctness

The suite passes with **327 tests and 2,069 assertions**, normally and with
tracing JIT in random order (seed `20261003`). New cases compare single-field
lookups with the complete map before and after it is built, for names that
extend framing names, repeated fields, absolute-form targets and framed
bodies. A differential fuzz of 150,000 mutated and fragmented requests gave
identical requests, single-field lookups, parser phases and error statuses on
the original eager parser and this one. The fuzz script is not part of the
repository.

## Reproduce

The [patch](changes/http-lazy-fields.patch) is the complete source delta;
reverse it in a copy of `src` to reconstruct the baseline.

```sh
lazy_baseline=$(mktemp -d)
cp -R src "$lazy_baseline/src"
patch -R -p1 -d "$lazy_baseline" < benchmarks/changes/http-lazy-fields.patch

python3 benchmarks/compare.py --label=http-lazy-fields-repeat --protocol=http \
  --targets=crab-before-jit,crab-jit,elysia \
  --baseline-source="$lazy_baseline/src" --bun=/path/to/bun-1.4.2 \
  --http-response=fresh --http-request=browser-unique \
  --concurrency=128 --duration=5 --warmup=2 --trials=3
```

The limits of the earlier pages apply: one development machine, loopback,
short closed-loop trials. These are achieved local rates, not a ceiling,
production capacity or a general comparison between runtimes.
