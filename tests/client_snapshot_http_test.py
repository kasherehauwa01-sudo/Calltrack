"""Use only a fixture server with CALLTRACK_TEST_INSIGHTS_MODE_FILE configured."""
import http.cookiejar
import json
import os
from pathlib import Path
import urllib.request

mode_file=Path(os.environ['CALLTRACK_TEST_INSIGHTS_MODE_FILE'])
base=os.environ.get('CALLTRACK_TEST_URL','http://127.0.0.1:8090/vr/calltrack/api/')
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(body):
    return json.load(opener.open(urllib.request.Request(base+'client_purchases.php',data=json.dumps(body).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':csrf})))
login=json.load(opener.open(urllib.request.Request(base+'web_auth_api.php?action=login',data=json.dumps({'login':'one@example.test','pin':'12345678'}).encode(),headers={'Content-Type':'application/json'})))
csrf=login['csrf_token']
try:
    for mode in ['http503','malformed','missing']:
        mode_file.write_text(mode)
        data=request({'call_id':4,'action':'summary'})
        assert data['status']=='success',data
        assert data['summary']['revenue']=='47.02',data
        assert data['summary'].get('rfm') is None,data
        if mode=='missing':
            assert data['summary']['rfm_status']=='snapshot_missing',data
            assert data['details_error'] is None,data
        else:
            assert data['details_error'],data
        purchases=request({'call_id':4,'from':'2025-11','to':'2026-10'})
        assert len(purchases['tree'])==3,purchases
        assert purchases['analytics']['current_total']=='41.00',purchases
finally:
    mode_file.unlink(missing_ok=True)
print('client_snapshot_http_test: upstream 503/malformed/missing RFM preserve summary and purchases OK')
