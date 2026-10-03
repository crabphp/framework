#!/usr/bin/env python3
"""Compare single-process servers and HTTP baselines with identical, validated loads."""

import argparse
import fcntl
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import platform
import re
import shutil
import signal
import socket
import subprocess
import tempfile
import time


DIRECTORY = Path(__file__).resolve().parent
ROOT = DIRECTORY.parent
SPEC = importlib.util.spec_from_file_location("crab_http_benchmark", DIRECTORY / "http.py")
HTTP = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(HTTP)
VALIDATION_SPEC = importlib.util.spec_from_file_location("http_baseline_validation", DIRECTORY / "baselines/verify.py")
VALIDATION = importlib.util.module_from_spec(VALIDATION_SPEC)
VALIDATION_SPEC.loader.exec_module(VALIDATION)
HTTP_ERRORS = ("connect_errors", "read_errors", "write_errors", "status_errors", "timeouts")
BASELINE_BACKENDS = {"php-select": "select", "php-select-jit": "select",
                     "php-revolt-jit": "revolt", "crab-transport-jit": "transport",
                     "crab-codec-jit": "codec"}
BEFORE_TARGETS = {"crab-before", "crab-before-jit"}
CRAB_TARGETS = {"crab", "crab-jit"} | BEFORE_TARGETS
PHP_TARGETS = CRAB_TARGETS | BASELINE_BACKENDS.keys()
HTTP_ONLY_TARGETS = set(BASELINE_BACKENDS) | {"bun"}
TARGETS = PHP_TARGETS | {"elysia", "bun"}


def php_command(target):
    return ["php", "-d", "opcache.enable_cli=1", "-d",
            "opcache.jit=tracing" if target.endswith("-jit") else "opcache.jit=off", "-d",
            "opcache.jit_buffer_size=128M" if target.endswith("-jit") else "opcache.jit_buffer_size=0"]


def capture(command):
    try:
        result = subprocess.run(command, capture_output=True, text=True, timeout=15)
        return {"command": command, "exit_code": result.returncode,
                "stdout": result.stdout.strip(), "stderr": result.stderr.strip()}
    except (OSError, subprocess.TimeoutExpired) as error:
        return {"command": command, "error": str(error)}


def source_hashes():
    paths = set(ROOT.joinpath("src").rglob("*.php"))
    paths.update(DIRECTORY / name for name in ("compare.py", "http.py", "server.php", "websocket.mjs"))
    paths.update(ROOT / name for name in ("composer.json", "composer.lock"))
    paths.update(DIRECTORY / "elysia" / name for name in
                 ("server.ts", "package.json", "bun.lock", "bun.lockb", "package-lock.json", "pnpm-lock.yaml", "yarn.lock"))
    paths.update(path for path in (DIRECTORY / "baselines").rglob("*") if path.suffix in {".php", ".ts", ".py"})
    return {str(path.relative_to(ROOT)): hashlib.sha256(path.read_bytes()).hexdigest()
            for path in sorted(paths) if path.is_file()}


def baseline_source_hashes(source):
    if source is None:
        return {}
    root = Path(source)
    return {"src/" + str(path.relative_to(root)): hashlib.sha256(path.read_bytes()).hexdigest()
            for path in sorted(root.rglob("*.php")) if path.is_file()}


def save(document, path):
    temporary = path.with_suffix(".tmp")
    temporary.write_text(json.dumps(document, indent=2) + "\n")
    temporary.replace(path)


def stop_process(process):
    if process.poll() is None:
        try:
            os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
    try:
        process.wait(timeout=10)
        return None
    except subprocess.TimeoutExpired:
        return f"Owned process group {process.pid} did not exit within 10 seconds after SIGTERM; no forced kill sent."


def socket_ready(server, port):
    deadline = time.monotonic() + 5
    while time.monotonic() < deadline:
        if server.poll() is not None:
            raise RuntimeError("Benchmark server exited during startup.")
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                return
        except OSError:
            time.sleep(0.02)
    raise RuntimeError("Benchmark server did not become ready.")


