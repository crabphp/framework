"""Remove only recorded, detached anonymous volumes from this run's containers."""
from pathlib import Path
import json,os,re,subprocess
root=Path(os.environ['BENCH_RESULTS']);project=os.environ['COMPOSE_PROJECT_NAME'];volumes=set()
for path in root.glob('*-inspect.json'):
    data=json.loads(path.read_text())
    if data.get('Config',{}).get('Labels',{}).get('com.docker.compose.project')!=project:
        continue
    for mount in data.get('Mounts',[]):
        name=mount.get('Name','')
        if mount['Type']=='volume' and re.fullmatch('[a-f0-9]{64}',name):volumes.add(name)
for name in sorted(volumes):
    if subprocess.run(['docker','volume','inspect',name],capture_output=True).returncode:
        continue
    attached=subprocess.check_output(['docker','ps','-aq','--filter','volume='+name]).decode().strip()
    if attached:
        print('Retained attached volume:',name)
    else:
        subprocess.run(['docker','volume','rm',name],check=True)
