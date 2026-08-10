
// Общие API-методы дашборда используются встроенным скриптом админ-панели.
window.calltrackApi = window.calltrackApi || {};
window.calltrackApi.endpoints = Object.assign({
  calls: '/vr/calltrack/api/get_calls.php',
  personalContacts: '/vr/calltrack/api/get_personal_contacts.php',
  personalContact: '/vr/calltrack/api/personal_contact.php',
  deleteCalls: '/vr/calltrack/api/delete_calls.php',
  deletePersonalContacts: '/vr/calltrack/api/delete_personal_contacts.php',
  updates: '/vr/calltrack/api/admin_updates.php',
  email: '/vr/calltrack/api/admin_email.php',
  update: '/vr/calltrack/api/update.php',
  users: '/vr/calltrack/api/get_users.php',
  userCommand: '/vr/calltrack/api/user_command.php'
}, window.calltrackApi.endpoints || {});
window.calltrackApi.requestJson = async function requestJson(url, options = {}) {
  const response = await fetch(url, options);
  const text = await response.text();

  let payload = {};

  try {
    payload = text ? JSON.parse(text) : {};
  } catch (e) {
    console.error("BAD JSON:", text);
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }
    throw new Error('API вернул некорректный JSON');
  }

  if (!response.ok) {
    throw new Error(payload?.message || `HTTP ${response.status}`);
  }

  if (payload && payload.status === 'error') {
    throw new Error(payload.message || 'API error');
  }

  return payload;
};

window.calltrackApi.loadCalls = window.calltrackApi.loadCalls || (async function loadDashboardCalls() {
  const separator = window.calltrackApi.endpoints.calls.includes('?') ? '&' : '?';
  return window.calltrackApi.requestJson(`${window.calltrackApi.endpoints.calls}${separator}period=all&limit=0`);
});

window.calltrackApi.getPersonalContacts = window.calltrackApi.getPersonalContacts || (async function getDashboardPersonalContacts() {
  const payload = await window.calltrackApi.requestJson(window.calltrackApi.endpoints.personalContacts);
  const rows = Array.isArray(payload.data) ? payload.data : [];
  return rows.map((row) => ({
    id_db: row.id_db || row.id || '',
    user_phone: row.user_phone || '',
    manager: row.manager || '',
    contact_phone: row.contact_phone || '',
    personal_flag: row.personal_flag ?? '',
    updated_at: row.updated_at || ''
  }));
});

window.calltrackApi.updatePersonalContactFlag = window.calltrackApi.updatePersonalContactFlag || (async function updateDashboardPersonalContactFlag(idDb, personalFlag) {
  return window.calltrackApi.requestJson(window.calltrackApi.endpoints.personalContact, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
    body: JSON.stringify({ id_db: idDb, personal_flag: personalFlag })
  });
});
window.calltrackApi.deletePersonalContacts = window.calltrackApi.deletePersonalContacts || (async function deleteDashboardPersonalContacts(ids) {
  return window.calltrackApi.requestJson(window.calltrackApi.endpoints.deletePersonalContacts, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
    body: JSON.stringify({ ids })
  });
});

window.calltrackApi.deleteCalls = window.calltrackApi.deleteCalls || (async function deleteDashboardCalls({ ids = [], call_ids = [] } = {}) {
  return window.calltrackApi.requestJson(window.calltrackApi.endpoints.deleteCalls, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
    body: JSON.stringify({ ids, call_ids })
  });
});

window.calltrackApi.getUpdates = window.calltrackApi.getUpdates || (async function getDashboardUpdates() {
  const payload = await window.calltrackApi.requestJson(window.calltrackApi.endpoints.updates);
  return Array.isArray(payload.data) ? payload.data : [];
});

window.calltrackApi.saveUpdate = window.calltrackApi.saveUpdate || (async function saveDashboardUpdate(formData) {
  return window.calltrackApi.requestJson(window.calltrackApi.endpoints.updates, {
    method: 'POST',
    body: formData
  });
});

window.calltrackApi.deleteUpdate = window.calltrackApi.deleteUpdate || (async function deleteDashboardUpdate(id) {
  return window.calltrackApi.requestJson(window.calltrackApi.endpoints.updates, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
    body: JSON.stringify({ action: 'delete', id })
  });
});

