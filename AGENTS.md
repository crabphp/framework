# Framework conventions

- This repository is `crabphp/framework`. The networking engine lives in `src/Engine/` with its own `composer.json` and is also published alone as `crabphp/engine`; framework components are sibling namespaces under `src/`.
- Nothing under `src/Engine/` may reference a `Crab\` namespace other than `Crab\Engine\`; `tests/EngineBoundaryTest.php` enforces this. The framework may depend on the engine, never the reverse.
- Engine tests live in `tests/Engine/`, engine benchmarks in `benchmarks/`.

# Engine conventions

- Use multiline docblocks for class, function, and member declarations; allow single-line docblocks only inside method or function bodies.
- Keep the engine core lightweight and compatible with PHP 8.4, using its modern features and a pure-PHP select backend by default; do not require Swoole, OpenSwoole, or similar runtimes, and keep native event backends optional.
- Build the engine as a powerful, general-purpose networking foundation first; design the minimal web and agent-facing layers separately later, keeping their dependencies and features outside the core.
- Include TCP, UDP, HTTP, and WebSocket servers in the required engine release scope; HTTP/WebSocket are engine protocol modules, while the later web framework remains separate.
- Prioritize performance with proven networking designs and reproducible measurements, while keeping extension points small and explicit.
- Keep reproducible HTTP and WebSocket benchmarks and repeat matched measurements when changing their performance-critical paths; report throughput, latency, errors, environment and limitations without claiming an unmeasured maximum.
- Consider native PHP Fibers and Revolt as supported architectural options when they deliver measured performance benefits; evaluate their overhead and preserve PHP 8.4 compatibility.
- During the engine redesign, treat existing code and architecture as references; choose APIs, ownership boundaries, and dependencies from the requirements without preserving the current design for compatibility alone.
