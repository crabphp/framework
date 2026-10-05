# Framework conventions

- This repository is `crabphp/framework`. The networking engine lives in `src/Engine/` with its own `composer.json` and is also published alone as `crabphp/engine`; framework components are sibling namespaces under `src/`.
- Nothing under `src/Engine/` may reference a `Crab\` namespace other than `Crab\Engine\`; `tests/EngineBoundaryTest.php` enforces this. The framework may depend on the engine, never the reverse.
- Engine tests live in `tests/Engine/`, engine benchmarks in `benchmarks/`.
- [ARCHITECTURE.md](ARCHITECTURE.md) is the agreed design: layers, lifecycle, scopes, concurrency model and build order. Read it before designing a component and keep it true when the design changes.
- [COMPONENTS.md](COMPONENTS.md) is the flow for building a component. Follow its steps in order and meet its definition of done; when the flow itself changes, change that file in the same change.
- When a convention is decided or changed, record it in this file in the same change. Before adding a class, interface or helper, check it against these rules and against how the existing components already do it.

## Lean and standard

- Keep the framework lean. Prefer a small implementation of our own to a third-party dependency; runtime dependencies are Revolt and the PSR interface packages. Ask before adding any other.
- Everything is used through a `Crab\` type, so that nothing we depend on is outside our control. A third-party library is wrapped behind our own interface in one place, and only that place names it. PSR interfaces are the one exception, because they are the standard we chose to follow.
- Async tools come from `Crab\Engine`: `EventLoop`, `Suspension`, and `Async\Pool`, `Task`, `Async` and `Cancellation`. Only `src/Engine/EventLoop/` may name Revolt; `tests/EngineBoundaryTest.php` enforces it for all of `src/`.
- Follow PHP-FIG wherever a PSR exists: PSR-7 messages, PSR-17 factories, PSR-15 handlers and middleware, PSR-11 container, PSR-3 logger, and PSR-14, PSR-16, PSR-18 and PSR-20 when those components are built.
- Use the multiline docblock rule from the engine conventions in framework code too.

## Components

- Each component has its own folder and namespace under `src/` (`Http`, `Routing`, `Validation`, `Container`, `Config`, `Log`, `Console`, `Database`, `Testing`) and its tests in the matching folder under `tests/`.
- Every component has the same shape: it works standalone, has a contract when packages touch it, ships a `<Component>Provider` when it is optional, and is kept able to be published as its own package the way the engine is.
- A component works on its own, without `App`, the container or a provider. It may depend on `Contracts`, on PSR interfaces and on the engine, and on another component only where that is its purpose. `tests/LayerBoundaryTest.php` enforces this; a new dependency between components is added to its `ALLOWED` list deliberately, never worked around.
- The root of `src/` is the glue that makes components an application: `App.php`, `AppBuilder.php`, `Route.php`, `Command.php`, `helpers.php`, and `Commands/` for built-in commands. Code that needs `App` belongs there, not inside a component.
- Do not add a `Support` or utility namespace until two components need the same code, and then put only that code in it.

## Contracts

- A package depends on `Crab\Contracts` and PSR interfaces only, never on a component's class. So everything a package touches has a contract in `Crab\Contracts\<Component>`, never inside the component, including where no PSR exists: `Config`, `Router`, `Console`, `Container`, `Database`, `Connection`, `Query`, `Validator`, `Rule`, `MiddlewareStack`, `Provider`, `WorkerProvider`, and the console's `Command`, `Input` and `Output`.
- Anything an application can do through `App`, a provider can do through a contract in the container. When `App` gains a way to extend the application, the contract comes with it.
- Exceptions and immutable value objects are the exception to that: `HttpException`, `DatabaseException`, `ValidationException`, `Cookie`, the `Response` factories and the validation attributes (`Email`, `Length`, `ListOf`, ...) are named by class, because there is no behaviour behind them to swap.
- A value object that a contract returns lives beside it in `Crab\Contracts`, as a final readonly class with no I/O (`Contracts\Database\Page`), since a contract may not name a component's class.
- `App` binds each contract in the container, and binds the concrete class to the same object so that asking by class cannot autowire a second one. The glue type-hints contracts too.
- Where a PSR interface exists, the contract extends it and adds only methods Crab really needs; do not add a contract that adds nothing to its PSR interface.
- A contract references only PSR interfaces, other contracts and engine types, never a component's class. The component's class implements the contract and shares its short name (`Crab\Http\Request` implements `Crab\Contracts\Http\Request`).
- Signatures accept the PSR type wherever PSR methods suffice, so third-party implementations plug in; ask for a Crab contract only where its extra methods are used.

## Application wiring

- `App` binds the core directly: configuration, container, validator, router, HTTP factory, logger, error handler and console. Everything optional is added by a `Provider`, the same way for our packages as for anyone else's, registered explicitly with `$app->register()`.
- A provider has `register(Container $container)`. There is no `boot()`; bindings are closures run on first use. A provider with work to do in each worker implements `WorkerProvider` as well: `start(Worker $worker, Container $container)` opens what holds a socket, file or timer and `stop(Worker $worker, Container $container)` releases it. Providers stop in reverse order, `stop()` must not suspend, and a provider keeps no state of its own.
- A provider declares its settings and their defaults once with `Config::defaults()`; code reads them with no default argument. The container has no per-request scope: `singleton()` is one per worker, `bind()` is new on every `get()`.
- Every error response is built by `Http\ErrorResponse`, for the error handler and for the router's 404 and 405 alike. Expected answers such as an unmatched route are returned as responses, not thrown, so they pass back through middleware.
- Inside a worker, PHP warnings and notices are thrown as `ErrorException`; code must not rely on a warning being ignored, and uses `@` only where a failure is then checked by return value.
- Anything that adds work to every request and is not needed to answer it, such as the request id, is off by default and switched on by one configuration key, with its measured cost documented.
- Nothing fails silently outside a request: `App` sends everything the engine catches in a worker (a failed task, a failure escaping a timer, a failing `stop()`, a request failure that escaped the error handler) to the logger through the engine group's `onFailure()`, and the worker keeps running. A new entry point (queue job, scheduled job) gets the same treatment.
- `App` assembles its middleware, the error handler included, in each worker as the worker starts, never in the parent and never lazily on a request, so a misconfiguration stops startup with its cause printed.
- A binding's closure and a constructor the container calls must not suspend: the container is shared by every fiber in the worker and guards against circular dependencies by entry name, so a second request asking for an entry that is still being made is refused.
- Objects the container keeps are shared by every request a worker serves: they hold no state of a single request, and anything holding a socket or file is made in the worker on first use, not in the parent.
- State has three scopes. Process scope (config, routes, provider list) is fixed once the application is defined. Worker scope is the container's kept objects. Request scope travels only on the PSR-7 request and its attributes, never in a service or a static.
- A component that does I/O (database, cache, queue, HTTP client) exposes a pool in its contract, `acquire()` and `release()`, so code written against it is correct with blocking drivers now and async drivers later. Blocking drivers ship first. The pool is the engine's `Async\Pool`; do not write another.
- A pooled component resolves the event loop when it is used, not when it is constructed: a worker installs its own loop after the fork, and anything that kept the parent's would wait forever.
- HTTP, queue consumers and the scheduler run as separate engine worker groups. Workers hold nothing another worker needs; shared state lives in external stores behind contracts.

## Handlers

- A handler says what it takes in its signature, and the framework validates the request against it before calling it. Do not add a second place to describe a route, such as an annotation or a schema file.
- A handler's parameters are the request, the pattern's parameters by name, and the request's data: either as further typed parameters, one per field, or as one input class, never both. Services are never handler parameters; a handler class takes them in its constructor.
- Take a few flat fields as parameters. Use an input class when there are many, when they nest, or when several routes share them. An input class is a final readonly class whose constructor declares every field with one type.
- A handler is a closure, `[Controller::class, 'method']`, or a class with `__invoke()`. A controller is a plain class: there is no base class and no helper to inherit.
- A handler returns data, which is sent as JSON with 200, or a response when the status, a header or the body needs saying. Status and headers are set in code with the `Response` factories and nowhere else, so nothing has to be kept in step. Do not add an attribute for something code can say.
- The OpenAPI document is a by-product: it describes what signatures declare and is never a reason to make application code longer. A route is never refused or warned about for being under-described.
- Attributes are for what PHP cannot say in a type: validation rules, and `#[ListOf]` for what an array holds.
- A signature is read when the route is added, never on a request, and one that cannot be honoured throws there, naming the route and saying what to do instead.
- Data that does not fit is a 422 built by `Http\ErrorResponse` with every problem by field, returned and not thrown. JSON is taken as it is; only text sources (query string, route parameters) are converted to numbers and booleans. Fields a handler does not declare are ignored and never reach it.
- `Validation` knows nothing of HTTP or routing. A rule is an attribute implementing `Contracts\Validation\Rule`, which checks a value and describes itself as JSON Schema, so the document cannot disagree with the check.
- A new rule or supported type is added to validation and to its JSON Schema together, with a test of both.

