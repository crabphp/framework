# Framework conventions

- This repository is `crabphp/framework`. The networking engine lives in `src/Engine/` with its own `composer.json` and is also published alone as `crabphp/engine`; framework components are sibling namespaces under `src/`.
- Nothing under `src/Engine/` may reference a `Crab\` namespace other than `Crab\Engine\`; `tests/EngineBoundaryTest.php` enforces this. The framework may depend on the engine, never the reverse.
- Engine tests live in `tests/Engine/`, engine benchmarks in `benchmarks/`.
- When a convention is decided or changed, record it in this file in the same change. Before adding a class, interface or helper, check it against these rules and against how the existing components already do it.

## Lean and standard

- Keep the framework lean. Prefer a small implementation of our own to a third-party dependency; runtime dependencies are Revolt and the PSR interface packages. Ask before adding any other.
- Follow PHP-FIG wherever a PSR exists: PSR-7 messages, PSR-17 factories, PSR-15 handlers and middleware, PSR-11 container, PSR-3 logger, and PSR-14, PSR-16, PSR-18 and PSR-20 when those components are built.
- Use the multiline docblock rule from the engine conventions in framework code too.

## Components

- Each component has its own folder and namespace under `src/` (`Http`, `Routing`, `Container`, `Config`, `Log`, `Console`) and its tests in the matching folder under `tests/`.
- A component works on its own, without `App`, the container or a provider. It may depend on `Contracts`, on PSR interfaces and on the engine, and on another component only where that is its purpose.
- The root of `src/` is the glue that makes components an application: `App.php`, `helpers.php`, and `Commands/` for built-in commands. Code that needs `App` belongs there, not inside a component.
- Do not add a `Support` or utility namespace until two components need the same code, and then put only that code in it.

## Contracts

- Every interface that another package implements or type-hints lives in `Crab\Contracts\<Component>`, never inside the component. This includes interfaces with no PSR counterpart, such as `Provider` and the console's `Command`, `Input` and `Output`.
- Where a PSR interface exists, the contract extends it and adds only methods Crab really needs; do not add a contract that adds nothing to its PSR interface.
- A contract references only PSR interfaces, other contracts and engine types, never a component's class. The component's class implements the contract and shares its short name (`Crab\Http\Request` implements `Crab\Contracts\Http\Request`).
- Signatures accept the PSR type wherever PSR methods suffice, so third-party implementations plug in; ask for a Crab contract only where its extra methods are used.

## Application wiring

- `App` binds the core directly: configuration, container, router, HTTP factory, logger, error handler and console. Everything optional is added by a `Provider`, the same way for our packages as for anyone else's, registered explicitly with `$app->register()`.
- A provider has `register(Container $container)` only. There is no `boot()`; bindings are closures run on first use. Work that must happen in each worker after the engine forks gets its own explicit hook when first needed.
- `App` assembles its middleware, the error handler included, in each worker as the worker starts, never in the parent and never lazily on a request, so a misconfiguration stops startup with its cause printed.
- Objects the container keeps are shared by every request a worker serves: they hold no state of a single request, and anything holding a socket or file is made in the worker on first use, not in the parent.

## Helpers

- A global helper function lives in the `helpers.php` of the component it belongs to when it needs only that component (`env()` in `src/Config/helpers.php`). A helper that needs the running application lives in `src/helpers.php` (`config()`).
- Wrap each helper in `function_exists`, list its file under Composer's `files`, and keep it a thin call to a class method that works without the helper.

## Performance

- Code on the request path builds values on first use and avoids objects created per request. When changing `Http` or `Routing` request-path code, measure the per-request cost against the engine alone with JIT on, in the manner of `benchmarks/request-path.php`, and report it.
- Do not add an optimization that reuses parsed data across identical requests; that is a benchmark hack. Lazy work is fine.

## Workflow

- Commit directly on `main`, without feature branches. Commit and push only when asked.

# Engine conventions

- Use multiline docblocks for class, function, and member declarations; allow single-line docblocks only inside method or function bodies.
- Keep the engine core lightweight and compatible with PHP 8.4, using its modern features and a pure-PHP select backend by default; do not require Swoole, OpenSwoole, or similar runtimes, and keep native event backends optional.
- Build the engine as a powerful, general-purpose networking foundation first; design the minimal web and agent-facing layers separately later, keeping their dependencies and features outside the core.
- Include TCP, UDP, HTTP, and WebSocket servers in the required engine release scope; HTTP/WebSocket are engine protocol modules, while the later web framework remains separate.
- Prioritize performance with proven networking designs and reproducible measurements, while keeping extension points small and explicit.
- Keep reproducible HTTP and WebSocket benchmarks and repeat matched measurements when changing their performance-critical paths; report throughput, latency, errors, environment and limitations without claiming an unmeasured maximum.
- Consider native PHP Fibers and Revolt as supported architectural options when they deliver measured performance benefits; evaluate their overhead and preserve PHP 8.4 compatibility.
- During the engine redesign, treat existing code and architecture as references; choose APIs, ownership boundaries, and dependencies from the requirements without preserving the current design for compatibility alone.
