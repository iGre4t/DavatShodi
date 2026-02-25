(() => {
  const PANE_SELECTOR = '.sub-pane[data-pane="tc-monitoring"]';
  const API_URL = 'mini%20apps/Task%20Club/TCMonitoring.php?action=stats';

  let hasLoadedOnce = false;
  let isLoading = false;

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

  function taskTypeLabel(taskType) {
    const token = String(taskType || '').trim().toLowerCase();
    if (token === 'describe_photo') return 'توصیف تصویر';
    if (token === 'team_task') return 'تیمی';
    if (token === 'info') return 'اطلاعاتی';
    return 'کوییز';
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

  function setStatus(message, isError = false) {
    const statusEl = getElement('tc-monitoring-status');
    if (!statusEl) return;
    statusEl.textContent = String(message || '');
    statusEl.style.color = isError ? '#d1434a' : '';
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

  function renderKpis(summary = {}) {
    const host = getElement('tc-monitoring-kpis');
    if (!host) return;
    const favoriteTask = summary?.favoriteTask;
    const favoriteText = favoriteTask?.title
      ? `${favoriteTask.title} (${formatPercent(favoriteTask.completionRate || 0)})`
      : 'هنوز ماموریتی تکمیل نشده است';

    const items = [
      { label: 'تعداد دعوت‌شدگان', value: formatNumber(summary.totalUsers || 0) },
      { label: 'حداقل یک‌بار ورود', value: formatNumber(summary.loggedInUsers || 0) },
      { label: 'کاربران با ماموریت تکمیل‌شده', value: formatNumber(summary.usersWithCompletion || 0) },
      { label: 'واجد حداقل یک سطح جایزه', value: formatNumber(summary.usersEligibleAnyLevel || 0) },
      { label: 'میانگین امتیاز', value: formatNumber(summary.avgScore || 0, 2) },
      { label: 'بیشترین امتیاز', value: formatNumber(summary.topScore || 0) },
      { label: 'تعداد کارت‌های بازشده', value: formatNumber(summary.totalCardFlips || 0) },
      { label: 'تعداد ماموریت‌ها', value: formatNumber(summary.taskCount || 0) },
      { label: 'تعداد نوع جایزه', value: formatNumber(summary.prizeTypes || 0) },
      { label: 'جوایز باقی‌مانده', value: formatNumber(summary.prizeRemaining || 0) },
      { label: 'ظرفیت کل جوایز', value: formatNumber(summary.prizeCapacity || 0) },
      { label: 'جوایز داده‌شده', value: formatNumber(summary.prizeGiven || 0) },
      { label: 'محبوب‌ترین ماموریت', value: favoriteText, wide: true }
    ];

    host.innerHTML = items.map((item) => `
      <article class="card tc-monitoring-kpi ${item.wide ? 'tc-monitoring-kpi--wide' : ''}">
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

    host.innerHTML = rows.map((item) => {
      const title = String(item?.title || '-');
      const completedUsers = Math.max(0, Number.parseInt(item?.completedUsers ?? 0, 10) || 0);
      const completionRate = Math.max(0, Math.min(100, Number(item?.completionRate ?? 0) || 0));
      return `
        <div class="tc-monitoring-bar-row">
          <div class="tc-monitoring-bar-head">
            <span class="tc-monitoring-bar-title">${escapeHtml(title)}</span>
            <span class="tc-monitoring-bar-meta">${formatNumber(completedUsers)} / ${formatNumber(totalUsers)} (${formatPercent(completionRate)})</span>
          </div>
          <div class="tc-monitoring-bar-track">
            <span class="tc-monitoring-bar-fill" style="width:${completionRate}%"></span>
          </div>
        </div>
      `;
    }).join('');
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
      const levelType = String(item?.type || 'value_sum') === 'out_of_value'
        ? 'خارج از ارزش'
        : 'جمع ارزش';
      return `
        <div class="tc-monitoring-bar-row">
          <div class="tc-monitoring-bar-head">
            <span class="tc-monitoring-bar-title">${escapeHtml(name)} <span class="tc-monitoring-badge">${escapeHtml(levelType)}</span></span>
            <span class="tc-monitoring-bar-meta">${formatNumber(eligibleUsers)} / ${formatNumber(totalUsers)} | امتیاز ${formatNumber(score)}</span>
          </div>
          <div class="tc-monitoring-bar-track">
            <span class="tc-monitoring-bar-fill tc-monitoring-bar-fill--alt" style="width:${eligibleRate}%"></span>
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
      body.innerHTML = '<tr><td colspan="8" class="muted">فعالیتی از کاربران ثبت نشده است.</td></tr>';
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
        <td>${formatNumber(row?.cardFlips || 0)}</td>
        <td>${formatNumber(row?.prizeWonCount || 0)}</td>
      </tr>
    `).join('');
  }

  function renderTaskStats(taskStats = []) {
    const body = getElement('tc-monitoring-task-stats');
    if (!body) return;
    const rows = Array.isArray(taskStats) ? taskStats : [];
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="6" class="muted">آماری برای ماموریت‌ها ثبت نشده است.</td></tr>';
      return;
    }
    body.innerHTML = rows.map((row) => `
      <tr>
        <td>${escapeHtml(row?.title || '-')}</td>
        <td>${escapeHtml(taskTypeLabel(row?.taskType || 'quiz'))}</td>
        <td>${formatNumber(row?.completedUsers || 0)}</td>
        <td>${formatPercent(row?.completionRate || 0)}</td>
        <td>${formatNumber(row?.awardedScoreAvg || 0, 2)}</td>
        <td>${formatNumber(row?.awardedScoreTotal || 0, 2)}</td>
      </tr>
    `).join('');
  }

  function renderPrizeInventory(prizeStats = {}) {
    const body = getElement('tc-monitoring-prize-inventory');
    if (!body) return;
    const rows = Array.isArray(prizeStats?.rows) ? prizeStats.rows : [];
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="6" class="muted">جایزه‌ای ثبت نشده است.</td></tr>';
      return;
    }
    body.innerHTML = rows.map((row) => {
      const quantity = Math.max(0, Number.parseInt(row?.quantity ?? 0, 10) || 0);
      const remaining = Math.max(0, Number.parseInt(row?.last ?? 0, 10) || 0);
      const given = Math.max(0, quantity - remaining);
      return `
        <tr>
          <td>${escapeHtml(row?.name || '-')}</td>
          <td>${escapeHtml(row?.onWheelName || row?.name || '-')}</td>
          <td>${formatNumber(quantity)}</td>
          <td>${formatNumber(remaining)}</td>
          <td>${formatNumber(given)}</td>
          <td>${formatNumber(row?.value || 0, 2)}</td>
        </tr>
      `;
    }).join('');
  }

  function renderData(payload = {}) {
    const summary = payload?.summary || {};
    renderKpis(summary);
    renderTaskChart(payload?.taskStats || [], Number(summary?.totalUsers || 0));
    renderLevelChart(payload?.levelStats || [], Number(summary?.totalUsers || 0));
    renderActiveUsers(payload?.mostActiveUsers || []);
    renderTaskStats(payload?.taskStats || []);
    renderPrizeInventory(payload?.prizeStats || {});
    setUpdatedAt(payload?.generatedAt || '');
  }

  async function loadMonitoring(force = false) {
    const pane = getPane();
    if (!pane) return;
    if (isLoading) return;
    if (hasLoadedOnce && !force) return;

    isLoading = true;
    setStatus('در حال بارگذاری داده‌های مانیتورینگ...');
    try {
      const response = await fetch(API_URL, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store'
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload || payload.status !== 'ok') {
        const message = payload?.message || `خطا در دریافت اطلاعات (${response.status})`;
        throw new Error(message);
      }
      renderData(payload.data || {});
      hasLoadedOnce = true;
      setStatus('');
    } catch (error) {
      const message = error instanceof Error ? error.message : 'بارگذاری داده‌های مانیتورینگ ناموفق بود.';
      setStatus(message, true);
    } finally {
      isLoading = false;
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

    const refreshButton = getElement('tc-monitoring-refresh');
    if (refreshButton instanceof HTMLButtonElement) {
      refreshButton.addEventListener('click', () => {
        void loadMonitoring(true);
      });
    }

    document.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      const trigger = target.closest('.sub-item[data-pane]');
      if (!(trigger instanceof HTMLElement)) return;
      if (String(trigger.dataset.pane || '') !== 'tc-monitoring') return;
      window.setTimeout(() => {
        void loadMonitoring(false);
      }, 0);
    });

    window.addEventListener('tcTasksChanged', () => {
      hasLoadedOnce = false;
      if (isMonitoringPaneActive()) {
        void loadMonitoring(false);
      }
    });

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
