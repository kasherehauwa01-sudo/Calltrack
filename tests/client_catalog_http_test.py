"""Only the explicitly local, disposable fixture server."""
from max_message_history_http_test import Client,check
one,two,admin,boss=(Client(name) for name in ['one','two','admin','boss'])
code,data=Client().request('clients_list.php');check(code==401,'catalog requires login')
code,data=one.request('clients_list.php');check(code==200 and data['total']>50,'manager directory pagination')
check(len(data['items'])==50 and all(r['manager']=='One' for r in data['items']),'only assigned clients, 50 per page')
check(data['managers']==[],'manager cannot select other managers')
first_ids={row['id'] for row in data['items']}
code,second=one.request('clients_list.php?page=2');check(code==200 and not first_ids.intersection(row['id'] for row in second['items']),'second page distinct')
for user in [admin,boss]:
    code,data=user.request('clients_list.php');check(code==200 and data['total']>100,'privileged sees all directory')
    check({m['name'] for m in data['managers']}=={'One','Two'},'manager filter options')
    code,data=user.request('clients_list.php?manager=2');check(code==200 and all(r['manager']=='Two' for r in data['items']),'privileged filter')
check(one.request('clients_list.php?manager=2')[0]==403,'manager cannot request foreign manager')
code,data=one.request('clients_list.php?user_phone=web-user-2');check(code==200 and all(r['manager']=='One' for r in data['items']),'owner injection does not expand list')
for query in ['search=%D0%B0%D0%BB%D1%8C%D1%84%D0%B0','search=9000000001','search=%2B7%20%28900%29%20000-00-01']:
    code,data=one.request('clients_list.php?'+query);check(code==200 and [r['id'] for r in data['items']]==['catalog-a'],'name/phone search')
code,data=one.request('clients_list.php?search=%D0%91%D0%B5%D0%B7');check(code==200 and data['items'][0]['id']=='catalog-none' and data['items'][0]['last_activity_at'] is None,'no-call client in directory')
for endpoint in ['client_events.php','client_purchases.php','client_max_history.php']:
    payload={'client_id':'catalog-b','action':'context','manager':'Two','user_phone':'web-user-2','call_id':2001}
    check(one.request(endpoint,payload)[0]==403,'foreign client rejected in '+endpoint)
    check(one.request(endpoint,{'client_id':'not-existing','action':'context'})[0]==403,'unknown client rejected in '+endpoint)
code,data=one.request('client_events.php',{'client_id':'catalog-a','user_phone':'web-user-2'});
check(code==200 and [r['id_db'] for r in data['calls']]==[2001] and [r['id'] for r in data['emails']]==[2001],'direct events matching and owner scope')
check(set(data['client'])=={'id','name','phones','manager','manager_id'},'client response allowlist')
code,data=one.request('client_events.php',{'client_id':'catalog-none'});check(code==200 and data['calls']==[] and data['emails']==[],'no-interaction client opens')
for user in [one,admin,boss]:
    code,data=user.request('client_purchases.php',{'client_id':'catalog-none','action':'context'});check(code==200 and data['context']['found_in_1c'],'workspace context without call/email')
    code,data=user.request('client_max_history.php',{'client_id':'catalog-a'});check(code==200 and data['data']==[{'date':'2026-10-18','incoming_count':0,'outgoing_count':1,'last_event_at':'2026-10-18T17:30:00Z'}],'direct MAX history')
check(two.request('client_events.php',{'client_id':'catalog-b'})[0]==200,'second manager own client')
check(one.request('client_events.php',{'client_id':'catalog-a'},False)[0]==403,'CSRF on history POST')
print('client_catalog_http_test: roles, search, pagination, assignments, forged IDs/owners, no-call history, MAX, legacy endpoints OK')