window.calltrackApi.getEmailMessages = window.calltrackApi.getEmailMessages || (async function getEmailMessages(params = {}) {
  const query = new URLSearchParams(params);
  const separator = window.calltrackApi.endpoints.email.includes('?') ? '&' : '?';
  const payload = await window.calltrackApi.requestJson(`${window.calltrackApi.endpoints.email}${query.toString() ? separator + query.toString() : ''}`);
  return Array.isArray(payload.data) ? payload.data : [];
});

window.calltrackApi.getEmailSettings = window.calltrackApi.getEmailSettings || (async function getEmailSettings() {
  const separator = window.calltrackApi.endpoints.email.includes('?') ? '&' : '?';
  const payload = await window.calltrackApi.requestJson(`${window.calltrackApi.endpoints.email}${separator}action=settings`);
  return Array.isArray(payload.data) ? payload.data : [];
});

window.calltrackApi.syncEmail = window.calltrackApi.syncEmail || (async function syncEmail() {
  const separator = window.calltrackApi.endpoints.email.includes('?') ? '&' : '?';
  const payload = await window.calltrackApi.requestJson(`${window.calltrackApi.endpoints.email}${separator}action=sync`);
  return payload.data || { imported: 0, mailboxes: 0, errors: [] };
});

window.calltrackApi.getEmailMessage = window.calltrackApi.getEmailMessage || (async function getEmailMessage(id) {
  const separator = window.calltrackApi.endpoints.email.includes('?') ? '&' : '?';
  const payload = await window.calltrackApi.requestJson(`${window.calltrackApi.endpoints.email}${separator}action=detail&id=${encodeURIComponent(id)}`);
  return payload.data || null;
});

window.calltrackApi.saveEmailSettings = window.calltrackApi.saveEmailSettings || (async function saveEmailSettings(data) {
  return window.calltrackApi.requestJson(window.calltrackApi.endpoints.email, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
    body: JSON.stringify(data)
  });
});

window.calltrackApi.deleteEmailSettings = window.calltrackApi.deleteEmailSettings || (async function deleteEmailSettings(id) {
  return window.calltrackApi.requestJson(window.calltrackApi.endpoints.email, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
    body: JSON.stringify({ action: 'delete', id })
  });
});

window.calltrackApi.getUsers = window.calltrackApi.getUsers || (async function getDashboardUsers() {
  const payload = await window.calltrackApi.requestJson(window.calltrackApi.endpoints.users);
  return Array.isArray(payload.data) ? payload.data : [];
});

window.calltrackApi.sendUserCommand = window.calltrackApi.sendUserCommand || (async function sendDashboardUserCommand(userPhone, command, meta = {}) {
  return window.calltrackApi.requestJson(window.calltrackApi.endpoints.userCommand, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
    body: JSON.stringify({ user_phone: userPhone, command, ...meta })
  });
});


const API_BASE = '../api/';
const state = { rows: [], total: null, personalContacts: [], chart: null, selected: new Set(), personalContactsLoaded: false };

const $ = (id) => document.getElementById(id);
const statusEl = $('status');
const bodyEl = $('callsBody');
const deleteSelectedBtn = $('deleteSelectedBtn');
const personalContactsBodyEl = $('personalContactsBody');
const personalContactsStatusEl = $('personalContactsStatus');

function setStatus(message) { statusEl.textContent = message || ''; }
function setPersonalContactsStatus(message) { personalContactsStatusEl.textContent = message || ''; }
function esc(value) {
  return String(value ?? '').replace(/[&<>'"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[ch]));
}
function formatDateDisplay(value) {
  if (!value) return '';
  const raw = String(value).trim();
  const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!match) return raw;
  return `${match[3]}.${match[2]}.${match[1]}`;
}
function formatDateTimeDisplay(value) {
  if (!value) return '';
  const date = formatDateDisplay(value);
  const time = String(value).match(/(?:T|\s)(\d{1,2}:\d{2}(?::\d{2})?)/);
  return time ? `${date} ${time[1]}` : date;
}
function query(params) {
  const sp = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && String(value).trim() !== '') sp.set(key, String(value).trim());
  });
  return sp.toString();
}
async function requestJson(endpoint, options = {}) {
  const response = await fetch(API_BASE + endpoint, options);
  const text = await response.text();
  let payload;
  try { payload = text ? JSON.parse(text) : {}; } catch (error) { throw new Error(`Некорректный JSON от ${endpoint}: ${text.slice(0, 200)}`); }
  if (!response.ok || payload.status === 'error') throw new Error(payload.message || `HTTP ${response.status}`);
  return payload;
}

