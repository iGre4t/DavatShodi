(() => {
  const TASKS_ENDPOINT = 'mini%20apps/Task%20Club/TCT.php';
  const tcShellEl = document.querySelector('.tc-shell');
  const TASK_CLUB_CSRF = tcShellEl instanceof HTMLElement
    ? String(tcShellEl.dataset.tcCsrf || '').trim()
    : '';

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
    if (token === 'team_task' || token === 'team-task' || token === 'team task') {
      return 'team_task';
    }
    if (token === 'describe_photo' || token === 'describe-photo' || token === 'describe photo' || token === 'describe-photo-task' || token === 'describe photo task') {
      return 'describe_photo';
    }
    return 'quiz';
  }

  function isInfoLikeTaskType(taskType) {
    const type = normalizeTaskType(taskType);
    return type === 'info' || type === 'team_task' || type === 'describe_photo';
  }

  function isDescribePhotoTaskType(taskType) {
    return normalizeTaskType(taskType) === 'describe_photo';
  }

  function isTeamTaskType(taskType) {
    return normalizeTaskType(taskType) === 'team_task';
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
      devPhase: normalizeBool(raw.devPhase ?? raw.dev_phase ?? false),
      startDate: normalizeDate(raw.startDate ?? raw.start_date ?? ''),
      startTime: normalizeTime(raw.startTime ?? raw.start_time ?? ''),
      endDate: normalizeDate(raw.endDate ?? raw.end_date ?? ''),
      endTime: normalizeTime(raw.endTime ?? raw.end_time ?? ''),
      score: normalizeScoreValue(raw.score ?? raw.taskScore ?? 0),
      afterEndtimeScore: normalizeScoreValue(raw.afterEndtimeScore ?? raw.after_endtime_score ?? 0),
      infoTitle: String(raw.infoTitle ?? raw.info_title ?? '').trim(),
      infoText: String(raw.infoText ?? raw.info_text ?? '').trim(),
      guidePrefix: String(raw.guidePrefix ?? raw.guide_prefix ?? '').replace(/\r\n?/g, '\n'),
      guideSuffix: String(raw.guideSuffix ?? raw.guide_suffix ?? '').replace(/\r\n?/g, '\n'),
      teamMin: normalizeScoreValue(raw.teamMin ?? raw.team_min ?? (normalizeTaskType(raw.taskType ?? raw.task_type ?? 'quiz') === 'team_task' ? 1 : 0)),
      teamMax: normalizeScoreValue(raw.teamMax ?? raw.team_max ?? (normalizeTaskType(raw.taskType ?? raw.task_type ?? 'quiz') === 'team_task' ? 1 : 0)),
      teamAdditionalNote: String(raw.teamAdditionalNote ?? raw.team_additional_note ?? '').replace(/\r\n?/g, '\n'),
      taskPhotos: Array.isArray(raw.taskPhotos ?? raw.task_photos)
        ? (raw.taskPhotos ?? raw.task_photos).map((item) => ({
          id: String(item?.id ?? '').trim(),
          name: String(item?.name ?? '').trim(),
          fileName: String(item?.fileName ?? item?.filename ?? '').trim(),
          sourceFilename: String(item?.sourceFilename ?? item?.source_filename ?? '').trim(),
          sourcePhotoId: Number.parseInt(String(item?.sourcePhotoId ?? item?.source_photo_id ?? '0'), 10) || 0,
          url: String(item?.url ?? '').trim(),
          createdAt: String(item?.createdAt ?? item?.created_at ?? '').trim()
        })).filter((item) => item.id && item.fileName)
        : [],
      taskChallenges: Array.isArray(raw.taskChallenges ?? raw.task_challenges)
        ? (raw.taskChallenges ?? raw.task_challenges).map((item) => ({
          id: String(item?.id ?? '').trim(),
          name: String(item?.name ?? '').trim(),
          guide: String(item?.guide ?? item?.challengeGuide ?? item?.challenge_guide ?? '').replace(/\r\n?/g, '\n'),
          quantity: normalizeScoreValue(item?.quantity ?? 0),
          last: normalizeScoreValue(item?.last ?? item?.quantity ?? 0),
          createdAt: String(item?.createdAt ?? item?.created_at ?? '').trim()
        })).filter((item) => item.id)
        : [],
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
    const titleInput = pane.querySelector('[data-task-field="taskTitle"]');
    const activeToggle = pane.querySelector('[data-task-field="active"]');
    const durationToggle = pane.querySelector('[data-task-field="duration"]');
    const devPhaseToggle = pane.querySelector('[data-task-field="devPhase"]');
    const startDate = pane.querySelector('[data-task-field="startDate"]');
    const startTime = pane.querySelector('[data-task-field="startTime"]');
    const endDate = pane.querySelector('[data-task-field="endDate"]');
    const endTime = pane.querySelector('[data-task-field="endTime"]');
    const statusEl = pane.querySelector('[data-task-status]');
    const saveStatusEl = pane.querySelector('[data-task-save-status]');
    const saveButton = pane.querySelector('[data-action="save-task-settings"]');
    if (
      !(titleInput instanceof HTMLInputElement) ||
      !(activeToggle instanceof HTMLInputElement) ||
      !(durationToggle instanceof HTMLInputElement) ||
      !(devPhaseToggle instanceof HTMLInputElement) ||
      !(startDate instanceof HTMLInputElement) ||
      !(startTime instanceof HTMLSelectElement) ||
      !(endDate instanceof HTMLInputElement) ||
      !(endTime instanceof HTMLSelectElement)
    ) {
      return null;
    }
    return {
      titleInput,
      activeToggle,
      durationToggle,
      devPhaseToggle,
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

  function getTaskTeamSettingsControls(pane) {
    if (!(pane instanceof HTMLElement)) return null;
    if (!isTeamTaskType(pane.dataset.taskType || 'quiz')) return null;
    const teamMinInput = pane.querySelector('[data-task-field="teamMin"]');
    const teamMaxInput = pane.querySelector('[data-task-field="teamMax"]');
    const teamAdditionalNoteInput = pane.querySelector('[data-task-field="teamAdditionalNote"]');
    const saveStatusEl = pane.querySelector('[data-task-team-save-status]');
    const saveButton = pane.querySelector('[data-action="save-team-settings"]');
    if (
      !(teamMinInput instanceof HTMLInputElement) ||
      !(teamMaxInput instanceof HTMLInputElement) ||
      !(teamAdditionalNoteInput instanceof HTMLTextAreaElement)
    ) {
      return null;
    }
    return {
      teamMinInput,
      teamMaxInput,
      teamAdditionalNoteInput,
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

  function setTaskTeamSettingsSaveStatus(pane, message, isError = false) {
    const controls = getTaskTeamSettingsControls(pane);
    if (!controls?.saveStatusEl) return;
    controls.saveStatusEl.textContent = message || '';
    controls.saveStatusEl.style.color = isError ? '#d1434a' : '';
  }

  function getTaskInfoContentControls(pane) {
    if (!(pane instanceof HTMLElement)) return null;
    const titleInput = pane.querySelector('[data-task-field="infoTitle"]');
    const textInput = pane.querySelector('[data-task-field="infoText"]');
    const guidePrefixInput = pane.querySelector('[data-task-field="guidePrefix"]');
    const guideSuffixInput = pane.querySelector('[data-task-field="guideSuffix"]');
    const saveButton = pane.querySelector('[data-action="save-task-information"]');
    const statusEl = pane.querySelector('[data-task-info-save-status]');
    if (!(titleInput instanceof HTMLInputElement) || !(textInput instanceof HTMLTextAreaElement)) {
      return null;
    }
    return {
      titleInput,
      textInput,
      guidePrefixInput: guidePrefixInput instanceof HTMLTextAreaElement ? guidePrefixInput : null,
      guideSuffixInput: guideSuffixInput instanceof HTMLTextAreaElement ? guideSuffixInput : null,
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
      info_text: String(controls.textInput.value || '').replace(/\r\n?/g, '\n'),
      info_guide_prefix: String(controls.guidePrefixInput?.value || '').replace(/\r\n?/g, '\n'),
      info_guide_suffix: String(controls.guideSuffixInput?.value || '').replace(/\r\n?/g, '\n')
    };
  }

  function collectTaskTeamSettingsFromPane(pane) {
    const controls = getTaskTeamSettingsControls(pane);
    if (!controls) return null;
    return {
      team_min: String(normalizeScoreValue(controls.teamMinInput.value)),
      team_max: String(normalizeScoreValue(controls.teamMaxInput.value)),
      team_additional_note: String(controls.teamAdditionalNoteInput.value || '').replace(/\r\n?/g, '\n')
    };
  }

  const describePhotoStateByTaskId = new Map();

  function getDescribePhotoState(taskId) {
    const key = String(taskId || '').trim();
    if (!key) return null;
    if (!describePhotoStateByTaskId.has(key)) {
      describePhotoStateByTaskId.set(key, {
        selectedPhoto: null,
        photos: []
      });
    }
    return describePhotoStateByTaskId.get(key);
  }

  function normalizeDescribePhotoList(items) {
    if (!Array.isArray(items)) return [];
    return items.map((item) => ({
      id: String(item?.id ?? '').trim(),
      name: String(item?.name ?? '').trim(),
      fileName: String(item?.fileName ?? item?.filename ?? '').trim(),
      sourceFilename: String(item?.sourceFilename ?? item?.source_filename ?? '').trim(),
      sourcePhotoId: normalizeScoreValue(item?.sourcePhotoId ?? item?.source_photo_id ?? 0),
      url: String(item?.url ?? '').trim(),
      createdAt: String(item?.createdAt ?? item?.created_at ?? '').trim()
    })).filter((item) => item.id !== '' && item.fileName !== '');
  }

  function toPhotoChooserPayload(photo) {
    if (!photo || typeof photo !== 'object') return null;
    const id = Number.parseInt(String(photo.id ?? 0), 10);
    const title = String(photo.title ?? '').trim();
    const filename = String(photo.filename ?? '').trim();
    if (!filename) return null;
    return {
      id: Number.isFinite(id) && id > 0 ? id : 0,
      title,
      filename
    };
  }

  function buildDescribePhotoPreviewUrl(photoLike) {
    const directUrl = String(photoLike?.url ?? '').trim();
    if (directUrl) {
      return directUrl;
    }
    const sourcePath = String(photoLike?.filename ?? photoLike?.sourceFilename ?? '').trim();
    if (!sourcePath) {
      return '';
    }
    if (/^(?:https?:|data:|\/)/i.test(sourcePath)) {
      return sourcePath;
    }
    return encodeURI(sourcePath);
  }

  function getDescribePhotoPaneControls(pane) {
    if (!(pane instanceof HTMLElement)) return null;
    const nameInput = pane.querySelector('[data-task-photo-name]');
    const previewImage = pane.querySelector('[data-task-photo-preview-image]');
    const previewPlaceholder = pane.querySelector('[data-task-photo-preview-placeholder]');
    const uploadStatus = pane.querySelector('[data-task-photo-upload-status]');
    const listBody = pane.querySelector('[data-task-photo-list-body]');
    const listStatus = pane.querySelector('[data-task-photo-list-status]');
    if (
      !(nameInput instanceof HTMLInputElement) ||
      !(previewImage instanceof HTMLImageElement) ||
      !(previewPlaceholder instanceof HTMLElement) ||
      !(uploadStatus instanceof HTMLElement) ||
      !(listBody instanceof HTMLElement) ||
      !(listStatus instanceof HTMLElement)
    ) {
      return null;
    }
    return {
      nameInput,
      previewImage,
      previewPlaceholder,
      uploadStatus,
      listBody,
      listStatus
    };
  }

  function setDescribePhotoUploadStatus(pane, message, isError = false) {
    const controls = getDescribePhotoPaneControls(pane);
    if (!controls) return;
    controls.uploadStatus.textContent = String(message || '').trim();
    controls.uploadStatus.style.color = isError ? '#d1434a' : '';
  }

  function setDescribePhotoListStatus(pane, message, isError = false) {
    const controls = getDescribePhotoPaneControls(pane);
    if (!controls) return;
    controls.listStatus.textContent = String(message || '').trim();
    controls.listStatus.style.color = isError ? '#d1434a' : '';
  }

  function renderDescribePhotoUploadCard(pane) {
    if (!(pane instanceof HTMLElement)) return;
    const taskId = String(pane.dataset.taskId || '').trim();
    const state = getDescribePhotoState(taskId);
    const controls = getDescribePhotoPaneControls(pane);
    if (!state || !controls) return;

    const selected = state.selectedPhoto;
    const previewUrl = buildDescribePhotoPreviewUrl(selected);
    const hasSelected = Boolean(selected && previewUrl);
    if (hasSelected) {
      controls.previewImage.src = previewUrl;
      controls.previewImage.alt = String(selected?.title || selected?.filename || 'Selected photo');
      controls.previewImage.classList.remove('hidden');
      controls.previewPlaceholder.classList.add('hidden');
    } else {
      controls.previewImage.classList.add('hidden');
      controls.previewImage.removeAttribute('src');
      controls.previewPlaceholder.classList.remove('hidden');
    }

    const clearBtn = pane.querySelector('[data-action="clear-task-photo"]');
    const addBtn = pane.querySelector('[data-action="add-task-photo"]');
    if (clearBtn instanceof HTMLButtonElement) {
      clearBtn.disabled = !state.selectedPhoto;
    }
    if (addBtn instanceof HTMLButtonElement) {
      addBtn.disabled = !state.selectedPhoto;
    }
  }

  function renderDescribePhotoList(pane) {
    if (!(pane instanceof HTMLElement)) return;
    const taskId = String(pane.dataset.taskId || '').trim();
    const state = getDescribePhotoState(taskId);
    const controls = getDescribePhotoPaneControls(pane);
    if (!state || !controls) return;

    if (!Array.isArray(state.photos) || !state.photos.length) {
      controls.listBody.innerHTML = '<tr><td colspan="3" class="muted">No photos added yet.</td></tr>';
      return;
    }

    controls.listBody.innerHTML = state.photos.map((photo) => {
      const thumbUrl = buildDescribePhotoPreviewUrl(photo);
      const escapedName = escapeHtml(photo.name || '');
      const escapedPhotoId = escapeHtml(photo.id || '');
      return `
        <tr data-task-photo-row="${escapedPhotoId}">
          <td>
            ${thumbUrl ? `<img class="tc-task-photo-thumb" src="${escapeHtml(thumbUrl)}" alt="${escapedName || 'Task photo'}" loading="lazy" />` : '<span class="muted">No Preview</span>'}
          </td>
          <td>
            <input
              type="text"
              class="tc-task-photo-name-input"
              data-task-photo-row-name
              value="${escapedName}"
            />
          </td>
          <td>
            <div class="tc-task-photo-row-actions">
              <button type="button" class="btn ghost" data-action="save-task-photo-name" data-photo-id="${escapedPhotoId}">Save</button>
              <button type="button" class="btn ghost tc-btn-danger" data-action="remove-task-photo" data-photo-id="${escapedPhotoId}">Remove</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');
  }

  function applyDescribePhotoTaskStateFromTask(pane, task) {
    if (!(pane instanceof HTMLElement)) return;
    const taskId = String(pane.dataset.taskId || '').trim();
    if (!taskId) return;
    const state = getDescribePhotoState(taskId);
    if (!state) return;
    state.photos = normalizeDescribePhotoList(task?.taskPhotos);
    state.selectedPhoto = null;
    const controls = getDescribePhotoPaneControls(pane);
    if (controls) {
      controls.nameInput.value = '';
    }
    renderDescribePhotoUploadCard(pane);
    renderDescribePhotoList(pane);
    setDescribePhotoUploadStatus(pane, '');
    setDescribePhotoListStatus(pane, '');
  }

  const teamChallengeStateByTaskId = new Map();

  function normalizeTeamChallengeList(items) {
    if (!Array.isArray(items)) return [];
    return items.map((item) => {
      const id = String(item?.id ?? '').trim();
      const name = String(item?.name ?? '').trim() || 'Challenge';
      const guide = String(item?.guide ?? item?.challengeGuide ?? item?.challenge_guide ?? '').replace(/\r\n?/g, '\n');
      const quantity = Math.max(0, normalizeScoreValue(item?.quantity ?? 0));
      const rawLast = Math.max(0, normalizeScoreValue(item?.last ?? quantity));
      const last = quantity === 0 ? 0 : Math.min(rawLast, quantity);
      return {
        id,
        name,
        guide,
        quantity,
        last,
        createdAt: String(item?.createdAt ?? item?.created_at ?? '').trim()
      };
    }).filter((item) => item.id);
  }

  function getTeamChallengeState(taskId) {
    const key = String(taskId || '').trim();
    if (!key) return null;
    if (!teamChallengeStateByTaskId.has(key)) {
      teamChallengeStateByTaskId.set(key, { challenges: [] });
    }
    return teamChallengeStateByTaskId.get(key);
  }

  function getTeamChallengeControls(pane) {
    if (!(pane instanceof HTMLElement)) return null;
    const nameInput = pane.querySelector('[data-team-challenge-name]');
    const quantityInput = pane.querySelector('[data-team-challenge-quantity]');
    const addStatusEl = pane.querySelector('[data-team-challenge-add-status]');
    const listBody = pane.querySelector('[data-team-challenge-list-body]');
    const listStatusEl = pane.querySelector('[data-team-challenge-list-status]');
    if (
      !(nameInput instanceof HTMLInputElement) ||
      !(quantityInput instanceof HTMLInputElement) ||
      !(addStatusEl instanceof HTMLElement) ||
      !(listBody instanceof HTMLElement) ||
      !(listStatusEl instanceof HTMLElement)
    ) {
      return null;
    }
    return {
      nameInput,
      quantityInput,
      addStatusEl,
      listBody,
      listStatusEl
    };
  }

  function setTeamChallengeAddStatus(pane, message, isError = false) {
    const controls = getTeamChallengeControls(pane);
    if (!controls) return;
    controls.addStatusEl.textContent = String(message || '').trim();
    controls.addStatusEl.style.color = isError ? '#d1434a' : '';
  }

  function setTeamChallengeListStatus(pane, message, isError = false) {
    const controls = getTeamChallengeControls(pane);
    if (!controls) return;
    controls.listStatusEl.textContent = String(message || '').trim();
    controls.listStatusEl.style.color = isError ? '#d1434a' : '';
  }

  function renderTeamChallengeList(pane) {
    if (!(pane instanceof HTMLElement)) return;
    const taskId = String(pane.dataset.taskId || '').trim();
    const state = getTeamChallengeState(taskId);
    const controls = getTeamChallengeControls(pane);
    if (!state || !controls) return;

    if (!Array.isArray(state.challenges) || !state.challenges.length) {
      controls.listBody.innerHTML = '<tr><td colspan="4" class="muted">No challenges added yet.</td></tr>';
      return;
    }

    controls.listBody.innerHTML = state.challenges.map((challenge) => {
      const challengeId = escapeHtml(challenge.id);
      const name = escapeHtml(challenge.name || 'Challenge');
      const quantity = Math.max(0, normalizeScoreValue(challenge.quantity));
      const last = quantity === 0
        ? 0
        : Math.min(Math.max(0, normalizeScoreValue(challenge.last)), quantity);
      return `
        <tr data-team-challenge-row="${challengeId}">
          <td>
            <input type="text" class="tc-team-challenge-name-input" data-team-challenge-row-name value="${name}" />
          </td>
          <td>
            <input type="number" min="0" step="1" class="tc-team-challenge-num-input" data-team-challenge-row-quantity value="${escapeHtml(String(quantity))}" />
          </td>
          <td>
            <input type="number" min="0" step="1" class="tc-team-challenge-num-input" data-team-challenge-row-last value="${escapeHtml(String(last))}" />
          </td>
          <td>
            <div class="tc-team-challenge-row-actions">
              <button type="button" class="btn ghost" data-action="open-team-challenge-guide" data-challenge-id="${challengeId}">Guide</button>
              <button type="button" class="btn ghost" data-action="save-team-challenge" data-challenge-id="${challengeId}">Save</button>
              <button type="button" class="btn ghost tc-btn-danger" data-action="remove-team-challenge" data-challenge-id="${challengeId}">Remove</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');
  }

  function applyTeamTaskChallengeStateFromTask(pane, task) {
    if (!(pane instanceof HTMLElement)) return;
    const taskId = String(pane.dataset.taskId || '').trim();
    if (!taskId) return;
    const state = getTeamChallengeState(taskId);
    if (!state) return;
    state.challenges = normalizeTeamChallengeList(task?.taskChallenges);
    const controls = getTeamChallengeControls(pane);
    if (controls) {
      controls.nameInput.value = '';
      controls.quantityInput.value = '1';
    }
    renderTeamChallengeList(pane);
    setTeamChallengeAddStatus(pane, '');
    setTeamChallengeListStatus(pane, '');
  }

  function getTeamChallengeById(taskId, challengeId) {
    const state = getTeamChallengeState(taskId);
    if (!state) return null;
    const normalizedId = String(challengeId || '').trim();
    if (!normalizedId) return null;
    return state.challenges.find((item) => String(item?.id || '').trim() === normalizedId) || null;
  }

  let teamChallengeGuideModalEl = null;
  let teamChallengeGuideContext = null;

  function closeTeamChallengeGuideModal() {
    if (!(teamChallengeGuideModalEl instanceof HTMLElement)) return;
    teamChallengeGuideModalEl.hidden = true;
    teamChallengeGuideContext = null;
  }

  function setTeamChallengeGuideModalStatus(message, isError = false) {
    const modal = ensureTeamChallengeGuideModal();
    const statusEl = modal.querySelector('[data-team-challenge-guide-status]');
    if (!(statusEl instanceof HTMLElement)) return;
    statusEl.textContent = String(message || '').trim();
    statusEl.style.color = isError ? '#d1434a' : '';
  }

  function ensureTeamChallengeGuideModal() {
    if (teamChallengeGuideModalEl instanceof HTMLElement) {
      return teamChallengeGuideModalEl;
    }
    const wrapper = document.createElement('div');
    wrapper.className = 'tc-team-challenge-guide-modal';
    wrapper.hidden = true;
    wrapper.innerHTML = `
      <div class="tc-team-challenge-guide-dialog" role="dialog" aria-modal="true" aria-label="Challenge Guide">
        <div class="tc-team-challenge-guide-head">
          <h3 data-team-challenge-guide-title>Challenge Guide</h3>
          <button type="button" class="btn ghost" data-action="close-team-challenge-guide-modal">Close</button>
        </div>
        <label class="field full">
          <span>Guide Text</span>
          <textarea data-team-challenge-guide-text rows="10"></textarea>
        </label>
        <div class="field full tc-team-challenge-guide-actions">
          <button type="button" class="btn primary standard-primary-button" data-action="save-team-challenge-guide">Save Guide</button>
        </div>
        <p class="muted small" data-team-challenge-guide-status aria-live="polite"></p>
      </div>
    `;
    wrapper.addEventListener('keydown', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLTextAreaElement)) return;
      if (!target.matches('[data-team-challenge-guide-text]')) return;
      const isOneKey = event.key === '1' || event.code === 'Digit1' || event.code === 'Numpad1';
      if (event.ctrlKey && event.altKey && isOneKey) {
        event.preventDefault();
        applyHeaderShortcutToTextarea(target);
      }
    });
    wrapper.addEventListener('click', async (event) => {
      const rawTarget = event.target;
      const target = rawTarget instanceof Element
        ? rawTarget
        : (rawTarget instanceof Node ? rawTarget.parentElement : null);
      if (!(target instanceof Element)) return;
      if (target === wrapper || target.closest('[data-action="close-team-challenge-guide-modal"]')) {
        closeTeamChallengeGuideModal();
        return;
      }
      const saveButton = target.closest('[data-action="save-team-challenge-guide"]');
      if (!(saveButton instanceof HTMLButtonElement)) return;
      if (!teamChallengeGuideContext || teamChallengeGuideContext.saving) return;
      const textarea = wrapper.querySelector('[data-team-challenge-guide-text]');
      if (!(textarea instanceof HTMLTextAreaElement)) return;

      const context = teamChallengeGuideContext;
      context.saving = true;
      saveButton.disabled = true;
      setTeamChallengeGuideModalStatus('Saving...');
      try {
        const data = await postTaskAction('save_team_task_challenge_guide', {
          id: context.taskId,
          challenge_id: context.challengeId,
          challenge_guide: String(textarea.value || '').replace(/\r\n?/g, '\n')
        });
        const returnedTasks = Array.isArray(data.tasks) ? data.tasks : [];
        const keepPane = context.paneKey || '';
        if (returnedTasks.length) {
          renderTaskSubtabs(context.layout, returnedTasks, keepPane);
          try {
            window.TC_TASKS = returnedTasks;
          } catch {}
        }
        const activePane = findPaneByKey(context.layout, keepPane);
        if (activePane instanceof HTMLElement) {
          activateTaskTopPane(activePane, 'challenge-storage');
          setTeamChallengeListStatus(activePane, data.message || 'Challenge guide saved.');
        }
        closeTeamChallengeGuideModal();
      } catch (error) {
        setTeamChallengeGuideModalStatus(error?.message || 'Failed to save challenge guide.', true);
      } finally {
        if (teamChallengeGuideContext) {
          teamChallengeGuideContext.saving = false;
        }
        saveButton.disabled = false;
      }
    });
    const modalHost = document.querySelector('.tc-shell');
    (modalHost instanceof HTMLElement ? modalHost : document.body).appendChild(wrapper);
    teamChallengeGuideModalEl = wrapper;
    return wrapper;
  }

  function openTeamChallengeGuideModal(layout, pane, challenge) {
    if (!(layout instanceof HTMLElement) || !(pane instanceof HTMLElement) || !challenge) return;
    const taskId = String(pane.dataset.taskId || '').trim();
    const challengeId = String(challenge.id || '').trim();
    if (!taskId || !challengeId) return;
    const modal = ensureTeamChallengeGuideModal();
    const titleEl = modal.querySelector('[data-team-challenge-guide-title]');
    const textarea = modal.querySelector('[data-team-challenge-guide-text]');
    if (!(titleEl instanceof HTMLElement) || !(textarea instanceof HTMLTextAreaElement)) return;
    titleEl.textContent = `Guide - ${String(challenge.name || 'Challenge').trim() || 'Challenge'}`;
    textarea.value = String(challenge.guide || '').replace(/\r\n?/g, '\n');
    setTeamChallengeGuideModalStatus('', false);
    teamChallengeGuideContext = {
      layout,
      paneKey: String(pane.dataset.pane || '').trim(),
      taskId,
      challengeId,
      saving: false
    };
    modal.hidden = false;
    textarea.focus();
  }

  function applyHeaderShortcutToTextarea(textarea) {
    if (!(textarea instanceof HTMLTextAreaElement)) return;
    const value = String(textarea.value || '');
    const start = Math.max(0, textarea.selectionStart ?? 0);
    const end = Math.max(start, textarea.selectionEnd ?? start);

    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
    const lineEndIndex = value.indexOf('\n', end);
    const lineEnd = lineEndIndex >= 0 ? lineEndIndex : value.length;
    const line = value.slice(lineStart, lineEnd);
    const lineTrimmedLeft = line.replace(/^\s+/, '');
    const leftPaddingLength = line.length - lineTrimmedLeft.length;
    const leftPadding = line.slice(0, leftPaddingLength);
    const raw = lineTrimmedLeft.replace(/^#\s+/, '');
    const nextLine = `${leftPadding}# ${raw}`;

    textarea.value = `${value.slice(0, lineStart)}${nextLine}${value.slice(lineEnd)}`;
    const caret = lineStart + nextLine.length;
    textarea.selectionStart = caret;
    textarea.selectionEnd = caret;
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  const infoRateStateByTaskId = new Map();

  function getInfoRateTableColspanForPane(pane) {
    if (!(pane instanceof HTMLElement)) return 7;
    if (isTeamTaskType(pane.dataset.taskType || 'quiz')) return 8;
    return isDescribePhotoTaskType(pane.dataset.taskType || 'quiz') ? 8 : 7;
  }

  function formatTeamJoinTypeLabel(value) {
    const token = String(value ?? '').trim().toLowerCase();
    if (token === 'public_open' || token === 'public-open' || token === 'open') {
      return 'Public (Open)';
    }
    if (token === 'public_request' || token === 'public-request' || token === 'request') {
      return 'Public (Request)';
    }
    return 'Private';
  }

  function normalizeDescribeResultItem(item) {
    if (!item || typeof item !== 'object') return null;
    const photoId = String(item.photoId ?? item.photo_id ?? '').trim();
    if (!photoId) return null;
    return {
      photoId,
      photoName: String(item.photoName ?? item.photo_name ?? '').trim() || 'Photo',
      photoUrl: String(item.photoUrl ?? item.photo_url ?? '').trim(),
      articleFile: String(item.articleFile ?? item.article_file ?? '').trim(),
      wordCount: normalizeScoreValue(item.wordCount ?? item.word_count ?? 0)
    };
  }

  function getInfoRateElements(pane) {
    if (!(pane instanceof HTMLElement)) return null;
    const body = pane.querySelector('[data-task-info-rate-body]');
    const searchInput = pane.querySelector('[data-task-info-search]');
    const selectAll = pane.querySelector('[data-task-info-select-all]');
    const bulkScoreInput = pane.querySelector('[data-task-info-bulk-score]');
    const statusEl = pane.querySelector('[data-task-info-rate-status]');
    if (!(body instanceof HTMLElement) || !(searchInput instanceof HTMLInputElement)) {
      return null;
    }
    return {
      body,
      searchInput,
      selectAll: selectAll instanceof HTMLInputElement ? selectAll : null,
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
        isTeamMode: false,
        teams: [],
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
    const colspan = getInfoRateTableColspanForPane(pane);
    if (!silent) {
      controls.body.innerHTML = `<tr><td colspan="${colspan}" class="muted">Loading invitees...</td></tr>`;
    }
    try {
      const data = await postTaskAction('get_info_task_rate_data', { id: taskId });
      const state = getInfoRateState(taskId);
      if (!state) return;
      state.maxScore = normalizeScoreValue(data.maxScore ?? 0);
      const taskType = normalizeTaskType(data.taskType || pane.dataset.taskType || 'quiz');
      state.isTeamMode = taskType === 'team_task';
      if (state.isTeamMode) {
        state.teams = Array.isArray(data.teams) ? data.teams.map((row) => ({
          id: String(row.id || '').trim(),
          name: String(row.name || '').trim(),
          joinType: String(row.joinType || row.join_type || 'private').trim(),
          status: String(row.status || '').trim().toLowerCase() === 'started' ? 'started' : 'draft',
          challengeAccepted: Boolean(row.challengeAccepted ?? row.challenge_accepted ?? false),
          leaderWorkId: String(row.leaderWorkId || row.leader_work_id || '').trim(),
          leaderName: String(row.leaderName || row.leader_name || '').trim(),
          memberCount: normalizeScoreValue(row.memberCount ?? row.member_count ?? 0),
          minMembers: normalizeScoreValue(row.minMembers ?? row.min_members ?? 0),
          maxMembers: normalizeScoreValue(row.maxMembers ?? row.max_members ?? 0),
          assignedScore: normalizeScoreValue(row.assignedScore ?? row.assigned_score ?? 0),
          inviteCount: normalizeScoreValue(row.inviteCount ?? row.invite_count ?? 0),
          requestCount: normalizeScoreValue(row.requestCount ?? row.request_count ?? 0),
          members: Array.isArray(row.members) ? row.members.map((member) => ({
            workId: String(member.workId || '').trim(),
            firstName: String(member.firstName || '').trim(),
            lastName: String(member.lastName || '').trim(),
            phone: String(member.phone || '').trim(),
            score: normalizeScoreValue(member.score ?? 0)
          })) : []
        })).filter((row) => row.id) : [];
        state.invitees = [];
        state.selected = new Set();
      } else {
        state.teams = [];
        state.invitees = Array.isArray(data.invitees) ? data.invitees.map((row) => ({
          workId: String(row.workId || '').trim(),
          firstName: String(row.firstName || '').trim(),
          lastName: String(row.lastName || '').trim(),
          phone: String(row.phone || '').trim(),
          customScore: normalizeScoreValue(row.customScore ?? 0),
          describeResults: Array.isArray(row.describeResults ?? row.describe_results)
            ? (row.describeResults ?? row.describe_results)
              .map((item) => normalizeDescribeResultItem(item))
              .filter(Boolean)
            : []
        })) : [];
        state.selected = new Set();
      }
      renderInfoRateTable(pane);
      setInfoRateStatus(pane, '');
    } catch (error) {
      const failedText = isTeamTaskType(pane.dataset.taskType || 'quiz') ? 'Failed to load teams.' : 'Failed to load invitees.';
      controls.body.innerHTML = `<tr><td colspan="${colspan}" class="muted">${failedText}</td></tr>`;
      setInfoRateStatus(pane, error?.message || failedText, true);
    }
  }

  function renderTeamRateTable(pane, state, controls, colspan) {
    const query = String(state.query || '').trim().toLowerCase();
    const visibleRows = (Array.isArray(state.teams) ? state.teams : []).filter((row) => {
      if (!query) return true;
      const haystack = `${row.name} ${row.leaderName} ${row.leaderWorkId} ${row.status} ${row.joinType}`.toLowerCase();
      return haystack.includes(query);
    });
    if (!visibleRows.length) {
      controls.body.innerHTML = `<tr><td colspan="${colspan}" class="muted">No team found.</td></tr>`;
      return;
    }

    const startedRows = visibleRows.filter((team) => team.status === 'started');
    const otherRows = visibleRows.filter((team) => team.status !== 'started');

    const renderTeamRow = (team, sectionToken = 'other') => {
      const isStarted = sectionToken === 'started';
      const statusMarkup = isStarted
        ? '<span class="tc-team-started-flag"><i class="ri-flag-2-line" aria-hidden="true"></i><span>Started</span></span>'
        : '<span class="tc-team-status-muted">Draft</span>';
      const minMembers = Math.max(1, normalizeScoreValue(team.minMembers || 1));
      const maxMembers = Math.max(minMembers, normalizeScoreValue(team.maxMembers || minMembers));
      const memberCount = normalizeScoreValue(team.memberCount || 0);
      const startedTag = isStarted ? '<span class="tc-team-started-chip">Live</span>' : '';
      return `
        <tr class="tc-team-rate-row ${isStarted ? 'tc-team-rate-row--started' : ''}" data-team-id="${escapeHtml(team.id)}">
          <td>
            <div class="tc-team-name-cell">
              <span>${escapeHtml(team.name || 'Team')}</span>
              ${startedTag}
            </div>
          </td>
          <td>${statusMarkup}</td>
          <td>${escapeHtml(formatTeamJoinTypeLabel(team.joinType))}</td>
          <td>${escapeHtml(team.leaderName || team.leaderWorkId || '-')}</td>
          <td>${escapeHtml(String(memberCount))} / ${escapeHtml(String(maxMembers))} <small class="muted">(min ${escapeHtml(String(minMembers))})</small></td>
          <td>${escapeHtml(String(normalizeScoreValue(team.assignedScore || 0)))}</td>
          <td>
            <label class="tc-team-accepted-check-wrap">
              <input type="checkbox" data-team-challenge-accepted-check ${team.challengeAccepted ? 'checked' : ''} />
              <span>Challenge Accepted</span>
            </label>
          </td>
          <td><button type="button" class="btn primary standard-primary-button" data-action="team-row-preview">Team Preview</button></td>
        </tr>
      `;
    };

    const renderSection = (title, rows, sectionToken) => {
      if (!rows.length) return '';
      return `
        <tr class="tc-team-rate-section-row tc-team-rate-section-row--${escapeHtml(sectionToken)}">
          <td colspan="${colspan}">
            <div class="tc-team-rate-section-head">
              <span class="tc-team-rate-section-title">${escapeHtml(title)}</span>
              <span class="tc-team-rate-section-count">${escapeHtml(String(rows.length))}</span>
            </div>
          </td>
        </tr>
        ${rows.map((team) => renderTeamRow(team, sectionToken)).join('')}
      `;
    };

    controls.body.innerHTML = [
      renderSection('Started Teams', startedRows, 'started'),
      renderSection('Other Teams', otherRows, 'other')
    ].filter(Boolean).join('');
  }

  function renderInfoRateTable(pane) {
    if (!(pane instanceof HTMLElement)) return;
    const taskId = String(pane.dataset.taskId || '').trim();
    const state = getInfoRateState(taskId);
    const controls = getInfoRateElements(pane);
    if (!state || !controls) return;
    if (state.isTeamMode || isTeamTaskType(pane.dataset.taskType || 'quiz')) {
      renderTeamRateTable(pane, state, controls, getInfoRateTableColspanForPane(pane));
      return;
    }
    const isDescribeTask = isDescribePhotoTaskType(pane.dataset.taskType || 'quiz');
    const colspan = getInfoRateTableColspanForPane(pane);
    const query = String(state.query || '').trim().toLowerCase();
    const visibleRows = state.invitees.filter((row) => {
      if (!query) return true;
      const haystack = `${row.firstName} ${row.lastName} ${row.phone} ${row.workId}`.toLowerCase();
      return haystack.includes(query);
    });
    if (!visibleRows.length) {
      controls.body.innerHTML = `<tr><td colspan="${colspan}" class="muted">No invitee found.</td></tr>`;
      if (controls.selectAll instanceof HTMLInputElement) {
        controls.selectAll.checked = false;
        controls.selectAll.indeterminate = false;
      }
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
          ${isDescribeTask ? `<td><button type="button" class="btn ghost" data-action="info-row-results" ${Array.isArray(row.describeResults) && row.describeResults.length ? '' : 'disabled'}>Results</button></td>` : ''}
        </tr>
      `;
    }).join('');

    const visibleIds = visibleRows.map((row) => row.workId);
    const visibleSelected = visibleIds.filter((id) => state.selected.has(id)).length;
    if (controls.selectAll instanceof HTMLInputElement) {
      controls.selectAll.checked = visibleIds.length > 0 && visibleSelected === visibleIds.length;
      controls.selectAll.indeterminate = visibleSelected > 0 && visibleSelected < visibleIds.length;
    }
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

  function getInfoRateInviteeByWorkId(taskId, workId) {
    const state = getInfoRateState(taskId);
    if (!state) return null;
    const normalizedWorkId = String(workId || '').trim();
    if (!normalizedWorkId) return null;
    return state.invitees.find((row) => String(row.workId || '').trim() === normalizedWorkId) || null;
  }

  let describeResultsModalEl = null;
  let describeArticleModalEl = null;
  let describeResultsContext = null;
  let describeArticleContext = null;
  let teamPreviewModalEl = null;
  let teamPreviewContext = null;

  function closeDescribeResultsModal() {
    if (!(describeResultsModalEl instanceof HTMLElement)) return;
    describeResultsModalEl.hidden = true;
    describeResultsContext = null;
  }

  function closeDescribeArticleModal() {
    if (!(describeArticleModalEl instanceof HTMLElement)) return;
    describeArticleModalEl.hidden = true;
    describeArticleContext = null;
  }

  function ensureDescribeResultsModal() {
    if (describeResultsModalEl instanceof HTMLElement) {
      return describeResultsModalEl;
    }
    const wrapper = document.createElement('div');
    wrapper.className = 'tc-describe-results-modal';
    wrapper.hidden = true;
    wrapper.innerHTML = `
      <div class="tc-describe-results-dialog" role="dialog" aria-modal="true" aria-label="Describe Photo Results">
        <div class="tc-describe-results-head">
          <h3 data-describe-results-title>Results</h3>
          <button type="button" class="btn ghost" data-action="close-describe-results-modal">Close</button>
        </div>
        <p class="muted small" data-describe-results-hint></p>
        <div class="tc-describe-results-grid" data-describe-results-grid></div>
      </div>
    `;
    wrapper.addEventListener('click', (event) => {
      const rawTarget = event.target;
      const target = rawTarget instanceof Element
        ? rawTarget
        : (rawTarget instanceof Node ? rawTarget.parentElement : null);
      if (!(target instanceof Element)) return;
      if (target === wrapper || target.closest('[data-action="close-describe-results-modal"]')) {
        closeDescribeResultsModal();
        return;
      }
      const photoButton = target.closest('[data-action="open-describe-result-photo"]');
      if (!(photoButton instanceof HTMLButtonElement)) return;
      const context = describeResultsContext;
      if (!context || !(context.pane instanceof HTMLElement) || !context.invitee) return;
      const photoId = String(photoButton.getAttribute('data-photo-id') || '').trim();
      if (!photoId) return;
      const selectedResult = (context.invitee.describeResults || []).find((item) => String(item?.photoId || '').trim() === photoId) || null;
      if (!selectedResult) return;
      void openDescribeArticleModal(context.pane, context.invitee, selectedResult);
    });
    const modalHost = document.querySelector('.tc-shell');
    (modalHost instanceof HTMLElement ? modalHost : document.body).appendChild(wrapper);
    describeResultsModalEl = wrapper;
    return wrapper;
  }

  function ensureDescribeArticleModal() {
    if (describeArticleModalEl instanceof HTMLElement) {
      return describeArticleModalEl;
    }
    const wrapper = document.createElement('div');
    wrapper.className = 'tc-describe-article-modal';
    wrapper.hidden = true;
    wrapper.innerHTML = `
      <div class="tc-describe-article-dialog" role="dialog" aria-modal="true" aria-label="Describe Photo Text Result">
        <div class="tc-describe-article-head">
          <h3 data-describe-article-title>Result Text</h3>
          <button type="button" class="btn ghost" data-action="close-describe-article-modal">Close</button>
        </div>
        <p class="muted small" data-describe-article-meta></p>
        <div class="tc-describe-article-content" data-describe-article-text></div>
        <div class="tc-describe-article-score-box">
          <label class="field standard-width">
            <span>Custom Score</span>
            <input type="number" min="0" step="1" data-describe-article-score />
          </label>
          <div class="tc-describe-article-score-actions">
            <button type="button" class="btn secondary" data-action="describe-article-save-score">Save</button>
            <button type="button" class="btn primary standard-primary-button" data-action="describe-article-max-score">Max Score</button>
          </div>
        </div>
        <p class="muted small" data-describe-article-status></p>
      </div>
    `;
    wrapper.addEventListener('click', async (event) => {
      const rawTarget = event.target;
      const target = rawTarget instanceof Element
        ? rawTarget
        : (rawTarget instanceof Node ? rawTarget.parentElement : null);
      if (!(target instanceof Element)) return;
      if (target === wrapper || target.closest('[data-action="close-describe-article-modal"]')) {
        closeDescribeArticleModal();
        return;
      }
      const context = describeArticleContext;
      if (!context || !(context.pane instanceof HTMLElement) || !context.workId || !context.taskId) return;

      const saveButton = target.closest('[data-action="describe-article-save-score"]');
      if (saveButton instanceof HTMLButtonElement) {
        if (context.saving) return;
        const scoreInput = wrapper.querySelector('[data-describe-article-score]');
        if (!(scoreInput instanceof HTMLInputElement)) return;
        const scoreValue = normalizeScoreValue(scoreInput.value);
        context.saving = true;
        setDescribeArticleStatus('Saving...', false);
        const ok = await assignInfoScores(context.pane, [context.workId], 'custom', scoreValue);
        context.saving = false;
        if (ok) {
          const refreshed = getInfoRateInviteeByWorkId(context.taskId, context.workId);
          if (refreshed) {
            context.invitee = refreshed;
            scoreInput.value = String(normalizeScoreValue(refreshed.customScore));
          }
          setDescribeArticleStatus('Score saved.', false);
        } else {
          setDescribeArticleStatus('Failed to save score.', true);
        }
        return;
      }

      const maxButton = target.closest('[data-action="describe-article-max-score"]');
      if (maxButton instanceof HTMLButtonElement) {
        if (context.saving) return;
        context.saving = true;
        setDescribeArticleStatus('Saving...', false);
        const ok = await assignInfoScores(context.pane, [context.workId], 'max');
        context.saving = false;
        const scoreInput = wrapper.querySelector('[data-describe-article-score]');
        if (ok) {
          const refreshed = getInfoRateInviteeByWorkId(context.taskId, context.workId);
          if (refreshed) {
            context.invitee = refreshed;
            if (scoreInput instanceof HTMLInputElement) {
              scoreInput.value = String(normalizeScoreValue(refreshed.customScore));
            }
          }
          setDescribeArticleStatus('Max score applied.', false);
        } else {
          setDescribeArticleStatus('Failed to save score.', true);
        }
      }
    });
    const modalHost = document.querySelector('.tc-shell');
    (modalHost instanceof HTMLElement ? modalHost : document.body).appendChild(wrapper);
    describeArticleModalEl = wrapper;
    return wrapper;
  }

  function setDescribeArticleStatus(message, isError = false) {
    const modal = ensureDescribeArticleModal();
    const status = modal.querySelector('[data-describe-article-status]');
    if (!(status instanceof HTMLElement)) return;
    status.textContent = String(message || '');
    status.style.color = isError ? '#d1434a' : '';
  }

  function openDescribeResultsModal(pane, invitee) {
    const modal = ensureDescribeResultsModal();
    const title = modal.querySelector('[data-describe-results-title]');
    const hint = modal.querySelector('[data-describe-results-hint]');
    const grid = modal.querySelector('[data-describe-results-grid]');
    if (!(title instanceof HTMLElement) || !(hint instanceof HTMLElement) || !(grid instanceof HTMLElement)) return;

    const firstName = String(invitee?.firstName || '').trim();
    const lastName = String(invitee?.lastName || '').trim();
    const fullName = `${firstName} ${lastName}`.trim() || String(invitee?.workId || 'Invitee');
    title.textContent = `Results - ${fullName}`;
    hint.textContent = 'Click a photo to open the submitted text.';

    const results = Array.isArray(invitee?.describeResults) ? invitee.describeResults : [];
    if (!results.length) {
      grid.innerHTML = '<div class="muted">No saved results found for this user.</div>';
    } else {
      grid.innerHTML = results.map((result) => {
        const photoName = escapeHtml(String(result?.photoName || 'Photo'));
        const photoId = escapeHtml(String(result?.photoId || ''));
        const wordCount = normalizeScoreValue(result?.wordCount ?? 0);
        const photoUrl = String(result?.photoUrl || '').trim();
        return `
          <button type="button" class="tc-describe-result-photo-btn" data-action="open-describe-result-photo" data-photo-id="${photoId}">
            ${photoUrl ? `<img src="${escapeHtml(photoUrl)}" alt="${photoName}" loading="lazy" />` : '<div class="tc-describe-result-photo-fallback">No Preview</div>'}
            <div class="tc-describe-result-photo-name">${photoName}</div>
            <div class="tc-describe-result-photo-words">Words: ${escapeHtml(String(wordCount))}</div>
          </button>
        `;
      }).join('');
    }

    describeResultsContext = {
      pane,
      invitee
    };
    modal.hidden = false;
  }

  async function openDescribeArticleModal(pane, invitee, resultItem) {
    const modal = ensureDescribeArticleModal();
    const title = modal.querySelector('[data-describe-article-title]');
    const meta = modal.querySelector('[data-describe-article-meta]');
    const textArea = modal.querySelector('[data-describe-article-text]');
    const scoreInput = modal.querySelector('[data-describe-article-score]');
    if (
      !(title instanceof HTMLElement) ||
      !(meta instanceof HTMLElement) ||
      !(textArea instanceof HTMLElement) ||
      !(scoreInput instanceof HTMLInputElement)
    ) {
      return;
    }

    const taskId = String(pane.dataset.taskId || '').trim();
    const workId = String(invitee?.workId || '').trim();
    const photoId = String(resultItem?.photoId || '').trim();
    if (!taskId || !workId || !photoId) return;

    const state = getInfoRateState(taskId);
    const maxScore = state ? normalizeScoreValue(state.maxScore) : 0;
    const currentScore = normalizeScoreValue(invitee?.customScore ?? 0);

    const firstName = String(invitee?.firstName || '').trim();
    const lastName = String(invitee?.lastName || '').trim();
    const fullName = `${firstName} ${lastName}`.trim() || workId;

    title.textContent = `${resultItem?.photoName || 'Photo'} - ${fullName}`;
    meta.textContent = 'Loading result text...';
    textArea.textContent = '';
    scoreInput.max = String(maxScore);
    scoreInput.value = String(currentScore);
    setDescribeArticleStatus('', false);

    describeArticleContext = {
      pane,
      taskId,
      workId,
      photoId,
      invitee,
      resultItem,
      saving: false
    };
    modal.hidden = false;

    try {
      const data = await postTaskAction('get_describe_task_result_text', {
        id: taskId,
        work_id: workId,
        photo_id: photoId
      });
      const result = normalizeDescribeResultItem(data.result) || resultItem;
      const text = String(data.text || '');
      const wordCount = normalizeScoreValue(result?.wordCount ?? 0);
      meta.textContent = `Photo ID: ${String(result?.photoId || photoId)} - Words: ${wordCount}`;
      textArea.textContent = text;
    } catch (error) {
      meta.textContent = 'Failed to load result text.';
      textArea.textContent = String(error?.message || 'Result text is not available.');
      setDescribeArticleStatus(String(error?.message || 'Failed to load result text.'), true);
    }
  }

  function closeTeamPreviewModal() {
    if (!(teamPreviewModalEl instanceof HTMLElement)) return;
    teamPreviewModalEl.hidden = true;
    teamPreviewContext = null;
  }

  function setTeamPreviewStatus(message, isError = false) {
    if (!(teamPreviewModalEl instanceof HTMLElement)) return;
    const statusEl = teamPreviewModalEl.querySelector('[data-team-preview-status]');
    if (!(statusEl instanceof HTMLElement)) return;
    statusEl.textContent = String(message || '');
    statusEl.style.color = isError ? '#d1434a' : '';
  }

  function renderTeamPreviewModalContent() {
    if (!(teamPreviewModalEl instanceof HTMLElement)) return;
    const context = teamPreviewContext;
    if (!context || !context.team) return;
    const team = context.team;
    const titleEl = teamPreviewModalEl.querySelector('[data-team-preview-title]');
    const hintEl = teamPreviewModalEl.querySelector('[data-team-preview-hint]');
    const nameInput = teamPreviewModalEl.querySelector('[data-team-preview-name]');
    const joinSelect = teamPreviewModalEl.querySelector('[data-team-preview-join-type]');
    const statusSelect = teamPreviewModalEl.querySelector('[data-team-preview-status-select]');
    const leaderSelect = teamPreviewModalEl.querySelector('[data-team-preview-leader]');
    const scoreInput = teamPreviewModalEl.querySelector('[data-team-preview-score]');
    const applyScoreButton = teamPreviewModalEl.querySelector('[data-action="team-preview-apply-score"]');
    const maxScoreButton = teamPreviewModalEl.querySelector('[data-action="team-preview-max-score"]');
    const membersBody = teamPreviewModalEl.querySelector('[data-team-preview-members-body]');
    if (
      !(titleEl instanceof HTMLElement) ||
      !(hintEl instanceof HTMLElement) ||
      !(nameInput instanceof HTMLInputElement) ||
      !(joinSelect instanceof HTMLSelectElement) ||
      !(statusSelect instanceof HTMLSelectElement) ||
      !(leaderSelect instanceof HTMLSelectElement) ||
      !(scoreInput instanceof HTMLInputElement) ||
      !(applyScoreButton instanceof HTMLButtonElement) ||
      !(maxScoreButton instanceof HTMLButtonElement) ||
      !(membersBody instanceof HTMLElement)
    ) {
      return;
    }

    const members = Array.isArray(team.members) ? team.members : [];
    const memberCount = normalizeScoreValue(team.memberCount ?? members.length);
    const maxMembers = Math.max(1, normalizeScoreValue(team.maxMembers || 1));
    const minMembers = Math.max(1, normalizeScoreValue(team.minMembers || 1));
    const challengeAccepted = Boolean(team.challengeAccepted ?? false);

    titleEl.textContent = `Team Preview - ${team.name || 'Team'}`;
    hintEl.textContent = `Members: ${memberCount} / ${maxMembers} (min ${minMembers}) - Challenge Accepted: ${challengeAccepted ? 'Yes' : 'No'}`;
    nameInput.value = String(team.name || '');
    joinSelect.value = String(team.joinType || 'private');
    statusSelect.value = String(team.status || 'draft') === 'started' ? 'started' : 'draft';
    scoreInput.max = String(normalizeScoreValue(context.maxScore || 0));
    scoreInput.placeholder = `0-${normalizeScoreValue(context.maxScore || 0)}`;
    scoreInput.disabled = !challengeAccepted;
    applyScoreButton.disabled = !challengeAccepted;
    maxScoreButton.disabled = !challengeAccepted;

    leaderSelect.innerHTML = members.length
      ? members.map((member) => {
        const fullName = `${String(member.firstName || '').trim()} ${String(member.lastName || '').trim()}`.trim() || String(member.workId || '');
        const selected = String(member.workId || '') === String(team.leaderWorkId || '') ? ' selected' : '';
        return `<option value="${escapeHtml(String(member.workId || ''))}"${selected}>${escapeHtml(fullName)} (${escapeHtml(String(member.workId || ''))})</option>`;
      }).join('')
      : '<option value="">No members</option>';

    if (!members.length) {
      membersBody.innerHTML = '<tr><td colspan="6" class="muted">No members found in this team.</td></tr>';
    } else {
      membersBody.innerHTML = members.map((member) => {
        const fullName = `${String(member.firstName || '').trim()} ${String(member.lastName || '').trim()}`.trim();
        const role = String(member.status || '').trim() === 'leader' ? 'Team Admin' : 'Member';
        const isLeader = String(member.workId || '') === String(team.leaderWorkId || '');
        const disableRemove = members.length <= 1;
        return `
          <tr data-team-member-work-id="${escapeHtml(String(member.workId || ''))}">
            <td>${escapeHtml(fullName || '-')}</td>
            <td>${escapeHtml(String(member.phone || '-'))}</td>
            <td><code>${escapeHtml(String(member.workId || ''))}</code></td>
            <td>${escapeHtml(role)}</td>
            <td>${escapeHtml(String(normalizeScoreValue(member.score || 0)))}</td>
            <td>
              <button
                type="button"
                class="btn ghost"
                data-action="team-preview-remove-member"
                data-work-id="${escapeHtml(String(member.workId || ''))}"
                ${disableRemove ? 'disabled' : ''}
              >${isLeader ? 'Remove Admin' : 'Remove'}</button>
            </td>
          </tr>
        `;
      }).join('');
    }
  }

  function ensureTeamPreviewModal() {
    if (teamPreviewModalEl instanceof HTMLElement) {
      return teamPreviewModalEl;
    }
    const wrapper = document.createElement('div');
    wrapper.className = 'tc-team-preview-modal';
    wrapper.hidden = true;
    wrapper.innerHTML = `
      <div class="tc-team-preview-dialog" role="dialog" aria-modal="true" aria-label="Team Preview">
        <div class="tc-team-preview-head">
          <h3 data-team-preview-title>Team Preview</h3>
          <button type="button" class="btn ghost" data-action="close-team-preview-modal">Close</button>
        </div>
        <p class="muted small" data-team-preview-hint></p>
        <div class="tc-team-preview-grid">
          <label class="field standard-width">
            <span>Team Name</span>
            <input type="text" data-team-preview-name autocomplete="off" />
          </label>
          <label class="field standard-width">
            <span>Join Type</span>
            <select data-team-preview-join-type>
              <option value="private">Private</option>
              <option value="public_request">Public (Request)</option>
              <option value="public_open">Public (Open)</option>
            </select>
          </label>
          <label class="field standard-width">
            <span>Team Status</span>
            <select data-team-preview-status-select>
              <option value="draft">Draft</option>
              <option value="started">Started</option>
            </select>
          </label>
          <label class="field standard-width">
            <span>Team Admin</span>
            <select data-team-preview-leader></select>
          </label>
          <button type="button" class="btn primary standard-primary-button" data-action="team-preview-save-settings">Save Team Settings</button>
        </div>
        <div class="tc-team-preview-score-actions">
          <label class="field standard-width">
            <span>Custom Score (All Team Members)</span>
            <input type="number" min="0" step="1" data-team-preview-score />
          </label>
          <button type="button" class="btn secondary" data-action="team-preview-apply-score">Apply Custom Score</button>
          <button type="button" class="btn primary standard-primary-button" data-action="team-preview-max-score">Max Score</button>
        </div>
        <div class="table-wrapper tc-team-preview-members-wrap">
          <table class="tct-list-table tc-team-preview-members-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Phone</th>
                <th>Work ID</th>
                <th>Role</th>
                <th>Score</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody data-team-preview-members-body>
              <tr><td colspan="6" class="muted">Loading team...</td></tr>
            </tbody>
          </table>
        </div>
        <p class="muted small" data-team-preview-status></p>
      </div>
    `;
    wrapper.addEventListener('click', async (event) => {
      const rawTarget = event.target;
      const target = rawTarget instanceof Element
        ? rawTarget
        : (rawTarget instanceof Node ? rawTarget.parentElement : null);
      if (!(target instanceof Element)) return;

      if (target === wrapper || target.closest('[data-action="close-team-preview-modal"]')) {
        closeTeamPreviewModal();
        return;
      }

      const context = teamPreviewContext;
      if (!context || !(context.pane instanceof HTMLElement) || !context.taskId || !context.teamId) {
        return;
      }

      const saveSettingsButton = target.closest('[data-action="team-preview-save-settings"]');
      if (saveSettingsButton instanceof HTMLButtonElement) {
        if (context.saving) return;
        const teamNameInput = wrapper.querySelector('[data-team-preview-name]');
        const joinTypeInput = wrapper.querySelector('[data-team-preview-join-type]');
        const statusInput = wrapper.querySelector('[data-team-preview-status-select]');
        const leaderInput = wrapper.querySelector('[data-team-preview-leader]');
        if (
          !(teamNameInput instanceof HTMLInputElement) ||
          !(joinTypeInput instanceof HTMLSelectElement) ||
          !(statusInput instanceof HTMLSelectElement) ||
          !(leaderInput instanceof HTMLSelectElement)
        ) {
          return;
        }
        const teamName = String(teamNameInput.value || '').trim();
        if (!teamName) {
          setTeamPreviewStatus('Team name is required.', true);
          return;
        }
        context.saving = true;
        setTeamPreviewStatus('Saving...', false);
        try {
          await postTaskAction('team_task_admin_update_team', {
            id: context.taskId,
            team_id: context.teamId,
            team_name: teamName,
            join_type: String(joinTypeInput.value || 'private'),
            team_status: String(statusInput.value || 'draft'),
            leader_work_id: String(leaderInput.value || '')
          });
          await loadInfoRateDataIntoPane(context.pane, { silent: true });
          const teamData = await postTaskAction('team_task_admin_get_team', {
            id: context.taskId,
            team_id: context.teamId
          });
          context.maxScore = normalizeScoreValue(teamData.maxScore ?? context.maxScore);
          context.team = teamData.team || null;
          renderTeamPreviewModalContent();
          setTeamPreviewStatus('Team settings saved.');
        } catch (error) {
          setTeamPreviewStatus(error?.message || 'Failed to save team settings.', true);
        } finally {
          context.saving = false;
        }
        return;
      }

      const removeMemberButton = target.closest('[data-action="team-preview-remove-member"]');
      if (removeMemberButton instanceof HTMLButtonElement) {
        if (context.saving) return;
        const workId = String(removeMemberButton.getAttribute('data-work-id') || '').trim();
        if (!workId) return;
        if (!window.confirm('Remove this user from team?')) return;
        context.saving = true;
        setTeamPreviewStatus('Updating team...', false);
        try {
          await postTaskAction('team_task_admin_remove_member', {
            id: context.taskId,
            team_id: context.teamId,
            work_id: workId
          });
          await loadInfoRateDataIntoPane(context.pane, { silent: true });
          const teamData = await postTaskAction('team_task_admin_get_team', {
            id: context.taskId,
            team_id: context.teamId
          });
          context.maxScore = normalizeScoreValue(teamData.maxScore ?? context.maxScore);
          context.team = teamData.team || null;
          renderTeamPreviewModalContent();
          setTeamPreviewStatus('Team member removed.');
        } catch (error) {
          const message = String(error?.message || 'Failed to update team.');
          if (message.toLowerCase().includes('team not found')) {
            closeTeamPreviewModal();
            setInfoRateStatus(context.pane, 'Team was removed.');
          } else {
            setTeamPreviewStatus(message, true);
          }
        } finally {
          context.saving = false;
        }
        return;
      }

      const applyScoreButton = target.closest('[data-action="team-preview-apply-score"]');
      if (applyScoreButton instanceof HTMLButtonElement) {
        if (context.saving) return;
        const scoreInput = wrapper.querySelector('[data-team-preview-score]');
        if (!(scoreInput instanceof HTMLInputElement)) return;
        const members = Array.isArray(context.team?.members) ? context.team.members : [];
        const workIds = members
          .map((member) => String(member?.workId || '').trim())
          .filter((token) => token !== '');
        if (!workIds.length) {
          setTeamPreviewStatus('Team has no members to score.', true);
          return;
        }
        context.saving = true;
        setTeamPreviewStatus('Saving scores...', false);
        const scoreValue = normalizeScoreValue(scoreInput.value);
        const ok = await assignInfoScores(context.pane, workIds, 'custom', scoreValue);
        if (ok) {
          try {
            await loadInfoRateDataIntoPane(context.pane, { silent: true });
            const teamData = await postTaskAction('team_task_admin_get_team', {
              id: context.taskId,
              team_id: context.teamId
            });
            context.maxScore = normalizeScoreValue(teamData.maxScore ?? context.maxScore);
            context.team = teamData.team || null;
            renderTeamPreviewModalContent();
            setTeamPreviewStatus('Team score updated.');
          } catch (error) {
            setTeamPreviewStatus(error?.message || 'Score was saved but refresh failed.', true);
          }
        } else {
          setTeamPreviewStatus('Failed to save team score.', true);
        }
        context.saving = false;
        return;
      }

      const maxScoreButton = target.closest('[data-action="team-preview-max-score"]');
      if (maxScoreButton instanceof HTMLButtonElement) {
        if (context.saving) return;
        const members = Array.isArray(context.team?.members) ? context.team.members : [];
        const workIds = members
          .map((member) => String(member?.workId || '').trim())
          .filter((token) => token !== '');
        if (!workIds.length) {
          setTeamPreviewStatus('Team has no members to score.', true);
          return;
        }
        context.saving = true;
        setTeamPreviewStatus('Saving scores...', false);
        const ok = await assignInfoScores(context.pane, workIds, 'max');
        if (ok) {
          try {
            await loadInfoRateDataIntoPane(context.pane, { silent: true });
            const teamData = await postTaskAction('team_task_admin_get_team', {
              id: context.taskId,
              team_id: context.teamId
            });
            context.maxScore = normalizeScoreValue(teamData.maxScore ?? context.maxScore);
            context.team = teamData.team || null;
            renderTeamPreviewModalContent();
            setTeamPreviewStatus('Max score applied to team members.');
          } catch (error) {
            setTeamPreviewStatus(error?.message || 'Score was saved but refresh failed.', true);
          }
        } else {
          setTeamPreviewStatus('Failed to save team score.', true);
        }
        context.saving = false;
      }
    });
    const modalHost = document.querySelector('.tc-shell');
    (modalHost instanceof HTMLElement ? modalHost : document.body).appendChild(wrapper);
    teamPreviewModalEl = wrapper;
    return wrapper;
  }

  async function openTeamPreviewModal(pane, teamId) {
    if (!(pane instanceof HTMLElement)) return;
    const taskId = String(pane.dataset.taskId || '').trim();
    const normalizedTeamId = String(teamId || '').trim();
    if (!taskId || !normalizedTeamId) return;
    const modal = ensureTeamPreviewModal();
    const membersBody = modal.querySelector('[data-team-preview-members-body]');
    if (membersBody instanceof HTMLElement) {
      membersBody.innerHTML = '<tr><td colspan="6" class="muted">Loading team...</td></tr>';
    }
    setTeamPreviewStatus('', false);
    modal.hidden = false;
    try {
      const data = await postTaskAction('team_task_admin_get_team', {
        id: taskId,
        team_id: normalizedTeamId
      });
      teamPreviewContext = {
        pane,
        taskId,
        teamId: normalizedTeamId,
        maxScore: normalizeScoreValue(data.maxScore ?? 0),
        team: data.team || null,
        saving: false
      };
      renderTeamPreviewModalContent();
      setTeamPreviewStatus('');
    } catch (error) {
      closeTeamPreviewModal();
      setInfoRateStatus(pane, error?.message || 'Failed to load team preview.', true);
    }
  }

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (teamPreviewModalEl instanceof HTMLElement && !teamPreviewModalEl.hidden) {
      closeTeamPreviewModal();
      return;
    }
    if (teamChallengeGuideModalEl instanceof HTMLElement && !teamChallengeGuideModalEl.hidden) {
      closeTeamChallengeGuideModal();
      return;
    }
    if (describeArticleModalEl instanceof HTMLElement && !describeArticleModalEl.hidden) {
      closeDescribeArticleModal();
      return;
    }
    if (describeResultsModalEl instanceof HTMLElement && !describeResultsModalEl.hidden) {
      closeDescribeResultsModal();
    }
  });

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
      title: String(controls.titleInput.value || '').trim(),
      active: controls.activeToggle.checked ? '1' : '0',
      duration: controls.durationToggle.checked ? '1' : '0',
      dev_phase: controls.devPhaseToggle.checked ? '1' : '0',
      start_date: normalizeDate(controls.startDate.value),
      start_time: normalizeTime(controls.startTime.value),
      end_date: normalizeDate(controls.endDate.value),
      end_time: normalizeTime(controls.endTime.value)
    };
  }

  function collectTaskScoreFromPane(pane) {
    const controls = getTaskScoreControls(pane);
    if (!controls) return null;
    const isInfoTask = isInfoLikeTaskType(controls.taskType);
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
    controls.titleInput.value = String(task?.title || task?.tagCode || '');
    controls.activeToggle.checked = normalizeBool(task?.active);
    controls.durationToggle.checked = normalizeBool(task?.duration);
    controls.devPhaseToggle.checked = normalizeBool(task?.devPhase);
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
    const teamSettingsControls = getTaskTeamSettingsControls(pane);
    if (teamSettingsControls) {
      const nextTeamMin = Math.max(1, normalizeScoreValue(task?.teamMin));
      const nextTeamMax = Math.max(nextTeamMin, normalizeScoreValue(task?.teamMax));
      teamSettingsControls.teamMinInput.value = String(nextTeamMin);
      teamSettingsControls.teamMaxInput.value = String(nextTeamMax);
      teamSettingsControls.teamAdditionalNoteInput.value = String(task?.teamAdditionalNote || '');
      setTaskTeamSettingsSaveStatus(pane, '');
    }
    const infoControls = getTaskInfoContentControls(pane);
    if (infoControls) {
      infoControls.titleInput.value = String(task?.infoTitle || '');
      infoControls.textInput.value = String(task?.infoText || '');
      if (infoControls.guidePrefixInput instanceof HTMLTextAreaElement) {
        infoControls.guidePrefixInput.value = String(task?.guidePrefix || '');
      }
      if (infoControls.guideSuffixInput instanceof HTMLTextAreaElement) {
        infoControls.guideSuffixInput.value = String(task?.guideSuffix || '');
      }
      setTaskInfoContentSaveStatus(pane, '');
    }
    if (normalizeTaskType(task?.taskType || pane.dataset.taskType || 'quiz') === 'describe_photo') {
      applyDescribePhotoTaskStateFromTask(pane, task);
    }
    if (isTeamTaskType(task?.taskType || pane.dataset.taskType || 'quiz')) {
      applyTeamTaskChallengeStateFromTask(pane, task);
    }
    syncTaskPaneToggleState(pane);
    setTaskSaveStatus(pane, '');
    setTaskScoreSaveStatus(pane, '');
  }

  function buildTaskControlCardMarkup(task) {
    const titleText = task.title || task.tagCode;
    const isInfoTask = isInfoLikeTaskType(task.taskType);
    const taskTypeToken = normalizeTaskType(task.taskType);
    const isDescribePhotoTask = taskTypeToken === 'describe_photo';
    const isTeamTask = taskTypeToken === 'team_task';
    const typeLabel = taskTypeToken === 'describe_photo'
      ? 'Describe Photo Task'
      : (taskTypeToken === 'team_task' ? 'Team Task' : (isInfoTask ? 'Info Task' : 'Quiz Task'));
    const quizSrc = `mini%20apps/Task%20Club/TCQ.php?task_id=${encodeURIComponent(task.id)}`;
    const infoTitle = task.infoTitle || '';
    const infoText = task.infoText || '';
    const guidePrefix = String(task.guidePrefix || '');
    const guideSuffix = String(task.guideSuffix || '');
    const teamMin = Math.max(1, normalizeScoreValue(task.teamMin ?? 1));
    const teamMaxRaw = Math.max(1, normalizeScoreValue(task.teamMax ?? teamMin));
    const teamMax = Math.max(teamMin, teamMaxRaw);
    const teamAdditionalNote = String(task.teamAdditionalNote || '');
    const taskPhotos = normalizeDescribePhotoList(task?.taskPhotos);
    const taskChallenges = normalizeTeamChallengeList(task?.taskChallenges);
    const infoTaskTopTabs = isDescribePhotoTask
      ? '<button type="button" class="tc-task-top-item" aria-selected="false" data-task-top-trigger="information">Information</button><button type="button" class="tc-task-top-item" aria-selected="false" data-task-top-trigger="photo">Photo</button><button type="button" class="tc-task-top-item" aria-selected="false" data-task-top-trigger="invitees-rate">Invitees Rate</button>'
      : (isTeamTask
        ? '<button type="button" class="tc-task-top-item" aria-selected="false" data-task-top-trigger="information">Information</button><button type="button" class="tc-task-top-item" aria-selected="false" data-task-top-trigger="challenge-storage">Challenge Storage</button><button type="button" class="tc-task-top-item" aria-selected="false" data-task-top-trigger="team">Team</button><button type="button" class="tc-task-top-item" aria-selected="false" data-task-top-trigger="invitees-rate">Teams Rate</button>'
        : '<button type="button" class="tc-task-top-item" aria-selected="false" data-task-top-trigger="information">Information</button><button type="button" class="tc-task-top-item" aria-selected="false" data-task-top-trigger="invitees-rate">Invitees Rate</button>');
    const describePhotoSection = isDescribePhotoTask
      ? `
          <div class="tc-task-top-section" data-task-top-section="photo" hidden>
            <div class="card">
              <div class="section-header"><h3>Upload Photo</h3></div>
              <div class="form" style="gap:12px;">
                <label class="field standard-width">
                  <span>Name</span>
                  <input type="text" data-task-photo-name placeholder="Photo name" />
                </label>
                <div class="photo-uploader tc-task-photo-uploader">
                  <div class="photo-preview" aria-live="polite">
                    <img data-task-photo-preview-image class="hidden" alt="Selected task photo" />
                    <div data-task-photo-preview-placeholder class="photo-placeholder">No photo selected</div>
                  </div>
                  <div class="photo-actions">
                    <button type="button" class="btn" data-action="pick-task-photo">Choose photo</button>
                    <button type="button" class="btn ghost" data-action="clear-task-photo" disabled>Clear</button>
                    <button type="button" class="btn primary standard-primary-button" data-action="add-task-photo" disabled>Add Photo</button>
                  </div>
                </div>
                <p class="muted small" data-task-photo-upload-status aria-live="polite"></p>
              </div>
            </div>
            <div class="card">
              <div class="section-header"><h3>Photo List</h3></div>
              <div class="table-wrapper tc-task-photo-table-wrap">
                <table class="tct-list-table tc-task-photo-table">
                  <thead>
                    <tr>
                      <th>Photo</th>
                      <th>Name</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody data-task-photo-list-body>
                    ${taskPhotos.length ? '' : '<tr><td colspan="3" class="muted">No photos added yet.</td></tr>'}
                  </tbody>
                </table>
              </div>
              <p class="muted small" data-task-photo-list-status aria-live="polite"></p>
            </div>
          </div>
        `
      : '';
    const teamChallengeSection = isTeamTask
      ? `
          <div class="tc-task-top-section" data-task-top-section="challenge-storage" hidden>
            <div class="card">
              <div class="section-header"><h3>Add Challenge</h3></div>
              <div class="form" style="gap:12px;">
                <label class="field standard-width">
                  <span>Name</span>
                  <input type="text" data-team-challenge-name autocomplete="off" placeholder="Challenge name" />
                </label>
                <label class="field standard-width">
                  <span>Quantity</span>
                  <input type="number" min="1" step="1" value="1" data-team-challenge-quantity />
                </label>
                <div class="field full">
                  <button type="button" class="btn primary standard-primary-button" data-action="add-team-challenge">Add</button>
                </div>
                <p class="muted small" data-team-challenge-add-status aria-live="polite"></p>
              </div>
            </div>
            <div class="card">
              <div class="section-header"><h3>Challenge List</h3></div>
              <div class="table-wrapper tc-team-challenge-table-wrap">
                <table class="tct-list-table tc-team-challenge-table">
                  <thead>
                    <tr>
                      <th>Name</th>
                      <th>Quantity</th>
                      <th>Last</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody data-team-challenge-list-body>
                    ${taskChallenges.length ? '' : '<tr><td colspan="4" class="muted">No challenges added yet.</td></tr>'}
                  </tbody>
                </table>
              </div>
              <p class="muted small" data-team-challenge-list-status aria-live="polite"></p>
            </div>
          </div>
        `
      : '';
    const teamSettingsSection = isTeamTask
      ? `
          <div class="tc-task-top-section" data-task-top-section="team" hidden>
            <div class="card">
              <div class="section-header"><h3>Team Setting</h3></div>
              <div class="form" style="gap:12px;">
                <label class="field standard-width">
                  <span>Team Min</span>
                  <input type="number" min="1" step="1" data-task-field="teamMin" value="${escapeHtml(String(teamMin))}" />
                </label>
                <label class="field standard-width">
                  <span>Team Max</span>
                  <input type="number" min="1" step="1" data-task-field="teamMax" value="${escapeHtml(String(teamMax))}" />
                </label>
                <div class="field full">
                  <button type="button" class="btn primary standard-primary-button" data-action="save-team-settings">Save</button>
                </div>
                <p class="muted small" data-task-team-save-status aria-live="polite"></p>
              </div>
            </div>
            <div class="card">
              <div class="section-header"><h3>Team Additional Note</h3></div>
              <div class="form" style="gap:12px;">
                <label class="field full">
                  <span>Team Additional Note</span>
                  <textarea data-task-field="teamAdditionalNote" rows="8">${escapeHtml(teamAdditionalNote)}</textarea>
                </label>
                <div class="field full">
                  <button type="button" class="btn primary standard-primary-button" data-action="save-team-settings">Save</button>
                </div>
              </div>
            </div>
          </div>
        `
      : '';
    const inviteesRateColspan = isTeamTask ? 8 : (isDescribePhotoTask ? 8 : 7);
    const inviteesRateResultHeader = isDescribePhotoTask ? '<th>Results</th>' : '';
    const inviteesRateCardTitle = isTeamTask ? 'Team List Card' : 'Invitees List Card';
    const inviteesRateSearchLabel = isTeamTask ? 'Search Teams' : 'Search Invitees';
    const inviteesRateSearchPlaceholder = isTeamTask
      ? 'Search by team name or team admin'
      : 'Search by name, phone, Work ID';
    const inviteesRateBulkControls = isTeamTask ? '' : `
                <div class="tc-info-rate-bulk">
                  <label class="field standard-width">
                    <span>Custom Score (Selected)</span>
                    <input type="number" min="0" step="1" data-task-info-bulk-score />
                  </label>
                  <button type="button" class="btn secondary" data-action="info-bulk-apply">Apply Custom Score</button>
                  <button type="button" class="btn primary standard-primary-button" data-action="info-bulk-max">Max Score</button>
                </div>
              `;
    const inviteesRateTableWrapClass = isTeamTask ? 'tc-team-rate-table-wrap' : 'tc-info-rate-table-wrap';
    const inviteesRateTableClass = isTeamTask ? 'tc-team-rate-table' : 'tc-info-rate-table';
    const inviteesRateTableHeader = isTeamTask
      ? `
                        <th>Team Name</th>
                        <th>Status</th>
                        <th>Join Type</th>
                        <th>Team Admin</th>
                        <th>Members</th>
                        <th>Score</th>
                        <th>Challenge Accepted</th>
                        <th>Action</th>
                      `
      : `
                        <th><input type="checkbox" data-task-info-select-all /></th>
                        <th>First Name</th>
                        <th>Last Name</th>
                        <th>Phone</th>
                        <th>Work ID</th>
                        <th>Custom Score</th>
                        <th>Fast Score</th>
                        ${inviteesRateResultHeader}
                      `;
    const inviteesRateLoadingText = isTeamTask ? 'Loading teams...' : 'Loading invitees...';
    return `
      <div class="tc-task-top-shell" data-task-top-shell>
        <div class="tc-task-top-nav" role="tablist" aria-label="Task Tabs">
          <button type="button" class="tc-task-top-item active" aria-selected="true" data-task-top-trigger="control">Control Pane</button>
          ${isInfoTask
            ? infoTaskTopTabs
            : '<button type="button" class="tc-task-top-item" aria-selected="false" data-task-top-trigger="quiz">Quiz</button>'}
        </div>

        <div class="tc-task-top-section active" data-task-top-section="control">
          <div class="card">
            <div class="section-header">
              <h3>${escapeHtml(titleText)}</h3>
            </div>
            <p class="muted small">Tag Code: <code>${escapeHtml(task.tagCode)}</code> - Type: ${escapeHtml(typeLabel)}</p>
            <div class="form" style="gap:12px;">
              <label class="field standard-width">
                <span>Task Name</span>
                <input type="text" data-task-field="taskTitle" value="${escapeHtml(titleText)}" autocomplete="off" />
              </label>
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
                <label class="switch tc-switch">
                  <span class="switch-label">Dev Phase</span>
                  <span class="switch-toggle">
                    <input type="checkbox" data-task-field="devPhase" aria-label="Task Dev Phase" />
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
            ${isTeamTask ? `
              <div class="card">
                <div class="section-header"><h3>Guide Wrapper</h3></div>
                <div class="form" style="gap:12px;">
                  <label class="field full">
                    <span>Guide Prefix</span>
                    <textarea data-task-field="guidePrefix" rows="6">${escapeHtml(guidePrefix)}</textarea>
                  </label>
                  <label class="field full">
                    <span>Guide Suffix</span>
                    <textarea data-task-field="guideSuffix" rows="6">${escapeHtml(guideSuffix)}</textarea>
                  </label>
                  <div class="field full">
                    <button type="button" class="btn primary standard-primary-button" data-action="save-task-information">Save</button>
                  </div>
                </div>
              </div>
            ` : ''}
          </div>
          ${describePhotoSection}
          ${teamChallengeSection}
          ${teamSettingsSection}
          <div class="tc-task-top-section" data-task-top-section="invitees-rate" hidden>
            <div class="card">
              <div class="section-header"><h3>${inviteesRateCardTitle}</h3></div>
              <div class="form" style="gap:12px;">
                <label class="field standard-width">
                  <span>${inviteesRateSearchLabel}</span>
                  <input type="text" data-task-info-search placeholder="${inviteesRateSearchPlaceholder}" autocomplete="off" />
                </label>
                ${inviteesRateBulkControls}
                <div class="table-wrapper ${inviteesRateTableWrapClass}">
                  <table class="tct-list-table ${inviteesRateTableClass}">
                    <thead>
                      <tr>
                        ${inviteesRateTableHeader}
                      </tr>
                    </thead>
                    <tbody data-task-info-rate-body>
                      <tr><td colspan="${inviteesRateColspan}" class="muted">${inviteesRateLoadingText}</td></tr>
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
    if (TASK_CLUB_CSRF !== '') {
      formData.append('csrf', TASK_CLUB_CSRF);
    }
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
      if (fieldName === 'devPhase') {
        setTaskSaveStatus(pane, '');
        return;
      }
      if (
        fieldName === 'taskTitle' ||
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
      if (fieldName === 'teamMin' || fieldName === 'teamMax') {
        setTaskTeamSettingsSaveStatus(pane, '');
        return;
      }
      if (fieldName === 'teamAdditionalNote') {
        setTaskTeamSettingsSaveStatus(pane, '');
        return;
      }
      if (fieldName === 'infoTitle' || fieldName === 'infoText' || fieldName === 'guidePrefix' || fieldName === 'guideSuffix') {
        setTaskInfoContentSaveStatus(pane, '');
      }
    };

    layout.addEventListener('change', handleTaskFieldUpdate);
    layout.addEventListener('input', handleTaskFieldUpdate);
    layout.addEventListener('keydown', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLTextAreaElement)) return;
      if (!target.matches('[data-task-field="infoText"], [data-task-field="guidePrefix"], [data-task-field="guideSuffix"], [data-task-field="teamAdditionalNote"]')) return;
      const isOneKey = event.key === '1' || event.code === 'Digit1' || event.code === 'Numpad1';
      if (event.ctrlKey && event.altKey && isOneKey) {
        event.preventDefault();
        applyHeaderShortcutToTextarea(target);
      }
    });

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

      if (target.matches('[data-team-challenge-accepted-check]')) {
        if (!state.isTeamMode) return;
        const checkbox = target;
        if (!(checkbox instanceof HTMLInputElement)) return;
        const row = checkbox.closest('tr[data-team-id]');
        if (!(row instanceof HTMLTableRowElement)) return;
        const teamId = String(row.dataset.teamId || '').trim();
        if (!teamId) return;
        const nextValue = checkbox.checked;
        checkbox.disabled = true;
        setInfoRateStatus(pane, 'Saving...');
        void (async () => {
          try {
            await postTaskAction('team_task_admin_set_challenge_accepted', {
              id: taskId,
              team_id: teamId,
              challenge_accepted: nextValue ? '1' : '0'
            });
            state.teams = state.teams.map((team) => {
              if (String(team.id || '').trim() !== teamId) return team;
              return { ...team, challengeAccepted: nextValue };
            });
            renderInfoRateTable(pane);
            setInfoRateStatus(pane, 'Challenge accepted state updated.');
            if (
              teamPreviewContext
              && String(teamPreviewContext.taskId || '').trim() === taskId
              && String(teamPreviewContext.teamId || '').trim() === teamId
              && teamPreviewContext.team
            ) {
              teamPreviewContext.team = {
                ...teamPreviewContext.team,
                challengeAccepted: nextValue
              };
              renderTeamPreviewModalContent();
            }
          } catch (error) {
            checkbox.checked = !nextValue;
            setInfoRateStatus(pane, error?.message || 'Failed to save challenge accepted state.', true);
          } finally {
            checkbox.disabled = false;
          }
        })();
        return;
      }

      if (target.matches('[data-task-info-select-all]')) {
        const controls = getInfoRateElements(pane);
        if (!controls || !(controls.selectAll instanceof HTMLInputElement) || state.isTeamMode) return;
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
        if (state.isTeamMode) return;
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
        if (sectionKey === 'invitees-rate' && isInfoLikeTaskType(pane.dataset.taskType || 'quiz')) {
          void loadInfoRateDataIntoPane(pane);
        }
        if (sectionKey === 'photo') {
          renderDescribePhotoUploadCard(pane);
          renderDescribePhotoList(pane);
        }
        if (sectionKey === 'challenge-storage') {
          renderTeamChallengeList(pane);
        }
        return;
      }

      const pickTaskPhotoButton = target.closest('[data-action="pick-task-photo"]');
      if (pickTaskPhotoButton instanceof HTMLButtonElement) {
        const pane = pickTaskPhotoButton.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        if (normalizeTaskType(pane.dataset.taskType || 'quiz') !== 'describe_photo') return;
        const taskId = String(pane.dataset.taskId || '').trim();
        const state = getDescribePhotoState(taskId);
        const controls = getDescribePhotoPaneControls(pane);
        if (!state || !controls) return;
        if (typeof window.openPhotoChooserModal !== 'function') {
          setDescribePhotoUploadStatus(pane, 'Photo chooser is not available.', true);
          return;
        }
        window.openPhotoChooserModal({
          allowMultiple: false,
          onChoose: (selectedPhotos = []) => {
            const selected = toPhotoChooserPayload(selectedPhotos[0]);
            if (!selected) {
              setDescribePhotoUploadStatus(pane, 'No photo selected.', true);
              return;
            }
            state.selectedPhoto = selected;
            if (String(controls.nameInput.value || '').trim() === '') {
              controls.nameInput.value = selected.title || '';
            }
            renderDescribePhotoUploadCard(pane);
            setDescribePhotoUploadStatus(pane, 'Photo selected. Click Add Photo to save.');
          }
        });
        return;
      }

      const clearTaskPhotoButton = target.closest('[data-action="clear-task-photo"]');
      if (clearTaskPhotoButton instanceof HTMLButtonElement) {
        const pane = clearTaskPhotoButton.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        const taskId = String(pane.dataset.taskId || '').trim();
        const state = getDescribePhotoState(taskId);
        const controls = getDescribePhotoPaneControls(pane);
        if (!state || !controls) return;
        state.selectedPhoto = null;
        controls.nameInput.value = '';
        renderDescribePhotoUploadCard(pane);
        setDescribePhotoUploadStatus(pane, '');
        return;
      }

      const addTaskPhotoButton = target.closest('[data-action="add-task-photo"]');
      if (addTaskPhotoButton instanceof HTMLButtonElement) {
        const pane = addTaskPhotoButton.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        const taskId = String(pane.dataset.taskId || '').trim();
        if (!taskId) return;
        const state = getDescribePhotoState(taskId);
        const controls = getDescribePhotoPaneControls(pane);
        if (!state || !controls || !state.selectedPhoto) return;

        addTaskPhotoButton.disabled = true;
        setDescribePhotoUploadStatus(pane, 'Saving...');
        try {
          const data = await postTaskAction('add_describe_task_photo', {
            id: taskId,
            photo_name: String(controls.nameInput.value || '').trim(),
            photo_json: JSON.stringify(state.selectedPhoto)
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
            activateTaskTopPane(activePane, 'photo');
            setDescribePhotoUploadStatus(activePane, data.message || 'Photo added.');
            setDescribePhotoListStatus(activePane, '');
          }
        } catch (error) {
          setDescribePhotoUploadStatus(pane, error?.message || 'Failed to add photo.', true);
        } finally {
          addTaskPhotoButton.disabled = false;
        }
        return;
      }

      const saveTaskPhotoNameButton = target.closest('[data-action="save-task-photo-name"]');
      if (saveTaskPhotoNameButton instanceof HTMLButtonElement) {
        const pane = saveTaskPhotoNameButton.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        const taskId = String(pane.dataset.taskId || '').trim();
        const photoId = String(saveTaskPhotoNameButton.getAttribute('data-photo-id') || '').trim();
        if (!taskId || !photoId) return;
        const row = saveTaskPhotoNameButton.closest('tr[data-task-photo-row]');
        const nameInput = row?.querySelector('[data-task-photo-row-name]');
        if (!(nameInput instanceof HTMLInputElement)) return;
        const nextName = String(nameInput.value || '').trim();
        if (!nextName) {
          setDescribePhotoListStatus(pane, 'Photo name is required.', true);
          nameInput.focus();
          return;
        }

        saveTaskPhotoNameButton.disabled = true;
        setDescribePhotoListStatus(pane, 'Saving...');
        try {
          const data = await postTaskAction('rename_describe_task_photo', {
            id: taskId,
            photo_id: photoId,
            photo_name: nextName
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
            activateTaskTopPane(activePane, 'photo');
            setDescribePhotoListStatus(activePane, data.message || 'Photo name updated.');
          }
        } catch (error) {
          setDescribePhotoListStatus(pane, error?.message || 'Failed to update photo name.', true);
        } finally {
          saveTaskPhotoNameButton.disabled = false;
        }
        return;
      }

      const removeTaskPhotoButton = target.closest('[data-action="remove-task-photo"]');
      if (removeTaskPhotoButton instanceof HTMLButtonElement) {
        const pane = removeTaskPhotoButton.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        const taskId = String(pane.dataset.taskId || '').trim();
        const photoId = String(removeTaskPhotoButton.getAttribute('data-photo-id') || '').trim();
        if (!taskId || !photoId) return;

        removeTaskPhotoButton.disabled = true;
        setDescribePhotoListStatus(pane, 'Removing...');
        try {
          const data = await postTaskAction('remove_describe_task_photo', {
            id: taskId,
            photo_id: photoId
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
            activateTaskTopPane(activePane, 'photo');
            setDescribePhotoListStatus(activePane, data.message || 'Photo removed.');
          }
        } catch (error) {
          setDescribePhotoListStatus(pane, error?.message || 'Failed to remove photo.', true);
        } finally {
          removeTaskPhotoButton.disabled = false;
        }
        return;
      }

      const addTeamChallengeButton = target.closest('[data-action="add-team-challenge"]');
      if (addTeamChallengeButton instanceof HTMLButtonElement) {
        const pane = addTeamChallengeButton.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        const taskId = String(pane.dataset.taskId || '').trim();
        const controls = getTeamChallengeControls(pane);
        if (!taskId || !controls) return;
        const challengeName = String(controls.nameInput.value || '').trim();
        const quantity = Math.max(0, normalizeScoreValue(controls.quantityInput.value));
        if (!challengeName) {
          setTeamChallengeAddStatus(pane, 'Challenge name is required.', true);
          controls.nameInput.focus();
          return;
        }
        if (quantity < 1) {
          setTeamChallengeAddStatus(pane, 'Challenge quantity must be at least 1.', true);
          controls.quantityInput.focus();
          return;
        }

        addTeamChallengeButton.disabled = true;
        setTeamChallengeAddStatus(pane, 'Saving...');
        try {
          const data = await postTaskAction('add_team_task_challenge', {
            id: taskId,
            challenge_name: challengeName,
            quantity: String(quantity)
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
            activateTaskTopPane(activePane, 'challenge-storage');
            setTeamChallengeAddStatus(activePane, data.message || 'Challenge added.');
          }
        } catch (error) {
          setTeamChallengeAddStatus(pane, error?.message || 'Failed to add challenge.', true);
        } finally {
          addTeamChallengeButton.disabled = false;
        }
        return;
      }

      const openTeamChallengeGuideButton = target.closest('[data-action="open-team-challenge-guide"]');
      if (openTeamChallengeGuideButton instanceof HTMLButtonElement) {
        const pane = openTeamChallengeGuideButton.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        const taskId = String(pane.dataset.taskId || '').trim();
        const challengeId = String(openTeamChallengeGuideButton.getAttribute('data-challenge-id') || '').trim();
        if (!taskId || !challengeId) return;
        const challenge = getTeamChallengeById(taskId, challengeId);
        if (!challenge) {
          setTeamChallengeListStatus(pane, 'Challenge not found.', true);
          return;
        }
        openTeamChallengeGuideModal(layout, pane, challenge);
        return;
      }

      const saveTeamChallengeButton = target.closest('[data-action="save-team-challenge"]');
      if (saveTeamChallengeButton instanceof HTMLButtonElement) {
        const pane = saveTeamChallengeButton.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        const taskId = String(pane.dataset.taskId || '').trim();
        const challengeId = String(saveTeamChallengeButton.getAttribute('data-challenge-id') || '').trim();
        const row = saveTeamChallengeButton.closest('tr[data-team-challenge-row]');
        const nameInput = row?.querySelector('[data-team-challenge-row-name]');
        const quantityInput = row?.querySelector('[data-team-challenge-row-quantity]');
        const lastInput = row?.querySelector('[data-team-challenge-row-last]');
        if (
          !taskId ||
          !challengeId ||
          !(nameInput instanceof HTMLInputElement) ||
          !(quantityInput instanceof HTMLInputElement) ||
          !(lastInput instanceof HTMLInputElement)
        ) {
          return;
        }
        const challengeName = String(nameInput.value || '').trim();
        const quantity = Math.max(0, normalizeScoreValue(quantityInput.value));
        let last = Math.max(0, normalizeScoreValue(lastInput.value));
        if (!challengeName) {
          setTeamChallengeListStatus(pane, 'Challenge name is required.', true);
          nameInput.focus();
          return;
        }
        if (quantity === 0) {
          last = 0;
        } else if (last > quantity) {
          last = quantity;
          lastInput.value = String(last);
        }

        saveTeamChallengeButton.disabled = true;
        setTeamChallengeListStatus(pane, 'Saving...');
        try {
          const data = await postTaskAction('save_team_task_challenge', {
            id: taskId,
            challenge_id: challengeId,
            challenge_name: challengeName,
            quantity: String(quantity),
            last: String(last)
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
            activateTaskTopPane(activePane, 'challenge-storage');
            setTeamChallengeListStatus(activePane, data.message || 'Challenge updated.');
          }
        } catch (error) {
          setTeamChallengeListStatus(pane, error?.message || 'Failed to update challenge.', true);
        } finally {
          saveTeamChallengeButton.disabled = false;
        }
        return;
      }

      const removeTeamChallengeButton = target.closest('[data-action="remove-team-challenge"]');
      if (removeTeamChallengeButton instanceof HTMLButtonElement) {
        const pane = removeTeamChallengeButton.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        const taskId = String(pane.dataset.taskId || '').trim();
        const challengeId = String(removeTeamChallengeButton.getAttribute('data-challenge-id') || '').trim();
        if (!taskId || !challengeId) return;
        if (!window.confirm('Remove this challenge?')) return;

        removeTeamChallengeButton.disabled = true;
        setTeamChallengeListStatus(pane, 'Removing...');
        try {
          const data = await postTaskAction('remove_team_task_challenge', {
            id: taskId,
            challenge_id: challengeId
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
            activateTaskTopPane(activePane, 'challenge-storage');
            setTeamChallengeListStatus(activePane, data.message || 'Challenge removed.');
          }
        } catch (error) {
          setTeamChallengeListStatus(pane, error?.message || 'Failed to remove challenge.', true);
        } finally {
          removeTeamChallengeButton.disabled = false;
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

      const teamPreviewButton = target.closest('[data-action="team-row-preview"]');
      if (teamPreviewButton instanceof HTMLButtonElement) {
        const pane = teamPreviewButton.closest('.sub-pane[data-task-pane="1"]');
        const row = teamPreviewButton.closest('tr[data-team-id]');
        if (!(pane instanceof HTMLElement) || !(row instanceof HTMLTableRowElement)) return;
        const teamId = String(row.dataset.teamId || '').trim();
        if (!teamId) return;
        void openTeamPreviewModal(pane, teamId);
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

      const rowResultsButton = target.closest('[data-action="info-row-results"]');
      if (rowResultsButton instanceof HTMLButtonElement) {
        const pane = rowResultsButton.closest('.sub-pane[data-task-pane="1"]');
        const row = rowResultsButton.closest('tr[data-work-id]');
        if (!(pane instanceof HTMLElement) || !(row instanceof HTMLTableRowElement)) return;
        const taskId = String(pane.dataset.taskId || '').trim();
        const workId = String(row.dataset.workId || '').trim();
        if (!taskId || !workId) return;
        const invitee = getInfoRateInviteeByWorkId(taskId, workId);
        if (!invitee || !Array.isArray(invitee.describeResults) || !invitee.describeResults.length) {
          setInfoRateStatus(pane, 'No saved describe result for this invitee.', true);
          return;
        }
        openDescribeResultsModal(pane, invitee);
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

      const teamSaveButton = target.closest('[data-action="save-team-settings"]');
      if (teamSaveButton instanceof HTMLButtonElement) {
        const pane = teamSaveButton.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        const taskId = String(pane.dataset.taskId || '').trim();
        if (!taskId) return;

        const teamSettings = collectTaskTeamSettingsFromPane(pane);
        if (!teamSettings) return;

        const teamMinValue = normalizeScoreValue(teamSettings.team_min);
        const teamMaxValue = normalizeScoreValue(teamSettings.team_max);
        if (teamMinValue < 1 || teamMaxValue < 1) {
          setTaskTeamSettingsSaveStatus(pane, 'Team Min and Team Max must be at least 1.', true);
          return;
        }
        if (teamMaxValue < teamMinValue) {
          setTaskTeamSettingsSaveStatus(pane, 'Team Max must be equal to or greater than Team Min.', true);
          return;
        }

        teamSaveButton.disabled = true;
        setTaskTeamSettingsSaveStatus(pane, 'Saving...');
        try {
          const data = await postTaskAction('save_team_task_settings', {
            id: taskId,
            ...teamSettings
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
            activateTaskTopPane(activePane, 'team');
            setTaskTeamSettingsSaveStatus(activePane, data.message || 'Team settings saved.');
          }
        } catch (error) {
          setTaskTeamSettingsSaveStatus(pane, error?.message || 'Failed to save team settings.', true);
        } finally {
          const refreshedPane = pane.dataset.pane
            ? findPaneByKey(layout, pane.dataset.pane)
            : null;
          const refreshedButton = refreshedPane instanceof HTMLElement
            ? refreshedPane.querySelector('[data-action="save-team-settings"]')
            : null;
          if (refreshedButton instanceof HTMLButtonElement) {
            refreshedButton.disabled = false;
          } else {
            teamSaveButton.disabled = false;
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
