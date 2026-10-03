#!/usr/bin/env python3
"""Bounded HTTP correctness checks for diagnostic and production benchmark fixtures."""

import argparse
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
import fcntl
import hashlib
import json
from pathlib import Path
import select
import socket
import subprocess
import tempfile
import time


DIRECTORY = Path(__file__).resolve().parent
ROOT = DIRECTORY.parent.parent
BODY = b"Hello from Crab\n"
REQUEST = b"GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"
CLOSE_REQUEST = b"GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n"


def require(condition, message):
    if not condition:
        raise AssertionError(message)


class Client:
    def __init__(self, port, timeout, slow=False):
        self.socket = socket.create_connection(("127.0.0.1", port), timeout=timeout)
        self.socket.settimeout(timeout)
        if slow:
            self.socket.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 1024)
        self.buffer = b""
        self.deadline = time.monotonic() + timeout

    def __enter__(self):
        return self

    def __exit__(self, *_):
        self.socket.close()

    def receive(self):
        self.remaining_timeout()
        chunk = self.socket.recv(65536)
        require(chunk, "Server closed before a complete response arrived.")
        self.buffer += chunk

    def response(self):
        while b"\r\n\r\n" not in self.buffer:
            require(len(self.buffer) <= 32768, "Response headers exceeded the verifier limit.")
            self.receive()
        head, self.buffer = self.buffer.split(b"\r\n\r\n", 1)
        lines = head.decode("latin1").split("\r\n")
        version, status, _ = lines[0].split(" ", 2)
        require(version == "HTTP/1.1", f"Unexpected response version: {version}")
        headers = {}
        for line in lines[1:]:
            name, separator, value = line.partition(":")
            require(separator and name and not name[0].isspace(), f"Malformed response field: {line!r}")
            headers.setdefault(name.lower(), []).append(value.strip())
        lengths = headers.get("content-length", [])
        require(len(lengths) == 1 and lengths[0].isdigit(), "Expected exactly one numeric Content-Length.")
        length = int(lengths[0])
        require(length <= 1048576, "Response body exceeded the verifier limit.")
        require("transfer-encoding" not in headers, "Buffered fixture response unexpectedly used transfer coding.")
        while len(self.buffer) < length:
            self.receive()
        body, self.buffer = self.buffer[:length], self.buffer[length:]
        return {"status": int(status), "headers": headers, "body": body, "raw_headers": head.decode("latin1")}

    def expect_close(self):
        require(self.buffer == b"", "Unexpected bytes followed the final response.")
        self.remaining_timeout()
        require(self.socket.recv(1) == b"", "Server kept the connection open after its final response.")

    def remaining_timeout(self):
        remaining = self.deadline - time.monotonic()
        if remaining <= 0:
            raise TimeoutError("Bounded verification connection exceeded its deadline.")
        self.socket.settimeout(remaining)


def check_success(response):
    require(response["status"] == 200, f"Expected status 200, received {response['status']}.")
    require(response["body"] == BODY, f"Unexpected response body: {response['body']!r}")
    headers = response["headers"]
    require(headers.get("content-length") == ["16"], "Expected Content-Length: 16.")
    require(headers.get("content-type") == ["text/plain; charset=utf-8"], "Content-Type differs from the common fixture.")
    require("content-encoding" not in headers, "Fixture response unexpectedly used compression.")
    dates = headers.get("date", [])
    require(len(dates) == 1, "Expected one Date header.")
    date = parsedate_to_datetime(dates[0])
    require(date.tzinfo is not None and abs((datetime.now(timezone.utc) - date).total_seconds()) < 120,
            "Date header is missing a timezone or is not current.")