## Database

- MySQL and SQLite are the supported databases. PostgreSQL is out of scope for now: do not add code, tests or documentation for it until the owner asks.
- SQL values are always bound, never concatenated; an exception's message carries the SQL and never the bound values.
- A statement is retried on a new connection only when the old one was already closed before the statement was sent and no transaction was open. Anything that may have reached the server is reported, not repeated.
- The layers are built in order and each works without the one above it: pool, query layer, query builder, model. Do not make a lower layer depend on a higher one.
- The query builder needs only the `Connection` contract. The one place a lower layer names it is `Connection::table()`, a single `new Query($this, $table)`; nothing else in the pool or query layer may know it.
- `Query` collects what a statement says and holds no SQL; `Grammar` writes it, and a dialect subclass overrides only where its database differs. A new clause is state on `Query` and a method on `Grammar`, never SQL assembled in `Query`.
- In the builder, values are bound, names are quoted and operators are checked against a fixed list. Only methods ending in `Raw` take SQL as written, and every one of them takes bindings.
- A builder method that runs a statement leaves the query unchanged. A mistake in building a query is thrown from the call that made it, and `update()` and `delete()` refuse a query whose join, order or limit they would have to drop.
- Database tests run on SQLite in the suite and on MySQL when `CRAB_TEST_MYSQL_DSN` is set; run both before changing `src/Database/`.

