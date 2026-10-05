# Crab framework architecture

Status: **agreed 2026-10-04.** Sections marked *(today)* describe code that exists. Sections marked *(proposed)* are agreed but not built yet. The rules that follow from this document are in [AGENTS.md](AGENTS.md). The engine has its own document, [src/Engine/ARCHITECTURE.md](src/Engine/ARCHITECTURE.md).

## Goals

1. **Lean.** A small core that an application can read in an afternoon. Features are components you add, not weight you carry.
2. **Standard.** PHP-FIG interfaces wherever one exists, so third-party code plugs in and ours can be used elsewhere.
3. **Long-running by design.** The process serves many requests, so the rules about where state may live are part of the architecture, not an afterthought.
4. **Scalable.** Workers are stateless and interchangeable; adding workers or machines adds capacity.
5. **Measured.** Request-path cost is measured against the engine alone whenever it changes.

## Layers

Each layer may depend only on the layers below it.

~~~mermaid
flowchart TD
    APP[Applications and third-party packages]
    GLUE[Glue: App, built-in commands, app-level helpers]
    COMP[Components: Http, Routing, Container, Config, Log, Console, ...]
    CON[Contracts and PSR interfaces]
    ENG[Engine: sockets, HTTP/1.1, WebSocket, workers, timers]
    APP --> GLUE
    APP --> CON
    GLUE --> COMP
    COMP --> CON
    COMP --> ENG
    CON --> ENG
~~~

| Layer | Path | May depend on |
| --- | --- | --- |
| Engine *(today)* | `src/Engine/` | PHP only, plus Revolt inside the one adapter folder `src/Engine/EventLoop/`; enforced by `tests/EngineBoundaryTest.php` |
| Contracts *(today)* | `src/Contracts/` | PSR interfaces, other contracts, engine types |
| Components *(today)* | `src/<Component>/` | Contracts, PSR interfaces, the engine; another component only where that is its purpose |
| Glue *(today)* | `src/App.php`, `src/helpers.php`, `src/Commands/` | Everything above |
| Applications and packages | outside this repository | **Contracts and PSR interfaces only**, plus `App` in the one file that builds it |

The last row is the rule that keeps the framework replaceable piece by piece: a package never names a component's class.

### Enforcing the layers *(today)*

`tests/LayerBoundaryTest.php` fails when a contract references a component, or when a component references the glue or a component it is not listed as built on. Today the listed pairs are `Routing` on `Http` and `Testing` on `Container`. `tests/EngineBoundaryTest.php` does the same for the engine. A rule that is only written down drifts.

## Contracts

A contract exists for everything a package touches, including where no PSR exists. `App` binds each contract in the container, so a package asks for the contract and never for the class.

| Contract | Extends | Status |
| --- | --- | --- |
| `Http\Request` | PSR-7 `ServerRequestInterface` | today |
| `Http\MiddlewareStack` | none | today |
| `Container\Container` | PSR-11 `ContainerInterface` | today |
| `Provider`, `WorkerProvider` | none | today |
| `Console\Command`, `Input`, `Output` | none | today |
| `Config\Config` | none | today |
| `Database\Database`, `Database\Connection`, `Database\Query` | none | today |
| `Routing\Router` | PSR-15 `RequestHandlerInterface` | today |
| `Console\Console` | none | today |
| Logger, cache, events, clock, HTTP client | used directly as PSR-3, 16, 14, 20, 18 | no Crab contract unless we add a method |

Contracts are the stable surface. Once there is a release, a contract changes only in a major version; component classes may change freely behind it.

## Component anatomy

Every component has the same shape, so the tenth looks like the first.

| Part | Rule |
| --- | --- |
| Folder | `src/<Component>/`, namespace `Crab\<Component>` |
| Standalone | Works with `new`, without `App`, the container or a provider |
| Contract | In `src/Contracts/<Component>/` if packages touch it |
| Provider *(proposed)* | An optional component ships `<Component>Provider` that binds its contract; core components are bound by `App` |
| Helpers | `helpers.php` in the folder, only for functions that need this component alone |
| Tests | `tests/<Component>/`, exercising the component without `App` |
| Package *(proposed)* | Its own `composer.json`, published read-only from this repository as `crabphp/<component>`, the way the engine already is |

Publishing components separately is what lets someone use the router or container without the framework. It costs a split step at release time and nothing day to day.

