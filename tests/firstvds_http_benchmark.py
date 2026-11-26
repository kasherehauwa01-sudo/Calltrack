"""Full local HTTP handlers with 165k directory, local MariaDB and Sales Journal stub."""
import json,os,time
from max_message_history_http_test import Client,check
assert os.environ.get('CALLTRACK_TEST_FIXTURES')=='1'
assert os.environ.get('CALLTRACK_STORAGE_DIR')=='/tmp/firstvds-http-storage'
user=Client('one')
samples=[]
for n in range(10):
    start=time.perf_counter()
    for endpoint,payload in [
        ('client_purchases.php',{'client_id':'catalog-a','action':'analytics','from':'2026-10','to':'2026-10'}),
        ('client_events.php',{'client_id':'catalog-a'}),
        ('client_max_history.php',{'client_id':'catalog-a'}),
    ]:
        code,data=user.request(endpoint,payload)
        check(code==200,'large directory HTTP '+endpoint)
    samples.append(time.perf_counter()-start)
ordered=sorted(samples)
print(json.dumps({'scope':'Three actual local HTTP handlers sequentially; 165000+ clients; synthetic DB and local Sales Journal stub, warm OS cache; excludes browser rendering and real remote network',
                  'samples':len(samples),'p50_seconds':ordered[4],'p95_seconds':ordered[-1],'max_seconds':max(samples)},ensure_ascii=False))
check(max(samples)<2,'Local complete three-endpoint card budget <2 seconds')
