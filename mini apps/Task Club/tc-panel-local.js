(() => {
  const TASKS_ENDPOINT = 'mini%20apps/Task%20Club/TCT.php';

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function normalizeTaskType(value) {
    const token = String(value ?? '').trim().toLowerCase();
    if (token === 'quiz' || token === 'quiz-task' || token === 'quiz task') {
      return 'quiz';
    }
    return 'quiz';
  }

  function normalizeBool(value) {
    if (typeof value === 'boolean') return value;
    if (typeof value === 'number') return value === 1;
    const token = String(value ?? '').trim().toLowerCase();
    return token === '1' || token === 'true' || token === 'on' || token === 'yes';
  }

  function normalizeDate(value) {
    const token = String(value ?? '').trim();
    return /^\d{4}-\d{2}-\d{2}$/.test(token) ? token : '';
  }

  function normalizeTime(value) {
    const token = String(value ?? '').trim();
    const matched = token.match(/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/);
    if (!matched) return '';
    return `${matched[1]}:${matched[2]}`;
  }

  function normalizeScoreValue(value) {
    const parsed = Number.parseInt(String(value ?? '').trim(), 10);
    if (!Number.isFinite(parsed) || parsed < 0) {
      return 0;
    }
    return parsed;
  }

  function normalizeTask(task, index) {
    const raw = task && typeof task === 'object' ? task : {};
    const id = String(raw.id ?? '').trim();
    const title = String(raw.title ?? '').trim();
    const tagCode = String(raw.tagCode ?? raw.tag_code ?? '').trim().toUpperCase();
    const parsedOrder = Number.parseInt(raw.order, 10);
    return {
      id,
      title,
      tagCode,
      taskType: normalizeTaskType(raw.taskType ?? raw.task_type ?? 'quiz'),
      active: normalizeBool(raw.active),
      duration: normalizeBool(raw.duration),
      startDate: normalizeDate(raw.startDate ?? raw.start_date ?? ''),
      startTime: normalizeTime(raw.startTime ?? raw.start_time ?? ''),
      endDate: normalizeDate(raw.endDate ?? raw.end_date ?? ''),
      endTime: normalizeTime(raw.endTime ?? raw.end_time ?? ''),
      score: normalizeScoreValue(raw.score ?? raw.taskScore ?? 0),
      afterEndtimeScore: normalizeScoreValue(raw.afterEndtimeScore ?? raw.after_endtime_score ?? 0),
      order: Number.isFinite(parsedOrder) && parsedOrder > 0 ? parsedOrder : (index + 1)
    };
  }

  function buildTimeOptions(selected = '') {
    const selectedValue = normalizeTime(selected);
    const options = ['<option value="">Select time</option>'];
    for (let hour = 0; hour <= 23; hour += 1) {
      const value = `${String(hour).padStart(2, '0')}:00`;
      const isSelected = value === selectedValue ? ' selected' : '';
      options.push(`<option value="${value}"${isSelected}>${value}</option>`);
    }
    return options.join('');
  }

  function parseTimeToSeconds(value) {
    if (!value) return null;
    const normalized = String(value).trim();
    const parts = normalized.split(':').map((part) => Number(part));
    if (parts.length < 2 || parts.length > 3 || parts.some((n) => !Number.isFinite(n))) {
      return null;
    }
    const [hours, minutes, seconds = 0] = parts;
    return hours * 3600 + minutes * 60 + seconds;
  }

  function compareGregorianDates(a = '', b = '') {
    const left = normalizeDate(a);
    const right = normalizeDate(b);
    if (!left || !right) return null;
    if (left === right) return 0;
    return left > right ? 1 : -1;
  }

  function getCurrentLocalSeconds() {
    const now = new Date();
    return now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();
  }

  function getTehranDateTimeParts(date = new Date()) {
    try {
      const formatter = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Tehran',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
      });
      const parts = formatter.formatToParts(date);
      const year = parts.find((p) => p.type === 'year')?.value ?? '';
      const month = parts.find((p) => p.type === 'month')?.value ?? '';
      const day = parts.find((p) => p.type === 'day')?.value ?? '';
      const hour = parts.find((p) => p.type === 'hour')?.value ?? '00';
      const minute = parts.find((p) => p.type === 'minute')?.value ?? '00';
      return {
        date: `${year}-${month}-${day}`,
        time: `${hour}:${minute}`
      };
    } catch {
      const fallback = new Date();
      const year = String(fallback.getFullYear());
      const month = String(fallback.getMonth() + 1).padStart(2, '0');
      const day = String(fallback.getDate()).padStart(2, '0');
      const hour = String(fallback.getHours()).padStart(2, '0');
      const minute = String(fallback.getMinutes()).padStart(2, '0');
      return {
        date: `${year}-${month}-${day}`,
        time: `${hour}:${minute}`
      };
    }
  }

  function getCurrentTehranSeconds() {
    const parts = getTehranDateTimeParts();
    return parseTimeToSeconds(parts.time) ?? getCurrentLocalSeconds();
  }

  function describeSameDayDurationState(startTime, endTime) {
    const nowSeconds = getCurrentTehranSeconds();
    const startSeconds = parseTimeToSeconds(startTime);
    const endSeconds = parseTimeToSeconds(endTime);

    if (endSeconds !== null && nowSeconds >= endSeconds) {
      return 'Ended';
    }
    if (startSeconds !== null && nowSeconds >= startSeconds) {
      return 'Active';
    }
    if (startSeconds !== null && nowSeconds < startSeconds) {
      return 'Upcoming';
    }
    return 'Upcoming';
  }

  function deriveStatusFromSettings(settings) {
    const active = normalizeBool(settings.active);
    const duration = normalizeBool(settings.duration);
    const startDate = normalizeDate(settings.startDate);
    const startTime = normalizeTime(settings.startTime);
    const endDate = normalizeDate(settings.endDate);
    const endTime = normalizeTime(settings.endTime);

    if (duration) {
      const today = getTehranDateTimeParts().date;
      if (!startDate || !today) {
        return { label: 'Not Active', tone: 'inactive' };
      }

      const startRelation = compareGregorianDates(startDate, today);
      const endRelation = compareGregorianDates(endDate, today);

      if (startRelation === 1) {
        return { label: 'Upcoming', tone: 'upcoming' };
      }
      if (endRelation !== null && endRelation === -1) {
        return { label: 'Ended', tone: 'ended' };
      }

      if (startRelation === 0 || endRelation === 0) {
        const state = describeSameDayDurationState(startTime, endTime);
        if (state === 'Ended') return { label: 'Ended', tone: 'ended' };
        if (state === 'Active') return { label: 'Active', tone: 'active' };
        return { label: 'Upcoming', tone: 'upcoming' };
      }

      return { label: 'Active', tone: 'active' };
    }

    return active
      ? { label: 'Active', tone: 'active' }
      : { label: 'Not Active', tone: 'inactive' };
  }

  function toSafePanePart(value) {
    return String(value ?? '')
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9_-]+/g, '-')
      .replace(/-+/g, '-')
      .replace(/^-+|-+$/g, '');
  }

  function makeTaskPaneKey(task, index, usedKeys) {
    const part = toSafePanePart(task.tagCode || task.id || `task-${index + 1}`) || `task-${index + 1}`;
    let key = `tc-task-${part}`;
    let counter = 2;
    while (usedKeys.has(key)) {
      key = `tc-task-${part}-${counter}`;
      counter += 1;
    }
    usedKeys.add(key);
    return key;
  }

  function activatePane(layout, targetPane) {
    if (!(layout instanceof HTMLElement) || !targetPane) return;
    layout.querySelectorAll('.sub-nav .sub-item[data-pane]').forEach((item) => {
      if (!(item instanceof HTMLElement)) return;
      item.classList.toggle('active', item.dataset.pane === targetPane);
    });
    layout.querySelectorAll('.sub-pane[data-pane]').forEach((pane) => {
      if (!(pane instanceof HTMLElement)) return;
      pane.classList.toggle('active', pane.dataset.pane === targetPane);
    });
  }

  function ensureAnyActivePane(layout, preferredPane = '') {
    if (!(layout instanceof HTMLElement)) return;
    const navItems = Array.from(layout.querySelectorAll('.sub-nav .sub-item[data-pane]'));
    if (!navItems.length) return;
    const hasPreferred = preferredPane && navItems.some((item) => item.dataset.pane === preferredPane);
    if (hasPreferred) {
      activatePane(layout, preferredPane);
      return;
    }
    const currentActive = navItems.find((item) => item.classList.contains('active'));
    if (currentActive && currentActive.dataset.pane) {
      activatePane(layout, currentActive.dataset.pane);
      return;
    }
    const firstPane = navItems[0]?.dataset?.pane || '';
    if (firstPane) {
      activatePane(layout, firstPane);
    }
  }

  function findPaneByKey(layout, paneKey) {
    if (!(layout instanceof HTMLElement) || !paneKey) return null;
    const panes = layout.querySelectorAll('.sub-pane[data-pane]');
    for (const pane of panes) {
      if (!(pane instanceof HTMLElement)) continue;
      if (pane.dataset.pane === paneKey) {
        return pane;
      }
    }
    return null;
  }

  function activateTaskTopPane(taskPane, sectionKey) {
    if (!(taskPane instanceof HTMLElement) || !sectionKey) return;
    const shell = taskPane.querySelector('[data-task-top-shell]');
    if (!(shell instanceof HTMLElement)) return;
    shell.querySelectorAll('[data-task-top-trigger]').forEach((button) => {
      if (!(button instanceof HTMLElement)) return;
      button.classList.toggle('active', button.getAttribute('data-task-top-trigger') === sectionKey);
    });
    shell.querySelectorAll('[data-task-top-section]').forEach((section) => {
      if (!(section instanceof HTMLElement)) return;
      section.classList.toggle('active', section.getAttribute('data-task-top-section') === sectionKey);
    });
  }

  function getTaskPaneControls(pane) {
    if (!(pane instanceof HTMLElement)) return null;
    const activeToggle = pane.querySelector('[data-task-field="active"]');
    const durationToggle = pane.querySelector('[data-task-field="duration"]');
    const startDate = pane.querySelector('[data-task-field="startDate"]');
    const startTime = pane.querySelector('[data-task-field="startTime"]');
    const endDate = pane.querySelector('[data-task-field="endDate"]');
    const endTime = pane.querySelector('[data-task-field="endTime"]');
    const statusEl = pane.querySelector('[data-task-status]');
    const saveStatusEl = pane.querySelector('[data-task-save-status]');
    const saveButton = pane.querySelector('[data-action="save-task-settings"]');
    if (
      !(activeToggle instanceof HTMLInputElement) ||
      !(durationToggle instanceof HTMLInputElement) ||
      !(startDate instanceof HTMLInputElement) ||
      !(startTime instanceof HTMLSelectElement) ||
      !(endDate instanceof HTMLInputElement) ||
      !(endTime instanceof HTMLSelectElement)
    ) {
      return null;
    }
    return {
      activeToggle,
      durationToggle,
      startDate,
      startTime,
      endDate,
      endTime,
      statusEl: statusEl instanceof HTMLElement ? statusEl : null,
      saveStatusEl: saveStatusEl instanceof HTMLElement ? saveStatusEl : null,
      saveButton: saveButton instanceof HTMLButtonElement ? saveButton : null
    };
  }

  function getTaskScoreControls(pane) {
    if (!(pane instanceof HTMLElement)) return null;
    const scoreInput = pane.querySelector('[data-task-field="score"]');
    const afterEndtimeScoreInput = pane.querySelector('[data-task-field="afterEndtimeScore"]');
    const saveStatusEl = pane.querySelector('[data-task-score-save-status]');
    const saveButton = pane.querySelector('[data-action="save-task-score-system"]');
    if (
      !(scoreInput instanceof HTMLInputElement) ||
      !(afterEndtimeScoreInput instanceof HTMLInputElement)
    ) {
      return null;
    }
    return {
      scoreInput,
      afterEndtimeScoreInput,
      saveStatusEl: saveStatusEl instanceof HTMLElement ? saveStatusEl : null,
      saveButton: saveButton instanceof HTMLButtonElement ? saveButton : null
    };
  }

  function setTaskSaveStatus(pane, message, isError = false) {
    const controls = getTaskPaneControls(pane);
    if (!controls?.saveStatusEl) return;
    controls.saveStatusEl.textContent = message || '';
    controls.saveStatusEl.style.color = isError ? '#d1434a' : '';
  }

  function setTaskScoreSaveStatus(pane, message, isError = false) {
    const controls = getTaskScoreControls(pane);
    if (!controls?.saveStatusEl) return;
    controls.saveStatusEl.textContent = message || '';
    controls.saveStatusEl.style.color = isError ? '#d1434a' : '';
  }

  function setTaskPaneStatus(pane, label, tone) {
    const controls = getTaskPaneControls(pane);
    if (!controls?.statusEl) return;
    controls.statusEl.textContent = label;
    controls.statusEl.classList.remove(
      'tc-status--active',
      'tc-status--ended',
      'tc-status--upcoming',
      'tc-status--inactive'
    );
    if (tone) {
      controls.statusEl.classList.add(`tc-status--${tone}`);
    }
  }

  function setDurationFieldsEnabled(pane, enabled) {
    const controls = getTaskPaneControls(pane);
    if (!controls) return;
    controls.startDate.disabled = !enabled;
    controls.startTime.disabled = !enabled;
    controls.endDate.disabled = !enabled;
    controls.endTime.disabled = !enabled;
  }

  function collectTaskSettingsFromPane(pane) {
    const controls = getTaskPaneControls(pane);
    if (!controls) return null;
    return {
      active: controls.activeToggle.checked ? '1' : '0',
      duration: controls.durationToggle.checked ? '1' : '0',
      start_date: normalizeDate(controls.startDate.value),
      start_time: normalizeTime(controls.startTime.value),
      end_date: normalizeDate(controls.endDate.value),
      end_time: normalizeTime(controls.endTime.value)
    };
  }

  function collectTaskScoreFromPane(pane) {
    const controls = getTaskScoreControls(pane);
    if (!controls) return null;
    return {
      score: String(normalizeScoreValue(controls.scoreInput.value)),
      after_endtime_score: String(normalizeScoreValue(controls.afterEndtimeScoreInput.value))
    };
  }

  function updateTaskPaneStatus(pane) {
    const settings = collectTaskSettingsFromPane(pane);
    if (!settings) return;
    const derived = deriveStatusFromSettings({
      active: settings.active,
      duration: settings.duration,
      startDate: settings.start_date,
      startTime: settings.start_time,
      endDate: settings.end_date,
      endTime: settings.end_time
    });
    setTaskPaneStatus(pane, derived.label, derived.tone);
  }

  function syncTaskPaneToggleState(pane) {
    const controls = getTaskPaneControls(pane);
    if (!controls) return;
    if (controls.activeToggle.checked) {
      controls.durationToggle.checked = false;
    } else if (controls.durationToggle.checked) {
      controls.activeToggle.checked = false;
    }
    setDurationFieldsEnabled(pane, controls.durationToggle.checked);
    updateTaskPaneStatus(pane);
  }

  function applyTaskSettingsToPane(pane, task) {
    const controls = getTaskPaneControls(pane);
    if (!controls) return;
    controls.activeToggle.checked = normalizeBool(task?.active);
    controls.durationToggle.checked = normalizeBool(task?.duration);
    controls.startDate.value = normalizeDate(task?.startDate);
    controls.startTime.value = normalizeTime(task?.startTime);
    controls.endDate.value = normalizeDate(task?.endDate);
    controls.endTime.value = normalizeTime(task?.endTime);
    const scoreControls = getTaskScoreControls(pane);
    if (scoreControls) {
      scoreControls.scoreInput.value = String(normalizeScoreValue(task?.score));
      scoreControls.afterEndtimeScoreInput.value = String(normalizeScoreValue(task?.afterEndtimeScore));
    }
    syncTaskPaneToggleState(pane);
    setTaskSaveStatus(pane, '');
    setTaskScoreSaveStatus(pane, '');
  }

  function buildTaskControlCardMarkup(task) {
    const titleText = task.title || task.tagCode;
    const typeLabel = task.taskType === 'quiz' ? 'Quiz Task' : 'Quiz Task';
    const quizSrc = `mini%20apps/Task%20Club/TCQ.php?task_id=${encodeURIComponent(task.id)}`;
    return `
      <div class="tc-task-top-shell" data-task-top-shell>
        <div class="tc-task-top-nav">
          <button type="button" class="sub-item active" data-task-top-trigger="control">Control Pane</button>
          <button type="button" class="sub-item" data-task-top-trigger="quiz">Quiz</button>
        </div>

        <div class="tc-task-top-section active" data-task-top-section="control">
          <div class="card">
            <div class="section-header">
              <h3>${escapeHtml(titleText)}</h3>
            </div>
            <p class="muted small">Tag Code: <code>${escapeHtml(task.tagCode)}</code> - Type: ${escapeHtml(typeLabel)}</p>
            <div class="form" style="gap:12px;">
              <div class="tc-status tc-status--inactive" data-task-status>Not Active</div>
              <div class="tc-switch-grid">
                <label class="switch tc-switch">
                  <span class="switch-label">Active</span>
                  <span class="switch-toggle">
                    <input type="checkbox" data-task-field="active" aria-label="Task Active" />
                    <span class="switch-track"><span class="switch-thumb"></span></span>
                  </span>
                </label>
                <label class="switch tc-switch">
                  <span class="switch-label">Duration</span>
                  <span class="switch-toggle">
                    <input type="checkbox" data-task-field="duration" aria-label="Task Duration" />
                    <span class="switch-track"><span class="switch-thumb"></span></span>
                  </span>
                </label>
              </div>
              <div class="form grid two-column-fields tc-datetime-grid">
                <div class="tc-datetime-title tc-datetime-title--start">Start</div>
                <label class="field standard-width tc-datetime-start">
                  <span>Date</span>
                  <input type="date" data-task-field="startDate" placeholder="YYYY-MM-DD" />
                </label>
                <label class="field standard-width tc-datetime-start-time">
                  <span>Time</span>
                  <select data-task-field="startTime">
                    ${buildTimeOptions(task.startTime)}
                  </select>
                </label>
                <div class="tc-datetime-title tc-datetime-title--end">End</div>
                <label class="field standard-width tc-datetime-end">
                  <span>Date</span>
                  <input type="date" data-task-field="endDate" placeholder="YYYY-MM-DD" />
                </label>
                <label class="field standard-width tc-datetime-end-time">
                  <span>Time</span>
                  <select data-task-field="endTime">
                    ${buildTimeOptions(task.endTime)}
                  </select>
                </label>
                <div class="tc-datetime-empty" aria-hidden="true"></div>
              </div>
              <div class="field full">
                <button type="button" class="btn primary standard-primary-button" data-action="save-task-settings">Save</button>
              </div>
              <p class="muted small" data-task-save-status aria-live="polite"></p>
            </div>
          </div>
          <div class="card">
            <div class="section-header">
              <h3>Score System</h3>
            </div>
            <div class="form" style="gap:12px;">
              <label class="field standard-width">
                <span>Score</span>
                <input type="number" min="0" step="1" data-task-field="score" />
              </label>
              <label class="field standard-width">
                <span>After Endtime Score</span>
                <input type="number" min="0" step="1" data-task-field="afterEndtimeScore" />
              </label>
              <div class="field full">
                <button type="button" class="btn primary standard-primary-button" data-action="save-task-score-system">Save</button>
              </div>
              <p class="muted small" data-task-score-save-status aria-live="polite"></p>
            </div>
          </div>
        </div>

        <div class="tc-task-top-section" data-task-top-section="quiz">
          <div class="card tc-task-quiz-card">
            <iframe
              class="tc-task-quiz-frame"
              src="${escapeHtml(quizSrc)}"
              loading="lazy"
              referrerpolicy="same-origin"
              title="Task Quiz"
            ></iframe>
          </div>
        </div>
      </div>
    `;
  }

  function renderTaskSubtabs(layout, tasks, preferredPane = '') {
    if (!(layout instanceof HTMLElement)) return;
    const navHost = layout.querySelector('[data-tc-task-subtab-nav]');
    const paneHost = layout.querySelector('[data-tc-task-subtab-panes]');
    const nav = layout.querySelector('.sub-nav');
    if (!(navHost instanceof HTMLElement) || !(paneHost instanceof HTMLElement) || !(nav instanceof HTMLElement)) {
      return;
    }

    const previousActivePane = preferredPane || (nav.querySelector('.sub-item.active')?.dataset?.pane || '');
    navHost.innerHTML = '';
    paneHost.innerHTML = '';

    const normalizedTasks = (Array.isArray(tasks) ? tasks : [])
      .map((task, index) => normalizeTask(task, index))
      .filter((task) => task.id && task.tagCode)
      .sort((a, b) => a.order - b.order);

    if (!normalizedTasks.length) {
      ensureAnyActivePane(layout, previousActivePane);
      return;
    }

    const taskById = new Map();
    const usedPaneKeys = new Set();
    const navFragment = document.createDocumentFragment();
    const paneFragment = document.createDocumentFragment();

    normalizedTasks.forEach((task, index) => {
      const paneKey = makeTaskPaneKey(task, index, usedPaneKeys);
      const labelText = `${task.order}. ${task.title || task.tagCode}`;

      taskById.set(task.id, task);

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'sub-item';
      button.dataset.pane = paneKey;
      button.dataset.taskId = task.id;
      button.textContent = labelText;
      navFragment.appendChild(button);

      const pane = document.createElement('div');
      pane.className = 'sub-pane';
      pane.dataset.pane = paneKey;
      pane.dataset.taskPane = '1';
      pane.dataset.taskId = task.id;
      pane.innerHTML = buildTaskControlCardMarkup(task);
      paneFragment.appendChild(pane);
    });

    navHost.appendChild(navFragment);
    paneHost.appendChild(paneFragment);
    ensureAnyActivePane(layout, previousActivePane);

    paneHost.querySelectorAll('.sub-pane[data-task-pane="1"]').forEach((pane) => {
      if (!(pane instanceof HTMLElement)) return;
      const taskId = pane.dataset.taskId || '';
      applyTaskSettingsToPane(pane, taskById.get(taskId) || null);
      activateTaskTopPane(pane, 'control');
    });
  }

  async function postTaskAction(action, payload = {}) {
    const formData = new FormData();
    formData.append('tct_action', action);
    Object.entries(payload).forEach(([key, value]) => {
      formData.append(key, String(value ?? ''));
    });
    const response = await fetch(TASKS_ENDPOINT, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    });
    const data = await response.json();
    if (!response.ok || data?.status !== 'ok') {
      throw new Error(data?.message || 'Request failed.');
    }
    return data;
  }

  async function fetchTaskList() {
    const data = await postTaskAction('list');
    return Array.isArray(data.tasks) ? data.tasks : [];
  }

  async function refreshTaskSubtabs(layout) {
    if (!(layout instanceof HTMLElement)) return;
    try {
      const tasks = await fetchTaskList();
      renderTaskSubtabs(layout, tasks);
    } catch {
      renderTaskSubtabs(layout, []);
    }
  }

  function setupTaskPaneInteractions(layout) {
    if (!(layout instanceof HTMLElement)) return;
    if (layout.dataset.tcTaskPaneHandlersReady === '1') return;
    layout.dataset.tcTaskPaneHandlersReady = '1';

    const handleTaskFieldUpdate = (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      const field = target.closest('[data-task-field]');
      if (!(field instanceof Element)) return;
      const pane = field.closest('.sub-pane[data-task-pane="1"]');
      if (!(pane instanceof HTMLElement)) return;
      const fieldName = field.getAttribute('data-task-field') || '';
      if (fieldName === 'active' || fieldName === 'duration') {
        syncTaskPaneToggleState(pane);
        setTaskSaveStatus(pane, '');
        return;
      }
      if (
        fieldName === 'startDate' ||
        fieldName === 'startTime' ||
        fieldName === 'endDate' ||
        fieldName === 'endTime'
      ) {
        updateTaskPaneStatus(pane);
        setTaskSaveStatus(pane, '');
        return;
      }
      if (fieldName === 'score' || fieldName === 'afterEndtimeScore') {
        setTaskScoreSaveStatus(pane, '');
        return;
      }
    };

    layout.addEventListener('change', handleTaskFieldUpdate);
    layout.addEventListener('input', handleTaskFieldUpdate);

    layout.addEventListener('click', async (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;

      const topTrigger = target.closest('[data-task-top-trigger]');
      if (topTrigger instanceof HTMLElement) {
        const pane = topTrigger.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        const sectionKey = topTrigger.getAttribute('data-task-top-trigger') || '';
        if (!sectionKey) return;
        event.preventDefault();
        activateTaskTopPane(pane, sectionKey);
        return;
      }

      const saveButton = target.closest('[data-action="save-task-settings"]');
      if (saveButton instanceof HTMLButtonElement) {
        const pane = saveButton.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        const taskId = pane.dataset.taskId || '';
        if (!taskId) return;

        const settings = collectTaskSettingsFromPane(pane);
        if (!settings) return;

        saveButton.disabled = true;
        setTaskSaveStatus(pane, 'Saving...');
        try {
          const data = await postTaskAction('save_task_settings', {
            id: taskId,
            ...settings
          });
          const returnedTasks = Array.isArray(data.tasks) ? data.tasks : [];
          const keepPane = pane.dataset.pane || '';
          if (returnedTasks.length) {
            renderTaskSubtabs(layout, returnedTasks, keepPane);
            try {
              window.TC_TASKS = returnedTasks;
            } catch {}
          }
          const activePane = findPaneByKey(layout, keepPane);
          if (activePane instanceof HTMLElement) {
            setTaskSaveStatus(activePane, data.message || 'Task settings saved.');
          }
        } catch (error) {
          setTaskSaveStatus(pane, error?.message || 'Failed to save task settings.', true);
        } finally {
          const refreshedPane = pane.dataset.pane
            ? findPaneByKey(layout, pane.dataset.pane)
            : null;
          const refreshedButton = refreshedPane instanceof HTMLElement
            ? refreshedPane.querySelector('[data-action="save-task-settings"]')
            : null;
          if (refreshedButton instanceof HTMLButtonElement) {
            refreshedButton.disabled = false;
          } else {
            saveButton.disabled = false;
          }
        }
        return;
      }

      const scoreSaveButton = target.closest('[data-action="save-task-score-system"]');
      if (!(scoreSaveButton instanceof HTMLButtonElement)) return;
      const pane = scoreSaveButton.closest('.sub-pane[data-task-pane="1"]');
      if (!(pane instanceof HTMLElement)) return;
      const taskId = pane.dataset.taskId || '';
      if (!taskId) return;

      const scoreSettings = collectTaskScoreFromPane(pane);
      if (!scoreSettings) return;

      scoreSaveButton.disabled = true;
      setTaskScoreSaveStatus(pane, 'Saving...');
      try {
        const data = await postTaskAction('save_task_score_system', {
          id: taskId,
          ...scoreSettings
        });
        const returnedTasks = Array.isArray(data.tasks) ? data.tasks : [];
        const keepPane = pane.dataset.pane || '';
        if (returnedTasks.length) {
          renderTaskSubtabs(layout, returnedTasks, keepPane);
          try {
            window.TC_TASKS = returnedTasks;
          } catch {}
        }
        const activePane = findPaneByKey(layout, keepPane);
        if (activePane instanceof HTMLElement) {
          setTaskScoreSaveStatus(activePane, data.message || 'Score settings saved.');
        }
      } catch (error) {
        setTaskScoreSaveStatus(pane, error?.message || 'Failed to save score settings.', true);
      } finally {
        const refreshedPane = pane.dataset.pane
          ? findPaneByKey(layout, pane.dataset.pane)
          : null;
        const refreshedButton = refreshedPane instanceof HTMLElement
          ? refreshedPane.querySelector('[data-action="save-task-score-system"]')
          : null;
        if (refreshedButton instanceof HTMLButtonElement) {
          refreshedButton.disabled = false;
        } else {
          scoreSaveButton.disabled = false;
        }
      }
    });
  }

  function initWheelSubLayouts() {
    const layouts = document.querySelectorAll('[data-tc-sub-layout]');
    layouts.forEach((layout) => {
      if (!(layout instanceof HTMLElement)) return;
      if (layout.dataset.tcSubLayoutReady === '1') return;
      layout.dataset.tcSubLayoutReady = '1';

      const nav = layout.querySelector('.sub-nav');
      if (!(nav instanceof HTMLElement)) return;

      nav.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const trigger = target.closest('.sub-item[data-pane]');
        if (!(trigger instanceof HTMLElement)) return;
        const targetPane = trigger.dataset.pane;
        if (!targetPane) return;
        event.preventDefault();
        activatePane(layout, targetPane);
      });

      setupTaskPaneInteractions(layout);

      window.addEventListener('tcTasksChanged', (event) => {
        const tasks = event?.detail?.tasks;
        if (Array.isArray(tasks)) {
          renderTaskSubtabs(layout, tasks);
          return;
        }
        refreshTaskSubtabs(layout);
      });

      refreshTaskSubtabs(layout);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWheelSubLayouts);
  } else {
    initWheelSubLayouts();
  }
})();
