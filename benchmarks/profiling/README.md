# HTTP path profiling

This diagnostic tool copies engine and Revolt sources into a temporary directory,
instruments selected methods and statements, and changes the temporary process's
Composer mappings to load those copies. It does not modify production sources.
PHP 8.4 and development dependencies are required: `nikic/php-parser` is already
included through PHPUnit. Graceful signal handling also requires `pcntl`.

From the `engine` directory, prepare an isolated copy:

```sh
export CRAB_ENGINE_ROOT="$(pwd)"
export CRAB_PROFILE_DIR="$(mktemp -d "${TMPDIR:-/tmp}/crab-http-profile.XXXXXX")"
cp benchmarks/profiling/{prepare.php,Probe.php,server.php} "$CRAB_PROFILE_DIR/"
php "$CRAB_PROFILE_DIR/prepare.php" "$CRAB_ENGINE_ROOT"
```

The preparation command requires an explicit engine root and refuses to generate
files inside that root. `manifest.json` records source hashes, instrumented methods,
and cancellation construction sites. Exact statement markers fail on unsupported
source changes; update them before comparing profiles. Both the original read loop
and its short-read variant are supported. Optional `checkDeadline()` instrumentation
and AST-based cancellation construction spans also cover their optimized forms.

Run profiling under the same exclusive lock as the benchmark runners, keeping it
held through server shutdown. This example uses one serving process, JIT, two
seconds of warmup and five seconds of measured traffic. It verifies the HTTP body,
keeps raw wrk output, and terminates only its owned server process group:

```sh
python3 - <<'PY'
import fcntl, importlib.util, json, os, signal, subprocess
from pathlib import Path

root = Path(os.environ["CRAB_ENGINE_ROOT"])
directory = Path(os.environ["CRAB_PROFILE_DIR"])
spec = importlib.util.spec_from_file_location("http_benchmark", root / "benchmarks/http.py")
http = importlib.util.module_from_spec(spec)
spec.loader.exec_module(http)
lua = directory / "latency.lua"
lua.write_text(http.LUA)
with open("/tmp/crab-engine-benchmark.lock", "a") as lock:
    fcntl.flock(lock, fcntl.LOCK_EX)
    port = http.ephemeral_port()
    command = ["php", "-d", "opcache.enable_cli=1", "-d", "opcache.jit=tracing",
               "-d", "opcache.jit_buffer_size=128M", str(directory / "server.php"),
               "--protocol=http", "--host=127.0.0.1", f"--port={port}", "--workers=1"]
    env = os.environ | {"CRAB_HTTP_PROFILE_OUTPUT": str(directory / "profile.json")}
    with (directory / "server.log").open("w") as log:
        server = subprocess.Popen(command, env=env, stdout=log, stderr=log, start_new_session=True)
        try:
            http.verify_server(server, port)
            for phase, seconds in [("warmup", 2), ("measured", 5)]:
                load = subprocess.run(["wrk", "-t2", "-c32", f"-d{seconds}s", "--timeout", "2s",
                                       "--latency", "-s", str(lua), f"http://127.0.0.1:{port}/"],
                                      capture_output=True, text=True, timeout=seconds + 15)
                (directory / f"{phase}.txt").write_text(load.stdout + load.stderr)
                load.check_returncode()
                metrics = json.loads(next(line.removeprefix("CRAB_METRICS ")
                                          for line in load.stdout.splitlines() if line.startswith("CRAB_METRICS ")))
                assert metrics["requests"] > 0
                assert not any(metrics[key] for key in
                               ["connect_errors", "read_errors", "write_errors", "status_errors", "timeouts"])
        finally:
            if server.poll() is None:
                os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=10)
        assert server.returncode == 0, (directory / "server.log").read_text()
print(directory / "profile.json")
PY
```

The profiler writes aggregate JSON on normal shutdown, including graceful SIGTERM;
it emits nothing per request. Output defaults to `profile-PID.json` beside the copied
tool, or follows `CRAB_HTTP_PROFILE_OUTPUT`. Inspect `open_span_count` (normally zero),
`loaded_files`, JIT status, socket read-result counts, spans, and parent-child edges.
Missing `Cancellation.reason.construct` means no reason was constructed, provided
the manifest confirms that construction sites were instrumented.

Interpret timings as diagnostic **wall time**, not CPU percentages or benchmark
throughput. Inclusive spans overlap and must not be added. Self time excludes
directly nested spans within the same Fiber; uninstrumented work and residual probe
overhead remain. Blocking select time is reported separately from observed active
self time. The fixture handler does not suspend, and generators are timed only
during `valid()`, `current()`, and `next()`, never across yields.

Instrumentation changes JIT behavior and can add substantial overhead. The report
measures bookkeeping time, but does not remove all instrumentation cost. Totals
include startup, warmup, measurement, drain, and shutdown. Compare call counts and
normalized costs against the recorded source snapshots, corroborate hotspots with
native sampling where available, and verify any improvement using uninstrumented,
matched benchmark trials. Keep generated copies, profiles, and logs outside the
repository unless deliberately retaining a named result as evidence.