def verify_http(port, *, diagnostic=False, timeout=2.0, pressure_requests=0):
    """Validate a running fixture without sustaining load or owning its process.

    The default sends only 13 successful requests. Diagnostic rejection checks
    apply only to the deliberately restricted PHP fixture. The optional slow
    reader is bounded and checks byte preservation; it does not prove that a
    particular write was partial without separate server-side instrumentation.
    """
    result = {"status": "passed", "checks": [], "successful_requests": 0}
    with Client(port, timeout) as client:
        client.socket.sendall(CLOSE_REQUEST)
        response = client.response()
        check_success(response)
        client.expect_close()
        result["response"] = {"raw_headers": response["raw_headers"], "body": BODY.decode(), "body_bytes": len(BODY)}
    result["checks"].append("exact body, headers, and Connection: close")
    result["successful_requests"] += 1

    with Client(port, timeout) as client:
        for index in range(3):
            client.socket.sendall(CLOSE_REQUEST if index == 2 else REQUEST)
            check_success(client.response())
        client.expect_close()
    result["checks"].append("three sequential requests on one persistent connection")
    result["successful_requests"] += 3

    fragments = [b"GET / HTTP/1.1\r\nHo", b"st: localhost\r", b"\nConnection: close\r\n", b"\r", b"\n"]
    with Client(port, timeout) as client:
        for fragment in fragments[:-1]:
            client.socket.sendall(fragment)
            readable, _, _ = select.select([client.socket], [], [], 0.005)
            require(not readable, "Server responded or closed before fragmented request headers were complete.")
        client.socket.sendall(fragments[-1])
        check_success(client.response())
        client.expect_close()
    result["checks"].append("fragmented request line, header name, and CRLF terminator")
    result["successful_requests"] += 1

    with Client(port, timeout) as client:
        client.socket.sendall(REQUEST * 7 + CLOSE_REQUEST)
        for _ in range(8):
            check_success(client.response())
        client.expect_close()
    result["checks"].append("eight ordered pipelined responses without lost or extra bytes")
    result["successful_requests"] += 8

    if diagnostic:
        with Client(port, timeout) as client:
            client.socket.sendall(b"GET / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\nConnection: close\r\n\r\n")
            check_success(client.response())
            client.expect_close()
        result["successful_requests"] += 1
        result["checks"].append("explicit zero-length request body")
        rejected = [
            ("malformed request line", b"garbage\r\n\r\n", 400),
            ("missing Host", b"GET / HTTP/1.1\r\n\r\n", 400),
            ("duplicate Host", b"GET / HTTP/1.1\r\nHost: a\r\nHost: b\r\n\r\n", 400),
            ("unsupported method", b"POST / HTTP/1.1\r\nHost: x\r\n\r\n", 405),
            ("unsupported target", b"GET /other HTTP/1.1\r\nHost: x\r\n\r\n", 404),
            ("nonempty body", b"GET / HTTP/1.1\r\nHost: x\r\nContent-Length: 1\r\n\r\nx", 501),
            ("transfer coding", b"GET / HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n", 501),
            ("invalid Content-Length", b"GET / HTTP/1.1\r\nHost: x\r\nContent-Length: x\r\n\r\n", 400),
            ("duplicate Content-Length", b"GET / HTTP/1.1\r\nHost: x\r\nContent-Length: 0\r\nContent-Length: 0\r\n\r\n", 400),
            ("oversized headers", b"GET / HTTP/1.1\r\nHost: x\r\nX-Large: " + b"x" * 16384 + b"\r\n\r\n", 431),
        ]
        for name, request, status in rejected:
            with Client(port, timeout) as client:
                client.socket.sendall(request)
                response = client.response()
                require(response["status"] == status, f"{name}: expected {status}, received {response['status']}.")
                require(response["headers"].get("connection") == ["close"], f"{name}: rejection must declare Connection: close.")
                client.expect_close()
        result["checks"].append("explicit closing rejection of ten unsupported or invalid requests")
        result["rejected_requests"] = len(rejected)

    if pressure_requests:
        require(1 <= pressure_requests <= 2048, "Slow-reader verification is capped at 2,048 requests.")
        with Client(port, max(timeout, 5.0), slow=True) as client:
            client.socket.sendall(REQUEST * (pressure_requests - 1) + CLOSE_REQUEST)
            time.sleep(0.05)
            for _ in range(pressure_requests):
                check_success(client.response())
            client.expect_close()
        result["successful_requests"] += pressure_requests
        result["slow_reader_requests"] = pressure_requests
        result["checks"].append("bounded slow-reader pipeline preserves every response byte")
    return result


def ephemeral_port():
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        return listener.getsockname()[1]


def wait_ready(process, port):
    deadline = time.monotonic() + 5
    while time.monotonic() < deadline:
        require(process.poll() is None, "Fixture exited during startup.")
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                return
        except OSError:
            time.sleep(0.01)
    raise TimeoutError("Fixture did not become ready within five seconds.")


