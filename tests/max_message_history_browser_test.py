"""Run only with tests/fixtures/max_history_seed.php in a disposable local DB."""
import os
from pathlib import Path
from datetime import datetime,timezone
from playwright.sync_api import sync_playwright,expect
base=os.environ.get('MAX_HISTORY_TEST_PAGE','http://127.0.0.1:8090/vr/calltrack/analizmop/')
assert base.startswith('http://127.0.0.1:')
chart=os.environ.get('CALLTRACK_TEST_CHART','/workspace/.calltrack-tools/chart.umd.min.js')
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
    for zone in ['UTC','America/Los_Angeles']:
        page=browser.new_page(locale='ru-RU',timezone_id=zone,viewport={'width':1366,'height':900})
        page.clock.set_fixed_time(datetime(2026,10,19,12,tzinfo=timezone.utc))
        page.route('https://cdn.jsdelivr.net/npm/chart.js',lambda route:route.fulfill(path=chart,content_type='application/javascript'))
        page.goto(base)
        page.locator('#authLogin').fill('one@example.test');page.locator('#authPin').fill('12345678');page.locator('#authForm button').click()
        expect(page.locator('#appShell')).to_be_visible()
        page.locator('[data-tab=clientCalls]').click()
        timeline=page.locator('#clientCallsList')
        expect(timeline.get_by_text('Переписка MAX',exact=True)).to_have_count(1,timeout=15000)
        card=timeline.locator('article.call-segment').filter(has_text='Переписка MAX')
        expect(card).to_contain_text('Входящие: 6');expect(card).to_contain_text('Исходящие: 4')
        expect(card).to_contain_text('18.10.2026');expect(card).to_contain_text('00:40')
        expect(timeline.locator('[data-index]')).not_to_have_count(0)
        expect(timeline.locator('[data-email-id]')).not_to_have_count(0)
        expect(timeline.locator('[data-sale-id], [data-sale-group]')).not_to_have_count(0,timeout=15000)
        page.get_by_role('button',name='Покупатель',exact=True).click()
        history=page.locator('#clientHistoryList')
        expect(history.get_by_text('Переписка MAX',exact=True)).to_have_count(1,timeout=15000)
        expect(history).to_contain_text('Входящие: 6');expect(history).to_contain_text('Исходящие: 4')
        expect(history).to_contain_text('Test note')
        expect(page.locator('#clientSummaryKpis')).to_contain_text('47,02',timeout=15000)
        for key in ['source_event_id','max_user_id','shortcut_id','contact_name']:
            assert key not in history.inner_text()
        if zone=='UTC':page.screenshot(path='/workspace/.calltrack-tools/max-history-review.png',full_page=True)
        page.locator('#clientHistoryCloseBtn').click()
        page.route('**/api/client_max_history.php',lambda route:route.abort())
        page.evaluate('maxHistoryCache.clear();renderClientCalls()')
        expect(timeline).to_contain_text('История MAX временно недоступна',timeout=15000)
        expect(timeline.locator('[data-index]')).not_to_have_count(0)
        expect(timeline.locator('[data-email-id]')).not_to_have_count(0)
        page.close()
    browser.close()
print('max_message_history_browser_test: timeline/modal, calls/email/sales, timezone, graceful failure OK')
