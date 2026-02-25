(() => {
  const API_URL = 'mini%20apps/Task%20Club/task_access_store.php';
  const tcShellEl = document.querySelector('.tc-shell');
  const csrfToken = tcShellEl instanceof HTMLElement
    ? String(tcShellEl.dataset.tcCsrf || '').trim()
    : '';

  const userSelectEl = document.getElementById('tc-task-access-user-select');
  const treeEl = document.getElementById('tc-task-access-tree');
  const saveBtnEl = document.getElementById('tc-task-access-save');
  const statusEl = document.getElementById('tc-task-access-status');
  if (
    !(userSelectEl instanceof HTMLSelectElement)
    || !(treeEl instanceof HTMLElement)
    || !(saveBtnEl instanceof HTMLButtonElement)
    || !(statusEl instanceof HTMLElement)
  ) {
    return;
  }

  const paneLabelMap = {
    control: 'کنترل پنل',
    quiz: 'کوییز',
    information: 'اطلاعات',
    photo: 'عکس‌ها',
    'invitees-rate': 'امتیازدهی',
    'challenge-storage': 'مخزن چالش',
    team: 'تیم'
  };

  const state = {
    users: [],
    tasks: [],
    accessByUser: {},
    currentUserCode: ''
  };

  function normalizeToken(value) {
    const token = String(value ?? '').trim().toLowerCase();
    return token;
  }

  function normalizeBool(value) {
    if (typeof value === 'boolean') return value;
    if (typeof value === 'number') return value === 1;
    const token = normalizeToken(value);
    return token === '1' || token === 'true' || token === 'on' || token === 'yes';
  }

  function setStatus(message, isError = false) {
    statusEl.textContent = String(message || '').trim();
    statusEl.style.color = isError ? '#d1434a' : '';
  }

  async function requestGet(action, params = {}) {
    const url = new URL(API_URL, window.location.href);
    url.searchParams.set('action', String(action || '').trim());
    Object.entries(params || {}).forEach(([key, value]) => {
      if (value === null || value === undefined) return;
      url.searchParams.set(String(key), String(value));
    });
    const response = await fetch(url.toString(), { credentials: 'same-origin' });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload?.status !== 'ok') {
      throw new Error(payload?.message || 'Request failed.');
    }
    return payload?.data && typeof payload.data === 'object' ? payload.data : {};
  }

  async function requestPost(action, payload = {}) {
    const response = await fetch(`${API_URL}?action=${encodeURIComponent(String(action || '').trim())}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ ...payload, csrf: csrfToken })
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data?.status !== 'ok') {
      throw new Error(data?.message || 'Request failed.');
    }
    return data;
  }

  function renderUserOptions() {
    const options = Array.isArray(state.users) ? state.users : [];
    if (!options.length) {
      userSelectEl.innerHTML = '<option value="">کاربری یافت نشد</option>';
      userSelectEl.disabled = true;
      return;
    }
    const previous = String(userSelectEl.value || '').trim();
    userSelectEl.innerHTML = options.map((user) => {
      const code = String(user?.code || '').trim();
      const fullName = String(user?.fullName || '').trim() || String(user?.username || '').trim() || code;
      const username = String(user?.username || '').trim();
      const subtitle = username && username !== fullName ? ` - ${username}` : '';
      return `<option value="${escapeHtml(code)}">${escapeHtml(`${fullName} (${code}${subtitle})`)}</option>`;
    }).join('');
    const match = options.find((item) => String(item?.code || '').trim() === previous);
    if (match) {
      userSelectEl.value = previous;
    } else {
      const preferred = options.find((item) => (
        normalizeToken(String(item?.code || '')) === normalizeToken(state.currentUserCode)
      ));
      userSelectEl.value = String(preferred?.code || options[0]?.code || '').trim();
    }
    userSelectEl.disabled = false;
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function getSelectedUserKey() {
    return normalizeToken(String(userSelectEl.value || '').trim());
  }

  function getUserRules() {
    const key = getSelectedUserKey();
    const userAccess = state.accessByUser?.[key];
    const tasks = userAccess && typeof userAccess === 'object' && userAccess.tasks && typeof userAccess.tasks === 'object'
      ? userAccess.tasks
      : {};
    return tasks;
  }

  function resolveTaskRule(task, userRules) {
    const taskId = String(task?.id || '').trim();
    const paneKeys = Array.isArray(task?.paneKeys) ? task.paneKeys : [];
    const rawRule = userRules?.[taskId];
    const rule = rawRule && typeof rawRule === 'object' ? rawRule : {};
    const enabled = normalizeBool(rule.enabled ?? true);
    const rawPanes = rule.panes && typeof rule.panes === 'object' ? rule.panes : {};
    const panes = {};
    paneKeys.forEach((paneKey) => {
      panes[paneKey] = normalizeBool(rawPanes[paneKey] ?? true);
    });
    return { enabled, panes };
  }

  function renderTree() {
    const tasks = Array.isArray(state.tasks) ? state.tasks : [];
    if (!tasks.length) {
      treeEl.innerHTML = '<div class="muted">هیچ تسکی ثبت نشده است.</div>';
      return;
    }
    const userRules = getUserRules();
    treeEl.innerHTML = tasks.map((task) => {
      const taskId = String(task?.id || '').trim();
      const title = String(task?.title || '').trim() || String(task?.tagCode || '').trim() || taskId;
      const order = Number.parseInt(String(task?.order || '0'), 10);
      const paneKeys = Array.isArray(task?.paneKeys) ? task.paneKeys : [];
      const rule = resolveTaskRule(task, userRules);
      const parentChecked = rule.enabled ? ' checked' : '';
      const children = paneKeys.map((paneKey) => {
        const paneChecked = rule.panes[paneKey] ? ' checked' : '';
        const paneDisabled = rule.enabled ? '' : ' disabled';
        const paneLabel = paneLabelMap[paneKey] || paneKey;
        return `<label class="tc-task-access-item tc-task-access-child">
          <input type="checkbox" data-role="pane" data-pane-key="${escapeHtml(paneKey)}"${paneChecked}${paneDisabled} />
          <span>${escapeHtml(paneLabel)}</span>
        </label>`;
      }).join('');
      return `<section class="tc-task-access-group" data-task-id="${escapeHtml(taskId)}">
        <label class="tc-task-access-item tc-task-access-parent">
          <input type="checkbox" data-role="task"${parentChecked} />
          <span>${escapeHtml(`${Number.isFinite(order) && order > 0 ? `${order}. ` : ''}${title}`)}</span>
        </label>
        <div class="tc-task-access-children">
          ${children}
        </div>
      </section>`;
    }).join('');
  }

  function collectRulesFromTree() {
    const rules = {};
    const groups = treeEl.querySelectorAll('.tc-task-access-group[data-task-id]');
    groups.forEach((group) => {
      if (!(group instanceof HTMLElement)) return;
      const taskId = String(group.dataset.taskId || '').trim();
      if (!taskId) return;
      const parentInput = group.querySelector('input[type="checkbox"][data-role="task"]');
      const enabled = parentInput instanceof HTMLInputElement ? parentInput.checked : true;
      const panes = {};
      group.querySelectorAll('input[type="checkbox"][data-role="pane"][data-pane-key]').forEach((input) => {
        if (!(input instanceof HTMLInputElement)) return;
        const paneKey = String(input.dataset.paneKey || '').trim();
        if (!paneKey) return;
        panes[paneKey] = input.checked;
      });
      rules[taskId] = { enabled, panes };
    });
    return rules;
  }

  function onTreeChange(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) || target.type !== 'checkbox') return;
    const role = String(target.dataset.role || '').trim();
    if (role !== 'task') return;
    const group = target.closest('.tc-task-access-group[data-task-id]');
    if (!(group instanceof HTMLElement)) return;
    group.querySelectorAll('input[type="checkbox"][data-role="pane"]').forEach((input) => {
      if (!(input instanceof HTMLInputElement)) return;
      input.disabled = !target.checked;
    });
  }

  async function saveCurrentUserRules() {
    const selectedUserCode = String(userSelectEl.value || '').trim();
    if (!selectedUserCode) {
      setStatus('ابتدا یک کاربر انتخاب کنید.', true);
      return;
    }
    const rules = collectRulesFromTree();
    saveBtnEl.disabled = true;
    setStatus('در حال ذخیره...');
    try {
      const response = await requestPost('save_user_access', {
        userCode: selectedUserCode,
        rules
      });
      const key = normalizeToken(selectedUserCode);
      const normalizedRules = response?.data?.rules && typeof response.data.rules === 'object'
        ? response.data.rules
        : rules;
      state.accessByUser[key] = { tasks: normalizedRules };
      setStatus(response?.message || 'دسترسی‌ها ذخیره شد.');
      renderTree();
    } catch (error) {
      setStatus(error?.message || 'ذخیره دسترسی‌ها ناموفق بود.', true);
    } finally {
      saveBtnEl.disabled = false;
    }
  }

  async function bootstrap() {
    setStatus('در حال بارگذاری...');
    try {
      const data = await requestGet('bootstrap');
      state.currentUserCode = String(data?.currentUserCode || '').trim();
      state.users = Array.isArray(data?.users) ? data.users : [];
      state.tasks = Array.isArray(data?.tasks) ? data.tasks : [];
      state.accessByUser = data?.access && typeof data.access === 'object' ? data.access : {};
      renderUserOptions();
      renderTree();
      setStatus('');
    } catch (error) {
      setStatus(error?.message || 'بارگذاری اطلاعات دسترسی تسک‌ها ناموفق بود.', true);
      treeEl.innerHTML = '';
    }
  }

  userSelectEl.addEventListener('change', () => {
    renderTree();
    setStatus('');
  });
  treeEl.addEventListener('change', onTreeChange);
  saveBtnEl.addEventListener('click', () => {
    void saveCurrentUserRules();
  });
  window.addEventListener('tcTasksChanged', () => {
    void bootstrap();
  });

  void bootstrap();
})();