## Readability

- Crab is built for JSON APIs and to be read by people and AI agents alike. A file should be understandable on its own: declare parameter, return and property types everywhere, and prefer a named class to an array whose keys the reader must look up.
- Nothing happens that the code does not show: no hidden globals, no auto-discovery, no behaviour switched by a naming convention. Offer one way to do a thing, not two.
- A static name is allowed only for declaring what is fixed before workers start: `Route::get()` for routes and `Command::add()` for commands, each one line onto the current application, like `config()`. There is none for the request, the user, the container or any service, because a worker serves overlapping requests. Do not add another without the owner's agreement.
- An error message says what was wrong and what to do instead, so that a person or an agent can fix it without reading the framework.
- Write plain statements that read top to bottom. Name a value on one line and use it on the next; do not call the result of a `require`, nest closures to pass one value, or pack unrelated steps into one expression.
- A fluent chain is fine where each call is one named, typed step on its own line, as in `App::configure()->with...()->create()` and the query builder: the editor completes every step and the reader sees one thing per line.
- This matters most in what application developers see: the skeleton, the README examples and error messages.

## Helpers

- Global helpers exist only for process-scope values (`config()`, `env()`). There is no helper for the request, the authenticated user or the container.
- Call `env()` only inside configuration files; everything else reads `config()`.
- A global helper function lives in the `helpers.php` of the component it belongs to when it needs only that component (`env()` in `src/Config/helpers.php`). A helper that needs the running application lives in `src/helpers.php` (`config()`).
- Wrap each helper in `function_exists`, list its file under Composer's `files`, and keep it a thin call to a class method that works without the helper.

