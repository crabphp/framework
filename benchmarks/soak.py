#!/usr/bin/env python3
"""Manual long-running stability check: mixed HTTP and WebSocket traffic against
one HTTP and one WebSocket server process, with memory and descriptor samples.

It is not a benchmark and never runs with the test suite. Start it by hand:

    python3 benchmarks/soak.py --label=overnight --minutes=480

Each cycle sends persistent requests, one connection per request, request
bodies, streamed megabyte responses, WebSocket echoes, and a batch of broken
clients (malformed heads, partial heads, abrupt disconnects). After every cycle
the servers rest briefly and are sampled. The run fails if a server exits, a
load reports a wrong status, descriptors do not return to their resting count,
or resting memory keeps growing.
"""
import argparse
import fcntl
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

DIRECTORY = Path(__file__).resolve().parent
ROOT = DIRECTORY.parent
BROWSER_LUA = '''local next_request = 0
request = function()
  next_request = next_request + 1
  wrk.headers["X-Request-Id"] = "soak-" .. next_request
  wrk.headers["User-Agent"] = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/128.0.0.0 Safari/537.36"
  wrk.headers["Accept"] = "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"
  wrk.headers["Cookie"] = "session=abcdef0123456789abcdef0123456789; theme=dark"
  return wrk.format("GET", "/?r=" .. next_request)
end
'''
CLOSE_LUA = 'wrk.headers["Connection"] = "close"\n'
BODY_LUA = 'wrk.method = "POST"\nwrk.body = string.rep("x", 2048)\nwrk.headers["Content-Type"] = "application/octet-stream"\n'
BROKEN = [b"GET / HTTP/1.1\r\nHost: x\r\nBroken header\r\n\r\n", b"GET / HTTP/9.9\r\nHost: x\r\n\r\n",
          b"POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 5\r\nContent-Length: 6\r\n\r\n", b"\x00\x01\x02\r\n\r\n",
          b"GET / HTTP/1.1\r\nHost: x\r\n", b"POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 100\r\n\r\nshort", b""]


def free_port():
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))
        return probe.getsockname()[1]


def sample(pid):
    """Resident memory and open descriptors of one process."""
    rss = subprocess.run(["ps", "-o", "rss=", "-p", str(pid)], capture_output=True, text=True).stdout.strip()
    proc = Path(f"/proc/{pid}/fd")
    if proc.is_dir():
        descriptors = len(list(proc.iterdir()))
    else:
        listing = subprocess.run(["lsof", "-a", "-p", str(pid), "-d", "0-999999"], capture_output=True, text=True).stdout
        descriptors = max(0, len(listing.splitlines()) - 1)
    return {"rss_bytes": int(rss) * 1024 if rss else None, "descriptors": descriptors}


def wrk(port, path, connections, seconds, lua=None):
    command = ["wrk", "-t", "2", "-c", str(connections), "-d", f"{seconds}s", "--timeout", "5s"]
    if lua is not None:
        command += ["-s", str(lua)]
    output = subprocess.run(command + [f"http://127.0.0.1:{port}{path}"], capture_output=True, text=True).stdout
    requests = next((int(line.split()[0]) for line in output.splitlines() if " requests in " in line), 0)
    bad = next((int(line.split(":")[1]) for line in output.splitlines() if "Non-2xx" in line), 0)
    sockets = next((line.strip() for line in output.splitlines() if "Socket errors" in line), "")
    return {"path": path, "requests": requests, "non_2xx": bad, "socket_errors": sockets}


def broken_clients(port, count):
    """Clients that misbehave; the server must answer or drop each without harm."""
    answered = 0
    for index in range(count):
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=2) as client:
                payload = BROKEN[index % len(BROKEN)]
                if payload:
                    client.sendall(payload)
                if index % 3 == 0:
                    # Reset instead of closing politely.
                    client.setsockopt(socket.SOL_SOCKET, socket.SO_LINGER, b"\x01\x00\x00\x00\x00\x00\x00\x00")
                    continue
                client.settimeout(0.05)
                try:
                    answered += bool(client.recv(4096))
                except (socket.timeout, OSError):
                    pass
        except OSError:
            pass
    return {"clients": count, "answered": answered}


