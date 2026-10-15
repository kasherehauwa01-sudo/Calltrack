"""Selector/race regression using controlled purchase responses and the local login fixture."""
import os
from datetime import datetime, timezone
from playwright.sync_api import sync_playwright, expect

base = os.environ.get('CALLTRACK_TEST_PAGE', 'http://127.0.0.1:8090/vr/calltrack/analizmop/')
chart = os.environ.get('CALLTRACK_TEST_CHART', '/workspace/task-audit/chart.umd.min.js')
candidates = [{'key': 'client:a', 'name': 'Альфа', 'id': 'a'}, {'key': 'client:b', 'name': 'Бета', 'id': 'b'}]
held = []
hold_a = False

def respond(route):
    data = route.request.post_data_json
    key = data.get('client_key', '')
    context = {'found_in_1c': True, 'phone': '9000000001', 'can_mark_personal': False,
               'candidates': candidates, 'selected_client_key': key, 'selection_required': not key}
    result = {'status': 'success', 'context': context}
    if key:
        total = '11.11' if key == 'client:a' else '22.22'
        result.update(tree=[], excluded_count=2, cache_stale=False, analytics={
            'current_total': total, 'previous_total': '0.00', 'absolute_change': total,
            'percentage_change': None, 'from': data.get('from'), 'to': data.get('to'),
            'previous_from': '2024-11', 'previous_to': '2025-10', 'buckets': []})
    if hold_a and key == 'client:a':
        held.append((route, result))
    else:
        route.fulfill(json=result)

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/usr/bin/chromium', headless=True, args=['--no-sandbox'])
    page = browser.new_page(locale='ru-RU')
    page.clock.set_fixed_time(datetime(2026, 10, 15, 12, tzinfo=timezone.utc))
    page.route('https://cdn.jsdelivr.net/npm/chart.js', lambda route: route.fulfill(path=chart, content_type='application/javascript'))
    page.goto(base)
    page.locator('#authLogin').fill('one@example.test')
    page.locator('#authPin').fill('12345678')
    page.locator('#authForm button').click()
    expect(page.locator('#appShell')).to_be_visible()
    page.route('**/api/client_purchases.php', respond)
    page.evaluate("openClientHistoryModal('Общий номер',{call_id:4})")
    page.locator('#clientPurchasesTab').click()
    expect(page.locator('#purchaseClientChoice')).to_be_visible()
    expect(page.locator('#purchaseClientSelect option')).to_have_count(3)
    expect(page.locator('#purchaseAnalyticsStatus')).to_contain_text('Выберите клиента')
    expect(page.locator('#purchaseAnalyticsRetry')).to_be_hidden()
    page.locator('#purchaseClientSelect').select_option('client:a')
    expect(page.locator('#purchaseKpis')).to_contain_text('11,11 ₽')
    expect(page.locator('#purchaseIdentityNote')).to_contain_text('2')
    page.locator('#purchaseFrom').fill('2026-10')
    page.locator('#purchaseFrom').dispatch_event('change')
    expect(page.locator('#purchaseKpis')).to_contain_text('11,11 ₽')
    page.locator('#purchaseClientSelect').select_option('client:b')
    expect(page.locator('#purchaseKpis')).to_contain_text('22,22 ₽')
    expect(page.locator('#purchaseFrom')).to_have_value('2026-10')
    # Hold A's response, finish B first, then deliver A late.
    hold_a = True
    page.locator('#purchaseClientSelect').select_option('client:a')
    page.wait_for_timeout(100)
    assert len(held) == 1
    page.locator('#purchaseClientSelect').select_option('client:b')
    expect(page.locator('#purchaseKpis')).to_contain_text('22,22 ₽')
    route, result = held.pop()
    with page.expect_response('**/api/client_purchases.php'):
        route.fulfill(json=result)
    page.wait_for_timeout(100)
    expect(page.locator('#purchaseClientSelect')).to_have_value('client:b')
    expect(page.locator('#purchaseKpis')).to_contain_text('22,22 ₽')
    page.locator('#purchaseClientSelect').select_option('')
    expect(page.locator('#purchaseKpis')).to_be_empty()
    expect(page.locator('#purchaseChart')).to_be_hidden()
    expect(page.locator('#purchaseAnalyticsStatus')).to_contain_text('Выберите клиента')
    page.keyboard.press('Escape')
    page.evaluate("openClientHistoryModal('Общий номер',{call_id:4})")
    page.locator('#clientPurchasesTab').click()
    expect(page.locator('#purchaseClientSelect')).to_have_value('')
    expect(page.locator('#purchaseKpis')).to_be_empty()
    browser.close()
print('client_workspace_shared_browser_test: selection, filters, exclusions, late response, reset OK')
