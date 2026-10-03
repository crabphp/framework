#!/usr/bin/env python3
"""Reproducible local HTTP/wrk comparisons; preserves raw output and environment."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import platform
import signal
import socket
import statistics
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
LUA = '''done = function(summary, latency, requests)
  io.write(string.format("CRAB_METRICS {\\"requests\\":%d,\\"duration_us\\":%d,\\"p50_ms\\":%.6f,\\"p95_ms\\":%.6f,\\"p99_ms\\":%.6f,\\"connect_errors\\":%d,\\"read_errors\\":%d,\\"write_errors\\":%d,\\"status_errors\\":%d,\\"timeouts\\":%d}\\n", summary.requests, summary.duration, latency:percentile(50)/1000, latency:percentile(95)/1000, latency:percentile(99)/1000, summary.errors.connect, summary.errors.read, summary.errors.write, summary.errors.status, summary.errors.timeout))
end
'''


def capture(command):
    return subprocess.run(command, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT).stdout.strip()


def ephemeral_port():
    with socket.socket() as stream:
        stream.bind(("127.0.0.1", 0))
        return stream.getsockname()[1]


def sample_processes(server_pid, load_pid):
    rows = {}
    for line in capture(["ps", "-axo", "pid=,ppid=,%cpu=,rss="]).splitlines():
        fields = line.split()
        if len(fields) == 4:
            rows[int(fields[0])] = (int(fields[1]), float(fields[2]), int(fields[3]) * 1024)
    owned = {server_pid}
    while True:
        children = {pid for pid, row in rows.items() if row[0] in owned}
        if children <= owned:
            break
        owned |= children
    return {"server_cpu_percent": sum(rows[p][1] for p in owned if p in rows),
            "server_rss_bytes": sum(rows[p][2] for p in owned if p in rows),
            "load_cpu_percent": rows.get(load_pid, (0, 0, 0))[1],
            "load_rss_bytes": rows.get(load_pid, (0, 0, 0))[2]}


def run_load(server, port, concurrency, duration, lua):
    command = ["wrk", "-t", str(min(2, concurrency)), "-c", str(concurrency), "-d", f"{duration}s",
               "--timeout", "2s", "--latency", "-s", str(lua), f"http://127.0.0.1:{port}/"]
    load = subprocess.Popen(command, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    samples = []
    try:
        while load.poll() is None:
            samples.append(sample_processes(server.pid, load.pid))
            time.sleep(0.2)
        output = load.communicate()[0]
    finally:
        if load.poll() is None:
            load.terminate()
            load.wait(timeout=3)
    metrics = next((json.loads(line.removeprefix("CRAB_METRICS ")) for line in output.splitlines()
                    if line.startswith("CRAB_METRICS ")), None)
    if load.returncode or metrics is None:
        raise RuntimeError(output)
    metrics["requests_per_second"] = metrics["requests"] * 1_000_000 / metrics["duration_us"]
    return {"command": command, "raw_output": output, "metrics": metrics, "samples": samples,
            "peak_server_rss_bytes": max((s["server_rss_bytes"] for s in samples), default=0)}


def verify_server(server, port):
    for _ in range(150):
        if server.poll() is not None:
            raise RuntimeError("Benchmark server exited during startup.")
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=0.2) as client:
                client.sendall(b"GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
                output = b""
                while chunk := client.recv(65536):
                    output += chunk
                head, body = output.split(b"\r\n\r\n", 1)
                if not head.startswith(b"HTTP/1.1 200 OK\r\n") or body != b"Hello from Crab\n":
                    raise RuntimeError(f"Unexpected benchmark response: {output!r}")
            return
        except (ConnectionError, OSError):
            time.sleep(0.02)
    raise RuntimeError("Benchmark server did not become ready.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--label", required=True)
    parser.add_argument("--workers", default="1")
    parser.add_argument("--concurrency", default="1,32,128")
    parser.add_argument("--duration", type=int, default=4)
    parser.add_argument("--trials", type=int, default=3)
    parser.add_argument("--warmup", type=int, default=1)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    workers = [int(x) for x in args.workers.split(",")]
    concurrencies = [int(x) for x in args.concurrency.split(",")]
    if min(workers + concurrencies + [args.duration, args.trials, args.warmup]) < 1:
        parser.error("Counts and durations must be positive.")
    php = ["php", "-d", "opcache.enable_cli=1", "-d", "opcache.jit=off"]
    result = {"label": args.label, "started_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
              "notes": ["Loopback on a shared development host; load generator and server share CPUs.",
                        "wrk is closed-loop; throughput does not establish open-loop overload capacity.",
                        "ps CPU percentages are OS sampling estimates; process RSS is summed including shared pages.",
                        "16-byte static response, persistent HTTP/1.1, maxRequests=1000000, explicit StreamSelectDriver."],
              "environment": {"platform": platform.platform(), "cpu": capture(["sysctl", "-n", "machdep.cpu.brand_string"]),
                              "php": capture(php + ["-v"]), "extensions": capture(php + ["-m"]),
                              "wrk": capture(["wrk", "--version"]), "python": platform.python_version(),
                              "php_ini": capture(php + ["--ini"]),
                              "opcache_enable_cli": True, "opcache_jit": "off"},
              "source_sha256": {str(path.relative_to(ROOT)): hashlib.sha256(path.read_bytes()).hexdigest()
                                for path in sorted(ROOT.joinpath("src/Engine").rglob("*.php"))},
              "revolt": json.loads(ROOT.joinpath("composer.lock").read_text())["packages"][0]["version"],
              "configuration": vars(args) | {"output": str(args.output) if args.output else None},
              "cases": []}
    body_probe = ('require ' + json.dumps(str(ROOT / "vendor/autoload.php")) + '; '
                  '$before=count(get_resources("stream")); $body=new Crab\\Engine\\Http\\Body(); '
                  '$opened=count(get_resources("stream"))-$before; unset($body); $runs=[]; '
                  'for($trial=0;$trial<3;++$trial){$start=hrtime(true); '
                  'for($i=0;$i<100000;++$i){$body=new Crab\\Engine\\Http\\Body(); $body->rewind(); unset($body);} '
                  '$runs[]=(hrtime(true)-$start)/1e6;} '
                  'echo json_encode(["iterations"=>100000,"empty_body_open_streams"=>$opened,"duration_ms"=>$runs]);')
    result["body_allocation_probe"] = json.loads(capture(php + ["-r", body_probe]))
    encoder_probe = ('require ' + json.dumps(str(ROOT / "vendor/autoload.php")) + '; '
                     '$request=new Crab\\Engine\\Http\\Request("GET","/","1.1",["host"=>["localhost"]],new Crab\\Engine\\Http\\Body()); '
                     '$encoder=new Crab\\Engine\\Http\\ResponseEncoder(Crab\\Engine\\Http\\Response::text("Hello from Crab\\n"),$request); '
                     'echo json_encode(array_map(strlen(...),iterator_to_array($encoder->bytes())));')
    result["response_encoder_segment_bytes"] = json.loads(capture(php + ["-r", encoder_probe]))
    output_path = args.output or ROOT / "benchmarks" / "results" / f"http-{args.label}.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="crab-http-bench-") as temporary:
        lua = Path(temporary) / "latency.lua"
        lua.write_text(LUA)
        for count in workers:
            port = ephemeral_port()
            command = php + [str(ROOT / "benchmarks/server.php"), "--protocol=http", f"--port={port}", f"--workers={count}"]
            with tempfile.TemporaryFile(mode="w+") as log:
                server = subprocess.Popen(command, stdout=log, stderr=log, start_new_session=True)
                try:
                    verify_server(server, port)
                    for concurrency in concurrencies:
                        run_load(server, port, concurrency, args.warmup, lua)
                        trials = [run_load(server, port, concurrency, args.duration, lua) for _ in range(args.trials)]
                        case = {"workers": count, "concurrency": concurrency, "server_command": command, "trials": trials,
                                "median_requests_per_second": statistics.median(t["metrics"]["requests_per_second"] for t in trials)}
                        result["cases"].append(case)
                        output_path.write_text(json.dumps(result, indent=2) + "\n")
                        print(f"{args.label}: workers={count} concurrency={concurrency} median={case['median_requests_per_second']:.1f} req/s", flush=True)
                finally:
                    if server.poll() is None:
                        os.killpg(server.pid, signal.SIGTERM)
                        try:
                            server.wait(timeout=8)
                        except subprocess.TimeoutExpired:
                            os.killpg(server.pid, signal.SIGKILL)
                            server.wait(timeout=3)
                    log.seek(0)
                    result.setdefault("server_logs", []).append({"workers": count, "exit_code": server.returncode, "output": log.read()})
                    output_path.write_text(json.dumps(result, indent=2) + "\n")
    print(output_path)


if __name__ == "__main__":
    with open("/tmp/crab-engine-benchmark.lock", "a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        main()
