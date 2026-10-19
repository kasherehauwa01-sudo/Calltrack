"""Authenticated local Calltrack endpoint + fake Sales Journal contract; never production."""
from max_message_history_http_test import Client,check
payload={'call_id':4,'action':'category_details','category':'Посуда'}
check(Client().request('client_purchases.php',payload)[0]==401,'category auth required')
one,two,admin,boss=(Client(n) for n in ['one','two','admin','boss'])
check(one.request('client_purchases.php',payload,False)[0]==403,'category CSRF required')
check(two.request('client_purchases.php',payload)[0]==403,'foreign client category scope')
for user in [one,admin,boss]:
    code,data=user.request('client_purchases.php',payload)
    check(code==200 and len(data['data']['items'])==10 and data['data']['level']=='subcategories','top10 subcategories')
    code,data=user.request('client_purchases.php',{**payload,'subcategory':'Подкатегория 0'})
    check(code==200 and len(data['data']['items'])==20 and data['data']['level']=='products','top20 products')
check(one.request('client_purchases.php',{**payload,'client_keys':['client:forged']})[0]==400,'forged tag rejected')
check(one.request('client_purchases.php',{**payload,'category':[]})[0]==400,'invalid category path rejected')
print('category details HTTP: 401/403/CSRF, 3 roles, scoped context, paths, top10/top20, forged tags OK')
