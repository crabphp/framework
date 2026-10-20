# Framework deployment comparison

This compares Crab, webman/Workerman, Hyperf/Swoole, Spiral/RoadRunner, Laravel 12 with Octane/FrankenPHP, Symfony 7 with FrankenPHP workers, Laravel 12 with nginx/PHP-FPM, and Elysia/Bun. Tempest was attempted and excluded under the required PHP 8.4 constraint; see below. Crab is measured with tracing JIT on and off. The reference run uses native ARM64 containers on one Apple M1 Pro under OrbStack.

## Results

Reference run: **2026-10-19 22:36 UTC through 2026-10-20 00:18 UTC** (20 October in the host’s timezone). The [raw run](results/expanded-20261019T223645Z/summary.json) contains **180 measured trials, 36 warmups and 36 preflight checks**. All measured trials and warmups reported zero errors, covering **383,770,262 completed measured responses**. [Recomputed-result audit](results/expanded-20261019T223645Z/audit.json), [unchanged fixture-input audit](results/expanded-20261019T223645Z/fixture-source-audit.json), and [cleanup verification](results/expanded-20261019T223645Z/cleanup-verification.json).

The earlier partial five-configuration run is marked superseded and contributes no numbers to this table.

| Case / connections | Contender | Requests/s, median [range] | p50, ms | p99, ms | Errors | Peak MiB |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| hello / 64 | Crab, JIT off | 200,401 [196,798–203,739] | 0.290 | 0.709 | 0 | 70.4 |
| hello / 64 | Crab, tracing JIT | 205,131 [199,943–210,753] | 0.283 | 0.685 | 0 | 38.8 |
| hello / 64 | Laravel / Octane + FrankenPHP | 4,106 [4,017–4,162] | 15.252 | 26.104 | 0 | 158.9 |
| hello / 64 | Symfony / FrankenPHP | 12,718 [12,560–12,816] | 4.935 | 11.952 | 0 | 115.5 |
| hello / 64 | Laravel / FPM + nginx | 2,001 [1,974–2,031] | 30.762 | 46.076 | 0 | 114.8 |
| hello / 64 | Elysia / Bun | 320,177 [309,411–325,929] | 0.166 | 0.670 | 0 | 114.1 |
| hello / 64 | webman / Workerman | 146,898 [144,496–148,543] | 0.403 | 0.889 | 0 | 76.6 |
| hello / 64 | Hyperf / Swoole | 97,048 [95,748–98,085] | 0.663 | 1.502 | 0 | 64.3 |
| hello / 64 | Spiral / RoadRunner | 5,519 [5,411–5,558] | 10.777 | 20.706 | 0 | 167.9 |
| hello / 256 | Crab, JIT off | 195,528 [190,057–198,760] | 1.273 | 2.681 | 0 | 33.1 |
| hello / 256 | Crab, tracing JIT | 199,003 [191,360–201,487] | 1.264 | 2.612 | 0 | 40.4 |
| hello / 256 | Laravel / Octane + FrankenPHP | 4,099 [3,657–4,111] | 61.765 | 77.109 | 0 | 185.2 |
| hello / 256 | Symfony / FrankenPHP | 13,910 [13,799–14,013] | 18.026 | 39.317 | 0 | 108.3 |
| hello / 256 | Laravel / FPM + nginx | 1,885 [1,735–1,946] | 134.366 | 165.642 | 0 | 84.4 |
| hello / 256 | Elysia / Bun | 327,547 [319,834–333,857] | 0.698 | 1.723 | 0 | 106.8 |
| hello / 256 | webman / Workerman | 147,699 [144,629–149,356] | 1.698 | 3.266 | 0 | 54.0 |
| hello / 256 | Hyperf / Swoole | 97,946 [93,946–100,416] | 2.739 | 4.080 | 0 | 60.2 |
| hello / 256 | Spiral / RoadRunner | 5,422 [5,215–5,536] | 45.018 | 70.867 | 0 | 156.3 |
| db / 64 | Crab, JIT off | 18,367 [17,873–18,398] | 3.473 | 8.063 | 0 | 31.6 |
| db / 64 | Crab, tracing JIT | 18,440 [17,724–18,637] | 3.414 | 7.665 | 0 | 37.4 |
| db / 64 | Laravel / Octane + FrankenPHP | 2,463 [2,056–2,494] | 25.073 | 42.647 | 0 | 158.1 |
| db / 64 | Symfony / FrankenPHP | 7,442 [6,532–7,479] | 8.305 | 17.414 | 0 | 106.9 |
| db / 64 | Laravel / FPM + nginx | 1,084 [881–1,093] | 56.997 | 78.045 | 0 | 83.6 |
| db / 64 | Elysia / Bun | 84,725 [76,820–86,275] | 0.685 | 2.290 | 0 | 136.6 |
| db / 64 | webman / Workerman | 8,314 [7,972–8,486] | 7.191 | 15.512 | 0 | 60.6 |
| db / 64 | Hyperf / Swoole | 20,102 [19,693–20,505] | 3.400 | 5.668 | 0 | 142.7 |
| db / 64 | Spiral / RoadRunner | 3,654 [3,530–3,669] | 16.763 | 26.909 | 0 | 142.4 |
| validate / 64 | Crab, JIT off | 101,389 [98,827–101,963] | 0.599 | 1.317 | 0 | 33.0 |
| validate / 64 | Crab, tracing JIT | 98,975 [97,825–100,342] | 0.608 | 33.730 | 0 | 38.2 |
| validate / 64 | Laravel / Octane + FrankenPHP | 2,870 [2,771–2,904] | 21.831 | 32.909 | 0 | 166.3 |
| validate / 64 | Symfony / FrankenPHP | 10,116 [9,973–10,300] | 6.147 | 14.589 | 0 | 111.9 |
| validate / 64 | Laravel / FPM + nginx | 1,629 [1,530–1,653] | 38.189 | 50.850 | 0 | 84.5 |
| validate / 64 | Elysia / Bun | 160,705 [158,694–164,632] | 0.356 | 0.943 | 0 | 125.2 |
| validate / 64 | webman / Workerman | 11,873 [11,813–12,052] | 5.129 | 10.481 | 0 | 60.6 |
| validate / 64 | Hyperf / Swoole | 13,225 [13,153–13,284] | 4.938 | 10.020 | 0 | 61.8 |
| validate / 64 | Spiral / RoadRunner | 4,912 [4,841–5,018] | 11.844 | 20.903 | 0 | 141.5 |
| Not run | Tempest 3.19.2 — requires PHP 8.5 | — | — | — | — | — |

