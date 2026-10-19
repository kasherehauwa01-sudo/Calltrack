"""Only the opt-in disposable fixture; never production."""
import http.cookiejar,json,os,urllib.request,urllib.error
BASE=os.environ.get('MAX_HISTORY_TEST_URL','http://127.0.0.1:8090/vr/calltrack/api/')
assert BASE.startswith('http://127.0.0.1:')
checks=0
class Client:
    def __init__(self,name=None):
        self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf=''
        if name:
            code,data=self.request('web_auth_api.php?action=login',{'login':name+'@example.test','pin':'12345678'})
            assert code==200,data;self.csrf=data['csrf_token']
    def request(self,path,body=None,csrf=True):
        headers={'Content-Type':'application/json'}
        if self.csrf and csrf:headers['X-CSRF-Token']=self.csrf
        request=urllib.request.Request(BASE+path,data=json.dumps(body).encode() if body is not None else None,headers=headers)
        try:response=self.http.open(request)
        except urllib.error.HTTPError as error:response=error
        return response.code,json.load(response)
def check(condition,label):
    global checks
    assert condition,label
    checks+=1
payload={'call_id':4,'from':'2026-10-18','to':'2026-10-18'}
check(Client().request('client_max_history.php',payload)[0]==401,'no auth')
one=Client('one');two=Client('two');admin=Client('admin');boss=Client('boss')
check(one.request('client_max_history.php',payload,False)[0]==403,'CSRF')
check(one.request('client_max_history.php')[0]==405,'POST required')
for client in [one,admin,boss]:
    code,data=client.request('client_max_history.php',payload)
    check(code==200 and len(data['data'])==1,'authorized context')
    row=data['data'][0]
    check(row=={'date':'2026-10-18','incoming_count':6,'outgoing_count':4,'last_event_at':'2026-10-17T21:40:00Z'},'single daily aggregate, both phones')
    check(set(row)=={'date','incoming_count','outgoing_count','last_event_at'},'aggregate response allowlist')
check(two.request('client_max_history.php',payload)[0]==403,'manager B cannot use A call')
check(one.request('client_max_history.php',{**payload,'call_id':3})[0]==403,'manager A cannot use B call')
code,data=one.request('client_max_history.php',{**payload,'manager':'Two','user_phone':'web-user-2','phone':'9993333333','timezone':'America/Los_Angeles','client_name':'Forged'})
check(code==200 and data['data'][0]['incoming_count']==6,'forged scope and timezone ignored')
for client,email,count in [(one,10,6),(two,11,1),(admin,10,6),(admin,11,1),(boss,10,6)]:
    code,data=client.request('client_max_history.php',{'email_id':email,'from':'2026-10-18','to':'2026-10-18'})
    check(code==200 and data['data'][0]['incoming_count']==count,'email owner isolation')
check(one.request('client_max_history.php',{'email_id':11})[0]==403,'foreign email context rejected')
check(one.request('client_max_history.php',{**payload,'from':'2026-02-30'})[0]==400,'invalid date')
check(one.request('client_max_history.php',{'phone':'9992223344'})[0]==400,'phone alone cannot authorize context')
check(one.request('get_calls.php')[0]==200,'calls endpoint unchanged')
check(one.request('client_purchases.php',{'call_id':4,'action':'context'})[0]==200,'existing client context unchanged')
print(f'max_message_history_http_test: {checks} checks OK')
