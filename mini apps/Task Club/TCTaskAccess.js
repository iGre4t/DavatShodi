(() => {
  const API_URL = 'mini%20apps/Task%20Club/task_access_store.php';
  const tcShellEl = document.querySelector('.tc-shell');
  const csrfToken = tcShellEl instanceof HTMLElement
    ? String(tcShellEl.dataset.tcCsrf || '').trim()
    : '';

  const usersBodyEl = document.getElementById('tc-task-access-users-body');
  const modalEl = document.getElementById('tc-task-access-modal');
  const modalTitleEl = document.getElementById('tc-task-access-modal-title');
  const manageTasksToggleEl = document.getElementById('tc-task-access-manage-tasks');
  const treeEl = document.getElementById('tc-task-access-tree');
  const saveBtnEl = document.getElementById('tc-task-access-save');
  const statusEl = document.getElementById('tc-task-access-status');
  const closeBtns = modalEl ? modalEl.querySelectorAll('[data-tc-task-access-close]') : [];

  if (
    !(usersBodyEl instanceof HTMLElement)
    || !(modalEl instanceof HTMLElement)
    || !(manageTasksToggleEl instanceof HTMLInputElement)
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
    currentUserCode: '',
    editingUserCode: ''
  };

  function normalizeToken(value) {
    return String(value ?? '').trim().toLowerCase();
  }

  function normalizeBool(value) {
    if (typeof value === 'boolean') return value;
    if (typeof value === 'number') return value === 1;
    const token = normalizeToken(value);
    return token === '1' || token === 'true' || token === 'on' || token === 'yes';
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function setStatus(message, isError = false) {
    statusEl.textContent = String(message || '').trim();
    statusEl.style.color = isError ? '#d1434a' : '';
  }

  function setUsersTableMessage(message, colspan = 5) {
    usersBodyEl.innerHTML = `<tr><td colspan="${colspan}" class="muted">${escapeHtml(message)}</td></tr>`;
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

  function findUserByCode(userCode) {
    const key = normalizeToken(userCode);
    return state.users.find((user) => normalizeToken(user?.code) === key) || null;
  }

  function getEditingUser() {
    if (!state.editingUserCode) return null;
    return findUserByCode(state.editingUserCode);
  }

  function getUserAccessEntry(userCode) {
    const key = normalizeToken(userCode);
    const entry = state.accessByUser?.[key];
    return entry && typeof entry === 'object' ? entry : {};
  }

  function getUserRules(userCode) {
    const entry = getUserAccessEntry(userCode);
    const tasks = entry.tasks;
    return tasks && typeof tasks === 'object' ? tasks : {};
  }

  function getUserManageTasksFlag(userCode) {
    const entry = getUserAccessEntry(userCode);
    return normalizeBool(entry.allowManageTasksTab ?? entry.allow_manage_tasks_tab ?? false);
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
    const editingUser = getEditingUser();
    if (!editingUser) {
      treeEl.innerHTML = '<div class="muted">کاربری انتخاب نشده است.</div>';
      return;
    }
    const tasks = Array.isArray(state.tasks) ? state.tasks : [];
    if (!tasks.length) {
      treeEl.innerHTML = '<div class="muted">هیچ تسکی ثبت نشده است.</div>';
      return;
    }
    const userRules = getUserRules(editingUser.code);
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

  function renderManageTasksToggle() {
    const editingUser = getEditingUser();
    const hasUser = Boolean(editingUser);
    manageTasksToggleEl.disabled = !hasUser;
    manageTasksToggleEl.checked = hasUser ? getUserManageTasksFlag(editingUser.code) : false;
  }

  function renderUsersTable() {
    const users = Array.isArray(state.users) ? state.users : [];
    if (!users.length) {
      setUsersTableMessage('کاربری با دسترسی باشگاه تعاملی پیدا نشد.');
      return;
    }
    usersBodyEl.innerHTML = users.map((user, index) => {
      const code = String(user?.code || '').trim();
      const fullName = String(user?.fullName || '').trim() || String(user?.username || '').trim() || code;
      const username = String(user?.username || '').trim() || '—';
      return `<tr>
        <td>${escapeHtml(String(index + 1))}</td>
        <td>${escapeHtml(fullName)}</td>
        <td><code>${escapeHtml(code)}</code></td>
        <td>${escapeHtml(username)}</td>
        <td>
          <button type="button" class="btn ghost" data-action="open-task-access" data-user-code="${escapeHtml(code)}">دسترسی‌ها</button>
        </td>
      </tr>`;
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

  function openModalForUser(userCode) {
    const user = findUserByCode(userCode);
    if (!user) {
      setUsersTableMessage('کاربر انتخاب‌شده یافت نشد.');
      return;
    }
    state.editingUserCode = normalizeToken(user.code);
    if (modalTitleEl instanceof HTMLElement) {
      const fullName = String(user.fullName || '').trim() || String(user.username || '').trim() || String(user.code || '');
      modalTitleEl.textContent = `دسترسی‌ها · ${fullName}`;
    }
    setStatus('');
    renderManageTasksToggle();
    renderTree();
    modalEl.classList.remove('hidden');
    modalEl.setAttribute('aria-hidden', 'false');
  }

  function closeModal() {
    modalEl.classList.add('hidden');
    modalEl.setAttribute('aria-hidden', 'true');
    state.editingUserCode = '';
    setStatus('');
    renderManageTasksToggle();
    renderTree();
    if (modalTitleEl instanceof HTMLElement) {
      modalTitleEl.textContent = 'دسترسی‌ها';
    }
  }

  async function saveCurrentUserRules() {
    const editingUser = getEditingUser();
    if (!editingUser) {
      setStatus('ابتدا یک کاربر انتخاب کنید.', true);
      return;
    }
    const rules = collectRulesFromTree();
    saveBtnEl.disabled = true;
    setStatus('در حال ذخیره...');
    try {
      const response = await requestPost('save_user_access', {
        userCode: String(editingUser.code || '').trim(),
        rules,
        allowManageTasksTab: manageTasksToggleEl.checked ? 1 : 0
      });
      const key = normalizeToken(editingUser.code);
      const normalizedRules = response?.data?.rules && typeof response.data.rules === 'object'
        ? response.data.rules
        : rules;
      const allowManageTasksTab = normalizeBool(response?.data?.allowManageTasksTab ?? manageTasksToggleEl.checked);
      state.accessByUser[key] = {
        allowManageTasksTab,
        tasks: normalizedRules
      };
      setStatus(response?.message || 'دسترسی‌ها ذخیره شد.');
      renderUsersTable();
      renderManageTasksToggle();
      renderTree();
    } catch (error) {
      setStatus(error?.message || 'ذخیره دسترسی‌ها ناموفق بود.', true);
    } finally {
      saveBtnEl.disabled = false;
    }
  }

  async function bootstrap() {
    setUsersTableMessage('در حال بارگذاری...');
    try {
      const data = await requestGet('bootstrap');
      state.currentUserCode = String(data?.currentUserCode || '').trim();
      state.users = Array.isArray(data?.users) ? data.users : [];
      state.tasks = Array.isArray(data?.tasks) ? data.tasks : [];
      state.accessByUser = data?.access && typeof data.access === 'object' ? data.access : {};
      renderUsersTable();
      if (!modalEl.classList.contains('hidden')) {
        const editingUser = getEditingUser();
        if (!editingUser) {
          closeModal();
        } else {
          renderManageTasksToggle();
          renderTree();
          setStatus('');
        }
      }
    } catch (error) {
      setUsersTableMessage('بارگذاری اطلاعات دسترسی تسک‌ها ناموفق بود.');
      if (!modalEl.classList.contains('hidden')) {
        setStatus(error?.message || 'بارگذاری اطلاعات دسترسی تسک‌ها ناموفق بود.', true);
      }
    }
  }

  usersBodyEl.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const trigger = target.closest('button[data-action="open-task-access"][data-user-code]');
    if (!(trigger instanceof HTMLButtonElement)) return;
    const userCode = String(trigger.dataset.userCode || '').trim();
    if (!userCode) return;
    openModalForUser(userCode);
  });

  closeBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      closeModal();
    });
  });

  modalEl.addEventListener('click', (event) => {
    if (event.target === modalEl) {
      closeModal();
    }
  });

  treeEl.addEventListener('change', onTreeChange);
  saveBtnEl.addEventListener('click', () => {
    void saveCurrentUserRules();
  });
  window.addEventListener('tcTasksChanged', () => {
    void bootstrap();
  });

  renderManageTasksToggle();
  renderTree();
  void bootstrap();
})();
