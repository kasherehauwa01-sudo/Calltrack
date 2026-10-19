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
    for zone in ['UTC', 'America/Los_Angeles']:
        page = browser.new_page(timezone_id=zone)
        page.set_content('<style>'+''.join(re.findall(r'<style[^>]*>(.*?)</style>',html,re.S))+'</style>'+html[html.index('    <section class="charts-grid" id="salesChartsGrid"'):html.index('    <section class="view" id="clientCallsView"')])
        page.add_script_tag(path='/workspace/.calltrack-tools/chart.umd.min.js')
        page.add_script_tag(content="""
const activeManagers=new Set(['A']); let requests=[];
window.fetch=async(url)=>{requests.push(url);return {ok:true,json:async()=>({status:'success',items:Array.from({length:12},(_,i)=>({month:i+1,revenue:i?0:'123.45',checks:i?0:2,units:i?0:'5.5'}))})};};
""" + sales_js)
        page.evaluate('refreshDashboardSales()')
        page.wait_for_function('dashboardSalesRows.length===12')
        assert page.evaluate('dashboardSalesChart.data.datasets[0].data[0]') == 123.45
        assert page.evaluate('dashboardSalesChart.data.labels.length') == 12
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
        assert page.locator('#dashboardSalesPlot').is_visible()
        assert not page.locator('#dashboardSalesStatus').is_visible()
        assert html.index('id="salesChartsGrid"') > html.index('id="managerCallsChart"')
        assert page.locator('#dashboardSalesCard').evaluate("el=>getComputedStyle(el).borderRadius") == page.evaluate("getComputedStyle(document.documentElement).getPropertyValue('--radius').trim()")
        # Error must not show an empty graph as though there were no sales; retry recovers.
        page.evaluate("window.fetch=async()=>({ok:false,json:async()=>({status:'error',message:'Backend unavailable'})});dashboardSalesKey='';refreshDashboardSales()")
        page.wait_for_function("document.getElementById('dashboardSalesStatus').textContent==='Backend unavailable'")
        assert not page.locator('#dashboardSalesPlot').is_visible()
        assert page.locator('#dashboardSalesRetry').is_visible()
        page.evaluate("window.fetch=async()=>({ok:true,json:async()=>({status:'success',items:Array.from({length:12},(_,i)=>({month:i+1,revenue:0,checks:0,units:0}))})})")
        page.click('#dashboardSalesRetry')
        page.wait_for_function('dashboardSalesRows.length===12')
        assert page.locator('#dashboardSalesPlot').is_visible()
        assert page.evaluate('dashboardSalesChart.data.datasets[0].data.every(v=>v===0)')
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
print('dashboard_sales_browser_test: PASS (2 browser timezones, 3 metrics, managers, year, MAX)')
