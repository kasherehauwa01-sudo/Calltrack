"""Local browser fixture: canonical Email manager vs historical call manager spelling."""
from datetime import datetime,timezone
from playwright.sync_api import sync_playwright,expect

with sync_playwright() as p:
    browser=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
    page=browser.new_page(locale='ru-RU')
    page.clock.set_fixed_time(datetime(2026,10,19,12,tzinfo=timezone.utc))
    page.route('https://cdn.jsdelivr.net/npm/chart.js',lambda r:r.fulfill(path='/workspace/.calltrack-tools/chart.umd.min.js',content_type='application/javascript'))
    def calls(route):
        data=route.fetch().json()
        for row in data.get('data',[]):
            if row.get('manager')=='One':row['manager']='Сергеевна Пашута Марина'
        for row in data.get('managers',[]):
            if row.get('display_name')=='One':row['display_name']='Пашута Марина Сергеевна'
        route.fulfill(json=data)
    def emails(route):
        data=route.fetch().json()
        for row in data.get('data',[]):
            if row.get('manager_name')=='One':row['manager_name']='Пашута Марина Сергеевна'
        route.fulfill(json=data)
    page.route('**/api/get_calls.php*',calls)
    page.route('**/api/admin_email.php*',emails)
    page.goto('http://127.0.0.1:8090/vr/calltrack/analizmop/')
    page.locator('#authLogin').fill('admin@example.test');page.locator('#authPin').fill('12345678');page.locator('#authForm button').click()
    expect(page.locator('#appShell')).to_be_visible();page.locator('[data-tab=clientCalls]').click()
    page.locator('#clientManagerTags [data-manager="Пашута Марина Сергеевна"]').click()
    expect(page.locator('#clientManagerTags [data-manager="Сергеевна Пашута Марина"]')).to_have_count(0)
    expect(page.locator('#clientCallsList [data-email-id="10"]')).to_be_visible(timeout=15000)
    expect(page.locator('#clientCallsList [data-index]')).not_to_have_count(0)
    expect(page.locator('#clientCallsList [data-email-id="11"]')).to_have_count(0)
    page.locator('#clientManagerTags [data-manager="Two"]').click()
    expect(page.locator('#clientCallsList [data-email-id="11"]')).to_be_visible()
    expect(page.locator('#clientCallsList [data-email-id="10"]')).to_have_count(0)
    page.close();browser.close()
print('Email timeline browser: historical/canonical name merge, visible outgoing email, no duplicate manager, manager isolation OK')
