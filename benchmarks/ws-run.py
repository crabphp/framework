#!/usr/bin/env python3
"""Run matching, validated WebSocket trials; owns and stops only its server process."""

import argparse
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import platform
import signal
import socket
import subprocess
import time


parser = argparse.ArgumentParser()
parser.add_argument('--label', required=True)
parser.add_argument('--duration', type=float, default=2)
parser.add_argument('--warmup', type=float, default=0.5)
parser.add_argument('--trials', type=int, default=3)
parser.add_argument('--concurrency', default='1,32,128')
parser.add_argument('--payloads', default='64,1024')
parser.add_argument('--workers', type=int, default=1)
parser.add_argument('--opcache-cli', action='store_true')
args = parser.parse_args()
directory = Path(__file__).resolve().parent
results = directory / 'results'
results.mkdir(exist_ok=True)
benchmark_lock = open('/tmp/crab-engine-benchmark.lock', 'a')
print('Waiting for exclusive benchmark access...', flush=True)
fcntl.flock(benchmark_lock, fcntl.LOCK_EX)


def server_usage(pid):
    fields = subprocess.check_output(['ps', '-p', str(pid), '-o', 'rss=', '-o', 'time='], text=True).split()
    cpu = 0.0
    for part in fields[1].split(':'):
        cpu = cpu * 60 + float(part)
    return {'rss_bytes': int(fields[0]) * 1024, 'cpu_seconds': cpu}


with socket.socket() as reservation:
    reservation.bind(('127.0.0.1', 0))
    port = reservation.getsockname()[1]

php_command = ['php'] + (['-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=0'] if args.opcache_cli else [])
server_command = php_command + [str(directory / 'server.php'), '--protocol=ws', '--host=127.0.0.1',
                  f'--port={port}', f'--workers={args.workers}']
log_path = results / f'ws-{args.label}-server.log'
document = {
    'label': args.label, 'timestamp_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
    'system': platform.platform(), 'machine': platform.machine(), 'cpu_count': os.cpu_count(),
    'php': subprocess.check_output(php_command + ['-v'], text=True).strip(),
    'php_configuration': json.loads(subprocess.check_output(php_command + ['-r',
        'echo json_encode(["opcache.enable_cli"=>ini_get("opcache.enable_cli"),'
        '"opcache.jit"=>ini_get("opcache.jit"),"opcache.jit_buffer_size"=>ini_get("opcache.jit_buffer_size"),'
        '"memory_limit"=>ini_get("memory_limit"),"extensions"=>get_loaded_extensions()]);'], text=True)),
    'driver': 'Revolt StreamSelectDriver', 'workers': args.workers,
    'server_command': server_command, 'shared_host_client': True,
    'method': 'closed-loop, one in-flight masked binary echo per connection; validated payload; fresh connections per trial',
    'results': [],
}
source_files = sorted((directory.parent / 'src' / 'Engine').rglob('*.php')) + [directory / 'server.php', directory / 'websocket.mjs']
document['source_sha256'] = {str(path.relative_to(directory.parent)): hashlib.sha256(path.read_bytes()).hexdigest()
                             for path in source_files}
packages = json.loads((directory.parent / 'composer.lock').read_text())['packages']
document['revolt_version'] = next(package['version'] for package in packages if package['name'] == 'revolt/event-loop')
output_path = results / f'ws-{args.label}.json'
with log_path.open('w') as log:
    server = subprocess.Popen(server_command, stdout=log, stderr=subprocess.STDOUT,
                              start_new_session=True)
    try:
        deadline = time.monotonic() + 5
        while True:
            if server.poll() is not None:
                raise RuntimeError(f'Server exited: {log_path.read_text()}')
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=0.2):
                    break
            except OSError:
                if time.monotonic() >= deadline:
                    raise RuntimeError('Server did not become ready.')
                time.sleep(0.05)
        for payload in map(int, args.payloads.split(',')):
            for concurrency in map(int, args.concurrency.split(',')):
                for trial in range(1, args.trials + 1):
                    command = ['node', str(directory / 'websocket.mjs'), f'--port={port}',
                               f'--concurrency={concurrency}', f'--payload={payload}',
                               f'--duration={args.duration}', f'--warmup={args.warmup}']
                    before = server_usage(server.pid)
                    completed = subprocess.run(command, capture_output=True, text=True,
                                               timeout=args.duration + args.warmup + 20)
                    if completed.returncode != 0:
                        raise RuntimeError(f'Client failed: {completed.stdout}\n{completed.stderr}')
                    result = json.loads(completed.stdout)
                    after = server_usage(server.pid)
                    result['server_process'] = {
                        'rss_bytes_after_trial': after['rss_bytes'],
                        'cpu_seconds_including_warmup_and_handshake': after['cpu_seconds'] - before['cpu_seconds'],
                        'scope': 'server process only; workers=1 measures the serving process',
                    } if args.workers == 1 else None
                    result['trial'] = trial
                    document['results'].append(result)
                    output_path.write_text(json.dumps(document, indent=2) + '\n')
                    print(f'{args.label}: {payload} bytes C{concurrency} trial {trial}: '
                          f'{result["messages_per_second"]:.0f}/s '
                          f'p99={result["latency_ms"]["p99"]:.3f}ms errors={result["errors"]}', flush=True)
    finally:
        os.killpg(server.pid, signal.SIGTERM) if server.poll() is None else None
        try:
            server.wait(timeout=10)
        except subprocess.TimeoutExpired:
            os.killpg(server.pid, signal.SIGKILL)
            server.wait(timeout=5)
document['server_exit_code'] = server.returncode
output_path.write_text(json.dumps(document, indent=2) + '\n')
fcntl.flock(benchmark_lock, fcntl.LOCK_UN)
benchmark_lock.close()
print(output_path)
