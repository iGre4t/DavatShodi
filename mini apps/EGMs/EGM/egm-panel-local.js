(() => {
  const TASKS_ENDPOINT = 'mini%20apps/EGMs/EGM/EGMT.php';
  const PERIOD_INVITES_ENDPOINT = 'mini%20apps/EGMs/EGM/period_invites.php';
  const PERIOD_INVITE_CARDS_ENDPOINT = 'mini%20apps/EGMs/EGM/period_invite_cards.php';
  const PERIOD_EXPORTS_ENDPOINT = 'mini%20apps/EGMs/EGM/period_exports.php';
  const GUEST_CONTROL_ENDPOINT = 'mini%20apps/EGMs/EGM/check-in.php';
  const INVITE_CARD_QR_ENDPOINT = 'modules/minor/QR%20Code%20Generator/generate.php';
  const LOGS_ENDPOINT = 'mini%20apps/EGMs/EGM/egm_logs.php';
  const EGM_TASKS_CHANGED_HANDLER_KEY = '__egmPanelTasksChangedHandler';
  const egmShellEl = document.querySelector('.egm-shell');
  const TASK_CLUB_CSRF = egmShellEl instanceof HTMLElement
    ? String(egmShellEl.dataset.egmCsrf || '').trim()
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
    return 'period';
  }

  function isInfoLikeTaskType(taskType) {
    const type = normalizeTaskType(taskType);
    return type === 'period' || type === 'info' || type === 'team_task' || type === 'describe_photo';
  }

  function isQuizLikeTaskType(taskType) {
    const type = normalizeTaskType(taskType);
    return type === 'quiz' || type === 'conditional_quiz';
  }

  function hasInformationPaneTaskType(taskType) {
    const type = normalizeTaskType(taskType);
    return isQuizLikeTaskType(type) || type === 'info' || type === 'team_task' || type === 'describe_photo';
  }

  function isDescribePhotoTaskType(taskType) {
    return normalizeTaskType(taskType) === 'describe_photo';
  }

  function isTeamTaskType(taskType) {
    return normalizeTaskType(taskType) === 'team_task';
  }

  function resolveDefaultTopPanesForTaskType(taskType) {
    const normalizedType = normalizeTaskType(taskType);
    if (normalizedType === 'period') {
      return ['control', 'information', 'invite', 'invitees', 'invite-card', 'export'];
    }
    if (normalizedType === 'conditional_quiz') {
      return ['control', 'information', 'quiz', 'crisis-control'];
    }
    if (normalizedType === 'quiz') {
      return ['control', 'information', 'quiz'];
    }
    if (normalizedType === 'info') {
      return ['control', 'information', 'invitees-rate'];
    }
    if (normalizedType === 'describe_photo') {
      return ['control', 'information', 'photo', 'invitees-rate'];
    }
    if (normalizedType === 'team_task') {
      return ['control', 'information', 'challenge-storage', 'team', 'invitees-rate'];
    }
    return ['control', 'information', 'invite', 'invitees'];
  }

  function normalizeAllowedTopPanes(value, taskType) {
    const defaults = resolveDefaultTopPanesForTaskType(taskType);
    const allowedSet = new Set(defaults);
    if (!Array.isArray(value) || !value.length) {
      return defaults;
    }
    const filtered = value.map((entry) => String(entry || '').trim().toLowerCase())
      .filter((paneKey) => paneKey && allowedSet.has(paneKey));
    if (!filtered.length) {
      return defaults;
    }
    return Array.from(new Set(filtered));
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

  function normalizeMinimumStayMinutes(value) {
    const parsed = Number.parseInt(String(value ?? '').trim(), 10);
    return Number.isFinite(parsed) ? Math.max(1, Math.min(1440, parsed)) : 1;
  }

  function buildMinimumStayOptions(selectedValue) {
    const selected = normalizeMinimumStayMinutes(selectedValue);
    const values = new Set([selected]);
    for (let minutes = 1; minutes <= 60; minutes += 1) values.add(minutes);
    for (let minutes = 75; minutes <= 180; minutes += 15) values.add(minutes);
    for (let minutes = 210; minutes <= 360; minutes += 30) values.add(minutes);
    for (let minutes = 420; minutes <= 1440; minutes += 60) values.add(minutes);
    const label = (minutes) => {
      if (minutes < 60) return `${minutes.toLocaleString('fa-IR')} دقیقه`;
      if (minutes % 60 === 0) return `${(minutes / 60).toLocaleString('fa-IR')} ساعت`;
      return `${Math.floor(minutes / 60).toLocaleString('fa-IR')} ساعت و ${(minutes % 60).toLocaleString('fa-IR')} دقیقه`;
    };
    return Array.from(values).sort((left, right) => left - right)
      .map((minutes) => `<option value="${minutes}"${minutes === selected ? ' selected' : ''}>${label(minutes)}</option>`)
      .join('');
  }

  function normalizeTask(task, index) {
    const raw = task && typeof task === 'object' ? task : {};
    const id = String(raw.id ?? '').trim();
    const title = String(raw.title ?? '').trim();
    const tagCode = String(raw.tagCode ?? raw.tag_code ?? '').trim().toUpperCase();
    const taskType = 'period';
    const parsedOrder = Number.parseInt(raw.order, 10);
    return {
      id,
      title,
      tagCode,
      taskType,
      taskAccessEnabled: normalizeBool(raw.taskAccessEnabled ?? raw.task_access_enabled ?? true),
      allowedTopPanes: normalizeAllowedTopPanes(raw.allowedTopPanes ?? raw.allowed_top_panes ?? [], taskType),
      active: normalizeBool(raw.active),
      duration: normalizeBool(raw.duration),
      quitRequired: normalizeBool(raw.quitRequired ?? raw.quit_required ?? false),
      quitTimelineRequired: normalizeBool(raw.quitTimelineRequired ?? raw.quit_timeline_required ?? true),
      minimumStayMinutes: normalizeMinimumStayMinutes(raw.minimumStayMinutes ?? raw.minimum_stay_minutes ?? 1),
      devPhase: normalizeBool(raw.devPhase ?? raw.dev_phase ?? false),
      startDate: normalizeDate(raw.startDate ?? raw.start_date ?? ''),
      startTime: normalizeTime(raw.startTime ?? raw.start_time ?? ''),
      endDate: normalizeDate(raw.endDate ?? raw.end_date ?? ''),
      endTime: normalizeTime(raw.endTime ?? raw.end_time ?? ''),
      enterDeadlineDate: normalizeDate(raw.enterDeadlineDate ?? raw.enter_deadline_date ?? ''),
      enterDeadlineTime: normalizeTime(raw.enterDeadlineTime ?? raw.enter_deadline_time ?? ''),
      quitOpeningDate: normalizeDate(raw.quitOpeningDate ?? raw.quit_opening_date ?? ''),
      quitOpeningTime: normalizeTime(raw.quitOpeningTime ?? raw.quit_opening_time ?? ''),
      score: normalizeScoreValue(raw.score ?? raw.taskScore ?? 0),
      afterEndtimeScore: normalizeScoreValue(raw.afterEndtimeScore ?? raw.after_endtime_score ?? 0),
      hasGoldenTime: normalizeBool(raw.hasGoldenTime ?? raw.has_golden_time ?? true),
      anotherChanceIfZero: normalizeBool(raw.anotherChanceIfZero ?? raw.another_chance_if_zero ?? false),
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
      return 'پایان‌یافته';
    }
    if (startSeconds !== null && nowSeconds >= startSeconds) {
      return 'فعال';
    }
    if (startSeconds !== null && nowSeconds < startSeconds) {
      return 'در انتظار شروع';
    }
    return 'در انتظار شروع';
  }

  function deriveStatusFromSettings(settings) {
    const active = normalizeBool(settings.active);
    const duration = normalizeBool(settings.duration);
    const quitRequired = normalizeBool(settings.quitRequired);
    const quitTimelineRequired = normalizeBool(settings.quitTimelineRequired ?? true);
    const startDate = normalizeDate(settings.startDate);
    const startTime = normalizeTime(settings.startTime);
    const endDate = normalizeDate(settings.endDate);
    const endTime = normalizeTime(settings.endTime);
    const enterDeadlineDate = normalizeDate(settings.enterDeadlineDate);
    const enterDeadlineTime = normalizeTime(settings.enterDeadlineTime);
    const quitOpeningDate = normalizeDate(settings.quitOpeningDate);
    const quitOpeningTime = normalizeTime(settings.quitOpeningTime);

    if (duration) {
      const today = getTehranDateTimeParts().date;
      if (!startDate || !today) {
        return { label: 'غیرفعال', tone: 'inactive' };
      }

      const startRelation = compareGregorianDates(startDate, today);
      const endRelation = compareGregorianDates(endDate, today);

      if (startRelation === 1) {
        return { label: 'در انتظار شروع', tone: 'upcoming' };
      }
      if (endRelation !== null && endRelation === -1) {
        return { label: 'پایان‌یافته', tone: 'ended' };
      }

      if (startRelation === 0 || endRelation === 0) {
        const nowSeconds = getCurrentTehranSeconds();
        const startSeconds = parseTimeToSeconds(startTime);
        const endSeconds = parseTimeToSeconds(endTime);
        if (startRelation === 0 && startSeconds !== null && nowSeconds < startSeconds) {
          return { label: 'در انتظار شروع', tone: 'upcoming' };
        }
        if (endRelation === 0 && endSeconds !== null && nowSeconds >= endSeconds) {
          return { label: 'پایان‌یافته', tone: 'ended' };
        }
      }

      if (quitRequired) {
        if (!quitTimelineRequired) return { label: 'فعال / ورود و خروج شناور', tone: 'active' };
        const startAt = startDate && startTime ? `${startDate}T${startTime}` : '';
        const enterDeadlineAt = enterDeadlineDate && enterDeadlineTime ? `${enterDeadlineDate}T${enterDeadlineTime}` : '';
        const quitOpeningAt = quitOpeningDate && quitOpeningTime ? `${quitOpeningDate}T${quitOpeningTime}` : '';
        const endAt = endDate && endTime ? `${endDate}T${endTime}` : '';
        if (!startAt || !enterDeadlineAt || !quitOpeningAt || !endAt || !(startAt < enterDeadlineAt && enterDeadlineAt < quitOpeningAt && quitOpeningAt < endAt)) {
          return { label: 'زمان‌بندی نامعتبر', tone: 'inactive' };
        }
        const nowParts = getTehranDateTimeParts();
        const nowAt = `${nowParts.date}T${String(nowParts.time || '').slice(0, 5)}`;
        if (nowAt < enterDeadlineAt) return { label: 'فعال / زمان ورود', tone: 'active' };
        if (nowAt < quitOpeningAt) return { label: 'فعال / زمان ایمن', tone: 'immune' };
        return { label: 'فعال / زمان خروج', tone: 'quit' };
      }
      return { label: 'فعال', tone: 'active' };
    }

    return active
      ? { label: 'فعال', tone: 'active' }
      : { label: 'غیرفعال', tone: 'inactive' };
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
    let key = `egm-task-${part}`;
    let counter = 2;
    while (usedKeys.has(key)) {
      key = `egm-task-${part}-${counter}`;
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
    if (targetPane === 'egm-logs') {
      loadEventGuestManagerLogs(layout);
    }
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

  function applyTaskTopPaneAccess(taskPane, task) {
    if (!(taskPane instanceof HTMLElement)) return;
    const shell = taskPane.querySelector('[data-task-top-shell]');
    if (!(shell instanceof HTMLElement)) return;
    const allowed = new Set(normalizeAllowedTopPanes(task?.allowedTopPanes || [], task?.taskType || 'quiz'));
    shell.querySelectorAll('[data-task-top-trigger]').forEach((button) => {
      if (!(button instanceof HTMLElement)) return;
      const key = String(button.getAttribute('data-task-top-trigger') || '').trim().toLowerCase();
      if (key && !allowed.has(key)) {
        button.remove();
      }
    });
    shell.querySelectorAll('[data-task-top-section]').forEach((section) => {
      if (!(section instanceof HTMLElement)) return;
      const key = String(section.getAttribute('data-task-top-section') || '').trim().toLowerCase();
      if (key && !allowed.has(key)) {
        section.remove();
      }
    });
    const remainingTriggers = shell.querySelectorAll('[data-task-top-trigger]');
    if (remainingTriggers.length > 0) {
      return;
    }
    shell.innerHTML = `
      <div class="card">
        <div class="section-header"><h3>No Access</h3></div>
        <p class="muted">شما به هیچ‌یک از بخش‌های این بازه دسترسی ندارید.</p>
      </div>
    `;
  }

  function getTaskPaneControls(pane) {
    if (!(pane instanceof HTMLElement)) return null;
    const titleInput = pane.querySelector('[data-task-field="taskTitle"]');
    const activeToggle = pane.querySelector('[data-task-field="active"]');
    const durationToggle = pane.querySelector('[data-task-field="duration"]');
    const quitRequiredToggle = pane.querySelector('[data-task-field="quitRequired"]');
    const quitTimelineRequiredToggle = pane.querySelector('[data-task-field="quitTimelineRequired"]');
    const minimumStayMinutes = pane.querySelector('[data-task-field="minimumStayMinutes"]');
    const minimumStayContainer = pane.querySelector('[data-quit-minimum-stay]');
    const devPhaseToggle = pane.querySelector('[data-task-field="devPhase"]');
    const startDate = pane.querySelector('[data-task-field="startDate"]');
    const startTime = pane.querySelector('[data-task-field="startTime"]');
    const endDate = pane.querySelector('[data-task-field="endDate"]');
    const endTime = pane.querySelector('[data-task-field="endTime"]');
    const enterDeadlineDate = pane.querySelector('[data-task-field="enterDeadlineDate"]');
    const enterDeadlineTime = pane.querySelector('[data-task-field="enterDeadlineTime"]');
    const quitOpeningDate = pane.querySelector('[data-task-field="quitOpeningDate"]');
    const quitOpeningTime = pane.querySelector('[data-task-field="quitOpeningTime"]');
    const quitTimeline = pane.querySelector('[data-quit-timeline]');
    const statusEl = pane.querySelector('[data-task-status]');
    const saveStatusEl = pane.querySelector('[data-task-save-status]');
    const saveButton = pane.querySelector('[data-action="save-task-settings"]');
    if (
      !(titleInput instanceof HTMLInputElement) ||
      !(activeToggle instanceof HTMLInputElement) ||
      !(durationToggle instanceof HTMLInputElement) ||
      !(quitRequiredToggle instanceof HTMLInputElement) ||
      !(quitTimelineRequiredToggle instanceof HTMLInputElement) ||
      !(minimumStayMinutes instanceof HTMLSelectElement) ||
      !(devPhaseToggle instanceof HTMLInputElement) ||
      !(startDate instanceof HTMLInputElement) ||
      !(startTime instanceof HTMLSelectElement) ||
      !(endDate instanceof HTMLInputElement) ||
      !(endTime instanceof HTMLSelectElement) ||
      !(enterDeadlineDate instanceof HTMLInputElement) ||
      !(enterDeadlineTime instanceof HTMLSelectElement) ||
      !(quitOpeningDate instanceof HTMLInputElement) ||
      !(quitOpeningTime instanceof HTMLSelectElement)
    ) {
      return null;
    }
    return {
      titleInput,
      activeToggle,
      durationToggle,
      quitRequiredToggle,
      quitTimelineRequiredToggle,
      minimumStayMinutes,
      minimumStayContainer: minimumStayContainer instanceof HTMLElement ? minimumStayContainer : null,
      devPhaseToggle,
      startDate,
      startTime,
      endDate,
      endTime,
      enterDeadlineDate,
      enterDeadlineTime,
      quitOpeningDate,
      quitOpeningTime,
      quitTimeline: quitTimeline instanceof HTMLElement ? quitTimeline : null,
      statusEl: statusEl instanceof HTMLElement ? statusEl : null,
      saveStatusEl: saveStatusEl instanceof HTMLElement ? saveStatusEl : null,
      saveButton: saveButton instanceof HTMLButtonElement ? saveButton : null
    };
  }

  function getTaskScoreControls(pane) {
    if (!(pane instanceof HTMLElement)) return null;
    const scoreInput = pane.querySelector('[data-task-field="score"]');
    const hasGoldenTimeToggle = pane.querySelector('[data-task-field="hasGoldenTime"]');
    const afterEndtimeScoreInput = pane.querySelector('[data-task-field="afterEndtimeScore"]');
    const saveStatusEl = pane.querySelector('[data-task-score-save-status]');
    const saveButton = pane.querySelector('[data-action="save-task-score-system"]');
    if (!(scoreInput instanceof HTMLInputElement)) {
      return null;
    }
    const taskType = normalizeTaskType(pane.dataset.taskType || 'quiz');
    return {
      scoreInput,
      hasGoldenTimeToggle: hasGoldenTimeToggle instanceof HTMLInputElement ? hasGoldenTimeToggle : null,
      afterEndtimeScoreInput: afterEndtimeScoreInput instanceof HTMLInputElement ? afterEndtimeScoreInput : null,
      taskType,
      saveStatusEl: saveStatusEl instanceof HTMLElement ? saveStatusEl : null,
      saveButton: saveButton instanceof HTMLButtonElement ? saveButton : null
    };
  }

  function getTaskCrisisControls(pane) {
    if (!(pane instanceof HTMLElement)) return null;
    if (normalizeTaskType(pane.dataset.taskType || 'quiz') !== 'conditional_quiz') return null;
    const anotherChanceIfZeroToggle = pane.querySelector('[data-task-field="anotherChanceIfZero"]');
    const saveStatusEl = pane.querySelector('[data-task-crisis-save-status]');
    const saveButton = pane.querySelector('[data-action="save-conditional-quiz-crisis-control"]');
    if (!(anotherChanceIfZeroToggle instanceof HTMLInputElement)) {
      return null;
    }
    return {
      anotherChanceIfZeroToggle,
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

  function syncTaskScoreGoldenTimeState(pane) {
    const controls = getTaskScoreControls(pane);
    if (!controls || controls.taskType !== 'conditional_quiz') return;
    const enabled = controls.hasGoldenTimeToggle instanceof HTMLInputElement
      ? controls.hasGoldenTimeToggle.checked
      : true;
    if (controls.afterEndtimeScoreInput instanceof HTMLInputElement) {
      controls.afterEndtimeScoreInput.disabled = !enabled;
      controls.afterEndtimeScoreInput.setAttribute('aria-disabled', enabled ? 'false' : 'true');
      if (!enabled) {
        controls.afterEndtimeScoreInput.value = '0';
      }
    }
  }

  function setTaskCrisisSaveStatus(pane, message, isError = false) {
    const controls = getTaskCrisisControls(pane);
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
      controls.listBody.innerHTML = '<tr><td colspan="3" class="muted">هنوز عکسی اضافه نشده است.</td></tr>';
      return;
    }

    controls.listBody.innerHTML = state.photos.map((photo) => {
      const thumbUrl = buildDescribePhotoPreviewUrl(photo);
      const escapedName = escapeHtml(photo.name || '');
      const escapedPhotoId = escapeHtml(photo.id || '');
      return `
        <tr data-task-photo-row="${escapedPhotoId}">
          <td>
            ${thumbUrl ? `<img class="egm-task-photo-thumb" src="${escapeHtml(thumbUrl)}" alt="${escapedName || 'Task photo'}" loading="lazy" />` : '<span class="muted">No Preview</span>'}
          </td>
          <td>
            <input
              type="text"
              class="egm-task-photo-name-input"
              data-task-photo-row-name
              value="${escapedName}"
            />
          </td>
          <td>
            <div class="egm-task-photo-row-actions">
              <button type="button" class="btn ghost" data-action="save-task-photo-name" data-photo-id="${escapedPhotoId}">Save</button>
              <button type="button" class="btn ghost egm-btn-danger" data-action="remove-task-photo" data-photo-id="${escapedPhotoId}">Remove</button>
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
            <input type="text" class="egm-team-challenge-name-input" data-team-challenge-row-name value="${name}" />
          </td>
          <td>
            <input type="number" min="0" step="1" class="egm-team-challenge-num-input" data-team-challenge-row-quantity value="${escapeHtml(String(quantity))}" />
          </td>
          <td>
            <input type="number" min="0" step="1" class="egm-team-challenge-num-input" data-team-challenge-row-last value="${escapeHtml(String(last))}" />
          </td>
          <td>
            <div class="egm-team-challenge-row-actions">
              <button type="button" class="btn ghost" data-action="open-team-challenge-guide" data-challenge-id="${challengeId}">Guide</button>
              <button type="button" class="btn ghost" data-action="save-team-challenge" data-challenge-id="${challengeId}">Save</button>
              <button type="button" class="btn ghost egm-btn-danger" data-action="remove-team-challenge" data-challenge-id="${challengeId}">Remove</button>
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
    wrapper.className = 'egm-team-challenge-guide-modal';
    wrapper.hidden = true;
    wrapper.innerHTML = `
      <div class="egm-team-challenge-guide-dialog" role="dialog" aria-modal="true" aria-label="Challenge Guide">
        <div class="egm-team-challenge-guide-head">
          <h3 data-team-challenge-guide-title>Challenge Guide</h3>
          <button type="button" class="btn ghost" data-action="close-team-challenge-guide-modal">Close</button>
        </div>
        <label class="field full">
          <span>Guide Text</span>
          <textarea data-team-challenge-guide-text rows="10"></textarea>
        </label>
        <div class="field full egm-team-challenge-guide-actions">
          <button type="button" class="btn primary standard-primary-button" data-action="save-team-challenge-guide">Save Guide</button>
        </div>
        <p class="muted small" data-team-challenge-guide-status aria-live="polite"></p>
      </div>
    `;
    wrapper.addEventListener('keydown', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLTextAreaElement)) return;
      if (!target.matches('[data-team-challenge-guide-text]')) return;
      const hasCtrl = event.ctrlKey || event.metaKey;
      const isBoldKey = isShortcutLetterKey(event, 'B');
      const isListKey = isShortcutLetterKey(event, 'L');
      if (hasCtrl && !event.altKey && isBoldKey) {
        event.preventDefault();
        event.stopPropagation();
        applyBoldShortcutToTextarea(target);
        return;
      }
      if (hasCtrl && !event.altKey && isListKey) {
        event.preventDefault();
        event.stopPropagation();
        applyListShortcutToTextarea(target);
        return;
      }
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
            window.EGM_TASKS = returnedTasks;
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
    const modalHost = document.querySelector('.egm-shell');
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

  function isShortcutLetterKey(event, letter) {
    const token = String(letter || '').trim().toUpperCase();
    if (!/^[A-Z]$/.test(token)) return false;
    const byKey = String(event?.key || '').toLowerCase() === token.toLowerCase();
    const byCode = String(event?.code || '').toUpperCase() === `KEY${token}`;
    return byKey || byCode;
  }

  function replaceTextareaRange(textarea, start, end, replacement, selectionStart = null, selectionEnd = null) {
    if (!(textarea instanceof HTMLTextAreaElement)) return;
    const value = String(textarea.value || '');
    const safeStart = Math.max(0, Math.min(value.length, Number.isFinite(start) ? start : 0));
    const safeEnd = Math.max(safeStart, Math.min(value.length, Number.isFinite(end) ? end : safeStart));
    const nextValue = `${value.slice(0, safeStart)}${replacement}${value.slice(safeEnd)}`;
    textarea.value = nextValue;
    const defaultCaret = safeStart + String(replacement || '').length;
    const nextSelectionStart = Number.isFinite(selectionStart) ? Math.max(0, Math.min(nextValue.length, selectionStart)) : defaultCaret;
    const nextSelectionEnd = Number.isFinite(selectionEnd) ? Math.max(nextSelectionStart, Math.min(nextValue.length, selectionEnd)) : nextSelectionStart;
    textarea.selectionStart = nextSelectionStart;
    textarea.selectionEnd = nextSelectionEnd;
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function applyBoldShortcutToTextarea(textarea) {
    if (!(textarea instanceof HTMLTextAreaElement)) return;
    const value = String(textarea.value || '');
    const start = Math.max(0, textarea.selectionStart ?? 0);
    const end = Math.max(start, textarea.selectionEnd ?? start);
    if (start === end) {
      const wrapped = '<b></b>';
      const caret = start + 3;
      replaceTextareaRange(textarea, start, end, wrapped, caret, caret);
      return;
    }
    const selectedText = value.slice(start, end);
    const wrapped = `<b>${selectedText}</b>`;
    replaceTextareaRange(textarea, start, end, wrapped, start + 3, start + 3 + selectedText.length);
  }

  function applyListShortcutToTextarea(textarea) {
    if (!(textarea instanceof HTMLTextAreaElement)) return;
    const value = String(textarea.value || '');
    const rawStart = Math.max(0, textarea.selectionStart ?? 0);
    const rawEnd = Math.max(rawStart, textarea.selectionEnd ?? rawStart);

    const lineStart = value.lastIndexOf('\n', Math.max(0, rawStart - 1)) + 1;
    const lineEndIndex = value.indexOf('\n', rawEnd);
    const lineEnd = lineEndIndex >= 0 ? lineEndIndex : value.length;

    const selectedBlock = value.slice(lineStart, lineEnd);
    const lines = selectedBlock
      .split('\n')
      .map((line) => String(line || '').trim())
      .filter((line) => line !== '');

    if (!lines.length) return;

    const listBody = lines.map((line) => `  <li>${line}</li>`).join('\n');
    const listMarkup = `<ul>\n${listBody}\n</ul>`;
    replaceTextareaRange(textarea, lineStart, lineEnd, listMarkup);
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
          scoreSubmitted: Boolean(row.scoreSubmitted ?? row.score_submitted ?? false),
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
      const haystack = `${row.name} ${row.leaderName} ${row.leaderWorkId} ${row.status} ${row.joinType} ${row.scoreSubmitted ? 'submitted score' : ''}`.toLowerCase();
      return haystack.includes(query);
    });
    if (!visibleRows.length) {
      controls.body.innerHTML = `<tr><td colspan="${colspan}" class="muted">No team found.</td></tr>`;
      return;
    }

    const submittedRows = visibleRows.filter((team) => Boolean(team.scoreSubmitted));
    const startedRows = visibleRows.filter((team) => !Boolean(team.scoreSubmitted) && team.status === 'started');
    const otherRows = visibleRows.filter((team) => !Boolean(team.scoreSubmitted) && team.status !== 'started');

    const renderTeamRow = (team, sectionToken = 'other') => {
      const isSubmitted = sectionToken === 'submitted' || Boolean(team.scoreSubmitted);
      const isStarted = !isSubmitted && sectionToken === 'started';
      const statusMarkup = isSubmitted
        ? '<span class="egm-team-submitted-flag"><i class="ri-checkbox-circle-line" aria-hidden="true"></i><span>Submitted Score</span></span>'
        : (isStarted
          ? '<span class="egm-team-started-flag"><i class="ri-flag-2-line" aria-hidden="true"></i><span>Started</span></span>'
          : '<span class="egm-team-status-muted">Draft</span>');
      const minMembers = Math.max(1, normalizeScoreValue(team.minMembers || 1));
      const maxMembers = Math.max(minMembers, normalizeScoreValue(team.maxMembers || minMembers));
      const memberCount = normalizeScoreValue(team.memberCount || 0);
      const statusTag = isSubmitted
        ? '<span class="egm-team-submitted-chip">Scored</span>'
        : (isStarted ? '<span class="egm-team-started-chip">Live</span>' : '');
      return `
        <tr class="egm-team-rate-row ${isStarted ? 'egm-team-rate-row--started' : ''} ${isSubmitted ? 'egm-team-rate-row--submitted' : ''}" data-team-id="${escapeHtml(team.id)}">
          <td>
            <div class="egm-team-name-cell">
              <span>${escapeHtml(team.name || 'Team')}</span>
              ${statusTag}
            </div>
          </td>
          <td>${statusMarkup}</td>
          <td>${escapeHtml(formatTeamJoinTypeLabel(team.joinType))}</td>
          <td>${escapeHtml(team.leaderName || team.leaderWorkId || '-')}</td>
          <td>${escapeHtml(String(memberCount))} / ${escapeHtml(String(maxMembers))} <small class="muted">(min ${escapeHtml(String(minMembers))})</small></td>
          <td>${escapeHtml(String(normalizeScoreValue(team.assignedScore || 0)))}</td>
          <td>
            <label class="egm-team-accepted-check-wrap">
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
        <tr class="egm-team-rate-section-row egm-team-rate-section-row--${escapeHtml(sectionToken)}">
          <td colspan="${colspan}">
            <div class="egm-team-rate-section-head">
              <span class="egm-team-rate-section-title">${escapeHtml(title)}</span>
              <span class="egm-team-rate-section-count">${escapeHtml(String(rows.length))}</span>
            </div>
          </td>
        </tr>
        ${rows.map((team) => renderTeamRow(team, sectionToken)).join('')}
      `;
    };

    controls.body.innerHTML = [
      renderSection('Started Teams', startedRows, 'started'),
      renderSection('Other Teams', otherRows, 'other'),
      renderSection('Submitted Score', submittedRows, 'submitted')
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
            <div class="egm-info-rate-row-actions">
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
    wrapper.className = 'egm-describe-results-modal';
    wrapper.hidden = true;
    wrapper.innerHTML = `
      <div class="egm-describe-results-dialog" role="dialog" aria-modal="true" aria-label="Describe Photo Results">
        <div class="egm-describe-results-head">
          <h3 data-describe-results-title>Results</h3>
          <button type="button" class="btn ghost" data-action="close-describe-results-modal">Close</button>
        </div>
        <p class="muted small" data-describe-results-hint></p>
        <div class="egm-describe-results-grid" data-describe-results-grid></div>
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
    const modalHost = document.querySelector('.egm-shell');
    (modalHost instanceof HTMLElement ? modalHost : document.body).appendChild(wrapper);
    describeResultsModalEl = wrapper;
    return wrapper;
  }

  function ensureDescribeArticleModal() {
    if (describeArticleModalEl instanceof HTMLElement) {
      return describeArticleModalEl;
    }
    const wrapper = document.createElement('div');
    wrapper.className = 'egm-describe-article-modal';
    wrapper.hidden = true;
    wrapper.innerHTML = `
      <div class="egm-describe-article-dialog" role="dialog" aria-modal="true" aria-label="Describe Photo Text Result">
        <div class="egm-describe-article-head">
          <h3 data-describe-article-title>Result Text</h3>
          <button type="button" class="btn ghost" data-action="close-describe-article-modal">Close</button>
        </div>
        <p class="muted small" data-describe-article-meta></p>
        <div class="egm-describe-article-content" data-describe-article-text></div>
        <div class="egm-describe-article-score-box">
          <label class="field standard-width">
            <span>Custom Score</span>
            <input type="number" min="0" step="1" data-describe-article-score />
          </label>
          <div class="egm-describe-article-score-actions">
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
    const modalHost = document.querySelector('.egm-shell');
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
          <button type="button" class="egm-describe-result-photo-btn" data-action="open-describe-result-photo" data-photo-id="${photoId}">
            ${photoUrl ? `<img src="${escapeHtml(photoUrl)}" alt="${photoName}" loading="lazy" />` : '<div class="egm-describe-result-photo-fallback">No Preview</div>'}
            <div class="egm-describe-result-photo-name">${photoName}</div>
            <div class="egm-describe-result-photo-words">Words: ${escapeHtml(String(wordCount))}</div>
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
    wrapper.className = 'egm-team-preview-modal';
    wrapper.hidden = true;
    wrapper.innerHTML = `
      <div class="egm-team-preview-dialog" role="dialog" aria-modal="true" aria-label="Team Preview">
        <div class="egm-team-preview-head">
          <h3 data-team-preview-title>Team Preview</h3>
          <button type="button" class="btn ghost" data-action="close-team-preview-modal">Close</button>
        </div>
        <p class="muted small" data-team-preview-hint></p>
        <div class="egm-team-preview-grid">
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
        <div class="egm-team-preview-score-actions">
          <label class="field standard-width">
            <span>Custom Score (All Team Members)</span>
            <input type="number" min="0" step="1" data-team-preview-score />
          </label>
          <button type="button" class="btn secondary" data-action="team-preview-apply-score">Apply Custom Score</button>
          <button type="button" class="btn primary standard-primary-button" data-action="team-preview-max-score">Max Score</button>
        </div>
        <div class="table-wrapper egm-team-preview-members-wrap">
          <table class="tct-list-table egm-team-preview-members-table">
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
    const modalHost = document.querySelector('.egm-shell');
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
      'egm-status--active',
      'egm-status--ended',
      'egm-status--upcoming',
      'egm-status--inactive',
      'egm-status--immune',
      'egm-status--quit'
    );
    if (tone) {
      controls.statusEl.classList.add(`egm-status--${tone}`);
    }
  }

  function setDurationFieldsEnabled(pane, enabled) {
    const controls = getTaskPaneControls(pane);
    if (!controls) return;
    controls.startDate.disabled = !enabled;
    controls.startTime.disabled = !enabled;
    controls.endDate.disabled = !enabled;
    controls.endTime.disabled = !enabled;
    const quitRequiredEnabled = enabled && controls.quitRequiredToggle.checked;
    controls.quitTimelineRequiredToggle.disabled = !quitRequiredEnabled;
    const quitTimelineEnabled = quitRequiredEnabled && controls.quitTimelineRequiredToggle.checked;
    controls.enterDeadlineDate.disabled = !quitTimelineEnabled;
    controls.enterDeadlineTime.disabled = !quitTimelineEnabled;
    controls.quitOpeningDate.disabled = !quitTimelineEnabled;
    controls.quitOpeningTime.disabled = !quitTimelineEnabled;
    if (controls.quitTimeline) controls.quitTimeline.hidden = !quitTimelineEnabled;
    controls.minimumStayMinutes.disabled = !quitRequiredEnabled || controls.quitTimelineRequiredToggle.checked;
    if (controls.minimumStayContainer) controls.minimumStayContainer.hidden = !quitRequiredEnabled || controls.quitTimelineRequiredToggle.checked;
  }

  function collectTaskSettingsFromPane(pane) {
    const controls = getTaskPaneControls(pane);
    if (!controls) return null;
    const quitRequired = controls.quitRequiredToggle.checked;
    const quitTimelineRequired = quitRequired && controls.quitTimelineRequiredToggle.checked;
    return {
      title: String(controls.titleInput.value || '').trim(),
      active: controls.activeToggle.checked ? '1' : '0',
      duration: controls.durationToggle.checked ? '1' : '0',
      quit_required: quitRequired ? '1' : '0',
      quit_timeline_required: quitTimelineRequired ? '1' : '0',
      minimum_stay_minutes: String(normalizeMinimumStayMinutes(controls.minimumStayMinutes.value)),
      dev_phase: controls.devPhaseToggle.checked ? '1' : '0',
      start_date: normalizeDate(controls.startDate.value),
      start_time: normalizeTime(controls.startTime.value),
      end_date: normalizeDate(controls.endDate.value),
      end_time: normalizeTime(controls.endTime.value),
      enter_deadline_date: quitTimelineRequired ? normalizeDate(controls.enterDeadlineDate.value) : '',
      enter_deadline_time: quitTimelineRequired ? normalizeTime(controls.enterDeadlineTime.value) : '',
      quit_opening_date: quitTimelineRequired ? normalizeDate(controls.quitOpeningDate.value) : '',
      quit_opening_time: quitTimelineRequired ? normalizeTime(controls.quitOpeningTime.value) : ''
    };
  }

  function collectTaskScoreFromPane(pane) {
    const controls = getTaskScoreControls(pane);
    if (!controls) return null;
    const isInfoTask = isInfoLikeTaskType(controls.taskType);
    const hasGoldenTime = controls.taskType === 'conditional_quiz'
      ? Boolean(controls.hasGoldenTimeToggle?.checked)
      : true;
    return {
      score: String(normalizeScoreValue(controls.scoreInput.value)),
      has_golden_time: hasGoldenTime ? '1' : '0',
      after_endtime_score: isInfoTask || (controls.taskType === 'conditional_quiz' && !hasGoldenTime)
        ? '0'
        : String(normalizeScoreValue(controls.afterEndtimeScoreInput?.value))
    };
  }

  function collectTaskCrisisControlFromPane(pane) {
    const controls = getTaskCrisisControls(pane);
    if (!controls) return null;
    return {
      another_chance_if_zero: controls.anotherChanceIfZeroToggle.checked ? '1' : '0'
    };
  }

  function updateTaskPaneStatus(pane) {
    const settings = collectTaskSettingsFromPane(pane);
    if (!settings) return;
    const derived = deriveStatusFromSettings({
      active: settings.active,
      duration: settings.duration,
      quitRequired: settings.quit_required,
      quitTimelineRequired: settings.quit_timeline_required,
      startDate: settings.start_date,
      startTime: settings.start_time,
      endDate: settings.end_date,
      endTime: settings.end_time,
      enterDeadlineDate: settings.enter_deadline_date,
      enterDeadlineTime: settings.enter_deadline_time,
      quitOpeningDate: settings.quit_opening_date,
      quitOpeningTime: settings.quit_opening_time
    });
    setTaskPaneStatus(pane, derived.label, derived.tone);
  }

  function syncTaskPaneToggleState(pane) {
    const controls = getTaskPaneControls(pane);
    if (!controls) return;
    if (controls.quitRequiredToggle.checked) {
      controls.durationToggle.checked = true;
      controls.activeToggle.checked = false;
    } else if (controls.activeToggle.checked) {
      controls.durationToggle.checked = false;
      controls.quitRequiredToggle.checked = false;
    } else if (controls.durationToggle.checked) {
      controls.activeToggle.checked = false;
    }
    if (!controls.durationToggle.checked) controls.quitRequiredToggle.checked = false;
    setDurationFieldsEnabled(pane, controls.durationToggle.checked);
    updateTaskPaneStatus(pane);
  }

  function applyTaskSettingsToPane(pane, task) {
    const controls = getTaskPaneControls(pane);
    if (!controls) return;
    controls.titleInput.value = String(task?.title || task?.tagCode || '');
    controls.activeToggle.checked = normalizeBool(task?.active);
    controls.durationToggle.checked = normalizeBool(task?.duration);
    controls.quitRequiredToggle.checked = normalizeBool(task?.quitRequired);
    controls.quitTimelineRequiredToggle.checked = normalizeBool(task?.quitTimelineRequired ?? true);
    controls.minimumStayMinutes.innerHTML = buildMinimumStayOptions(task?.minimumStayMinutes);
    controls.minimumStayMinutes.value = String(normalizeMinimumStayMinutes(task?.minimumStayMinutes));
    controls.devPhaseToggle.checked = normalizeBool(task?.devPhase);
    controls.startDate.value = normalizeDate(task?.startDate);
    controls.startTime.value = normalizeTime(task?.startTime);
    controls.endDate.value = normalizeDate(task?.endDate);
    controls.endTime.value = normalizeTime(task?.endTime);
    controls.enterDeadlineDate.value = normalizeDate(task?.enterDeadlineDate);
    controls.enterDeadlineTime.value = normalizeTime(task?.enterDeadlineTime);
    controls.quitOpeningDate.value = normalizeDate(task?.quitOpeningDate);
    controls.quitOpeningTime.value = normalizeTime(task?.quitOpeningTime);
    const scoreControls = getTaskScoreControls(pane);
    if (scoreControls) {
      scoreControls.scoreInput.value = String(normalizeScoreValue(task?.score));
      if (scoreControls.hasGoldenTimeToggle instanceof HTMLInputElement) {
        scoreControls.hasGoldenTimeToggle.checked = normalizeBool(task?.hasGoldenTime ?? true);
      }
      if (scoreControls.afterEndtimeScoreInput instanceof HTMLInputElement) {
        scoreControls.afterEndtimeScoreInput.value = String(normalizeScoreValue(task?.afterEndtimeScore));
      }
      syncTaskScoreGoldenTimeState(pane);
    }
    const crisisControls = getTaskCrisisControls(pane);
    if (crisisControls) {
      crisisControls.anotherChanceIfZeroToggle.checked = normalizeBool(task?.anotherChanceIfZero);
      setTaskCrisisSaveStatus(pane, '');
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
    setTaskCrisisSaveStatus(pane, '');
  }

  function buildTaskControlCardMarkup(task) {
    const titleText = task.title || task.tagCode;
    const isInfoTask = isInfoLikeTaskType(task.taskType);
    const hasInformationPane = hasInformationPaneTaskType(task.taskType);
    const taskTypeToken = normalizeTaskType(task.taskType);
    const isPeriod = taskTypeToken === 'period';
    const isDescribePhotoTask = taskTypeToken === 'describe_photo';
    const isTeamTask = taskTypeToken === 'team_task';
    const isConditionalQuizTask = taskTypeToken === 'conditional_quiz';
    const quizSrc = `mini%20apps/EGMs/EGM/EGMQ.php?task_id=${encodeURIComponent(task.id)}`;
    const guestControlSrc = GUEST_CONTROL_ENDPOINT;
    const infoTitle = task.infoTitle || '';
    const infoText = task.infoText || '';
    const guidePrefix = String(task.guidePrefix || '');
    const guideSuffix = String(task.guideSuffix || '');
    const teamMin = Math.max(1, normalizeScoreValue(task.teamMin ?? 1));
    const teamMaxRaw = Math.max(1, normalizeScoreValue(task.teamMax ?? teamMin));
    const teamMax = Math.max(teamMin, teamMaxRaw);
    const teamAdditionalNote = String(task.teamAdditionalNote || '');
    const hasGoldenTime = normalizeBool(task.hasGoldenTime ?? true);
    const anotherChanceIfZero = normalizeBool(task.anotherChanceIfZero);
    const taskPhotos = normalizeDescribePhotoList(task?.taskPhotos);
    const taskChallenges = normalizeTeamChallengeList(task?.taskChallenges);
    const topTabsMarkup = isPeriod
      ? '<button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="information">اطلاعات</button><button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="invite">دعوت</button><button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="invitees">دعوت‌شدگان</button><button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="invite-card">کارت دعوت</button><button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="export">خروجی</button>'
      : isDescribePhotoTask
      ? '<button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="information">اطلاعات</button><button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="photo">عکس‌ها</button><button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="invitees-rate">امتیازدهی دعوت‌شدگان</button>'
      : (isTeamTask
        ? '<button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="information">اطلاعات</button><button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="challenge-storage">انبار چالش‌ها</button><button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="team">تیم</button><button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="invitees-rate">امتیازدهی تیم‌ها</button>'
        : (isInfoTask
          ? '<button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="information">اطلاعات</button><button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="invitees-rate">امتیازدهی دعوت‌شدگان</button>'
          : '<button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="information">اطلاعات</button><button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="quiz">کوئیز</button>'));
    const informationSection = hasInformationPane
      ? `
          <div class="egm-task-top-section" data-task-top-section="information" hidden>
            <div class="card">
              <div class="section-header"><h3>اطلاعات بازه</h3></div>
              <div class="form" style="gap:12px;">
                <label class="field standard-width">
                  <span>عنوان</span>
                  <input type="text" data-task-field="infoTitle" value="${escapeHtml(infoTitle)}" />
                </label>
                <label class="field full">
                  <span>متن</span>
                  <textarea data-task-field="infoText" rows="8">${escapeHtml(infoText)}</textarea>
                </label>
                <div class="field full">
                  <button type="button" class="btn primary standard-primary-button" data-action="save-task-information">ذخیره</button>
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
        `
      : '';
    const periodInvitationSections = isPeriod ? `
      <div class="egm-task-top-section" data-task-top-section="invite" hidden>
        <div class="card egm-period-source-card">
          <div class="section-header"><h3>دعوت کاربران به بازه</h3></div>
          <p class="muted">منبع فعلی: <strong data-period-invite-source>—</strong></p>
          <p class="hint">منبع از تب «دعوت‌شدگان» EGM انتخاب می‌شود.</p>
        </div>
        <div class="card egm-period-filter-card">
          <div class="section-header"><h3>جستجو و فیلتر کاربران</h3></div>
          <form class="form" data-period-invite-filter-form>
            <label class="field full"><span>جستجوی نام، کد ملی یا کد پرسنلی</span><input type="search" name="q" autocomplete="off" /></label>
            <div class="egm-period-filter-grid">
              <label class="field"><span>معاونت</span><select name="deputy"><option value="">همه معاونت‌ها</option></select></label>
              <label class="field"><span>اداره کل</span><select name="general_department"><option value="">همه اداره‌های کل</option></select></label>
              <label class="field"><span>اداره</span><select name="department"><option value="">همه اداره‌ها</option></select></label>
              <label class="field"><span>جنسیت</span><select name="gender"><option value="">همه</option></select></label>
              <label class="field"><span>سطح پستی</span><select name="postal_level"><option value="">همه سطوح</option></select></label>
            </div>
            <div class="egm-period-actions"><button type="submit" class="btn primary">اعمال فیلترها</button><button type="button" class="btn ghost" data-period-invite-clear>پاک کردن</button></div>
          </form>
        </div>
        <div class="card egm-period-excel-card">
          <div class="section-header"><h3>انتخاب کاربران با فایل Excel</h3></div>
          <p class="muted">حداقل یکی از ستون‌های کد ملی یا کد پرسنلی را انتخاب کنید. انتخاب هر دو اختیاری است.</p>
          <div class="form">
            <input type="file" accept=".csv,.xls,.xlsx" data-period-excel-file hidden />
            <div class="egm-period-actions"><button type="button" class="btn" data-period-excel-pick>انتخاب فایل</button><span class="muted" data-period-excel-name>فایلی انتخاب نشده است.</span></div>
            <div class="egm-period-filter-grid" data-period-excel-sheet-row hidden>
              <label class="field full"><span>شیت Excel</span><select data-period-excel-sheet disabled><option value="">ابتدا شیت را انتخاب کنید</option></select></label>
            </div>
            <div class="egm-period-filter-grid" data-period-excel-mapping hidden>
              <label class="field"><span>ستون کد ملی</span><select data-period-excel-national><option value="">انتخاب نشده</option></select></label>
              <label class="field"><span>ستون کد پرسنلی</span><select data-period-excel-work><option value="">انتخاب نشده</option></select></label>
              <label class="field"><span>ستون نام</span><select data-period-excel-first><option value="">انتخاب نشده</option></select></label>
              <label class="field"><span>ستون نام خانوادگی</span><select data-period-excel-last><option value="">انتخاب نشده</option></select></label>
              <label class="field"><span>ستون شماره همراه</span><select data-period-excel-phone><option value="">انتخاب نشده</option></select></label>
              <label class="field"><span>ستون معاونت</span><select data-period-excel-deputy><option value="">انتخاب نشده</option></select></label>
              <label class="field"><span>ستون اداره کل</span><select data-period-excel-general-department><option value="">انتخاب نشده</option></select></label>
              <label class="field"><span>ستون اداره</span><select data-period-excel-department><option value="">انتخاب نشده</option></select></label>
              <label class="field"><span>ستون جنسیت</span><select data-period-excel-gender><option value="">انتخاب نشده</option></select></label>
              <label class="field"><span>ستون سطح پستی</span><select data-period-excel-postal-level><option value="">انتخاب نشده</option></select></label>
            </div>
            <button type="button" class="btn primary" data-period-excel-match disabled>تطبیق و انتخاب کاربران</button>
            <p class="hint" data-period-excel-status aria-live="polite"></p>
          </div>
        </div>
        <div class="card egm-period-unmatched-card" data-period-unmatched-card hidden>
          <div class="section-header"><h3>کاربران بدون تطبیق فایل</h3><div class="egm-period-actions"><button type="button" class="btn ghost" data-period-export-uninviteable disabled>Export Uniniviteable</button><strong><span data-period-unmatched-total>0</span> ردیف</strong></div></div>
          <p class="muted">هر کاربری را که تأیید کنید فقط به کاربران همین EGM افزوده و به این بازه دعوت می‌شود؛ این کاربران وارد OEU نمی‌شوند.</p>
          <div class="table-wrapper egm-period-table-wrap"><table class="tct-list-table egm-period-table"><thead><tr>
            <th><input type="checkbox" data-period-unmatched-select-all aria-label="انتخاب همه کاربران بدون تطبیق" /></th><th>ردیف Excel</th><th>نام</th><th>نام خانوادگی</th><th>کد ملی</th><th>کد پرسنلی</th><th>شماره همراه</th><th>معاونت</th><th>اداره کل</th><th>اداره</th><th>جنسیت</th><th>سطح پستی</th><th>جزئیات فایل</th><th>عملیات</th>
          </tr></thead><tbody data-period-unmatched-body></tbody></table></div>
          <div class="egm-period-list-footer"><span class="muted"><span data-period-unmatched-selected-count>0</span> ردیف انتخاب شده</span><button type="button" class="btn primary" data-period-invite-unmatched-selected disabled>افزودن و دعوت انتخاب‌شده‌ها</button></div>
          <p class="hint" data-period-unmatched-status aria-live="polite"></p>
        </div>
        <div class="card egm-period-candidates-card">
          <div class="section-header"><h3>فهرست کاربران قابل دعوت</h3><strong><span data-period-candidate-total>0</span> نفر</strong></div>
          <div class="table-wrapper egm-period-table-wrap"><table class="tct-list-table egm-period-table"><thead><tr>
            <th><input type="checkbox" data-period-candidate-select-all aria-label="انتخاب همه کاربران نمایان" /></th><th>شماره مهمان</th><th>نام</th><th>نام خانوادگی</th><th>کد ملی</th><th>کد پرسنلی</th><th>معاونت</th><th>اداره کل</th><th>اداره</th><th>جنسیت</th><th>سطح پستی</th><th>وضعیت</th>
          </tr></thead><tbody data-period-candidate-body><tr><td colspan="12" class="muted">در حال بارگذاری...</td></tr></tbody></table></div>
          <div class="egm-period-list-footer"><div class="egm-period-actions"><button type="button" class="btn ghost" data-period-candidate-prev>قبلی</button><span data-period-candidate-page>صفحه ۱ از ۱</span><button type="button" class="btn ghost" data-period-candidate-next>بعدی</button></div><button type="button" class="btn primary" data-period-invite-selected disabled>دعوت کاربران انتخاب‌شده</button></div>
          <p class="hint" data-period-candidate-status aria-live="polite"></p>
        </div>
      </div>
      <div class="egm-task-top-section" data-task-top-section="invitees" hidden>
        <div class="card egm-period-invitees-card">
          <div class="section-header"><h3>دعوت‌شدگان این بازه</h3><strong><span data-period-invitee-total>0</span> نفر</strong></div>
          <div class="form"><label class="field full"><span>جستجو</span><input type="search" data-period-invitee-search placeholder="نام، کد ملی یا کد پرسنلی" autocomplete="off" /></label></div>
          <div class="table-wrapper egm-period-table-wrap"><table class="tct-list-table egm-period-table"><thead><tr>
            <th>شماره مهمان</th><th>نام</th><th>نام خانوادگی</th><th>کد ملی</th><th>کد پرسنلی</th><th>معاونت</th><th>اداره کل</th><th>اداره</th><th>جنسیت</th><th>سطح پستی</th><th>Correct Presence</th><th>Fake Presence</th><th>منبع</th><th>عملیات</th>
          </tr></thead><tbody data-period-invitee-body><tr><td colspan="14" class="muted">در حال بارگذاری...</td></tr></tbody></table></div>
          <div class="egm-period-list-footer"><div class="egm-period-actions"><button type="button" class="btn ghost" data-period-invitee-prev>قبلی</button><span data-period-invitee-page>صفحه ۱ از ۱</span><button type="button" class="btn ghost" data-period-invitee-next>بعدی</button></div><button type="button" class="btn ghost" data-period-invitee-refresh>بازخوانی</button></div>
          <p class="hint" data-period-invitee-status aria-live="polite"></p>
        </div>
      </div>
      <div class="egm-task-top-section" data-task-top-section="invite-card" hidden>
        <div class="card egm-period-invite-card-background-card">
          <div class="section-header"><div><h3>تصویر کارت این بازه</h3><p class="muted small">فقط تصویر پس‌زمینه مخصوص این بازه است؛ محل QR، کادر متن، فونت، متغیرها و شرط‌ها از تنظیمات مشترک کارت دعوت رویداد خوانده می‌شوند.</p></div></div>
          <div class="egm-period-invite-card-background-editor">
            <div class="egm-period-invite-card-background-preview">
              <img data-period-invite-card-background-preview alt="پیش‌نمایش تصویر کارت دعوت این بازه" hidden />
              <div data-period-invite-card-background-placeholder>در حال دریافت تصویر...</div>
            </div>
            <div class="egm-period-invite-card-background-controls">
              <p class="muted" data-period-invite-card-background-details></p>
              <input type="file" accept="image/png,image/jpeg,image/webp" data-period-invite-card-background-file hidden />
              <div class="egm-period-actions">
                <button type="button" class="btn" data-period-invite-card-background-pick>انتخاب تصویر این بازه</button>
                <button type="button" class="btn primary standard-primary-button" data-period-invite-card-background-save disabled>ذخیره تصویر</button>
                <button type="button" class="btn ghost" data-period-invite-card-background-remove disabled>حذف تصویر اختصاصی</button>
              </div>
              <p class="hint" data-period-invite-card-background-status aria-live="polite"></p>
            </div>
          </div>
        </div>
        <div class="card egm-period-invite-card-generator">
          <div class="section-header"><div><h3>کارت دعوت بازه</h3><p class="muted small">برای تمام دعوت‌شدگان این بازه، کارت JPG اختصاصی و لینک امن ساخته می‌شود.</p></div></div>
          <p class="hint">طرح، متن و جای QR از تنظیمات «کارت دعوت» همین EGM خوانده می‌شود. هر کد یکتا به کد EGM و کد بازه ختم می‌شود.</p>
          <div class="egm-period-actions"><button type="button" class="btn primary standard-primary-button" data-period-invite-card-generate>Generate Invite Cards</button><button type="button" class="btn" data-period-invite-card-export disabled>خروجی Excel لینک کارت‌ها</button><button type="button" class="btn ghost" data-period-invite-card-refresh>بازخوانی وضعیت</button></div>
          <div class="egm-period-invite-card-progress" data-period-invite-card-progress-wrap>
            <progress max="100" value="0" data-period-invite-card-progress></progress>
            <div class="egm-period-list-footer"><strong><span data-period-invite-card-generated>0</span> از <span data-period-invite-card-total>0</span> کارت</strong><span class="muted"><span data-period-invite-card-percent>0</span>٪</span></div>
          </div>
          <p class="hint" data-period-invite-card-status aria-live="polite">برای دریافت وضعیت روی این تب بمانید.</p>
        </div>
      </div>
      <div class="egm-task-top-section" data-task-top-section="export" hidden>
        <div class="card egm-period-export-card">
          <div class="section-header"><div><h3>خروجی اکسل بازه</h3><p class="muted small">تمام فایل‌ها مستقیماً از اطلاعات ذخیره‌شده همین بازه در پایگاه داده ساخته می‌شوند.</p></div></div>
          <div class="egm-period-export-grid">
            <article class="egm-period-export-option"><div><h4>همه مهمانان</h4><p>فهرست کامل دعوت‌شدگان همراه وضعیت دقیق، ورود و خروج.</p></div><a class="btn primary standard-primary-button" href="${PERIOD_EXPORTS_ENDPOINT}?type=all_guests&amp;period_code=${encodeURIComponent(task.tagCode)}">دریافت فایل Excel</a></article>
            <article class="egm-period-export-option"><div><h4>مهمانان ناخوانده</h4><p>فقط مهمانانی که هنگام مراجعه به‌عنوان مهمان ناخوانده ثبت شده‌اند.</p></div><a class="btn primary standard-primary-button" href="${PERIOD_EXPORTS_ENDPOINT}?type=uninvited_guests&amp;period_code=${encodeURIComponent(task.tagCode)}">دریافت فایل Excel</a></article>
            <article class="egm-period-export-option"><div><h4>گزارش کامل</h4><p>تمام تلاش‌های ورود، خروج، تکرار، رد شدن و دیگر رویدادهای کنترل مهمان.</p></div><a class="btn primary standard-primary-button" href="${PERIOD_EXPORTS_ENDPOINT}?type=full_log&amp;period_code=${encodeURIComponent(task.tagCode)}">دریافت فایل Excel</a></article>
            <article class="egm-period-export-option"><div><h4>وضعیت همه کاربران</h4><p>یک ردیف برای هر کاربر با آخرین وضعیت دقیق ثبت‌شده در این بازه.</p></div><a class="btn primary standard-primary-button" href="${PERIOD_EXPORTS_ENDPOINT}?type=user_conditions&amp;period_code=${encodeURIComponent(task.tagCode)}">دریافت فایل Excel</a></article>
            <article class="egm-period-export-option"><div><h4>حضور واقعی</h4><p>مهمانانی که ورود و خروج عادی و معتبر برای این بازه دارند.</p></div><a class="btn primary standard-primary-button" href="${PERIOD_EXPORTS_ENDPOINT}?type=correct_presence&amp;period_code=${encodeURIComponent(task.tagCode)}">حضور واقعی</a></article>
            <article class="egm-period-export-option"><div><h4>حضوری نامعقول</h4><p>مهمانانی که ورود یا خروج آنها با عملیات اجباری ثبت شده است.</p></div><a class="btn primary standard-primary-button" href="${PERIOD_EXPORTS_ENDPOINT}?type=fake_presence&amp;period_code=${encodeURIComponent(task.tagCode)}">حضوری نامعقول</a></article>
          </div>
          <p class="hint">کد ملی، کد پرسنلی و شماره همراه به‌صورت متن ذخیره می‌شوند تا صفرهای ابتدای آن‌ها در Excel حذف نشود.</p>
        </div>
      </div>
    ` : '';
    const quizSection = isQuizLikeTaskType(task.taskType)
      ? `
          <div class="egm-task-top-section" data-task-top-section="quiz" hidden>
            <div class="card egm-task-quiz-card">
              <iframe
                class="egm-task-quiz-frame"
                src="${escapeHtml(quizSrc)}"
                loading="lazy"
                referrerpolicy="same-origin"
                title="آزمون بازه"
              ></iframe>
            </div>
          </div>
        `
      : '';
    const crisisControlSection = isConditionalQuizTask
      ? `
          <div class="egm-task-top-section" data-task-top-section="crisis-control" hidden>
            <div class="card">
              <div class="section-header"><h3>Return Chance</h3></div>
              <div class="form" style="gap:12px;">
                <label class="switch egm-switch">
                  <span class="switch-label">Another Chance if 0</span>
                  <span class="switch-toggle">
                    <input type="checkbox" data-task-field="anotherChanceIfZero" aria-label="Another Chance if 0" ${anotherChanceIfZero ? 'checked' : ''} />
                    <span class="switch-track"><span class="switch-thumb"></span></span>
                  </span>
                </label>
                <div class="field full">
                  <button type="button" class="btn primary standard-primary-button" data-action="save-conditional-quiz-crisis-control">Save</button>
                </div>
                <p class="muted small" data-task-crisis-save-status aria-live="polite"></p>
              </div>
            </div>
          </div>
        `
      : '';
    const describePhotoSection = isDescribePhotoTask
      ? `
          <div class="egm-task-top-section" data-task-top-section="photo" hidden>
            <div class="card">
              <div class="section-header"><h3>بارگذاری عکس</h3></div>
              <div class="form" style="gap:12px;">
                <label class="field standard-width">
                  <span>نام</span>
                  <input type="text" data-task-photo-name placeholder="نام عکس" />
                </label>
                <div class="photo-uploader egm-task-photo-uploader">
                  <div class="photo-preview" aria-live="polite">
                    <img data-task-photo-preview-image class="hidden" alt="عکس انتخاب‌شده تسک" />
                    <div data-task-photo-preview-placeholder class="photo-placeholder">عکسی انتخاب نشده است</div>
                  </div>
                  <div class="photo-actions">
                    <button type="button" class="btn" data-action="pick-task-photo">انتخاب عکس</button>
                    <button type="button" class="btn ghost" data-action="clear-task-photo" disabled>حذف انتخاب</button>
                    <button type="button" class="btn primary standard-primary-button" data-action="add-task-photo" disabled>افزودن عکس</button>
                  </div>
                </div>
                <p class="muted small" data-task-photo-upload-status aria-live="polite"></p>
              </div>
            </div>
            <div class="card">
              <div class="section-header"><h3>فهرست عکس‌ها</h3></div>
              <div class="table-wrapper egm-task-photo-table-wrap">
                <table class="tct-list-table egm-task-photo-table">
                  <thead>
                    <tr>
                      <th>عکس</th>
                      <th>نام</th>
                      <th>عملیات</th>
                    </tr>
                  </thead>
                  <tbody data-task-photo-list-body>
                    ${taskPhotos.length ? '' : '<tr><td colspan="3" class="muted">هنوز عکسی اضافه نشده است.</td></tr>'}
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
          <div class="egm-task-top-section" data-task-top-section="challenge-storage" hidden>
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
              <div class="table-wrapper egm-team-challenge-table-wrap">
                <table class="tct-list-table egm-team-challenge-table">
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
          <div class="egm-task-top-section" data-task-top-section="team" hidden>
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
                <div class="egm-info-rate-bulk">
                  <label class="field standard-width">
                    <span>Custom Score (Selected)</span>
                    <input type="number" min="0" step="1" data-task-info-bulk-score />
                  </label>
                  <button type="button" class="btn secondary" data-action="info-bulk-apply">Apply Custom Score</button>
                  <button type="button" class="btn primary standard-primary-button" data-action="info-bulk-max">Max Score</button>
                </div>
              `;
    const inviteesRateTableWrapClass = isTeamTask ? 'egm-team-rate-table-wrap' : 'egm-info-rate-table-wrap';
    const inviteesRateTableClass = isTeamTask ? 'egm-team-rate-table' : 'egm-info-rate-table';
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
      <div class="egm-task-top-shell" data-task-top-shell>
        <div class="egm-task-top-nav" role="tablist" aria-label="بخش‌های بازه">
          <button type="button" class="egm-task-top-item active" aria-selected="true" data-task-top-trigger="control">کنترل</button>
          ${topTabsMarkup}
          ${isConditionalQuizTask ? '<button type="button" class="egm-task-top-item" aria-selected="false" data-task-top-trigger="crisis-control">Crisis Control</button>' : ''}
        </div>

        <div class="egm-task-top-section active" data-task-top-section="control">
          <div class="card">
            <div class="section-header">
              <h3>${escapeHtml(titleText)}</h3>
            </div>
            <p class="muted small">کد یکتا: <code>${escapeHtml(task.tagCode)}</code></p>
            <div class="form" style="gap:12px;">
              <label class="field standard-width">
                <span>نام بازه</span>
                <input type="text" data-task-field="taskTitle" value="${escapeHtml(titleText)}" autocomplete="off" />
              </label>
              <div class="egm-status egm-status--inactive" data-task-status>غیرفعال</div>
              <div class="egm-switch-grid">
                <label class="switch egm-switch">
                  <span class="switch-label">فعال</span>
                  <span class="switch-toggle">
                    <input type="checkbox" data-task-field="active" aria-label="فعال بودن بازه" />
                    <span class="switch-track"><span class="switch-thumb"></span></span>
                  </span>
                </label>
                <label class="switch egm-switch">
                  <span class="switch-label">زمان‌بندی</span>
                  <span class="switch-toggle">
                    <input type="checkbox" data-task-field="duration" aria-label="زمان‌بندی بازه" />
                    <span class="switch-track"><span class="switch-thumb"></span></span>
                  </span>
                </label>
                <label class="switch egm-switch">
                  <span class="switch-label">Quit Required</span>
                  <span class="switch-toggle">
                    <input type="checkbox" data-task-field="quitRequired" aria-label="الزام ثبت خروج" />
                    <span class="switch-track"><span class="switch-thumb"></span></span>
                  </span>
                </label>
                <label class="switch egm-switch">
                  <span class="switch-label">الزام مهلت ورود و آغاز خروج</span>
                  <span class="switch-toggle">
                    <input type="checkbox" data-task-field="quitTimelineRequired" aria-label="الزام مهلت ورود و آغاز خروج" />
                    <span class="switch-track"><span class="switch-thumb"></span></span>
                  </span>
                </label>
                <label class="switch egm-switch">
                  <span class="switch-label">حالت آزمایشی</span>
                  <span class="switch-toggle">
                    <input type="checkbox" data-task-field="devPhase" aria-label="حالت آزمایشی بازه" />
                    <span class="switch-track"><span class="switch-thumb"></span></span>
                  </span>
                </label>
              </div>
              <div class="field standard-width" data-quit-minimum-stay hidden>
                <span>حداقل مدت حضور پیش از خروج</span>
                <select data-task-field="minimumStayMinutes">${buildMinimumStayOptions(task.minimumStayMinutes)}</select>
                <p class="hint">پس از عبور خروج‌های ثبت‌شده از ۲۰٪ دعوت‌شدگان، ورود بسته و این حداقل زمان نادیده گرفته می‌شود.</p>
              </div>
              <div class="form grid two-column-fields egm-datetime-grid">
                <div class="egm-datetime-title egm-datetime-title--start">شروع</div>
                <label class="field standard-width egm-datetime-start">
                  <span>تاریخ</span>
                  <input type="date" data-task-field="startDate" placeholder="YYYY-MM-DD" />
                </label>
                <label class="field standard-width egm-datetime-start-time">
                  <span>ساعت</span>
                  <select data-task-field="startTime">
                    ${buildTimeOptions(task.startTime)}
                  </select>
                </label>
                <div class="egm-quit-timeline" data-quit-timeline hidden>
                  <div class="egm-datetime-title">مهلت ورود (Enter Deadline)</div>
                  <label class="field standard-width">
                    <span>تاریخ</span>
                    <input type="date" data-task-field="enterDeadlineDate" placeholder="YYYY-MM-DD" />
                  </label>
                  <label class="field standard-width">
                    <span>ساعت</span>
                    <select data-task-field="enterDeadlineTime">
                      ${buildTimeOptions(task.enterDeadlineTime)}
                    </select>
                  </label>
                  <div class="egm-datetime-title">آغاز خروج (Quit Opening)</div>
                  <label class="field standard-width">
                    <span>تاریخ</span>
                    <input type="date" data-task-field="quitOpeningDate" placeholder="YYYY-MM-DD" />
                  </label>
                  <label class="field standard-width">
                    <span>ساعت</span>
                    <select data-task-field="quitOpeningTime">
                      ${buildTimeOptions(task.quitOpeningTime)}
                    </select>
                  </label>
                </div>
                <div class="egm-datetime-title egm-datetime-title--end">پایان</div>
                <label class="field standard-width egm-datetime-end">
                  <span>تاریخ</span>
                  <input type="date" data-task-field="endDate" placeholder="YYYY-MM-DD" />
                </label>
                <label class="field standard-width egm-datetime-end-time">
                  <span>ساعت</span>
                  <select data-task-field="endTime">
                    ${buildTimeOptions(task.endTime)}
                  </select>
                </label>
                <div class="egm-datetime-empty" aria-hidden="true"></div>
              </div>
              <div class="field full">
                <button type="button" class="btn primary standard-primary-button" data-action="save-task-settings">ذخیره</button>
                ${isPeriod ? '<button type="button" class="btn ghost egm-btn-danger" data-action="reset-period-attendance">Reset Period Records</button>' : ''}
                <a class="btn ghost" href="${guestControlSrc}" target="_blank" rel="noopener">پنل کنترل مهمان رویداد</a>
              </div>
              <p class="muted small" data-task-save-status aria-live="polite"></p>
            </div>
          </div>
          ${isPeriod ? '' : `<div class="card">
            <div class="section-header">
              <h3>${isConditionalQuizTask ? 'سیستم امتیازدهی بر اساس پاسخ' : 'سیستم امتیازدهی'}</h3>
            </div>
            <div class="form" style="gap:12px;">
              <label class="field standard-width">
                <span>${isConditionalQuizTask ? 'امتیاز پاسخ صحیح' : (isInfoTask ? 'امتیاز کل' : 'مدت زمان طلایی')}</span>
                <input type="number" min="0" step="1" data-task-field="score" />
              </label>
              ${isConditionalQuizTask ? `
                <label class="switch egm-switch">
                  <span class="switch-label">Has Golden Time</span>
                  <span class="switch-toggle">
                    <input type="checkbox" data-task-field="hasGoldenTime" aria-label="Has Golden Time" ${hasGoldenTime ? 'checked' : ''} />
                    <span class="switch-track"><span class="switch-thumb"></span></span>
                  </span>
                </label>
              ` : ''}
              ${isInfoTask ? '' : `
                <label class="field standard-width">
                  <span>${isConditionalQuizTask ? 'امتیاز پاسخ صحیح در زمان طلایی' : 'امتیاز پاسخ پس از پایان زمان طلایی'}</span>
                  <input type="number" min="0" step="1" data-task-field="afterEndtimeScore" ${isConditionalQuizTask && !hasGoldenTime ? 'disabled aria-disabled="true"' : ''} />
                </label>
              `}
              <div class="field full">
                <button type="button" class="btn primary standard-primary-button" data-action="save-task-score-system">ذخیره</button>
              </div>
              <p class="muted small" data-task-score-save-status aria-live="polite"></p>
            </div>
          </div>`}
        </div>
        ${informationSection}
        ${periodInvitationSections}
        ${describePhotoSection}
        ${teamChallengeSection}
        ${teamSettingsSection}
        ${crisisControlSection}
        ${isInfoTask && !isPeriod ? `
          <div class="egm-task-top-section" data-task-top-section="invitees-rate" hidden>
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
        ` : ''}
        ${quizSection}
      </div>
    `;
  }

  function renderTaskSubtabs(layout, tasks, preferredPane = '') {
    if (!(layout instanceof HTMLElement)) return;
    const navHost = layout.querySelector('[data-egm-task-subtab-nav]');
    const paneHost = layout.querySelector('[data-egm-task-subtab-panes]');
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

    const visibleTasks = normalizedTasks.filter((task) => task.taskAccessEnabled !== false);

    if (!visibleTasks.length) {
      if (normalizedTasks.length > 0) {
        paneHost.innerHTML = `
          <div class="card">
            <div class="section-header"><h3>عدم دسترسی</h3></div>
            <p class="muted">شما به هیچ بازه‌ای دسترسی ندارید.</p>
          </div>
        `;
      }
      ensureAnyActivePane(layout, previousActivePane);
      return;
    }

    const taskById = new Map();
    const usedPaneKeys = new Set();
    const navFragment = document.createDocumentFragment();
    const paneFragment = document.createDocumentFragment();

    visibleTasks.forEach((task, index) => {
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
      const task = taskById.get(taskId) || null;
      applyTaskSettingsToPane(pane, task);
      applyTaskTopPaneAccess(pane, task);
      setupPeriodInvitationPane(pane, task);
      const firstTopTrigger = pane.querySelector('[data-task-top-trigger]');
      if (firstTopTrigger instanceof HTMLElement) {
        const firstSection = String(firstTopTrigger.getAttribute('data-task-top-trigger') || '').trim();
        if (firstSection !== '') {
          activateTaskTopPane(pane, firstSection);
          if (firstSection === 'invitees-rate' && isInfoLikeTaskType(task?.taskType || pane.dataset.taskType || 'quiz')) {
            void loadInfoRateDataIntoPane(pane);
          }
        }
      }
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

  async function resetGuestControlRecords(periodCode) {
    const response = await fetch(GUEST_CONTROL_ENDPOINT, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        action: 'reset_period_records',
        period_code: String(periodCode || ''),
        confirmation: 'RESET_PERIOD_ATTENDANCE',
        csrf: TASK_CLUB_CSRF
      })
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data?.status !== 'ok') {
      throw new Error(data?.message || 'بازنشانی سوابق حضور بازه ناموفق بود.');
    }
    return data;
  }

  const periodInviteStates = new WeakMap();

  function getPeriodInviteState(pane) {
    let state = periodInviteStates.get(pane);
    if (!state) {
      state = { source: '', candidates: [], invitees: [], selected: new Set(), page: 1, pages: 1, inviteePage: 1, inviteePages: 1, excelWorkbook: null, excelSheetName: '', excelRows: [], excelHeaders: [], matchedMode: false, unmatchedRows: [], unmatchedSelected: new Set(), periodBackground: null, periodBackgroundDraft: null, periodBackgroundBusy: false };
      periodInviteStates.set(pane, state);
    }
    return state;
  }

  async function readPeriodInviteResponse(response) {
    const responseText = await response.text();
    if (responseText.trim() === '') {
      const status = Number(response.status || 0);
      if (status === 413) throw new Error('حجم درخواست بیش از حد مجاز سرور است. فایل در بخش‌های کوچک‌تر پردازش خواهد شد؛ دوباره تلاش کنید.');
      if (status === 502 || status === 503 || status === 504) throw new Error('سرور هنگام پردازش فایل به محدودیت زمانی رسید. دوباره تلاش کنید.');
      throw new Error(`پاسخ سرور خالی بود (HTTP ${status || 'نامشخص'}).`);
    }
    let data = null;
    try {
      data = JSON.parse(responseText.replace(/^\uFEFF/, ''));
    } catch (error) {
      const firstBrace = responseText.indexOf('{');
      const lastBrace = responseText.lastIndexOf('}');
      if (firstBrace >= 0 && lastBrace > firstBrace) {
        try {
          data = JSON.parse(responseText.slice(firstBrace, lastBrace + 1));
        } catch (embeddedError) {
          data = null;
        }
      }
    }
    if (!data || typeof data !== 'object') {
      const status = Number(response.status || 0);
      if (status === 413) throw new Error('حجم درخواست بیش از حد مجاز سرور است. فایل در بخش‌های کوچک‌تر پردازش خواهد شد؛ دوباره تلاش کنید.');
      if (status === 422) throw new Error('سرور یک خطای اعتبارسنجی برگرداند، اما cPanel متن خطا را تغییر داد. پس از استقرار نسخه جدید دوباره تلاش کنید.');
      if (status === 502 || status === 503 || status === 504) throw new Error('سرور هنگام پردازش فایل به محدودیت زمانی رسید. دوباره تلاش کنید.');
      if (response.redirected && /(?:^|\/)login\.php(?:$|[?#])/i.test(String(response.url || ''))) {
        throw new Error('نشست شما منقضی شده است. دوباره وارد پنل شوید.');
      }
      throw new Error(`پاسخ سرور JSON معتبر نبود (HTTP ${status || 'نامشخص'}).`);
    }
    if (!response.ok || data?.status !== 'ok') {
      throw new Error(data?.message || `عملیات دعوت ناموفق بود (HTTP ${response.status || 'نامشخص'}).`);
    }
    return data;
  }

  async function requestPeriodInvites(action, payload = {}, method = 'GET') {
    let response;
    try {
      if (method === 'GET') {
        const query = new URLSearchParams({ action, ...payload });
        response = await fetch(`${PERIOD_INVITES_ENDPOINT}?${query}`, { credentials: 'same-origin' });
      } else {
        response = await fetch(PERIOD_INVITES_ENDPOINT, {
          method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action, csrf: TASK_CLUB_CSRF, ...payload })
        });
      }
    } catch (error) {
      throw new Error('ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی کرده و دوباره تلاش کنید.');
    }
    try {
      return await readPeriodInviteResponse(response);
    } catch (error) {
      if (error instanceof TypeError) {
        throw new Error('دریافت پاسخ سرور قطع شد. اتصال اینترنت را بررسی کرده و دوباره تلاش کنید.');
      }
      throw error;
    }
  }

  async function matchPeriodExcelRows(periodCode, rows, onProgress = null) {
    const originalsById = new Map(rows.map((row) => [String(row.excel_id || ''), row]));
    const compactRows = rows.map((row) => ({
      excel_id: String(row.excel_id || ''),
      source_row: Number(row.source_row || 0),
      national_id: String(row.national_id || ''),
      work_id: String(row.work_id || '')
    }));
    const batchSize = 400;
    const batches = [];
    for (let index = 0; index < compactRows.length; index += batchSize) {
      batches.push(compactRows.slice(index, index + batchSize));
    }
    if (!batches.length) batches.push([]);

    const matchedByCandidate = new Map();
    const unmatchedByExcelId = new Map();
    let source = '';
    for (let index = 0; index < batches.length; index += 1) {
      if (typeof onProgress === 'function') onProgress(index + 1, batches.length);
      const data = await requestPeriodInvites('match_excel', { period_code: periodCode, rows: batches[index] }, 'POST');
      source = String(data.source || source);
      (Array.isArray(data.rows) ? data.rows : []).forEach((row) => {
        const candidateId = String(row?.candidate_id || '');
        if (candidateId !== '') matchedByCandidate.set(candidateId, row);
      });
      (Array.isArray(data.unmatched_rows) ? data.unmatched_rows : []).forEach((row) => {
        const excelId = String(row?.excel_id || '');
        if (excelId !== '') {
          const original = originalsById.get(excelId);
          const merged = original ? { ...row, ...original } : row;
          if (String(row?.match_error || '').trim() !== '') merged.match_error = String(row.match_error);
          if (typeof row?.can_invite === 'boolean') merged.can_invite = row.can_invite;
          unmatchedByExcelId.set(excelId, merged);
        }
      });
    }
    return {
      status: 'ok',
      source,
      rows: Array.from(matchedByCandidate.values()),
      matched: matchedByCandidate.size,
      unmatched: unmatchedByExcelId.size,
      conflicts: Array.from(unmatchedByExcelId.values()).filter((row) => String(row?.match_error || '').trim() !== '').length,
      unmatched_rows: Array.from(unmatchedByExcelId.values())
    };
  }

  async function requestPeriodInviteCards(action, payload = {}, method = 'GET') {
    if (method === 'GET') {
      const query = new URLSearchParams({ action, ...payload });
      const response = await fetch(`${PERIOD_INVITE_CARDS_ENDPOINT}?${query}`, { credentials: 'same-origin' });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data?.status !== 'ok') throw new Error(data?.message || 'دریافت وضعیت کارت‌های دعوت ناموفق بود.');
      return data;
    }
    const response = await fetch(PERIOD_INVITE_CARDS_ENDPOINT, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, csrf: TASK_CLUB_CSRF, ...payload })
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data?.status !== 'ok') throw new Error(data?.message || 'ساخت کارت‌های دعوت ناموفق بود.');
    return data;
  }

  function renderPeriodInviteCardBackground(pane, background, message = '') {
    const state = getPeriodInviteState(pane);
    const preview = pane.querySelector('[data-period-invite-card-background-preview]');
    const placeholder = pane.querySelector('[data-period-invite-card-background-placeholder]');
    const details = pane.querySelector('[data-period-invite-card-background-details]');
    const status = pane.querySelector('[data-period-invite-card-background-status]');
    const save = pane.querySelector('[data-period-invite-card-background-save]');
    const remove = pane.querySelector('[data-period-invite-card-background-remove]');
    const imageData = String(background?.imageData || '');
    const width = Number(background?.imageWidth || 0);
    const height = Number(background?.imageHeight || 0);
    const isDraft = background?.source === 'draft';
    if (preview instanceof HTMLImageElement) {
      preview.src = imageData;
      preview.hidden = imageData === '';
    }
    if (placeholder instanceof HTMLElement) {
      placeholder.hidden = imageData !== '';
      placeholder.textContent = 'هنوز تصویر اصلی کارت دعوت ذخیره نشده است.';
    }
    if (details) {
      const sourceLabel = isDraft ? 'تصویر انتخاب‌شده و ذخیره‌نشده'
        : background?.has_override ? 'تصویر اختصاصی همین بازه' : 'تصویر مشترک کارت دعوت رویداد';
      details.textContent = `${sourceLabel}${width > 0 && height > 0 ? ` — ${width}×${height} پیکسل` : ''}`;
    }
    if (save instanceof HTMLButtonElement) save.disabled = !state.periodBackgroundDraft || state.periodBackgroundBusy;
    if (remove instanceof HTMLButtonElement) remove.disabled = !state.periodBackground?.has_override || state.periodBackgroundBusy;
    if (status) {
      status.textContent = message || (background?.has_override
        ? 'هنگام ساخت کارت‌ها، تصویر اختصاصی این بازه استفاده می‌شود.'
        : 'برای این بازه تصویر اختصاصی ثبت نشده و تصویر مشترک استفاده می‌شود.');
    }
  }

  function readPeriodInviteCardBackgroundFile(file) {
    return new Promise((resolve, reject) => {
      if (!(file instanceof File) || !['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
        reject(new Error('یک تصویر PNG، JPG یا WebP انتخاب کنید.'));
        return;
      }
      if (file.size > 8 * 1024 * 1024) {
        reject(new Error('حجم تصویر نباید بیشتر از ۸ مگابایت باشد.'));
        return;
      }
      const reader = new FileReader();
      reader.onerror = () => reject(new Error('خواندن تصویر انتخاب‌شده ناموفق بود.'));
      reader.onload = () => {
        const dataUrl = String(reader.result || '');
        const image = new Image();
        image.onerror = () => reject(new Error('فایل انتخاب‌شده تصویر معتبری نیست.'));
        image.onload = () => resolve({ file, imageData: dataUrl, imageName: file.name, imageWidth: image.naturalWidth, imageHeight: image.naturalHeight, source: 'draft' });
        image.src = dataUrl;
      };
      reader.readAsDataURL(file);
    });
  }

  async function loadPeriodInviteCardBackground(pane) {
    const state = getPeriodInviteState(pane);
    try {
      const data = await requestPeriodInviteCards('period_background', { period_code: periodCodeForPane(pane) });
      state.periodBackground = data.background || null;
      state.periodBackgroundDraft = null;
      renderPeriodInviteCardBackground(pane, state.periodBackground);
      return state.periodBackground;
    } catch (error) {
      renderPeriodInviteCardBackground(pane, state.periodBackground || {}, error?.message || 'دریافت تصویر این بازه ناموفق بود.');
      return null;
    }
  }

  async function savePeriodInviteCardBackground(pane) {
    const state = getPeriodInviteState(pane);
    const draft = state.periodBackgroundDraft;
    if (!draft?.file || state.periodBackgroundBusy) return;
    const expectedWidth = Number(state.periodBackground?.imageWidth || 0);
    const expectedHeight = Number(state.periodBackground?.imageHeight || 0);
    if (expectedWidth > 0 && expectedHeight > 0 && (draft.imageWidth !== expectedWidth || draft.imageHeight !== expectedHeight)) {
      renderPeriodInviteCardBackground(pane, draft, `ابعاد تصویر باید دقیقاً ${expectedWidth}×${expectedHeight} پیکسل و برابر تصویر اصلی باشد.`);
      return;
    }
    state.periodBackgroundBusy = true;
    let finalMessage = '';
    renderPeriodInviteCardBackground(pane, draft, 'در حال ذخیره تصویر این بازه در پایگاه داده...');
    try {
      const form = new FormData();
      form.append('action', 'save_period_background');
      form.append('csrf', TASK_CLUB_CSRF);
      form.append('period_code', periodCodeForPane(pane));
      form.append('imageName', draft.imageName || draft.file.name || 'period-background');
      form.append('background', draft.file, draft.file.name || 'period-background');
      const response = await fetch(PERIOD_INVITE_CARDS_ENDPOINT, { method: 'POST', credentials: 'same-origin', body: form });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data?.status !== 'ok') throw new Error(data?.message || 'ذخیره تصویر این بازه ناموفق بود.');
      state.periodBackground = data.background || null;
      state.periodBackgroundDraft = null;
      finalMessage = 'تصویر این بازه ذخیره شد. برای اعمال آن روی فایل‌های قبلی، کارت‌ها را دوباره تولید کنید.';
    } catch (error) {
      finalMessage = error?.message || 'ذخیره تصویر این بازه ناموفق بود.';
    } finally {
      state.periodBackgroundBusy = false;
      renderPeriodInviteCardBackground(pane, state.periodBackgroundDraft || state.periodBackground || {}, finalMessage);
    }
  }

  async function removePeriodInviteCardBackground(pane) {
    const state = getPeriodInviteState(pane);
    if (!state.periodBackground?.has_override || state.periodBackgroundBusy) return;
    if (!window.confirm('تصویر اختصاصی این بازه حذف و تصویر مشترک جایگزین شود؟')) return;
    state.periodBackgroundBusy = true;
    let finalMessage = '';
    try {
      const data = await requestPeriodInviteCards('remove_period_background', { period_code: periodCodeForPane(pane) }, 'POST');
      state.periodBackground = data.background || null;
      state.periodBackgroundDraft = null;
      finalMessage = 'تصویر اختصاصی حذف شد. از این پس تصویر مشترک استفاده می‌شود؛ برای تغییر فایل‌های قبلی کارت‌ها را دوباره تولید کنید.';
    } catch (error) {
      finalMessage = error?.message || 'حذف تصویر اختصاصی ناموفق بود.';
    } finally {
      state.periodBackgroundBusy = false;
      renderPeriodInviteCardBackground(pane, state.periodBackground || {}, finalMessage);
    }
  }

  function renderPeriodInviteCardProgress(pane, data, message = '') {
    const total = Math.max(0, Number(data?.total || 0));
    const generated = Math.max(0, Math.min(total, Number(data?.generated || 0)));
    const percent = total > 0 ? Math.floor(generated * 100 / total) : 0;
    const progress = pane.querySelector('[data-period-invite-card-progress]');
    if (progress instanceof HTMLProgressElement) progress.value = percent;
    const totalEl = pane.querySelector('[data-period-invite-card-total]');
    const generatedEl = pane.querySelector('[data-period-invite-card-generated]');
    const percentEl = pane.querySelector('[data-period-invite-card-percent]');
    if (totalEl) totalEl.textContent = String(total);
    if (generatedEl) generatedEl.textContent = String(generated);
    if (percentEl) percentEl.textContent = String(percent);
    const exportButton = pane.querySelector('[data-period-invite-card-export]');
    if (exportButton instanceof HTMLButtonElement) {
      exportButton.disabled = generated < 1 || Boolean(getPeriodInviteState(pane).inviteCardRunning);
    }
    const status = pane.querySelector('[data-period-invite-card-status]');
    if (status && message) status.textContent = message;
  }

  async function loadPeriodInviteCardStatus(pane) {
    const status = pane.querySelector('[data-period-invite-card-status]');
    try {
      if (status) status.textContent = 'در حال دریافت وضعیت...';
      const data = await requestPeriodInviteCards('status', { period_code: periodCodeForPane(pane) });
      const message = data.total < 1 ? 'هنوز دعوت‌شونده‌ای برای این بازه ثبت نشده است.'
        : data.configuration_ready ? `${data.pending} کارت در انتظار ساخت است.` : 'ابتدا تنظیمات کارت دعوت EGM را ذخیره کنید.';
      renderPeriodInviteCardProgress(pane, data, message);
      return data;
    } catch (error) {
      if (status) status.textContent = error?.message || 'دریافت وضعیت ناموفق بود.';
      return null;
    }
  }

  async function uploadPeriodInviteCard(pane, periodCode, invitee, blob) {
    const form = new FormData();
    form.append('action', 'upload');
    form.append('csrf', TASK_CLUB_CSRF);
    form.append('period_code', periodCode);
    form.append('invite_code', String(invitee.inviteCode || ''));
    form.append('card', blob, `${invitee.inviteCode}.jpg`);
    const response = await fetch(PERIOD_INVITE_CARDS_ENDPOINT, { method: 'POST', credentials: 'same-origin', body: form });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data?.status !== 'ok') throw new Error(data?.message || 'بارگذاری کارت دعوت ناموفق بود.');
    renderPeriodInviteCardProgress(pane, data, `کارت ${data.generated} از ${data.total} ذخیره شد.`);
    return data;
  }

  async function generatePeriodInviteCards(pane) {
    const state = getPeriodInviteState(pane);
    if (state.inviteCardRunning) return;
    const button = pane.querySelector('[data-period-invite-card-generate]');
    const status = pane.querySelector('[data-period-invite-card-status]');
    const periodCode = periodCodeForPane(pane);
    state.inviteCardRunning = true;
    if (button instanceof HTMLButtonElement) button.disabled = true;
    try {
      const current = await requestPeriodInviteCards('status', { period_code: periodCode });
      let regenerate = false;
      if (Number(current.total || 0) > 0 && Number(current.pending || 0) === 0) {
        regenerate = window.confirm('همه کارت‌ها قبلاً ساخته شده‌اند. همه فایل‌ها با همان کدهای یکتا دوباره ساخته شوند؟');
        if (!regenerate) {
          renderPeriodInviteCardProgress(pane, current, 'ساخت مجدد لغو شد؛ کارت‌های فعلی بدون تغییر باقی ماندند.');
          return;
        }
      }
      if (status) status.textContent = 'در حال آماده‌سازی کدهای امن...';
      const prepared = await requestPeriodInviteCards('prepare', { period_code: periodCode, regenerate }, 'POST');
      const config = prepared.configuration;
      renderPeriodInviteCardProgress(pane, prepared, 'ساخت کارت‌ها آغاز شد...');
      if (!window.EGMInviteCardRenderer || typeof window.EGMInviteCardRenderer.render !== 'function') {
        throw new Error('موتور ساخت تصویر کارت دعوت بارگذاری نشده است. صفحه را بازخوانی کنید.');
      }
      while (true) {
        const batch = await requestPeriodInviteCards('next_batch', { period_code: periodCode, limit: '3' });
        const rows = Array.isArray(batch.rows) ? batch.rows : [];
        if (!rows.length) {
          renderPeriodInviteCardProgress(pane, batch, batch.total > 0 ? 'همه کارت‌های دعوت با موفقیت ساخته شدند.' : 'دعوت‌شونده‌ای وجود ندارد.');
          break;
        }
        for (const [batchIndex, invitee] of rows.entries()) {
          if (status) status.textContent = `در حال ساخت کارت ${Number(batch.generated || 0) + batchIndex + 1} از ${batch.total}...`;
          const rendered = await window.EGMInviteCardRenderer.render(config, invitee, String(invitee.nationalId || invitee.workId || ''), {
            qrEndpoint: INVITE_CARD_QR_ENDPOINT, mimeType: 'image/jpeg', quality: 0.92
          });
          await uploadPeriodInviteCard(pane, periodCode, invitee, rendered.blob);
        }
      }
    } catch (error) {
      if (status) status.textContent = `${error?.message || 'ساخت کارت‌ها ناموفق بود.'} با زدن دوباره دکمه، ادامه از کارت‌های باقی‌مانده انجام می‌شود.`;
    } finally {
      state.inviteCardRunning = false;
      if (button instanceof HTMLButtonElement) button.disabled = false;
      const exportButton = pane.querySelector('[data-period-invite-card-export]');
      const generatedCount = Number(pane.querySelector('[data-period-invite-card-generated]')?.textContent || 0);
      if (exportButton instanceof HTMLButtonElement) exportButton.disabled = generatedCount < 1;
    }
  }

  async function exportPeriodInviteCardLinks(pane) {
    const status = pane.querySelector('[data-period-invite-card-status]');
    const button = pane.querySelector('[data-period-invite-card-export]');
    if (button instanceof HTMLButtonElement) button.disabled = true;
    try {
      if (!window.XLSX?.utils || typeof window.XLSX.writeFile !== 'function') {
        throw new Error('موتور ساخت فایل Excel بارگذاری نشده است. صفحه را بازخوانی کنید.');
      }
      if (status) status.textContent = 'در حال ساخت فایل Excel...';
      const data = await requestPeriodInviteCards('export_data', {
        period_code: periodCodeForPane(pane),
        period_date: periodDateForPane(pane)
      });
      const rows = Array.isArray(data.rows) ? data.rows : [];
      if (!rows.length) throw new Error('هنوز هیچ کارت دعوتی برای این بازه ساخته نشده است.');
      const table = [['نام و نام خانوادگی', 'کد ملی', 'کد پرسنلی', 'شماره همراه', 'لینک کارت دعوت']];
      rows.forEach((row) => table.push([
        String(row?.full_name || ''), String(row?.national_id || ''), String(row?.work_id || ''),
        String(row?.phone_number || ''), String(row?.invite_url || '')
      ]));
      const worksheet = window.XLSX.utils.aoa_to_sheet(table);
      worksheet['!cols'] = [{ wch: 28 }, { wch: 16 }, { wch: 16 }, { wch: 16 }, { wch: 64 }];
      worksheet['!autofilter'] = { ref: `A1:E${table.length}` };
      rows.forEach((row, index) => {
        const cell = worksheet[`E${index + 2}`];
        const target = String(row?.invite_url || '');
        if (cell && target) cell.l = { Target: target, Tooltip: 'باز کردن کارت دعوت' };
      });
      const workbook = window.XLSX.utils.book_new();
      window.XLSX.utils.book_append_sheet(workbook, worksheet, 'لینک کارت‌ها');
      const filename = periodExportDatedFilename('لینک کارت‌های دعوت', pane, data.period_date);
      window.XLSX.writeFile(workbook, filename, { bookType: 'xlsx', compression: true });
      if (status) status.textContent = 'فایل واقعی Excel (.xlsx) لینک کارت‌ها دانلود شد.';
    } catch (error) {
      if (status) status.textContent = error?.message || 'ساخت فایل Excel ناموفق بود.';
    } finally {
      if (button instanceof HTMLButtonElement) button.disabled = false;
    }
  }

  function periodSourceLabel(source) {
    if (source === 'period_excel') return 'Excel (کاربر اختصاصی EGM)';
    return source === 'oeu' ? 'OEU (کاربران سازمانی)' : 'Custom (فایل اختصاصی EGM)';
  }

  function periodCandidateRowMarkup(row, state) {
    const id = String(row?.candidate_id || '');
    const disabled = row?.invited === true;
    const checked = !disabled && state.selected.has(id);
    return `<tr data-period-candidate-id="${escapeHtml(id)}">
      <td><input type="checkbox" data-period-candidate-check value="${escapeHtml(id)}" ${checked ? 'checked' : ''} ${disabled ? 'disabled' : ''} /></td>
      <td><code>${escapeHtml(row?.guest_number || '—')}</code></td>
      <td>${escapeHtml(row?.first_name || '—')}</td><td>${escapeHtml(row?.last_name || '—')}</td>
      <td><span dir="ltr">${escapeHtml(row?.national_id || '—')}</span></td><td><span dir="ltr">${escapeHtml(row?.work_id || '—')}</span></td>
      <td>${escapeHtml(row?.deputy || '—')}</td><td>${escapeHtml(row?.general_department || '—')}</td><td>${escapeHtml(row?.department || '—')}</td>
      <td>${escapeHtml(row?.gender || '—')}</td><td>${escapeHtml(row?.postal_level || '—')}</td>
      <td>${disabled ? '<span class="egm-period-invited-badge">دعوت شده</span>' : 'قابل دعوت'}</td>
    </tr>`;
  }

  function renderPeriodCandidates(pane, data) {
    const state = getPeriodInviteState(pane);
    const body = pane.querySelector('[data-period-candidate-body]');
    const total = pane.querySelector('[data-period-candidate-total]');
    const pageMeta = pane.querySelector('[data-period-candidate-page]');
    const inviteButton = pane.querySelector('[data-period-invite-selected]');
    state.candidates = Array.isArray(data?.rows) ? data.rows : [];
    state.page = Number(data?.page || 1);
    state.pages = Number(data?.pages || 1);
    if (body) body.innerHTML = state.candidates.length
      ? state.candidates.map((row) => periodCandidateRowMarkup(row, state)).join('')
      : '<tr><td colspan="12" class="muted">کاربری مطابق فیلترها پیدا نشد.</td></tr>';
    if (total) total.textContent = String(data?.total ?? state.candidates.length);
    if (pageMeta) pageMeta.textContent = state.matchedMode ? 'نتایج تطبیق فایل' : `صفحه ${state.page} از ${state.pages}`;
    const prev = pane.querySelector('[data-period-candidate-prev]');
    const next = pane.querySelector('[data-period-candidate-next]');
    if (prev instanceof HTMLButtonElement) prev.disabled = state.matchedMode || state.page <= 1;
    if (next instanceof HTMLButtonElement) next.disabled = state.matchedMode || state.page >= state.pages;
    if (inviteButton instanceof HTMLButtonElement) inviteButton.disabled = state.selected.size === 0;
    const selectAll = pane.querySelector('[data-period-candidate-select-all]');
    if (selectAll instanceof HTMLInputElement) {
      const available = state.candidates.filter((row) => !row?.invited);
      selectAll.checked = available.length > 0 && available.every((row) => state.selected.has(String(row.candidate_id || '')));
    }
  }

  function periodUnmatchedDetailsMarkup(row) {
    const matchError = String(row?.match_error || '').trim();
    const entries = Object.entries(row?.raw_data || {}).filter(([, value]) => String(value ?? '').trim() !== '');
    if (!entries.length && !matchError) return '—';
    const reason = matchError ? `<p><strong>دلیل عدم تطبیق:</strong> ${escapeHtml(matchError)}</p>` : '';
    return `<details class="egm-period-excel-details" ${matchError ? 'open' : ''}><summary>${matchError ? 'نمایش دلیل' : 'نمایش اطلاعات'}</summary><div>${reason}${entries.map(([label, value]) => `<p><strong>${escapeHtml(label)}:</strong> ${escapeHtml(value)}</p>`).join('')}</div></details>`;
  }

  function periodUninviteableRows(state) {
    return (Array.isArray(state?.unmatchedRows) ? state.unmatchedRows : []).filter((row) => (
      String(row?.national_id || '').trim() === '' && String(row?.work_id || '').trim() === ''
    ));
  }

  function exportPeriodUninviteable(pane) {
    const state = getPeriodInviteState(pane);
    const rows = periodUninviteableRows(state);
    const status = pane.querySelector('[data-period-unmatched-status]');
    if (!window.XLSX?.utils || typeof window.XLSX.writeFile !== 'function') {
      if (status) status.textContent = 'موتور ساخت فایل Excel بارگذاری نشده است. صفحه را بازخوانی کنید.';
      return;
    }
    if (!rows.length) {
      if (status) status.textContent = 'کاربر غیرقابل دعوتی بدون کد ملی و کد پرسنلی وجود ندارد.';
      return;
    }
    const table = [['Full Name', 'Phone Number']];
    rows.forEach((row) => table.push([
      [String(row?.first_name || '').trim(), String(row?.last_name || '').trim()].filter(Boolean).join(' '),
      String(row?.phone_number || '').trim()
    ]));
    const worksheet = window.XLSX.utils.aoa_to_sheet(table);
    worksheet['!cols'] = [{ wch: 34 }, { wch: 20 }];
    worksheet['!autofilter'] = { ref: `A1:B${table.length}` };
    for (let rowNumber = 2; rowNumber <= table.length; rowNumber += 1) {
      if (worksheet[`B${rowNumber}`]) worksheet[`B${rowNumber}`].z = '@';
    }
    const workbook = window.XLSX.utils.book_new();
    window.XLSX.utils.book_append_sheet(workbook, worksheet, 'Uninviteable');
    const filename = periodExportDatedFilename('کاربران غیرقابل دعوت', pane);
    window.XLSX.writeFile(workbook, filename, { bookType: 'xlsx', compression: true });
    if (status) status.textContent = `${rows.length} کاربر غیرقابل دعوت در فایل Excel دانلود شد.`;
  }

  function renderPeriodUnmatchedRows(pane) {
    const state = getPeriodInviteState(pane);
    const card = pane.querySelector('[data-period-unmatched-card]');
    const body = pane.querySelector('[data-period-unmatched-body]');
    const rows = Array.isArray(state.unmatchedRows) ? state.unmatchedRows : [];
    if (card instanceof HTMLElement) card.hidden = rows.length === 0;
    if (body) body.innerHTML = rows.map((row) => {
      const id = String(row?.excel_id || '');
      const canInvite = row?.can_invite !== false && Boolean(String(row?.national_id || row?.work_id || '').trim());
      const checked = canInvite && state.unmatchedSelected.has(id);
      const matchError = String(row?.match_error || '').trim();
      const action = matchError
        ? `<span class="muted">${escapeHtml(matchError)}</span>`
        : (canInvite ? `<button type="button" class="btn ghost" data-period-invite-unmatched-one="${escapeHtml(id)}">افزودن و دعوت</button>` : '<span class="muted">بدون شناسه قابل استفاده</span>');
      return `<tr data-period-unmatched-id="${escapeHtml(id)}">
        <td><input type="checkbox" data-period-unmatched-check value="${escapeHtml(id)}" ${checked ? 'checked' : ''} ${canInvite ? '' : 'disabled'} /></td>
        <td>${escapeHtml(row?.source_row || '—')}</td><td>${escapeHtml(row?.first_name || '—')}</td><td>${escapeHtml(row?.last_name || '—')}</td>
        <td><span dir="ltr">${escapeHtml(row?.national_id || '—')}</span></td><td><span dir="ltr">${escapeHtml(row?.work_id || '—')}</span></td><td><span dir="ltr">${escapeHtml(row?.phone_number || '—')}</span></td>
        <td>${escapeHtml(row?.deputy || '—')}</td><td>${escapeHtml(row?.general_department || '—')}</td><td>${escapeHtml(row?.department || '—')}</td><td>${escapeHtml(row?.gender || '—')}</td><td>${escapeHtml(row?.postal_level || '—')}</td>
        <td>${periodUnmatchedDetailsMarkup(row)}</td><td>${action}</td>
      </tr>`;
    }).join('');
    const total = pane.querySelector('[data-period-unmatched-total]');
    if (total) total.textContent = String(rows.length);
    const count = pane.querySelector('[data-period-unmatched-selected-count]');
    if (count) count.textContent = String(state.unmatchedSelected.size);
    const bulkButton = pane.querySelector('[data-period-invite-unmatched-selected]');
    if (bulkButton instanceof HTMLButtonElement) bulkButton.disabled = state.unmatchedSelected.size === 0;
    const exportButton = pane.querySelector('[data-period-export-uninviteable]');
    if (exportButton instanceof HTMLButtonElement) exportButton.disabled = periodUninviteableRows(state).length === 0;
    const selectAll = pane.querySelector('[data-period-unmatched-select-all]');
    if (selectAll instanceof HTMLInputElement) {
      const selectable = rows.filter((row) => row?.can_invite !== false && String(row?.national_id || row?.work_id || '').trim());
      selectAll.checked = selectable.length > 0 && selectable.every((row) => state.unmatchedSelected.has(String(row.excel_id || '')));
      selectAll.indeterminate = state.unmatchedSelected.size > 0 && !selectAll.checked;
    }
  }

  async function invitePeriodUnmatchedRows(pane, excelIds) {
    const state = getPeriodInviteState(pane);
    const wanted = new Set((excelIds || []).map(String));
    const rows = state.unmatchedRows.filter((row) => wanted.has(String(row?.excel_id || '')) && row?.can_invite !== false);
    if (!rows.length) return;
    const status = pane.querySelector('[data-period-unmatched-status]');
    if (status) status.textContent = 'در حال افزودن کاربران به EGM...';
    try {
      const data = await requestPeriodInvites('invite_unmatched', { period_code: periodCodeForPane(pane), rows }, 'POST');
      state.unmatchedRows = state.unmatchedRows.filter((row) => !wanted.has(String(row?.excel_id || '')));
      wanted.forEach((id) => state.unmatchedSelected.delete(id));
      renderPeriodUnmatchedRows(pane);
      if (status) status.textContent = data?.message || 'کاربران انتخاب‌شده افزوده و دعوت شدند.';
      await loadPeriodInvitees(pane, 1);
      await loadPeriodCandidates(pane, 1);
    } catch (error) {
      if (status) status.textContent = error?.message || 'افزودن کاربران بدون تطبیق ناموفق بود.';
    }
  }

  async function loadPeriodFilterOptions(pane) {
    const periodCode = String(pane.querySelector('[data-task-field="taskTitle"]')?.closest('.sub-pane')?.dataset.taskId || pane.dataset.taskId || '');
    const tagCode = String(pane.dataset.periodCode || '');
    const data = await requestPeriodInvites('filter_options', { period_code: tagCode || pane.dataset.taskTagCode || periodCode });
    const options = data?.options || {};
    const labels = { deputy: 'همه معاونت‌ها', general_department: 'همه اداره‌های کل', department: 'همه اداره‌ها', gender: 'همه', postal_level: 'همه سطوح' };
    Object.entries(labels).forEach(([name, placeholder]) => {
      const select = pane.querySelector(`[data-period-invite-filter-form] select[name="${name}"]`);
      if (!(select instanceof HTMLSelectElement)) return;
      select.innerHTML = `<option value="">${placeholder}</option>` + (Array.isArray(options[name]) ? options[name] : [])
        .map((value) => `<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`).join('');
    });
    const sourceEl = pane.querySelector('[data-period-invite-source]');
    if (sourceEl) sourceEl.textContent = periodSourceLabel(data?.source);
    getPeriodInviteState(pane).source = String(data?.source || '');
  }

  function periodCodeForPane(pane) {
    return String(pane.dataset.taskTagCode || '').trim();
  }

  function periodDateForPane(pane) {
    const candidates = [
      pane?.dataset?.taskStartDate,
      pane?.dataset?.taskEnterDeadlineDate,
      pane?.dataset?.taskQuitOpeningDate,
      pane?.dataset?.taskEndDate
    ];
    for (const value of candidates) {
      const date = String(value || '').slice(0, 10);
      if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) continue;
      const [year, month, day] = date.split('-').map(Number);
      const parsed = new Date(`${date}T12:00:00`);
      if (!Number.isNaN(parsed.getTime())
        && parsed.getFullYear() === year
        && parsed.getMonth() + 1 === month
        && parsed.getDate() === day) return date;
    }
    return '';
  }

  function periodExportShamsiDayMonth(gregorianDate = '') {
    const dateText = String(gregorianDate || '').slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(dateText)) return '';
    const parsed = new Date(`${dateText}T12:00:00`);
    if (Number.isNaN(parsed.getTime())) return '';
    const parts = new Intl.DateTimeFormat('fa-IR-u-ca-persian-nu-latn', {
      day: 'numeric', month: 'long', timeZone: 'Asia/Tehran'
    }).formatToParts(parsed);
    const day = parts.find((part) => part.type === 'day')?.value || '';
    const month = parts.find((part) => part.type === 'month')?.value || '';
    return `${day} ${month}ماه`.trim();
  }

  function periodExportDatedFilename(label, pane, explicitDate = '') {
    const shamsiDate = periodExportShamsiDayMonth(explicitDate || periodDateForPane(pane));
    if (!shamsiDate) throw new Error('تاریخ بازه تنظیم نشده است؛ نام فایل از تاریخ امروز ساخته نمی‌شود.');
    return `${label} ${shamsiDate}.xlsx`
      .replace(/[\\/:*?"<>|]+/g, '-');
  }

  async function loadPeriodCandidates(pane, page = 1) {
    const state = getPeriodInviteState(pane);
    state.matchedMode = false;
    const form = pane.querySelector('[data-period-invite-filter-form]');
    const params = { period_code: periodCodeForPane(pane), page: String(page), page_size: '50' };
    if (form instanceof HTMLFormElement) {
      new FormData(form).forEach((value, key) => { params[key] = String(value || ''); });
    }
    const status = pane.querySelector('[data-period-candidate-status]');
    if (status) status.textContent = 'در حال بارگذاری...';
    try {
      const data = await requestPeriodInvites('list_candidates', params);
      state.source = String(data?.source || '');
      const sourceEl = pane.querySelector('[data-period-invite-source]');
      if (sourceEl) sourceEl.textContent = periodSourceLabel(state.source);
      renderPeriodCandidates(pane, data);
      if (status) status.textContent = '';
    } catch (error) {
      if (status) status.textContent = error?.message || 'بارگذاری کاربران ناموفق بود.';
    }
  }

  let periodInviteeEditorContext = null;

  function ensurePeriodInviteeEditor() {
    let modal = document.querySelector('[data-period-invitee-editor]');
    if (modal instanceof HTMLElement) return modal;
    modal = document.createElement('div');
    modal.className = 'egm-period-invitee-editor';
    modal.dataset.periodInviteeEditor = '1';
    modal.hidden = true;
    modal.innerHTML = `
      <section class="egm-period-invitee-editor-dialog" role="dialog" aria-modal="true" aria-labelledby="egm-period-invitee-editor-title" dir="rtl">
        <header class="egm-period-invitee-editor-head">
          <div><span>ویرایش دعوت‌شونده و حضور</span><h3 id="egm-period-invitee-editor-title" data-period-invitee-editor-title>ویرایش مهمان</h3></div>
          <button type="button" class="btn ghost" data-period-invitee-editor-close aria-label="بستن">بستن</button>
        </header>
        <form data-period-invitee-editor-form>
          <div class="egm-period-invitee-editor-body">
            <section class="egm-period-invitee-editor-section">
              <div class="egm-period-invitee-editor-section-head"><h4>اطلاعات مهمان</h4><small data-period-invitee-editor-source></small></div>
              <div class="egm-period-invitee-editor-grid">
                <label class="field"><span>نام</span><input name="first_name" type="text" maxlength="191" /></label>
                <label class="field"><span>نام خانوادگی</span><input name="last_name" type="text" maxlength="191" /></label>
                <label class="field"><span>کد ملی</span><input name="national_id" type="text" inputmode="numeric" maxlength="10" dir="ltr" /></label>
                <label class="field"><span>کد پرسنلی</span><input name="work_id" type="text" maxlength="128" dir="ltr" /></label>
                <label class="field"><span>شماره همراه</span><input name="phone_number" type="text" maxlength="32" dir="ltr" /></label>
                <label class="field"><span>شماره مهمان</span><input name="guest_number" type="text" maxlength="32" dir="ltr" /></label>
                <label class="field"><span>معاونت</span><input name="deputy" type="text" maxlength="191" /></label>
                <label class="field"><span>اداره کل</span><input name="general_department" type="text" maxlength="191" /></label>
                <label class="field"><span>اداره</span><input name="department" type="text" maxlength="191" /></label>
                <label class="field"><span>جنسیت</span><input name="gender" type="text" maxlength="32" /></label>
                <label class="field"><span>سطح پستی</span><input name="postal_level" type="text" maxlength="64" /></label>
              </div>
              <div class="egm-period-invitee-editor-checks">
                <label><input name="is_active" type="checkbox" /> کاربر فعال است</label>
                <label><input name="is_uninvited_guest" type="checkbox" /> مهمان ناخوانده است</label>
                <label><input name="outside_organization" type="checkbox" /> خارج از سازمان است</label>
              </div>
              <p class="hint" data-period-invitee-editor-profile-note>تغییر مشخصات مهمان در همه بازه‌های همین EGM دیده می‌شود؛ سوابق حضور فقط در همین بازه تغییر می‌کند.</p>
            </section>
            <section class="egm-period-invitee-editor-section egm-period-invitee-editor-attendance">
              <div class="egm-period-invitee-editor-section-head"><h4>حضور در همین بازه</h4><small>ثبت یا اصلاح دستی</small></div>
              <div class="egm-period-invitee-editor-grid">
                <label class="field full"><span>وضعیت حضور</span><select name="attendance_state">
                  <option value="not_entered">ورود ثبت نشده</option><option value="entered">وارد شده، خروج ثبت نشده</option><option value="quit_completed">ورود و خروج ثبت شده</option>
                </select></label>
                <label class="field"><span>تاریخ ورود</span><input name="entered_date" type="date" /></label>
                <label class="field"><span>ساعت ورود</span><input name="entered_time" type="time" step="1" /></label>
                <label class="field"><span>تاریخ خروج</span><input name="quit_date" type="date" /></label>
                <label class="field"><span>ساعت خروج</span><input name="quit_time" type="time" step="1" /></label>
                <label class="field full"><span>طبقه‌بندی حضور</span><select name="presence_classification">
                  <option value="none">بدون پرچم حضور</option><option value="correct_presence">Correct Presence — حضور واقعی</option><option value="fake_presence">Fake Presence — حضور نامعقول</option>
                </select></label>
              </div>
              <p class="hint">انتخاب «ورود ثبت نشده» تاریخ‌ها، ساعت‌ها و پرچم حضور همین بازه را پاک می‌کند.</p>
            </section>
          </div>
          <footer class="egm-period-invitee-editor-actions">
            <p class="hint" data-period-invitee-editor-status aria-live="polite"></p>
            <div><button type="button" class="btn ghost" data-period-invitee-editor-cancel>انصراف</button><button type="submit" class="btn primary standard-primary-button" data-period-invitee-editor-save>ذخیره تغییرات</button></div>
          </footer>
        </form>
      </section>`;
    const close = () => {
      modal.hidden = true;
      document.body.classList.remove('egm-period-invitee-editor-open');
      periodInviteeEditorContext = null;
    };
    modal.addEventListener('click', (event) => {
      if (event.target === modal || (event.target instanceof Element && event.target.closest('[data-period-invitee-editor-close],[data-period-invitee-editor-cancel]'))) close();
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !modal.hidden) close();
    });
    modal.querySelector('select[name="attendance_state"]')?.addEventListener('change', () => syncPeriodInviteeEditorAttendance(modal));
    modal.querySelector('[data-period-invitee-editor-form]')?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const context = periodInviteeEditorContext;
      const form = event.currentTarget;
      if (!context || !(form instanceof HTMLFormElement)) return;
      const saveButton = modal.querySelector('[data-period-invitee-editor-save]');
      const status = modal.querySelector('[data-period-invitee-editor-status]');
      if (saveButton instanceof HTMLButtonElement) saveButton.disabled = true;
      if (status) status.textContent = 'در حال ذخیره تغییرات...';
      try {
        const values = Object.fromEntries(new FormData(form).entries());
        for (const name of ['is_active', 'is_uninvited_guest', 'outside_organization']) {
          const control = form.elements.namedItem(name);
          values[name] = control instanceof HTMLInputElement && control.checked;
        }
        const data = await requestPeriodInvites('update_invitee', {
          ...values,
          period_code: periodCodeForPane(context.pane),
          invite_id: String(context.row?.invite_id || '')
        }, 'POST');
        await loadPeriodInvitees(context.pane, getPeriodInviteState(context.pane).inviteePage);
        const listStatus = context.pane.querySelector('[data-period-invitee-status]');
        if (listStatus) listStatus.textContent = data?.message || 'اطلاعات مهمان و حضور ذخیره شد.';
        close();
      } catch (error) {
        if (status) status.textContent = error?.message || 'ذخیره اطلاعات مهمان ناموفق بود.';
      } finally {
        if (saveButton instanceof HTMLButtonElement) saveButton.disabled = false;
      }
    });
    document.body.appendChild(modal);
    return modal;
  }

  function syncPeriodInviteeEditorAttendance(modal) {
    const form = modal?.querySelector('[data-period-invitee-editor-form]');
    if (!(form instanceof HTMLFormElement)) return;
    const attendanceControl = form.elements.namedItem('attendance_state');
    const state = attendanceControl instanceof HTMLSelectElement ? attendanceControl.value : 'not_entered';
    for (const name of ['entered_date', 'entered_time']) {
      const control = form.elements.namedItem(name);
      if (control instanceof HTMLInputElement) control.disabled = state === 'not_entered';
    }
    for (const name of ['quit_date', 'quit_time']) {
      const control = form.elements.namedItem(name);
      if (control instanceof HTMLInputElement) control.disabled = state !== 'quit_completed';
    }
    const classification = form.elements.namedItem('presence_classification');
    if (classification instanceof HTMLSelectElement) {
      classification.disabled = state === 'not_entered';
      if (state === 'not_entered') classification.value = 'none';
    }
  }

  function openPeriodInviteeEditor(pane, row) {
    const modal = ensurePeriodInviteeEditor();
    const form = modal.querySelector('[data-period-invitee-editor-form]');
    if (!(form instanceof HTMLFormElement)) return;
    periodInviteeEditorContext = { pane, row };
    const setValue = (name, value) => {
      const control = form.elements.namedItem(name);
      if (control instanceof HTMLInputElement || control instanceof HTMLSelectElement) control.value = String(value ?? '');
    };
    for (const name of ['first_name', 'last_name', 'national_id', 'work_id', 'phone_number', 'guest_number', 'deputy', 'general_department', 'department', 'gender', 'postal_level', 'entered_date', 'entered_time', 'quit_date', 'quit_time']) {
      setValue(name, row?.[name] || '');
    }
    const attendanceState = ['entered', 'quit_completed'].includes(String(row?.attendance_state || ''))
      ? String(row.attendance_state)
      : (row?.quit_date && row?.quit_time ? 'quit_completed' : (row?.entered_date && row?.entered_time ? 'entered' : 'not_entered'));
    setValue('attendance_state', attendanceState);
    setValue('presence_classification', Number(row?.correct_presence || 0) === 1 ? 'correct_presence' : (Number(row?.fake_presence || 0) === 1 ? 'fake_presence' : 'none'));
    for (const [name, checked] of Object.entries({
      is_active: Number(row?.is_active ?? 1) === 1,
      is_uninvited_guest: Number(row?.is_uninvited_guest || row?.period_is_uninvited_guest || 0) === 1,
      outside_organization: Number(row?.outside_organization || 0) === 1
    })) {
      const control = form.elements.namedItem(name);
      if (control instanceof HTMLInputElement) control.checked = checked;
    }
    const fullName = [row?.first_name, row?.last_name].filter(Boolean).join(' ').trim();
    const title = modal.querySelector('[data-period-invitee-editor-title]');
    if (title) title.textContent = fullName || `مهمان ${row?.guest_number || ''}`.trim();
    const source = modal.querySelector('[data-period-invitee-editor-source]');
    if (source) source.textContent = `منبع: ${periodSourceLabel(row?.invitation_source || row?.source_type || '')}`;
    const profileNote = modal.querySelector('[data-period-invitee-editor-profile-note]');
    if (profileNote) profileNote.textContent = String(row?.source_type || '').toLowerCase() === 'oeu'
      ? 'این مهمان از OEU آمده است؛ تغییر مشخصات او برای ماندگاری با رکورد OEU نیز همگام می‌شود. سوابق حضور فقط در همین بازه تغییر می‌کند.'
      : 'تغییر مشخصات مهمان در همه بازه‌های همین EGM دیده می‌شود؛ سوابق حضور فقط در همین بازه تغییر می‌کند.';
    const status = modal.querySelector('[data-period-invitee-editor-status]');
    if (status) status.textContent = '';
    syncPeriodInviteeEditorAttendance(modal);
    modal.hidden = false;
    document.body.classList.add('egm-period-invitee-editor-open');
    window.setTimeout(() => {
      const firstName = form.elements.namedItem('first_name');
      if (firstName instanceof HTMLInputElement) firstName.focus();
    }, 0);
  }

  function renderPeriodInvitees(pane, data) {
    const state = getPeriodInviteState(pane);
    state.inviteePage = Number(data?.page || 1);
    state.inviteePages = Number(data?.pages || 1);
    const rows = Array.isArray(data?.rows) ? data.rows : [];
    state.invitees = rows;
    const body = pane.querySelector('[data-period-invitee-body]');
    if (body) body.innerHTML = rows.length ? rows.map((row) => `<tr>
      <td><code>${escapeHtml(row?.guest_number || '—')}</code></td><td>${escapeHtml(row?.first_name || '—')}</td><td>${escapeHtml(row?.last_name || '—')}</td>
      <td><span dir="ltr">${escapeHtml(row?.national_id || '—')}</span></td><td><span dir="ltr">${escapeHtml(row?.work_id || '—')}</span></td>
      <td>${escapeHtml(row?.deputy || '—')}</td><td>${escapeHtml(row?.general_department || '—')}</td><td>${escapeHtml(row?.department || '—')}</td>
      <td>${escapeHtml(row?.gender || '—')}</td><td>${escapeHtml(row?.postal_level || '—')}</td><td>${Number(row?.correct_presence || 0) === 1 ? 'بله' : '—'}</td><td>${Number(row?.fake_presence || 0) === 1 ? 'بله' : '—'}</td><td>${escapeHtml(periodSourceLabel(row?.invitation_source || row?.source))}</td>
      <td><div class="egm-period-invitee-row-actions"><button type="button" class="btn ghost" data-period-edit-invite="${escapeHtml(row?.invite_id || '')}">ویرایش</button><button type="button" class="btn ghost egm-btn-danger" data-period-remove-invite="${escapeHtml(row?.invite_id || '')}">حذف دعوت</button></div></td>
    </tr>`).join('') : '<tr><td colspan="14" class="muted">هنوز کسی به این بازه دعوت نشده است.</td></tr>';
    const total = pane.querySelector('[data-period-invitee-total]');
    if (total) total.textContent = String(data?.total || 0);
    const meta = pane.querySelector('[data-period-invitee-page]');
    if (meta) meta.textContent = `صفحه ${state.inviteePage} از ${state.inviteePages}`;
    const prev = pane.querySelector('[data-period-invitee-prev]');
    const next = pane.querySelector('[data-period-invitee-next]');
    if (prev instanceof HTMLButtonElement) prev.disabled = state.inviteePage <= 1;
    if (next instanceof HTMLButtonElement) next.disabled = state.inviteePage >= state.inviteePages;
  }

  async function loadPeriodInvitees(pane, page = 1) {
    const status = pane.querySelector('[data-period-invitee-status]');
    const search = pane.querySelector('[data-period-invitee-search]');
    if (status) status.textContent = 'در حال بارگذاری...';
    try {
      const data = await requestPeriodInvites('list_invitees', { period_code: periodCodeForPane(pane), page: String(page), page_size: '50', q: search?.value || '' });
      renderPeriodInvitees(pane, data);
      if (status) status.textContent = '';
    } catch (error) {
      if (status) status.textContent = error?.message || 'بارگذاری دعوت‌شدگان ناموفق بود.';
    }
  }

  async function parsePeriodExcel(file) {
    if (!window.XLSX) throw new Error('کتابخانه خواندن Excel در دسترس نیست.');
    const workbook = window.XLSX.read(await file.arrayBuffer(), { type: 'array' });
    if (!Array.isArray(workbook?.SheetNames) || workbook.SheetNames.length < 1) {
      throw new Error('این فایل هیچ شیت قابل خواندنی ندارد.');
    }
    return workbook;
  }

  function normalizePeriodExcelHeader(value) {
    return String(value ?? '')
      .normalize('NFKC')
      .toLowerCase()
      .replace(/[\u064b-\u065f\u0670\u06d6-\u06ed]/g, '')
      .replace(/[\u200b-\u200f\u202a-\u202e\u2060\ufeff]/g, ' ')
      .replace(/[يىئ]/g, 'ی')
      .replace(/[كڪ]/g, 'ک')
      .replace(/[ةۀ]/g, 'ه')
      .replace(/ؤ/g, 'و')
      .replace(/[إأآٱ]/g, 'ا')
      .replace(/[۰-۹]/g, (digit) => String(digit.charCodeAt(0) - 0x06f0))
      .replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660))
      .replace(/[^\p{L}\p{N}]+/gu, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function periodExcelHeaderSimilarity(left, right) {
    if (!left || !right) return 0;
    if (left === right) return 1;
    const rows = Array.from({ length: right.length + 1 }, (_, index) => index);
    for (let leftIndex = 1; leftIndex <= left.length; leftIndex += 1) {
      let previous = rows[0];
      rows[0] = leftIndex;
      for (let rightIndex = 1; rightIndex <= right.length; rightIndex += 1) {
        const current = rows[rightIndex];
        const substitution = previous + (left[leftIndex - 1] === right[rightIndex - 1] ? 0 : 1);
        rows[rightIndex] = Math.min(rows[rightIndex] + 1, rows[rightIndex - 1] + 1, substitution);
        previous = current;
      }
    }
    return 1 - (rows[right.length] / Math.max(left.length, right.length));
  }

  function suggestPeriodExcelColumn(headers, aliases) {
    const normalized = headers.map(normalizePeriodExcelHeader);
    const normalizedAliases = aliases.map(normalizePeriodExcelHeader);
    let index = normalized.findIndex((header) => normalizedAliases.includes(header));
    if (index >= 0) return String(index);
    index = normalized.findIndex((header) => normalizedAliases.some((alias) => alias.length > 3 && header.includes(alias)));
    if (index >= 0) return String(index);

    let closestIndex = -1;
    let closestScore = 0;
    normalized.forEach((header, headerIndex) => {
      if (header.length < 4) return;
      normalizedAliases.forEach((alias) => {
        if (alias.length < 4) return;
        const lengthRatio = Math.min(header.length, alias.length) / Math.max(header.length, alias.length);
        if (lengthRatio < 0.55) return;
        const score = periodExcelHeaderSimilarity(header, alias);
        if (score > closestScore) {
          closestScore = score;
          closestIndex = headerIndex;
        }
      });
    });
    return closestIndex >= 0 && closestScore >= 0.72 ? String(closestIndex) : '';
  }

  const periodExcelMappings = [
    ['national', ['national id', 'nationalid', 'کد ملی']], ['work', ['work id', 'workid', 'personnel id', 'کد پرسنلی', 'شماره پرسنلی']],
    ['first', ['first name', 'firstname', 'نام']], ['last', ['last name', 'lastname', 'family', 'surname', 'نام خانوادگی']],
    ['phone', ['phone number', 'phone', 'mobile', 'شماره همراه', 'شماره موبایل', 'تلفن همراه', 'موبایل']], ['deputy', ['deputy', 'معاونت']],
    ['general-department', ['general department', 'اداره کل']], ['department', ['department', 'اداره']], ['gender', ['gender', 'جنسیت']],
    ['postal-level', ['postal level', 'postal grade', 'سطح پستی', 'رتبه پستی']]
  ];

  function applyPeriodExcelSheet(pane, sheetName) {
    const state = getPeriodInviteState(pane);
    if (!state.excelWorkbook || !sheetName || !state.excelWorkbook.Sheets?.[sheetName]) {
      throw new Error('یک شیت معتبر از فایل Excel انتخاب کنید.');
    }
    const rows = window.XLSX.utils.sheet_to_json(state.excelWorkbook.Sheets[sheetName], {
      header: 1,
      defval: '',
      raw: false,
      blankrows: false
    });
    if (!Array.isArray(rows) || rows.length < 1 || !Array.isArray(rows[0])) {
      throw new Error('شیت انتخاب‌شده خالی است یا سطر عنوان ندارد.');
    }
    const headers = rows[0].map((value) => String(value ?? '').trim());
    if (!headers.some(Boolean)) {
      throw new Error('سطر اول شیت انتخاب‌شده باید عنوان ستون‌ها را داشته باشد.');
    }

    state.excelSheetName = sheetName;
    state.excelRows = rows;
    state.excelHeaders = headers;
    state.matchedMode = false;
    state.selected.clear();
    state.unmatchedRows = [];
    state.unmatchedSelected.clear();
    renderPeriodUnmatchedRows(pane);

    const optionMarkup = '<option value="">انتخاب نشده</option>' + headers
      .map((label, index) => `<option value="${index}">${escapeHtml(label || `ستون ${index + 1}`)}</option>`).join('');
    periodExcelMappings.forEach(([key, aliases]) => {
      const select = pane.querySelector(`[data-period-excel-${key}]`);
      if (!(select instanceof HTMLSelectElement)) return;
      select.innerHTML = optionMarkup;
      select.value = suggestPeriodExcelColumn(headers, aliases);
    });
    const mapping = pane.querySelector('[data-period-excel-mapping]');
    if (mapping) mapping.hidden = false;
    const match = pane.querySelector('[data-period-excel-match]');
    if (match instanceof HTMLButtonElement) match.disabled = rows.length < 2;
    const status = pane.querySelector('[data-period-excel-status]');
    if (status) status.textContent = `شیت «${sheetName}» انتخاب شد؛ ${Math.max(0, rows.length - 1)} ردیف آماده نگاشت و تطبیق است.`;
  }

  function setupPeriodInvitationPane(pane, task) {
    if (!(pane instanceof HTMLElement) || pane.dataset.periodInvitesReady === '1') return;
    pane.dataset.periodInvitesReady = '1';
    pane.dataset.taskTagCode = String(task?.tagCode || '');
    pane.dataset.taskStartDate = String(task?.startDate || task?.start_date || '');
    pane.dataset.taskEnterDeadlineDate = String(task?.enterDeadlineDate || task?.enter_deadline_date || '');
    pane.dataset.taskQuitOpeningDate = String(task?.quitOpeningDate || task?.quit_opening_date || '');
    pane.dataset.taskEndDate = String(task?.endDate || task?.end_date || '');
    const state = getPeriodInviteState(pane);
    pane.querySelector('[data-period-invite-card-generate]')?.addEventListener('click', () => void generatePeriodInviteCards(pane));
    pane.querySelector('[data-period-invite-card-export]')?.addEventListener('click', () => exportPeriodInviteCardLinks(pane));
    pane.querySelector('[data-period-invite-card-refresh]')?.addEventListener('click', () => void Promise.all([loadPeriodInviteCardStatus(pane), loadPeriodInviteCardBackground(pane)]));
    pane.querySelector('[data-period-invite-card-background-pick]')?.addEventListener('click', () => {
      pane.querySelector('[data-period-invite-card-background-file]')?.click();
    });
    pane.querySelector('[data-period-invite-card-background-save]')?.addEventListener('click', () => void savePeriodInviteCardBackground(pane));
    pane.querySelector('[data-period-invite-card-background-remove]')?.addEventListener('click', () => void removePeriodInviteCardBackground(pane));
    pane.querySelector('[data-period-invite-card-background-file]')?.addEventListener('change', async (event) => {
      const input = event.currentTarget;
      if (!(input instanceof HTMLInputElement) || !input.files?.[0]) return;
      try {
        const draft = await readPeriodInviteCardBackgroundFile(input.files[0]);
        state.periodBackgroundDraft = draft;
        const expectedWidth = Number(state.periodBackground?.imageWidth || 0);
        const expectedHeight = Number(state.periodBackground?.imageHeight || 0);
        const message = expectedWidth > 0 && expectedHeight > 0 && (draft.imageWidth !== expectedWidth || draft.imageHeight !== expectedHeight)
          ? `این تصویر ${draft.imageWidth}×${draft.imageHeight} است؛ ابعاد لازم ${expectedWidth}×${expectedHeight} پیکسل است.`
          : 'تصویر انتخاب شد؛ برای نگهداری آن دکمه ذخیره تصویر را بزنید.';
        renderPeriodInviteCardBackground(pane, draft, message);
      } catch (error) {
        state.periodBackgroundDraft = null;
        renderPeriodInviteCardBackground(pane, state.periodBackground || {}, error?.message || 'انتخاب تصویر ناموفق بود.');
      } finally {
        input.value = '';
      }
    });
    const filterForm = pane.querySelector('[data-period-invite-filter-form]');
    filterForm?.addEventListener('submit', (event) => { event.preventDefault(); state.selected.clear(); void loadPeriodCandidates(pane, 1); });
    pane.querySelector('[data-period-invite-clear]')?.addEventListener('click', () => { filterForm?.reset(); state.selected.clear(); void loadPeriodCandidates(pane, 1); });
    pane.querySelector('[data-period-candidate-prev]')?.addEventListener('click', () => void loadPeriodCandidates(pane, Math.max(1, state.page - 1)));
    pane.querySelector('[data-period-candidate-next]')?.addEventListener('click', () => void loadPeriodCandidates(pane, Math.min(state.pages, state.page + 1)));
    pane.querySelector('[data-period-invitee-prev]')?.addEventListener('click', () => void loadPeriodInvitees(pane, Math.max(1, state.inviteePage - 1)));
    pane.querySelector('[data-period-invitee-next]')?.addEventListener('click', () => void loadPeriodInvitees(pane, Math.min(state.inviteePages, state.inviteePage + 1)));
    pane.querySelector('[data-period-invitee-refresh]')?.addEventListener('click', () => void loadPeriodInvitees(pane, state.inviteePage));
    let searchTimer = 0;
    pane.querySelector('[data-period-invitee-search]')?.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = window.setTimeout(() => loadPeriodInvitees(pane, 1), 250); });
    pane.addEventListener('change', (event) => {
      const target = event.target;
      if (target instanceof HTMLInputElement && target.matches('[data-period-candidate-check]')) {
        target.checked ? state.selected.add(target.value) : state.selected.delete(target.value);
        renderPeriodCandidates(pane, { rows: state.candidates, total: pane.querySelector('[data-period-candidate-total]')?.textContent || state.candidates.length, page: state.page, pages: state.pages });
      }
      if (target instanceof HTMLInputElement && target.matches('[data-period-candidate-select-all]')) {
        state.candidates.filter((row) => !row?.invited).forEach((row) => target.checked ? state.selected.add(String(row.candidate_id)) : state.selected.delete(String(row.candidate_id)));
        renderPeriodCandidates(pane, { rows: state.candidates, total: pane.querySelector('[data-period-candidate-total]')?.textContent || state.candidates.length, page: state.page, pages: state.pages });
      }
      if (target instanceof HTMLInputElement && target.matches('[data-period-unmatched-check]')) {
        target.checked ? state.unmatchedSelected.add(target.value) : state.unmatchedSelected.delete(target.value);
        renderPeriodUnmatchedRows(pane);
      }
      if (target instanceof HTMLInputElement && target.matches('[data-period-unmatched-select-all]')) {
        state.unmatchedRows.filter((row) => row?.can_invite !== false && String(row?.national_id || row?.work_id || '').trim()).forEach((row) => {
          const id = String(row.excel_id || '');
          target.checked ? state.unmatchedSelected.add(id) : state.unmatchedSelected.delete(id);
        });
        renderPeriodUnmatchedRows(pane);
      }
    });
    pane.querySelector('[data-period-invite-selected]')?.addEventListener('click', async () => {
      const status = pane.querySelector('[data-period-candidate-status]');
      try {
        const data = await requestPeriodInvites('invite', { period_code: periodCodeForPane(pane), candidate_ids: Array.from(state.selected) }, 'POST');
        state.selected.clear();
        if (status) status.textContent = data?.message || 'دعوت‌ها ذخیره شدند.';
        await loadPeriodCandidates(pane, 1);
        await loadPeriodInvitees(pane, 1);
      } catch (error) { if (status) status.textContent = error?.message || 'دعوت کاربران ناموفق بود.'; }
    });
    pane.addEventListener('click', async (event) => {
      const unmatchedInvite = event.target instanceof Element ? event.target.closest('[data-period-invite-unmatched-one]') : null;
      if (unmatchedInvite instanceof HTMLButtonElement) {
        await invitePeriodUnmatchedRows(pane, [unmatchedInvite.dataset.periodInviteUnmatchedOne || '']);
        return;
      }
      const edit = event.target instanceof Element ? event.target.closest('[data-period-edit-invite]') : null;
      if (edit instanceof HTMLButtonElement) {
        const row = state.invitees.find((item) => String(item?.invite_id || '') === String(edit.dataset.periodEditInvite || ''));
        if (row) openPeriodInviteeEditor(pane, row);
        return;
      }
      const remove = event.target instanceof Element ? event.target.closest('[data-period-remove-invite]') : null;
      if (!(remove instanceof HTMLButtonElement)) return;
      if (!window.confirm('دعوت این کاربر از بازه حذف شود؟')) return;
      try {
        await requestPeriodInvites('remove', { period_code: periodCodeForPane(pane), invite_id: remove.dataset.periodRemoveInvite || '' }, 'POST');
        await loadPeriodInvitees(pane, state.inviteePage);
        await loadPeriodCandidates(pane, state.page);
      } catch (error) { const status = pane.querySelector('[data-period-invitee-status]'); if (status) status.textContent = error?.message || 'حذف دعوت ناموفق بود.'; }
    });
    pane.querySelector('[data-period-invite-unmatched-selected]')?.addEventListener('click', () => void invitePeriodUnmatchedRows(pane, Array.from(state.unmatchedSelected)));
    pane.querySelector('[data-period-export-uninviteable]')?.addEventListener('click', () => exportPeriodUninviteable(pane));
    const fileInput = pane.querySelector('[data-period-excel-file]');
    const sheetSelect = pane.querySelector('[data-period-excel-sheet]');
    pane.querySelector('[data-period-excel-pick]')?.addEventListener('click', () => {
      if (fileInput instanceof HTMLInputElement) fileInput.value = '';
      fileInput?.click();
    });
    fileInput?.addEventListener('change', async () => {
      const file = fileInput.files?.[0];
      if (!file) return;
      const status = pane.querySelector('[data-period-excel-status]');
      try {
        state.excelWorkbook = await parsePeriodExcel(file);
        state.excelSheetName = '';
        state.excelRows = [];
        state.excelHeaders = [];
        state.matchedMode = false;
        state.selected.clear();
        state.unmatchedRows = [];
        state.unmatchedSelected.clear();
        renderPeriodUnmatchedRows(pane);
        pane.querySelector('[data-period-excel-name]').textContent = file.name;
        if (sheetSelect instanceof HTMLSelectElement) {
          sheetSelect.replaceChildren(new Option('انتخاب شیت...', ''));
          state.excelWorkbook.SheetNames.forEach((name) => sheetSelect.add(new Option(String(name), String(name))));
          sheetSelect.disabled = false;
          sheetSelect.value = '';
        }
        const sheetRow = pane.querySelector('[data-period-excel-sheet-row]');
        if (sheetRow) sheetRow.hidden = false;
        const mapping = pane.querySelector('[data-period-excel-mapping]');
        if (mapping) mapping.hidden = true;
        const match = pane.querySelector('[data-period-excel-match]');
        if (match instanceof HTMLButtonElement) match.disabled = true;
        if (status) status.textContent = `فایل شامل ${state.excelWorkbook.SheetNames.length} شیت است؛ شیت موردنظر را انتخاب کنید.`;
      } catch (error) {
        state.excelWorkbook = null;
        const sheetRow = pane.querySelector('[data-period-excel-sheet-row]');
        const mapping = pane.querySelector('[data-period-excel-mapping]');
        const match = pane.querySelector('[data-period-excel-match]');
        if (sheetRow) sheetRow.hidden = true;
        if (mapping) mapping.hidden = true;
        if (match instanceof HTMLButtonElement) match.disabled = true;
        if (status) status.textContent = error?.message || 'خواندن فایل ناموفق بود.';
      }
    });
    sheetSelect?.addEventListener('change', () => {
      const status = pane.querySelector('[data-period-excel-status]');
      const selectedSheet = sheetSelect instanceof HTMLSelectElement ? sheetSelect.value : '';
      const mapping = pane.querySelector('[data-period-excel-mapping]');
      const match = pane.querySelector('[data-period-excel-match]');
      if (!selectedSheet) {
        state.excelSheetName = '';
        state.excelRows = [];
        state.excelHeaders = [];
        if (mapping) mapping.hidden = true;
        if (match instanceof HTMLButtonElement) match.disabled = true;
        if (status) status.textContent = 'برای پردازش ستون‌ها ابتدا یک شیت را انتخاب کنید.';
        return;
      }
      try {
        applyPeriodExcelSheet(pane, selectedSheet);
      } catch (error) {
        if (mapping) mapping.hidden = true;
        if (match instanceof HTMLButtonElement) match.disabled = true;
        if (status) status.textContent = error?.message || 'خواندن شیت انتخاب‌شده ناموفق بود.';
      }
    });
    pane.querySelector('[data-period-excel-match]')?.addEventListener('click', async () => {
      const nationalIndex = pane.querySelector('[data-period-excel-national]')?.value ?? '';
      const workIndex = pane.querySelector('[data-period-excel-work]')?.value ?? '';
      const status = pane.querySelector('[data-period-excel-status]');
      if (nationalIndex === '' && workIndex === '') { if (status) status.textContent = 'حداقل یکی از ستون‌های کد ملی یا کد پرسنلی را انتخاب کنید.'; return; }
      const mappedValue = (row, key) => {
        const value = pane.querySelector(`[data-period-excel-${key}]`)?.value ?? '';
        return value === '' ? '' : String(row[Number(value)] ?? '');
      };
      const rows = state.excelRows.slice(1).map((row, index) => ({
        excel_id: `x:${index + 2}`, source_row: index + 2,
        national_id: nationalIndex === '' ? '' : String(row[Number(nationalIndex)] ?? ''), work_id: workIndex === '' ? '' : String(row[Number(workIndex)] ?? ''),
        first_name: mappedValue(row, 'first'), last_name: mappedValue(row, 'last'), phone_number: mappedValue(row, 'phone'),
        deputy: mappedValue(row, 'deputy'), general_department: mappedValue(row, 'general-department'), department: mappedValue(row, 'department'),
        gender: mappedValue(row, 'gender'), postal_level: mappedValue(row, 'postal-level'),
        raw_data: Object.fromEntries(state.excelHeaders.map((header, columnIndex) => [String(header || `ستون ${columnIndex + 1}`), String(row[columnIndex] ?? '')]))
      }));
      const matchButton = pane.querySelector('[data-period-excel-match]');
      if (matchButton instanceof HTMLButtonElement && matchButton.disabled) return;
      if (matchButton instanceof HTMLButtonElement) matchButton.disabled = true;
      try {
        const data = await matchPeriodExcelRows(periodCodeForPane(pane), rows, (part, total) => {
          if (status) status.textContent = total > 1 ? `در حال تطبیق بخش ${part} از ${total}…` : 'در حال تطبیق فایل…';
        });
        state.matchedMode = true;
        state.selected = new Set((data.rows || []).filter((row) => !row?.invited).map((row) => String(row.candidate_id || '')));
        state.unmatchedRows = Array.isArray(data.unmatched_rows) ? data.unmatched_rows : [];
        state.unmatchedSelected.clear();
        renderPeriodCandidates(pane, { rows: data.rows || [], total: data.matched || 0, page: 1, pages: 1 });
        renderPeriodUnmatchedRows(pane);
        const conflictText = Number(data.conflicts || 0) > 0 ? ` از این تعداد، ${data.conflicts} ردیف تعارض شناسه داشت.` : '';
        if (status) status.textContent = `${data.matched || 0} کاربر تطبیق و انتخاب شد؛ ${data.unmatched || 0} ردیف بدون تطبیق بود.${conflictText}`;
      } catch (error) {
        if (status) status.textContent = error?.message || 'تطبیق فایل ناموفق بود.';
      } finally {
        if (matchButton instanceof HTMLButtonElement) matchButton.disabled = false;
      }
    });
  }

  async function fetchTaskList() {
    const data = await postTaskAction('list');
    return Array.isArray(data.tasks) ? data.tasks : [];
  }

  async function refreshTaskSubtabs(layout) {
    if (!(layout instanceof HTMLElement)) return;
    const navHost = layout.querySelector('[data-egm-task-subtab-nav]');
    const paneHost = layout.querySelector('[data-egm-task-subtab-panes]');
    if (!(navHost instanceof HTMLElement) || !(paneHost instanceof HTMLElement)) {
      return;
    }
    try {
      const tasks = await fetchTaskList();
      window.EGM_TASKS = tasks;
      renderTaskSubtabs(layout, tasks);
    } catch (error) {
      console.error('Failed to refresh EGM period tabs.', error);
      const cachedTasks = Array.isArray(window.EGM_TASKS) ? window.EGM_TASKS : [];
      if (cachedTasks.length) {
        renderTaskSubtabs(layout, cachedTasks);
      }
    }
  }

  function setupTaskPaneInteractions(layout) {
    if (!(layout instanceof HTMLElement)) return;
    if (layout.dataset.egmTaskPaneHandlersReady === '1') return;
    layout.dataset.egmTaskPaneHandlersReady = '1';

    const handleTaskFieldUpdate = (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      const field = target.closest('[data-task-field]');
      if (!(field instanceof Element)) return;
      const pane = field.closest('.sub-pane[data-task-pane="1"]');
      if (!(pane instanceof HTMLElement)) return;
      const fieldName = field.getAttribute('data-task-field') || '';
      if (fieldName === 'active' || fieldName === 'duration' || fieldName === 'quitRequired' || fieldName === 'quitTimelineRequired') {
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
        fieldName === 'endTime' ||
        fieldName === 'enterDeadlineDate' ||
        fieldName === 'enterDeadlineTime' ||
        fieldName === 'quitOpeningDate' ||
        fieldName === 'quitOpeningTime' ||
        fieldName === 'minimumStayMinutes'
      ) {
        updateTaskPaneStatus(pane);
        setTaskSaveStatus(pane, '');
        return;
      }
      if (fieldName === 'hasGoldenTime') {
        syncTaskScoreGoldenTimeState(pane);
        setTaskScoreSaveStatus(pane, '');
        return;
      }
      if (fieldName === 'score' || fieldName === 'afterEndtimeScore') {
        setTaskScoreSaveStatus(pane, '');
        return;
      }
      if (fieldName === 'anotherChanceIfZero') {
        setTaskCrisisSaveStatus(pane, '');
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
      const hasCtrl = event.ctrlKey || event.metaKey;
      const isBoldKey = isShortcutLetterKey(event, 'B');
      const isListKey = isShortcutLetterKey(event, 'L');
      if (hasCtrl && !event.altKey && isBoldKey) {
        event.preventDefault();
        event.stopPropagation();
        applyBoldShortcutToTextarea(target);
        return;
      }
      if (hasCtrl && !event.altKey && isListKey) {
        event.preventDefault();
        event.stopPropagation();
        applyListShortcutToTextarea(target);
        return;
      }
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
        if (sectionKey === 'invite') {
          void loadPeriodFilterOptions(pane).then(() => loadPeriodCandidates(pane, 1));
        }
        if (sectionKey === 'invitees') {
          void loadPeriodInvitees(pane, 1);
        }
        if (sectionKey === 'invite-card') {
          void Promise.all([loadPeriodInviteCardStatus(pane), loadPeriodInviteCardBackground(pane)]);
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

      const resetPeriodAttendanceButton = target.closest('[data-action="reset-period-attendance"]');
      if (resetPeriodAttendanceButton instanceof HTMLButtonElement) {
        const pane = resetPeriodAttendanceButton.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        const periodCode = periodCodeForPane(pane);
        const periodTitle = String(pane.querySelector('[data-task-field="taskTitle"]')?.value || periodCode).trim();
        if (!periodCode || !window.confirm(`تمام تاریخ‌ها و ساعت‌های ورود و خروج، وضعیت حضور و گزارش‌های کنترل مهمان بازه «${periodTitle}» حذف می‌شود. کاربران و دعوت‌ها حذف نمی‌شوند. ادامه می‌دهید؟`)) return;
        resetPeriodAttendanceButton.disabled = true;
        setTaskSaveStatus(pane, 'در حال بازنشانی سوابق حضور بازه...');
        try {
          const data = await resetGuestControlRecords(periodCode);
          setTaskSaveStatus(pane, data?.message || 'سوابق حضور بازه بازنشانی شد.');
        } catch (error) {
          setTaskSaveStatus(pane, error?.message || 'بازنشانی سوابق حضور بازه ناموفق بود.', true);
        } finally {
          resetPeriodAttendanceButton.disabled = false;
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
            setDescribePhotoUploadStatus(pane, 'عکس انتخاب شد. برای ذخیره روی افزودن عکس کلیک کنید.');
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
              window.EGM_TASKS = returnedTasks;
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
              window.EGM_TASKS = returnedTasks;
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
              window.EGM_TASKS = returnedTasks;
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
              window.EGM_TASKS = returnedTasks;
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
              window.EGM_TASKS = returnedTasks;
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
              window.EGM_TASKS = returnedTasks;
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
        setTaskInfoContentSaveStatus(pane, 'در حال ذخیره...');
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
              window.EGM_TASKS = returnedTasks;
            } catch {}
          }
          const activePane = findPaneByKey(layout, keepPane);
          if (activePane instanceof HTMLElement) {
            activateTaskTopPane(activePane, 'information');
            setTaskInfoContentSaveStatus(activePane, data.message || 'اطلاعات بازه ذخیره شد.');
          }
        } catch (error) {
          setTaskInfoContentSaveStatus(pane, error?.message || 'ذخیره اطلاعات بازه ناموفق بود.', true);
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
        setTaskSaveStatus(pane, 'در حال ذخیره...');
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
              window.EGM_TASKS = returnedTasks;
            } catch {}
          }
          const activePane = findPaneByKey(layout, keepPane);
          if (activePane instanceof HTMLElement) {
            setTaskSaveStatus(activePane, data.message || 'تنظیمات بازه ذخیره شد.');
          }
        } catch (error) {
          setTaskSaveStatus(pane, error?.message || 'ذخیره تنظیمات بازه ناموفق بود.', true);
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
              window.EGM_TASKS = returnedTasks;
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

      const crisisSaveButton = target.closest('[data-action="save-conditional-quiz-crisis-control"]');
      if (crisisSaveButton instanceof HTMLButtonElement) {
        const pane = crisisSaveButton.closest('.sub-pane[data-task-pane="1"]');
        if (!(pane instanceof HTMLElement)) return;
        const taskId = String(pane.dataset.taskId || '').trim();
        if (!taskId) return;

        const crisisSettings = collectTaskCrisisControlFromPane(pane);
        if (!crisisSettings) return;

        crisisSaveButton.disabled = true;
        setTaskCrisisSaveStatus(pane, 'Saving...');
        try {
          const data = await postTaskAction('save_conditional_quiz_crisis_control', {
            id: taskId,
            ...crisisSettings
          });
          const returnedTasks = Array.isArray(data.tasks) ? data.tasks : [];
          const keepPane = pane.dataset.pane || '';
          if (returnedTasks.length) {
            renderTaskSubtabs(layout, returnedTasks, keepPane);
            try {
              window.EGM_TASKS = returnedTasks;
            } catch {}
          }
          const activePane = findPaneByKey(layout, keepPane);
          if (activePane instanceof HTMLElement) {
            activateTaskTopPane(activePane, 'crisis-control');
            setTaskCrisisSaveStatus(activePane, data.message || 'Crisis Control settings saved.');
          }
        } catch (error) {
          setTaskCrisisSaveStatus(pane, error?.message || 'Failed to save Crisis Control settings.', true);
        } finally {
          const refreshedPane = pane.dataset.pane
            ? findPaneByKey(layout, pane.dataset.pane)
            : null;
          const refreshedButton = refreshedPane instanceof HTMLElement
            ? refreshedPane.querySelector('[data-action="save-conditional-quiz-crisis-control"]')
            : null;
          if (refreshedButton instanceof HTMLButtonElement) {
            refreshedButton.disabled = false;
          } else {
            crisisSaveButton.disabled = false;
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
      setTaskScoreSaveStatus(pane, 'در حال ذخیره...');
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
            window.EGM_TASKS = returnedTasks;
          } catch {}
        }
        const activePane = findPaneByKey(layout, keepPane);
        if (activePane instanceof HTMLElement) {
          setTaskScoreSaveStatus(activePane, data.message || 'تنظیمات امتیازدهی ذخیره شد.');
        }
      } catch (error) {
        setTaskScoreSaveStatus(pane, error?.message || 'ذخیره تنظیمات امتیازدهی ناموفق بود.', true);
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

  const egmLogsState = {
    loading: false,
    debounceTimer: 0,
    abortController: null
  };

  function formatLogTimestamp(value) {
    const raw = String(value || '').trim();
    if (!raw) return '-';
    const date = new Date(raw);
    if (Number.isNaN(date.getTime())) return raw;
    return date.toLocaleString('en-GB', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit'
    });
  }

  function setEventGuestManagerLogsStatus(pane, message, isError = false) {
    const statusEl = pane?.querySelector?.('#egm-logs-status');
    if (!(statusEl instanceof HTMLElement)) return;
    statusEl.textContent = String(message || '');
    statusEl.classList.toggle('error', Boolean(isError));
  }

  function renderEventGuestManagerLogRows(pane, items) {
    const body = pane?.querySelector?.('#egm-logs-body');
    if (!(body instanceof HTMLElement)) return;
    const logs = Array.isArray(items) ? items : [];
    if (!logs.length) {
      body.innerHTML = '<tr><td colspan="8" class="muted">No logs found.</td></tr>';
      return;
    }
    body.innerHTML = logs.map((item) => {
      const userLabel = String(item?.username || item?.user_id || '').trim() || '-';
      const message = String(item?.message || '').trim() || '-';
      const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
      const detailKeys = [
        'requestAction',
        'answerText',
        'seconds_waited',
        'quiz_duration_seconds',
        'questionCode',
        'questionIndex',
        'taskTitle',
        'slide',
        'durationMs',
        'reason'
      ];
      const details = detailKeys
        .filter((key) => metadata[key] !== undefined && metadata[key] !== null && String(metadata[key]).trim() !== '')
        .map((key) => `${key}: ${String(metadata[key])}`)
        .join(' | ');
      const entity = [item?.entity_type, item?.entity_id]
        .map((value) => String(value || '').trim())
        .filter(Boolean)
        .join(': ');
      const action = entity
        ? `${String(item?.action || '').trim()} (${entity})`
        : String(item?.action || '').trim();
      const level = String(item?.level || 'info').toLowerCase();
      return `
        <tr>
          <td>${escapeHtml(formatLogTimestamp(item?.timestamp))}</td>
          <td><span class="egm-log-level egm-log-level--${escapeHtml(level)}">${escapeHtml(item?.level || 'info')}</span></td>
          <td>${escapeHtml(userLabel)}</td>
          <td><code>${escapeHtml(action || '-')}</code></td>
          <td>${escapeHtml(item?.status || '-')}</td>
          <td>${escapeHtml(message)}</td>
          <td>${escapeHtml(details || '-')}</td>
          <td><code>${escapeHtml(item?.ip_address || '-')}</code></td>
        </tr>
      `;
    }).join('');
  }

  function syncEventGuestManagerLogDays(pane, days, selectedDay) {
    const select = pane?.querySelector?.('#egm-logs-day');
    if (!(select instanceof HTMLSelectElement)) return;
    const current = String(selectedDay || select.value || '').trim();
    const options = Array.isArray(days) ? days : [];
    select.innerHTML = options.length
      ? options.map((day) => `<option value="${escapeHtml(day)}"${day === current ? ' selected' : ''}>${escapeHtml(day)}</option>`).join('')
      : `<option value="${escapeHtml(current || '')}">${escapeHtml(current || 'No log files')}</option>`;
  }

  async function fetchEventGuestManagerLogs(pane) {
    if (!(pane instanceof HTMLElement)) return;
    const searchInput = pane.querySelector('#egm-logs-search');
    const daySelect = pane.querySelector('#egm-logs-day');
    const query = searchInput instanceof HTMLInputElement ? searchInput.value.trim() : '';
    const day = daySelect instanceof HTMLSelectElement ? daySelect.value.trim() : '';
    const params = new URLSearchParams();
    if (query) params.set('q', query);
    if (day) params.set('day', day);
    params.set('limit', '150');

    if (egmLogsState.abortController) {
      egmLogsState.abortController.abort();
    }
    const controller = new AbortController();
    egmLogsState.abortController = controller;
    egmLogsState.loading = true;
    setEventGuestManagerLogsStatus(pane, query ? 'Searching logs...' : 'Loading logs...');

    try {
      const response = await fetch(`${LOGS_ENDPOINT}?${params.toString()}`, {
        headers: { Accept: 'application/json' },
        signal: controller.signal
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || payload?.status !== 'ok') {
        throw new Error(payload?.message || 'Failed to load logs.');
      }
      syncEventGuestManagerLogDays(pane, payload.days, payload.day);
      renderEventGuestManagerLogRows(pane, payload.items);
      const count = Array.isArray(payload.items) ? payload.items.length : 0;
      const suffix = query ? ` for "${query}"` : '';
      setEventGuestManagerLogsStatus(pane, `Showing ${count} log entr${count === 1 ? 'y' : 'ies'}${suffix}.`);
      pane.dataset.egmLogsLoaded = '1';
    } catch (error) {
      if (error?.name === 'AbortError') return;
      renderEventGuestManagerLogRows(pane, []);
      setEventGuestManagerLogsStatus(pane, error?.message || 'Failed to load logs.', true);
    } finally {
      if (egmLogsState.abortController === controller) {
        egmLogsState.abortController = null;
      }
      egmLogsState.loading = false;
    }
  }

  function loadEventGuestManagerLogs(layout, force = false) {
    const pane = layout?.querySelector?.('[data-egm-logs-pane="1"]');
    if (!(pane instanceof HTMLElement)) return;
    if (!force && pane.dataset.egmLogsLoaded === '1') return;
    fetchEventGuestManagerLogs(pane);
  }

  function setupEventGuestManagerLogsPane(layout) {
    const pane = layout?.querySelector?.('[data-egm-logs-pane="1"]');
    if (!(pane instanceof HTMLElement) || pane.dataset.egmLogsReady === '1') return;
    pane.dataset.egmLogsReady = '1';
    const searchInput = pane.querySelector('#egm-logs-search');
    const daySelect = pane.querySelector('#egm-logs-day');
    const refreshButton = pane.querySelector('#egm-logs-refresh');

    if (searchInput instanceof HTMLInputElement) {
      searchInput.addEventListener('input', () => {
        window.clearTimeout(egmLogsState.debounceTimer);
        egmLogsState.debounceTimer = window.setTimeout(() => fetchEventGuestManagerLogs(pane), 220);
      });
    }
    if (daySelect instanceof HTMLSelectElement) {
      daySelect.addEventListener('change', () => fetchEventGuestManagerLogs(pane));
    }
    if (refreshButton instanceof HTMLButtonElement) {
      refreshButton.addEventListener('click', () => fetchEventGuestManagerLogs(pane));
    }
  }

  function initWheelSubLayouts() {
    const previousTasksChangedHandler = window[EGM_TASKS_CHANGED_HANDLER_KEY];
    if (typeof previousTasksChangedHandler === 'function') {
      window.removeEventListener('egmTasksChanged', previousTasksChangedHandler);
    }
    delete window[EGM_TASKS_CHANGED_HANDLER_KEY];
    const layouts = document.querySelectorAll('[data-egm-sub-layout]');
    layouts.forEach((layout) => {
      if (!(layout instanceof HTMLElement)) return;
      if (layout.dataset.egmSubLayoutReady === '1') return;
      layout.dataset.egmSubLayoutReady = '1';

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

      setupEventGuestManagerLogsPane(layout);
      setupTaskPaneInteractions(layout);

      // Keep the built-in controls usable while the period request runs.
      ensureAnyActivePane(layout);

      const tasksChangedHandler = (event) => {
        const tasks = event?.detail?.tasks;
        if (Array.isArray(tasks)) {
          renderTaskSubtabs(layout, tasks);
          return;
        }
        refreshTaskSubtabs(layout);
      };
      window[EGM_TASKS_CHANGED_HANDLER_KEY] = tasksChangedHandler;
      window.addEventListener('egmTasksChanged', tasksChangedHandler);

      refreshTaskSubtabs(layout);
      if (layout.querySelector('[data-egm-logs-pane="1"].active')) {
        loadEventGuestManagerLogs(layout);
      }
    });
  }

  window.__egmPanelInitializerSource = TASKS_ENDPOINT;
  window.initEventGuestManagerPanel = initWheelSubLayouts;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWheelSubLayouts);
  } else {
    initWheelSubLayouts();
  }
})();
