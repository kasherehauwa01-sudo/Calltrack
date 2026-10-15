"""RFM states remain local to the summary; run against the isolated workspace fixture."""
import json
import os
from playwright.sync_api import sync_playwright, expect

base=os.environ.get('CALLTRACK_TEST_PAGE','http://127.0.0.1:8090/vr/calltrack/analizmop/')
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
    page=browser.new_page(locale='ru-RU',viewport={'width':1366,'height':768})
    page.goto(base)
    page.locator('#authLogin').fill('one@example.test');page.locator('#authPin').fill('12345678');page.locator('#authForm button').click()
    expect(page.locator('#appShell')).to_be_visible()
    page.wait_for_function('allCalls.length > 0')
    mode='snapshot_missing'
    def summary(route):
        body=route.request.post_data_json
        if body.get('action')!='summary':
            route.continue_();return
        data=route.fetch().json()
        s=data['summary']
        for key in ['calculated_at','period_from','period_to','rfm_status']:
            s.pop(key,None)
        if mode in ['snapshot_missing','client_missing','unavailable']:
            s['rfm']=None;s['rfm_status']=mode
        elif mode=='stale':
            s['rfm_status']='stale'
        elif mode=='failure':
            for key in ['rfm','top_categories','unique_products']:
                s.pop(key,None)
            data['details_error']='Товарные показатели и RFM временно недоступны'
        route.fulfill(json=data)
    page.route('**/api/client_purchases.php',summary)
    for state in ['snapshot_missing','client_missing','stale','failure','legacy']:
        mode=state
        page.evaluate("openClientHistoryModal('Покупатель',{call_id:4})")
        expect(page.locator('#clientSummaryKpis')).to_contain_text('47,02 ₽')
        expect(page.locator('#clientHistoryList')).to_contain_text('Test note')
        if state in ['snapshot_missing','client_missing','failure']:
            expect(page.locator('#clientSummaryStatus')).to_contain_text('RFM пока недоступен')
        elif state=='stale':
            expect(page.locator('#clientSummaryStatus')).to_contain_text('RFM: данные устарели')
        else:
            expect(page.locator('#clientSummaryKpis')).to_contain_text('Постоянные активные')
        if state!='legacy':
            expect(page.locator('#clientSummaryRetry')).to_be_visible()
            page.locator('#clientSummaryRetry').click()
            expect(page.locator('#clientSummaryKpis')).to_contain_text('47,02 ₽')
        page.locator('#clientPurchasesTab').click()
        expect(page.locator('#purchaseTree > details')).to_have_count(3)
        page.keyboard.press('Escape')
        page.wait_for_function("!document.body.classList.contains('client-workspace-locked')")
    browser.close()
print('client_snapshot_browser_test: 5 RFM states, calls and purchases OK')
