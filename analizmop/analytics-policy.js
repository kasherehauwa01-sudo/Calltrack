(() => {
  const url = '/vr/calltrack/api/analytics_policy_admin.php';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const priorityLabels = {LOW:'Низкий',NORMAL:'Обычный',HIGH:'Высокий',URGENT:'Срочный'};
  let epoch = 0;
  window.closeAnalyticsTaskPolicy = () => { ++epoch; };
  window.showAnalyticsTaskPolicy = async back => {
    const requestEpoch = ++epoch;
    const panel = document.getElementById('tasksView');
    const api = (method='GET',body) => window.calltrackApi.requestJson(url,{method,cache:'no-store',credentials:'same-origin',...(body?{headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}:{})});
    try {
      const {policy} = await api();
      if (requestEpoch !== epoch) return;
      panel.innerHTML = `<section class="filter-card clients-catalog"><button type="button" id="policyBack" class="updates-action-btn">К сигналам</button><h2>Политика автоматических задач</h2><p>Версия: ${policy.revision}. ${Number(policy.approved) ? 'Утверждена' : 'НЕ УТВЕРЖДЕНА — автоматизация заблокирована'}. Сохранение изменений сбрасывает утверждение.</p><p>Общий режим, актуальность источников, идентификация и остальные проверки действуют независимо. Ручные задачи не зависят от этой политики.</p><form id="analyticsPolicyForm" class="updates-form"><label>Минимальная confidence (0–1)<input name="min_confidence" type="number" min="0" max="1" step="0.0001" value="${esc(policy.min_confidence ?? '')}" placeholder="Не задана"></label>${policy.types.map(type=>`<fieldset data-policy-kind="${esc(type.signal_type)}"><legend>${esc(type.signal_type)}</legend><label class="inline-check"><input type="checkbox" name="${type.signal_type}_enabled" ${Number(type.enabled)?'checked':''}> Разрешить автоматизацию этого типа после утверждения</label><label>Приоритет<select name="${type.signal_type}_priority"><option value="">Не задан</option>${Object.entries(priorityLabels).map(([key,label])=>`<option value="${key}"${type.priority===key?' selected':''}>${label}</option>`).join('')}</select></label><label>Срок выполнения, календарных дней<input name="${type.signal_type}_due_days" type="number" min="0" max="365" step="1" value="${esc(type.due_days ?? '')}" placeholder="Не задан"></label></fieldset>`).join('')}<button type="submit" class="registry-refresh-btn">Сохранить черновик</button></form><hr><label class="inline-check"><input type="checkbox" id="confirmBusinessPolicy"> Бизнес-параметры текущей сохранённой версии отдельно согласованы</label><button type="button" class="updates-action-btn" id="approveBusinessPolicy">Явно утвердить сохранённую версию</button><p role="status" id="policyMessage"></p></section>`;
      panel.querySelector('#policyBack').onclick = back;
      panel.querySelector('#analyticsPolicyForm').onsubmit = async event => {
        event.preventDefault();const form = new FormData(event.target);
        const minimum = form.get('min_confidence');
        const body={revision:Number(policy.revision),action:'save_draft',min_confidence:minimum===''?null:Number(minimum),types:policy.types.map(type=>({signal_type:type.signal_type,enabled:form.has(`${type.signal_type}_enabled`),priority:form.get(`${type.signal_type}_priority`)||null,due_days:form.get(`${type.signal_type}_due_days`)===''?null:Number(form.get(`${type.signal_type}_due_days`))}))};
        try {await api('POST',body);window.showAnalyticsTaskPolicy(back);}catch(error){panel.querySelector('#policyMessage').textContent=error.message;}
      };
      panel.querySelector('#approveBusinessPolicy').onclick = async () => {
        if(!panel.querySelector('#confirmBusinessPolicy').checked){panel.querySelector('#policyMessage').textContent='Подтвердите отдельное согласование сохранённых параметров.';return;}
        if(!confirm('Утвердить сохранённую версию политики? Несохранённые изменения формы не применяются.'))return;
        try {await api('POST',{revision:Number(policy.revision),action:'approve',confirm_business_approval:true});window.showAnalyticsTaskPolicy(back);}catch(error){panel.querySelector('#policyMessage').textContent=error.message;}
      };
    }catch(error){if(requestEpoch === epoch) panel.textContent=error.message;}
  };
})();
