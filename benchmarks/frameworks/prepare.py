from pathlib import Path
import os, json, subprocess, tarfile, io, shutil, hashlib
root=Path(__file__).resolve().parent
scratch=Path(os.environ['BENCH_SCRATCH']);context=scratch/'context'
context.mkdir(parents=True,exist_ok=True)
for item in root.iterdir():
    if item.name in ['results','__pycache__']:continue
    if item.is_dir():shutil.copytree(item,context/item.name,dirs_exist_ok=True,ignore=shutil.ignore_patterns("__pycache__"))
    else:shutil.copy2(item,context/item.name)
commits=json.loads((root/'source-commits.json').read_text())
for name,repo in [('framework',root.parents[1]),('crab',root.parents[2]/'crab')]:
    dest=context/(name+'-source');dest.mkdir(exist_ok=True)
    archive=subprocess.check_output(['git','archive',commits['skeleton_commit' if name=='crab' else 'framework_commit']],cwd=repo)
    with tarfile.open(fileobj=io.BytesIO(archive))as tf:tf.extractall(dest,filter='data')
manifest={str(f.relative_to(context)):hashlib.sha256(f.read_bytes()).hexdigest()for f in context.rglob('*')if f.is_file()}
(scratch/'source-manifest.json').write_text(json.dumps(manifest,indent=2))
print('Prepared '+str(context),flush=True)
