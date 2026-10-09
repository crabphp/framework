# Framework conventions

- This repository is `crabphp/framework`. The networking engine lives in `src/Engine/` with its own `composer.json` and is also published alone as `crabphp/engine`; framework components are sibling namespaces under `src/`.
- Nothing under `src/Engine/` may reference a `Crab\` namespace other than `Crab\Engine\`; `tests/EngineBoundaryTest.php` enforces this. The framework may depend on the engine, never the reverse.
- Engine tests live in `tests/Engine/`, engine benchmarks in `benchmarks/`.
- [ARCHITECTURE.md](ARCHITECTURE.md) is the agreed design: layers, lifecycle, scopes, concurrency model and build order. Read it before designing a component and keep it true when the design changes.
- [COMPONENTS.md](COMPONENTS.md) is the flow for building a component. Follow its steps in order and meet its definition of done; when the flow itself changes, change that file in the same change.
- When a convention is decided or changed, record it in this file in the same change. Before adding a class, interface or helper, check it against these rules and against how the existing components already do it.

## Lean and standard

- Keep the framework lean. Prefer a small implementation of our own to a third-party dependency; runtime dependencies are Revolt and the PSR interface packages. Ask before adding any other.
- Everything is used through a `Crab\` type, so that nothing we depend on is outside our control and Crab always has a place to add a method. A third-party library is wrapped behind our own interface in one place, and only that place names it. A PSR interface is extended by a Crab contract, never asked for bare by Crab's own services.
- Async tools come from `Crab\Engine`: `EventLoop`, `Suspension`, and `Async\Pool`, `Task`, `Async` and `Cancellation`. Only `src/Engine/EventLoop/` may name Revolt; `tests/EngineBoundaryTest.php` enforces it for all of `src/`.
- Follow PHP-FIG wherever a PSR exists: PSR-7 messages, PSR-17 factories, PSR-15 handlers and middleware, PSR-11 container, PSR-3 logger, and PSR-14, PSR-16, PSR-18 and PSR-20 when those components are built.
- Use the multiline docblock rule from the engine conventions in framework code too.

## Components

- Each component has its own folder and namespace under `src/` (`Http`, `HttpClient`, `Routing`, `Validation`, `Auth`, `Container`, `Config`, `Log`, `Clock`, `Console`, `Database`, `Testing`) and its tests in the matching folder under `tests/`.
- Every component has the same shape: it works standalone, has a contract when packages touch it, ships a `<Component>Provider` when it is optional, and is kept able to be published as its own package the way the engine is.
- A component works on its own, without `App`, the container or a provider. It may depend on `Contracts`, on PSR interfaces and on the engine, and on another component only where that is its purpose. `tests/LayerBoundaryTest.php` enforces this; a new dependency between components is added to its `ALLOWED` list deliberately, never worked around.
- The root of `src/` is the glue that makes components an application: `App.php`, `AppBuilder.php`, `Server.php`, `Route.php`, `Command.php`, `helpers.php`, and `Commands/` for built-in commands. Code that needs `App` belongs there, not inside a component.
- Do not add a `Support` or utility namespace until two components need the same code, and then put only that code in it.

## Contracts

- A package depends on `Crab\Contracts` and PSR interfaces only, never on a component's class. So everything a package touches has a contract in `Crab\Contracts\<Component>`, never inside the component, including where no PSR exists: `Config`, `Router`, `Console`, `Container`, `Database`, `Connection`, `Query`, `Schema`, `Table`, `Column`, `Migration`, `Migrator`, `Validator`, `Rule`, `Clock`, `Logger`, `Factory`, `Middleware`, the HTTP client's `Client`, `Response` and `CookieJar`, `Identity`, `IdentityResolver`, `TokenStore`, `PasswordHasher`, `MiddlewareStack`, `Provider`, `WorkerProvider`, and the console's `Command`, `Input` and `Output`.
- Anything an application can do through `App`, a provider can do through a contract in the container. When `App` gains a way to extend the application, the contract comes with it.
- Exceptions and immutable value objects are the exception to that: `HttpException`, `DatabaseException`, `ValidationException`, `Cookie`, the `Response` factories and the validation attributes (`Email`, `Length`, `ListOf`, ...) are named by class, because there is no behaviour behind them to swap.
- A value object that a contract returns or takes lives beside it in `Crab\Contracts`, as a final readonly class with no I/O (`Contracts\Database\Page`, `Contracts\HttpClient\File`), since a contract may not name a component's class.
- `App` binds each contract in the container, and binds the concrete class to the same object so that asking by class cannot autowire a second one. The glue type-hints contracts too.
- Every service has a contract of Crab's own, including where a PSR interface covers it completely: the contract then extends the PSR interface and may add nothing (`Contracts\Log\Logger`, `Contracts\Clock\Clock`, `Contracts\Http\Factory`). It is the name applications ask for and the place a method is added when Crab needs one. `App` binds the contract and binds the PSR interface to the same object, so code written against the standard gets it too. What an application implements has one too where it is something Crab runs: `Contracts\Http\Middleware` is PSR-15's middleware under Crab's name.
- A contract references only PSR interfaces, other contracts and engine types, never a component's class. The component's class implements the contract and shares its short name (`Crab\Http\Request` implements `Crab\Contracts\Http\Request`).
- Signatures accept the PSR type wherever PSR methods suffice, so third-party implementations plug in; ask for a Crab contract only where its extra methods are used.

## Application wiring

- `App` binds the core directly: configuration, container, validator, router, HTTP factory, logger, clock, error handler and console. Everything optional is added by a `Provider`, the same way for our packages as for anyone else's, registered explicitly with `$app->register()`.
- A provider has `register(Container $container)`. There is no `boot()`; bindings are closures run on first use. A provider with work to do in each worker implements `WorkerProvider` as well: `start(Worker $worker, Container $container)` opens what holds a socket, file or timer and `stop(Worker $worker, Container $container)` releases it. Providers stop in reverse order, `stop()` must not suspend, and a provider keeps no state of its own.
- A provider declares its settings and their defaults once with `Config::defaults()`; code reads them with no default argument. The container has no per-request scope: `singleton()` is one per worker, `bind()` is new on every `get()`.
- Every error response is built by `Http\ErrorResponse`, for the error handler and for the router's 404 and 405 alike. Expected answers such as an unmatched route are returned as responses, not thrown, so they pass back through middleware.
- Inside a worker, PHP warnings and notices are thrown as `ErrorException`; code must not rely on a warning being ignored, and uses `@` only where a failure is then checked by return value.
- Anything that adds work to every request and is not needed to answer it, such as the request id or CORS, is off by default: it is middleware an application adds, with its measured cost documented.
- A route the framework adds, such as the health path, is asked for in `app.php` (`withRouting(health: '/up')`) and shows in `routes` like any other. `App` adds none by itself.
- Nothing fails silently outside a request: `App` sends everything the engine catches in a worker (a failed task, a failure escaping a timer, a failing `stop()`, a request failure that escaped the error handler) to the logger through the engine group's `onFailure()`, and the worker keeps running. A new entry point (queue job, scheduled job) gets the same treatment.
- `App` assembles its middleware, the error handler included, in each worker as the worker starts, never in the parent and never lazily on a request, so a misconfiguration stops startup with its cause printed.
- A binding's closure and a constructor the container calls must not suspend: the container is shared by every fiber in the worker and guards against circular dependencies by entry name, so a second request asking for an entry that is still being made is refused.
- Objects the container keeps are shared by every request a worker serves: they hold no state of a single request, and anything holding a socket or file is made in the worker on first use, not in the parent.
- State has three scopes. Process scope (config, routes, provider list) is fixed once the application is defined. Worker scope is the container's kept objects. Request scope travels only on the PSR-7 request and its attributes, never in a service or a static.
- A component that does I/O (database, cache, queue) exposes a pool in its contract, `acquire()` and `release()`, so code written against it is correct with blocking drivers now and async drivers later. Blocking drivers ship first. The pool is the engine's `Async\Pool`; do not write another. The HTTP client keeps its pools to itself, one per server: a call is one request and its answer, and there is nothing for a caller to hold between two.
- A pooled component resolves the event loop when it is used, not when it is constructed: a worker installs its own loop after the fork, and anything that kept the parent's would wait forever.
- HTTP, queue consumers and the scheduler run as separate engine worker groups. Workers hold nothing another worker needs; shared state lives in external stores behind contracts.

## Handlers

- A handler says what it takes in its signature, and the framework validates the request against it before calling it. Do not add a second place to describe a route, such as an annotation or a schema file.
- A handler's parameters are the request, the pattern's parameters by name, and the request's data: either as further typed parameters, one per field, or as one input class, never both. Services are never handler parameters; a handler class takes them in its constructor.
- Take a few flat fields as parameters. Use an input class when there are many, when they nest, or when several routes share them. An input class is a final readonly class whose constructor declares every field with one type.
- A handler is a closure, `[Controller::class, 'method']`, or a class with `__invoke()`. A controller is a plain class: there is no base class and no helper to inherit.
- A handler returns data, which is sent as JSON with 200, or a response when the status, a header or the body needs saying. Status and headers are set in code with the `Response` factories and nowhere else, so nothing has to be kept in step. Do not add an attribute for something code can say.
- Crab writes no description of the API (OpenAPI, JSON Schema). It was built and removed: keeping a document true cost every later feature a second thing to maintain, and a document that is partly true misleads. The signatures are the description. Do not add one back, or keep data on a route or a rule for one, until the owner asks.
- Attributes are for what PHP cannot say in a type: validation rules, and `#[ListOf]` for what an array holds.
- A signature is read when the route is added, never on a request, and one that cannot be honoured throws there, naming the route and saying what to do instead.
- Data that does not fit is a 422 built by `Http\ErrorResponse` with every problem by field, returned and not thrown. JSON is taken as it is; only text sources (query string, route parameters) are converted to numbers and booleans. Fields a handler does not declare are ignored and never reach it.
- `Validation` knows nothing of HTTP or routing. A rule is an attribute implementing `Contracts\Validation\Rule`, which checks a value and says what is wrong with it.
- A handler's input class, and every class inside it, is read when the route is added (`Validator::inspect()`), so one that cannot be validated stops startup.

