"""Run the pinned fixtures, retaining every trial and cleaning up through run.sh."""
from pathlib import Path
import datetime as dt
import http.client
import json
import os
import platform
import socket
import statistics
import subprocess
import threading
import time
import urllib.request

ROOT = Path(__file__).resolve().parent
OUT = Path(os.environ['BENCH_RESULTS'])
OUT.mkdir(parents=True, exist_ok=True)
SERVICES = ['crab-off', 'crab-jit', 'octane', 'symfony', 'fpm', 'elysia', 'webman', 'hyperf', 'spiral']
PORTS = dict(zip(SERVICES, range(23101, 23110)))
if os.environ.get('BENCH_SERVICES'):
    SERVICES = [name for name in SERVICES if name in os.environ['BENCH_SERVICES'].split(',')]
WARM = int(os.environ.get('BENCH_WARMUP', '15'))
DURATION = int(os.environ.get('BENCH_DURATION', '30'))
TRIALS = int(os.environ.get('BENCH_TRIALS', '5'))
CASES = [('hello', 64), ('hello', 256), ('db', 64), ('validate', 64)]
if os.environ.get('BENCH_CASES'):
    CASES = [case for case in CASES if case[0] in os.environ['BENCH_CASES'].split(',')]


def command(args, timeout=180, check=True):
    result = subprocess.run(args, cwd=ROOT, capture_output=True, text=True, timeout=timeout)
    if check and result.returncode:
        raise RuntimeError(' '.join(args) + '\n' + result.stdout + result.stderr)
    return result


def compose(*args, **kwargs):
    return command(['docker', 'compose', *args], **kwargs)


def container(service):
    value = compose('ps', '-q', service).stdout.strip()
    if not value:
        raise RuntimeError('No running container for ' + service)
    return value


def save(name, data):
    (OUT / name).write_text(json.dumps(data, indent=2) + '\n')


class DockerHTTP(http.client.HTTPConnection):
    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(5)
        self.sock.connect(DOCKER_SOCKET)


def stats(cid):
    conn = DockerHTTP('localhost')
    try:
        conn.request('GET', '/containers/' + cid + '/stats?stream=false&one-shot=true')
        response = conn.getresponse()
        if response.status != 200:
            raise RuntimeError('Docker statistics failed: ' + response.read().decode())
        data = json.loads(response.read())
        return {'cpu_ns': data['cpu_stats']['cpu_usage']['total_usage'],
                'memory_bytes': data['memory_stats'].get('usage', 0),
                'memory_limit': data['memory_stats'].get('limit', 0)}
    finally:
        conn.close()


def wait_ready(service):
    deadline = time.monotonic() + 120
    while time.monotonic() < deadline:
        try:
            with urllib.request.urlopen('http://127.0.0.1:' + str(PORTS[service]) + '/hello', timeout=2) as response:
                if response.status == 200 and json.load(response) == {'message': 'Hello, world'}:
                    return
        except Exception:
            pass
        time.sleep(.2)
    raise RuntimeError('Readiness failed for ' + service + '\n' + compose('logs', service).stdout)


def exercise(service, kind, connections, seconds, label, sample=False):
    args = ['docker', 'exec', IDS['loadgen'], 'wrk', '-t4', '-c' + str(connections),
            '-d' + str(seconds) + 's', '--timeout', '5s', '--latency',
            '-s', '/bench/workload.lua', 'http://' + service + ':8080', '--', kind]
    observations = []
    stop = threading.Event()
    failures = []

    def observe():
        while not stop.is_set():
            try:
                observations.append({'time': time.monotonic(), **{name: stats(IDS[name]) for name in [service, 'mysql', 'loadgen']}})
            except Exception as error:
                failures.append(str(error))
            stop.wait(.5)

    watcher = threading.Thread(target=observe)
    if sample:
        watcher.start()
    try:
        result = command(args, timeout=seconds + 60)
    finally:
        stop.set()
        if sample:
            watcher.join()
    (OUT / (label + '.log')).write_text(result.stdout + result.stderr)
    lines = [line.removeprefix('BENCH_JSON ') for line in result.stdout.splitlines() if line.startswith('BENCH_JSON ')]
    if len(lines) != 1:
        raise RuntimeError('Missing wrk result in ' + label)
    record = json.loads(lines[0])
    assert record['workload'] == kind, record
    record['command'] = args
    record['kind'] = kind
    record['connections'] = connections
    record['service'] = service
    record['error_count'] = record['bad_response'] + sum(record['errors'][key] for key in ['connect', 'read', 'write', 'timeout'])
    if sample:
        if failures or len(observations) < 2:
            raise RuntimeError('Missing resource observations: ' + repr(failures))
        record['resources'] = {}
        for name in [service, 'mysql', 'loadgen']:
            start, end = observations[0], observations[-1]
            record['resources'][name] = {
                'average_cpu_percent': (end[name]['cpu_ns'] - start[name]['cpu_ns']) / 1e9 / (end['time'] - start['time']) * 100,
                'peak_sampled_memory_bytes': max(row[name]['memory_bytes'] for row in observations),
            }
        record['container_peak_bytes'] = int(command(['docker', 'exec', IDS[service], 'cat', '/sys/fs/cgroup/memory.peak']).stdout)
        record['container_current_bytes'] = int(command(['docker', 'exec', IDS[service], 'cat', '/sys/fs/cgroup/memory.current']).stdout)
        save(label + '-resources.json', observations)
    return record


