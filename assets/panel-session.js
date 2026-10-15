(() => {
  if (!window.PANEL_SESSION) return;
  const endpoint = new URL('api/panel-session.php', location.href);
  const login = new URL('login.php?session=expired', location.href);
  const logout = new URL('logout.php', location.href);
  const originalFetch = window.fetch.bind(window);
  let state = window.PANEL_SESSION, deadline = 0, warning = 120, leaving = false, checking = null, renewing = false, dialog = null;
  const setState = value => {
    state = value;
    deadline = Date.now() + (value.expires_at - value.server_now) * 1000;
    warning = value.warning_seconds || 120;
    if (remaining() > 0) document.body.style.visibility = '';
    if (remaining() > warning && dialog?.open) dialog.close();
  };
  const remaining = () => Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
  const leave = (explicit = false) => {
    if (leaving) return; leaving = true;
    document.documentElement.style.visibility = 'hidden';
    try { localStorage.setItem('panel-session-logout', String(Date.now())); } catch {}
    location.replace(explicit ? logout.href : login.href);
  };
  const expiredResponse = response => response.headers.get('X-Panel-Session-Expired') === '1' || response.status === 401 || (response.redirected && new URL(response.url).pathname === login.pathname);
  window.fetch = async (...args) => {
    const response = await originalFetch(...args);
    if (response.url && new URL(response.url).origin === location.origin && expiredResponse(response)) leave();
    return response;
  };
  const xhrSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function(...args) {
    this.addEventListener('load', () => {
      if (!this.responseURL || new URL(this.responseURL).origin !== location.origin) return;
      if (this.status === 401 || this.getResponseHeader('X-Panel-Session-Expired') === '1' || new URL(this.responseURL).pathname === login.pathname) leave();
    });
    return xhrSend.apply(this, args);
  };
  const check = () => {
    if (checking || leaving) return checking;
    checking = (async () => {
      try {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 5000);
        let response;
        try { response = await originalFetch(endpoint, {credentials:'same-origin', cache:'no-store',signal:controller.signal}); }
        finally { clearTimeout(timeout); }
        if (expiredResponse(response)) { leave(); return; }
        if (!response.ok) { if (remaining() === 0) leave(); return; }
        const value = await response.json();
        if (!value.authenticated) { leave(); return; }
        setState(value);
      } catch {
        // An unavailable server is not evidence of logout before the known deadline.
        if (remaining() === 0) leave();
      } finally { checking = null; }
    })();
    return checking;
  };
  const showWarning = () => {
    if (!dialog) {
      dialog = document.createElement('dialog'); dialog.dir = 'rtl'; dialog.className = 'card';
      dialog.setAttribute('aria-labelledby', 'panel-session-title');
      dialog.style.cssText = 'width:min(440px,calc(100vw - 32px));border:1px solid #e1e6ef;border-radius:24px;padding:28px;text-align:right;color:inherit';
      dialog.innerHTML = '<h3 id="panel-session-title">نشست شما رو به پایان است</h3><p>برای ادامه کار، نشست خود را تمدید کنید.</p><p data-session-countdown aria-live="off"></p><p data-session-status role="status"></p><div style="display:flex;gap:12px"><button type="button" class="btn primary" data-session-continue>ادامه</button><button type="button" class="btn ghost" data-session-leave>خروج</button></div>';
      dialog.addEventListener('cancel', event => event.preventDefault());
      dialog.querySelector('[data-session-leave]').onclick = () => leave(true);
      dialog.querySelector('[data-session-continue]').onclick = async () => {
        if (renewing || leaving) return; renewing = true;
        const button = dialog.querySelector('[data-session-continue]'), status = dialog.querySelector('[data-session-status]');
        button.disabled = true; status.textContent = 'در حال تمدید…';
        try {
          const response = await originalFetch(endpoint, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'continue',csrf:state.csrf})});
          if (expiredResponse(response)) { leave(); return; }
          const value = await response.json();
          if (!response.ok || !value.authenticated) throw new Error(value.message || 'تمدید انجام نشد. دوباره تلاش کنید.');
          setState(value); status.textContent = ''; dialog.close();
          try { localStorage.setItem('panel-session-renewed', String(Date.now())); } catch {}
        } catch(error) { status.textContent = 'تمدید انجام نشد. اتصال را بررسی کرده و دوباره تلاش کنید.'; }
        finally { renewing = false; button.disabled = false; }
      };
      document.body.append(dialog);
    }
    if (!dialog.open) dialog.showModal();
    dialog.querySelector('[data-session-countdown]').textContent = `خروج خودکار تا ${remaining()} ثانیه دیگر`;
  };
  const tick = () => {
    if (leaving) return;
    if (remaining() === 0) { document.body.style.visibility = 'hidden'; check(); return; }
    if (remaining() <= warning) showWarning();
  };
  if (!state.authenticated) { leave(); return; }
  setState(state); tick(); check();
  setInterval(tick, 1000); setInterval(check, 15000);
  window.addEventListener('storage', event => {
    if (event.key === 'panel-session-renewed') check();
    if (event.key === 'panel-session-logout') leave();
  });
  document.addEventListener('click', event => {
    const link = event.target.closest?.('a[href]');
    if (link && new URL(link.href).origin === location.origin && new URL(link.href).pathname === logout.pathname) { event.preventDefault(); leave(true); }
  });
  window.addEventListener('pageshow', event => {
    if (event.persisted) {
      document.body.style.visibility = 'hidden';
      if (leaving) { location.replace(login.href); return; }
    }
    check();
  });
  window.addEventListener('focus', check);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { tick(); check(); } });
})();
