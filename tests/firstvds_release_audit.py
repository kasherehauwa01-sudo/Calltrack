"""Offline Git/production-hash audit. No server, API or database connections."""
import hashlib,json,subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1]
observed=json.loads((root/'deploy/firstvds/observed-files.json').read_text())
refs={'main':'a4a02cf4716b37e03bb9d0ff221745127c2c4807','production':'715be8462deaf270c0729310abab25961f91772a','server_git':'cfbab96539bbcadcf1963991f77c10ccc78ce5a8'}
def blob(ref,path):
    p=subprocess.run(['git','show',ref+':'+path],cwd=root,capture_output=True)
    return p.stdout if p.returncode==0 else None
def digest(data):return hashlib.sha256(data).hexdigest() if data is not None else None
rows=[]
for path,expected in observed['files'].items():
    row={'path':path,'observed':expected,'matches':[name for name,ref in refs.items() if digest(blob(ref,path))==expected],
         'release_sha256':digest((root/path).read_bytes())}
    row['install_change']=row['release_sha256']!=expected
    rows.append(row)
    print(json.dumps(row,ensure_ascii=False))
# These production-only commit paths already exist byte-for-byte in main/release.
for path in ['api/add_max_messages.php','api/max_messages_service.php','database/20261016_create_max_messages.sql','docs/max_message_sync.md','tests/max_messages_db_test.php','tests/max_messages_sync_test.php']:
    assert blob(refs['production'],path)==blob(refs['main'],path)==(root/path).read_bytes(),path
# No Android or database change in the release on top of current main.
for directory in ['app','database']:
    result=subprocess.check_output(['git','diff','--name-only',refs['main'],'HEAD','--',directory],cwd=root,text=True)
    assert not result,result
print('firstvds_release_audit: MAX exact-byte preservation; Android/schema unchanged; observed hash matrix emitted')