context = json.loads(command(['docker', 'context', 'inspect']).stdout)[0]
DOCKER_SOCKET = context['Endpoints']['docker']['Host'].removeprefix('unix://')
info = json.loads(command(['docker', 'info', '--format', '{{json .}}']).stdout)
if info['NCPU'] < 8:
    raise RuntimeError('This pinned CPU layout needs at least eight OrbStack guest CPUs.')
metadata = {'started_utc': dt.datetime.now(dt.timezone.utc).isoformat(), 'host': platform.platform(),
            'docker': {key: info[key] for key in ['NCPU', 'MemTotal', 'KernelVersion', 'ServerVersion', 'Architecture', 'OperatingSystem']},
            'orbstack': command(['orbctl', 'version']).stdout,
            'orbstack_settings': command(['orbctl', 'config', 'show']).stdout,
            'source_commits': json.loads((ROOT / 'source-commits.json').read_text()),
            'warmup_seconds': WARM, 'duration_seconds': DURATION, 'trials': TRIALS,
            'full_method': WARM == 15 and DURATION == 30 and TRIALS == 5 and len(CASES) == 4 and len(SERVICES) == 9,
            'images': (ROOT / 'images.env').read_text(),
            'excluded': json.loads((ROOT / 'exclusions.json').read_text())}
save('metadata.json', metadata)
compose('up', '-d', 'mysql', 'loadgen')
IDS = {name: container(name) for name in ['mysql', 'loadgen']}
infrastructure = {name: json.loads(command(['docker', 'inspect', cid]).stdout)[0] for name, cid in IDS.items()}
for name, cpus, cpuset, memory in [('mysql', 2, '2,3', 2 * 1024**3), ('loadgen', 4, '4,5,6,7', 512 * 1024**2)]:
    limits = infrastructure[name]['HostConfig']
    assert limits['NanoCpus'] == cpus * 1_000_000_000 and limits['CpusetCpus'] == cpuset
    assert limits['Memory'] == memory and limits['MemorySwap'] == memory
save('infrastructure-inspect.json', infrastructure)
for attempt in range(120):
    health = json.loads(command(['docker', 'inspect', IDS['mysql']]).stdout)[0].get('State', {}).get('Health', {}).get('Status')
    if health == 'healthy':
        break
    time.sleep(1)
else:
    raise RuntimeError('MySQL did not become healthy.')
identities = {}
for user, password, transport in [('root', 'benchroot', ['--protocol=SOCKET']), ('bench', 'bench', ['--protocol=TCP', '-h127.0.0.1', '-P23306'])]:
    identities[user] = command(['docker', 'exec', IDS['mysql'], 'mysql', *transport, '-u' + user, '-p' + password, '-N', '-B', 'benchmark', '-e', 'SELECT CURRENT_USER(), DATABASE()']).stdout.strip()
assert identities == {'root': 'root@localhost\tbenchmark', 'bench': 'bench@%\tbenchmark'}, identities
save('fixture-accounts-verified.json', identities)
versions = {'mysql': command(['docker', 'exec', IDS['mysql'], 'mysqld', '--version']).stdout,
            'wrk': command(['docker', 'exec', IDS['loadgen'], 'wrk', '--version'], check=False).stdout,
            'loadgen_packages': command(['docker', 'exec', IDS['loadgen'], 'dpkg-query', '-W', 'wrk']).stdout}
