(() => {
  const endpoint = '/vr/calltrack/api/manager_tasks.php';
  const diagnostics = '/vr/calltrack/api/analytics_admin.php';
  const statuses = {OPEN:'Открыта',IN_PROGRESS:'В работе',DONE:'Выполнена',CANCELLED:'Отменена'};
  const priorities = {LOW:'Низкий',NORMAL:'Обычный',HIGH:'Высокий',URGENT:'Срочный'};
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const api = (url, method = 'GET', body) => window.calltrackApi.requestJson(url, {method, credentials:'same-origin',cache:'no-store',...(body ? {headers:{'Content-Type':'application/json'},body:JSON.stringify(body)} : {})});
  let epoch = 0;
  let user, pageAfter = '', nextAfter = null, status = '', assignees = [], manager = '', type = '', completed = false;
  let badgeEpoch = 0;
  const drawCount = count => { const badge=document.getElementById('tasksCount'); if(badge){badge.hidden=false;badge.textContent=String(count);badge.setAttribute('aria-label',`Невыполненных задач: ${count}`);} };
  window.refreshManagerTasksCount = async () => {const request=++badgeEpoch;try {const data=await api(`${endpoint}?count_only=1`);if(request===badgeEpoch)drawCount(data.unfinished_count);} catch {if(request===badgeEpoch){const badge=document.getElementById('tasksCount');if(badge)badge.hidden=true;}}};
  const createdDate = value => {const match=/^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));return match ? `${match[3]}.${match[2]}.${match[1]}` : 'не указана';};
  const panel = () => document.getElementById('tasksView');
  const options = (values, selected) => Object.entries(values).map(([key,label]) => `<option value="${key}"${key === selected ? ' selected' : ''}>${label}</option>`).join('');
  const managerOptions = selected => assignees.map(row => `<option value="${row.id}"${String(row.id) === String(selected) ? ' selected' : ''}>${esc(row.display_name)}</option>`).join('');
  async function showList() {
    window.closeAnalyticsTaskPolicy?.();
    const requestEpoch = ++epoch;
    panel().innerHTML = '<section class="filter-card"><p role="status">Загрузка задач…</p></section>';
    try {
      const data = await api(`${endpoint}?after=${encodeURIComponent(pageAfter)}&status=${encodeURIComponent(status)}&manager=${encodeURIComponent(manager)}&type=${encodeURIComponent(type)}&completed=${completed ? '1' : '0'}`);
      if (requestEpoch !== epoch) return;
      nextAfter = data.next_after; assignees = data.assignees || [];drawCount(data.unfinished_count);
      panel().innerHTML = `<section class="filter-card clients-catalog"><h2>Задачи менеджеров</h2>
        <div class="clients-filters"><label>Статус<select id="taskStatusFilter"><option value="">Все</option>${options(completed ? {DONE:statuses.DONE} : {OPEN:statuses.OPEN,IN_PROGRESS:statuses.IN_PROGRESS,CANCELLED:statuses.CANCELLED},status)}</select></label>${data.role !== 'manager' ? `<label>Менеджер<select id="taskManagerFilter"><option value="">Все менеджеры</option>${managerOptions(manager)}</select></label>` : ''}</div>
        <div class="updates-actions" aria-label="Фильтры задач"><button type="button" class="updates-action-btn" id="tasksCompleted" aria-pressed="${completed}">Выполненные</button><button type="button" class="updates-action-btn" data-task-type="" aria-pressed="${type===''}">Все типы</button>${(data.types || []).map(tag=>`<button type="button" class="updates-action-btn" data-task-type="${esc(tag.key)}" aria-pressed="${type===tag.key}">${esc(tag.label)}</button>`).join('')}</div>
        <div class="updates-actions"><button type="button" class="updates-action-btn" id="taskRefresh">Обновить</button>${data.role === 'admin' ? '<button type="button" class="registry-refresh-btn" id="taskCreate">Создать задачу</button><button type="button" class="updates-action-btn" id="taskDiagnostics">Аналитические сигналы</button>' : ''}</div>
        <div class="task-list">${data.items.map(row => `<button type="button" class="task-card" data-task="${esc(row.id)}"><span class="task-card-top"><span class="task-card-title">${esc(row.title)}</span><span class="task-status task-status--${Object.hasOwn(statuses,row.status) ? row.status.toLowerCase() : 'unknown'}">${esc(statuses[row.status] || 'Неизвестный статус')}</span></span><strong class="task-card-client">${esc(row.client_name || 'Клиент не указан')}</strong><span class="task-card-bottom"><span>Добавлена: ${createdDate(row.created_at)}</span><span>Ответственный: ${esc(row.assignee_name || 'Не назначен')}</span><span class="task-card-open" aria-hidden="true">Открыть →</span></span></button>`).join('') || '<p class="task-empty">Нет задач по выбранным фильтрам.</p>'}</div>
        <div class="clients-pagination"><button type="button" class="updates-action-btn" id="tasksFirst">В начало</button><button type="button" class="updates-action-btn" id="tasksNext" ${nextAfter ? '' : 'disabled'}>Далее</button></div></section>`;
      panel().querySelector('#taskStatusFilter').onchange = event => {status = event.target.value;pageAfter = '';showList();};
      panel().querySelector('#taskRefresh').onclick = showList;
      const managerFilter=panel().querySelector('#taskManagerFilter');if(managerFilter)managerFilter.onchange=event=>{manager=event.target.value;pageAfter='';showList();};
      panel().querySelector('#tasksCompleted').onclick=()=>{completed=!completed;status='';pageAfter='';showList();};
      panel().querySelectorAll('[data-task-type]').forEach(button=>button.onclick=()=>{type=button.dataset.taskType;pageAfter='';showList();});
      panel().querySelector('#tasksFirst').onclick = () => {pageAfter = '';showList();};
      panel().querySelector('#tasksNext').onclick = () => {pageAfter = nextAfter;showList();};
      panel().querySelectorAll('[data-task]').forEach(button => button.onclick = () => showTask(button.dataset.task));
      if (data.role === 'admin') {panel().querySelector('#taskCreate').onclick = showCreate;panel().querySelector('#taskDiagnostics').onclick = () => showDiagnostics();}
    } catch (error) { if (requestEpoch !== epoch) return; panel().innerHTML = `<section class="filter-card"><p role="alert">${esc(error.message)}</p><button type="button" id="tasksRetry">Повторить</button></section>`;panel().querySelector('#tasksRetry').onclick = showList; }
  }
  const matchingNotice = task => {let e;try{e=JSON.parse(task.evidence_json || '{}');}catch{return '';}const m=e.signal?.matching;if(m?.status!=='PRELIMINARY_MATCH')return '';return `<p role="note">Предварительное сопоставление — ручная проверка не выполнялась. Способ: ${esc(m.method)}. Связанные карточки: ${(m.member_ids || []).map(esc).join(', ')}. Группа: ${esc(m.group_key)}</p>`;};
  async function showTask(id) {
    window.closeAnalyticsTaskPolicy?.();
    const requestEpoch = ++epoch;
    panel().innerHTML = '<section class="filter-card"><button type="button" class="updates-action-btn" id="taskLoadingBack">← К списку задач</button><p role="status"><span class="task-spinner" aria-hidden="true"></span> Загрузка задачи…</p></section>';
    panel().querySelector('#taskLoadingBack').onclick = showList;
    try {
      const {task, history, client, role} = await api(`${endpoint}?id=${encodeURIComponent(id)}`);
      if (requestEpoch !== epoch) return;
      const editable = role !== 'supervisor' && task.status !== 'CANCELLED';
      panel().innerHTML = `<section class="task-detail" aria-labelledby="taskDetailTitle">
        <nav class="task-detail-nav" aria-label="Навигация по задачам"><button type="button" class="updates-action-btn" id="tasksBack">← К списку задач</button><span>Добавлена: ${createdDate(task.created_at)}</span></nav>
        <header class="task-detail-header"><div><p class="task-eyebrow">Задача</p><h2 id="taskDetailTitle">${esc(task.title)}</h2></div><span class="task-status task-status--${Object.hasOwn(statuses,task.status) ? task.status.toLowerCase() : 'unknown'}">${esc(statuses[task.status] || 'Неизвестный статус')}</span></header>
        <section class="task-client-panel" aria-label="Клиент"><div><p class="task-eyebrow">Клиент</p><h3>${esc(client?.name || 'Клиент не указан')}</h3><p class="task-muted">Ответственный: ${esc(task.assignee_name || 'Не назначен')}</p></div><div class="task-client-actions"><button type="button" class="updates-action-btn" id="taskClientCard" aria-describedby="taskClientLoading">Карточка клиента</button><span id="taskClientLoading" class="task-client-feedback" role="status" aria-live="polite"></span></div></section>
        <section class="task-description-panel"><h3>Что нужно сделать</h3><p id="taskDescription">${esc(task.description || 'Описание задачи не добавлено.')}</p></section>
        <form id="taskEdit" class="task-detail-form">
          <section class="task-result-panel"><h3>Результат работы</h3><label for="taskResult" class="task-muted">${editable ? 'Зафиксируйте итог общения и дальнейшие договорённости.' : 'Сохранённый результат выполнения задачи.'}</label><textarea id="taskResult" name="result" maxlength="10000" rows="7" placeholder="Например: связались с клиентом, выяснили причину и договорились о следующем шаге…" ${editable ? '' : 'disabled'}>${esc(task.result)}</textarea>${task.status==='DONE' && editable ? '<p class="task-field-hint">Можно уточнить результат. Задача останется выполненной.</p>' : ''}</section>
          <aside class="task-settings-panel" aria-label="Параметры задачи"><h3>Параметры задачи</h3><label>Статус<select name="status" ${editable ? '' : 'disabled'}>${options(task.status === 'DONE' ? {DONE:statuses.DONE} : role === 'admin' ? statuses : {OPEN:statuses.OPEN,IN_PROGRESS:statuses.IN_PROGRESS,DONE:statuses.DONE,...(task.status==='CANCELLED'?{CANCELLED:statuses.CANCELLED}:{})},task.status)}</select></label>
          ${role === 'admin' && editable ? `<label>Менеджер<select name="assignee_id">${assignees.some(row=>String(row.id)===String(task.assignee_id)) ? '' : `<option value="${task.assignee_id}" selected>${esc(task.assignee_name)} (неактивен)</option>`}${managerOptions(task.assignee_id)}</select></label><label>Приоритет<select name="priority">${options(priorities,task.priority)}</select></label><label>Срок выполнения (UTC)<input name="due_at" value="${esc(task.due_at || '')}" placeholder="YYYY-MM-DD HH:MM:SS" aria-describedby="taskDueHint"></label><p id="taskDueHint" class="task-field-hint">Формат: год-месяц-день часы:минуты:секунды.</p>` : `<dl class="task-parameters">${role !== 'manager' ? `<div><dt>Приоритет</dt><dd>${esc(priorities[task.priority])}</dd></div>` : ''}<div><dt>Срок выполнения (UTC)</dt><dd>${esc(task.due_at || 'Не задан')}</dd></div></dl>`}
          </aside>
          <footer class="task-detail-footer"><p role="status" id="taskMessage" aria-live="polite"></p>${editable ? '<span class="task-muted">После сохранения вы вернётесь к списку задач.</span><button type="submit" class="registry-refresh-btn">Сохранить</button>' : '<span class="task-muted">Задача доступна только для просмотра.</span>'}</footer>
        </form>
        ${role !== 'manager' ? `<details class="task-extra"><summary>Дополнительные сведения и история</summary><div class="task-extra-content"><p class="task-muted">ID клиента: ${esc(task.client_id)}</p>${matchingNotice(task)}${task.source_signal_key ? '<p><a href="#taskSourceEvidence" class="updates-action-btn" id="taskSource">Исходный аналитический сигнал</a></p>' : ''}<div id="taskSourceEvidence"></div><h3>История</h3><ul class="task-history">${history.map(row => `<li><span>${esc(row.created_at)} · ${esc(({CREATED:'Задача создана',UPDATED:'Задача изменена'})[row.event_type] || row.event_type)}</span><details><summary>Подробности изменения</summary><pre>${esc(row.data_json)}</pre></details></li>`).join('') || '<li>История изменений пока пуста.</li>'}</ul></div></details>` : ''}
      </section>`;
      panel().querySelector('#tasksBack').onclick = showList;
      if (task.source_signal_key) {
        const description = panel().querySelector('#taskDescription');
        description.textContent = 'Загрузка показателей покупок…';
        const loadMetrics = async () => {
          description.textContent = 'Загрузка показателей покупок…';
          try {
            const {summary} = await api('/vr/calltrack/api/client_purchases.php', 'POST', {client_id:task.client_id,action:'purchase_metrics'});
            if (requestEpoch !== epoch) return;
            const interval = Number(summary.average_interval);
            const frequency = summary.average_interval != null && Number.isFinite(interval) && interval > 0 ? `каждые ${interval.toLocaleString('ru-RU')} дней` : 'не определена';
            description.textContent = `Частота покупок: ${frequency}. Последняя покупка: ${summary.last_purchase || 'нет покупок'}. Без покупок: ${summary.days_without_purchase == null ? 'неизвестно' : summary.days_without_purchase + ' дн.'} Связаться с клиентом, выяснить причину отсутствия заказов и зафиксировать результат.`;
          } catch (error) {
            if (requestEpoch !== epoch) return;
            description.textContent = 'Не удалось загрузить актуальные показатели. ';
            const retry = document.createElement('button');retry.type='button';retry.className='updates-action-btn';retry.textContent='Повторить';retry.onclick=loadMetrics;description.append(retry);
          }
        };
        loadMetrics();
      }
      panel().querySelector('#taskClientCard').onclick = async event => {
        const button=event.currentTarget;button.disabled=true;button.setAttribute('aria-busy','true');
        const feedback=panel().querySelector('#taskClientLoading');
        feedback.innerHTML='<span class="task-spinner" aria-hidden="true"></span><span>Загрузка карточки…</span>';
        try {
          const history = await window.calltrackApi.loadClientEvents({client_id:task.client_id});
          if(requestEpoch===epoch){openClientHistoryModal(client?.name || task.client_id, {client_id:task.client_id}, history);feedback.textContent='';}
        } catch (error) {
          if(requestEpoch===epoch)feedback.textContent = `Не удалось загрузить карточку. ${error.message}. Попробуйте ещё раз.`;
        } finally {button.disabled=false;button.removeAttribute('aria-busy');}
      };
      if (role !== 'manager' && task.source_signal_key) panel().querySelector('#taskSource').onclick = event => {
        event.preventDefault();
        const evidence = document.createElement('pre'); evidence.style.whiteSpace = 'pre-wrap';
        try { evidence.textContent = JSON.stringify(JSON.parse(task.evidence_json || '{}'),null,2); } catch { evidence.textContent = 'Сведения недоступны'; }
        panel().querySelector('#taskSourceEvidence').replaceChildren(evidence);
      };
      panel().querySelector('#taskEdit').onsubmit = async event => {
        event.preventDefault();const form = new FormData(event.target);const body = {id:task.id,version:Number(task.version),status:form.get('status'),result:form.get('result')};
        if (role === 'admin') {body.assignee_id = Number(form.get('assignee_id'));body.priority = form.get('priority');body.due_at = form.get('due_at') || null;}
        const submit=event.target.querySelector('[type=submit]');submit.disabled=true;submit.textContent='Сохранение…';submit.setAttribute('aria-busy','true');panel().querySelector('#taskMessage').textContent='';
        try {await api(endpoint,'PATCH',body);window.refreshManagerTasksCount();if(requestEpoch===epoch){pageAfter='';showList();}} catch(error) {if(requestEpoch===epoch)panel().querySelector('#taskMessage').textContent = error.message;}finally{submit.disabled=false;submit.textContent='Сохранить';submit.removeAttribute('aria-busy');}
      };
    } catch (error) {if (requestEpoch === epoch) {panel().innerHTML=`<section class="filter-card"><p role="alert">${esc(error.message)}</p><button type="button" id="taskLoadRetry">Повторить</button><button type="button" id="taskLoadBack">К списку задач</button></section>`;panel().querySelector('#taskLoadRetry').onclick=()=>showTask(id);panel().querySelector('#taskLoadBack').onclick=showList;}}
  }
  function showCreate() {
    ++epoch;
    panel().innerHTML = `<section class="filter-card"><h2>Новая задача</h2><form id="taskNew" class="updates-form"><label>ClientsVR client_id<input name="client_id" maxlength="255" required></label><label>Менеджер<select name="assignee_id">${managerOptions()}</select></label><label>Название<input name="title" maxlength="255" required></label><label>Описание<textarea name="description" maxlength="10000"></textarea></label><label>Категория<select name="family"><option value="MANUAL">Общая</option><option value="INACTIVITY">Отсутствие покупок</option></select></label><label>Приоритет<select name="priority">${options(priorities,'NORMAL')}</select></label><label>Срок UTC (YYYY-MM-DD HH:MM:SS)<input name="due_at"></label><button type="submit" class="registry-refresh-btn">Создать</button><button type="button" id="newTaskBack">Отмена</button><p role="status" id="newTaskMessage"></p></form></section>`;
    panel().querySelector('#newTaskBack').onclick = showList;
    panel().querySelector('#taskNew').onsubmit = async event => {
      event.preventDefault();const data = Object.fromEntries(new FormData(event.target));data.assignee_id = Number(data.assignee_id);data.due_at ||= null;
      try {const result = await api(endpoint,'POST',data);window.refreshManagerTasksCount();showTask(result.id);}catch(error){panel().querySelector('#newTaskMessage').textContent = error.message;}
    };
  }
  const candidateView = row => {
    const c = row.candidate;
    if (!c || c.status !== 'CANDIDATE') return '';

    const ids = Array.isArray(c.client_ids) ? c.client_ids : [];

    return `<div class="analytics-candidate-box">
      <strong>Предварительное сопоставление — ${esc(c.manager_name)}</strong>
      <p>Ответственный менеджер · ID ${esc(c.manager_id)}</p>
      <p>Найдены рабочие карточки ClientsVR:</p>
      <div class="analytics-candidate-options">
        ${ids.map(id => `<button type="button"
          class="updates-action-btn"
          data-candidate-id="${esc(id)}"
          data-candidate-signal="${esc(row.signal_key)}"
          title="Подставить ID без подтверждения клиента"
        >Карточка ${esc(id)}</button>`).join('')}
      </div>
      <p class="analytics-review-note">
        Выбор карточки только заполняет форму. Если карточек несколько,
        необходимо проверить всю историю продаж и установить,
        какая карточка должна быть привязана.
      </p>
      <p role="status" class="analytics-candidate-message"></p>
    </div>`;
  };

  const analyticsReviewStyle = `<style>
    .analytics-candidate-box {
      margin: 16px 0;
      padding: 18px;
      background: #fff;
      border: 1px solid #d7dfe9;
      border-radius: 14px;
    }
    .analytics-candidate-box p {
      margin: 9px 0;
      line-height: 1.5;
    }
    .analytics-candidate-options {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin: 12px 0;
    }
    .analytics-review-note {
      color: #526173;
      font-size: .9em;
    }
    .analytics-review-form {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
      gap: 14px 18px;
      margin-top: 14px;
      align-items: start;
    }
    .analytics-review-form > label {
      display: flex;
      flex-direction: column;
      gap: 6px;
      min-width: 0;
      line-height: 1.45;
    }
    .analytics-review-form > label:has(input[type="checkbox"]) {
      grid-column: 1 / -1;
      flex-direction: row;
      align-items: flex-start;
      gap: 10px;
    }
    .analytics-review-form input[type="checkbox"] {
      flex: 0 0 auto;
      margin: 4px 0 0;
      width: 17px;
      height: 17px;
    }
    .analytics-review-form input:not([type="checkbox"]),
    .analytics-review-form select,
    .analytics-review-form textarea {
      box-sizing: border-box;
      width: 100%;
      max-width: 100%;
      min-width: 0;
      padding: 10px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      background: #fff;
      color: #172336;
      font: inherit;
    }
    .analytics-review-form label:has(textarea),
    .analytics-review-form > button,
    .analytics-review-form > p[role="status"] {
      grid-column: 1 / -1;
    }
    .analytics-review-form textarea {
      min-height: 90px;
      resize: vertical;
    }
    .analytics-review-form > button {
      justify-self: start;
    }
    @media (max-width: 700px) {
      .analytics-review-form {
        grid-template-columns: minmax(0, 1fr);
      }
    }
  </style>`;

  async function showDiagnostics(after = '') {
    window.closeAnalyticsTaskPolicy?.();
    const requestEpoch = ++epoch;
    try {
      const data = await api(`${diagnostics}?after=${encodeURIComponent(after)}`);
      if (requestEpoch !== epoch) return;
      panel().innerHTML = `<section class="filter-card clients-catalog"><button type="button" id="diagnosticsBack" class="updates-action-btn">К задачам</button><h2>Аналитические сигналы</h2>${analyticsReviewStyle}<button type="button" class="updates-action-btn" id="analyticsPolicy">Политика автоматизации</button><p>Режим: ${esc(data.mode)}. Сопоставление клиента само по себе не создаёт задачу.</p><p>Получено: ${data.summary.received} · Прошли проверки: ${data.summary.passed || 0} · На проверке: ${data.summary.review || 0} · Заблокировано: ${data.summary.blocked || 0} · Создано задач: ${data.summary.created_tasks} · Уже существовали: ${data.summary.existing_tasks || 0} · Без менеджера: ${data.summary.without_eligible_manager || 0} · Выполненных задач: ${data.completed_analytics_tasks} · Отклонённых событий: ${data.summary.rejected_events}</p><details><summary>Отклонённые доставки</summary><ul>${(data.rejections || []).map(row=>`<li>${esc(row.signal_id)} · ${esc(row.reason_code)} · ${esc(row.created_at)}</li>`).join('')}</ul></details>${data.items.map(row => `<article class="client-catalog-row"><strong>${esc(row.subject?.key)} · ${esc(row.signal_type)} · ${esc(row.decision)}</strong><span>Сигнал: ${esc(row.signal_id)} · Срез: ${esc(row.as_of)} · Последняя покупка: ${esc(row.last_purchase)} · Confidence: ${esc(row.confidence)}</span><details><summary>Обоснование расчёта</summary><pre style="white-space:pre-wrap">${esc(JSON.stringify(row.metrics,null,2))}</pre></details><p>${esc(row.reasons_json)}</p><button type="button" class="updates-action-btn" data-dry-run="${esc(row.signal_key)}">Dry-run: проверить без задач</button>${row.task_id ? `<button type="button" class="updates-action-btn" data-linked-task="${esc(row.task_id)}">Задача</button>` : ''}${candidateView(row)}${row.subject?.kind === 'normalized_name' ? `<button type="button" class="updates-action-btn" data-group-review="${esc(row.signal_key)}">Проверить группу карточек и историю</button>` : ''}<details><summary>Идентификация клиента</summary><form class="analytics-review-form" data-binding="${esc(row.signal_key)}"><label>ClientsVR client_id<input name="client_id" value="${esc(row.binding?.client_id || '')}"></label><label>Статус<select name="binding_status">${options({REVIEW:'На проверке',AMBIGUOUS:'Неоднозначно',NOT_FOUND:'Не найден'},row.binding?.status || 'REVIEW')}</select></label><label><input type="checkbox" name="technical" ${Number(row.binding?.technical) ? 'checked' : ''}> Технический/агрегатный покупатель</label><label><input type="checkbox" name="confirm_nontechnical"> Проверено: это не технический и не агрегатный покупатель</label><label><input type="checkbox" name="confirm_all_sales_scope"> Проверена вся группа исторических продаж; совпадение имени или телефона само по себе не является подтверждением</label><label>Основание проверки<textarea name="evidence" required maxlength="2000">${esc(row.binding?.evidence || '')}</textarea></label><input type="hidden" name="revision" value="${esc(row.binding?.revision || '')}"><button type="submit" class="updates-action-btn">Сохранить и проверить без создания задач</button><p role="status"></p></form></details></article>`).join('')}<button type="button" id="diagnosticsNext" ${data.next_after ? '' : 'disabled'}>Далее</button></section>`;
      panel().querySelectorAll('[data-candidate-id]').forEach(button => {
        button.onclick = () => {
          const form = [...panel().querySelectorAll('[data-binding]')]
            .find(item => item.dataset.binding === button.dataset.candidateSignal);

          if (!form) return;

          form.elements.namedItem('client_id').value = button.dataset.candidateId;
          form.elements.namedItem('binding_status').value = 'REVIEW';

          const message = button.closest('.analytics-candidate-box')
            ?.querySelector('.analytics-candidate-message');

          if (message) {
            message.textContent = 'ID подставлен. Статус: На проверке. Данные не сохранены.';
          }
        };
      });
      panel().querySelector('#diagnosticsBack').onclick = showList;
      panel().querySelector('#analyticsPolicy').onclick = () => window.showAnalyticsTaskPolicy(() => showDiagnostics(after));
      panel().querySelectorAll('[data-dry-run]').forEach(button => button.onclick = async () => {
        try {await api(diagnostics,'POST',{signal_key:button.dataset.dryRun,action:'dry_run'});showDiagnostics(after);}catch(error){button.insertAdjacentText('afterend',error.message);}
      });
      panel().querySelector('#diagnosticsNext').onclick = () => showDiagnostics(data.next_after);
      panel().querySelectorAll('[data-linked-task]').forEach(button => button.onclick = () => showTask(button.dataset.linkedTask));
      panel().querySelectorAll('[data-group-review]').forEach(button => button.onclick = () => showVerifiedReview(button.dataset.groupReview));
      panel().querySelectorAll('[data-binding]').forEach(form => form.onsubmit = async event => {
        event.preventDefault();const values = new FormData(form);
        const body = {signal_key:form.dataset.binding,client_id:values.get('client_id') || null,binding_status:values.get('binding_status'),technical:values.has('technical'),confirm_nontechnical:values.has('confirm_nontechnical'),confirm_all_sales_scope:values.has('confirm_all_sales_scope'),evidence:values.get('evidence'),expected_revision:values.get('revision') ? Number(values.get('revision')) : null};
        try {await api(diagnostics,'POST',body);showDiagnostics(after);}catch(error){form.querySelector('[role="status"]').textContent = error.message;}
      });
    } catch (error) {if (requestEpoch === epoch) panel().textContent = error.message;}
  }
  async function showVerifiedReview(signalKey) {
    const requestEpoch = ++epoch;
    const reviewUrl = '/vr/calltrack/api/analytics_verified_review.php';
    panel().textContent = 'Загрузка всей группы…';
    try {
      const data = await api(`${reviewUrl}?signal_key=${encodeURIComponent(signalKey)}`);
      if (requestEpoch !== epoch) return;
      const revision = Number(data.binding?.revision || 0);
      let review = {};try {review = JSON.parse(data.binding?.evidence || '{}');} catch {}
      panel().innerHTML = `<section class="filter-card"><button id="reviewBack">Назад</button><h2>Ручная проверка покупателя</h2><p>Этап: ${esc(review.stage || data.group.stage)} · Версия: ${revision} · Автор: ${esc(review.reviewer_id || 'не подтверждён')}</p><p>Совпадение имени или телефона не подтверждает идентичность. Проверьте все карточки и всю историю продаж. Подтверждение не создаёт задачу.</p><p>Блокеры: ${esc(data.group.reasons.join(', ') || 'нет конфликтов менеджера; требуется ручная проверка')}</p><div class="clients-list">${data.group.cards.map(c=>`<article class="client-catalog-row"><strong>${esc(c.name)}</strong><span>ClientsVR ID: ${esc(c.client_id)} · ${c.technical ? 'Техническая карточка без менеджера' : 'Менеджер: ' + esc(c.manager) + ' (ID ' + esc(c.manager_id) + ')'}${c.archived ? ' · Архивная' : ''}</span><span>Телефоны: ${esc((c.phones || []).join(', ') || 'не указаны')}</span></article>`).join('')}</div><p>Продаж: ${esc(data.history.sale_count)} · ${esc(data.history.first_purchase)} — ${esc(data.history.last_purchase)}</p><button id="loadReviewHistory">Загрузить историю с начала</button><div id="reviewHistory"></div><form id="verifiedReviewForm"><label>Конкретная рабочая карточка<select name="client_id"><option value="">Выберите явно</option>${data.group.cards.filter(c=>!c.technical&&!c.archived).map(c=>`<option value="${esc(c.client_id)}">${esc(c.name)} · ${esc(c.client_id)} · менеджер ${esc(c.manager_id)}</option>`).join('')}</select></label><label>Основание проверки<textarea name="reason" required maxlength="2000">${esc(review.reason || '')}</textarea></label><label><input type="checkbox" name="confirm_identity"> Личность покупателя проверена независимо от совпадения имени/телефона</label><label><input type="checkbox" name="confirm_all_history"> Проверена вся группа исторических продаж</label><button type="button" data-review-action="begin">Начать ручную проверку</button><button type="submit">Подтвердить проверенную карточку</button><button type="button" data-review-action="revoke">Отозвать подтверждение</button><button type="button" data-review-action="export">Получить подписанную квитанцию</button><p role="status"></p></form><pre id="reviewReceipt"></pre></section>`;
      panel().querySelector('#reviewBack').onclick = () => showDiagnostics();
      let after = 0;
      const loadHistory = async () => {
        try {
          const result = await api(`${reviewUrl}?signal_key=${encodeURIComponent(signalKey)}&action=history_rows&after=${after}`);
          if (requestEpoch !== epoch) return;
          const pre = document.createElement('pre'); pre.textContent = JSON.stringify(result.history.items,null,2);
          panel().querySelector('#reviewHistory').append(pre);
          after = result.history.next_after;
          const button = panel().querySelector('#loadReviewHistory');
          button.disabled = after === null; button.textContent = result.all_history_loaded ? 'Вся история загружена' : 'Следующая страница';
        } catch(error) {panel().querySelector('[role="status"]').textContent=error.message;}
      };
      panel().querySelector('#loadReviewHistory').onclick = loadHistory;
      const form = panel().querySelector('#verifiedReviewForm');
      const save = async action => {
        const values = new FormData(form);
        try {
          const result = await api(reviewUrl,'POST',{signal_key:signalKey,action,expected_revision:revision,client_id:values.get('client_id'),reason:values.get('reason'),confirm_identity:values.has('confirm_identity'),confirm_all_history:values.has('confirm_all_history')});
          if (requestEpoch !== epoch) return;
          if(action==='export')panel().querySelector('#reviewReceipt').textContent=JSON.stringify(result.receipt,null,2);
          else await showVerifiedReview(signalKey);
        } catch(error) {form.querySelector('[role="status"]').textContent=error.message;}
      };
      form.onsubmit = event => {event.preventDefault();save('confirm');};
      form.querySelectorAll('[data-review-action]').forEach(button=>button.onclick=()=>save(button.dataset.reviewAction));
    } catch(error) {if(requestEpoch===epoch)panel().textContent=error.message;}
  }
  window.closeManagerTasks = () => { ++epoch;window.closeAnalyticsTaskPolicy?.(); };
  window.openManagerTasks = account => { if(user?.id!==account?.id){manager='';type='';completed=false;status='';}user = account;pageAfter = '';showList(); };
})();
