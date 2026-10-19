"""Isolated real Chromium drilldown: lazy requests, in-flight sharing, error/retry and stale responses."""
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

with sync_playwright() as p:
    browser=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
    page=browser.new_page(locale='ru-RU')
    page.set_content('<div id="tree"></div>')
    page.add_script_tag(path=str(Path(__file__).resolve().parents[1]/'analizmop/client-category-tree.js'))
    page.evaluate('''() => {
      window.calls=[];window.pending=[];window.generation=1;
      window.renderTree=(name,generation=window.generation)=>window.calltrackCategoryTree.render(document.querySelector('#tree'),[{category:name,revenue:'100',quantity:'5'}],{
        money:v=>v+' ₽',number:String,share:(v,total)=>v+'/'+total,totalRevenue:'200',isCurrent:()=>generation===window.generation,
        loadDetails:path=>{window.calls.push(path);return new Promise((resolve,reject)=>window.pending.push({resolve,reject}));}
      });window.renderTree('Категория');
    }''')
    assert page.evaluate('calls.length')==0
    button=page.get_by_role('button',name='▸ Категория',exact=True)
    button.click();page.get_by_role('button',name='▾ Категория',exact=True).click();button.click()
    assert page.evaluate('calls.length')==1
    page.evaluate('''pending[0].resolve({items:Array.from({length:10},(_,i)=>({name:'Подкатегория '+i,revenue:String(100-i),quantity:'1'})),total_count:12,parent_revenue:'1000'})''')
    expect(page.get_by_text('Топ 10 подкатегорий · показано 10 из 12')).to_be_visible()
    assert page.get_by_role('button',name='▸ Подкатегория',exact=False).count()==10
    page.get_by_role('button',name='▸ Подкатегория 0',exact=True).click()
    assert page.evaluate('calls[1]')=={'category':'Категория','subcategory':'Подкатегория 0'}
    page.evaluate('''pending[1].resolve({items:Array.from({length:20},(_,i)=>({name:i===0?'<script>evil()</script>':'Товар '+i,revenue:'1.05',quantity:'2',article:'A'+i,code:'C'+i})),total_count:25,parent_revenue:'30'})''')
    expect(page.get_by_text('Топ 20 товаров · показано 20 из 25')).to_be_visible()
    expect(page.get_by_text('<script>evil()</script>',exact=True)).to_be_visible()
    assert page.locator('#tree script').count()==0
    page.get_by_role('button',name='▾ Подкатегория 0',exact=True).click()
    page.get_by_role('button',name='▸ Подкатегория 0',exact=True).click()
    assert page.evaluate('calls.length')==2
    page.get_by_role('button',name='▸ Подкатегория 1',exact=True).click()
    page.evaluate("pending[2].reject(new Error('offline'))")
    expect(page.get_by_text('offline',exact=True)).to_be_visible()
    page.get_by_role('button',name='Повторить',exact=True).click()
    assert page.evaluate('calls.length')==4
    page.evaluate("pending[3].resolve({items:[],total_count:0,parent_revenue:'0'})")
    expect(page.get_by_text('Данные не найдены',exact=True)).to_be_visible()
    page.get_by_role('button',name='▸ Подкатегория 2',exact=True).click()
    page.evaluate("generation++;renderTree('Другой клиент');pending[4].resolve({items:[{name:'STALE',revenue:'9',quantity:'1'}],total_count:1,parent_revenue:'9'})")
    expect(page.get_by_role('button',name='▸ Другой клиент',exact=True)).to_be_visible()
    expect(page.get_by_text('STALE',exact=True)).to_have_count(0)
    assert page.get_by_role('button',name='▸ Другой клиент',exact=True).get_attribute('aria-expanded')=='false'
    browser.close()
print('category tree browser: lazy top10/top20, paths, in-flight/reopen dedup, retry, empty, stale client selection, escaped product names and aria OK')

# Shared actual client modal + authenticated PHP route (local disposable fixtures only).
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
    page=browser.new_page(locale='ru-RU')
    page.set_default_timeout(60000)
    page.route('https://cdn.jsdelivr.net/npm/chart.js',lambda r:r.fulfill(path='/workspace/.calltrack-tools/chart.umd.min.js',content_type='application/javascript'))
    page.goto('http://127.0.0.1:8090/vr/calltrack/analizmop/',timeout=120000)
    page.locator('#authLogin').fill('one@example.test');page.locator('#authPin').fill('12345678');page.locator('#authForm button').click()
    expect(page.locator('#appShell')).to_be_visible()
    page.locator('[data-tab=clientCalls]').click()
    page.get_by_role('button',name='Покупатель',exact=True).click()
    page.locator('#clientTopCategories').get_by_role('button',name='▸ Посуда',exact=True).click()
    expect(page.get_by_text('Топ 10 подкатегорий · показано 10 из 12')).to_be_visible()
    page.get_by_role('button',name='▸ Подкатегория 0',exact=True).click()
    expect(page.get_by_text('Топ 20 товаров · показано 20 из 25')).to_be_visible()
    expect(page.get_by_text('Товар 19',exact=True)).to_be_visible()
    browser.close()
print('category drilldown application smoke: existing client modal -> PHP auth/context -> Sales Journal fixture -> nested tables OK')