records, warmups, startup, checks = [], [], [], []
for kind, connections in CASES:
    case = kind + '-' + str(connections)
    compose('rm', '--stop', '--force', '--volumes', *SERVICES)
    for service in SERVICES:
        start = time.monotonic()
        compose('up', '-d', '--no-deps', service)
        IDS[service] = container(service)
        wait_ready(service)
        startup.append({'case': case, 'service': service, 'ready_seconds': time.monotonic() - start})
        checked = command(['docker', 'exec', IDS['loadgen'], 'python3', '-I', '/bench/check.py', service])
        checks.append({'case': case, 'service': service, **json.loads(checked.stdout)})
        jit = (checks[-1]['metadata'].get('jit') or {}).get('on', False)
        if service.startswith('crab'):
            assert jit == (service == 'crab-jit'), checks[-1]
        actual = json.loads(command(['docker', 'inspect', IDS[service]]).stdout)[0]
        assert actual['HostConfig']['NanoCpus'] == 2_000_000_000
        assert actual['HostConfig']['CpusetCpus'] == '0,1'
        assert actual['HostConfig']['Memory'] == 2 * 1024**3
        assert actual['HostConfig']['MemorySwap'] == 2 * 1024**3
        save(case + '-' + service + '-inspect.json', actual)
        if service == 'elysia' and service not in versions:
            versions[service] = {'bun': command(['docker', 'exec', IDS[service], 'bun', '--version']).stdout,
                                 'metadata': checks[-1]['metadata'],
                                 'image': json.loads(command(['docker', 'image', 'inspect', actual['Image']]).stdout)[0],
                                 'processes': command(['docker', 'top', IDS[service], '-eo', 'pid,ppid,args']).stdout}
            save('versions.json', versions)
        if service not in versions:
            app = '/srv/crab' if service.startswith('crab') else '/srv/app'
            php = 'require "' + app + '/vendor/autoload.php"; $out=[]; foreach(Composer\\InstalledVersions::getInstalledPackages() as $p) {$out[$p]=[Composer\\InstalledVersions::getPrettyVersion($p),Composer\\InstalledVersions::getReference($p)];} echo json_encode($out);'
            versions[service] = {'php': command(['docker', 'exec', IDS[service], 'php', '-v']).stdout,
                                 'packages': json.loads(command(['docker', 'exec', IDS[service], 'php', '-r', php]).stdout),
                                 'metadata': checks[-1]['metadata'],
                                 'image': json.loads(command(['docker', 'image', 'inspect', actual['Image']]).stdout)[0],
                                 'processes': command(['docker', 'top', IDS[service], '-eo', 'pid,ppid,args']).stdout}
            if service in ['symfony', 'octane']:
                versions[service]['frankenphp'] = command(['docker', 'exec', IDS[service], 'frankenphp', 'version']).stdout
            if service == 'spiral':
                versions[service]['roadrunner'] = command(['docker', 'exec', IDS[service], 'rr', '--version']).stdout
            if service == 'fpm':
                q = command(['docker', 'exec', IDS[service], 'nginx', '-v'])
                versions[service]['nginx'] = q.stdout + q.stderr
            save('versions.json', versions)
    save('preflight.json', checks)
    save('startup.json', startup)
    for trial in range(TRIALS):
        order = SERVICES[trial % len(SERVICES):] + SERVICES[:trial % len(SERVICES)]
        for service in order:
            if trial == 0:
                warmups.append(exercise(service, kind, connections, WARM, case + '-' + service + '-warmup'))
                save('warmups.json', warmups)
            label = case + '-' + service + '-trial-' + str(trial + 1)
            print('RUN ' + label + ' ' + dt.datetime.now(dt.timezone.utc).isoformat(), flush=True)
            record = exercise(service, kind, connections, DURATION, label, sample=True)
            record['trial'] = trial + 1
            with urllib.request.urlopen('http://127.0.0.1:' + str(PORTS[service]) + '/__bench', timeout=10) as response:
                record['runtime_after'] = json.load(response)
            records.append(record)
            save('trials.json', records)
            print('DONE ' + label + ': ' + str(round(record['rps'])) + ' req/s; errors=' + str(record['error_count']), flush=True)
    for service in SERVICES:
        (OUT / (case + '-' + service + '-server.log')).write_text(compose('logs', '--no-color', service).stdout)
summary = []
for kind, connections in CASES:
    for service in SERVICES:
        rows = [row for row in records if row['kind'] == kind and row['connections'] == connections and row['service'] == service]
        summary.append({'case': kind, 'connections': connections, 'service': service, 'runs': len(rows),
                        'rps': statistics.median(row['rps'] for row in rows),
                        'rps_min': min(row['rps'] for row in rows), 'rps_max': max(row['rps'] for row in rows),
                        'p50_ms': statistics.median(row['p50_us'] for row in rows) / 1000,
                        'p99_ms': statistics.median(row['p99_us'] for row in rows) / 1000,
                        'errors': sum(row['error_count'] for row in rows),
                        'peak_mib': max(row['container_peak_bytes'] for row in rows) / 1024**2,
                        'app_cpu_percent': statistics.median(row['resources'][service]['average_cpu_percent'] for row in rows),
                        'db_cpu_percent': statistics.median(row['resources']['mysql']['average_cpu_percent'] for row in rows),
                        'generator_cpu_percent': statistics.median(row['resources']['loadgen']['average_cpu_percent'] for row in rows)})
save('summary.json', summary)
lines = ['| Case / connections | Contender | Requests/s, median [range] | p50, ms | p99, ms | Errors | Peak MiB |', '| --- | --- | ---: | ---: | ---: | ---: | ---: |']
for row in summary:
    lines.append(f"| {row['case']} / {row['connections']} | {row['service']} | {row['rps']:,.0f} [{row['rps_min']:,.0f}–{row['rps_max']:,.0f}] | {row['p50_ms']:.3f} | {row['p99_ms']:.3f} | {row['errors']} | {row['peak_mib']:.1f} |")
(OUT / 'summary.md').write_text('\n'.join(lines) + '\n')
print('COMPLETE ' + str(OUT), flush=True)
