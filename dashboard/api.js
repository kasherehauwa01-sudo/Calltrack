const API_BASE = '../api/';
const state = { rows: [], personalContacts: [], chart: null, selected: new Set(), personalContactsLoaded: false };

const $ = (id) => document.getElementById(id);
const statusEl = $('status');
const bodyEl = $('callsBody');
const deleteSelectedBtn = $('deleteSelectedBtn');
const personalContactsBodyEl = $('personalContactsBody');
const personalContactsStatusEl = $('personalContactsStatus');
const clientTestPhoneEl = $('clientTestPhone');
const testClientsBtn = $('testClientsBtn');
const clientTestStatusEl = $('clientTestStatus');
const clientTestModalEl = $('clientTestModal');
const clientTestResultTitleEl = $('clientTestResultTitle');
const clientTestResultEl = $('clientTestResult');

function setStatus(message) { statusEl.textContent = message || ''; }
function setPersonalContactsStatus(message) { personalContactsStatusEl.textContent = message || ''; }
function esc(value) {
  return String(value ?? '').replace(/[&<>'"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[ch]));
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
  return Array.isArray(payload.data) ? payload.data : [];
}
async function getPersonalContacts() {
  const payload = await requestJson('get_personal_contacts.php');
  return Array.isArray(payload.data) ? payload.data : [];
}
async function testClientPhone(phone) {
  return requestJson('test_clients.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
    body: JSON.stringify({ phone })
  });
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
  rows.forEach((row) => { byDay[row.call_date || 'Без даты'] = (byDay[row.call_date || 'Без даты'] || 0) + 1; });
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
      <td>${esc(row.call_date)}</td>
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
    personalContactsBodyEl.innerHTML = '<tr><td colspan="7" class="empty">Нет данных</td></tr>';
    return;
  }

  personalContactsBodyEl.innerHTML = rows.map((row) => {
    const isPersonal = Number(row.personal_flag) === 1;
    return `
      <tr>
        <td>${esc(row.id_db)}</td><td>${esc(row.user_phone)}</td><td>${esc(row.manager)}</td><td>${esc(row.contact_phone)}</td>
        <td><span class="badge ${isPersonal ? 'yes' : 'no'}">${isPersonal ? 'Личный' : 'Рабочий'}</span></td>
        <td>${esc(row.created_at)}</td><td>${esc(row.updated_at)}</td>
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

function closeClientTestModal() {
  clientTestModalEl.hidden = true;
  document.body.style.overflow = '';
  testClientsBtn.focus();
}

function showClientTestResult(result) {
  const rows = Array.isArray(result.data) ? result.data : [];
  if (result.found && rows.length) {
    clientTestResultTitleEl.textContent = 'Номер найден в clients';
    clientTestResultEl.innerHTML = `
      <div class="tableWrap">
        <table>
          <thead><tr><th>Номер телефона</th><th>Наименование</th></tr></thead>
          <tbody>${rows.map((row) => `<tr><td>${esc(row.phone)}</td><td>${esc(row.name)}</td></tr>`).join('')}</tbody>
        </table>
      </div>`;
  } else {
    clientTestResultTitleEl.textContent = 'Номер телефона не найден';
    clientTestResultEl.innerHTML = `
      <div class="resultReason">
        <strong>Номер телефона не найден.</strong>
        <span>Причина: ${esc(result.reason || 'неизвестная ошибка')}</span>
        ${result.normalized_phone ? `<div class="normalizedPhone">Нормализованный номер: ${esc(result.normalized_phone)}</div>` : ''}
      </div>`;
  }
  clientTestModalEl.hidden = false;
  document.body.style.overflow = 'hidden';
  $('closeClientTestModal').focus();
}

async function runClientsTest() {
  const phone = clientTestPhoneEl.value.trim();
  testClientsBtn.disabled = true;
  clientTestStatusEl.textContent = 'Запрос к API clients...';
  try {
    const result = await testClientPhone(phone);
    showClientTestResult(result);
    clientTestStatusEl.textContent = result.found ? `Номер ${result.normalized_phone} найден` : 'Номер не найден';
  } catch (error) {
    console.error(error);
    clientTestStatusEl.textContent = 'Тест не выполнен';
    showClientTestResult({ found: false, reason: error.message });
  } finally {
    testClientsBtn.disabled = false;
  }
}

function showTab(tabName) {
  const panels = {
    registry: 'registryPanel',
    personalContacts: 'personalContactsPanel',
    generalSettings: 'generalSettingsPanel'
  };
  const activeTab = panels[tabName] ? tabName : 'registry';
  Object.entries(panels).forEach(([name, panelId]) => {
    $(panelId).hidden = name !== activeTab;
  });
  document.querySelectorAll('.tabBtn').forEach((button) => {
    button.classList.toggle('active', button.dataset.tab === activeTab);
  });
  if (activeTab === 'personalContacts' && !state.personalContactsLoaded) {
    loadPersonalContacts().catch((error) => { console.error(error); setPersonalContactsStatus(`Ошибка: ${error.message}`); alert(error.message); });
  }
}

async function loadData() {
  setStatus('Загрузка...');
  const rows = await getCalls({ period: $('period').value, manager: $('manager').value, phone: $('phone').value, user_phone: $('userPhone').value });
  state.rows = rows;
  render(rows);
  setStatus(`Загружено: ${rows.length}`);
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.tabBtn').forEach((button) => {
    button.addEventListener('click', () => showTab(button.dataset.tab));
  });
  $('loadBtn').addEventListener('click', () => loadData().catch((error) => { console.error(error); setStatus(`Ошибка: ${error.message}`); alert(error.message); }));
  $('loadPersonalContactsBtn').addEventListener('click', () => loadPersonalContacts().catch((error) => { console.error(error); setPersonalContactsStatus(`Ошибка: ${error.message}`); alert(error.message); }));
  testClientsBtn.addEventListener('click', runClientsTest);
  clientTestPhoneEl.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') runClientsTest();
  });
  $('closeClientTestModal').addEventListener('click', closeClientTestModal);
  clientTestModalEl.addEventListener('click', (event) => {
    if (event.target.hasAttribute('data-close-client-test')) closeClientTestModal();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !clientTestModalEl.hidden) closeClientTestModal();
  });
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