## Running the server

- Plain `serve` is for the developer: it watches and restarts. Production says what it wants, `--no-watch` or `--daemon`. A default is chosen for whoever types the command most.
- `<command> --help` and `-h` describe a command and never run it; the console handles them, so a command does not use `h` for an option of its own.
- `Server` in the glue is the one place that starts, replaces and stops the application's server; a command about the server calls it and holds no process logic of its own.
- New code is loaded by a new process, never into a running one: classes cannot be redefined, and the application is defined in the server process before it forks. Do not add a way to "reload" that keeps that process.
- A background server is a keeper, a server process under it, and workers under that. The keeper owns the listening socket and passes it to each server process it starts, so that old and new accept from one queue. Do not make two servers bind the same address instead (`SO_REUSEPORT`): on Linux, connections queued on the socket that closes are lost.
- The keeper stays small and loads nothing of the application, because it runs old code for as long as the service lives. What a reload needs to know travels in the request, not in the keeper.
- A command that starts or reloads a server returns only when it accepts connections, and exits with a failure and the reason when it does not. A reload that fails leaves the running server untouched.
- A process that stops gracefully goes on reading a connection that looks idle for a moment (`HttpSession::DRAIN_GRACE`): the client may have sent a request before it could know.
- A process id read from a file is checked to be a server before it is reported or signalled; the file may be older than the process that now has the number.
- After going into the background a process must not use the `STDIN`, `STDOUT` or `STDERR` constants, which are closed; `echo`, `php://stdout` and `php://stderr` reach its log.
- A process started with `proc_open` inherits every descriptor its parent has open, and PHP cannot close them by number. So only a process that opens almost nothing, the keeper, may start a long-lived one; a server process must never start its own successor.
- Process control that is not about HTTP (`Keeper`, `Generation`, `Daemon`, `PidFile`, `FileWatcher`, `Runtime::onReady()` and `onSignal()`) lives in `Engine\Process` and is tested there with real processes; what needs `App` lives in `Server`, tested by driving a real application in `tests/ServerTest.php`. Run both on Linux (`composer test:linux`) before changing either.

