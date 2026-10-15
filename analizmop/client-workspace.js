/* Client workspace uses the existing authenticated API, Chart.js and sale dialog. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  // Format decimal strings without converting money to IEEE-754 numbers.
  const money = value => {
    const [whole, fraction = '00'] = String(value ?? '0.00').split('.');
    return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + (fraction === '00' ? '' : ',' + fraction.padEnd(2, '0')) + ' ₽';
  };
  const change = item => `${String(item.absolute_change).startsWith('-')?'':'+'}${money(item.absolute_change)} (${item.percentage_change === null ? 'нет базы для сравнения' : new Intl.NumberFormat('ru-RU', {maximumFractionDigits:1,signDisplay:'exceptZero'}).format(item.percentage_change) + '%'})`;
  let epoch = 0, analyticsRequest = 0, reference = {}, chart = null, treeLoaded = false, treeLoading = false;
  const api = data => window.calltrackApi.requestJson('/vr/calltrack/api/client_purchases.php', {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({...reference, ...data})
  });

  // A single observer + keyboard listener for this modal family, for its lifetime.
  const modals = [$('clientHistoryModal'), $('saleModal'), $('client1cModal')];
  const stack = [];
  const origins = new Map();
  function syncModals() {
    for (const modal of modals) {
      const at = stack.indexOf(modal);
      if (modal.classList.contains('open') && at < 0) {
        origins.set(modal, document.activeElement);stack.push(modal);
        modal.querySelector('button')?.focus();
      } else if (!modal.classList.contains('open') && at >= 0) {
        stack.splice(at, 1);
        const origin = origins.get(modal);origins.delete(modal);
        if (origin?.isConnected) origin.focus();
      }
    }
    document.body.classList.toggle('client-workspace-locked', stack.length > 0);
    modals.forEach(modal => { modal.inert = stack.includes(modal) && modal !== stack.at(-1); });
  }
  const observer = new MutationObserver(syncModals);
  modals.forEach(modal => observer.observe(modal, {attributes:true, attributeFilter:['class']}));
  function closeInstruction() { $('client1cModal').classList.remove('open');$('client1cModal').setAttribute('aria-hidden','true'); }
  document.addEventListener('keydown', event => {
    if (!stack.length) return;
    const top = stack.at(-1);
    if (event.key === 'Escape') {
      event.preventDefault();event.stopImmediatePropagation();
      if (top.id === 'saleModal') closeSaleModal();
      else if (top.id === 'client1cModal') closeInstruction();
      else closeClientHistoryModal();
    } else if (event.key === 'Tab') {
      const focusable = [...top.querySelectorAll('button,input,select,[tabindex="0"],summary')].filter(el => !el.disabled && el.getClientRects().length);
      const first = focusable[0], last = focusable.at(-1);
      if (event.shiftKey && (document.activeElement === first || !top.contains(document.activeElement))) {event.preventDefault();last?.focus();}
      else if (!event.shiftKey && (document.activeElement === last || !top.contains(document.activeElement))) {event.preventDefault();first?.focus();}
    }
  }, true);

  function tab(purchases) {
    $('clientGeneralPanel').hidden = purchases;$('clientPurchasesPanel').hidden = !purchases;
    for (const [id, selected] of [['clientGeneralTab',!purchases],['clientPurchasesTab',purchases]]) {
      $(id).setAttribute('aria-selected',String(selected));$(id).tabIndex=selected?0:-1;
    }
    if (purchases && !treeLoaded && !treeLoading) loadTree();
    if (purchases) chart?.resize();
  }
  $('clientGeneralTab').addEventListener('click', () => tab(false));
  $('clientPurchasesTab').addEventListener('click', () => tab(true));
  [$('clientGeneralTab'),$('clientPurchasesTab')].forEach(button => button.addEventListener('keydown',event => {
    if (['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) {
      event.preventDefault();const purchases=event.key==='End'||(event.key!=='Home'&&button.id==='clientGeneralTab');
      tab(purchases);$(purchases?'clientPurchasesTab':'clientGeneralTab').focus();
    }
  }));
  $('clientAdd1c').addEventListener('click', () => { $('client1cModal').classList.add('open');$('client1cModal').setAttribute('aria-hidden','false'); });
  $('client1cClose').addEventListener('click',closeInstruction);
  $('client1cModal').addEventListener('click',event => {if(event.target===$('client1cModal'))closeInstruction();});
  $('clientMarkPersonal').addEventListener('click',async () => {
    const current=epoch, callId=reference.call_id;
    $('clientMarkPersonal').disabled=true;$('clientPersonalStatus').textContent='Сохраняем…';
    try {
      const result=await window.calltrackApi.requestJson(window.calltrackApi.endpoints.personalContact, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({call_id:callId})});
      if(result.skipped)throw new Error('Контакт не сохранён: пользователь заблокирован');
      if(current!==epoch)return;
      $('clientPersonalStatus').textContent='Личный контакт. Звонки исключены из рабочих списков и статистики.';
      $('clientMarkPersonal').textContent='Личный';
      // Apply the same owner/phone scope immediately, then refresh from the server.
      const source=allCalls.find(call=>String(call.sqlId)===String(callId));
      if(source){allCalls=allCalls.filter(call=>!(call.userPhone===source.userPhone && normalizeClientLookupPhone(call.phone)===normalizeClientLookupPhone(source.phone)));renderAdminRegistry();renderClientCalls();applyFilters();}
      try {applyCallsPayload(await window.calltrackApi.loadCalls());await refreshClientSalesTimeline();}
      catch {if(current===epoch)$('clientPersonalStatus').textContent+=' Обновите страницу, если списки не обновились.';}
    } catch(error) {if(current===epoch){$('clientPersonalStatus').textContent=error.message;$('clientMarkPersonal').disabled=false;}}
  });
  function filters() {return {from:$('purchaseFrom').value,to:$('purchaseTo').value,detail:$('purchaseDetail').value};}
  function validFilters() {
    const f=filters();
    if (!f.from || !f.to || f.from>f.to) { $('purchaseAnalyticsStatus').textContent='Укажите период: «С» не позже «По».';return false; }
    return true;
  }
  function renderAnalytics(a, stale) {
    chart?.destroy();chart=null;
    $('purchaseKpis').innerHTML=[['Продажи за период',money(a.current_total)],['Аналогичный период прошлого года',money(a.previous_total)],['Динамика',change(a)]].map(([label,value])=>`<div><span>${escape(label)}</span><strong>${escape(value)}</strong></div>`).join('');
    $('purchaseComparison').textContent=`Текущий период: ${a.from} — ${a.to}. Прошлый год: ${a.previous_from} — ${a.previous_to}.`;
    const empty=a.buckets.every(b=>b.current_count===0);
    $('purchaseAnalyticsStatus').textContent=(empty?'За выбранный период покупок нет. ':'')+(stale?'Показаны последние сохранённые данные; Sales Journal временно недоступен.':'');
    $('purchaseChartTable').innerHTML='<table><thead><tr><th>Период</th><th>Текущий период</th><th>Прошлый год</th><th>Изменение</th></tr></thead><tbody>'+a.buckets.map(b=>`<tr><td>${escape(b.label)}</td><td>${escape(money(b.current))}</td><td>${escape(money(b.previous))}</td><td>${escape(change(b))}</td></tr>`).join('')+'</tbody></table>';
    if (!window.Chart) { $('purchaseAnalyticsStatus').textContent+=' Диаграмма недоступна; значения доступны в таблице ниже.';return; }
    chart=new Chart($('purchaseChart'),{type:'line',data:{labels:a.buckets.map(b=>b.label),datasets:[
      {label:'Текущий период',data:a.buckets.map(b=>Number(b.current)),borderColor:'#15803d',backgroundColor:'#15803d',pointRadius:a.buckets.length>100?0:3,tension:0},
      {label:'Прошлый год',data:a.buckets.map(b=>Number(b.previous)),borderColor:'#64748b',backgroundColor:'#64748b',borderDash:[7,5],pointStyle:'rect',pointRadius:a.buckets.length>100?0:3,tension:0}
    ]},options:{animation:false,responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},scales:{y:{title:{display:true,text:'Сумма покупок, ₽'}},x:{ticks:{maxTicksLimit:14}}},plugins:{tooltip:{callbacks:{
      title:items=>{const b=a.buckets[items[0].dataIndex];return `${b.current_date||'Нет даты в текущем году'} / ${b.previous_date||'Нет даты в прошлом году'}`;},
      label:context=>{const b=a.buckets[context.dataIndex];return `${context.dataset.label}: ${money(context.datasetIndex===0?b.current:b.previous)}`;},
      footer:items=>{const b=a.buckets[items[0].dataIndex];return [`Изменение: ${change(b)}`,`Количество покупок: ${b.current_count} / ${b.previous_count}`];}
    }}}}});
  }
  function renderTree(tree) {
    $('purchaseTree').replaceChildren();
    function branch(node, level) {
      const details=document.createElement('details'),summary=document.createElement('summary');
      const label=level===0?`${node.key} год`:level===1?new Date(node.key+'-01T12:00:00').toLocaleDateString('ru-RU',{month:'long',year:'numeric'}):node.key.split('-').reverse().join('.');
      summary.textContent=`${label} — ${money(node.total_amount)}`;details.append(summary);
      let built=false;
      details.addEventListener('toggle',()=>{
        if(!details.open||built)return;built=true;
        if(level<2)for(const child of node[level===0?'months':'dates'])details.append(branch(child,level+1));
        else for(const sale of node.sales){const button=document.createElement('button');button.type='button';button.dataset.purchaseSale=String(sale.sale_id);button.innerHTML=`<span>${escape(sale.document_number||'Без номера')}</span><span>${escape(money(sale.total_amount))}</span>`;details.append(button);}
      });
      return details;
    }
    tree.forEach(year=>$('purchaseTree').append(branch(year,0)));
  }
  $('purchaseTree').addEventListener('click',event=>{const sale=event.target.closest('[data-purchase-sale]');if(sale)openSaleCard(sale.dataset.purchaseSale);});
  function clearAnalytics() {
    chart?.destroy();chart=null;
    for(const id of ['purchaseKpis','purchaseComparison','purchaseChartTable'])$(id).replaceChildren();
  }
  async function loadTree() {
    clearAnalytics();
    if(!validFilters())return;
    const current=epoch, request=++analyticsRequest;treeLoading=true;
    $('purchaseTreeStatus').textContent='Загружаем историю покупок...';$('purchaseAnalyticsStatus').textContent='Загружаем динамику продаж...';
    $('purchaseTreeRetry').hidden=true;$('purchaseAnalyticsRetry').hidden=true;
    try {
      const result=await api(filters());if(current!==epoch)return;
      renderTree(result.tree);treeLoaded=true;
      $('purchaseTreeStatus').textContent=result.tree.length?(result.cache_stale?'Показаны последние сохранённые данные.':''):'История покупок не найдена';
      if(request===analyticsRequest){renderAnalytics(result.analytics,result.cache_stale);}
    }catch(error){if(current!==epoch)return;$('purchaseTreeStatus').textContent=error.message;$('purchaseTreeRetry').hidden=false;if(request===analyticsRequest){$('purchaseAnalyticsStatus').textContent=error.message;$('purchaseAnalyticsRetry').hidden=false;}}
    finally {if(current===epoch)treeLoading=false;}
  }
  async function loadAnalytics() {
    analyticsRequest++;clearAnalytics();
    if(!validFilters())return;
    if(!treeLoaded&&!treeLoading){loadTree();return;}
    const current=epoch, request=++analyticsRequest;
    $('purchaseAnalyticsStatus').textContent='Загружаем динамику продаж...';$('purchaseAnalyticsRetry').hidden=true;
    try {const result=await api({...filters(),action:'analytics'});if(current!==epoch||request!==analyticsRequest)return;renderAnalytics(result.analytics,result.cache_stale);}
    catch(error){if(current!==epoch||request!==analyticsRequest)return;$('purchaseAnalyticsStatus').textContent=error.message;$('purchaseAnalyticsRetry').hidden=false;}
  }
  $('purchaseFilters').addEventListener('submit',event=>{event.preventDefault();loadAnalytics();});
  $('purchaseFilters').addEventListener('change',loadAnalytics);
  $('purchaseAnalyticsRetry').addEventListener('click',loadAnalytics);
  $('purchaseTreeRetry').addEventListener('click',loadTree);
  window.clientWorkspace={
    open(ref) {
      const current=++epoch;analyticsRequest++;reference={...ref};treeLoaded=false;treeLoading=false;chart?.destroy();chart=null;tab(false);
      for(const id of ['purchaseKpis','purchaseComparison','purchaseTree','purchaseTreeStatus','purchaseAnalyticsStatus','purchaseChartTable','clientPersonalStatus'])$(id).replaceChildren();
      $('clientUnknown').hidden=true;$('clientIdentityStatus').textContent='Определяем клиента…';$('clientMarkPersonal').disabled=false;$('clientMarkPersonal').textContent='Пометить как личный контакт';
      $('purchaseTreeRetry').hidden=true;$('purchaseAnalyticsRetry').hidden=true;
      const parts=new Intl.DateTimeFormat('en-CA',{timeZone:'Europe/Moscow',year:'numeric',month:'2-digit'}).formatToParts(new Date());
      const year=Number(parts.find(p=>p.type==='year').value),month=Number(parts.find(p=>p.type==='month').value);
      const start=new Date(Date.UTC(year,month-12,1));
      $('purchaseFrom').value=`${start.getUTCFullYear()}-${String(start.getUTCMonth()+1).padStart(2,'0')}`;$('purchaseTo').value=`${year}-${String(month).padStart(2,'0')}`;$('purchaseDetail').value='month';
      api({action:'context'}).then(result=>{if(current!==epoch)return;$('clientIdentityStatus').textContent='';$('clientUnknown').hidden=result.context.found_in_1c||!result.context.phone;$('clientMarkPersonal').hidden=!result.context.can_mark_personal;}).catch(error=>{if(current===epoch)$('clientIdentityStatus').textContent=error.message;});
    },
    close() {epoch++;analyticsRequest++;treeLoading=false;chart?.destroy();chart=null;closeInstruction();if($('saleModal').classList.contains('open'))closeSaleModal();}
  };
})();