## Performance

- Code on the request path builds values on first use and avoids objects created per request. When changing `Http` or `Routing` request-path code, measure the per-request cost against the engine alone with JIT on, in the manner of `benchmarks/request-path.php`, and report it.
- Do not add an optimization that reuses parsed data across identical requests; that is a benchmark hack. Lazy work is fine.

## Workflow

- Commit directly on `main`, without feature branches. Commit and push only when asked.

## Application skeleton

- The skeleton is the separate repository `crabphp/crab`, beside this one, and reads the framework from `../framework` until there is a release.
- It stays minimal and readable at a glance: `app.php` builds the application top to bottom, `cua` is the command line (`php cua serve`), and the folders are `routes/`, `app/Handlers/`, `app/Providers/`, `config/` and `tests/`. Add a folder to it only when a component that needs one is built.
- `app.php` is one `App::configure(__DIR__)` chain that names the providers and, in `withRouting(routes:, commands:)`, the route and command files, and ends in `create()`. `AppBuilder` has no defaults of its own and finds nothing by convention: what an application starts with is a chain of ordinary steps in `App::configure()`, today `withEnv('.env')` and `withConfig('config', required: false)`, where anyone can read it. A new default is added to that chain, with the owner's agreement. A step that takes several things takes them one by one or as a list. The route files declare with `Route::` and `Command::`, and no skeleton file uses a variable it did not define.
- When the framework changes how an application is written, change the skeleton and its README in the same piece of work, and run its tests.

# Engine conventions

- Use multiline docblocks for class, function, and member declarations; allow single-line docblocks only inside method or function bodies.
- Keep the engine core lightweight and compatible with PHP 8.4, using its modern features and a pure-PHP select backend by default; do not require Swoole, OpenSwoole, or similar runtimes, and keep native event backends optional.
- Build the engine as a powerful, general-purpose networking foundation first; design the minimal web and agent-facing layers separately later, keeping their dependencies and features outside the core.
- Include TCP, UDP, HTTP, and WebSocket servers in the required engine release scope; HTTP/WebSocket are engine protocol modules, while the later web framework remains separate.
- Prioritize performance with proven networking designs and reproducible measurements, while keeping extension points small and explicit.
- Keep reproducible HTTP and WebSocket benchmarks and repeat matched measurements when changing their performance-critical paths; report throughput, latency, errors, environment and limitations without claiming an unmeasured maximum.
- Nothing the engine catches is dropped. A `catch` that does not rethrow reports through `FailureReporter::report()`, or `Connection::reportFailure()` for a protocol owner; never a bare `catch (\Throwable)` and never `error_log()`. A client's malformed input is answered, not reported. Engine tests read `$this->reports`.
- The engine is written against its own `EventLoop` (an abstract class, whose `EventLoop::current()` is the process's loop) and `Suspension`. Revolt is the scheduler behind them today, through `RevoltEventLoop` in `src/Engine/EventLoop/`; a loop method the engine needs is added to `EventLoop` and the adapter together.
- Name a type for what it is, in full: `EventLoop`, not `Loop`. Do not name a class as the plural of another (`Loops`); put the behaviour on the type it belongs to. Evaluate the overhead of any change there and preserve PHP 8.4 compatibility.
- During the engine redesign, treat existing code and architecture as references; choose APIs, ownership boundaries, and dependencies from the requirements without preserving the current design for compatibility alone.