## Middleware

- There is one kind of middleware, PSR-15's, used through `Contracts\Http\Middleware`, and two places it goes: around every request, with `withMiddleware()` in `app.php`, and around some routes, with `middleware:` on a route or a group. The same class works in both; nothing about a class says where it may be used.
- The framework's own middleware around every request is the list `App::MIDDLEWARE`. An application can put its own before or after it, leave one out, or replace the list; do not hard-wire a middleware into `App` so that it cannot be moved or removed.
- A feature that runs around requests is middleware an application adds, not a configuration switch: one place says what runs around a request. `RequestId` ships and is not in the default list.
- A middleware is told its options through its constructor and given as an object. Do not parse options out of a string such as `'throttle:60,1'`.
- All middleware is made when a worker starts, the application's and every route's, never on the first request. A route's or a group's middleware that could never run, a name that is no class or a class that is no middleware, is refused where it is declared, naming the route.
- The same name or the same object given again to a route, by its groups or itself, runs once, at the later place. Two objects of one class both run: each has its own options, and dropping one could drop a check the application asked for. Do not remove middleware by class.
- A route records the middleware around it in `RouteInfo`, and `routes` lists it, so what guards a route can be read without opening the routes file.
- A middleware object may be made before the workers are forked, so it chooses nothing per worker in its constructor (`RequestId` picks its prefix on the first request).
- An application sets the middleware around every request with `withMiddleware()` and a provider adds to it through the `MiddlewareStack` contract. `App` has no method of its own for it.
- A way of doing something for some routes, such as authenticating, is a middleware. Do not add a contract and a wrapper middleware around it where PSR-15 already says the same.

