#!/usr/bin/env python3
"""Build a bounded code-only overlay or verify it. Never installs or switches releases."""
import argparse,gzip,hashlib,io,json,os,subprocess,tarfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
SERVING='/var/www/html/vr/calltrack-v3-20261026'
FILES=['api/client_catalog.php','api/client_catalog_lookup.php','api/client_directory.php','api/client_events.php',
       'api/client_identity_index.php','api/client_purchases.php','api/clients_cache_refresh.php',
       'api/sales_buyer_catalog.php','api/max_message_history.php',
       'scripts/build_client_identity_index.php','scripts/firstvds_clients_refresh.sh']
def sha(data):return hashlib.sha256(data).hexdigest()
def git(*args):return subprocess.check_output(['git',*args],cwd=ROOT)
def manifest():
    git('diff','--exit-code','HEAD','--')
    observed=json.loads((ROOT/'deploy/firstvds/observed-files.json').read_text())
    payload={p:git('show','HEAD:'+p) for p in FILES}
    return {'version':1,'commit':git('rev-parse','HEAD').decode().strip(),'serving':SERVING,
            'storage':'/var/www/html/vr/calltrack/storage','baseline':observed['files'],
            'payload':{p:sha(v) for p,v in payload.items()},
            'never_replace':['config.php','config.local.php','.env','storage','database','app','analizmop','api/add_max_messages.php','api/max_messages_service.php']},payload
def check(bundle,serving,phase):
    m=json.loads((bundle/'manifest.json').read_text())
    if m.get('version')!=1 or set(m['payload'])!=set(FILES):raise RuntimeError('Unsupported package')
    expected=set(json.loads((bundle/'observed-files.json').read_text())['files'])
    if m['baseline']!=json.loads((bundle/'observed-files.json').read_text())['files']:raise RuntimeError('Incomplete baseline')
    if any(not p.startswith(('api/','analizmop/')) or '..' in Path(p).parts for p in expected):raise RuntimeError('Unsafe baseline path')
    for p,h in m['payload'].items():
        if sha((bundle/'payload'/p).read_bytes())!=h:raise RuntimeError('Corrupt payload: '+p)
    errors=[]
    for p,h in m['baseline'].items():
        wanted=m['payload'].get(p,h) if phase=='installed' else h
        f=serving/p
        if not f.is_file() or sha(f.read_bytes())!=wanted:errors.append(p)
    for p,h in m['payload'].items():
        if p in m['baseline']:continue
        f=serving/p
        if phase=='baseline':
            if f.exists() or f.is_symlink():errors.append(p+' already exists')
        elif not f.is_file() or sha(f.read_bytes())!=h:errors.append(p)
    if errors:raise RuntimeError('NO-GO: file set differs: '+', '.join(errors))
    print(json.dumps({'status':'code-files-match','phase':phase,'commit':m['commit'],
                      'deployment_authorized':False,'note':'Does not verify nginx, FPM, live API, permissions or source freshness'}))
def main():
    a=argparse.ArgumentParser(description=__doc__);a.add_argument('mode',choices=['plan','build','check'],nargs='?',default='plan')
    a.add_argument('--output',type=Path);a.add_argument('--bundle',type=Path);a.add_argument('--serving',type=Path,default=Path(SERVING))
    a.add_argument('--phase',choices=['baseline','installed'],default='baseline');args=a.parse_args()
    if args.mode=='check':
        if not args.bundle or not args.bundle.is_absolute() or not args.serving.is_absolute():a.error('Absolute --bundle and --serving required')
        check(args.bundle,args.serving,args.phase);return
    m,payload=manifest()
    if args.mode=='plan':print(json.dumps(m,ensure_ascii=False,indent=2));return
    out=args.output
    if out is None or not out.is_absolute() or out.exists():a.error('New absolute --output required')
    entries={'manifest.json':json.dumps(m,ensure_ascii=False,indent=2).encode(),
             'observed-files.json':(ROOT/'deploy/firstvds/observed-files.json').read_bytes(),
             'verify.py':Path(__file__).read_bytes(),
             **{'payload/'+p:b for p,b in payload.items()}}
    # Exclusive output; only tracked, allowlisted code. No credentials, databases or cache.
    with out.open('xb') as raw:
        with gzip.GzipFile(fileobj=raw,mode='wb',mtime=0,filename='') as gz:
            with tarfile.open(fileobj=gz,mode='w') as tar:
                for name,data in sorted(entries.items()):
                    info=tarfile.TarInfo(name);info.size=len(data);info.mode=0o644;info.mtime=0
                    tar.addfile(info,io.BytesIO(data))
    print(json.dumps({'artifact':str(out),'sha256':sha(out.read_bytes()),'commit':m['commit'],'bytes':out.stat().st_size}))
if __name__=='__main__':
    try:main()
    except (RuntimeError,OSError,ValueError,subprocess.CalledProcessError) as e:raise SystemExit(str(e))
