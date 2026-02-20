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
    if (token === 'info' || token === 'info-task' || token === 'info task') {
      return 'info';
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
      infoTitle: String(raw.infoTitle ?? raw.info_title ?? '').trim(),
      infoText: String(raw.infoText ?? raw.info_text ?? '').trim(),
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
      const isActive = button.getAttribute('data-task-top-trigger') === sectionKey;
      button.classList.toggle('active', isActive);
      button.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    shell.querySelectorAll('[data-task-top-section]').forEach((section) => {
      if (!(section instanceof HTMLElement)) return;
      const isActive = section.getAttribute('data-task-top-section') === sectionKey;
      section.classList.toggle('active', isActive);
      section.hidden = !isActive;
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
    if (!(scoreInput instanceof HTMLInputElement)) {
      return null;
    }
    const taskType = normalizeTaskType(pane.dataset.taskType || 'quiz');
    return {
      scoreInput,
      afterEndtimeScoreInput: afterEndtimeScoreInput instanceof HTMLInputElement ? afterEndtimeScoreInput : null,
      taskType,
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

  function getTaskInfoContentControls(pane) {
    if (!(pane instanceof HTMLElement)) return null;
    const titleInput = pane.querySelector('[data-task-field="infoTitle"]');
    const textInput = pane.querySelector('[data-task-field="infoText"]');
    const saveButton = pane.querySelector('[data-action="save-task-information"]');
    const statusEl = pane.querySelector('[data-task-info-save-status]');
    if (!(titleInput instanceof HTMLInputElement) || !(textInput instanceof HTMLTextAreaElement)) {
      return null;
    }
    return {
      titleInput,
      textInput,
      saveButton: saveButton instanceof HTMLButtonElement ? saveButton : null,
      statusEl: statusEl instanceof HTMLElement ? statusEl : null
    };
  }

  function setTaskInfoContentSaveStatus(pane, message, isError = false) {
    const controls = getTaskInfoContentControls(pane);
    if (!controls?.statusEl) return;
    controls.statusEl.textContent = message || '';
    controls.statusEl.style.color = isError ? '#d1434a' : '';
  }

  function collectTaskInfoContentFromPane(pane) {
    const controls = getTaskInfoContentControls(pane);
    if (!controls) return null;
    return {
      info_title: String(controls.titleInput.value || '').trim(),
      info_text: String(controls.textInput.value || '').trim()
    };
  }

  const infoRateStateByTaskId = new Map();

  function getInfoRateElements(pane) {
    if (!(pane instanceof HTMLElement)) return null;
    const body = pane.querySelector('[data-task-info-rate-body]');
    const searchInput = pane.querySelector('[data-task-info-search]');
    const selectAll = pane.querySelector('[data-task-info-select-all]');
    const bulkScoreInput = pane.querySelector('[data-task-info-bulk-score]');
    const statusEl = pane.querySelector('[data-task-info-rate-status]');
    if (!(body instanceof HTMLElement) || !(searchInput instanceof HTMLInputElement) || !(selectAll instanceof HTMLInputElement)) {
      return null;
    }
    return {
      body,
      searchInput,
      selectAll,
      bulkScoreInput: bulkScoreInput instanceof HTMLInputElement ? bulkScoreInput : null,
      statusEl: statusEl instanceof HTMLElement ? statusEl : null
    };
  }

  function setInfoRateStatus(pane, message, isError = false) {
    const controls = getInfoRateElements(pane);
    if (!controls?.statusEl) return;
    controls.statusEl.textContent = message || '';
    controls.statusEl.style.color = isError ? '#d1434a' : '';
  }

  function getInfoRateState(taskId) {
    const key = String(taskId || '').trim();
    if (!key) return null;
    if (!infoRateStateByTaskId.has(key)) {
      infoRateStateByTaskId.set(key, {
        maxScore: 0,
        invitees: [],
        selected: new Set(),
        query: ''
      });
    }
    return infoRateStateByTaskId.get(key);
  }

  async function loadInfoRateDataIntoPane(pane, { silent = false } = {}) {
    if (!(pane instanceof HTMLElement)) return;
    const taskId = String(pane.dataset.taskId || '').trim();
    if (!taskId) return;
    const controls = getInfoRateElements(pane);
    if (!controls) return;
    if (!silent) {
      controls.body.innerHTML = '<tr><td colspan="7" class="muted">Loading invitees...</td></tr>';
    }
    try {
      const data = await postTaskAction('get_info_task_rate_data', { id: taskId });
      const state = getInfoRateState(taskId);
      if (!state) return;
      state.maxScore = normalizeScoreValue(data.maxScore ?? 0);
      state.invitees = Array.isArray(data.invitees) ? data.invitees.map((row) => ({
        workId: String(row.workId || '').trim(),
        firstName: String(row.firstName || '').trim(),
        lastName: String(row.lastName || '').trim(),
        phone: String(row.phone || '').trim(),
        customScore: normalizeScoreValue(row.customScore ?? 0)
      })) : [];
      state.selected = new Set();
      renderInfoRateTable(pane);
      setInfoRateStatus(pane, '');
    } catch (error) {
      controls.body.innerHTML = '<tr><td colspan="7" class="muted">Failed to load invitees.</td></tr>';
      setInfoRateStatus(pane, error?.message || 'Failed to load invitees.', true);
    }
  }

  function renderInfoRateTable(pane) {
    if (!(pane instanceof HTMLElement)) return;
    const taskId = String(pane.dataset.taskId || '').trim();
    const state = getInfoRateState(taskId);
    const controls = getInfoRateElements(pane);
    if (!state || !controls) return;
    const query = String(state.query || '').trim().toLowerCase();
    const visibleRows = state.invitees.filter((row) => {
      if (!query) return true;
      const haystack = `${row.firstName} ${row.lastName} ${row.phone} ${row.workId}`.toLowerCase();
      return haystack.includes(query);
    });
    if (!visibleRows.length) {
      controls.body.innerHTML = '<tr><td colspan="7" class="muted">No invitee found.</td></tr>';
      controls.selectAll.checked = false;
      controls.selectAll.indeterminate = false;
      return;
    }
    const selectedCount = state.selected.size;
    const disableRowActions = selectedCount > 0;
    controls.body.innerHTML = visibleRows.map((row) => {
      const isChecked = state.selected.has(row.workId);
      return `
        <tr data-work-id="${escapeHtml(row.workId)}">
          <td><input type="checkbox" data-info-row-check value="${escapeHtml(row.workId)}" ${isChecked ? 'checked' : ''} /></td>
          <td>${escapeHtml(row.firstName)}</td>
          <td>${escapeHtml(row.lastName)}</td>
          <td>${escapeHtml(row.phone)}</td>
          <td><code>${escapeHtml(row.workId)}</code></td>
          <td>
            <div class="tc-info-rate-row-actions">
              <input type="number" min="0" step="1" data-info-row-score value="${escapeHtml(String(normalizeScoreValue(row.customScore)))}" ${disableRowActions ? 'disabled' : ''} />
              <button type="button" class="btn ghost" data-action="info-row-apply" ${disableRowActions ? 'disabled' : ''}>Save</button>
            </div>
          </td>
          <td><button type="button" class="btn primary standard-primary-button" data-action="info-row-max" ${disableRowActions ? 'disabled' : ''}>Max Score</button></td>
        </tr>
      `;
    }).join('');

    const visibleIds = visibleRows.map((row) => row.workId);
    const visibleSelected = visibleIds.filter((id) => state.selected.has(id)).length;
    controls.selectAll.checked = visibleIds.length > 0 && visibleSelected === visibleIds.length;
    controls.selectAll.indeterminate = visibleSelected > 0 && visibleSelected < visibleIds.length;
  }

  async function assignInfoScores(pane, workIds, mode, customScoreValue = 0) {
    if (!(pane instanceof HTMLElement)) return false;
    const taskId = String(pane.dataset.taskId || '').trim();
    if (!taskId || !Array.isArray(workIds) || !workIds.length) return false;
    const state = getInfoRateState(taskId);
    if (!state) return false;
    const payload = {
      id: taskId,
      mode: mode === 'max' ? 'max' : 'custom',
      work_ids: JSON.stringify(workIds)
    };
    if (mode !== 'max') {
      payload.custom_score = String(Math.max(0, Math.min(state.maxScore, normalizeScoreValue(customScoreValue))));
    }
    try {
      const data = await postTaskAction('save_info_task_scores', payload);
      const assigned = normalizeScoreValue(data.assignedScore ?? (mode === 'max' ? state.maxScore : customScoreValue));
      state.invitees = state.invitees.map((row) => {
        if (!workIds.includes(row.workId)) return row;
        return { ...row, customScore: assigned };
      });
      renderInfoRateTable(pane);
      setInfoRateStatus(pane, data.message || 'Scores updated.');
      return true;
    } catch (error) {
      setInfoRateStatus(pane, error?.message || 'Failed to save invitees score.', true);
      return false;
    }
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
    const isInfoTask = controls.taskType === 'info';
    return {
      score: String(normalizeScoreValue(controls.scoreInput.value)),
      after_endtime_score: isInfoTask ? '0' : String(normalizeScoreValue(controls.afterEndtimeScoreInput?.value))
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
      if (scoreControls.afterEndtimeScoreInput instanceof HTMLInputElement) {
        scoreControls.afterEndtimeScoreInput.value = String(normalizeScoreValue(task?.afterEndtimeScore));
      }
    }
    const infoControls = getTaskInfoContentControls(pane);
    if (infoControls) {
      infoControls.titleInput.value = String(task?.infoTitle || '');
      infoControls.textInput.value = String(task?.infoText || '');
      setTaskInfoContentSaveStatus(pane, '');
    }
    syncTaskPaneToggleState(pane);
    setTaskSaveStatus(pane, '');
    setTaskScoreSaveStatus(pane, '');
  }

  function buildTaskControlCardMarkup(task) {
    const titleText = task.title || task.tagCode;
    const isInfoTask = task.taskType === 'info';
    const typeLabel = isInfoTask ? 'Info Task' : 'Quiz Task';
    const quizSrc = `mini%20apps/Task%20Club/TCQ.php?task_id=${encodeURIComponent(task.id)}`;
    const infoTitle = task.infoTitle || '';
    const infoText = task.infoText || '';
    return `
      <div class="tc-task-top-shell" data-task-top-shell>
        <div class="tc-task-top-nav" role="tablist" aria-label="Task Tabs">
          <button type="button" class="tc-task-top-item active" aria-selected="true" data-task-top-trigger="control">Control Pane</button>
          ${isInfoTask
            ? '<button type="button" class="tc-task-top-item" aria-selected="false" data-task-top-trigger="information">Information</button><button type="button" class="tc-task-top-item" aria-selected="false" data-task-top-trigger="invitees-rate">Invitees Rate</button>'
            : '<button type="button" class="tc-task-top-item" aria-selected="false" data-task-top-trigger="quiz">Quiz</button>'}
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
                <span>${isInfoTask ? 'Total Score' : 'Active Duration (Golden Time)'}</span>
                <input type="number" min="0" step="1" data-task-field="score" />
              </label>
              ${isInfoTask ? '' : `
                <label class="field standard-width">
                  <span>Golden Time Ended, you can answer with lower score</span>
                  <input type="number" min="0" step="1" data-task-field="afterEndtimeScore" />
                </label>
              `}
              <div class="field full">
                <button type="button" class="btn primary standard-primary-button" data-action="save-task-score-system">Save</button>
              </div>
              <p class="muted small" data-task-score-save-status aria-live="polite"></p>
            </div>
          </div>
        </div>
        ${isInfoTask ? `
          <div class="tc-task-top-section" data-task-top-section="information" hidden>
            <div class="card">
              <div class="section-header"><h3>Information Card</h3></div>
              <div class="form" style="gap:12px;">
                <label class="field standard-width">
                  <span>Title</span>
                  <input type="text" data-task-field="infoTitle" value="${escapeHtml(infoTitle)}" />
                </label>
                <label class="field full">
                  <span>Text</span>
                  <textarea data-task-field="infoText" rows="8">${escapeHtml(infoText)}</textarea>
                </label>
                <div class="field full">
                  <button type="button" class="btn primary standard-primary-button" data-action="save-task-information">Save</button>
                </div>
                <p class="muted small" data-task-info-save-status aria-live="polite"></p>
              </div>
            </div>
          </div>
          <div class="tc-task-top-section" data-task-top-section="invitees-rate" hidden>
            <div class="card">
              <div class="section-header"><h3>Invitees List Card</h3></div>
              <div class="form" style="gap:12px;">
                <label class="field standard-width">
                  <span>Search Invitees</span>
                  <input type="text" data-task-info-search placeholder="Search by name, phone, Work ID" autocomplete="off" />
                </label>
                <div class="tc-info-rate-bulk">
                  <label class="field standard-width">
                    <span>Custom Score (Selected)</span>
                    <input type="number" min="0" step="1" data-task-info-bulk-score />
                  </label>
                  <button type="button" class="btn secondary" data-action="info-bulk-apply">Apply Custom Score</button>
                  <button type="button" class="btn primary standard-primary-button" data-action="info-bulk-max">Max Score</button>
                </div>
                <div class="table-wrapper tc-info-rate-table-wrap">
                  <table class="tct-list-table tc-info-rate-table">
                    <thead>
                      <tr>
                        <th><input type="checkbox" data-task-info-select-all /></th>
                        <th>First Name</th>
                        <th>Last Name</th>
                        <th>Phone</th>
                        <th>Work ID</th>
                        <th>Custom Score</th>
                        <th>Fast Score</th>
                      </tr>
                    </thead>
                    <tbody data-task-info-rate-body>
                      <tr><td colspan="7" class="muted">Loading invitees...</td></tr>
                    </tbody>
                  </table>
                </div>
                <p class="muted small" data-task-info-rate-status aria-live="polite"></p>
              </div>
            </div>
          </div>
        ` : `
          <div class="tc-task-top-section" data-task-top-section="quiz" hidden>
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
        `}
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
      pane.dataset.taskType = task.taskType;
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
      if (fieldName === 'infoTitle' || fieldName === 'infoText') {
        setTaskInfoContentSaveStatus(pane, '');
      }
    };

    layout.addEventListener('change', handleTaskFieldUpdate);
    layout.addEventListener('input', handleTaskFieldUpdate);

    layout.addEventListener('input', (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      const searchInput = target.closest('[data-task-info-search]');
      if (!(searchInput instanceof HTMLInputElement)) return;
      const pane = searchInput.closest('.sub-pane[data-task-pane="1"]');
      if (!(pane instanceof HTMLElement)) return;
      const taskId = String(pane.dataset.taskId || '').trim();
      const state = getInfoRateState(taskId);
      if (!state) return;
      state.query = String(searchInput.value || '').trim();
      renderInfoRateTable(pane);
    });

    layout.addEventListener('change', (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      const pane = target.closest('.sub-pane[data-task-pane="1"]');
      if (!(pane instanceof HTMLElement)) return;
      const taskId = String(pane.dataset.taskId || '').trim();
      const state = getInfoRateState(taskId);
      if (!state) return;

      if (target.matches('[data-task-info-select-all]')) {
        const controls = getInfoRateElements(pane);
        if (!controls) return;
        const shouldSelect = controls.selectAll.checked;
        const visibleRows = Array.from(pane.querySelectorAll('tbody[data-task-info-rate-body] tr[data-work-id]'));
        visibleRows.forEach((row) => {
          if (!(row instanceof HTMLTableRowElement)) return;
          const workId = String(row.dataset.workId || '').trim();
          if (!workId) return;
          if (shouldSelect) {
            state.selected.add(workId);
          } else {
            state.selected.delete(workId);
          }
        });
        renderInfoRateTable(pane);
        return;
      }

      if (target.matches('[data-info-row-check]')) {
        const checkbox = target;
        if (!(checkbox instanceof HTMLInputElement)) return;
        const workId = String(checkbox.value || '').trim();
        if (!workId) return;
        if (checkbox.checked) {
          state.selected.add(workId);
        } else {
          state.selected.delete(workId);
        }
        renderInfoRateTable(pane);
      }
    });

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
        if (sectionKey === 'invitees-rate' && normalizeTaskType(pane.dataset.taskType || 'quiz') === 'info') {
          void loadInfoRateDataIntoPane(pane);
        }
        return;
      }

      const saveInfoButton = target.closest('[data-action="save-task-information"]');
      if (saveInfoButton instanceof HTMLButtonElement) {
        const pane = saveInfoButton.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        const taskId = pane.dataset.taskId || '';
        if (!taskId) return;
        const payload = collectTaskInfoContentFromPane(pane);
        if (!payload) return;
        saveInfoButton.disabled = true;
        setTaskInfoContentSaveStatus(pane, 'Saving...');
        try {
          const data = await postTaskAction('save_info_task_content', {
            id: taskId,
            ...payload
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
            activateTaskTopPane(activePane, 'information');
            setTaskInfoContentSaveStatus(activePane, data.message || 'Information saved.');
          }
        } catch (error) {
          setTaskInfoContentSaveStatus(pane, error?.message || 'Failed to save information.', true);
        } finally {
          saveInfoButton.disabled = false;
        }
        return;
      }

      const rowApplyButton = target.closest('[data-action="info-row-apply"]');
      if (rowApplyButton instanceof HTMLButtonElement) {
        const pane = rowApplyButton.closest('.sub-pane[data-task-pane="1"]');
        const row = rowApplyButton.closest('tr[data-work-id]');
        if (!(pane instanceof HTMLElement) || !(row instanceof HTMLTableRowElement)) return;
        const taskId = String(pane.dataset.taskId || '').trim();
        const workId = String(row.dataset.workId || '').trim();
        if (!taskId || !workId) return;
        const scoreInput = row.querySelector('[data-info-row-score]');
        if (!(scoreInput instanceof HTMLInputElement)) return;
        const scoreValue = normalizeScoreValue(scoreInput.value);
        await assignInfoScores(pane, [workId], 'custom', scoreValue);
        return;
      }

      const rowMaxButton = target.closest('[data-action="info-row-max"]');
      if (rowMaxButton instanceof HTMLButtonElement) {
        const pane = rowMaxButton.closest('.sub-pane[data-task-pane="1"]');
        const row = rowMaxButton.closest('tr[data-work-id]');
        if (!(pane instanceof HTMLElement) || !(row instanceof HTMLTableRowElement)) return;
        const workId = String(row.dataset.workId || '').trim();
        if (!workId) return;
        await assignInfoScores(pane, [workId], 'max');
        return;
      }

      const bulkApplyButton = target.closest('[data-action="info-bulk-apply"]');
      if (bulkApplyButton instanceof HTMLButtonElement) {
        const pane = bulkApplyButton.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        const taskId = String(pane.dataset.taskId || '').trim();
        const state = getInfoRateState(taskId);
        const controls = getInfoRateElements(pane);
        if (!state || !controls || !state.selected.size) return;
        const scoreValue = normalizeScoreValue(controls.bulkScoreInput?.value);
        await assignInfoScores(pane, Array.from(state.selected), 'custom', scoreValue);
        return;
      }

      const bulkMaxButton = target.closest('[data-action="info-bulk-max"]');
      if (bulkMaxButton instanceof HTMLButtonElement) {
        const pane = bulkMaxButton.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        const taskId = String(pane.dataset.taskId || '').trim();
        const state = getInfoRateState(taskId);
        if (!state || !state.selected.size) return;
        await assignInfoScores(pane, Array.from(state.selected), 'max');
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
