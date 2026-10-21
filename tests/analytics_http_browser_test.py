"""Run against the opt-in local MariaDB/PHP fixture, never production URLs."""
import json
import os
import urllib.request
import urllib.error
import http.cookiejar
from playwright.sync_api import sync_playwright

BASE = os.environ.get('ANALYTICS_HTTP_TEST_URL', 'http://127.0.0.1:8877')
assert BASE.startswith('http://127.0.0.1:')
PREFIX = '/vr/calltrack/api/'

def session(user_id):
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    token = json.load(opener.open(BASE + '/fixture-login?id=' + str(user_id)))['csrf']
    return opener, token

def api(session, route, method='GET', data=None, csrf=True):
    opener, token = session
    headers = {'Content-Type': 'application/json'}
    if csrf:
        headers['X-CSRF-Token'] = token
    request = urllib.request.Request(BASE + PREFIX + route, method=method,
        headers=headers, data=json.dumps(data).encode() if data is not None else None)
    try:
        response = opener.open(request)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, json.load(response)

admin = session(1)
manager = session(3)
other = session(4)
supervisor = session(2)
assert api(manager, 'manager_bindings.php')[0] == 403
assert api(supervisor, 'analytics_policy_admin.php')[0] == 403
assert api(admin, 'manager_bindings.php', 'POST', {'designation':'Legacy Owner','web_user_id':3,'expected_revision':None}, csrf=False)[0] == 403
status, data = api(admin, 'manager_bindings.php')
assert status == 200 and len(data['designations']) == 2
assert api(admin, 'manager_bindings.php', 'POST', {'designation':'Legacy Owner','web_user_id':3,'expected_revision':None})[0] == 200
assert api(admin, 'manager_bindings.php', 'POST', {'designation':'Legacy Owner','web_user_id':4,'expected_revision':None})[0] == 409
status, data = api(admin, 'manager_tasks.php', 'POST', {'client_id':'client-100','assignee_id':3,'title':'HTTP synthetic task','description':'No production clients','priority':'NORMAL'})
assert status == 201, data
id = data['id']
assert api(manager, 'manager_tasks.php?id='+id)[0] == 200
assert api(other, 'manager_tasks.php?id='+id)[0] == 404
assert api(other, 'manager_tasks.php')[1]['items'] == []
assert api(supervisor, 'manager_tasks.php', 'PATCH', {'id':id,'version':1,'status':'DONE','result':'Forbidden'})[0] == 403
assert api(manager, 'manager_tasks.php', 'PATCH', {'id':id,'version':1,'status':'DONE','result':''})[0] == 422
assert api(manager, 'manager_tasks.php', 'PATCH', {'id':id,'version':1,'status':'IN_PROGRESS','result':'Synthetic follow-up'})[0] == 200
assert api(manager, 'manager_tasks.php', 'PATCH', {'id':id,'version':1,'status':'DONE','result':'Old revision'})[0] == 409
assert api(admin, 'manager_tasks.php', 'POST', {'client_id':'missing','assignee_id':3,'title':'Not allowed'})[0] == 422
policy = api(admin, 'analytics_policy_admin.php')[1]['policy']
assert int(policy['approved']) == 0 and policy['min_confidence'] is None
assert all(int(t['enabled']) == 0 and t['priority'] is None and t['due_days'] is None for t in policy['types'])
diagnostic = api(admin, 'analytics_admin.php')[1]
assert diagnostic['mode'] == 'disabled' and int(diagnostic['summary']['created_tasks']) == 0
assert api(manager, 'analytics_admin.php')[0] == 403
signal_key = diagnostic['items'][0]['signal_key']
result = api(admin, 'analytics_admin.php', 'POST', {'signal_key':signal_key,'action':'dry_run'})
assert result[0] == 200 and result[1]['decision']['decision'] == 'BLOCKED'
assert 'TASK_POLICY_NOT_APPROVED' in result[1]['decision']['reasons']

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/usr/bin/chromium', headless=True, args=['--no-sandbox'])
    context = browser.new_context()
    token = context.request.get(BASE + '/fixture-login?id=1').json()['csrf']
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.goto(BASE + '/fixture-ui')
    page.evaluate('(token) => {window.calltrackApi.csrfToken=token;window.openManagerTasks({id:1,role:"admin"});}', token)
    page.get_by_role('button',name='Создать задачу',exact=True).click()
    page.locator('input[name="client_id"]').fill('client-101')
    page.locator('input[name="title"]').fill('Browser synthetic task')
    page.locator('textarea[name="description"]').fill('Manual task while automation unapproved')
    page.get_by_role('button',name='Создать',exact=True).click()
    page.get_by_role('heading',name='Browser synthetic task').wait_for()
    page.locator('textarea[name="result"]').fill('Synthetic result')
    page.locator('select[name="status"]').select_option('DONE')
    page.get_by_role('button',name='Сохранить',exact=True).click()
    page.locator('select[name="status"][disabled]').wait_for()
    assert page.locator('textarea[name="result"]').input_value() == 'Synthetic result'
    page.get_by_role('button',name='К списку',exact=True).click()
    page.get_by_role('button',name='Аналитические сигналы',exact=True).click()
    page.get_by_role('button',name='Политика автоматизации',exact=True).click()
    assert page.locator('input[name="min_confidence"]').input_value() == ''
    page.locator('input[name="min_confidence"]').fill('0.65')
    page.locator('input[name="PURCHASE_OVERDUE_enabled"]').check()
    page.locator('select[name="PURCHASE_OVERDUE_priority"]').select_option('NORMAL')
    page.locator('input[name="PURCHASE_OVERDUE_due_days"]').fill('3')
    page.get_by_role('button',name='Сохранить черновик',exact=True).click()
    page.get_by_text('Версия: 2.',exact=False).wait_for()
    assert 'НЕ УТВЕРЖДЕНА' in page.locator('#tasksView').inner_text()
    assert not page.locator('#confirmBusinessPolicy').is_checked()
    assert not page.locator('input[name="CHURN_RISK_enabled"]').is_checked()
    page.evaluate('window.loadManagerBindings(3)')
    page.get_by_role('heading',name='Сопоставление с ClientsVR',exact=True).wait_for()
    page.get_by_text('Legacy Owner',exact=False).first.wait_for()
    assert not errors, errors
    browser.close()
print('analytics_http_browser_test: session/CSRF/ACL + real API + manual tasks + draft policy + aliases UI OK')