## Lifecycle and where state lives

A long-running server has four phases. The most common bug in this style of framework is state created in the wrong one.

| Phase | Runs in | What happens | State created here |
| --- | --- | --- | --- |
| 1. Define | Parent process, once | Load `.env` and config, create `App`, register providers, declare routes, middleware and commands | Definitions only: closures, class names, read-only config. No sockets, no files, no connections. |
| 2. Start | Each worker, after the engine forks | Build the middleware chain; run worker-start hooks | Worker-scoped objects: connections, pools, timers |
| 3. Request | Each worker, many times, possibly overlapping | Request → error handler → middleware → router → handler → response | Request-scoped data, carried **only** on the request object |
| 4. Stop | Each worker | Drain in-flight requests, run worker-stop hooks, close connections | none |

All four phases exist today. A provider that implements `WorkerProvider` gets `start(Worker $worker)` in phase 2 and `stop(Worker $worker)` in phase 4. Providers start in the order registered and stop in reverse. `stop()` runs only in a worker where every provider started, and after the event loop has stopped, so it cannot suspend.

### The three scopes

| Scope | Lifetime | Examples | Rule |
| --- | --- | --- | --- |
| Process | Whole run, copied into every worker at fork | Config, route table, provider list | Fixed after phase 1; during it, providers may add defaults beneath the application's configuration |
| Worker | One worker's life | Container singletons, connection pools, logger | Shared by every request in that worker; must hold nothing belonging to one request |
| Request | One request | The request, its attributes (route parameters, authenticated user, request id) | Travels as the PSR-7 request and its attributes; never stored in a service or a static |

Consequences worth stating plainly:

- There is no `request()` or `auth()` global helper. Global helpers are for process-scope values only (`config()`, `env()`). Request data is read from the request the handler was given.
- A container entry is worker-scoped by default. Anything per-request is passed as an argument.
- `env()` is called only inside config files. Everything else reads `config()`, so configuration has one source and can later be cached.

## Concurrency model *(decided; the database follows it)*

The engine runs each request in its own fiber. A handler that calls blocking PHP (PDO, `curl_exec`, `file_get_contents` on a URL) blocks its whole worker until it returns. This decision shapes the database, cache, queue and HTTP client components, so it should be made before any of them.

| | A. Blocking drivers | B. Async drivers |
| --- | --- | --- |
| How it scales | More workers; one in-flight request per worker while it blocks | Many in-flight requests per worker |
| Libraries | Any PHP library works (PDO, Guzzle, Redis extension) | Only fiber-aware clients; we write or adopt each one |
| Dependencies | None beyond PHP extensions | New runtime dependencies or a lot of our own protocol code |
| Risk | Lower throughput per worker under slow I/O | Larger surface to build and get right |

**Decision: write the contracts for B, ship drivers for A first.** Concretely:

- Components that do I/O expose a **pool** in their contract (`acquire()` a connection, use it, `release()` it), even when the first driver is blocking PDO with a pool of one per worker. Code written against the pool is correct under both models.
- No shared service keeps per-request state, as the scopes above already require.
- Async drivers can then be added later, per component, behind the same contracts, without touching application code.

This keeps the framework lean now and does not close the door on high-concurrency workers.

Components take every async tool from the engine's own types (`EventLoop`, `Suspension`, `Pool`, `Task`), never from a third-party library, so the scheduler can be replaced without touching them. The pool itself is the engine's `Async\Pool`: bounded, first come first served, with a wait that times out or is cancelled. The database is the first component built on it, with blocking PDO behind the `Database` and `Connection` contracts.

## Scaling out

- **Stateless workers.** A worker holds nothing another worker needs. Sessions, cache, queues and locks live in external stores behind contracts, so any worker on any machine can serve any request.
- **Process scaling.** `serve --workers=N` on one machine; identical instances behind a load balancer across machines. TLS terminates at the proxy (the engine has no TLS).
- **Isolation by worker group.** HTTP, queue consumers and the scheduler run as separate engine worker groups, so a slow job cannot starve request handling.
- **Bounded everything.** The engine already bounds connections, tasks, buffers and body size per worker; framework components that queue or buffer take an explicit limit too.
- **Graceful change.** SIGTERM drains; SIGUSR1 replaces workers one at a time. Deploying new code restarts the parent.

## Extension points

Small and explicit; there are five, and no others are planned.