def http_response(port):
    with socket.create_connection(("127.0.0.1", port), timeout=2) as client:
        client.sendall(b"GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
        output = b""
        while chunk := client.recv(65536):
            output += chunk
    head, body = output.split(b"\r\n\r\n", 1)
    if not head.startswith(b"HTTP/1.1 200 OK\r\n") or body != b"Hello from Crab\n":
        raise RuntimeError(f"Unexpected readiness response: {output!r}")
    return {"raw_headers": head.decode("latin1"), "body": body.decode("ascii"), "body_bytes": len(body)}


def server_cpu_time(pid):
    result = capture(["ps", "-p", str(pid), "-o", "time="])
    result["cpu_seconds"] = None
    if result.get("exit_code") != 0:
        result.setdefault("error", "Server CPU counter unavailable.")
        return result
    try:
        value = result["stdout"]
        days = 0
        if "-" in value:
            prefix, value = value.split("-", 1)
            days = int(prefix)
        parts = value.split(":")
        if not 1 <= len(parts) <= 3:
            raise ValueError("Unexpected ps CPU time format.")
        seconds = float(parts[-1])
        for index, part in enumerate(reversed(parts[:-1]), 1):
            seconds += int(part) * 60 ** index
        seconds += days * 86400
        if not math.isfinite(seconds) or seconds < 0:
            raise ValueError("Invalid ps CPU time counter.")
        result["cpu_seconds"] = seconds
    except ValueError as error:
        result["error"] = str(error)
    return result


def load(server, command, timeout, protocol):
    result = {"command": command, "timeout_seconds": timeout, "timed_out": False,
              "samples": [], "errors": []}
    if protocol == "http":
        result["server_cpu_before"] = server_cpu_time(server.pid)
    started = time.monotonic()
    with tempfile.TemporaryFile(mode="w+") as output:
        process = subprocess.Popen(command, stdout=output, stderr=subprocess.STDOUT,
                                   text=True, start_new_session=True)
        result["pid"] = process.pid
        deadline = time.monotonic() + timeout
        try:
            while process.poll() is None:
                if time.monotonic() >= deadline:
                    result["timed_out"] = True
                    result["errors"].append("Load exceeded its deadline.")
                    break
                result["samples"].append(HTTP.sample_processes(server.pid, process.pid))
                time.sleep(0.2)
        except (Exception, KeyboardInterrupt) as error:
            result["errors"].append(f"{type(error).__name__}: {error}")
        finally:
            cleanup_error = stop_process(process)
            if cleanup_error:
                result["errors"].append(cleanup_error)
            result["exit_code"] = process.returncode
            output.seek(0)
            result["raw_output"] = output.read()
    result["elapsed_wall_seconds"] = time.monotonic() - started
    if protocol == "http":
        result["server_cpu_after"] = server_cpu_time(server.pid)
        before = result["server_cpu_before"]["cpu_seconds"]
        after = result["server_cpu_after"]["cpu_seconds"]
        delta = after - before if before is not None and after is not None else None
        result["server_cpu_seconds"] = delta if delta is not None and delta >= 0 else None
        result["server_cpu_percent_of_elapsed"] = (
            result["server_cpu_seconds"] / result["elapsed_wall_seconds"] * 100
            if result["server_cpu_seconds"] is not None else None)
        result["server_cpu_scope"] = "Serving process only; cumulative ps time delta over load launch, execution and cleanup."
    if result["exit_code"] != 0:
        result["errors"].append(f"Load exited with code {result['exit_code']}.")
    if server.poll() is not None:
        result["errors"].append(f"Server exited during load with code {server.returncode}.")
    try:
        if protocol == "http":
            line = next(line for line in result["raw_output"].splitlines() if line.startswith("CRAB_METRICS "))
            metrics = json.loads(line.removeprefix("CRAB_METRICS "))
            metrics["requests_per_second"] = metrics["requests"] * 1_000_000 / metrics["duration_us"]
            if metrics["requests"] <= 0 or any(metrics[key] != 0 for key in HTTP_ERRORS):
                result["errors"].append("HTTP load reported errors or completed no requests.")
        else:
            metrics = json.loads(result["raw_output"])
            if metrics["errors"] != 0 or metrics["completed_messages"] <= 0 or metrics["first_error"] is not None:
                result["errors"].append("WebSocket validation failed or completed no echoes.")
        result["metrics"] = metrics
    except (ValueError, KeyError, StopIteration, ZeroDivisionError) as error:
        result["errors"].append(f"Invalid load metrics: {type(error).__name__}: {error}")
    result["peak_server_rss_bytes"] = max((sample["server_rss_bytes"] for sample in result["samples"]), default=0)
    result["status"] = "failed" if result["errors"] else "passed"
    return result


def wrk_command(port, concurrency, duration, lua, threads=2):
    return ["wrk", "-t", str(min(threads, concurrency)), "-c", str(concurrency), "-d", f"{duration:g}s",
            "--timeout", "2s", "--latency", "-s", str(lua), f"http://127.0.0.1:{port}/"]


def run_trial(record, args, lua):
    port = HTTP.ephemeral_port()
    target, protocol = record["target"], record["protocol"]
    if target in {"elysia", "bun"}:
        fixture = "elysia/server.ts" if target == "elysia" else "baselines/bun.ts"
        command = [args.bun, str(DIRECTORY / fixture)]
    elif target in BASELINE_BACKENDS:
        command = php_command(target) + [str(DIRECTORY / "baselines/php.php"),
                                         f"--backend={BASELINE_BACKENDS[target]}"]
    else:
        command = php_command(target) + [str(DIRECTORY / "server.php"), "--host=127.0.0.1", "--workers=1", "--metadata"]
        if target in BEFORE_TARGETS:
            command += [f"--source={args.baseline_source}"]
        if protocol == "http":
            command += [f"--http-response={args.http_response}"]
    command += [f"--protocol={protocol}", f"--port={port}"]
    record.update(server_command=command, port=port, errors=[], status="running")
    with tempfile.TemporaryFile(mode="w+") as output:
        server = None
        try:
            server = subprocess.Popen(command, cwd=ROOT, stdout=output, stderr=subprocess.STDOUT,
                                      text=True, start_new_session=True)
            record["server_pid"] = server.pid
            if protocol == "http":
                HTTP.verify_server(server, port)
                record["readiness"] = "Verified HTTP/1.1 200 and exact Hello from Crab newline body."
                record["http_response"] = http_response(port)
                record["http_validation"] = VALIDATION.verify_http(port)
                if args.warmup > 0:
                    record["warmup"] = load(server, wrk_command(port, record["concurrency"], args.warmup, lua, args.threads),
                                            args.warmup + 15, protocol)
                    if record["warmup"]["status"] != "passed":
                        raise RuntimeError("HTTP warmup failed; measurement skipped.")
                command = wrk_command(port, record["concurrency"], args.duration, lua, args.threads)
                timeout = args.duration + 15
            else:
                socket_ready(server, port)
                record["readiness"] = "TCP readiness; client validates WebSocket handshake and every binary echo."
                command = ["node", str(DIRECTORY / "websocket.mjs"), f"--port={port}",
                           f"--concurrency={record['concurrency']}", f"--payload={record['payload_bytes']}",
                           f"--duration={args.duration}", f"--warmup={args.warmup}"]
                timeout = args.warmup + args.duration + 15
            record["measurement"] = load(server, command, timeout, protocol)
            if record["measurement"]["status"] != "passed":
                record["errors"].append("Measured load failed; see measurement diagnostics.")
        except (Exception, KeyboardInterrupt) as error:
            record["errors"].append(f"{type(error).__name__}: {error}")
        finally:
            if server is not None:
                cleanup_error = stop_process(server)
                if cleanup_error:
                    record["errors"].append(cleanup_error)
                record["server_exit_code"] = server.returncode
                if server.returncode != 0:
                    record["errors"].append(f"Server exited with code {server.returncode}.")
            output.seek(0)
            record["server_log"] = output.read()
            if target in CRAB_TARGETS:
                try:
                    line = next(line for line in record["server_log"].splitlines()
                                if line.startswith("CRAB_SERVER_METADATA "))
                    metadata = json.loads(line.removeprefix("CRAB_SERVER_METADATA "))
                    record["engine_source"] = metadata
                    expected = Path(args.baseline_source) if target in BEFORE_TARGETS else ROOT / "src"
                    if Path(metadata["source_root"]).resolve() != expected.resolve():
                        raise ValueError("Engine source root differs from the selected target.")
                    for name, filename in metadata["loaded_classes"].items():
                        relative = name.removeprefix("Crab\\Engine\\").replace("\\", "/") + ".php"
                        if Path(filename).resolve() != (expected / relative).resolve():
                            raise ValueError(f"Engine class {name} loaded outside the selected source.")
                    if metadata["http_response"] != (args.http_response if protocol == "http" else None):
                        raise ValueError("Server response mode differs from the selected workload.")
                except (ValueError, KeyError, StopIteration, TypeError) as error:
                    record["errors"].append(f"Invalid engine source metadata: {type(error).__name__}: {error}")
    record["status"] = "failed" if record["errors"] else "passed"


def environment(targets, bun):
    probe = ('echo json_encode(["opcache.enable_cli"=>ini_get("opcache.enable_cli"),'
             '"opcache.jit"=>ini_get("opcache.jit"),"opcache.jit_buffer_size"=>ini_get("opcache.jit_buffer_size"),'
             '"memory_limit"=>ini_get("memory_limit"),"extensions"=>get_loaded_extensions(),'
             '"jit_status"=>function_exists("opcache_get_status")?(opcache_get_status(false)["jit"]??null):null]);')
    packages = json.loads((ROOT / "composer.lock").read_text())["packages"]
    result = {"platform": platform.platform(), "machine": platform.machine(), "cpu_count": os.cpu_count(),
              "python": platform.python_version(), "php": capture(["php", "-v"]),
              "php_ini": capture(["php", "--ini"]), "bun": capture([bun, "--version"]),
              "node": capture(["node", "--version"]), "wrk": capture(["wrk", "--version"]),
              "revolt": next(package["version"] for package in packages if package["name"] == "revolt/event-loop"),
              "php_configuration": {target: capture(php_command(target) + ["-r", probe])
                                    for target in targets if target in PHP_TARGETS}}
    result["cpu"] = capture(["sysctl", "-n", "machdep.cpu.brand_string"]) if platform.system() == "Darwin" else capture(["lscpu"])
    executable = shutil.which(bun)
    if executable:
        path = Path(executable).resolve()
        result["bun_executable"] = {"path": str(path), "sha256": hashlib.sha256(path.read_bytes()).hexdigest()}
    package = DIRECTORY / "elysia/node_modules/elysia/package.json"
    result["elysia_version"] = json.loads(package.read_text())["version"] if package.exists() else None
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--label", required=True)
    parser.add_argument("--protocol", choices=("http", "ws", "all"), default="all")
    parser.add_argument("--targets", default="crab,elysia")
    parser.add_argument("--baseline-source", help="Snapshot src directory required by crab-before and crab-before-jit.")
    parser.add_argument("--http-response", choices=("reuse", "fresh"), default="reuse",
                        help="Crab HTTP response object lifetime; other handlers remain unchanged.")
    parser.add_argument("--bun", default="bun", help="Bun executable name or absolute path.")
    parser.add_argument("--concurrency", default="32,128")
    parser.add_argument("--threads", type=int, default=2, help="Maximum wrk threads; capped by concurrency.")
    parser.add_argument("--payloads", default="64,1024")
    parser.add_argument("--duration", type=int, default=5, help="Measured duration in positive whole seconds.")
    parser.add_argument("--trials", type=int, default=3)
    parser.add_argument("--warmup", type=int, default=2, help="Warmup duration in whole seconds; zero disables warmup.")
    args = parser.parse_args()
    try:
        targets = args.targets.split(",")
        concurrencies = [int(value) for value in args.concurrency.split(",")]
        payloads = [int(value) for value in args.payloads.split(",")]
    except ValueError:
        parser.error("Concurrency and payload lists must contain integers.")
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", args.label):
        parser.error("Label must contain only letters, digits, underscores, hyphens and dots.")
    if not targets or len(set(targets)) != len(targets) or set(targets) - TARGETS:
        parser.error("Targets must be unique names from " + ", ".join(sorted(TARGETS)) + ".")
    if set(targets) & HTTP_ONLY_TARGETS and args.protocol != "http":
        parser.error("PHP baseline and native Bun targets require --protocol=http.")
    if set(targets) & BEFORE_TARGETS and args.baseline_source is None:
        parser.error("crab-before targets require --baseline-source=/absolute/snapshot/src.")
    if args.baseline_source is not None:
        baseline = Path(args.baseline_source).expanduser().resolve()
        if not all((baseline / name).is_file() for name in ("Runtime.php", "Http/Parser.php", "Http/ResponseEncoder.php")):
            parser.error("Baseline source must be an engine src directory containing Runtime and HTTP classes.")
        args.baseline_source = str(baseline)
    if (min(concurrencies) < 1 or args.threads < 1 or min(payloads) < 8 or max(payloads) > 65535 or args.trials < 1
            or not math.isfinite(args.duration) or args.duration <= 0
            or not math.isfinite(args.warmup) or args.warmup < 0):
        parser.error("Use positive concurrency/threads/trials/duration, payloads 8..65535 and nonnegative warmup.")
    protocols = ["http", "ws"] if args.protocol == "all" else [args.protocol]
    output = DIRECTORY / "results" / f"compare-{args.label}.json"
    output.parent.mkdir(exist_ok=True)
    document = {"label": args.label, "started_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                "status": "running", "configuration": vars(args), "environment": environment(targets, args.bun),
                "source_sha256": source_hashes(), "baseline_source_sha256": baseline_source_hashes(args.baseline_source),
                "http_body_bytes": len(b"Hello from Crab\n"),
                "notes": ["One serving process per target, fresh server per trial; Crab uses StreamSelectDriver.",
                          "Target order rotates through evenly spaced starting positions; every server receives its own identical warmup before measurement.",
                          "Loopback closed-loop workloads share host CPUs; client limits and thermal drift affect results.",
                          f"HTTP uses up to {args.threads} wrk threads, capped by concurrency; WebSocket uses one Node process and one in-flight echo per connection.",
                          "ps CPU percentages are OS estimates; aggregate RSS can double-count shared pages.",
                          "Cumulative serving-process ps CPU deltas are separate from sampled percentages; resolution depends on the OS.",
                          "PHP baseline fixtures isolate HTTP/networking layers and implement a limited GET workload; they are not full HTTP servers.",
                          "WebSocket ps samples include warmup/handshake/drain; its client CPU/latency metrics cover measurement.",
                          "HTTP body is checked at readiness; wrk checks socket/status errors, not each response body.",
                          f"Crab HTTP response mode: {args.http_response}; Bun/Elysia handlers and WebSocket echo behavior are unchanged.",
                          "crab-before targets load the provided source snapshot with the same benchmark fixture and installed dependencies.",
                          "Processes are owned groups; cleanup sends SIGTERM only, and unsuccessful cleanup fails the run."],
                "trials": []}
    save(document, output)
    try:
        with tempfile.TemporaryDirectory(prefix="crab-compare-") as temporary:
            lua = Path(temporary) / "latency.lua"
            lua.write_text(HTTP.LUA)
            for protocol in protocols:
                for concurrency in concurrencies:
                    for payload in payloads if protocol == "ws" else [None]:
                        for trial in range(1, args.trials + 1):
                            offset = ((trial - 1) * len(targets) // min(args.trials, len(targets))) % len(targets)
                            order = targets[offset:] + targets[:offset]
                            for target in order:
                                record = {"protocol": protocol, "concurrency": concurrency, "payload_bytes": payload,
                                          "http_response_mode": args.http_response if protocol == "http" and target in CRAB_TARGETS else None,
                                          "trial": trial, "target": target, "target_order": order,
                                          "started_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}
                                document["trials"].append(record)
                                save(document, output)
                                print(f"{args.label}: {protocol} {target} C{concurrency} payload={payload} trial={trial}", flush=True)
                                try:
                                    run_trial(record, args, lua)
                                finally:
                                    save(document, output)
                                print(f"  {record['status']}", flush=True)
                                if record["status"] != "passed":
                                    raise RuntimeError("Benchmark trial failed; raw diagnostics have been preserved.")
        document["status"] = "passed"
    except (Exception, KeyboardInterrupt) as error:
        document["status"] = "failed"
        document["error"] = f"{type(error).__name__}: {error}"
    finally:
        final_hashes = source_hashes()
        document["source_sha256_after"] = final_hashes
        document["source_hash_changes"] = sorted(path for path in set(final_hashes) | set(document["source_sha256"])
                                                 if final_hashes.get(path) != document["source_sha256"].get(path))
        if document["source_hash_changes"]:
            document["status"] = "failed"
            document["source_error"] = "Source files changed during measurements."
        baseline_hashes = baseline_source_hashes(args.baseline_source)
        document["baseline_source_sha256_after"] = baseline_hashes
        document["baseline_source_hash_changes"] = sorted(
            path for path in set(baseline_hashes) | set(document["baseline_source_sha256"])
            if baseline_hashes.get(path) != document["baseline_source_sha256"].get(path))
        if document["baseline_source_hash_changes"]:
            document["status"] = "failed"
            document["baseline_source_error"] = "Snapshot source files changed during measurements."
        document["finished_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        save(document, output)
    print(f"{document['status']}: {output}", flush=True)
    return 0 if document["status"] == "passed" else 1


def interrupted(signum, frame):
    raise KeyboardInterrupt(f"Received signal {signum}.")


if __name__ == "__main__":
    signal.signal(signal.SIGTERM, interrupted)
    with open("/tmp/crab-engine-benchmark.lock", "a") as lock:
        print("Waiting for exclusive benchmark access...", flush=True)
        fcntl.flock(lock, fcntl.LOCK_EX)
        raise SystemExit(main())
