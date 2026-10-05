(() => {
  'use strict';
  const shell = document.querySelector('.egm-shell');
  if (!shell || shell.dataset.egmAdminUxReady === '1') return;
  shell.dataset.egmAdminUxReady = '1';
  const remembered = new Map();
  let serial = 0;
  const fa = value => Number(value).toLocaleString('fa-IR');
  const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
  };

  // Keep original controls, values, event listeners and permission boundaries.
  function flow(host, stages, key, {tabs = false} = {}) {
    if (!host || host.dataset.egmFlow) return;
    stages = stages.filter(stage => stage.nodes.filter(Boolean).length);
    if (stages.length < 2) return;
    host.dataset.egmFlow = key;
    const nav = el('div', 'egm-flow-nav');
    nav.setAttribute('role', 'tablist');
    nav.setAttribute('aria-label', tabs ? 'بخش‌های تنظیمات' : 'مراحل تنظیم');
    const panels = [];
    const buttons = [];
    const footer = el('div', 'egm-flow-footer');
    const back = el('button', 'btn ghost', 'مرحله قبل');
    const next = el('button', 'btn primary', 'ادامه');
    back.type = next.type = 'button';
    const progress = el('span', 'egm-flow-progress');
    footer.append(back, progress, next);
    host.prepend(nav);
    stages.forEach((stage, index) => {
      const id = 'egm-flow-' + ++serial;
      const button = el('button', 'egm-flow-step');
      button.type = 'button';
      button.setAttribute('role', 'tab');
      button.id = id + '-tab';
      button.setAttribute('aria-controls', id);
      if (!tabs) button.append(el('span', 'egm-flow-number', fa(index + 1)));
      button.append(el('span', '', stage.title));
      const panel = el('section', 'egm-flow-panel');
      panel.id = id;
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', button.id);
      stage.nodes.filter(Boolean).forEach(node => panel.append(node));
      host.append(panel);
      nav.append(button);
      panels.push(panel);
      buttons.push(button);
      button.addEventListener('click', () => show(index, true));
    });
    if (!tabs) host.append(footer);
    let current = Math.min(remembered.get(key) || 0, stages.length - 1);
    function show(index, focus = false) {
      current = Math.max(0, Math.min(index, panels.length - 1));
      remembered.set(key, current);
      panels.forEach((panel, i) => { panel.hidden = i !== current; });
      buttons.forEach((button, i) => {
        button.setAttribute('aria-selected', String(i === current));
        button.tabIndex = i === current ? 0 : -1;
        button.classList.toggle('active', i === current);
      });
      back.hidden = current === 0;
      next.hidden = current === panels.length - 1;
      next.textContent = panels[current].querySelector('[data-game-settings]') ? 'ذخیره و ادامه'
        : current + 1 < stages.length ? 'ادامه: ' + stages[current + 1].title : 'ادامه';
      progress.textContent = fa(current + 1) + ' از ' + fa(stages.length);
      if (focus) buttons[current].focus({preventScroll: true});
    }
    back.addEventListener('click', () => show(current - 1, true));
    next.addEventListener('click', () => {
      const invalid = [...panels[current].querySelectorAll('input, select, textarea')]
        .find(input => !input.disabled && input.offsetParent !== null && !input.checkValidity());
      if (invalid) { invalid.reportValidity(); return; }
      const gameSettings = panels[current].querySelector('[data-game-settings]');
      if (gameSettings) {
        next.disabled = true;
        gameSettings.requestSubmit();
        return;
      }
      show(current + 1, true);
    });
    host.addEventListener('egm-game-settings-result', event => {
      next.disabled = false;
      if (event.detail?.ok) show(current + 1);
    });
    nav.addEventListener('keydown', event => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      show(event.key === 'Home' ? 0 : event.key === 'End' ? panels.length - 1
        : current + (event.key === 'ArrowLeft' ? 1 : -1), true);
    });
    host.addEventListener('invalid', event => {
      const index = panels.findIndex(panel => panel.contains(event.target));
      if (index >= 0) show(index);
    }, true);
    host.addEventListener('egm-flow-open', event => {
      if (event.target !== host) return;
      show(Number(event.detail?.index) || 0);
    });
    show(current);
  }

  function periodSettings(section) {
    const form = section.querySelector(':scope > .card > .form');
    if (!form || form.dataset.egmFlow) return;
    const switches = form.querySelector('.egm-switch-grid');
    if (!switches) return;
    const attendance = el('div', 'egm-settings-block');
    attendance.append(el('h4', '', 'قوانین حضور'));
    const attendanceSwitches = el('div', 'egm-switch-grid');
    ['quitRequired', 'quitTimelineRequired'].forEach(field => {
      const row = switches.querySelector('[data-task-field="' + field + '"]')?.closest('.switch');
      if (row) attendanceSwitches.append(row);
    });
    attendance.append(attendanceSwitches);
    const draw = form.querySelector('.egm-prize-entry-window');
    const save = form.querySelector('[data-action="save-task-settings"]')?.closest('.field');
    const final = el('div', 'egm-settings-final');
    if (save) {
      const operations = el('div', 'egm-period-operations');
      operations.append(el('h4', '', 'مدیریت بازه'));
      save.querySelectorAll('button:not([data-action="save-task-settings"]), a').forEach(node => operations.append(node));
      final.append(save, operations);
    }
    const status = form.querySelector('[data-task-save-status]');
    if (status) final.append(status);
    flow(form, [
      {title: 'مشخصات', nodes: [form.querySelector('[data-task-field="taskTitle"]')?.closest('.field'), form.querySelector('[data-task-status]'), switches]},
      {title: 'ورود و خروج', nodes: [attendance, form.querySelector('[data-quit-minimum-stay]'), form.querySelector('.egm-datetime-grid')]},
      {title: 'قرعه‌کشی و ذخیره', nodes: [draw, final]},
    ], 'period:' + (section.closest('[data-pane]')?.dataset.pane || ''));
  }

  function enhance() {
    const focused = shell.contains(document.activeElement) ? document.activeElement : null;
    const selection = focused instanceof HTMLInputElement || focused instanceof HTMLTextAreaElement
      ? [focused.selectionStart, focused.selectionEnd] : null;
    shell.querySelectorAll('[data-task-top-section="control"]').forEach(periodSettings);
    shell.querySelectorAll('[data-task-top-section="invite"]').forEach(section => {
      if (section.dataset.egmFlow) return;
      const methods = el('div', 'egm-invitation-methods');
      flow(methods, [
        {title: 'از فهرست کاربران', nodes: [section.querySelector('.egm-period-filter-card')]},
        {title: 'از فایل اکسل', nodes: [section.querySelector('.egm-period-excel-card')]},
      ], 'method:' + section.closest('[data-pane]')?.dataset.pane, {tabs: true});
      flow(section, [
        {title: 'انتخاب مهمان', nodes: [section.querySelector('.egm-period-source-card'), methods]},
        {title: 'بررسی و دعوت', nodes: [section.querySelector('.egm-period-candidates-card'), section.querySelector('.egm-period-unmatched-card')]},
        {title: 'نقشه سالن', nodes: [section.querySelector('[data-seat-map-editor]')]},
      ], 'invite:' + section.closest('[data-pane]')?.dataset.pane, {tabs: true});
    });
    shell.querySelectorAll('[data-task-top-section="draws"]').forEach(section => {
      if (section.dataset.egmFlow) return;
      const message = section.querySelector('[data-draw-message]');
      flow(section, [
        {title: 'قرعه‌کشی‌ها', nodes: [section.querySelector('[data-period-draw-list]')]},
        {title: 'افزودن یا ویرایش', nodes: [section.querySelector(':scope > .card')]},
      ], 'draw:' + section.closest('[data-pane]')?.dataset.pane, {tabs: true});
      if (message) section.querySelector(':scope > .egm-flow-nav')?.after(message);
    });
    shell.querySelectorAll('[data-egm-invite-card-pane]').forEach(pane => {
      if (pane.dataset.egmFlow) return;
      const artwork = pane.querySelector('.egm-invite-card-artwork-card');
      const content = pane.querySelector('.egm-invite-card-content-card');
      const preview = pane.querySelector('.egm-invite-card-preview-card');
      if (!artwork || !content || !preview) return;
      const tester = el('div', 'egm-card-test-controls');
      const picker = content.querySelector('[data-invite-card-invitee-search]')?.closest('.field');
      const commands = content.querySelector('.egm-invite-card-command-row');
      if (picker) tester.append(picker);
      if (commands) tester.append(commands);
      preview.querySelector('.section-header')?.after(tester);
      const variables = content.querySelector('[data-invite-card-conditional-builder]');
      const advanced = el('div', 'card');
      if (variables) advanced.append(variables);
      flow(pane, [
        {title: 'قالب و ناحیه‌ها', nodes: [artwork]},
        {title: 'متن کارت', nodes: [content]},
        ...(variables ? [{title: 'متغیرهای شرطی', nodes: [advanced]}] : []),
        {title: 'پیش‌نمایش و ذخیره', nodes: [preview]},
      ], 'card:' + pane.dataset.pane);
    });
    const prizes = shell.querySelector('#egm-prize-section');
    if (prizes && !prizes.dataset.egmFlow) {
      const cards = [...prizes.querySelectorAll(':scope > .card')];
      flow(prizes, [
        {title: 'موجودی جوایز', nodes: [cards[1]]},
        {title: 'افزودن جایزه', nodes: [cards[0]]},
        {title: 'صف رقابت‌ها', nodes: [cards[2]]},
      ], 'prizes', {tabs: true});
    }
    const periods = shell.querySelector('[data-pane="egm-manage-tasks"]');
    if (periods && !periods.dataset.egmFlow) {
      const message = periods.querySelector('#tct-status');
      const cards = [...periods.querySelectorAll(':scope > .card')];
      flow(periods, [
        {title: 'بازه‌ها', nodes: [cards.find(card => card.querySelector('#tct-list-body')) || cards[1]]},
        {title: 'بازه جدید', nodes: [cards.find(card => card.querySelector('#tct-form'))]},
      ], 'periods', {tabs: true});
      if (message) periods.querySelector(':scope > .egm-flow-nav')?.after(message);
    }
    const access = shell.querySelector('[data-egm-control-panel-section="admin-access"]');
    if (access && !access.dataset.egmFlow) {
      const accessCard = access.querySelector('#egm-task-access-users')?.closest('.card')
        || [...access.querySelectorAll(':scope > .card')].find(card => card.querySelector('.egm-task-access-users-table'));
      flow(access, [
        {title: 'ادمین‌ها', nodes: [access.querySelector('#egm-assign-admin-card')]},
        {title: 'دسترسی بازه‌ها', nodes: [accessCard]},
        {title: 'کد مدیریت', nodes: [access.querySelector('[data-egm-admin-passcode-content]')]},
      ], 'access', {tabs: true});
    }
    const monitoring = shell.querySelector('[data-pane="egm-monitoring"]');
    if (monitoring && !monitoring.dataset.egmFlow) {
      const cards = [...monitoring.querySelectorAll(':scope > .card')];
      flow(monitoring, [
        {title: 'نمای کلی', nodes: cards.slice(1, 4)},
        {title: 'پیشرفت فعالیت‌ها', nodes: cards.slice(4, 7)},
        {title: 'جزئیات مشارکت', nodes: cards.slice(7)},
      ], 'monitoring', {tabs: true});
    }
    shell.querySelectorAll('[data-game-id]').forEach(game => {
      if (game.dataset.egmFlow || !game.querySelector('[data-game-settings]')) return;
      game.querySelector('[data-game-settings] button[type="submit"]')?.classList.add('egm-flow-managed-save');
      flow(game, [
        {title: 'تنظیم بازی', nodes: [game.querySelector('[data-game-settings]')]},
        {title: 'مراحل و اتاق‌ها', nodes: [game.querySelector('.egm-games-section')]},
      ], 'game:' + game.dataset.gameId);
    });
    const manage = shell.querySelector('[data-invitees-top-section="manage-invitees"]');
    if (manage && !manage.dataset.egmFlow) {
      const cards = [...manage.querySelectorAll(':scope > .card')];
      flow(manage, [
        {title: 'دعوت با اکسل', nodes: [cards.find(card => card.hasAttribute('data-egm-custom-upload-card'))]},
        {title: 'افزودن یک مهمان', nodes: [cards.find(card => card.querySelector('#egm-add-invitee-btn'))]},
        {title: 'تنظیمات و آمار', nodes: [cards.find(card => card.querySelector('#egm-any-password-save')), cards.find(card => card.querySelector('h3')?.textContent.trim() === 'آمار')]},
      ], 'invitees:manage', {tabs: true});
    }
    // Keep live status, validation and identifiers; trim repeated helper copy.
    shell.querySelectorAll('.section-header p.muted, .field > span.muted.small, p.hint, p.muted.small').forEach(node => {
      if (node.id || node.hasAttribute('aria-live') || node.hasAttribute('role')
        || [...node.attributes].some(attr => attr.name.startsWith('data-'))
        || node.querySelector('input, button, a, [aria-live], [data-conditional-token-preview]')
        || node.closest('.egm-period-ended-note')) return;
      if (node.textContent.trim().length > 35) node.classList.add('egm-helper-copy');
    });
    shell.querySelectorAll('input:not([type]), input[type="text"], input[type="number"], input[type="search"], input[type="password"], input[type="email"], input[type="date"], input[type="time"], select, textarea').forEach(input => {
      input.classList.add('egm-standard-control');
    });
    if (focused?.isConnected && focused.offsetParent !== null && document.activeElement !== focused) {
      focused.focus({preventScroll: true});
      if (selection && selection[0] !== null) {
        try { focused.setSelectionRange(...selection); } catch {}
      }
    }
  }

  function start() {
    const eventForm = shell.querySelector('#egm-texts-card > .form');
    if (eventForm) flow(eventForm, [
      {title: 'وضعیت رویداد', nodes: [eventForm.querySelector('#egm-status-text'), eventForm.querySelector('.egm-switch-grid')]},
      {title: 'زمان‌بندی و ذخیره', nodes: [eventForm.querySelector('.egm-datetime-grid'), eventForm.querySelector('#egm-settings-save')?.closest('.field')]},
    ], 'event:settings');
    const sidebar = shell.querySelector('.sub-sidebar .sub-nav');
    if (sidebar) {
      const search = el('input', 'egm-standard-control egm-navigation-search');
      search.type = 'search';
      search.placeholder = 'جستجوی بخش…';
      search.setAttribute('aria-label', 'جستجوی بخش‌های مدیریت رویداد');
      const normalize = value => value.replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/\s+/g, '').toLowerCase();
      search.addEventListener('input', () => sidebar.querySelectorAll('.sub-item').forEach(button => {
        button.hidden = !normalize(button.textContent).includes(normalize(search.value));
      }));
      sidebar.before(search);
    }
    enhance();
    let queued = false;
    new MutationObserver(() => {
      if (queued) return;
      queued = true;
      window.setTimeout(() => { queued = false; enhance(); }, 80);
    }).observe(shell, {childList: true, subtree: true});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
  else start();
})();
