(() => {
  'use strict';
  const nav = document.querySelector('.sidebar > .nav');
  if (!nav) return;
  const buttons = [...nav.querySelectorAll('.nav-item[data-tab]')];
  const key = 'panel-sidebar-groups:' + (window.__CURRENT_USER_CODE || 'local');
  let saved = {};
  try { saved = JSON.parse(localStorage.getItem(key) || '{}'); } catch {}
  if (!saved || typeof saved !== 'object' || Array.isArray(saved)) saved = {};
  const groups = [];
  function group(id, title, icon, host, items) {
    if (!items.length) return null;
    const details = document.createElement('details');
    details.className = 'panel-nav-group';
    details.dataset.navGroup = id;
    const summary = document.createElement('summary');
    summary.innerHTML = `<span class="nav-icon ri ${icon}" aria-hidden="true"></span><span class="panel-nav-title"></span><span class="panel-nav-chevron ri ri-arrow-down-s-line" aria-hidden="true"></span>`;
    summary.querySelector('.panel-nav-title').textContent = title;
    summary.title = title;
    summary.setAttribute('aria-label', title);
    const body = document.createElement('div');
    body.className = 'panel-nav-body';
    body.append(...items);
    details.append(summary, body);
    host.append(details);
    details.open = saved[id] ?? items.some(item => item.matches('.active') || item.querySelector('.active'));
    details.addEventListener('toggle', () => {
      saved[id] = details.open;
      try { localStorage.setItem(key, JSON.stringify(saved)); } catch {}
    });
    groups.push(details);
    return details;
  }
  const isGeneral = button => ['home', 'users', 'settings', 'devsettings'].includes(button.dataset.tab);
  const egms = buttons.filter(button => button.dataset.tab.startsWith('event-guest-manager'));
  const clubs = buttons.filter(button => button.dataset.tab.startsWith('task-club'));
  const ratings = buttons.filter(button => button.dataset.tab.startsWith('rate-me'));
  const general = buttons.filter(isGeneral);
  const other = buttons.filter(button => !isGeneral(button) && !egms.includes(button) && !clubs.includes(button) && !ratings.includes(button));
  nav.querySelectorAll('.nav-separator').forEach(node => node.remove());
  group('general', 'عمومی و تنظیمات', 'ri-settings-3-line', nav, general);
  const featureItems = document.createElement('div');
  function managementFirst(items, tab) {
    return [...items.filter(button => button.dataset.tab === tab), ...items.filter(button => button.dataset.tab !== tab)];
  }
  group('egm', 'مدیریت مهمان رویداد', 'ri-user-star-line', featureItems, managementFirst(egms, 'event-guest-manager-creator'));
  group('clubs', 'باشگاه‌های تعاملی', 'ri-group-line', featureItems, managementFirst(clubs, 'task-club-creator'));
  group('ratings', 'ارزیابی‌ها', 'ri-star-line', featureItems, managementFirst(ratings, 'rate-me-creator'));
  featureItems.append(...other);
  if (featureItems.children.length) group('features', 'امکانات', 'ri-apps-line', nav, [...featureItems.children]);
  function refreshGroups() {
    groups.forEach(details => {
      details.hidden = ![...details.querySelectorAll('.nav-item')].some(button => !button.hidden);
    });
  }
  function revealActive() {
    const active = nav.querySelector('.nav-item.active:not([hidden])');
    for (let parent = active?.parentElement; parent && parent !== nav; parent = parent.parentElement) {
      if (parent instanceof HTMLDetailsElement) parent.open = true;
    }
  }
  window.addEventListener('panel-sidebar-visibility', event => {
    const button = buttons.find(button => button.dataset.tab === event.detail?.tabId);
    if (!button) return;
    button.hidden = !event.detail.visible;
    button.dataset.sidebarVisible = event.detail.visible ? '1' : '0';
    refreshGroups();
  });
  new MutationObserver(revealActive).observe(nav, {subtree:true, attributes:true, attributeFilter:['class']});
  refreshGroups();
  revealActive();
})();