## Calling out

- Code that calls another server asks for `Contracts\HttpClient\Client` and never uses `file_get_contents()`, cURL or a stream wrapper for it: those hold the whole worker until the other side answers.
- The client waits on the event loop: a socket is read and written only when it is ready, and the fiber that asked is suspended meanwhile. It names no Revolt type; its loop is `EventLoop::current()`, asked for when a connection is made.
- An answer with an error status is returned, not thrown. Only a request that got no answer throws, and its message carries the method, host and path, never the query string or a header.
- Without `retry()`, a request is sent again by itself only when the server cannot have acted on it twice: a kept connection turned out to be closed, nothing came back, and doing it twice is doing it once (`GET`, `HEAD`, `OPTIONS`, `PUT`, `DELETE`); or, on HTTP/2, the server said it did not act on it (a refused stream, or a connection it left before reaching the request). A `POST` is never repeated on a guess.
- What the client does not do is said where an application developer reads it: no HTTP/3, no HTTP/2 without encryption, no server push, no proxy other than a plain HTTP one, and no brotli.
- HTTP/2 is offered to every `https` server and spoken with those that take it; one `Http2Session` per server carries all its requests, and its connection stays lent from that server's pool for as long as it lasts. Every header block received is decoded, wanted or not, because each may change the table the next refers to. Frames are written whole and one writer at a time.
- The HPACK tables (`Hpack::STATIC`, `Hpack::HUFFMAN`) were generated from the text of RFC 7541 and are checked against its examples; do not edit them by hand.
- `retry()` tries a request again only when that cannot do it twice: one that may safely be made twice, on no answer or 429, 502, 503, 504; any other only when none of it was sent (`NetworkException::$unsent`) or the server answered 429 or 503. A 500 is never tried again. Widening this needs the owner's agreement.
- A name is looked up with the engine's `DnsResolver`, never `gethostbyname()` directly: the hosts file, then answers kept for as long as they hold, then the name servers over UDP on the event loop. The system's blocking resolver is only what is fallen back on when they give nothing.
- The client an application is given is shared by every request its worker serves, so nothing about one caller is set on it. `timeout()` and `cookies()` return another client over the same connections and leave the first as it was; a new option of that kind is added the same way, never as a setter.
- What belongs to one request is an argument of the call (`query:`, `headers:`, `json:`, `form:`, `files:`, `body:`, `cancellation:`); what holds for several is an argument of the constructor and, where a caller needs to change it, a method that returns another client. One thing is said in one of the two, not both.
- A client keeps no cookies unless it is given a jar. A jar gives a cookie back only where a browser would: host or named domain, path, `Secure`, and not expired.
- `Response::throw()` is how an error status becomes an exception, asked for by the caller. Do not add a client-wide switch that makes every error status throw.
- `FakeClient` extends `Client` and replaces only what carries a request (`Transport`), so that a test exercises the real request building, redirects and cookies. It is the one class in `src/` that extends a concrete class; a request no reply matches throws rather than reaching the network.

