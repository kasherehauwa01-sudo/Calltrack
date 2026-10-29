/* Explicit source aliases, separate from user name and credentials. Admin API enforces ACL. */
(() => {
  let generation = 0;
  const endpoint = '/vr/calltrack/api/manager_bindings.php';
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const api = (method = 'GET', body) => window.calltrackApi.requestJson(endpoint, {
    method, credentials: 'same-origin', cache: 'no-store',
    ...(body ? {headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)} : {})
  });
  window.loadManagerBindings = async userId => {
    const version = ++generation;
    let panel = document.getElementById('clientsVrManagerBindings');
    if (!panel) {
      panel = document.createElement('section'); panel.id = 'clientsVrManagerBindings';
      document.getElementById('webUserForm').insertAdjacentElement('afterend', panel);
    }
    panel.innerHTML = '<h3>Сопоставление с ClientsVR</h3><p role="status">Загрузка…</p>';
    if (!userId) { panel.innerHTML = '<h3>Сопоставление с ClientsVR</h3><p>Сначала сохраните пользователя.</p>'; return; }
    try {
      const data = await api(); if (version !== generation) return;
      const bindings = data.bindings || [];
      const own = bindings.filter(row => String(row.web_user_id) === String(userId));
      const available = (data.designations || []).filter(row => !bindings.some(binding => binding.designation_key === row.designation_key));
      panel.innerHTML = `<h3>Сопоставление с ClientsVR</h3>
        <p>Количество карточек — по локальному кэшу. Совпадение ФИО — подсказка, привязка сохраняется только вручную.</p>
        <ul>${own.map(row => `<li>${escape(row.designation)} <button type="button" class="updates-action-btn" data-remove-binding="${escape(row.designation_key)}">Удалить</button></li>`).join('') || '<li>Привязок нет</li>'}</ul>
        <label>Не привязанные обозначения (${available.length})<select id="managerBindingDesignation"><option value="">Выберите обозначение</option>${available.map(row => `<option value="${escape(row.designation_key)}">${escape(row.designation)} — ${row.cached_card_count} карточек${(row.suggested_user_ids || []).includes(Number(userId)) ? ' (совпадение ФИО)' : ''}</option>`).join('')}</select></label>
        <button type="button" class="updates-action-btn" id="managerBindingAdd">Привязать к пользователю</button>
        <p role="status" id="managerBindingStatus"></p>
        <details><summary>Все привязки (${bindings.length})</summary><ul>${bindings.map(row => `<li>${escape(row.designation)} → ${escape(row.display_name || 'пользователь недоступен')}${Number(row.is_active) ? '' : ' (неактивен)'}${String(row.web_user_id) !== String(userId) ? ` <button type="button" class="updates-action-btn" data-transfer-binding="${escape(row.designation_key)}">Переназначить этому пользователю</button>` : ''}</li>`).join('')}</ul></details>`;
      const mutate = async (method, row) => {
        const status = panel.querySelector('#managerBindingStatus');
        try {
          await api(method, {designation: row.designation, web_user_id: Number(userId), expected_revision: row.revision ? Number(row.revision) : null});
          await window.loadManagerBindings(userId);
        } catch (error) { status.textContent = error.message; }
      };
      panel.querySelector('#managerBindingAdd').addEventListener('click', () => {
        const selected = panel.querySelector('#managerBindingDesignation').value;
        const row = available.find(item => item.designation_key === selected);
        if (row) mutate('POST', row);
      });
      panel.querySelectorAll('[data-transfer-binding]').forEach(button => button.addEventListener('click', () => {
        const row = bindings.find(item => item.designation_key === button.dataset.transferBinding);
        if(row && confirm(`Переназначить «${row.designation}» от ${row.display_name} текущему пользователю?`)) mutate('POST',row);
      }));
      panel.querySelectorAll('[data-remove-binding]').forEach(button => button.addEventListener('click', () => {
        const row = own.find(item => item.designation_key === button.dataset.removeBinding);
        if (row && confirm(`Удалить привязку «${row.designation}»?`)) mutate('DELETE', row);
      }));
    } catch (error) { if (version === generation) panel.innerHTML = `<h3>Сопоставление с ClientsVR</h3><p role="alert">${escape(error.message)}</p>`; }
  };
})();