def start(protocol, port, php):
    log = tempfile.TemporaryFile(mode="w+")
    command = php + [str(DIRECTORY / "server.php"), f"--protocol={protocol}", f"--port={port}", "--workers=1",
                     "--http-response=fresh"]
    process = subprocess.Popen(command, cwd=ROOT, stdout=log, stderr=log, start_new_session=True)
    deadline = time.monotonic() + 5
    while time.monotonic() < deadline:
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                return process, log, command
        except OSError:
            time.sleep(0.05)
    raise RuntimeError(f"The {protocol} server did not start.")


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--label", required=True)
    parser.add_argument("--minutes", type=float, default=30, help="How long to keep cycling; hours are the point of this test.")
    parser.add_argument("--jit", choices=("off", "tracing"), default="tracing")
    parser.add_argument("--connections", type=int, default=64)
    args = parser.parse_args()
    if args.minutes <= 0 or args.connections < 2:
        parser.error("Use a positive duration and at least two connections.")
    php = ["php", "-d", "opcache.enable_cli=1", "-d", f"opcache.jit={args.jit}", "-d",
           "opcache.jit_buffer_size=128M" if args.jit == "tracing" else "opcache.jit_buffer_size=0"]
    output = DIRECTORY / "results" / f"soak-{args.label}.json"
    output.parent.mkdir(exist_ok=True)
    result = {"label": args.label, "started_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
              "configuration": vars(args), "platform": platform.platform(),
              "php": subprocess.run(["php", "-v"], capture_output=True, text=True).stdout.splitlines()[0],
              "status": "running", "problems": [], "cycles": []}
    servers = {}
    try:
        with tempfile.TemporaryDirectory(prefix="crab-soak-") as temporary:
            scripts = {}
            for name, text in (("browser", BROWSER_LUA), ("close", CLOSE_LUA), ("body", BODY_LUA)):
                scripts[name] = Path(temporary) / f"{name}.lua"
                scripts[name].write_text(text)
            for protocol in ("http", "ws"):
                port = free_port()
                process, log, command = start(protocol, port, php)
                servers[protocol] = {"process": process, "log": log, "port": port, "command": command}
            http, ws = servers["http"]["port"], servers["ws"]["port"]
            # One short cycle's worth of traffic settles allocator and JIT state before the baseline.
            wrk(http, "/", args.connections, 3, scripts["browser"])
            time.sleep(1)
            result["baseline"] = {name: sample(server["process"].pid) for name, server in servers.items()}
            finish = time.monotonic() + args.minutes * 60
            while time.monotonic() < finish and not result["problems"]:
                cycle = {"elapsed_minutes": round((time.monotonic() - (finish - args.minutes * 60)) / 60, 2), "loads": []}
                cycle["loads"].append(wrk(http, "/", args.connections, 8, scripts["browser"]))
                cycle["loads"].append(wrk(http, "/", max(2, args.connections // 2), 4, scripts["close"]))
                cycle["loads"].append(wrk(http, "/upload", max(2, args.connections // 2), 4, scripts["body"]))
                cycle["loads"].append(wrk(http, "/stream", 8, 4))
                echo = subprocess.run(["node", str(DIRECTORY / "websocket.mjs"), f"--port={ws}",
                                       f"--concurrency={max(2, args.connections // 2)}", "--payload=1024",
                                       "--duration=4", "--warmup=0"], capture_output=True, text=True)
                try:
                    metrics = json.loads(echo.stdout)
                    cycle["websocket"] = {"messages": metrics["completed_messages"], "errors": metrics["errors"],
                                          "first_error": metrics["first_error"]}
                except (ValueError, KeyError):
                    cycle["websocket"] = {"messages": 0, "errors": 1, "first_error": (echo.stderr or echo.stdout)[-300:]}
                cycle["broken"] = broken_clients(http, 200)
                time.sleep(2)
                cycle["samples"] = {name: sample(server["process"].pid) for name, server in servers.items()}
                for name, server in servers.items():
                    if server["process"].poll() is not None:
                        result["problems"].append(f"The {name} server exited with code {server['process'].returncode}.")
                for load in cycle["loads"]:
                    if load["requests"] == 0 or load["non_2xx"]:
                        result["problems"].append(f"Load {load['path']} completed {load['requests']} requests with {load['non_2xx']} wrong statuses.")
                if cycle["websocket"]["errors"] or not cycle["websocket"]["messages"]:
                    result["problems"].append(f"WebSocket echo failed: {cycle['websocket']['first_error']}")
                result["cycles"].append(cycle)
                output.write_text(json.dumps(result, indent=2) + "\n")
                rss = cycle["samples"]["http"]["rss_bytes"] / 1048576
                print(f"{cycle['elapsed_minutes']:7.1f} min  http {rss:6.1f} MiB {cycle['samples']['http']['descriptors']:3d} fds   "
                      f"ws {cycle['samples']['ws']['rss_bytes'] / 1048576:6.1f} MiB {cycle['samples']['ws']['descriptors']:3d} fds   "
                      f"{sum(load['requests'] for load in cycle['loads']):8d} requests", flush=True)
            # Judge resting state only: every sample follows two quiet seconds.
            for name in servers:
                samples = [cycle["samples"][name] for cycle in result["cycles"]]
                if not samples:
                    continue
                base = result["baseline"][name]
                leaked = samples[-1]["descriptors"] - base["descriptors"]
                third = max(1, len(samples) // 3)
                early = statistics.median(s["rss_bytes"] for s in samples[:third])
                late = statistics.median(s["rss_bytes"] for s in samples[-third:])
                result.setdefault("summary", {})[name] = {
                    "cycles": len(samples), "descriptors_baseline": base["descriptors"],
                    "descriptors_final": samples[-1]["descriptors"], "descriptors_peak_at_rest": max(s["descriptors"] for s in samples),
                    "rss_baseline_mib": round(base["rss_bytes"] / 1048576, 1), "rss_first_third_mib": round(early / 1048576, 1),
                    "rss_last_third_mib": round(late / 1048576, 1), "rss_peak_mib": round(max(s["rss_bytes"] for s in samples) / 1048576, 1)}
                if leaked > 2:
                    result["problems"].append(f"The {name} server holds {leaked} more descriptors at rest than at baseline.")
                # Short runs cannot show a trend; growth must be both relative and absolute to count.
                if len(samples) >= 9 and late > early * 1.10 and late - early > 4 * 1048576:
                    result["problems"].append(f"The {name} server's resting memory grew from {early / 1048576:.1f} to {late / 1048576:.1f} MiB.")
    except (Exception, KeyboardInterrupt) as error:
        result["problems"].append(f"{type(error).__name__}: {error}")
    finally:
        for name, server in servers.items():
            process = server["process"]
            if process.poll() is None:
                os.killpg(process.pid, signal.SIGTERM)
                try:
                    process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    os.killpg(process.pid, signal.SIGKILL)
                    process.wait(timeout=5)
                    result["problems"].append(f"The {name} server ignored SIGTERM.")
            server["log"].seek(0)
            text = server["log"].read()
            result.setdefault("servers", {})[name] = {"command": server["command"], "exit_code": process.returncode, "log": text[-4000:]}
            if process.returncode != 0 and not any(name in problem and "exited" in problem for problem in result["problems"]):
                result["problems"].append(f"The {name} server exited with code {process.returncode} at shutdown.")
            if text.strip():
                result["problems"].append(f"The {name} server wrote to its log; see servers.{name}.log.")
        result["status"] = "failed" if result["problems"] else "passed"
        result["finished_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        output.write_text(json.dumps(result, indent=2) + "\n")
    print(json.dumps(result.get("summary", {}), indent=2))
    for problem in result["problems"]:
        print("PROBLEM:", problem)
    print(f"{result['status']}: {output}")
    return 0 if result["status"] == "passed" else 1


if __name__ == "__main__":
    with open("/tmp/crab-engine-benchmark.lock", "a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        raise SystemExit(main())