**Elysia has the highest throughput in every measured case. Crab leads the PHP contenders in hello and validation throughput; Hyperf leads the PHP database case.** On database reads, Hyperf reaches 20,102 requests/s versus Crab’s 18,440 with JIT and 18,367 without. Elysia reaches 84,725. These losses remain in the published table.

**Medium finding — Crab tracing-JIT validation tail latency.** Its median trial p99 is **33.730 ms**, versus **1.317 ms** without JIT. Four of the five JIT trials have p99 between 22.527 and 45.106 ms; the other is 1.417 ms. Its validation throughput median is also 2.4% lower. The cause was not isolated: this is an observed configuration result, not proof of a defect in Crab or PHP’s JIT. No sample was removed and no runtime fix was made.

JIT increases Crab’s hello throughput medians by 2.4% at 64 connections and 1.8% at 256, and database throughput by 0.4%; the corresponding trial ranges overlap. Both configurations remain separate in the table.

The earlier [Crab versus Elysia study](../ELYSIA.md) found Elysia ahead on plain HTTP. Elysia also leads here: 320,177 requests/s for hello at 64 connections and 327,547 at 256, versus Crab’s best respective medians of 205,131 and 199,003. The earlier study used one process, native macOS, plaintext responses and shorter trials; these containerized two-worker JSON results are not a direct before/after performance comparison.