def verify_process(command, port, diagnostic, pressure_requests):
    result = {"command": command, "status": "failed"}
    with tempfile.TemporaryFile(mode="w+") as log:
        process = subprocess.Popen(command, cwd=ROOT, stdout=log, stderr=subprocess.STDOUT)
        result["pid"] = process.pid
        try:
            wait_ready(process, port)
            result["http"] = verify_http(port, diagnostic=diagnostic, pressure_requests=pressure_requests)
            # Repeated connect/close verifies the fixture remains usable after
            # earlier peers disappear; this is not an FD-leak measurement.
            for _ in range(16):
                with socket.create_connection(("127.0.0.1", port), timeout=1):
                    pass
            result["after_disconnects"] = verify_http(port)
            result["status"] = "passed"
        except Exception as error:
            result["error"] = f"{type(error).__name__}: {error}"
        finally:
            if process.poll() is None:
                process.terminate()
            try:
                process.wait(timeout=3)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=3)
                result["status"] = "failed"
                result["cleanup_error"] = "Owned fixture required forced cleanup after SIGTERM."
            result["exit_code"] = process.returncode
            if process.returncode != 0:
                result["status"] = "failed"
            log.seek(0)
            result["server_log"] = log.read()
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default="php")
    parser.add_argument("--backends", default="select,revolt,transport,codec")
    parser.add_argument("--jit", choices=("off", "tracing"), default="off")
    parser.add_argument("--bun", help="Also validate the native Bun and Elysia fixtures with this binary.")
    parser.add_argument("--pressure-requests", type=int, default=2048)
    parser.add_argument("--output", type=Path, help="Save the complete validation report as JSON.")
    args = parser.parse_args()
    backends = args.backends.split(",") if args.backends else []
    require(set(backends) <= {"select", "revolt", "transport", "codec"}, "Unknown PHP backend.")
    records = []
    with open("/tmp/crab-engine-benchmark.lock", "w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        php = [args.php, "-d", "opcache.enable_cli=1", "-d", f"opcache.jit={args.jit}",
               "-d", "opcache.jit_buffer_size=128M" if args.jit == "tracing" else "opcache.jit_buffer_size=0"]
        probe = ('echo json_encode(["version"=>PHP_VERSION,"opcache.enable_cli"=>ini_get("opcache.enable_cli"),'
                 '"opcache.jit"=>ini_get("opcache.jit"),"opcache.jit_buffer_size"=>ini_get("opcache.jit_buffer_size"),'
                 '"jit_status"=>function_exists("opcache_get_status")?(opcache_get_status(false)["jit"]??null):null]);')
        configuration = json.loads(subprocess.check_output(php + ["-r", probe], text=True, timeout=10))
        sources = list(ROOT.joinpath("src").rglob("*.php")) + [DIRECTORY / "php.php", DIRECTORY / "bun.ts",
                  DIRECTORY / "verify.py", DIRECTORY.parent / "elysia/server.ts", ROOT / "composer.lock"]
        hashes = {str(path.relative_to(ROOT)): hashlib.sha256(path.read_bytes()).hexdigest()
                  for path in sorted(sources) if path.is_file()}
        for backend in backends:
            port = ephemeral_port()
            command = php + [str(DIRECTORY / "php.php"), f"--backend={backend}", "--protocol=http", f"--port={port}"]
            result = verify_process(command, port, True, args.pressure_requests)
            result["target"] = f"php-{backend}"
            records.append(result)
        if args.bun:
            for target, fixture in [("bun", DIRECTORY / "bun.ts"), ("elysia", DIRECTORY.parent / "elysia/server.ts")]:
                port = ephemeral_port()
                command = [args.bun, str(fixture), "--protocol=http", f"--port={port}"]
                result = verify_process(command, port, False, 0)
                result["target"] = target
                records.append(result)
        unchanged = all(hashlib.sha256(ROOT.joinpath(path).read_bytes()).hexdigest() == digest
                        for path, digest in hashes.items())
    document = {"kind": "bounded correctness verification", "php_configuration": configuration,
                "source_sha256": hashes, "sources_unchanged_during_checks": unchanged, "records": records}
    encoded = json.dumps(document, indent=2) + "\n"
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        temporary = args.output.with_suffix(".tmp")
        temporary.write_text(encoded)
        temporary.replace(args.output)
    print(encoded, end="")
    return 0 if unchanged and records and all(record["status"] == "passed" for record in records) else 1


if __name__ == "__main__":
    raise SystemExit(main())
