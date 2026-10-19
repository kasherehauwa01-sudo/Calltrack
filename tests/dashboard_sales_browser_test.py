"""Offline real Chart.js smoke: sales filters, metrics, stale responses and MAX tags."""
import re
from pathlib import Path
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parents[1]
html = (root / 'analizmop/index.html').read_text()
sales_js = html[html.index('let dashboardSalesChart='):html.index('function renderCallTypeTags(')]
filter_js = html[html.index('function applyFilters('):html.index('function bindFilters(')]
with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox'])
    for zone,width in [('UTC',1200),('America/Los_Angeles',390)]:
        page = browser.new_page(timezone_id=zone,viewport={"width":width,"height":800})
        page.set_content('<style>'+''.join(re.findall(r'<style[^>]*>(.*?)</style>',html,re.S))+'</style>'+html[html.index('    <section class="charts-grid" id="salesChartsGrid"'):html.index('    <section class="view" id="clientCallsView"')])
        page.add_script_tag(path='/workspace/.calltrack-tools/chart.umd.min.js')
        page.add_script_tag(content="""
const activeManagers=new Set(['A']); let requests=[];
function fixture(year,empty=false){return {status:'success',year,previous_year:year-1,as_of:'2026-10-19',
items:Array.from({length:12},(_,i)=>({month:i+1,revenue:i||empty?0:'123.45',checks:i||empty?0:2,units:i||empty?0:'5.5'})),
previous_items:Array.from({length:12},(_,i)=>({month:i+1,revenue:i||empty?0:100,checks:i||empty?0:1,units:i||empty?0:4}))};}
window.fetch=async(url)=>{requests.push(url);const year=Number(new URL(url,'http://test').searchParams.get('year'));return {ok:true,json:async()=>fixture(year)};};
""" + sales_js)
        page.fill('#dashboardSalesYear','2026')
        page.evaluate('refreshDashboardSales()')
        page.wait_for_function('dashboardSalesRows.length===12')
        assert page.evaluate('dashboardSalesChart.data.datasets[0].data[0]') == 123.45
        assert page.evaluate('dashboardSalesChart.data.labels.length') == 12
        assert page.evaluate('dashboardSalesChart.config.type') == 'line'
        assert page.evaluate('dashboardSalesChart.data.datasets.map(row=>row.label)') == ['2026','2025']
        assert page.evaluate('dashboardSalesChart.data.datasets[0].data.slice(10)') == [None,None]
        assert page.evaluate('dashboardSalesChart.data.datasets[0].data[1]') == 0
        assert page.evaluate('dashboardSalesChart.data.datasets[1].data.slice(10)') == [0,0]
        assert page.evaluate("dashboardSalesChart.options.plugins.tooltip.callbacks.title([{dataIndex:2}])") == 'Март'
        assert page.evaluate("dashboardSalesChart.options.plugins.tooltip.callbacks.footer([{dataIndex:0}])") == 'Изменение: +23,5%'
        assert page.evaluate("dashboardSalesChart.options.plugins.tooltip.callbacks.footer([{dataIndex:1}])") == ''
        assert page.evaluate("dashboardSalesChart.options.plugins.tooltip.callbacks.footer([{dataIndex:11}])") == ''
        label=page.evaluate("dashboardSalesChart.options.plugins.tooltip.callbacks.label({dataset:{label:'2026'},parsed:{y:2145300}})")
        assert label.replace('\xa0',' ').replace('\u202f',' ') == '2026: 2 145 300 ₽'
        assert page.evaluate("dashboardSalesChart.data.datasets.every(row=>row.pointRadius>0 && row.tension>0 && row.spanGaps===false)")

        for metric, value in [('checks',2),('units',5.5),('revenue',123.45)]:
            page.select_option('#dashboardSalesMetric', metric)
            assert page.evaluate('dashboardSalesChart.data.datasets[0].data[0]') == value
        assert page.evaluate('requests.length') == 1
        page.evaluate("activeManagers.add('B');refreshDashboardSales()")
        page.wait_for_function('requests.length===2 && dashboardSalesRows.length===12')
        assert 'B' in page.evaluate('requests[1]')
        page.fill('#dashboardSalesYear','2025')
        page.locator('#dashboardSalesYear').dispatch_event('change')
        page.wait_for_function('requests.length===3')
        assert '2025' in page.evaluate('requests[2]')
        page.wait_for_function('dashboardSalesPayload?.year===2025')
        assert page.evaluate('dashboardSalesChart.data.datasets.map(row=>row.label)') == ['2025','2024']
        assert page.evaluate('dashboardSalesChart.data.datasets[0].data.every(value=>value!==null)')
        assert page.locator('#dashboardSalesPlot').is_visible()
        assert not page.locator('#dashboardSalesStatus').is_visible()
        assert html.index('id="salesChartsGrid"') > html.index('id="managerCallsChart"')
        assert page.locator('#dashboardSalesCard').evaluate("el=>getComputedStyle(el).borderRadius") == page.evaluate("getComputedStyle(document.documentElement).getPropertyValue('--radius').trim()")
        # Error must not show an empty graph as though there were no sales; retry recovers.
        page.evaluate("window.fetch=async()=>({ok:false,json:async()=>({status:'error',message:'Backend unavailable'})});dashboardSalesKey='';refreshDashboardSales()")
        page.wait_for_function("document.getElementById('dashboardSalesStatus').textContent==='Backend unavailable'")
        assert not page.locator('#dashboardSalesPlot').is_visible()
        assert page.locator('#dashboardSalesRetry').is_visible()
        page.evaluate("window.fetch=async()=>({ok:true,json:async()=>fixture(2025,true)})")
        page.click('#dashboardSalesRetry')
        page.wait_for_function('dashboardSalesRows.length===12')
        assert page.locator('#dashboardSalesPlot').is_visible()
        assert page.evaluate('dashboardSalesChart.data.datasets[0].data.every(v=>v===0)')
        assert page.locator('#dashboardSalesStatus').inner_text()=='Нет продаж за выбранные годы'
        # Exercise actual dashboard filtering with MAX-only selection.
        page.add_script_tag(content="""
const activeCallTypes=new Set(['MAX Входящие','MAX исходящие']);
const allCalls=[{source:'max',type:'Входящий',manager:'A'},{source:'max',type:'Исходящий',manager:'A'},{source:'max',type:'Пропущенный',manager:'A'},{source:'phone',type:'Входящий',manager:'A'}];
const activeGroupBy='month',activePeriod='all',els={};
const normalizeCallTypeLabel=v=>v==='Пропущенный'?'Неотвеченный':v;
const getPeriodRange=()=>null, normalizeDate=()=>null,normalizeDateTime=()=>null;
const hideNoData=()=>{},showNoData=()=>{},renderKpi=()=>{},destroyCharts=()=>{};
let captured=[];const analyzeCalls=rows=>(captured=rows,{}),renderCharts=()=>{};
""" + filter_js)
        page.evaluate('applyFilters()')
        assert page.evaluate('captured.map(c=>c.type)') == ['Входящий','Исходящий']
        page.close()
    browser.close()
print('dashboard_sales_browser_test: PASS (desktop/mobile, two timezones, two lines, future months, tooltip, year/manager/metric filters, empty/error, MAX)')
