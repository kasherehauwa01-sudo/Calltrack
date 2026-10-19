/* Directory listing only; the existing history workspace is loaded on selection. */
(() => {
  'use strict';
  const $=id=>document.getElementById(id);
  let page=1,request=0,selection=0,timer=null,items=[],pending=null,refreshTimer=null;
  const escape=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  async function load(background=false,retry=false){
    clearTimeout(refreshTimer);
    pending?.abort();pending=new AbortController();
    const current=++request;if(!background)selection++;
    if(!background)$('clientsStatus').textContent='Загрузка клиентов…';$('clientsRetry').hidden=true;
    if(!background){$('clientsList').replaceChildren();$('clientsPrev').disabled=true;$('clientsNext').disabled=true;}
    $('clientsManagerLabel').hidden=authenticatedWebUser?.role==='manager';
    const params={search:$('clientsSearch').value,activity:$('clientsActivity').value,page,page_size:50};
    if(authenticatedWebUser?.role!=='manager')params.manager=$('clientsManager').value;
    if(retry)params.retry_purchases=1;
    try{
      const result=await window.calltrackApi.loadClients(params,{signal:pending.signal});if(current!==request)return;
      items=result.items;page=result.page;
      const manager=$('clientsManager').value;
      $('clientsManager').innerHTML='<option value="">Все менеджеры</option>'+(result.managers||[]).map(m=>`<option value="${escape(m.id)}">${escape(m.name)}</option>`).join('');
      $('clientsManager').value=manager;
      if(result.purchase_state==='warming'){
        items=[];$('clientsList').replaceChildren();$('clientsCount').textContent='';$('clientsPage').textContent='';
        $('clientsStatus').textContent='Подготовка дат последних покупок…'+(result.purchase_total?` ${result.processed} из ${result.purchase_total}`:'');
        $('clientsPrev').disabled=true;$('clientsNext').disabled=true;
        refreshTimer=setTimeout(()=>load(true),2000);return;
      }
      $('clientsCount').textContent=`Найдено: ${result.total}`;
      $('clientsStatus').textContent=items.length?(result.purchase_state==='refreshing'?'Обновляем даты последних покупок…':''):'Клиенты не найдены';
      if(result.purchase_state==='refreshing')refreshTimer=setTimeout(()=>load(true),3000);
      $('clientsList').innerHTML=items.map(client=>`<button type="button" class="client-catalog-row" data-catalog-client="${escape(client.id)}"><strong>${escape(client.name)}</strong><span>${escape(client.phones.map(formatPhoneForDisplay).join(', ')||'Телефон не указан')}</span><span>Менеджер: ${escape(client.manager||'Не указан')}</span><span>Последняя покупка: ${escape(client.last_purchase_at?formatDateDisplay(client.last_purchase_at):'Покупок нет')}</span><span>Последняя активность: ${escape(formatDateTimeDisplay(client.last_activity_at)||'Нет активности')}</span></button>`).join('');
      $('clientsPage').textContent=`Страница ${page} из ${Math.max(1,Math.ceil(result.total/result.page_size))}`;
      $('clientsPrev').disabled=page<=1;$('clientsNext').disabled=page*result.page_size>=result.total;
    }catch(error){if(current!==request)return;$('clientsCount').textContent='';$('clientsPage').textContent='';$('clientsStatus').textContent=error.message;$('clientsRetry').hidden=false;}
  }
  $('clientsFilters').addEventListener('submit',event=>{event.preventDefault();clearTimeout(timer);page=1;load();});
  $('clientsSearch').addEventListener('input',()=>{clearTimeout(timer);clearTimeout(refreshTimer);pending?.abort();request++;selection++;$('clientsList').replaceChildren();$('clientsCount').textContent='';$('clientsStatus').textContent='Поиск клиентов…';$('clientsPrev').disabled=true;$('clientsNext').disabled=true;timer=setTimeout(()=>{page=1;load();},300);});
  for(const id of ['clientsManager','clientsActivity'])$(id).addEventListener('change',()=>{clearTimeout(timer);page=1;load();});
  $('clientsPrev').addEventListener('click',()=>{page--;load();});$('clientsNext').addEventListener('click',()=>{page++;load();});$('clientsRetry').addEventListener('click',()=>load(false,true));
  $('clientsList').addEventListener('click',async event=>{
    const button=event.target.closest('[data-catalog-client]');if(!button)return;
    const client=items.find(item=>item.id===button.dataset.catalogClient);if(!client)return;
    clearTimeout(refreshTimer);
    const current=++selection;$('clientsStatus').textContent='Загрузка истории клиента…';
    try{
      const history=await window.calltrackApi.loadClientEvents(client.id);
      if(current!==selection||!$('clientsView').classList.contains('active'))return;
      $('clientsStatus').textContent='';openClientHistoryModal(history.client.name,{client_id:history.client.id},history);
    }catch(error){if(current===selection)$('clientsStatus').textContent=error.message;}
  });
  window.clientCatalog={open(){clearTimeout(timer);load();},close(){clearTimeout(timer);clearTimeout(refreshTimer);request++;selection++;pending?.abort();}};
})();
