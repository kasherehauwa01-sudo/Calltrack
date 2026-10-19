"""Local fixture only: real-format phone through authenticated API and actual browser UI."""
import json
from datetime import datetime,timezone
from playwright.sync_api import sync_playwright,expect
from max_message_history_http_test import Client,check
expected={'date':'2026-10-19','incoming_count':0,'outgoing_count':1,'last_event_at':'2026-10-18T21:30:00Z'}
for name in ['one','admin','boss']:
    code,data=Client(name).request('client_max_history.php',{'call_id':90})
    check(code==200 and data['data']==[expected],name+' real-format matching + owner scope + timezone')
check(Client('two').request('client_max_history.php',{'call_id':90})[0]==403,'foreign owner cannot access real-phone context')
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
    for zone in ['UTC','America/Los_Angeles']:
        page=browser.new_page(locale='ru-RU',timezone_id=zone)
        page.set_default_timeout(60000)
        page.set_default_navigation_timeout(120000)
        page.clock.set_fixed_time(datetime(2026,10,19,12,tzinfo=timezone.utc))
        page.route('https://cdn.jsdelivr.net/npm/chart.js',lambda r:r.fulfill(path='/workspace/.calltrack-tools/chart.umd.min.js',content_type='application/javascript'))
        requests=[]
        page.on('request',lambda r:requests.append(r.post_data_json) if '/api/client_max_history.php' in r.url else None)
        page.goto('http://127.0.0.1:8090/vr/calltrack/analizmop/')
        page.locator('#authLogin').fill('one@example.test');page.locator('#authPin').fill('12345678');page.locator('#authForm button').click()
        expect(page.locator('#appShell')).to_be_visible();page.locator('[data-tab=clientCalls]').click()
        row=page.locator('.client-row').filter(has=page.get_by_role('button',name='Инесса Кораблева',exact=True))
        expect(row).to_contain_text('Переписка MAX',timeout=15000)
        expect(row).to_contain_text('Входящие: 0');expect(row).to_contain_text('Исходящие: 1')
        expect(row).to_contain_text('19.10.2026');expect(row).to_contain_text('00:30')
        page.get_by_role('button',name='Инесса Кораблева',exact=True).click()
        expect(page.locator('#clientHistoryList')).to_contain_text('Исходящие: 1')
        assert len([r for r in requests if str(r.get('call_id'))=='90'])==1,requests
        page.locator('#clientHistoryCloseBtn').click()
        for failure in [401,403,500,'network','timeout',200]:
            page.unroute('**/api/client_max_history.php')
            def fail(route,request,failure=failure):
                if failure in ['network','timeout']:route.abort('timedout' if failure=='timeout' else 'failed')
                else:route.fulfill(status=failure,content_type='application/json',body=json.dumps({'status':'success','data':[]} if failure==200 else {'status':'error','message':'fixture'}))
            page.route('**/api/client_max_history.php',fail)
            before=len(requests)
            page.evaluate('maxHistoryCache.clear();renderClientCalls()')
            if failure!=200:expect(page.locator('#clientCallsList')).to_contain_text('История MAX временно недоступна',timeout=15000)
            else:expect(page.locator('#clientCallsList').get_by_text('Переписка MAX',exact=True)).to_have_count(0)
            expect(page.locator('#clientCallsList [data-index]')).not_to_have_count(0)
            expect(page.locator('#clientCallsList [data-email-id]')).not_to_have_count(0)
            expect(page.locator('#clientCallsList [data-sale-id], #clientCallsList [data-sale-group]')).not_to_have_count(0,timeout=15000)
            page.wait_for_timeout(150)
            assert len(requests)-before==2,(failure,requests[before:]) # exactly one per two directory clients
        page.close()
    browser.close()
print('real phone API -> timeline/modal, UTC boundary, owner isolation, HTTP failures/empty/network/timeout, request counts: OK')
