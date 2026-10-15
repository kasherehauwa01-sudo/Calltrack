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
  const change = (item,format=money) => `${String(item.absolute_change).startsWith('-')?'':'+'}${format(item.absolute_change)} (${item.percentage_change === null ? 'нет базы для сравнения' : new Intl.NumberFormat('ru-RU', {maximumFractionDigits:1,signDisplay:'exceptZero'}).format(item.percentage_change) + '%'})`;
  const number = value => {
    const [whole,fraction=''] = String(value??0).split('.');
    const digits=fraction.replace(/0+$/,'');
    return whole.replace(/\B(?=(\d{3})+(?!\d))/g,' ')+(digits?','+digits:'');
  };
  const metrics={revenue:['Сумма продаж','₽',money],sales_count:['Количество продаж','продаж',number],average_check:['Средний чек','₽',money],units:['Количество проданных товаров','ед.',number],units_per_sale:['Количество товаров в чеке','ед. / чек',number]};
  const dateLabel = value => value?value.split('-').reverse().join('.'):'—';
  function clearSummary(){
    $('clientSummaryKpis').replaceChildren();$('clientTopCategories').replaceChildren();$('clientSummaryRetry').hidden=true;
    $('clientSummaryStatus').textContent='';
  }
  async function loadSummary(){
    const current=epoch;clearSummary();
    if(needsSelection){$('clientSummaryStatus').textContent='Выберите клиента в списке выше.';return;}
    $('clientSummaryStatus').textContent='Загружаем сводку покупок…';
    try{
      const result=await api({action:'summary'});if(current!==epoch)return;
      if(!applyPurchaseContext(result.context)){$('clientSummaryStatus').textContent='Выберите клиента в списке выше.';return;}
      const s=result.summary;
      const cards=[['Количество продаж',number(s.sales_count)],['Выручка',money(s.revenue)],['Первая покупка',dateLabel(s.first_purchase)],['Последняя продажа',dateLabel(s.last_purchase)],['Частота покупок',s.average_interval===null?'Недостаточно покупок':number(s.average_interval)+' дней'],['Дней без покупки',s.days_without_purchase===null?'—':number(s.days_without_purchase)],['Уникальных товаров',s.unique_products===undefined?'Недоступно':number(s.unique_products)],['RFM статус',s.rfm?.segment||(s.sales_count?'Недоступно':'Нет покупок')]];
      $('clientSummaryKpis').innerHTML=cards.map(([label,value])=>`<div><span>${escape(label)}</span><strong>${escape(value)}</strong></div>`).join('');
      const categories=s.top_categories;
      $('clientTopCategories').innerHTML=categories?.length?'<table><thead><tr><th>Категория</th><th>Выручка</th><th>Количество товаров</th></tr></thead><tbody>'+categories.map(c=>`<tr><td>${escape(c.category)}</td><td>${escape(money(c.revenue))}</td><td>${escape(number(c.quantity))}</td></tr>`).join('')+'</tbody></table>':(categories?'Покупок товаров нет.':'Категории временно недоступны.');
      $('clientSummaryStatus').textContent=[result.details_error,result.cache_stale?'Показаны последние сохранённые данные.':'',s.uncategorized_products?`Без категории: ${number(s.uncategorized_products)} товаров.`:''].filter(Boolean).join(' ');
      $('clientSummaryRetry').hidden=!result.details_error;
    }catch(error){if(current!==epoch)return;$('clientSummaryStatus').textContent=error.message;$('clientSummaryRetry').hidden=false;}
  }
  $('clientSummaryRetry').addEventListener('click',loadSummary);
  let epoch = 0, analyticsRequest = 0, reference = {}, chart = null, treeLoaded = false, treeLoading = false, needsSelection = false;
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
  function applyPurchaseContext(context) {
    const candidates=context.candidates||[];
    needsSelection=!!context.selection_required;
    reference.client_key=context.selected_client_key||'';
    $('clientIdentityStatus').textContent='';
    $('clientUnknown').hidden=context.found_in_1c||!context.phone;
    $('clientMarkPersonal').hidden=!context.can_mark_personal;
    $('purchaseClientChoice').hidden=candidates.length<2;
    const select=$('purchaseClientSelect');select.replaceChildren();
    const prompt=document.createElement('option');prompt.value='';prompt.textContent='Выберите клиента';select.append(prompt);
    for(const candidate of candidates){
      const option=document.createElement('option');option.value=candidate.key;
      const sameName=candidates.filter(c=>c.name.trim().toLocaleLowerCase()===candidate.name.trim().toLocaleLowerCase()).length>1;
      option.textContent=(candidate.name||'Клиент без названия')+(sameName&&candidate.id?` (${candidate.id})`:'');select.append(option);
    }
    select.value=reference.client_key;
    if(needsSelection){
      treeLoaded=false;clearAnalytics();$('purchaseTree').replaceChildren();
      $('purchaseAnalyticsStatus').textContent='Выберите клиента в списке выше.';
      $('purchaseTreeStatus').textContent='';$('purchaseIdentityNote').textContent='';
      $('purchaseTreeRetry').hidden=true;$('purchaseAnalyticsRetry').hidden=true;
    }
    return !needsSelection;
  }
  function showExcluded(result) {
    $('purchaseIdentityNote').textContent=result.excluded_count
      ? `Не включено продаж по общему номеру: ${result.excluded_count}. Это покупки других клиентов или записи, которые нельзя однозначно отнести к выбранному клиенту.` : '';
  }
  $('purchaseClientSelect').addEventListener('change',()=>{
    epoch++;analyticsRequest++;reference.client_key=$('purchaseClientSelect').value;
    needsSelection=!reference.client_key;treeLoaded=false;treeLoading=false;
    clearAnalytics();$('purchaseTree').replaceChildren();$('purchaseIdentityNote').textContent='';
    $('purchaseTreeStatus').textContent='';$('purchaseTreeRetry').hidden=true;$('purchaseAnalyticsRetry').hidden=true;
    if(needsSelection){clearSummary();$('clientSummaryStatus').textContent='Выберите клиента в списке выше.';$('purchaseAnalyticsStatus').textContent='Выберите клиента в списке выше.';return;}
    loadTree();loadSummary();
  });
  function filters() {return {from:$('purchaseFrom').value,to:$('purchaseTo').value,detail:$('purchaseDetail').value,metric:$('purchaseMetric').value};}
  function validFilters() {
    const f=filters();
    if (!f.from || !f.to || f.from>f.to) { $('purchaseAnalyticsStatus').textContent='Укажите период: «С» не позже «По».';return false; }
    return true;
  }
  function renderAnalytics(a, stale) {
    const [label,unit,format]=metrics[a.metric||'revenue'];
    $('purchaseChart').setAttribute('aria-label',label+': текущий период и прошлый год');
    chart?.destroy();chart=null;
    $('purchaseKpis').innerHTML=[[label+' за период',format(a.current_total)],['Аналогичный период прошлого года',format(a.previous_total)],['Динамика',change(a,format)]].map(([label,value])=>`<div><span>${escape(label)}</span><strong>${escape(value)}</strong></div>`).join('');
    $('purchaseComparison').textContent=`Текущий период: ${a.from} — ${a.to}. Прошлый год: ${a.previous_from} — ${a.previous_to}.`;
    const empty=a.buckets.every(b=>b.current_count===0);
    $('purchaseAnalyticsStatus').textContent=(empty?'За выбранный период покупок нет. ':'')+(stale?'Показаны последние сохранённые данные; Sales Journal временно недоступен.':'');

    if (!window.Chart) { $('purchaseAnalyticsStatus').textContent+=' Диаграмма временно недоступна. Итоги показаны выше.';return; }
    $('purchaseChart').parentElement.hidden=false;
    chart=new Chart($('purchaseChart'),{type:'line',data:{labels:a.buckets.map(b=>b.label),datasets:[
      {label:'Текущий период',data:a.buckets.map(b=>Number(b.current)),borderColor:'#15803d',backgroundColor:'#15803d',pointRadius:a.buckets.length>100?0:3,tension:0},
      {label:'Прошлый год',data:a.buckets.map(b=>Number(b.previous)),borderColor:'#64748b',backgroundColor:'#64748b',borderDash:[7,5],pointStyle:'rect',pointRadius:a.buckets.length>100?0:3,tension:0}
    ]},options:{animation:false,responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},scales:{y:{title:{display:true,text:label+', '+unit}},x:{ticks:{maxTicksLimit:14}}},plugins:{tooltip:{callbacks:{
      title:items=>{const b=a.buckets[items[0].dataIndex];return `${b.current_date||'Нет даты в текущем году'} / ${b.previous_date||'Нет даты в прошлом году'}`;},
      label:context=>{const b=a.buckets[context.dataIndex];return `${context.dataset.label}: ${format(context.datasetIndex===0?b.current:b.previous)}`;},
      footer:items=>{const b=a.buckets[items[0].dataIndex];return [`Изменение: ${change(b,format)}`,`Количество покупок: ${b.current_count} / ${b.previous_count}`];}
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
    chart?.destroy();chart=null;$('purchaseChart').parentElement.hidden=true;
    for(const id of ['purchaseKpis','purchaseComparison'])$(id).replaceChildren();
  }
  async function loadTree() {
    clearAnalytics();
    if(needsSelection||!validFilters())return;
    const current=epoch, request=++analyticsRequest;treeLoading=true;
    $('purchaseTreeStatus').textContent='Загружаем историю покупок...';$('purchaseAnalyticsStatus').textContent='Загружаем динамику продаж...';
    $('purchaseTreeRetry').hidden=true;$('purchaseAnalyticsRetry').hidden=true;
    try {
      const result=await api(filters());if(current!==epoch)return;
      if(!applyPurchaseContext(result.context))return;showExcluded(result);
      renderTree(result.tree);treeLoaded=true;
      $('purchaseTreeStatus').textContent=result.tree.length?(result.cache_stale?'Показаны последние сохранённые данные.':''):'История покупок не найдена';
      if(request===analyticsRequest){renderAnalytics(result.analytics,result.cache_stale);}
    }catch(error){if(current!==epoch)return;$('purchaseTreeStatus').textContent=error.message;$('purchaseTreeRetry').hidden=false;if(request===analyticsRequest){$('purchaseAnalyticsStatus').textContent=error.message;$('purchaseAnalyticsRetry').hidden=false;}}
    finally {if(current===epoch)treeLoading=false;}
  }
  async function loadAnalytics() {
    analyticsRequest++;clearAnalytics();
    if(needsSelection||!validFilters())return;
    if(!treeLoaded&&!treeLoading){loadTree();return;}
    const current=epoch, request=++analyticsRequest;
    $('purchaseAnalyticsStatus').textContent='Загружаем динамику продаж...';$('purchaseAnalyticsRetry').hidden=true;
    try {const result=await api({...filters(),action:'analytics'});if(current!==epoch||request!==analyticsRequest)return;if(!applyPurchaseContext(result.context))return;showExcluded(result);renderAnalytics(result.analytics,result.cache_stale);}
    catch(error){if(current!==epoch||request!==analyticsRequest)return;$('purchaseAnalyticsStatus').textContent=error.message;$('purchaseAnalyticsRetry').hidden=false;}
  }
  $('purchaseFilters').addEventListener('submit',event=>{event.preventDefault();loadAnalytics();});
  $('purchaseFilters').addEventListener('change',loadAnalytics);
  $('purchaseAnalyticsRetry').addEventListener('click',loadAnalytics);
  $('purchaseTreeRetry').addEventListener('click',loadTree);
  window.clientWorkspace={
    open(ref) {
      const current=++epoch;analyticsRequest++;reference={...ref};treeLoaded=false;treeLoading=false;needsSelection=false;clearAnalytics();clearSummary();tab(false);
      $('purchaseClientChoice').hidden=true;$('purchaseClientSelect').replaceChildren();$('purchaseIdentityNote').textContent='';
      for(const id of ['purchaseKpis','purchaseComparison','purchaseTree','purchaseTreeStatus','purchaseAnalyticsStatus','clientPersonalStatus'])$(id).replaceChildren();
      $('clientUnknown').hidden=true;$('clientIdentityStatus').textContent='Определяем клиента…';$('clientMarkPersonal').disabled=false;$('clientMarkPersonal').textContent='Пометить как личный контакт';
      $('purchaseTreeRetry').hidden=true;$('purchaseAnalyticsRetry').hidden=true;
      const parts=new Intl.DateTimeFormat('en-CA',{timeZone:'Europe/Moscow',year:'numeric',month:'2-digit'}).formatToParts(new Date());
      const year=Number(parts.find(p=>p.type==='year').value),month=Number(parts.find(p=>p.type==='month').value);
      const start=new Date(Date.UTC(year,month-12,1));
      $('purchaseFrom').value=`${start.getUTCFullYear()}-${String(start.getUTCMonth()+1).padStart(2,'0')}`;$('purchaseTo').value=`${year}-${String(month).padStart(2,'0')}`;$('purchaseDetail').value='month';$('purchaseMetric').value='revenue';
      api({action:'context'}).then(result=>{if(current!==epoch)return;applyPurchaseContext(result.context);loadSummary();}).catch(error=>{if(current===epoch)$('clientIdentityStatus').textContent=error.message;});
    },
    close() {epoch++;analyticsRequest++;treeLoading=false;chart?.destroy();chart=null;closeInstruction();if($('saleModal').classList.contains('open'))closeSaleModal();}
  };
})();