These are achieved rates for these small workloads and resource limits. They are not a universal framework ranking, a server ceiling, or a capacity estimate for a real application.

## Reproduce

From a checkout with the sibling `crab` skeleton repository and the recorded commits available:

```sh
cd framework/benchmarks/frameworks
./run.sh
```

Requirements: Apple Silicon macOS with OrbStack, Docker Compose, Python 3.12 or newer, Internet access for the pinned images/packages, and eight guest CPUs. The script selects the OrbStack Docker context, starts OrbStack if needed, builds the applications in a fresh scratch directory, creates its own database, checks the endpoints, runs the complete comparison, and removes its containers and database volume on exit. It stops OrbStack if the script itself started it and no other containers are running. It keeps the host awake during the run.

Allow about 100 minutes for measurements, plus the first build. Results go into a new `results/` directory. `summary.md` and `summary.json` contain the table; `trials.json`, individual wrk logs, resource samples, runtime cache checks, container configurations, version records and server logs retain the evidence. No run or outlier is silently discarded.

Only ports **23101–23109** and **23306** are published, bound to loopback. MySQL also listens on **23306 inside its container**. The script does not use Redis or the host's MySQL on 3306. The database credentials in Compose are solely for this disposable fixture.

For setup debugging, a short run can be requested explicitly:

```sh
BENCH_WARMUP=1 BENCH_DURATION=2 BENCH_TRIALS=1 ./run.sh
```

Such a run records `full_method: false` and must not supply the published table. The reference measurements use the defaults: 15-second warmups, five 30-second trials, all four case/concurrency combinations.

## Method

- Every application container gets **2 CPU quota units**, guest CPUs **0–1**, **2 GiB memory**, and no additional swap allowance. Each has two HTTP workers; FPM uses two static children. nginx and FPM share one container and one quota, communicating over a Unix socket.
- MySQL gets CPUs **2–3** and 2 GiB. The load generator runs in a separate container outside all contenders, on CPUs **4–7**, with four wrk threads and 512 MiB. This reserves separate guest CPU sets for application, database and client work. macOS still schedules the VM's threads on physical cores; this is not physical-core affinity.
- OrbStack is configured for eight guest CPUs and 8 GiB memory. Only one contender receives load at a time; the others remain idle. App healthcheck processes are disabled uniformly, with explicit readiness and functional checks performed by the harness.
- Cases use 64 persistent HTTP/1.1 connections; `hello` is also run at 256. There is no TLS, response cache, HTTP pipelining or load-generator think time.
- Each case starts fresh application containers. Before measurement, the harness checks full expected responses and representative validation boundaries over a persistent connection. Each contender receives 15 seconds of warmup before its first trial for that case. The five measured trials rotate contender order between rounds. The database stays shared throughout the run.
- Requests cycle through 10,000 variants. Hello changes a query value and a header; the database case cycles through all 10,000 row IDs; validation changes the name, email and age. Every hello response body is checked during load. Database/validation load checks expected status and required body keys; preflight checks exact values. These checks also run during warmup.

The separate client container was chosen so wrk could have a disjoint guest CPU set. Measured traffic crosses the same Linux container bridge for every contender; the host's published-port/NAT path is used for readiness and diagnostic requests, not for load generation.

## Applications and production settings

All PHP contenders use **PHP 8.4.26**, production `php.ini`, OPcache enabled, timestamp validation disabled, and Composer `--no-dev --classmap-authoritative`. OPcache has a 256 MiB budget, 32 MiB for interned strings, 32,531 file slots, and the same realpath-cache settings. Runtime endpoints verify the actual PHP/JIT state, disabled debug mode and authoritative loader.

