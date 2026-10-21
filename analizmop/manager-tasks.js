(() => {
  const endpoint = '/vr/calltrack/api/manager_tasks.php';
  const diagnostics = '/vr/calltrack/api/analytics_admin.php';
  const statuses = {OPEN:'Открыта',IN_PROGRESS:'В работе',DONE:'Выполнена',CANCELLED:'Отменена'};
  const priorities = {LOW:'Низкий',NORMAL:'Обычный',HIGH:'Высокий',URGENT:'Срочный'};
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const api = (url, method = 'GET', body) => window.calltrackApi.requestJson(url, {method, credentials:'same-origin',cache:'no-store',...(body ? {headers:{'Content-Type':'application/json'},body:JSON.stringify(body)} : {})});
  let epoch = 0;
  let user, pageAfter = '', nextAfter = null, status = '', assignees = [];
  const panel = () => document.getElementById('tasksView');
  const options = (values, selected) => Object.entries(values).map(([key,label]) => `<option value="${key}"${key === selected ? ' selected' : ''}>${label}</option>`).join('');
  const managerOptions = selected => assignees.map(row => `<option value="${row.id}"${String(row.id) === String(selected) ? ' selected' : ''}>${esc(row.display_name)}</option>`).join('');
  async function showList() {
    window.closeAnalyticsTaskPolicy?.();
    const requestEpoch = ++epoch;
    panel().innerHTML = '<section class="filter-card"><p role="status">Загрузка задач…</p></section>';
    try {
      const data = await api(`${endpoint}?after=${encodeURIComponent(pageAfter)}&status=${encodeURIComponent(status)}`);
      if (requestEpoch !== epoch) return;
      nextAfter = data.next_after; assignees = data.assignees || [];
      panel().innerHTML = `<section class="filter-card clients-catalog"><h2>Задачи менеджеров</h2>
        <div class="clients-filters"><label>Статус<select id="taskStatusFilter"><option value="">Все</option>${options(statuses,status)}</select></label></div>
        <div class="updates-actions"><button type="button" class="updates-action-btn" id="taskRefresh">Обновить</button>${data.role === 'admin' ? '<button type="button" class="registry-refresh-btn" id="taskCreate">Создать задачу</button><button type="button" class="updates-action-btn" id="taskDiagnostics">Аналитические сигналы</button>' : ''}</div>
        <div class="clients-list">${data.items.map(row => `<button type="button" class="client-catalog-row" data-task="${esc(row.id)}"><strong>${esc(row.title)}</strong><span>Клиент ID: ${esc(row.client_id)} · ${esc(row.assignee_name)}</span><span>${esc(statuses[row.status])} · ${esc(priorities[row.priority])} · Срок (UTC): ${esc(row.due_at || 'не задан')}</span></button>`).join('') || '<p>Задач нет.</p>'}</div>
        <div class="clients-pagination"><button type="button" class="updates-action-btn" id="tasksFirst">В начало</button><button type="button" class="updates-action-btn" id="tasksNext" ${nextAfter ? '' : 'disabled'}>Далее</button></div></section>`;
      panel().querySelector('#taskStatusFilter').onchange = event => {status = event.target.value;pageAfter = '';showList();};
      panel().querySelector('#taskRefresh').onclick = showList;
      panel().querySelector('#tasksFirst').onclick = () => {pageAfter = '';showList();};
      panel().querySelector('#tasksNext').onclick = () => {pageAfter = nextAfter;showList();};
      panel().querySelectorAll('[data-task]').forEach(button => button.onclick = () => showTask(button.dataset.task));
      if (data.role === 'admin') {panel().querySelector('#taskCreate').onclick = showCreate;panel().querySelector('#taskDiagnostics').onclick = () => showDiagnostics();}
    } catch (error) { if (requestEpoch !== epoch) return; panel().innerHTML = `<section class="filter-card"><p role="alert">${esc(error.message)}</p><button type="button" id="tasksRetry">Повторить</button></section>`;panel().querySelector('#tasksRetry').onclick = showList; }
  }
  async function showTask(id) {
    window.closeAnalyticsTaskPolicy?.();
    const requestEpoch = ++epoch;
    try {
      const {task, history, client, role} = await api(`${endpoint}?id=${encodeURIComponent(id)}`);
      if (requestEpoch !== epoch) return;
      const editable = role !== 'supervisor' && !['DONE','CANCELLED'].includes(task.status);
      panel().innerHTML = `<section class="filter-card clients-catalog"><button type="button" class="updates-action-btn" id="tasksBack">К списку</button><h2>${esc(task.title)}</h2>
        <p>Клиент: ${esc(client?.name || task.client_id)} (ClientsVR ID: ${esc(task.client_id)}) <button type="button" class="updates-action-btn" id="taskClientCard">Карточка клиента</button></p>
        <p style="white-space:pre-wrap">${esc(task.description)}</p><p>Ответственный: ${esc(task.assignee_name)}</p>
        ${task.source_signal_key ? `<p>Источник: <button type="button" class="updates-action-btn" id="taskSource">Аналитический сигнал</button></p>` : ''}
        <form id="taskEdit" class="updates-form"><label>Статус<select name="status" ${editable ? '' : 'disabled'}>${options(statuses,task.status)}</select></label>
        ${role === 'admin' && editable ? `<label>Менеджер<select name="assignee_id">${assignees.some(row=>String(row.id)===String(task.assignee_id)) ? '' : `<option value="${task.assignee_id}" selected>${esc(task.assignee_name)} (неактивен)</option>`}${managerOptions(task.assignee_id)}</select></label><label>Приоритет<select name="priority">${options(priorities,task.priority)}</select></label><label>Срок UTC (YYYY-MM-DD HH:MM:SS)<input name="due_at" value="${esc(task.due_at || '')}"></label>` : `<p>Приоритет: ${esc(priorities[task.priority])} · Срок UTC: ${esc(task.due_at || 'не задан')}</p>`}
        <label>Результат работы<textarea name="result" maxlength="10000" ${editable ? '' : 'disabled'}>${esc(task.result)}</textarea></label>${editable ? '<button type="submit" class="registry-refresh-btn">Сохранить</button>' : ''}<p role="status" id="taskMessage"></p></form>
        <h3>История</h3><ul>${history.map(row => `<li>${esc(row.created_at)} · ${esc(row.event_type)} · ${esc(row.data_json)}</li>`).join('')}</ul><div id="taskSourceEvidence"></div></section>`;
      panel().querySelector('#tasksBack').onclick = showList;
      panel().querySelector('#taskClientCard').onclick = async () => {
        try { const history = await window.calltrackApi.loadClientEvents({client_id:task.client_id});openClientHistoryModal(client?.name || task.client_id, {client_id:task.client_id}, history); }
        catch (error) { panel().querySelector('#taskMessage').textContent = error.message; }
      };
      if (task.source_signal_key) panel().querySelector('#taskSource').onclick = () => {
        const evidence = document.createElement('pre'); evidence.style.whiteSpace = 'pre-wrap';
        try { evidence.textContent = JSON.stringify(JSON.parse(task.evidence_json || '{}'),null,2); } catch { evidence.textContent = 'Сведения недоступны'; }
        panel().querySelector('#taskSourceEvidence').replaceChildren(evidence);
      };
      panel().querySelector('#taskEdit').onsubmit = async event => {
        event.preventDefault();const form = new FormData(event.target);const body = {id:task.id,version:Number(task.version),status:form.get('status'),result:form.get('result')};
        if (role === 'admin') {body.assignee_id = Number(form.get('assignee_id'));body.priority = form.get('priority');body.due_at = form.get('due_at') || null;}
        try {await api(endpoint,'PATCH',body);showTask(id);} catch(error) {panel().querySelector('#taskMessage').textContent = error.message;}
      };
    } catch (error) {if (requestEpoch === epoch) panel().textContent = error.message;}
  }
  function showCreate() {
    ++epoch;
    panel().innerHTML = `<section class="filter-card"><h2>Новая задача</h2><form id="taskNew" class="updates-form"><label>ClientsVR client_id<input name="client_id" maxlength="255" required></label><label>Менеджер<select name="assignee_id">${managerOptions()}</select></label><label>Название<input name="title" maxlength="255" required></label><label>Описание<textarea name="description" maxlength="10000"></textarea></label><label>Категория<select name="family"><option value="MANUAL">Общая</option><option value="INACTIVITY">Отсутствие покупок</option></select></label><label>Приоритет<select name="priority">${options(priorities,'NORMAL')}</select></label><label>Срок UTC (YYYY-MM-DD HH:MM:SS)<input name="due_at"></label><button type="submit" class="registry-refresh-btn">Создать</button><button type="button" id="newTaskBack">Отмена</button><p role="status" id="newTaskMessage"></p></form></section>`;
    panel().querySelector('#newTaskBack').onclick = showList;
    panel().querySelector('#taskNew').onsubmit = async event => {
      event.preventDefault();const data = Object.fromEntries(new FormData(event.target));data.assignee_id = Number(data.assignee_id);data.due_at ||= null;
      try {const result = await api(endpoint,'POST',data);showTask(result.id);}catch(error){panel().querySelector('#newTaskMessage').textContent = error.message;}
    };
  }
  async function showDiagnostics(after = '') {
    window.closeAnalyticsTaskPolicy?.();
    const requestEpoch = ++epoch;
    try {
      const data = await api(`${diagnostics}?after=${encodeURIComponent(after)}`);
      if (requestEpoch !== epoch) return;
      panel().innerHTML = `<section class="filter-card clients-catalog"><button type="button" id="diagnosticsBack" class="updates-action-btn">К задачам</button><h2>Аналитические сигналы</h2><button type="button" class="updates-action-btn" id="analyticsPolicy">Политика автоматизации</button><p>Режим: ${esc(data.mode)}. Сопоставление клиента само по себе не создаёт задачу.</p><p>Получено: ${data.summary.received} · Прошли проверки: ${data.summary.passed || 0} · На проверке: ${data.summary.review || 0} · Заблокировано: ${data.summary.blocked || 0} · Создано задач: ${data.summary.created_tasks} · Уже существовали: ${data.summary.existing_tasks || 0} · Без менеджера: ${data.summary.without_eligible_manager || 0} · Выполненных задач: ${data.completed_analytics_tasks} · Отклонённых событий: ${data.summary.rejected_events}</p><details><summary>Отклонённые доставки</summary><ul>${(data.rejections || []).map(row=>`<li>${esc(row.signal_id)} · ${esc(row.reason_code)} · ${esc(row.created_at)}</li>`).join('')}</ul></details>${data.items.map(row => `<article class="client-catalog-row"><strong>${esc(row.subject?.key)} · ${esc(row.signal_type)} · ${esc(row.decision)}</strong><span>Сигнал: ${esc(row.signal_id)} · Срез: ${esc(row.as_of)} · Последняя покупка: ${esc(row.last_purchase)} · Confidence: ${esc(row.confidence)}</span><details><summary>Обоснование расчёта</summary><pre style="white-space:pre-wrap">${esc(JSON.stringify(row.metrics,null,2))}</pre></details><p>${esc(row.reasons_json)}</p><button type="button" class="updates-action-btn" data-dry-run="${esc(row.signal_key)}">Dry-run: проверить без задач</button>${row.task_id ? `<button type="button" class="updates-action-btn" data-linked-task="${esc(row.task_id)}">Задача</button>` : ''}<details><summary>Идентификация клиента</summary><form data-binding="${esc(row.signal_key)}"><label>ClientsVR client_id<input name="client_id" value="${esc(row.binding?.client_id || '')}"></label><label>Статус<select name="binding_status">${options({VERIFIED:'Подтверждено вручную',REVIEW:'На проверке',AMBIGUOUS:'Неоднозначно',NOT_FOUND:'Не найден'},row.binding?.status || 'REVIEW')}</select></label><label><input type="checkbox" name="technical" ${Number(row.binding?.technical) ? 'checked' : ''}> Технический/агрегатный покупатель</label><label><input type="checkbox" name="confirm_nontechnical"> Проверено: это не технический и не агрегатный покупатель</label><label><input type="checkbox" name="confirm_all_sales_scope"> Проверена вся группа исторических продаж; совпадение имени или телефона само по себе не является подтверждением</label><label>Основание проверки<textarea name="evidence" required maxlength="2000">${esc(row.binding?.evidence || '')}</textarea></label><input type="hidden" name="revision" value="${esc(row.binding?.revision || '')}"><button type="submit" class="updates-action-btn">Сохранить и проверить без создания задач</button><p role="status"></p></form></details></article>`).join('')}<button type="button" id="diagnosticsNext" ${data.next_after ? '' : 'disabled'}>Далее</button></section>`;
      panel().querySelector('#diagnosticsBack').onclick = showList;
      panel().querySelector('#analyticsPolicy').onclick = () => window.showAnalyticsTaskPolicy(() => showDiagnostics(after));
      panel().querySelectorAll('[data-dry-run]').forEach(button => button.onclick = async () => {
        try {await api(diagnostics,'POST',{signal_key:button.dataset.dryRun,action:'dry_run'});showDiagnostics(after);}catch(error){button.insertAdjacentText('afterend',error.message);}
      });
      panel().querySelector('#diagnosticsNext').onclick = () => showDiagnostics(data.next_after);
      panel().querySelectorAll('[data-linked-task]').forEach(button => button.onclick = () => showTask(button.dataset.linkedTask));
      panel().querySelectorAll('[data-binding]').forEach(form => form.onsubmit = async event => {
        event.preventDefault();const values = new FormData(form);
        const body = {signal_key:form.dataset.binding,client_id:values.get('client_id') || null,binding_status:values.get('binding_status'),technical:values.has('technical'),confirm_nontechnical:values.has('confirm_nontechnical'),confirm_all_sales_scope:values.has('confirm_all_sales_scope'),evidence:values.get('evidence'),expected_revision:values.get('revision') ? Number(values.get('revision')) : null};
        try {await api(diagnostics,'POST',body);showDiagnostics(after);}catch(error){form.querySelector('[role="status"]').textContent = error.message;}
      });
    } catch (error) {if (requestEpoch === epoch) panel().textContent = error.message;}
  }
  window.closeManagerTasks = () => { ++epoch;window.closeAnalyticsTaskPolicy?.(); };
  window.openManagerTasks = account => { user = account;pageAfter = '';showList(); };
})();