## Request bodies

- A file in a request is never read into memory by the framework. The engine keeps a large body on disk, `Multipart` reads it through a window, and an `UploadedFile` is a `StreamSlice` of the place the file lies in. Do not turn an upload into a string to parse or to move it.
- What is kept in memory is bounded: a form's fields together, the number of its parts, and the headers of one part.
- The size a body may be and the time a client has to send it are settings, `server.max_body` and `server.body_timeout`, with the engine's defaults.

## Generality

- Crab is a framework: a component ships the general mechanism, and leaves an application's decisions to the application. It names no header, table, column or storage as the only one.
- A concrete way of doing something is opt-in, behind a provider of its own (`TokenAuthProvider` beside `AuthProvider`), so that an application doing it another way never loads it.
- A component ships no migration of its own. A table it needs is written into the application's migrations by a command (`tokens:install`), so that the application owns the file and all its tables are in one place.
- Where a choice has a standard default, the default is an argument's default value (`Authorization: Bearer`), and anything else is that argument, never another class.

## Authentication

- Who makes a request is request state. Middleware puts the user on the request's `user` attribute, and it reaches a handler as a parameter whose class implements `Contracts\Auth\Identity`. There is no static accessor for it and none may be added.
- A way of authenticating is a middleware and nothing more. Core `Auth` is `Identity`, that convention, and the `PasswordHasher`; it decides no scheme.
- Tokens are one way, opt-in through `TokenAuthProvider`: the `TokenAuth` middleware, the `TokenStore` and the `IdentityResolver` an application binds so that a user can be found from the id a token belongs to. Crab keeps no user table of its own.
- A secret is never stored as it is: a token is kept as its SHA-256 hash and a password through `PasswordHasher`. Neither appears in a log or an exception's message.

## Database

- MySQL and SQLite are the supported databases. PostgreSQL is out of scope for now: do not add code, tests or documentation for it until the owner asks.
- SQL values are always bound, never concatenated; an exception's message carries the SQL and never the bound values.
- A statement is retried on a new connection only when the old one was already closed before the statement was sent and no transaction was open. Anything that may have reached the server is reported, not repeated.
- Code that needs the time asks for `Contracts\Clock\Clock` (or PSR-20's `ClockInterface`, which is bound to the same object, where a signature should accept any PSR clock) and never calls `time()`, `date()` or `new DateTime('now')` itself, so a test can hold the clock still with `Testing\FrozenClock`. A component that works standalone takes the clock as an optional argument.
- `Table` and `Column` collect what a migration says and hold no SQL; `SchemaGrammar` writes it, and a dialect subclass says only where its database differs, as with `Query` and `Grammar`. A new column type is added to `Table`, to both dialects and to the tests of both.
- A migration runs in a transaction only where the database can roll back a change to its tables; say so wherever that difference reaches the user.
- An application's databases are configured by name and held by `DatabaseManager`. Asking for the `Database` contract gives the default one, and `Database::connection($name)` another; do not bind a second database in the container under a name of its own. The kind of database is read from its `dsn`, never from a separate setting.
- The layers are built in order and each works without the one above it: pool, query layer, query builder, schema builder, migrator, model. Do not make a lower layer depend on a higher one.
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
- It stays minimal and readable at a glance: `app.php` describes the application, `cua` is the command line (`php cua serve`), and the folders are `routes/`, `app/Handlers/`, `app/Providers/`, `config/`, `database/migrations/`, `storage/` and `tests/`.
- `storage/` holds what the application writes while it runs (logs in `storage/logs/`, the SQLite file, later caches and uploads) and is never committed. Nothing the application needs in order to start lives there. A relative `log.path` is inside the application's base path. Add a folder to it only when a component that needs one is built.
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
