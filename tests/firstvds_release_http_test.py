"""Only disposable local fixture storage; no production endpoints."""
import hashlib,json,os,subprocess
from pathlib import Path
from max_message_history_http_test import Client,check
assert os.environ.get('CALLTRACK_TEST_FIXTURES')=='1'
storage=Path(os.environ['CALLTRACK_STORAGE_DIR'])
assert str(storage)=='/tmp/firstvds-http-storage'
cache=storage/'cache/clients';idx=cache/'identity-v1'
one=Client('one')
def unavailable():
    for endpoint in ['client_purchases.php','client_events.php','client_max_history.php']:
        code,body=one.request(endpoint,{'client_id':'catalog-a','action':'context'})
        check(code==503 and body['status']=='error','fail closed '+endpoint)
manifest=idx/'current.json';saved=manifest.read_bytes()
try:
    manifest.unlink();unavailable()
finally:manifest.write_bytes(saved)
try:
    manifest.write_text('{');unavailable()
finally:manifest.write_bytes(saved)
metadata=json.loads(saved);bucket=idx/metadata['generation']/('records-'+hashlib.sha256(b'catalog-a').hexdigest()[:2]+'.json')
original=bucket.read_bytes()
try:
    bucket.write_text('{}');unavailable()
finally:bucket.write_bytes(original)
try:
    data=json.loads(saved);data['version']=999;manifest.write_text(json.dumps(data));unavailable()
finally:manifest.write_bytes(saved)
try:
    data=json.loads(saved);data['source']='stale';manifest.write_text(json.dumps(data));unavailable()
finally:manifest.write_bytes(saved)
code,data=one.request('client_events.php',{'client_id':'catalog-a'})
check(code==200 and len(data['calls'])==1 and len(data['emails'])==1,'events recovered unchanged')
code,data=one.request('client_purchases.php',{'client_id':'catalog-a','action':'analytics','from':'2026-10','to':'2026-10'})
check(code==200 and data.get('analytics',{}).get('current_total')=='20.00','purchase total with integrated source contract')
print('firstvds_release_http: missing/corrupt/stale/version index -> 503; calls/email recovery; exact purchase total OK')
