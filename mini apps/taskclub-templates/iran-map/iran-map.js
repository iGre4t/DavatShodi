(() => {
  const slide = document.getElementById('tc-iran-map-slide');
  const opener = document.getElementById('tc-open-iran-map');
  const back = document.getElementById('tc-iran-map-back');
  const svg = slide?.querySelector('svg');
  const status = document.getElementById('tc-iran-map-status');
  if (!slide || !opener || !back || !svg) return;
  let previousFocus, siblings = [], busy = false, post, canOpen;
  const shown = new Set();
  const open = () => {
    if (!slide.hidden) return;
    previousFocus = document.activeElement;
    siblings = Array.from(slide.parentElement.children)
      .filter(el => el !== slide && !['SCRIPT', 'STYLE'].includes(el.tagName))
      .map(el => [el, el.inert]);
    siblings.forEach(([el]) => { el.inert = true; });
    slide.hidden = false;
    opener.setAttribute('aria-expanded', 'true');
    back.focus();
  };
  const close = () => {
    slide.hidden = true;
    siblings.forEach(([el, inert]) => { el.inert = inert; });
    siblings = [];
    opener.setAttribute('aria-expanded', 'false');
    const focus = previousFocus?.isConnected && previousFocus.getClientRects().length ? previousFocus : opener;
    focus.focus();
  };
  const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
  const visible = () => {
    const box = svg.getBoundingClientRect();
    return !slide.hidden && document.visibilityState === 'visible' && box.width > 0 && box.height > 0
      && box.top >= 0 && box.bottom <= innerHeight && box.left >= 0 && box.right <= innerWidth;
  };
  const waitForMap = async () => {
    await Promise.all(slide.getAnimations().map(animation => animation.finished.catch(() => {})));
    while (!slide.hidden) {
      if (visible()) {
        await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
        await delay(400);
        if (visible()) return true;
      }
      await delay(150);
    }
    return false;
  };
  const draw = (dot, animate) => {
    if (shown.has(dot.taskId)) return;
    const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    circle.setAttribute('cx', dot.x);
    circle.setAttribute('cy', dot.y);
    circle.setAttribute('r', '4');
    circle.classList.add('tc-iran-map-dot');
    circle.dataset.taskId = dot.taskId;
    if (animate) circle.classList.add('tc-iran-map-dot-new');
    svg.append(circle);
    shown.add(dot.taskId);
  };
  const sync = async (force = false) => {
    if (force) open();
    if (!post || busy || document.visibilityState !== 'visible') return;
    if (!force && slide.hidden && canOpen && !canOpen()) return;
    busy = true;
    try {
      const payload = await post({action: 'iran_map_state'});
      const dots = Array.isArray(payload.dots) ? payload.dots : [];
      dots.filter(dot => dot.revealed).forEach(dot => draw(dot, false));
      const pending = dots.filter(dot => !dot.revealed);
      if (pending.length && (force || !canOpen || canOpen() || !slide.hidden)) open();
      for (const dot of pending) {
        if (slide.hidden || !await waitForMap()) break;
        draw(dot, true);
        // Acknowledge only after the dot has been visible, preserving interrupted reveals.
        await delay(650);
        if (!visible()) break;
        await post({action: 'iran_map_state', acknowledged: [dot.taskId]});
      }
      status.textContent = '';
    } catch (error) {
      status.textContent = 'دریافت نقشه ناموفق بود؛ دوباره تلاش می‌کنیم.';
    } finally { busy = false; }
  };
  opener.addEventListener('click', () => { open(); void sync(true); });
  back.addEventListener('click', close);
  slide.addEventListener('keydown', event => {
    if (event.key === 'Escape') { event.preventDefault(); close(); }
    if (event.key === 'Tab') { event.preventDefault(); back.focus(); }
  });
  window.tcIranMap = {
    complete: () => sync(true),
    check: () => sync(),
    configure: (request, ready) => {
      post = request; canOpen = ready;
      void sync();
      setInterval(() => { void sync(); }, 10000);
      document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') void sync(); });
    }
  };
})();