Crab starts from the pinned skeleton beside the pinned framework, retaining its path repository. It runs `php cua serve --no-watch`, with `APP_DEBUG=false`, `SERVER_WORKERS=2`, queue/scheduler off, and the default PDO database path. Its JIT variants use either disabled JIT or tracing JIT with a 128 MiB buffer. The framework and skeleton runtime source are not patched.

Both Laravel variants use the same application overlay. Config, event, route and view caches are built. Octane runs on the image's FrankenPHP binary with two workers and its documented 500-request recycling policy. FPM uses `pm=static`, two children and no request-count recycling. Their database configuration enables PDO persistence. These settings follow the [Laravel production deployment guide](https://laravel.com/framework/docs/12.x/deployment#optimization), [Octane documentation](https://laravel.com/framework/docs/12.x/octane) and [PDO connection documentation](https://www.php.net/manual/en/pdo.connections.php).

Symfony uses its native 7.4 FrankenPHP worker runtime, two workers, the runtime's default 500-request loop limit, a warmed production cache, dumped environment, inlined service factories and generated OPcache preloading. Its DBAL connection enables persistence. See [FrankenPHP's Symfony integration](https://frankenphp.dev/docs/symfony/), [Symfony's production performance settings](https://symfony.com/doc/7.4/performance.html) and the [DoctrineBundle configuration reference](https://www.doctrine-project.org/projects/doctrine-bundle/en/3.0/configuration.html).

FrankenPHP uses PHP's ZTS build; the other PHP servers use NTS. These are the normal runtime builds for those servers, not identical binaries. The other PHP contenders retain their default JIT state (off); Bun uses its normal JavaScript runtime settings. Worker recycling and the different server/request lifecycles remain part of the comparison.

webman uses `workerman/webman` v2.2.3 with `webman/database` (Illuminate) and the docs-recommended `webman/validation`. There are two Workerman HTTP processes with ext-event 3.1.6, debug off, the current skeleton’s `controller_reuse=false` default, and no development file monitor. Connections remain in each process; PDO persistence is enabled. See the [database integration](https://webman.workerman.net/doc/en/db/tutorial.html) and [recommended validator](https://webman.workerman.net/doc/en/components/validation.html).

Hyperf uses its 3.2 skeleton, Swoole 6.2.3, coroutine HTTP, `worker_num=2`, `APP_ENV=prod`, and a generated DI/scan cache with `SCAN_CACHEABLE=true`. Database work uses its own connection pool and validation uses `hyperf/validation`’s factory. The skeleton’s 100,000-request recycling setting caused socket read errors in the initial load check. `max_request=0` uses Swoole’s default unlimited lifetime to keep persistent connections intact: its [server settings documentation](https://github.com/swoole/docs/blob/master/public/zh-cn/server/setting.md#max_request) explicitly warns that recycling in `SWOOLE_BASE` disconnects clients. The [initial 64-connection evidence](results/setup-evidence/hyperf-default-recycling-hello-64-hyperf-trial-1.log) and [256-connection evidence](results/setup-evidence/hyperf-default-recycling-hello-256-hyperf-trial-1.log) are retained; the final configuration was rechecked before measurement. CLI display errors are off and the common PHP memory limit applies. The [Hyperf validator documentation](https://raw.githubusercontent.com/hyperf/hyperf/3.2/docs/en/validation.md) documents the factory and after-hook used here.

Spiral uses the current `spiral/app` skeleton, RoadRunner 3.0.0 with two PHP workers and no job-count recycling, and Cycle DBAL. Debug is off, tokenizer targets are cached, `php app.php configure` runs after the app overlay, and logs use the error level. The skeleton’s development-only dumper bootloader is removed for `--no-dev`; API routes retain JSON parsing without web-session/CSRF middleware. Cycle keeps its driver in the worker. These follow [Spiral deployment settings](https://spiral.dev/docs/start-deployment/current/en), its [database integration](https://spiral.dev/docs/basics-orm/current/en), and [Spiral Validator](https://spiral.dev/docs/validation-spiral/current/en). The RoadRunner ARM64 release archive is SHA-256 verified against its published release asset digest.

Elysia reuses the earlier benchmark’s pinned **Elysia 1.4.30 / Bun 1.4.2** and lockfile. `NODE_ENV=production`, normal AOT compilation, two serving Bun processes and Linux `reusePort` are enabled, following [Bun’s cluster pattern](https://bun.sh/guides/http/cluster). The parent only supervises processes. Bun 1.4.2 supports MySQL in its [built-in SQL client](https://bun.sh/docs/runtime/sql); no third-party MySQL driver is added. The isolated non-TLS fixture explicitly permits MySQL authentication public-key retrieval.

Crab, Hyperf and Bun use a **maximum of ten pooled database connections per worker**. webman has the same ceiling but serial requests use at most one connection per worker. The other blocking PHP deployments keep one active connection per worker. These are normal synchronous/coroutine behavior differences, not a claim that every framework maintains the same number of open sockets. Pool sizes and database CPU use are recorded so the database case can be interpreted accordingly.

### Case definitions

| Case | Behavior |
| --- | --- |
| `hello` | `GET /hello` returns 200 and `{"message":"Hello, world"}`. |
| `db` | `GET /users/{id}` performs a primary-key read and returns `id`, `name`, `email`, `age`. Crab uses its query builder, Laravel uses Eloquent, Symfony uses Doctrine DBAL, webman uses its Illuminate query builder, Hyperf uses `hyperf/db-connection`, Spiral uses Cycle DBAL, and Elysia uses Bun’s built-in MySQL SQL client. |
| `validate` | `POST /users` validates a 1–100-character name, a syntactically valid email, and a JSON integer age from 18 through 120. It returns the data with 201; invalid checked fields return 422. It does not insert a row. |

The shared MySQL table has 10,000 deterministic rows, one primary index, no timestamp columns, and no result cache. Its 512 MiB InnoDB buffer pool easily contains the working set. This measures warm point reads and the specified query layers, including Eloquent hydration; it is not a driver-only or disk-I/O comparison.

Laravel's string normalization middleware is omitted so input strings retain their bytes. A FormRequest check enforces JSON integer age rather than accepting numeric strings; its after-hook covers empty/whitespace name lengths that Laravel's non-implicit rules otherwise skip. All ordinary field rules still use FormRequest validation. Symfony uses Validator attributes with `MapRequestPayload`; Crab uses its action signature and validation attributes. webman and Hyperf use their documented native validator factories, with the same strict integer and whitespace-length checks as Laravel. Spiral’s native `string::range` trims whitespace, so its native `string::regexp` rule expresses literal 1–100 Unicode-character length; its `integer`, `email`, and numeric range rules remain native.

Elysia validates with `t.Object`. Its default failure status is already **422**, so no status translation is needed. Age uses `t.Number` with `multipleOf: 1` because this pinned release’s `t.Integer` coerces numeric strings. A JSON parse reviver preserves the integer-token requirement (`36.0` is refused, like PHP’s decoded float); name/email use native string length and email format rules. This parser cost is included in its validation timings. Elysia’s [validation documentation](https://elysiajs.com/tutorial/getting-started/validation/) describes the refusal status.

Email validators have different edge-case policies; the common ASCII workload and listed boundary checks do not establish equivalence for all Unicode strings, every possible email, or malformed JSON documents. Error envelopes remain framework-native JSON. During timing, Lua checks the exact hello body, expected statuses, and required database/validation field names; it does not correlate every response with its request ID. Exact values/types and refusal boundaries are checked before loading each fresh container.

## Excluded contender

**Tempest:** its current app skeleton is `tempest/app` v3.1.0 and current framework is v3.19.2. The framework requires **PHP ^8.5**, as stated in its [installation guide](https://tempestphp.com/3.x/getting-started/installation) and [published package](https://packagist.org/packages/tempest/framework). Composer on PHP 8.4.26 refuses that requirement; [the attempt log](tempest/php84-attempt.log) and [a reproducible compatibility probe](tempest/Dockerfile) retain the evidence. Extension checks were ignored only in this dry-run probe to isolate the PHP conflict; PHP’s check was retained. No unsupported runtime or older-release number is substituted.

Tempest documents [FrankenPHP worker support](https://tempestphp.com/blog/frankenphp-tempest), which would be the deployment choice if the PHP constraint changed. Because its current release cannot install under the specified runtime, no Tempest throughput or memory number is reported.

## Versions and provenance

| Component | Measured version / source |
| --- | --- |
| Host | Apple M1 Pro, 8 physical/logical cores, 16 GiB; MacBookPro18,3; macOS 27.2 (26B5112a), ARM64 |
| OrbStack / Docker | OrbStack 2.2.4; Docker 29.8.2; 8 guest CPUs, configured 8 GiB (Docker reports 8,392,925,184 bytes) |
| Linux | `7.2.9-orbstack-00391-g2b298d05fbd4`, aarch64 |
| PHP | 8.4.26 everywhere PHP is used; NTS for CLI/FPM, ZTS for FrankenPHP |
| Crab | framework `4ecdd3826105f0b73d2f0dccc49a90216171601a`; skeleton `b99e28702f06915cd377c0c799837d4d9b97c543` |
| Laravel / Octane | Laravel 12.70.0; Octane 2.22.0; skeleton 12.12.2 |
| Symfony / Doctrine | Symfony FrameworkBundle and Runtime 7.4.21; DBAL 4.5.0; DoctrineBundle 3.3.2; skeleton 7.4.99 |
| webman / Workerman | webman skeleton 2.2.3, framework 2.2.4; Workerman 5.2.3; ext-event 3.1.6 |
| webman database / validation | webman/database 2.1.10; webman/validation 2.2.1; Illuminate database and validation 13.37.0 |
| Hyperf / Swoole | Hyperf skeleton/framework/db-connection 3.2.0; validation 3.2.5; Swoole 6.2.3 with mysqlnd hooks |
| Spiral / RoadRunner | spiral/app 3.9.0; framework 3.17.2; RoadRunner 3.0.0; Cycle DBAL 2.23.3; spiral/validator 1.8.0 |
| Elysia / Bun | Elysia 1.4.30; Bun 1.4.2, using the earlier benchmark’s package and lockfile |
| FrankenPHP / Caddy | FrankenPHP 1.13.1; Caddy 2.11.7 |
| nginx / MySQL / wrk | nginx 1.22.1-9+deb12u10; MySQL 8.4.11; wrk 4.1.0-3+b2 |

Every measured image is ARM64. Full package references and runtime/image details are in the run’s `versions.json`; immutable skeleton revisions and checksums are in `skeletons.json`. The webman integration uses its current Illuminate 13 components, while the Laravel contenders remain on the requested Laravel 12 line.

`images.env` pins image digests. `skeletons.json` records immutable upstream skeleton commits and archive checksums; setup verifies the downloads. Contender Composer locks pin dependencies, and Symfony recipe references are retained in `symfony/symfony.lock`, and the generated recipe files are retained under `symfony/recipe/` so a future recipe update cannot silently change the app. `source-commits.json` pins the local framework and skeleton archives. The per-run source manifest records the actual build inputs, and version/container records identify the images and packages used. The Composer reference for Crab's path package is a path-content reference; the Git revision is the one in `source-commits.json`.

Debian nginx and wrk package versions are pinned. Builds still require the referenced registries, archives and packages to remain available; the run records generated package/recipe/configuration provenance. Different hardware or later software is a new measurement, not a reproduction of these rates.

## Reading the numbers

Requests/s is the median of five measured trials, with the full trial range. p50 and p99 are medians of the five wrk percentiles, not percentiles pooled across all requests. The error column totals socket errors, timeouts and unexpected response statuses/bodies; it is an event count, not a distinct-request error rate. Raw wrk status counters are retained separately to avoid double-counting them. Completed-response rates include error responses when any occur.

Peak memory is the maximum Linux cgroup `memory.peak` for that contender's fresh container during the case, including startup, preflight and warmup. It includes charged file cache and runtime overhead; it is not PHP heap, per-worker RSS or a sum safe to multiply by a worker count. Half-second Docker resource samples and post-trial current memory accompany each trial. CPU percentages count one guest CPU as 100%. Shared image pages and file-cache charging can make memory peaks depend on startup order; a smaller peak here does not establish a smaller intrinsic heap or a JIT memory saving.

OPcache hit percentages are not comparable request-hit rates. Crab keeps loaded classes in its workers and need not include files again on each request; a low hit percentage with flat misses can therefore be normal. The retained runtime snapshots show cached-script counts, hits, misses and cache-full state, together with the long-lived workers' actual runtime settings.

## Limits

One shared development machine, native ARM64 containers under macOS virtualization, a small route table and three synthetic workloads. Closed-loop wrk latency does not model fixed arrival rates, overload queues or Internet latency. Warm local MySQL omits storage misses and remote database latency. There is no authentication, TLS, rendering, real business logic, large response, WebSocket traffic or multi-machine scaling. Short worker lifetimes in this experiment cannot prove days-long memory stability.

The load generator’s largest per-trial mean CPU usage was **212.3% of its 400% allocation**; aggregate client CPU was not saturated. In the database case, Crab averaged about 92–94% of its 200% application allocation, with MySQL at 63–64% of 200%. This is consistent with waiting on its blocking PDO path, an inference rather than a traced diagnosis. Hyperf and Elysia used about 198% application CPU; their MySQL figures were 121% and 175%, respectively. The comparison includes those normal driver/pool behavior differences.

The host was not otherwise isolated. Recorded snapshots show other activity, roughly 5 GiB of compressed memory and 2.9 GiB of used host swap; sampled thermal/performance warning checks reported none. Physical CPU placement, clock frequency and background activity were not controlled. The ranges show the observed variation, including slower first database trials. They are not confidence intervals, and these measurements do not establish a maximum server capacity. Only the benchmark containers were observed running during the final run.

## Layout

- `crab/`, `laravel-octane/`, `laravel-fpm/`, `symfony/`, `webman/`, `hyperf/`, `spiral/`, `elysia/`: Dockerfiles, setup and application changes. The Laravel app links share `laravel/app/` to prevent handler drift.
- `common/`: common PHP settings; `mysql/`: the disposable dataset; `loadgen/`: wrk and response checks.
- `compose.yaml`, `run.sh`, `prepare.py`, `runner.py`, `cleanup.py`: reproducible orchestration and owned-resource cleanup.
- `results/`: raw trials, environment, cache checks, configuration, resource samples, logs and summaries.

## Retained observations and cleanup

The JIT validation finding is supported by all five raw logs in the [raw trial records](results/expanded-20261019T223645Z/trials.json); for example, [JIT trial 3](results/expanded-20261019T223645Z/validate-64-crab-jit-trial-3.log) and [non-JIT trial 3](results/expanded-20261019T223645Z/validate-64-crab-off-trial-3.log). Runtime snapshots verify the JIT flag remained on or off as configured and show no OPcache-full condition.

Crab readiness, including Docker Compose overhead, was 0.30–0.58 seconds. Its post-trial current container readings were 22.6–37.8 MiB without JIT and 25.7–37.4 MiB with JIT; no case had a monotonic increase across its five readings. Cache misses stayed flat within each Crab case. These short, container-level observations do not establish long-term heap stability.

The run removed its containers, network and MySQL volume. The task also stopped its keep-awake process and OrbStack service/background app, which it had started. Host Redis on 6379 and MySQL on 3306 were not used.
