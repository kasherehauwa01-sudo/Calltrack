"""Run against the isolated fixture described in docs/client-workspace.md only."""
import http.cookiejar
import json
import os
import urllib.error
import urllib.request

BASE = os.environ.get('CALLTRACK_TEST_URL', 'http://127.0.0.1:8090/vr/calltrack/api/')
checks = 0

def expect(actual, expected, label):
    global checks
    checks += 1
    assert actual == expected, (label, actual, expected)

class Client:
    def __init__(self, name=None):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = ''
        if name:
            status, data = self.request('web_auth_api.php?action=login', {'login': name+'@example.test', 'pin': '12345678'})
            expect(status, 200, 'login '+name)
            self.csrf = data['csrf_token']
    def request(self, path, data=None, csrf=True, bearer=None):
        headers = {'Content-Type': 'application/json'}
        if csrf and self.csrf: headers['X-CSRF-Token'] = self.csrf
        if bearer: headers['Authorization'] = 'Bearer '+bearer
        request = urllib.request.Request(BASE+path, data=json.dumps(data).encode() if data is not None else None, headers=headers)
        try: response = self.opener.open(request)
        except urllib.error.HTTPError as error: response = error
        return response.status, json.load(response)

anonymous=Client()
for path, body in [('client_purchases.php', {'call_id':1}), ('personal_contact.php', {'call_id':1}), ('sale_detail.php?id=100',None)]:
    expect(anonymous.request(path,body)[0],401,'authentication '+path)
one,two,admin,boss=[Client(n) for n in ['one','two','admin','boss']]
expect(one.request('client_purchases.php',{'call_id':4},csrf=False)[0],403,'purchases CSRF')
expect(one.request('personal_contact.php',{'call_id':1},csrf=False)[0],403,'mutation CSRF')
for body in [{'call_id':3},{'call_id':5,'user_phone':'web-user-1'},{'client_id':'client-1','phone':'+79992223344'}]:
    expect(one.request('client_purchases.php',body)[0],403 if 'call_id' in body else 400,'scope/forged client')
expect(one.request('personal_contact.php',{'call_id':3,'user_phone':'web-user-1'})[0],403,'foreign personal')
expect(one.request('personal_contact.php',{'user_phone':'web-user-2','contact_phone':'9991112233','personal_flag':1})[0],403,'spoof legacy identity')
expect(one.request('personal_contact.php',{'id_db':1,'personal_flag':1})[0],403,'spoof personal ID')
expect(one.request('personal_contact.php',{'call_id':4})[0],400,'known client cannot mark')
for client in [admin,boss]:
    expect(client.request('client_purchases.php',{'call_id':5,'action':'context'})[0],200,'elevated existing scope')
for call_id,known in [(1,False),(4,True)]:
    status,data=one.request('client_purchases.php',{'call_id':call_id,'action':'context'})
    expect(status,200,'context');expect(data['context']['found_in_1c'],known,'1C backend flag')
expect(one.request('client_purchases.php',{'email_id':11,'action':'context'})[0],403,'foreign email')
expect(one.request('client_purchases.php',{'email_id':10,'action':'context'})[1]['context']['found_in_1c'],True,'own email client')
expect(one.request('sale_detail.php?id=100')[0],403,'no sale allow-list yet')
expect(one.request('client_purchases.php',{'call_id':4,'client_key':'client:unrelated'})[0],400,'reject unrelated client selection')
expect(one.request('client_purchases.php',{'call_id':3,'client_key':'client:client-1'})[0],403,'selection cannot bypass interaction scope')
status,context=one.request('client_purchases.php',{'call_id':4,'action':'context'})
expect(context['context']['selection_required'],False,'single identity auto-selected')
expect(context['context']['selected_client_key'],'client:client-1','server selected stable identity')
expect(len(context['context']['candidates']),1,'scoped candidate list')
status,data=one.request('client_purchases.php',{'call_id':4,'from':'2025-11','to':'2026-10','detail':'month'})
expect(status,200,'purchases');expect(len(data['analytics']['buckets']),12,'HTTP buckets');expect(data['analytics']['current_total'],'41.00','all client phones exact totals')
expect(len(data['tree']),3,'full history years');expect('test-only-token' in json.dumps(data),False,'token absent')
expect(one.request('sale_detail.php?id=102')[0],200,'authorized detail');expect(two.request('sale_detail.php?id=102')[0],403,'cross-session detail');expect(one.request('sale_detail.php?id=999999')[0],403,'forged sale ID')
status,filtered=one.request('client_purchases.php',{'call_id':4,'from':'2026-10','to':'2026-10','detail':'day','action':'analytics'})
expect(status,200,'filter change');expect('tree' in filtered,False,'tree untouched');expect(filtered['requests'],0,'cache/no N+1')
expect(filtered['analytics']['current_total'],'0.60','daily cents')
expect(one.request('client_purchases.php',{'call_id':4,'from':'2026-10','to':'2026-01'})[0],400,'invalid range')
expect(boss.request('personal_contact.php',{'call_id':5})[0],200,'supervisor selected call owner')
expect(len(two.request('get_calls.php?period=all')[1]['data']),1,'supervisor marked correct manager')
expect(admin.request('personal_contact.php',{'user_phone':'web-user-2','contact_phone':'9993334455','personal_flag':0,'manager':'Two'})[0],200,'admin legacy management retained')
# Mark a user's unknown number while attempting to supply another owner.
expect(one.request('personal_contact.php',{'call_id':1,'user_phone':'web-user-2','manager':'Two','personal_flag':0})[0],200,'mark own persisted call')
expect(anonymous.request('add_call.php',{'call_id':'fixture-future','date':'2026-10-16','time':'10:00:00','phone':'8 (999) 111-22-33','type':'Исходящий','duration':60,'client':'Unresolved'},bearer='a'*43)[0],200,'future call stored')
status,calls=one.request('get_calls.php?period=all')
expect([r['id_db'] for r in calls['data']],[4],'historical formatted numbers excluded')
expect(one.request('dashboard.php')[1]['data']['total_calls'],1,'statistics exclude personal')
expect(len(anonymous.request('android_client_timeline.php?date_from=2026-10-01&date_to=2026-10-31',bearer='a'*43)[1]['data']['calls']),1,'Android working timeline excludes personal')
expect(len(two.request('get_calls.php?period=all')[1]['data']),2,'other owner unaffected')
status,contacts=anonymous.request('get_personal_contacts.php?user_phone=web-user-2',bearer='a'*43)
expect(status,200,'Android existing sync');expect(all(row['personal_flag']==1 and ''.join(c for c in row['contact_phone'] if c.isdigit())[-10:]=='9991112233' for row in contacts['data']),True,'Android server identity / phone aliases agree')
expect(anonymous.request('personal_contact.php',{'contact_phone':'9991112233','personal_flag':0,'user_phone':'web-user-2'},bearer='a'*43)[0],200,'Android legacy POST without web CSRF')
expect(len(one.request('get_calls.php?period=all')[1]['data']),4,'unmark restores history without deletion')
print(f'client_workspace_http_test: {checks} checks OK')
