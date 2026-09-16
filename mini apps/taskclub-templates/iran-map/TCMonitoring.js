(() => {
  const PANE_SELECTOR = '.sub-pane[data-pane="tc-monitoring"]';
  const API_BASE_URL = 'mini%20apps/Task%20Club/TCMonitoring.php';
  const API_URL = `${API_BASE_URL}?action=stats`;
  const TC_MONITORING_CLICK_HANDLER_KEY = '__tcMonitoringClickHandler';
  const TC_MONITORING_TASKS_CHANGED_HANDLER_KEY = '__tcMonitoringTasksChangedHandler';

  const previousClickHandler = window[TC_MONITORING_CLICK_HANDLER_KEY];
  if (typeof previousClickHandler === 'function') {
    document.removeEventListener('click', previousClickHandler);
  }
  delete window[TC_MONITORING_CLICK_HANDLER_KEY];
  const previousTasksChangedHandler = window[TC_MONITORING_TASKS_CHANGED_HANDLER_KEY];
  if (typeof previousTasksChangedHandler === 'function') {
    window.removeEventListener('tcTasksChanged', previousTasksChangedHandler);
  }
  delete window[TC_MONITORING_TASKS_CHANGED_HANDLER_KEY];

  let hasLoadedOnce = false;
  let isLoading = false;
  let currentMonitoringPayload = {};
  let selectedWorkIdGroups = [];
  let draftWorkIdGroups = [];
  let workIdGroupFilterInitialized = false;
  let pendingMonitoringReload = false;
  let monitoringProgressTimer = null;
  let monitoringProgressValue = 0;
  let isExporting = false;
  let surveyTasksLoaded = false;
  let surveyLoading = false;
  let currentSurveyPayload = null;
  let xlsxLoaderPromise = null;

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function formatNumber(value, maximumFractionDigits = 0) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) {
      return '0';
    }
    return new Intl.NumberFormat('fa-IR', {
      maximumFractionDigits,
      minimumFractionDigits: maximumFractionDigits > 0 ? 2 : 0
    }).format(numeric);
  }

  function formatPercent(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) {
      return '۰٪';
    }
    return `${new Intl.NumberFormat('fa-IR', {
      maximumFractionDigits: 1,
      minimumFractionDigits: 1
    }).format(numeric)}٪`;
  }

  function formatShamsiDateTime(value) {
    const date = new Date(String(value || ''));
    if (Number.isNaN(date.getTime())) return 'نامشخص';
    return new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
      day: 'numeric',
      month: 'long',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
      timeZone: 'Asia/Tehran'
    }).format(date);
  }

  function formatDuration(minutes) {
    const totalMinutes = Math.max(0, Number.parseInt(minutes ?? 0, 10) || 0);
    if (totalMinutes >= 1440) {
      const days = Math.floor(totalMinutes / 1440);
      const hours = Math.floor((totalMinutes % 1440) / 60);
      return `${formatNumber(days)} روز${hours > 0 ? ` و ${formatNumber(hours)} ساعت` : ''}`;
    }
    const hours = Math.floor(totalMinutes / 60);
    const remainingMinutes = totalMinutes % 60;
    return `${formatNumber(hours)} ساعت${remainingMinutes > 0 ? ` و ${formatNumber(remainingMinutes)} دقیقه` : ''}`;
  }

  function normalizeWorkIdGroupKey(group) {
    return String(group ?? '').trim()
      .replace(/[۰٠]/g, '0')
      .replace(/[۱١]/g, '1')
      .replace(/[۲٢]/g, '2')
      .replace(/[۳٣]/g, '3')
      .replace(/[۴٤]/g, '4')
      .replace(/[۵٥]/g, '5')
      .replace(/[۶٦]/g, '6')
      .replace(/[۷٧]/g, '7')
      .replace(/[۸٨]/g, '8')
      .replace(/[۹٩]/g, '9') || 'نامشخص';
  }

  function getPane() {
    const pane = document.querySelector(PANE_SELECTOR);
    return pane instanceof HTMLElement ? pane : null;
  }

  function getElement(id) {
    const pane = getPane();
    if (!pane) return null;
    const el = pane.querySelector(`#${id}`);
    return el instanceof HTMLElement ? el : null;
  }

  function monitoringApiUrl() {
    const groups = Array.isArray(selectedWorkIdGroups) ? selectedWorkIdGroups : [];
    const allGroups = Array.isArray(currentMonitoringPayload?.workIdGroups) ? currentMonitoringPayload.workIdGroups : [];
    const params = new URLSearchParams();
    params.set('action', 'stats');
    if (!workIdGroupFilterInitialized) {
      return `${API_BASE_URL}?${params.toString()}`;
    }
    if (!groups.length) {
      params.set('groups', '__none');
    } else if (!(allGroups.length > 0 && groups.length === allGroups.length)) {
      groups.forEach((group) => {
        params.append('groups[]', group);
      });
    }
    return `${API_BASE_URL}?${params.toString()}`;
  }

  function exportApiUrl(groups = []) {
    const params = new URLSearchParams();
    params.set('action', 'export');
    if (!groups.length) {
      params.set('groups', '__none');
    } else {
      groups.forEach((group) => params.append('groups[]', group));
    }
    return `${API_BASE_URL}?${params.toString()}`;
  }

  function setExportProgress(value, text = '') {
    const progress = getElement('tc-monitoring-export-progress');
    const fill = getElement('tc-monitoring-export-progress-fill');
    const percent = getElement('tc-monitoring-export-progress-percent');
    const label = getElement('tc-monitoring-export-progress-text');
    const normalized = Math.max(0, Math.min(100, Number(value) || 0));
    if (progress) {
      progress.hidden = false;
      progress.classList.toggle('is-pending', normalized > 0 && normalized < 100);
    }
    if (fill) fill.style.width = `${normalized}%`;
    if (percent) percent.textContent = formatPercent(normalized);
    if (label && text) label.textContent = text;
  }

  function setExportBusy(busy) {
    isExporting = Boolean(busy);
    ['tc-monitoring-export-submit', 'tc-monitoring-export-cancel', 'tc-monitoring-export-close', 'tc-monitoring-export-select-all', 'tc-monitoring-export-select-none']
      .forEach((id) => {
        const button = getElement(id);
        if (button instanceof HTMLButtonElement) button.disabled = isExporting;
      });
    const groups = getElement('tc-monitoring-export-groups');
    groups?.querySelectorAll('input[type="checkbox"]').forEach((input) => {
      if (input instanceof HTMLInputElement) input.disabled = isExporting;
    });
  }

  function closeExportModal() {
    if (isExporting) return;
    const modal = getElement('tc-monitoring-export-modal');
    if (modal) modal.hidden = true;
  }

  function openExportModal() {
    const modal = getElement('tc-monitoring-export-modal');
    const host = getElement('tc-monitoring-export-groups');
    const status = getElement('tc-monitoring-export-status');
    const progress = getElement('tc-monitoring-export-progress');
    if (!modal || !host) return;
    const groups = Array.isArray(currentMonitoringPayload?.workIdGroups)
      ? currentMonitoringPayload.workIdGroups.map((group) => String(group || '').trim()).filter(Boolean)
      : [];
    const initialGroups = workIdGroupFilterInitialized ? selectedWorkIdGroups : groups;
    const selectedSet = new Set(initialGroups.map(normalizeWorkIdGroupKey));
    host.innerHTML = groups.length
      ? groups.map((group) => `
        <label class="tc-monitoring-group-filter-item">
          <input type="checkbox" value="${escapeHtml(group)}" ${selectedSet.has(normalizeWorkIdGroupKey(group)) ? 'checked' : ''}>
          <span>${escapeHtml(group)}</span>
        </label>
      `).join('')
      : '<p class="muted small">گروهی برای خروجی پیدا نشد.</p>';
    if (status) status.textContent = '';
    if (status) status.style.color = '';
    if (progress) progress.hidden = true;
    setExportProgress(0, 'در حال ساخت فایل اکسل...');
    if (progress) progress.hidden = true;
    modal.hidden = false;
    const submit = getElement('tc-monitoring-export-submit');
    if (submit instanceof HTMLButtonElement) submit.focus();
  }

  function selectedExportGroups() {
    const host = getElement('tc-monitoring-export-groups');
    if (!host) return [];
    return Array.from(host.querySelectorAll('input[type="checkbox"]:checked'))
      .map((input) => input instanceof HTMLInputElement ? String(input.value || '').trim() : '')
      .filter(Boolean);
  }

  function exportFilename(response) {
    const disposition = String(response.headers.get('Content-Disposition') || '');
    const encodedMatch = disposition.match(/filename\*=UTF-8''([^;]+)/i);
    if (encodedMatch) {
      try {
        return decodeURIComponent(encodedMatch[1]);
      } catch (_error) {
        // Fall through to the safe Persian filename.
      }
    }
    return 'گزارش-مانیتورینگ.xlsx';
  }

  async function downloadMonitoringExport() {
    if (isExporting) return;
    const status = getElement('tc-monitoring-export-status');
    const groups = selectedExportGroups();
    setExportBusy(true);
    if (status) {
      status.textContent = '';
      status.style.color = '';
    }
    setExportProgress(8, 'در حال محاسبه داده‌های گزارش...');
    try {
      const response = await fetch(exportApiUrl(groups), {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store'
      });
      if (!response.ok) {
        throw new Error((await response.text()).trim() || 'ساخت فایل اکسل ناموفق بود.');
      }
      const contentType = String(response.headers.get('Content-Type') || '').toLowerCase();
      if (!contentType.includes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')) {
        throw new Error((await response.text()).trim() || 'پاسخ خروجی اکسل معتبر نبود. دوباره وارد پنل شوید و تلاش کنید.');
      }
      const total = Math.max(0, Number.parseInt(response.headers.get('Content-Length') || '0', 10) || 0);
      let blob;
      if (response.body && total > 0) {
        const reader = response.body.getReader();
        const chunks = [];
        let received = 0;
        while (true) {
          const { done, value } = await reader.read();
          if (done) break;
          chunks.push(value);
          received += value.byteLength;
          setExportProgress(Math.min(96, 12 + ((received / total) * 84)), 'در حال دریافت فایل اکسل...');
        }
        blob = new Blob(chunks, { type: contentType });
      } else {
        setExportProgress(65, 'در حال دریافت فایل اکسل...');
        blob = await response.blob();
      }
      setExportProgress(100, 'فایل آماده شد.');
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = exportFilename(response);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 2000);
      if (status) status.textContent = 'فایل اکسل با موفقیت ساخته و دانلود شد.';
    } catch (error) {
      if (status) {
        status.textContent = error instanceof Error ? error.message : 'ساخت فایل اکسل ناموفق بود.';
        status.style.color = '#d1434a';
      }
      setExportProgress(0, 'ساخت فایل ناموفق بود.');
    } finally {
      setExportBusy(false);
    }
  }

  function setStatus(message, isError = false) {
    const statusEl = getElement('tc-monitoring-status');
    if (!statusEl) return;
    statusEl.textContent = String(message || '');
    statusEl.style.color = isError ? '#d1434a' : '';
  }

  function setFilterStatus(message = '') {
    const statusEl = getElement('tc-monitoring-workid-filter-status');
    if (!statusEl) return;
    statusEl.textContent = String(message || '');
  }

  function setMonitoringProgress(value, text = '') {
    const loadingEl = getElement('tc-monitoring-loading');
    const fillEl = getElement('tc-monitoring-loading-fill');
    const percentEl = getElement('tc-monitoring-loading-percent');
    const textEl = getElement('tc-monitoring-loading-text');
    const normalized = Math.max(0, Math.min(100, Number(value) || 0));
    monitoringProgressValue = normalized;
    if (fillEl) fillEl.style.width = `${normalized}%`;
    if (percentEl) percentEl.textContent = formatPercent(normalized);
    if (textEl && text) textEl.textContent = text;
    if (loadingEl) {
      loadingEl.classList.toggle('is-indeterminate', normalized < 95);
    }
  }

  function startMonitoringLoading(text = 'در حال محاسبه داده‌ها') {
    const loadingEl = getElement('tc-monitoring-loading');
    const pane = getPane();
    if (loadingEl) {
      loadingEl.classList.remove('hidden');
    }
    if (pane) {
      pane.classList.add('tc-monitoring-is-loading');
    }
    setMonitoringProgress(8, text);
    if (monitoringProgressTimer) {
      window.clearInterval(monitoringProgressTimer);
    }
    monitoringProgressTimer = window.setInterval(() => {
      const cap = monitoringProgressValue < 70 ? 78 : 92;
      const increment = monitoringProgressValue < 70 ? 6 : 2;
      setMonitoringProgress(Math.min(cap, monitoringProgressValue + increment), text);
    }, 420);
  }

  function stopMonitoringLoading(success = true) {
    const loadingEl = getElement('tc-monitoring-loading');
    const pane = getPane();
    if (monitoringProgressTimer) {
      window.clearInterval(monitoringProgressTimer);
      monitoringProgressTimer = null;
    }
    if (success) {
      setMonitoringProgress(100, 'داده‌ها آماده شد');
      window.setTimeout(() => {
        if (loadingEl) loadingEl.classList.add('hidden');
        if (pane) pane.classList.remove('tc-monitoring-is-loading');
        setMonitoringProgress(0);
      }, 450);
      return;
    }
    if (loadingEl) loadingEl.classList.add('hidden');
    if (pane) pane.classList.remove('tc-monitoring-is-loading');
    setMonitoringProgress(0);
  }

  function setDiagnostics(details) {
    const diagnosticsEl = getElement('tc-monitoring-diagnostics');
    if (!diagnosticsEl) return;
    if (!details) {
      diagnosticsEl.textContent = '';
      diagnosticsEl.style.display = 'none';
      return;
    }

    const lines = [];
    const source = typeof details === 'object' ? details : { message: String(details) };
    if (source.type) lines.push(`type: ${source.type}`);
    if (source.code) lines.push(`code: ${source.code}`);
    if (source.status) lines.push(`http_status: ${source.status}`);
    if (source.message) lines.push(`message: ${source.message}`);
    if (source.file) lines.push(`file: ${source.file}`);
    if (source.line) lines.push(`line: ${source.line}`);
    if (source.fallbackMessage) lines.push(`fallback_message: ${source.fallbackMessage}`);
    if (source.fallbackFile) lines.push(`fallback_file: ${source.fallbackFile}`);
    if (source.fallbackLine) lines.push(`fallback_line: ${source.fallbackLine}`);
    if (source.preview) lines.push(`preview:\n${source.preview}`);

    diagnosticsEl.textContent = lines.join('\n') || String(details);
    diagnosticsEl.style.display = '';
  }

  function setUpdatedAt(value) {
    const updatedEl = getElement('tc-monitoring-updated');
    if (!updatedEl) return;
    if (!value) {
      updatedEl.textContent = '';
      return;
    }
    const date = new Date(String(value));
    if (Number.isNaN(date.getTime())) {
      updatedEl.textContent = '';
      return;
    }
    updatedEl.textContent = `آخرین بروزرسانی: ${date.toLocaleString('fa-IR')}`;
  }

  function normalizeSurveyTaskType(value) {
    const token = String(value ?? '').trim().toLowerCase();
    return ['shared_answers_quiz', 'shared-answers-quiz', 'shared answers quiz', 'shared_quiz', 'shared-quiz', 'shared quiz', 'survey_score_response', 'survey-score-response', 'survey score response', 'survey score'].includes(token)
      ? 'shared_answers_quiz'
      : token;
  }

  function setMonitoringView(view) {
    const pane = getPane();
    if (!pane) return;
    pane.querySelectorAll('[data-tc-monitoring-view]').forEach((button) => {
      const active = button.getAttribute('data-tc-monitoring-view') === view;
      button.classList.toggle('active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    pane.querySelectorAll('[data-tc-monitoring-view-panel]').forEach((panel) => {
      const active = panel.getAttribute('data-tc-monitoring-view-panel') === view;
      panel.classList.toggle('active', active);
      panel.hidden = !active;
    });
    if (view === 'shared-survey') void loadSurveyTasks();
    else void loadMonitoring(false);
  }

  function setSurveyStatus(message = '', isError = false) {
    const status = getElement('tc-survey-monitoring-status');
    if (!status) return;
    status.textContent = message;
    status.style.color = isError ? '#d1434a' : '';
  }

  function setSurveyLoading(percent, text = 'در حال محاسبه پاسخ‌ها') {
    const loading = getElement('tc-survey-monitoring-loading');
    const fill = getElement('tc-survey-monitoring-loading-fill');
    const percentEl = getElement('tc-survey-monitoring-loading-percent');
    const textEl = getElement('tc-survey-monitoring-loading-text');
    const value = Math.max(0, Math.min(100, Number(percent) || 0));
    loading?.classList.toggle('hidden', value <= 0 || value >= 100);
    if (fill) fill.style.width = `${value}%`;
    if (percentEl) percentEl.textContent = formatPercent(value);
    if (textEl) textEl.textContent = text;
  }

  async function fetchSurveyApi(action, taskId = '') {
    const params = new URLSearchParams({ action });
    if (taskId) params.set('task_id', taskId);
    const response = await fetch(`${API_BASE_URL}?${params.toString()}`, { credentials: 'same-origin', cache: 'no-store' });
    const payload = await response.json().catch(() => null);
    if (!response.ok || payload?.status !== 'ok') throw new Error(payload?.message || `خطا در دریافت اطلاعات (${response.status})`);
    return payload.data;
  }

  async function loadSurveyTasks(force = false) {
    if (surveyLoading || (surveyTasksLoaded && !force)) return;
    surveyLoading = true;
    setSurveyStatus('در حال دریافت ماموریت‌های نظرسنجی...');
    setSurveyLoading(15, 'در حال دریافت ماموریت‌ها');
    try {
      const tasks = await fetchSurveyApi('survey_tasks');
      const select = getElement('tc-survey-monitoring-task');
      const rows = (Array.isArray(tasks) ? tasks : []).filter((task) => !task?.taskType || normalizeSurveyTaskType(task.taskType) === 'shared_answers_quiz');
      if (!(select instanceof HTMLSelectElement)) return;
      const previous = String(select.value || '');
      select.innerHTML = rows.length
        ? rows.map((task) => `<option value="${escapeHtml(task.id)}">${escapeHtml(task.title || task.tagCode || task.id)}</option>`).join('')
        : '<option value="">ماموریت Survey Score Response وجود ندارد</option>';
      if (rows.some((task) => String(task.id) === previous)) select.value = previous;
      surveyTasksLoaded = true;
      setSurveyLoading(35, 'در حال خواندن پاسخ‌ها');
      if (select.value) await loadSurveyMonitoring(select.value, true);
      else {
        currentSurveyPayload = null;
        setSurveyStatus('هنوز ماموریت Survey Score Response ساخته نشده است.');
        setSurveyLoading(100);
        renderSurveyData({});
      }
    } catch (error) {
      setSurveyStatus(error?.message || 'دریافت ماموریت‌های نظرسنجی ناموفق بود.', true);
      setSurveyLoading(100);
    } finally {
      surveyLoading = false;
    }
  }

  function surveyKpi(label, value, hint = '') {
    return `<article class="card tc-monitoring-kpi"><div class="tc-monitoring-kpi-label">${escapeHtml(label)}</div><div class="tc-monitoring-kpi-value">${escapeHtml(value)}</div>${hint ? `<small class="muted">${escapeHtml(hint)}</small>` : ''}</article>`;
  }

  function surveyBar(title, count, rate, meta = '') {
    const width = Math.max(0, Math.min(100, Number(rate) || 0));
    return `<div class="tc-monitoring-bar-row"><div class="tc-monitoring-bar-head"><span class="tc-monitoring-bar-title">${escapeHtml(title)}</span><span class="tc-monitoring-bar-meta">${escapeHtml(meta || `${formatNumber(count)} نفر | ${formatPercent(width)}`)}</span></div><div class="tc-monitoring-bar-track"><span class="tc-monitoring-bar-fill" style="width:${width}%"></span></div></div>`;
  }

  function renderSurveyData(payload = {}) {
    currentSurveyPayload = payload && typeof payload === 'object' ? payload : {};
    const summary = currentSurveyPayload.summary || {};
    const task = currentSurveyPayload.task || {};
    const participants = Array.isArray(currentSurveyPayload.participants) ? currentSurveyPayload.participants : [];
    const kpis = getElement('tc-survey-monitoring-kpis');
    if (kpis) kpis.innerHTML = [
      surveyKpi('کل دعوت‌شدگان', formatNumber(summary.totalInvitees || 0)),
      surveyKpi('مشارکت‌کنندگان', formatNumber(summary.participants || 0), formatPercent(summary.completionRate || 0)),
      surveyKpi('شرکت‌نکرده', formatNumber(summary.notParticipated || 0)),
      surveyKpi('میانگین امتیاز داخلی', formatNumber(summary.averageInnerScore || 0, 2)),
      surveyKpi('میانگین سطح پاسخ', formatNumber(summary.averageResponseLevelNumber || 0, 2)),
      surveyKpi('سوال / سطح', `${formatNumber(summary.questionCount || 0)} / ${formatNumber(summary.responseLevelCount || 0)}`)
    ].join('');

    const completion = getElement('tc-survey-monitoring-completion');
    if (completion) completion.innerHTML = surveyBar('نرخ تکمیل', summary.participants || 0, summary.completionRate || 0);
    const dates = getElement('tc-survey-monitoring-dates');
    if (dates) dates.innerHTML = [
      surveyKpi('شروع ماموریت', [task.startDate, task.startTime].filter(Boolean).join(' ') || 'تنظیم نشده'),
      surveyKpi('پایان ماموریت', [task.endDate, task.endTime].filter(Boolean).join(' ') || 'تنظیم نشده'),
      surveyKpi('اولین تکمیل', summary.firstCompletedAt ? formatShamsiDateTime(summary.firstCompletedAt) : 'بدون داده'),
      surveyKpi('آخرین تکمیل', summary.lastCompletedAt ? formatShamsiDateTime(summary.lastCompletedAt) : 'بدون داده')
    ].join('');

    const levels = getElement('tc-survey-monitoring-levels');
    const levelRows = Array.isArray(currentSurveyPayload.levels) ? currentSurveyPayload.levels : [];
    if (levels) levels.innerHTML = levelRows.length ? levelRows.map((level) => surveyBar(level.name || 'بدون نام', level.count || 0, level.rate || 0)).join('') : '<p class="muted small">سطح پاسخی تنظیم نشده یا پاسخی ثبت نشده است.</p>';

    const questions = getElement('tc-survey-monitoring-questions');
    const questionRows = Array.isArray(currentSurveyPayload.questions) ? currentSurveyPayload.questions : [];
    if (questions) questions.innerHTML = questionRows.length ? questionRows.map((question, index) => `<section class="tc-survey-question"><div class="tc-monitoring-bar-head"><strong>${formatNumber(index + 1)}. ${escapeHtml(question.question || question.code || '')}</strong><span class="tc-monitoring-bar-meta">پاسخ ${formatNumber(question.answeredCount || 0)} نفر | ${formatPercent(question.responseRate || 0)}</span></div><div class="tc-monitoring-bars">${(Array.isArray(question.choices) ? question.choices : []).map((choice) => surveyBar(choice.answer || 'بدون پاسخ', choice.count || 0, choice.rate || 0)).join('')}</div></section>`).join('') : '<p class="muted small">سوالی برای این ماموریت ثبت نشده است.</p>';

    const body = getElement('tc-survey-monitoring-participants');
    if (body) body.innerHTML = participants.length ? participants.map((person) => {
      const answered = Math.max(0, Number(person.answeredQuestions) || 0);
      const total = Math.max(0, Number(person.questionCount) || 0);
      const rate = total > 0 ? answered * 100 / total : 0;
      return `<tr><td>${escapeHtml(person.fullName || person.workId || '')}</td><td>${escapeHtml(person.workId || '')}</td><td>${escapeHtml(person.responseLevelName || 'بدون تطبیق')}</td><td>${formatNumber(person.innerScore || 0)}</td><td><div class="tc-survey-table-progress"><span style="width:${Math.min(100, rate)}%"></span></div><small>${formatNumber(answered)} / ${formatNumber(total)}</small></td><td>${person.completedAt ? escapeHtml(formatShamsiDateTime(person.completedAt)) : 'نامشخص'}</td></tr>`;
    }).join('') : '<tr><td colspan="6" class="muted">هنوز مشارکتی ثبت نشده است.</td></tr>';
    const updated = getElement('tc-survey-monitoring-updated');
    if (updated) updated.textContent = currentSurveyPayload.generatedAt ? `آخرین بروزرسانی: ${formatShamsiDateTime(currentSurveyPayload.generatedAt)}` : '';
  }

  async function loadSurveyMonitoring(taskId, force = false) {
    if (!taskId || (surveyLoading && !force)) return;
    surveyLoading = true;
    setSurveyStatus('در حال استخراج پاسخ‌ها، تاریخ‌ها و پیشرفت...');
    setSurveyLoading(45, 'در حال استخراج پاسخ‌ها');
    try {
      const data = await fetchSurveyApi('survey_stats', taskId);
      setSurveyLoading(85, 'در حال ساخت گزارش');
      renderSurveyData(data || {});
      setSurveyStatus('');
      setSurveyLoading(100);
    } catch (error) {
      setSurveyStatus(error?.message || 'دریافت مانیتورینگ نظرسنجی ناموفق بود.', true);
      setSurveyLoading(100);
    } finally {
      surveyLoading = false;
    }
  }

  function loadXlsxLibrary() {
    if (window.XLSX) return Promise.resolve(window.XLSX);
    if (xlsxLoaderPromise) return xlsxLoaderPromise;
    xlsxLoaderPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'mini%20apps/Task%20Club/vendor/xlsx/xlsx.full.min.js';
      script.addEventListener('load', () => window.XLSX ? resolve(window.XLSX) : reject(new Error('کتابخانه اکسل بارگذاری نشد.')), { once: true });
      script.addEventListener('error', () => reject(new Error('کتابخانه اکسل بارگذاری نشد.')), { once: true });
      document.head.appendChild(script);
    });
    return xlsxLoaderPromise;
  }

  async function exportSurveyMonitoring() {
    if (!currentSurveyPayload?.task) return setSurveyStatus('ابتدا یک ماموریت را انتخاب کنید.', true);
    try {
      const XLSX = await loadXlsxLibrary();
      const rows = (currentSurveyPayload.participants || []).map((person) => ({
        'نام': person.fullName || '', 'شماره پرسنلی': person.workId || '', 'تلفن': person.phoneNumber || '', 'کد ملی': person.nationalId || '',
        'سطح پاسخ': person.responseLevelName || '', 'امتیاز داخلی': person.innerScore || 0,
        'سوالات پاسخ‌داده': person.answeredQuestions || 0, 'تعداد سوال': person.questionCount || 0, 'تاریخ تکمیل': person.completedAt || ''
      }));
      const workbook = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(workbook, XLSX.utils.json_to_sheet(rows), 'Participants');
      XLSX.utils.book_append_sheet(workbook, XLSX.utils.json_to_sheet(currentSurveyPayload.levels || []), 'Response Levels');
      XLSX.utils.book_append_sheet(workbook, XLSX.utils.json_to_sheet((currentSurveyPayload.questions || []).map((q) => ({ code: q.code, question: q.question, answered: q.answeredCount, responseRate: q.responseRate }))), 'Questions');
      XLSX.writeFile(workbook, `${String(currentSurveyPayload.task.title || currentSurveyPayload.task.tagCode || 'survey').replace(/[\\/:*?"<>|]+/g, '-')}-monitoring.xlsx`);
    } catch (error) {
      setSurveyStatus(error?.message || 'ساخت خروجی اکسل ناموفق بود.', true);
    }
  }

  function renderWorkIdGroupFilter(groups = [], selectedGroups = []) {
    const host = getElement('tc-monitoring-workid-filter');
    if (!host) return;
    const allGroups = Array.isArray(groups) ? groups.map((group) => String(group || '').trim()).filter(Boolean) : [];
    const selectedValues = workIdGroupFilterInitialized
      ? draftWorkIdGroups
      : (Array.isArray(selectedGroups)
        ? selectedGroups.map((group) => String(group || '').trim()).filter(Boolean)
        : []);
    const selectedSet = new Set((selectedValues.length ? selectedValues : allGroups).map(normalizeWorkIdGroupKey));
    draftWorkIdGroups = allGroups.filter((group) => selectedSet.has(normalizeWorkIdGroupKey(group)));
    if (!workIdGroupFilterInitialized) {
      selectedWorkIdGroups = draftWorkIdGroups.slice();
    }
    if (!allGroups.length) {
      host.innerHTML = '<p class="muted">گروهی برای شماره پرسنلی پیدا نشد.</p>';
      setFilterStatus('');
      return;
    }
    host.innerHTML = allGroups.map((group) => `
      <label class="tc-monitoring-group-filter-item">
        <input type="checkbox" value="${escapeHtml(group)}" ${selectedSet.has(normalizeWorkIdGroupKey(group)) ? 'checked' : ''}>
        <span>${escapeHtml(group)}</span>
      </label>
    `).join('');
    host.querySelectorAll('input[type="checkbox"]').forEach((input) => {
      input.addEventListener('change', () => {
        draftWorkIdGroups = Array.from(host.querySelectorAll('input[type="checkbox"]:checked'))
          .map((checked) => String(checked.value || '').trim())
          .filter(Boolean);
        updateWorkIdApplyState();
      });
    });
    updateWorkIdApplyState();
  }

  function sameGroupSelection(a = [], b = []) {
    const left = (Array.isArray(a) ? a : []).map(normalizeWorkIdGroupKey).sort();
    const right = (Array.isArray(b) ? b : []).map(normalizeWorkIdGroupKey).sort();
    if (left.length !== right.length) return false;
    return left.every((value, index) => value === right[index]);
  }

  function updateWorkIdApplyState() {
    const applyBtn = getElement('tc-monitoring-workid-apply');
    const changed = !sameGroupSelection(draftWorkIdGroups, selectedWorkIdGroups);
    if (applyBtn instanceof HTMLButtonElement) {
      applyBtn.disabled = isLoading || !changed;
    }
    if (isLoading) {
      setFilterStatus('در حال اعمال و محاسبه داده‌ها...');
    } else if (changed) {
      setFilterStatus('برای اعمال تغییرات روی «اعمال فیلتر» کلیک کنید.');
    } else {
      setFilterStatus('');
    }
  }

  function renderKpis(summary = {}) {
    const host = getElement('tc-monitoring-kpis');
    if (!host) return;

    const levelSubtitle = getElement('tc-monitoring-level-subtitle');
    if (levelSubtitle) {
      levelSubtitle.textContent = `حداکثر کارت جایزه قابل باز شدن: ${formatNumber(summary.maximumPrizeCardsOpenable || 0)}`;
    }

    const reconciliationDifference = Number(summary.prizeValueReconciliationDifference || 0);
    const hasPrizeProblem = summary.prizeValueHasProblem === true || Math.abs(reconciliationDifference) > 0.005;
    const reconciliationText = !hasPrizeProblem
      ? 'بدون مغایرت'
      : reconciliationDifference > 0
        ? `${formatNumber(reconciliationDifference, 2)} تومان بیشتر از بودجه`
        : `${formatNumber(Math.abs(reconciliationDifference), 2)} تومان کمتر از بودجه`;
    const items = [
      { label: 'تعداد دعوت‌شدگان', value: formatNumber(summary.totalUsers || 0) },
      { label: 'حداقل یک‌بار ورود', value: formatNumber(summary.loggedInUsers || 0) },
      { label: 'کاربران با ماموریت تکمیل‌شده', value: formatNumber(summary.usersWithCompletion || 0) },
      { label: 'واجد حداقل یک سطح جایزه', value: formatNumber(summary.usersEligibleAnyLevel || 0) },
      { label: 'حداکثر کارت جایزه قابل باز شدن', value: formatNumber(summary.maximumPrizeCardsOpenable || 0) },
      { label: 'میانگین امتیاز', value: formatNumber(summary.avgScore || 0, 2) },
      { label: 'بیشترین امتیاز', value: formatNumber(summary.topScore || 0) },
      { label: 'حداکثر امتیاز قابل دریافت', value: formatNumber(summary.maxPossibleScore || 0) },
      { label: '\u06a9\u0627\u0631\u0628\u0631\u0627\u0646 \u0628\u0627 \u062d\u062f\u0627\u06a9\u062b\u0631 \u0627\u0645\u062a\u06cc\u0627\u0632 \u0645\u0645\u06a9\u0646 \u062a\u0627 \u0627\u0644\u0627\u0646', value: formatNumber(summary.usersWithMaxPossibleScore || 0) },
      { label: 'ماموریت‌های شروع‌شده', value: formatNumber(summary.startedTaskCount || 0) },
      { label: 'همه ماموریت‌های شروع‌شده را انجام داده‌اند', value: formatNumber(summary.usersCompletedAllStarted || 0) },
      { label: 'جوایز باقی‌مانده', value: formatNumber(summary.prizeRemaining || 0) },
      { label: 'ظرفیت کل جوایز', value: formatNumber(summary.prizeCapacity || 0) },
      { label: 'جوایز داده‌شده', value: formatNumber(summary.prizeGiven || 0) },
      { label: 'مجموع ارزش جوایز داده‌شده', value: `${formatNumber(summary.prizeValueAssignedToInvitees || 0, 2)} تومان` },
      { label: 'مجموع ارزش جوایز داده‌نشده', value: `${formatNumber(summary.prizeValueRemaining || 0, 2)} تومان` },
      { label: 'بررسی بودجه جوایز', value: reconciliationText, wide: true, problem: hasPrizeProblem }
    ];

    host.innerHTML = items.map((item) => `
      <article class="card tc-monitoring-kpi ${item.wide ? 'tc-monitoring-kpi--wide' : ''} ${item.problem ? 'tc-monitoring-kpi--problem' : ''}">
        <div class="tc-monitoring-kpi-label">${escapeHtml(item.label)}</div>
        <div class="tc-monitoring-kpi-value">${escapeHtml(item.value)}</div>
      </article>
    `).join('');
  }

  function renderTaskChart(taskStats = [], totalUsers = 0) {
    const host = getElement('tc-monitoring-task-chart');
    if (!host) return;
    const rows = Array.isArray(taskStats) ? taskStats : [];
    if (!rows.length) {
      host.innerHTML = '<p class="muted">داده‌ای برای ماموریت‌ها پیدا نشد.</p>';
      return;
    }
    const startedRows = Array.isArray(currentMonitoringPayload?.completionDistribution)
      ? currentMonitoringPayload.completionDistribution
      : [];
    const startedById = new Map(startedRows.map((item) => [
      String(item?.id || '').trim(),
      item
    ]).filter(([id]) => id !== ''));
    const startedByTitle = new Map(startedRows.map((item) => [
      String(item?.title || '').trim(),
      item
    ]).filter(([title]) => title !== ''));

    host.innerHTML = rows.map((item) => {
      const title = String(item?.title || '-');
      const completedUsers = Math.max(0, Number.parseInt(item?.completedUsers ?? 0, 10) || 0);
      const completionRate = Math.max(0, Math.min(100, Number(item?.completionRate ?? 0) || 0));
      const startedItem = startedById.get(String(item?.id || '').trim())
        || startedByTitle.get(String(item?.title || '').trim())
        || null;
      const startedUsers = Math.max(0, Number.parseInt(startedItem?.users ?? 0, 10) || 0);
      const startedRate = Math.max(0, Math.min(100, Number(startedItem?.percentage ?? 0) || 0));
      const startedMetric = startedItem
        ? `
          <div class="tc-monitoring-comparison-line">
            <div class="tc-monitoring-comparison-meta">
              <span class="tc-monitoring-bar-meta-item tc-monitoring-bar-meta-item--started">مداوم انجام شده</span>
              <span>${formatNumber(startedUsers)} / ${formatNumber(totalUsers)} (${formatPercent(startedRate)})</span>
            </div>
            <div class="tc-monitoring-bar-track tc-monitoring-bar-track--slim">
              <span class="tc-monitoring-bar-fill tc-monitoring-bar-fill--alt" style="width:${startedRate}%"></span>
            </div>
          </div>
        `
        : '';
      return `
        <div class="tc-monitoring-bar-row">
          <div class="tc-monitoring-bar-head">
            <span class="tc-monitoring-bar-title">${escapeHtml(title)}</span>
          </div>
          <div class="tc-monitoring-comparison">
            <div class="tc-monitoring-comparison-line">
              <div class="tc-monitoring-comparison-meta">
                <span class="tc-monitoring-bar-meta-item">همه</span>
                <span>${formatNumber(completedUsers)} / ${formatNumber(totalUsers)} (${formatPercent(completionRate)})</span>
              </div>
              <div class="tc-monitoring-bar-track tc-monitoring-bar-track--slim">
                <span class="tc-monitoring-bar-fill" style="width:${completionRate}%"></span>
              </div>
            </div>
            ${startedMetric}
          </div>
        </div>
      `;
    }).join('');
  }

  function renderScoreStages(stages = [], totalUsers = 0) {
    const host = getElement('tc-monitoring-score-stages');
    if (!host) return;
    const rows = Array.isArray(stages) ? stages : [];
    if (!rows.length) {
      host.innerHTML = '<p class="muted">داده‌ای برای مرحله‌های امتیاز پیدا نشد.</p>';
      return;
    }
    host.innerHTML = `<div class="tc-monitoring-score-stage-grid">${rows.map((stage) => {
      const users = Math.max(0, Number.parseInt(stage?.users ?? 0, 10) || 0);
      const percentage = Math.max(0, Math.min(100, Number(stage?.percentage ?? 0) || 0));
      const minScore = Number(stage?.minScore ?? 0) || 0;
      const maxScore = Number(stage?.maxScore ?? 0) || 0;
      const title = minScore === 0 && maxScore === 0
        ? 'شرکت کرده اما بدون امتیاز'
        : `امتیاز ${formatNumber(minScore)} تا ${formatNumber(maxScore)}`;
      return `
        <div class="tc-monitoring-score-stage-card" style="--stage-percent:${percentage}%">
          <div class="tc-monitoring-score-stage-ring" aria-hidden="true">
            <span>${formatPercent(percentage)}</span>
          </div>
          <div class="tc-monitoring-score-stage-body">
            <strong>${escapeHtml(title)}</strong>
            <span>${formatNumber(users)} / ${formatNumber(totalUsers)}</span>
            <small>میانگین ${formatNumber(stage?.avgScore || 0, 2)}</small>
          </div>
        </div>
      `;
    }).join('')}</div>`;
  }

  function renderLevelChart(levelStats = [], totalUsers = 0) {
    const host = getElement('tc-monitoring-level-chart');
    if (!host) return;
    const rows = Array.isArray(levelStats) ? levelStats : [];
    if (!rows.length) {
      host.innerHTML = '<p class="muted">سطح جایزه‌ای ثبت نشده است.</p>';
      return;
    }

    host.innerHTML = rows.map((item) => {
      const name = String(item?.name || '-');
      const score = Math.max(0, Number.parseInt(item?.score ?? 0, 10) || 0);
      const eligibleUsers = Math.max(0, Number.parseInt(item?.eligibleUsers ?? 0, 10) || 0);
      const eligibleRate = Math.max(0, Math.min(100, Number(item?.eligibleRate ?? 0) || 0));
      const levelTypeToken = String(item?.type || 'value_sum');
      const levelType = levelTypeToken === 'out_of_value'
        ? 'خارج از ارزش'
        : (levelTypeToken === 'pot' ? 'Pot' : '\u062c\u0645\u0639 \u0627\u0631\u0632\u0634');
      return `
        <div class="tc-monitoring-bar-row">
          <div class="tc-monitoring-bar-head">
            <span class="tc-monitoring-bar-title">${escapeHtml(name)} <span class="tc-monitoring-badge">${escapeHtml(levelType)}</span></span>
            <span class="tc-monitoring-bar-meta">${formatNumber(eligibleUsers)} / ${formatNumber(totalUsers)} | نرخ واجد بودن ${formatPercent(eligibleRate)} | امتیاز ${formatNumber(score)}</span>
          </div>
          <div class="tc-monitoring-bar-track">
            <span class="tc-monitoring-bar-fill tc-monitoring-bar-fill--alt" style="width:${eligibleRate}%"></span>
          </div>
        </div>
      `;
    }).join('');
  }

  function renderParticipationChart(stats = {}) {
    const host = getElement('tc-monitoring-participation-chart');
    if (!host) return;

    const totalUsers = Math.max(0, Number.parseInt(stats?.totalUsers ?? 0, 10) || 0);
    const loggedInUsers = Math.max(0, Number.parseInt(stats?.loggedInUsers ?? 0, 10) || 0);
    const completedUsers = Math.max(0, Number.parseInt(stats?.usersWithCompletion ?? 0, 10) || 0);
    const metrics = [
      {
        label: 'کل دعوت‌شدگان',
        value: totalUsers,
        base: totalUsers,
        detail: 'همه کاربران موجود در گروه‌های انتخاب‌شده'
      },
      {
        label: 'حداقل یک‌بار ورود',
        value: loggedInUsers,
        base: totalUsers,
        detail: 'نرخ ورود از کل دعوت‌شدگان'
      },
      {
        label: 'مشارکت در حداقل یک ماموریت',
        value: stats?.usersWithTaskParticipation,
        base: totalUsers,
        detail: 'کاربرانی که با حداقل یک ماموریت تعامل داشته یا آن را تکمیل کرده‌اند'
      },
      {
        label: 'تکمیل همه ماموریت‌ها',
        value: stats?.usersCompletedAllStarted,
        base: totalUsers,
        detail: 'کاربرانی که همه ماموریت‌های شروع‌شده را تکمیل کرده‌اند'
      },
      {
        label: 'بالاترین امتیاز ممکن',
        value: stats?.usersWithMaxPossibleScore,
        base: totalUsers,
        detail: 'کاربرانی که به حداکثر امتیاز قابل دریافت رسیده‌اند'
      },
      {
        label: 'واجد سطح جایزه',
        value: stats?.usersEligibleAnyLevel,
        base: totalUsers,
        detail: 'کاربرانی که حداقل به یک سطح جایزه رسیده‌اند'
      },
      {
        label: 'ترک زودهنگام',
        value: stats?.loggedInWithoutCompletion,
        base: loggedInUsers,
        detail: 'وارد شده‌اند، اما هیچ ماموریتی را تکمیل نکرده‌اند'
      },
      {
        label: 'بدون ورود',
        value: stats?.neverLoggedInUsers,
        base: totalUsers,
        detail: 'دعوت‌شدگانی که حتی یک‌بار وارد نشده‌اند'
      }
    ].map((metric) => {
      const value = Math.max(0, Number.parseInt(metric.value ?? 0, 10) || 0);
      const base = Math.max(0, Number.parseInt(metric.base ?? 0, 10) || 0);
      const rate = base > 0 ? Math.min(100, (value * 100) / base) : 0;
      return { ...metric, value, base, rate };
    });

    host.innerHTML = metrics.map((metric) => {
      const tooltip = `${metric.label}: ${formatNumber(metric.value)} از ${formatNumber(metric.base)} (${formatPercent(metric.rate)}) — ${metric.detail}`;
      return `
        <div class="tc-monitoring-vertical-item" tabindex="0" aria-label="${escapeHtml(tooltip)}">
          <div class="tc-monitoring-vertical-value">${formatNumber(metric.value)}</div>
          <div class="tc-monitoring-vertical-track" aria-hidden="true">
            <span class="tc-monitoring-vertical-fill" style="height:${metric.rate}%"></span>
          </div>
          <div class="tc-monitoring-vertical-label">${escapeHtml(metric.label)}</div>
          <div class="tc-monitoring-vertical-tooltip" role="tooltip">
            <strong>${escapeHtml(metric.label)}</strong>
            <span>${formatNumber(metric.value)} از ${formatNumber(metric.base)} — ${formatPercent(metric.rate)}</span>
            <small>${escapeHtml(metric.detail)}</small>
          </div>
        </div>
      `;
    }).join('');
  }

  function renderActiveUsers(users = []) {
    const body = getElement('tc-monitoring-active-users');
    if (!body) return;
    const rows = Array.isArray(users) ? users : [];
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="7" class="muted">فعالیتی از کاربران ثبت نشده است.</td></tr>';
      return;
    }
    body.innerHTML = rows.map((row) => `
      <tr>
        <td>${formatNumber(row?.rank || 0)}</td>
        <td>${escapeHtml(row?.name || '-')}</td>
        <td><code>${escapeHtml(row?.workId || '-')}</code></td>
        <td>${formatNumber(row?.score || 0)}</td>
        <td>${formatNumber(row?.loginCount || 0)}</td>
        <td>${formatNumber(row?.completedTaskCount || 0)}</td>
        <td>${formatNumber(row?.activityScore || 0)}</td>
      </tr>
    `).join('');
  }

  function renderInfoKpis(hostId, items = [], note = '') {
    const host = getElement(hostId);
    if (!host) return;
    const cards = items.map((item) => `
      <article class="card tc-monitoring-kpi">
        <div class="tc-monitoring-kpi-label">${escapeHtml(item.label)}</div>
        <div class="tc-monitoring-kpi-value">${escapeHtml(item.value)}</div>
      </article>
    `).join('');
    const noteHtml = note
    host.innerHTML = `${noteHtml}${cards}`;
  }

  function renderChallengeStats(stats = {}) {
    const unavailable = stats?.available === false;
    renderInfoKpis('tc-monitoring-challenge-stats', [
      { label: 'وضعیت داده', value: unavailable ? 'ناموجود یا ناقص' : 'در دسترس' },
      { label: 'تیم‌های چالش شروع‌کرده', value: formatNumber(stats?.startedChallengeTeams || 0) },
      { label: 'کاربران دارای چالش شروع‌شده', value: formatNumber(stats?.challengeParticipants || 0) },
      { label: 'میانگین چالش برای همه کاربران', value: formatNumber(stats?.avgChallengesPerUser || 0, 2) },
      { label: 'میانگین چالش برای مشارکت‌کنندگان', value: formatNumber(stats?.avgChallengesPerParticipant || 0, 2) }
    ]);
  }

  function renderEventInfo(info = {}) {
    renderInfoKpis('tc-monitoring-event-info', [
      { label: 'شروع رویداد', value: formatShamsiDateTime(info?.startAt) },
      { label: 'پایان رویداد', value: formatShamsiDateTime(info?.endAt) },
      { label: 'مدت رویداد', value: info?.startAt && info?.endAt ? formatDuration(info?.durationMinutes) : 'نامشخص' }
    ]);
  }

  function renderWorkIdGroups(groups = []) {
    const body = getElement('tc-monitoring-workid-groups');
    if (!body) return;
    const footer = getElement('tc-monitoring-workid-groups-footer');
    const sourceRows = Array.isArray(groups) ? groups : [];
    const selectedValues = selectedWorkIdGroups.map((group) => String(group || '').trim()).filter(Boolean);
    const selectedSet = new Set((selectedValues.length
      ? selectedValues
      : sourceRows.map((row) => String(row?.group || '-'))).map(normalizeWorkIdGroupKey));
    const rows = sourceRows.filter((row) => selectedSet.has(normalizeWorkIdGroupKey(String(row?.group || '-'))));
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="6" class="muted">گروهی انتخاب نشده است.</td></tr>';
      if (footer) footer.innerHTML = '';
      return;
    }
    body.innerHTML = rows.map((row) => {
      const groupName = String(row?.group || '-');
      const users = Math.max(0, Number(row?.users || 0) || 0);
      const participants = Math.max(0, Number(row?.participants || 0) || 0);
      const avgCompleted = Math.max(0, Number(row?.avgCompletedStartedMissions || 0) || 0);
      const avgScore = Math.max(0, Number(row?.avgScore || 0) || 0);
      return `
        <tr data-monitoring-workid-group-row
          data-selected="1"
          data-users="${users}"
          data-participants="${participants}"
          data-completion-total="${avgCompleted * users}"
          data-score-total="${avgScore * users}">
          <td>${escapeHtml(groupName)}</td>
          <td>${formatNumber(users)}</td>
          <td>${formatNumber(participants)}</td>
          <td>${formatPercent(row?.participantRate || 0)}</td>
          <td>${formatNumber(avgCompleted, 2)}</td>
          <td>${formatNumber(avgScore, 2)}</td>
        </tr>
      `;
    }).join('');
    updateWorkIdGroupsFooter();
  }

  function updateWorkIdGroupsFooter() {
    const body = getElement('tc-monitoring-workid-groups');
    const footer = getElement('tc-monitoring-workid-groups-footer');
    if (!body || !footer) return;
    const checkedRows = Array.from(body.querySelectorAll('[data-monitoring-workid-group-row]'))
      .filter((row) => String(row.dataset.selected || '') === '1');
    const totals = checkedRows.reduce((acc, row) => {
      const users = Math.max(0, Number(row.dataset.users || 0) || 0);
      acc.users += users;
      acc.participants += Math.max(0, Number(row.dataset.participants || 0) || 0);
      acc.completionTotal += Math.max(0, Number(row.dataset.completionTotal || 0) || 0);
      acc.scoreTotal += Math.max(0, Number(row.dataset.scoreTotal || 0) || 0);
      return acc;
    }, {
      users: 0,
      participants: 0,
      completionTotal: 0,
      scoreTotal: 0
    });
    const participantRate = totals.users > 0 ? (totals.participants * 100) / totals.users : 0;
    const avgCompleted = totals.users > 0 ? totals.completionTotal / totals.users : 0;
    const avgScore = totals.users > 0 ? totals.scoreTotal / totals.users : 0;
    footer.innerHTML = `
      <tr>
        <th>جمع انتخاب‌شده</th>
        <th>${formatNumber(totals.users)}</th>
        <th>${formatNumber(totals.participants)}</th>
        <th>${formatPercent(participantRate)}</th>
        <th>${formatNumber(avgCompleted, 2)}</th>
        <th>${formatNumber(avgScore, 2)}</th>
      </tr>
    `;
  }
  function renderData(payload = {}) {
    const summary = payload?.summary || {};
    currentMonitoringPayload = payload || {};
    const groupStats = Array.isArray(payload?.workIdGroupStats) ? payload.workIdGroupStats : [];
    const filterGroups = Array.isArray(payload?.workIdGroups) && payload.workIdGroups.length
      ? payload.workIdGroups
      : groupStats.map((row) => String(row?.group || '').trim()).filter(Boolean);
    renderWorkIdGroupFilter(filterGroups, payload?.selectedWorkIdGroups || []);
    renderKpis(summary);
    renderEventInfo(payload?.eventInfo || {});
    renderScoreStages(payload?.scoreStages || [], Number(summary?.scoreStageUserCount || 0));
    renderTaskChart(payload?.taskStats || [], Number(summary?.totalUsers || 0));
    renderLevelChart(payload?.levelStats || [], Number(summary?.totalUsers || 0));
    renderParticipationChart(payload?.participationStats || summary);
    renderActiveUsers(payload?.mostActiveUsers || []);
    renderChallengeStats(payload?.challengeStats || {});
    renderWorkIdGroups(payload?.workIdGroupStats || []);
    setUpdatedAt(payload?.generatedAt || '');
  }

  async function loadMonitoring(force = false) {
    const pane = getPane();
    if (!pane) return;
    if (isLoading) {
      if (force) {
        pendingMonitoringReload = true;
      }
      return;
    }
    if (hasLoadedOnce && !force) return;

    isLoading = true;
    updateWorkIdApplyState();
    const loadingText = workIdGroupFilterInitialized ? 'در حال اعمال فیلتر و محاسبه داده‌ها' : 'در حال محاسبه داده‌های مانیتورینگ';
    setStatus('در حال بارگذاری داده‌های مانیتورینگ...');
    startMonitoringLoading(loadingText);
    setDiagnostics(null);
    let loadedSuccessfully = false;
    try {
      const response = await fetch(monitoringApiUrl(), {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store'
      });
      const responseText = await response.text();
      let payload = null;
      try {
        payload = JSON.parse(responseText);
      } catch (_error) {
        payload = null;
      }
      if (!payload && response.ok && responseText.trim()) {
        setDiagnostics({
          type: 'invalid_json',
          status: response.status,
          preview: responseText.trim().slice(0, 2000)
        });
        throw new Error('پاسخ مانیتورینگ معتبر نبود. احتمالا سرور به‌جای JSON پیام خطای PHP یا HTML برگردانده است.');
      }
      if (!response.ok || !payload || !['ok', 'partial'].includes(String(payload.status || ''))) {
        const message = payload?.message || `خطا در دریافت اطلاعات (${response.status})`;
        setDiagnostics(payload?.diagnostics || payload?.data?.diagnostics || {
          type: 'request_error',
          status: response.status,
          code: payload?.code || '',
          message
        });
        throw new Error(message);
      }
      renderData(payload.data || {});
      loadedSuccessfully = true;
      setDiagnostics(payload?.data?.diagnostics || payload?.diagnostics || null);
      hasLoadedOnce = true;
      const warnings = Array.isArray(payload?.data?.warnings) ? payload.data.warnings.filter(Boolean) : [];
      if (payload.status === 'partial' || warnings.length) {
        setStatus(warnings[0] || payload.message || 'بخشی از داده‌ها قابل محاسبه نبود؛ اطلاعات موجود نمایش داده شده است.');
      } else {
        setStatus('');
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : 'بارگذاری داده‌های مانیتورینگ ناموفق بود.';
      setStatus(message, true);
    } finally {
      isLoading = false;
      stopMonitoringLoading(loadedSuccessfully);
      updateWorkIdApplyState();
      if (pendingMonitoringReload) {
        pendingMonitoringReload = false;
        void loadMonitoring(true);
      }
    }
  }

  function isMonitoringPaneActive() {
    const pane = getPane();
    return pane instanceof HTMLElement && pane.classList.contains('active');
  }

  function bindEvents() {
    const pane = getPane();
    if (!(pane instanceof HTMLElement)) return;
    if (pane.dataset.tcMonitoringReady === '1') return;
    pane.dataset.tcMonitoringReady = '1';

    pane.querySelectorAll('[data-tc-monitoring-view]').forEach((button) => {
      button.addEventListener('click', () => setMonitoringView(String(button.getAttribute('data-tc-monitoring-view') || 'overview')));
    });
    const surveySelect = getElement('tc-survey-monitoring-task');
    if (surveySelect instanceof HTMLSelectElement) {
      surveySelect.addEventListener('change', () => void loadSurveyMonitoring(String(surveySelect.value || ''), true));
    }
    const surveyRefresh = getElement('tc-survey-monitoring-refresh');
    if (surveyRefresh instanceof HTMLButtonElement) {
      surveyRefresh.addEventListener('click', () => {
        const taskId = surveySelect instanceof HTMLSelectElement ? String(surveySelect.value || '') : '';
        if (taskId) void loadSurveyMonitoring(taskId, true);
        else void loadSurveyTasks(true);
      });
    }
    const surveyExport = getElement('tc-survey-monitoring-export');
    if (surveyExport instanceof HTMLButtonElement) surveyExport.addEventListener('click', () => void exportSurveyMonitoring());

    const refreshButton = getElement('tc-monitoring-refresh');
    if (refreshButton instanceof HTMLButtonElement) {
      refreshButton.addEventListener('click', () => {
        void loadMonitoring(true);
      });
    }

    const exportButton = getElement('tc-monitoring-export');
    if (exportButton instanceof HTMLButtonElement) {
      exportButton.addEventListener('click', openExportModal);
    }
    ['tc-monitoring-export-close', 'tc-monitoring-export-cancel'].forEach((id) => {
      const button = getElement(id);
      if (button instanceof HTMLButtonElement) button.addEventListener('click', closeExportModal);
    });
    const exportModal = getElement('tc-monitoring-export-modal');
    exportModal?.addEventListener('click', (event) => {
      if (event.target === exportModal) closeExportModal();
    });
    const exportSubmit = getElement('tc-monitoring-export-submit');
    if (exportSubmit instanceof HTMLButtonElement) {
      exportSubmit.addEventListener('click', () => void downloadMonitoringExport());
    }
    const exportSelectAll = getElement('tc-monitoring-export-select-all');
    if (exportSelectAll instanceof HTMLButtonElement) {
      exportSelectAll.addEventListener('click', () => {
        getElement('tc-monitoring-export-groups')?.querySelectorAll('input[type="checkbox"]').forEach((input) => {
          if (input instanceof HTMLInputElement) input.checked = true;
        });
      });
    }
    const exportSelectNone = getElement('tc-monitoring-export-select-none');
    if (exportSelectNone instanceof HTMLButtonElement) {
      exportSelectNone.addEventListener('click', () => {
        getElement('tc-monitoring-export-groups')?.querySelectorAll('input[type="checkbox"]').forEach((input) => {
          if (input instanceof HTMLInputElement) input.checked = false;
        });
      });
    }

    const applyFilterButton = getElement('tc-monitoring-workid-apply');
    if (applyFilterButton instanceof HTMLButtonElement) {
      applyFilterButton.addEventListener('click', () => {
        if (isLoading) return;
        selectedWorkIdGroups = draftWorkIdGroups.slice();
        workIdGroupFilterInitialized = true;
        updateWorkIdApplyState();
        void loadMonitoring(true);
      });
    }

    const monitoringClickHandler = (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      const trigger = target.closest('.sub-item[data-pane]');
      if (!(trigger instanceof HTMLElement)) return;
      if (String(trigger.dataset.pane || '') !== 'tc-monitoring') return;
      window.setTimeout(() => {
        void loadMonitoring(false);
      }, 0);
    };
    window[TC_MONITORING_CLICK_HANDLER_KEY] = monitoringClickHandler;
    document.addEventListener('click', monitoringClickHandler);

    const tasksChangedHandler = () => {
      hasLoadedOnce = false;
      surveyTasksLoaded = false;
      if (isMonitoringPaneActive()) {
        const surveyPanel = pane.querySelector('[data-tc-monitoring-view-panel="shared-survey"]');
        if (surveyPanel instanceof HTMLElement && !surveyPanel.hidden) void loadSurveyTasks(true);
        else void loadMonitoring(false);
      }
    };
    window[TC_MONITORING_TASKS_CHANGED_HANDLER_KEY] = tasksChangedHandler;
    window.addEventListener('tcTasksChanged', tasksChangedHandler);

    if (isMonitoringPaneActive()) {
      void loadMonitoring(false);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindEvents);
  } else {
    bindEvents();
  }
})();