| Point | How | Status |
| --- | --- | --- |
| Services | `Provider::register(Container)` | today |
| Per-worker work | `WorkerProvider::start(Worker)` / `stop(Worker)` | today |
| Around requests | PSR-15 middleware via `$app->middleware()`, or the `MiddlewareStack` contract from a provider | today |
| Commands | `Console` contract, `add()` | today |
| Reacting to events | PSR-14 dispatcher | proposed, when the first component needs it |

No auto-discovery, no facades, no `boot()` phase, no global container accessor.

## Errors and observability

- **One error path per entry point.** HTTP requests go through `ErrorHandler` *(today)*. Console commands report through `Console::run()` *(today)*. A task spawned on a worker that fails, a failure that escapes a timer, and a provider that fails to stop are written to the application's logger, and the worker keeps running *(today)*. Queue jobs and scheduled jobs get the same guarantee when those components are built.
- **The engine drops nothing.** Whatever it catches goes to the group's failure callbacks, and so to the application's logger, or to standard error when there are none *(today)*.
- **Fail at startup, not at first use.** Misconfiguration stops `serve` with its cause *(today for middleware and logger)*.
- **Request ids** *(today, opt-in)*. With `app.request_id` on, the outermost middleware assigns an id, stores it as the `request_id` attribute, returns it in `X-Request-Id` and the error handler adds it to the failure's log record. It is opt-in because it costs about 2 µs per request. The logger is shared by overlapping requests, so it cannot add the id by itself; code that logs passes it in the context.
- **Health and metrics** *(proposed)*. A built-in health route, and `Worker::stats()` exposed through a command or route.
- **Warnings are errors** *(today, in workers)*. Inside a worker, PHP warnings and notices are thrown as `ErrorException` so they reach the same error path; deprecations are logged once per place. Console commands other than `serve` run without this.
- **One error renderer** *(today)*. `Http\ErrorResponse` builds every error response, for the error handler and for the router's 404 and 405, so they look the same and unmatched routes still pass back through middleware as ordinary responses.

## Testing

| Level | What | Where |
| --- | --- | --- |
| Component | Each component alone, no `App`, no sockets; providers with worker hooks through `Testing\WorkerHarness` | `tests/<Component>/` |
| Application | `$app->handle($request)` end to end, no sockets | `tests/AppTest.php` |
| Engine | Real sockets, workers, shutdown | `tests/Engine/` |
| Boundary | Layer rules | `tests/EngineBoundaryTest.php`, `tests/LayerBoundaryTest.php` |
| Manual | Linux container run, long soak | `composer test:linux`, `composer soak` |

A PSR implementation should also pass that PSR's public conformance suite where one exists. The PSR-7 suite did not install alongside PHPUnit 13 when tried; that is unresolved.

## Build order

1. **Close the contract gaps:** `Config`, `Router`, `Console` contracts; boundary tests for the layer rules. *Done.*
2. **Worker lifecycle hooks:** `start` and `stop` for providers; error path for background tasks. *Done.*
3. **Finish Http:** response cookies, multipart uploads, JSON bodies, bodies for router 404 and 405, request id middleware, warnings to exceptions. *Done.* Still open: multipart is read in memory, and the request body is copied from the engine's spool.
4. **Small PSR components:** clock (PSR-20), events (PSR-14), cache (PSR-16).
5. **Database.** *Connection pool, the thin query layer and the query builder are done, ahead of step 4, tested on SQLite and MySQL.* Next, in this order and each needing its own decisions: models with relationships, migrations.
6. **Queue**, then **Scheduling**, each as its own worker group.
7. **Validation, Session, Auth.**
8. **Application skeleton** (`crabphp/crab`) and the `make:*` commands. *The skeleton exists, kept minimal; it grows a folder when a component arrives. The `make:*` commands are not built.*
9. **Per-component packages**, CI and the first tagged release.

## Decisions

All agreed on 2026-10-04:

1. **Contracts:** Crab keeps its own contract for everything a package touches.
2. **Concurrency model:** contracts are written for async drivers; blocking drivers ship first.
3. **Worker hooks:** a provider may also implement `WorkerProvider`, with `start(Worker)` and `stop(Worker)`.
4. **Packaging:** each component is published as its own package, at step 9 of the build order.
5. **Global helpers:** process-scope values only (`config()`, `env()`), permanently.