async function getCalls(filters) {
  const payload = await requestJson(`get_calls.php?${query(filters)}`);
  state.total = Number.isFinite(Number(payload.total)) ? Number(payload.total) : null;
  return Array.isArray(payload.data) ? payload.data : [];
}
async function getPersonalContacts() {
  const payload = await requestJson('get_personal_contacts.php');
  return Array.isArray(payload.data) ? payload.data : [];
}
async function deleteCall(idDb) {
  return requestJson('delete_call.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
    body: JSON.stringify({ id_db: idDb })
  });
}
async function deleteCalls(ids) {
  return requestJson('delete_calls.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
    body: JSON.stringify({ ids })
  });
}
async function updatePersonalContactFlag(idDb, personalFlag) {
  return requestJson('personal_contact.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
    body: JSON.stringify({ id_db: idDb, personal_flag: personalFlag })
  });
}
async function deletePersonalContacts(ids) {
  return requestJson('delete_personal_contacts.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
    body: JSON.stringify({ ids })
  });
}

function durationSec(value) {
  const n = Number(value || 0);
  return Number.isFinite(n) ? n : 0;
}
function fmtSec(sec) {
  const m = Math.floor(sec / 60), s = sec % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}
function typeKind(type) {
  const text = String(type || '').toLowerCase();
  if (text.includes('вход')) return 'incoming';
  if (text.includes('исход')) return 'outgoing';
  return 'missed';
}
function renderKpi(rows) {
  let incoming = 0, outgoing = 0, missed = 0, totalSec = 0;
  rows.forEach((row) => {
    const kind = typeKind(row.call_type);
    if (kind === 'incoming') incoming++; else if (kind === 'outgoing') outgoing++; else missed++;
    totalSec += durationSec(row.duration);
  });
  const items = [
    ['📞', rows.length, 'Всего звонков'],
    ['📥', incoming, 'Входящие'],
    ['📤', outgoing, 'Исходящие'],
    ['❌', missed, 'Пропущенные'],
    ['⏱', fmtSec(rows.length ? Math.round(totalSec / rows.length) : 0), 'Средняя длительность']
  ];
  $('kpi').innerHTML = items.map(([icon, value, label]) => `<div class="card"><div class="val">${icon} ${value}</div><div class="label">${label}</div></div>`).join('');
}
function renderChart(rows) {
  const byDay = {};
  rows.forEach((row) => { byDay[formatDateDisplay(row.call_date) || 'Без даты'] = (byDay[formatDateDisplay(row.call_date) || 'Без даты'] || 0) + 1; });
  const labels = Object.keys(byDay).sort();
  if (state.chart) state.chart.destroy();
  state.chart = new Chart($('callsByDay'), {
    type: 'line',
    data: { labels, datasets: [{ label: 'Звонки', data: labels.map((day) => byDay[day]), borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.18)', fill: true, tension: .3 }] },
    options: { responsive: true, maintainAspectRatio: false }
  });
}
function renderTable(rows) {
  state.selected.clear();
  $('selectAll').checked = false;
  deleteSelectedBtn.disabled = true;
  bodyEl.innerHTML = rows.map((row) => `
    <tr data-id="${esc(row.id_db)}">
      <td><input type="checkbox" class="rowCheck" value="${esc(row.id_db)}" /></td>
      <td>${esc(row.id_db)}</td>
      <td>${esc(formatDateDisplay(row.call_date))}</td>
      <td>${esc(row.call_time)}</td>
      <td>${esc(row.phone)}</td>
      <td>${esc(row.call_type)}</td>
      <td>${esc(row.duration)}</td>
      <td>${esc(row.manager)}</td>
      <td>${esc(row.client)}</td>
      <td>${esc(row.comment)}</td>
      <td>${esc(row.tag)}</td>
      <td>${esc(row.reminder)}</td>
      <td>${esc(row.reminder_text)}</td>
      <td>${esc(row.call_id)}</td>
      <td>${esc(row.user_phone)}</td>
      <td><button class="danger deleteOne" data-id="${esc(row.id_db)}">Удалить</button></td>
    </tr>`).join('');
}
function render(rows) { renderKpi(rows); renderChart(rows); renderTable(rows); }
function renderPersonalContacts(rows) {
  if (!rows.length) {
    personalContactsBodyEl.innerHTML = '<tr><td colspan="6" class="empty">Нет данных</td></tr>';
    return;
  }

  personalContactsBodyEl.innerHTML = rows.map((row) => {
    const isPersonal = Number(row.personal_flag) === 1;
    return `
      <tr>
        <td>${esc(row.id_db)}</td><td>${esc(row.user_phone)}</td><td>${esc(row.manager)}</td><td>${esc(row.contact_phone)}</td>
        <td><span class="badge ${isPersonal ? 'yes' : 'no'}">${isPersonal ? 'Личный' : 'Рабочий'}</span></td>
        <td>${esc(formatDateTimeDisplay(row.updated_at))}</td>
      </tr>`;
  }).join('');
}

async function loadPersonalContacts() {
  setPersonalContactsStatus('Загрузка...');
  const rows = await getPersonalContacts();
  state.personalContacts = rows;
  state.personalContactsLoaded = true;
  renderPersonalContacts(rows);
  setPersonalContactsStatus(`Загружено: ${rows.length}`);
}

function showTab(tabName) {
  const isRegistry = tabName === 'registry';
  $('registryPanel').hidden = !isRegistry;
  $('personalContactsPanel').hidden = isRegistry;
  document.querySelectorAll('.tabBtn').forEach((button) => {
    button.classList.toggle('active', button.dataset.tab === tabName);
  });
  if (!isRegistry && !state.personalContactsLoaded) {
    loadPersonalContacts().catch((error) => { console.error(error); setPersonalContactsStatus(`Ошибка: ${error.message}`); alert(error.message); });
  }
}

async function loadData() {
  setStatus('Загрузка...');
  const rows = await getCalls({ period: $('period').value, manager: $('manager').value, phone: $('phone').value, user_phone: $('userPhone').value });
  state.rows = rows;
  render(rows);
  setStatus(state.total && state.total > rows.length ? `Всего: ${state.total}. Загружено: ${rows.length}` : `Загружено: ${rows.length}`);
}

document.addEventListener('DOMContentLoaded', () => {
  // Старый standalone-экран админки может отсутствовать на новой странице дашборда.
  if (!$('loadBtn') || !$('selectAll') || !bodyEl || !deleteSelectedBtn) return;
  document.querySelectorAll('.tabBtn').forEach((button) => {
    button.addEventListener('click', () => showTab(button.dataset.tab));
  });
  $('loadBtn').addEventListener('click', () => loadData().catch((error) => { console.error(error); setStatus(`Ошибка: ${error.message}`); alert(error.message); }));
  const loadPersonalContactsBtn = $('loadPersonalContactsBtn');
  if (loadPersonalContactsBtn) {
    loadPersonalContactsBtn.addEventListener('click', () => loadPersonalContacts().catch((error) => { console.error(error); setPersonalContactsStatus(`Ошибка: ${error.message}`); alert(error.message); }));
  }
  $('selectAll').addEventListener('change', (event) => {
    document.querySelectorAll('.rowCheck').forEach((checkbox) => {
      checkbox.checked = event.target.checked;
      if (checkbox.checked) state.selected.add(checkbox.value); else state.selected.delete(checkbox.value);
    });
    deleteSelectedBtn.disabled = state.selected.size === 0;
  });
  bodyEl.addEventListener('change', (event) => {
    if (!event.target.classList.contains('rowCheck')) return;
    if (event.target.checked) state.selected.add(event.target.value); else state.selected.delete(event.target.value);
    deleteSelectedBtn.disabled = state.selected.size === 0;
  });
  bodyEl.addEventListener('click', async (event) => {
    if (!event.target.classList.contains('deleteOne')) return;
    const id = event.target.dataset.id;
    if (!confirm(`Удалить звонок #${id}?`)) return;
    await deleteCall(id);
    await loadData();
  });
  deleteSelectedBtn.addEventListener('click', async () => {
    const ids = Array.from(state.selected);
    if (!ids.length || !confirm(`Удалить выбранные звонки: ${ids.length}?`)) return;
    await deleteCalls(ids);
    await loadData();
  });
  loadData().catch((error) => { console.error(error); setStatus(`Ошибка: ${error.message}`); });
});
