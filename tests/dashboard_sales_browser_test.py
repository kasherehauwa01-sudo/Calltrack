"""Offline real Chart.js smoke: sales filters, metrics, stale responses and MAX tags."""
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
        page.set_content(html[html.index('    <article class="chart-card" style='):html.index('    <section class="charts-grid"')])
        page.add_script_tag(path='/workspace/.calltrack-tools/chart.umd.min.js')
        page.add_script_tag(content="""
const activeManagers=new Set(['A']); let requests=[];
window.fetch=async(url)=>{requests.push(url);return {ok:true,json:async()=>({status:'success',items:[{month:1,revenue:'123.45',checks:2,units:'5.5'}]})};};
""" + sales_js)
        page.evaluate('refreshDashboardSales()')
        page.wait_for_function('dashboardSalesRows.length===1')
        assert page.evaluate('dashboardSalesChart.data.datasets[0].data[0]') == 123.45
        assert page.evaluate('dashboardSalesChart.data.labels.length') == 12
        for metric, value in [('checks',2),('units',5.5),('revenue',123.45)]:
            page.select_option('#dashboardSalesMetric', metric)
            assert page.evaluate('dashboardSalesChart.data.datasets[0].data[0]') == value
        assert page.evaluate('requests.length') == 1
        page.evaluate("activeManagers.add('B');refreshDashboardSales()")
        page.wait_for_function('requests.length===2 && dashboardSalesRows.length===1')
        assert 'B' in page.evaluate('requests[1]')
        page.fill('#dashboardSalesYear','2025')
        page.locator('#dashboardSalesYear').dispatch_event('change')
        page.wait_for_function('requests.length===3')
        assert '2025' in page.evaluate('requests[2]')
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
