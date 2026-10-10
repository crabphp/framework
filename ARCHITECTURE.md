# Crab framework architecture

Status: **agreed 2026-10-04, scope narrowed 2026-10-10** (see [Decisions](#decisions)). Sections marked *(today)* describe code that exists. Sections marked *(proposed)* are agreed but not built yet. The rules that follow from this document are in [AGENTS.md](AGENTS.md). The engine has its own document, [src/Engine/ARCHITECTURE.md](src/Engine/ARCHITECTURE.md).

## Goals

1. **Lean.** A small core that an application can read in an afternoon. Features are components you add, not weight you carry.
2. **Standard.** PHP-FIG interfaces wherever one exists, so third-party code plugs in and ours can be used elsewhere.
3. **Long-running by design.** The process serves many requests, so the rules about where state may live are part of the architecture, not an afterthought.
4. **Scalable.** Workers are stateless and interchangeable; adding workers or machines adds capacity.
5. **Measured.** Request-path cost is measured against the engine alone whenever it changes.
6. **Readable by people and by AI agents.** The primary use is a JSON API. Someone, or something, reading a file should understand it without knowing the rest: explicit types on everything, no hidden globals, no auto-discovery, one way to do each thing, and the rules written down in [AGENTS.md](AGENTS.md).

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
| Glue *(today)* | `src/App.php`, `src/AppBuilder.php`, `src/Route.php`, `src/Command.php`, `src/helpers.php`, `src/Commands/` | Everything above |
| Applications and packages | outside this repository | **Contracts and PSR interfaces only**, plus `App` in the one file that builds it |

The last row is the rule that keeps the framework replaceable piece by piece: a package never names a component's class.

### Enforcing the layers *(today)*

`tests/LayerBoundaryTest.php` fails when a contract references a component, or when a component references the glue or a component it is not listed as built on. Today the listed pairs are `Routing` on `Http` and `Validation`, `Auth` on `Http`, `HttpClient` on `Http`, and `Testing` on `Container`. The cache, the queue and the scheduler need none: where one uses the database, the queue or the cache, it asks for that component's contract. `tests/EngineBoundaryTest.php` does the same for the engine. A rule that is only written down drifts.

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
| `Database\Schema`, `Database\Table`, `Database\Column`, `Database\Migration`, `Database\Migrator` | none | today |
| `Routing\Router` | PSR-15 `RequestHandlerInterface` | today |
| `Routing\RouteInfo` | none; a value object | today |
| `Validation\Validator`, `Validation\Rule` | none | today |
| `Console\Console` | none | today |
| `Clock\Clock` | PSR-20 `ClockInterface` | today |
| `Http\Middleware` | PSR-15 `MiddlewareInterface` | today |
| `Auth\Identity`, `Auth\PasswordHasher` | none | today |
| `Auth\TokenStore`, `Auth\IdentityResolver` | none | today, for authentication by token |
| `Log\Logger` | PSR-3 `LoggerInterface` | today |
| `Http\Factory` | the five PSR-17 factory interfaces | today |
| `HttpClient\Client` | PSR-18 `ClientInterface` | today |
| `HttpClient\Response` | PSR-7 `ResponseInterface` | today |
| `HttpClient\CookieJar` | none | today |
| `HttpClient\File` | none; a value object | today |
| `Cache\Cache` | PSR-16 `CacheInterface` | today |
| `Cache\Store` | none; where a cache keeps its entries | today |
| `Events\Dispatcher` | PSR-14 `EventDispatcherInterface` | today |
| `Queue\Queue`, `Queue\Store` | none | today |
| `Queue\Message` | none; a value object | today |
| `Schedule\Schedule`, `Schedule\Task` | none | today |
| `Runner` | none; runs worker processes that serve no requests | today |

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

## Actions *(today)*

The primary use is a JSON API, so what goes into a route is the contract with every client. An action declares it in its signature, and the framework does the HTTP around it:

~~~mermaid
flowchart LR
    REQ[Request] --> VAL[Validate against the signature]
    VAL -->|does not fit| E422[422, problems by field]
    VAL -->|fits| H["Action: fn (int $id, string $name): User"]
    H -->|data| JSON[JSON, 200]
    H -->|a response| ASIS[Sent as it is]
~~~

- An action takes the request's data as typed parameters, one per field, or as one input class when there are many. It returns data, or a response when it needs to set the status, a header or the body. It extends nothing and builds no response in the common case, so it can be called and tested as an ordinary function.
- The signature is read once, in the parent, when the route is added. An action the router cannot call, or a field that cannot be validated, stops the application from starting.
- `Validation` builds values from request data by the declared types and rules; it knows nothing of HTTP. `Routing` turns a mismatch into a 422 through `Http\ErrorResponse`, as an ordinary response, so it passes back through middleware.
- Services are never action parameters. An action class takes them in its constructor, which keeps "what the request sends" and "what the application provides" apart.
- Status and headers are set in code, with the `Response` factories. There is no second place that states them, so nothing can fall out of step.
- There is no generated description of the API. An OpenAPI document written from the signatures was built and then removed: PHP has no generics and no inference of a type from a value, so it could say what goes in but not the status or much of what comes out, and keeping it true was a cost on every later feature.

## Lifecycle and where state lives

A long-running server has four phases. The most common bug in this style of framework is state created in the wrong one.

| Phase | Runs in | What happens | State created here |
| --- | --- | --- | --- |
| 1. Define | Parent process, once | Load `.env` and config, create `App`, register providers, declare routes, middleware and commands | Definitions only: closures, class names, read-only config. No sockets, no files, no connections. |
| 2. Start | Each worker, after the engine forks | Build the middleware chain; run worker-start hooks | Worker-scoped objects: connections, pools, timers |
| 3. Request | Each worker, many times, possibly overlapping | Request → error handler → middleware → router → action → response | Request-scoped data, carried **only** on the request object |
| 4. Stop | Each worker | Drain in-flight requests, run worker-stop hooks, close connections | none |

All four phases exist today. A provider that implements `WorkerProvider` gets `start(Worker $worker)` in phase 2 and `stop(Worker $worker)` in phase 4. Providers start in the order registered and stop in reverse. `stop()` runs only in a worker where every provider started, and after the event loop has stopped, so it cannot suspend.

### The three scopes

| Scope | Lifetime | Examples | Rule |
| --- | --- | --- | --- |
| Process | Whole run, copied into every worker at fork | Config, route table, provider list | Fixed after phase 1; during it, providers may add defaults beneath the application's configuration |
| Worker | One worker's life | Container singletons, connection pools, logger | Shared by every request in that worker; must hold nothing belonging to one request |
| Request | One request | The request, its attributes (route parameters, authenticated user, request id) | Travels as the PSR-7 request and its attributes; never stored in a service or a static |

Consequences worth stating plainly:

- The logged-in user is request scope like everything else about a request: middleware puts it on the request and an action takes it as a parameter.
- There is no `request()` or `auth()` global helper. Global helpers and static names are for process-scope things only (`config()`, `env()`, `Route::get()`, `Command::add()`). Request data is read from the request the action was given.
- A container entry is worker-scoped by default. Anything per-request is passed as an argument.
- `env()` is called only inside config files. Everything else reads `config()`, so configuration has one source and can later be cached.

## Concurrency model *(decided; the database follows it)*

The engine runs each request in its own fiber. An action that calls blocking PHP (PDO, `curl_exec`, `file_get_contents` on a URL) blocks its whole worker until it returns. This decision shapes the database, cache, queue and HTTP client components, so it should be made before any of them.

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
- **Isolation by worker group.** HTTP, queue consumers and the scheduler run as separate engine worker groups, so a slow job cannot starve request handling. Today each is a command of its own, `serve`, `queue:work` and `schedule:work`, and so a process tree of its own; the queue's and the scheduler's are started through the `Runner` contract, which `App` implements.
- **Bounded everything.** The engine already bounds connections, tasks, buffers and body size per worker; framework components that queue or buffer take an explicit limit too.
- **Graceful change.** SIGTERM drains, and a connection that looks idle is read a moment longer, since a request may already be on its way to it. New code needs a new process, because everything defined in phase 1 lives in the server's parent: `reload` has the server's keeper start a new server process on the socket it holds, and retire the old one only once the new one accepts connections. If the new one cannot start, the old one is left serving. SIGUSR1 still replaces the workers of one server process one at a time, with the code it already has.

## Middleware *(today)*

There is one kind of middleware, PSR-15's, and two places to put it.

| | Around every request | Around some routes |
| --- | --- | --- |
| Declared | `withMiddleware()` in `app.php` | `middleware:` on a route or a group |
| Runs when no route matches | Yes | No |
| For | Errors, request ids, CORS, logging | Authentication, limits, permissions |

~~~mermaid
flowchart LR
    R[Request] --> G["Every request's<br/>(prepended, the framework's, appended)"]
    G --> RT[Router]
    RT -->|no route| NF[404, back out through the first box]
    RT --> GR["The groups'"] --> RO["The route's"] --> H[Action]
~~~

- **The framework's own middleware is a list anyone can read,** `App::MIDDLEWARE`, and an application says how its own relate to it: before, after, without one of them, or in place of them all. Nothing the framework runs around a request is fixed.
- **The same class works in either place.** Where it runs is decided where it is used, never by the class.
- **Features are middleware, not switches.** Request ids and authentication are things an application adds to a list, so what runs around a request is all in two places: `app.php` and the routes file.
- **All of it is made when a worker starts,** the application's and every route's, so a middleware that cannot be made stops the server from starting. A route naming a middleware that does not exist is refused sooner, when the route is declared.
- **A repeat runs once.** The same name or object given by a group and again by its route runs once; two objects of one class are two middleware and both run.

## Running the server *(today)*

One class, the glue's `Server`, runs the application's HTTP server and controls a running one; the commands are that class with words around it.

| Way | Command | For |
| --- | --- | --- |
| Watched | `serve` | Development, and so the default: a launcher runs the server as a child and starts it again when a source file changes |
| Foreground | `serve --no-watch` | Containers and process managers that keep a process in the foreground |
| Background | `serve --daemon`, then `status`, `reload`, `restart`, `stop` | A machine you deploy to |

A server in the background is three levels of process:

~~~mermaid
flowchart TD
    K["Keeper: holds the listening socket.<br/>Its process id is the server's, for good."]
    G1["Server process (generation):<br/>phase 1 runs here, then it forks"]
    W1[Worker]
    W2[Worker]
    G2["New generation, started by a reload<br/>on the same socket"]
    K --> G1
    G1 --> W1
    G1 --> W2
    K -.-> G2
~~~

- **New code is a new process.** PHP cannot redefine a class, and the application is defined in the server process before it forks, so neither a watcher nor a reload tries to change a running process. The watcher stops the old one first, since a moment's gap does not matter in development. A reload overlaps the two.
- **The keeper is what makes the overlap exact.** It binds the address once and passes the socket to each generation it starts, so the old and the new accept from one queue and no connection is left without a taker. It serves nothing and loads nothing new, so it never needs replacing itself, and what a generation opens is never inherited by the next.
- **A reload is all or nothing.** The keeper retires the old generation only when the new one reports that every worker accepts connections; otherwise it ends the new one and says why. A generation that dies by itself is started again.
- **Starting is not done until it works.** `serve --daemon` and `reload` return when connections are accepted, or fail with the reason, so a deploy script can trust the exit status.
- A background server writes its process id to `server.pid` and what it prints to `server.log`. A foreground server writes neither and is controlled by whatever started it.

## Extension points

Small and explicit; there are six, and no others are planned.

| Point | How | Status |
| --- | --- | --- |
| Services | `Provider::register(Container)` | today |
| Per-worker work | `WorkerProvider::start(Worker)` / `stop(Worker)` | today |
| Around requests | Middleware. Around every request: `withMiddleware()` in `app.php`, or the `MiddlewareStack` contract from a provider. Around some: `middleware:` on a route or a group. | today |
| Commands | `Console` contract, `add()` | today |
| Reacting to events | The `Events\Dispatcher` contract, PSR-14: `listen()` in a provider's `register()` | today, opt-in with `EventsProvider` |
| Worker processes | The `Runner` contract: `run($group, $work, $workers)` from a command | today |

No auto-discovery, no `boot()` phase, no global container accessor. `Route::get()` and `Command::add()` are static names for `$app->router` and `$app->console`, for declaring routes and commands before workers start; they are the only ones, and there is none for anything a request or a worker owns.

## Errors and observability

- **One error path per entry point.** HTTP requests go through `ErrorHandler` *(today)*. Console commands report through `Console::run()` *(today)*. A task spawned on a worker that fails, a failure that escapes a timer, and a provider that fails to stop are written to the application's logger, and the worker keeps running *(today)*. A queue job that throws is logged, tried again and in the end kept as failed; a scheduled task that throws is logged and runs again when next due; neither stops its worker *(today)*.
- **The engine drops nothing.** Whatever it catches goes to the group's failure callbacks, and so to the application's logger, or to standard error when there are none *(today)*.
- **Fail at startup, not at first use.** Misconfiguration stops `serve` with its cause *(today for middleware and logger)*.
- **Request ids** *(today, opt-in)*. `Http\RequestId` is middleware an application adds with `withMiddleware(prepend: [RequestId::class])`; it assigns an id, stores it as the `request_id` attribute, returns it in `X-Request-Id` and the error handler adds it to the failure's log record. It is opt-in because it costs about 2 µs per request. The logger is shared by overlapping requests, so it cannot add the id by itself; code that logs passes it in the context.
- **Health** *(today, opt-in)*. `withRouting(health: '/up')` adds a route answered by `Http\Health`, which says that the worker takes requests and asks nothing of any store.
- **Metrics** *(proposed)*. `Worker::stats()` exposed through a command or route.
- **CORS** *(today, opt-in)*. `Http\Cors` is middleware an application prepends, with its origins as an argument.
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
3. **Finish Http:** response cookies, multipart uploads, JSON bodies, bodies for router 404 and 405, request id middleware, warnings to exceptions. *Done.* A multipart body is read where the engine stored it, and an uploaded file is never held in memory. Still open: `getBody()` copies a body from the engine's storage when it is asked for.
4. **Small PSR components:** clock (PSR-20), events (PSR-14), cache (PSR-16), HTTP client (PSR-18). *Done.* The client speaks HTTP/2 and HTTP/1.1 on the event loop, with TLS through PHP's streams, an HTTP proxy, a cookie jar, retries and a fake for tests, and finds addresses with the engine's `DnsResolver`. The cache keeps its entries in files, in a database table or in memory, behind a `Store` contract that a Redis store can later implement.
5. **Database.** *Connection pool, the thin query layer, the query builder, the schema builder and migrations are done, tested on SQLite and MySQL.* Models with relationships are not planned; see the decisions. Next: a MySQL driver that waits on the event loop, behind the `Connection` contract as it is, so that a worker serves other requests while a query runs.
6. **Queue**, then **Scheduling**, each as its own worker group. *Done.* Jobs are kept in a database table and run by `queue:work`; scheduled tasks are run by `schedule:work`, with no cron entry. Next: letting `serve` start both beside the HTTP workers, under the keeper, so that one command and one reload cover all three.
7. **Validation, Auth.** *Validation is done, together with typed actions. Authentication is done: a way of authenticating is middleware that puts the user on the request, an action takes the user as a parameter, and tokens in the database are one such way, opt-in, with a migration the application owns.* Roles and permissions, and sessions, are not planned; see the decisions.
8. **Application skeleton** (`crabphp/crab`) and the `make:*` commands. *The skeleton exists, kept minimal; it grows a folder when a component arrives. `make:migration` and `make:middleware` are the `make:*` commands so far.*
9. **Per-component packages**, CI and the first tagged release. Nothing above needs to be finished first: what exists is a usable API framework, and use will say what is missing.

## Decisions

All agreed on 2026-10-04:

1. **Contracts:** Crab keeps its own contract for everything a package touches.
2. **Concurrency model:** contracts are written for async drivers; blocking drivers ship first.
3. **Worker hooks:** a provider may also implement `WorkerProvider`, with `start(Worker)` and `stop(Worker)`.
4. **Packaging:** each component is published as its own package, at step 9 of the build order.
5. **Global helpers:** process-scope values only (`config()`, `env()`), permanently.

Agreed on 2026-10-10:

6. **Scope:** Crab is not a Laravel on another engine. It stays a framework for JSON APIs that is long-running by design, and leaves out what that does not need: there is no model layer over the query builder, no session and no roles or permissions component. An application that wants one writes it over what is there, or brings a library.
7. **Own components first:** events, cache, queue and scheduling are Crab's own, because each is small and the queue and the scheduler must run on the engine's worker groups, which a library with a worker loop of its own would sit beside. A third-party library remains welcome behind a contract, as a store or a driver: a Symfony cache adapter as a `Cache\Store`, for one.
8. **Guide:** the usage of every component is written for application developers in `docs/`, one page per topic, and kept in step with the code by the `sync-docs` skill.
