#!/usr/bin/env python3
"""Compare HEAD engine bytes with the working tree on loopback, PHP 8.4 with JIT disabled.

Runs six alternating trials against the existing HTTP fixture. The Python load
client can bottleneck both variants; these trials detect gross regressions and
do not measure saturation. Run only on an otherwise idle development host.
No public network connections are made. Raw trials include errors and latency.
"""
import asyncio,subprocess,tempfile,pathlib,socket,time,json,statistics,platform,hashlib,fcntl
root=pathlib.Path(__file__).resolve().parents[1]
async def load(port,duration):
    latencies=[]; count=0; errors=[]
    end=time.monotonic()+duration
    async def client():
        nonlocal count
        try:
            r,w=await asyncio.open_connection('127.0.0.1',port)
            while time.monotonic()<end:
                start=time.perf_counter()
                w.write(b'GET / HTTP/1.1\r\nHost: localhost\r\n\r\n');await w.drain()
                head=await r.readuntil(b'\r\n\r\n')
                length=int(next(l.split(b':',1)[1] for l in head.split(b'\r\n') if l.lower().startswith(b'content-length:')))
                body=await r.readexactly(length)
                if body!=b'Hello from Crab\n':raise ValueError(body)
                count+=1;latencies.append((time.perf_counter()-start)*1000)
            w.close();await w.wait_closed()
        except Exception as e:errors.append(str(e))
    start=time.monotonic();await asyncio.gather(*(client() for _ in range(16)));elapsed=time.monotonic()-start
    latencies.sort()
    return dict(requests=count,elapsed=elapsed,rps=count/elapsed,p50_ms=latencies[len(latencies)//2],p99_ms=latencies[int(len(latencies)*.99)],errors=errors)
with open('/tmp/crab-engine-benchmark.lock', 'a') as lock, tempfile.TemporaryDirectory(prefix='memail-engine-baseline-') as directory:
    fcntl.flock(lock, fcntl.LOCK_EX)
    base=pathlib.Path(directory)
    for file in subprocess.check_output(['git','ls-files','src/Engine'],cwd=root,text=True).splitlines():
        target=base/file;target.parent.mkdir(parents=True,exist_ok=True)
        target.write_bytes(subprocess.check_output(['git','show','HEAD:'+file],cwd=root))
    result=dict(baseline_commit=subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),environment=dict(platform=platform.platform(),php=subprocess.check_output(['php84','-v'],text=True),jit='off',jit_buffer_size=0,opcache_enable_cli=True,clients=16,duration=2),source_hashes={str(p.relative_to(root)):hashlib.sha256(p.read_bytes()).hexdigest() for p in (root/'src/Engine').rglob('*.php')},trials=[])
    for variant in ['baseline','changed','changed','baseline','baseline','changed']:
        with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
        source=base/'src/Engine' if variant=='baseline' else root/'src/Engine'
        command=['php84','-d','opcache.enable_cli=1','-d','opcache.jit=off','-d','opcache.jit_buffer_size=0',str(root/'benchmarks/server.php'),'--port='+str(port),'--source='+str(source)]
        with tempfile.TemporaryFile() as log:
            proc=subprocess.Popen(command,stdout=log,stderr=log)
            try:
                deadline=time.monotonic()+5
                while True:
                    try:
                        with socket.create_connection(('127.0.0.1',port),.1):break
                    except OSError:
                        if time.monotonic()>deadline:raise RuntimeError('Server did not start')
                        time.sleep(.01)
                asyncio.run(load(port,.5))
                trial=asyncio.run(load(port,2));trial.update(variant=variant,command=command);result['trials'].append(trial)
            finally:
                proc.terminate();proc.wait(5)
                log.seek(0);result['trials'][-1]['server_log']=log.read().decode()
    result['median_rps']={v:statistics.median(t['rps'] for t in result['trials'] if t['variant']==v) for v in ['baseline','changed']}
    (root/'benchmarks/results/tls-transport.json').write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(dict(jit=result['environment']['jit'], jit_buffer_size=result['environment']['jit_buffer_size'], median_rps=result['median_rps'])))
