(() => {
  if (window.EGMDrawConnection) return;
  const badge = document.createElement('div');
  badge.id = 'draw-connection-status';
  badge.setAttribute('role', 'status');
  badge.setAttribute('aria-live', 'polite');
  badge.style.cssText = 'position:fixed;bottom:12px;left:16px;z-index:20;font-size:12px;font-family:inherit;color:rgba(255,255,255,.55);pointer-events:none;max-width:70vw;text-align:left';
  document.body.append(badge);
  let phase = 'checking', message = '', syncing = false, disposed = false, timer = null, generation = 0;
  const recoveries = new Set(), requests = new Set();
  const failure = () => Object.assign(new Error('ارتباط موقتاً قطع است.'), {connectionFailure:true});
  const notify = () => {
    badge.textContent = phase === 'online' ? 'متصل' : (message || (phase === 'offline' ? 'ارتباط قطع است · اتصال مجدد خودکار' : 'در حال برقراری ارتباط…'));
    badge.dataset.state = phase;
    window.dispatchEvent(new CustomEvent('egmdrawconnectionchange', {detail:{ready:phase === 'online'}}));
  };
  const schedule = delay => {
    clearTimeout(timer);
    if (!disposed) timer = setTimeout(synchronize, delay);
  };
  const disconnect = (reason = '') => {
    generation++;
    phase = 'offline'; message = reason;
    notify(); schedule(4000);
  };
  const request = async (endpoint, body) => {
    const readOnly = body.action === 'state';
    if ((!readOnly && phase !== 'online') || navigator.onLine === false) throw failure();
    const controller = new AbortController(); requests.add(controller);
    const timeout = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch(endpoint, {method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(body)});
      if ([401,403].includes(response.status) || (response.redirected && /(?:login|signin)\.php/i.test(response.url))) {
        disconnect('نشست نیاز به ورود دوباره دارد.'); throw failure();
      }
      if (response.status >= 500 || [408,429].includes(response.status)) { disconnect(); throw failure(); }
      let payload;
      try { payload = await response.json(); } catch {
        // A rejected operation can be replaced with HTML by a hosting proxy.
        // It does not prove that read requests or the session are disconnected.
        if (response.status >= 400 && response.status < 500) {
          throw new Error(`درخواست پذیرفته نشد (${response.status}). دوباره تلاش کنید.`);
        }
        disconnect(); throw failure();
      }
      if (!response.ok || payload.status !== 'ok') throw new Error(payload.message || 'درخواست ناموفق بود.');
      return payload;
    } catch (error) {
      if (error.connectionFailure) throw error;
      if (error.name === 'AbortError' || error instanceof TypeError || navigator.onLine === false) { disconnect(); throw failure(); }
      throw error;
    } finally { clearTimeout(timeout); requests.delete(controller); }
  };
  async function synchronize() {
    if (disposed || syncing || !recoveries.size) { schedule(1500); return; }
    if (navigator.onLine === false) { disconnect(); return; }
    syncing = true;
    const version = generation;
    const recovering = phase !== 'online';
    if (recovering) { phase = 'checking'; message = ''; notify(); }
    try {
      let settled = true;
      // Only read requests are retried. A lost mutation response is resolved from server state.
      for (const recovery of recoveries) {
        if (await recovery() === false) settled = false;
        if (version !== generation || disposed) return;
      }
      if (settled && version === generation) { phase = 'online'; message = ''; notify(); }
    } catch (error) {
      if (!error.connectionFailure) disconnect(error.message || 'در حال بررسی ارتباط…');
    } finally { syncing = false; schedule(phase === 'online' ? 15000 : 4000); }
  }
  const connection = {
    get ready() { return phase === 'online'; },
    request,
    isConnectionError: error => Boolean(error?.connectionFailure),
    register: recovery => { recoveries.add(recovery); schedule(0); return () => recoveries.delete(recovery); },
    check: () => schedule(0)
  };
  window.EGMDrawConnection = connection;
  window.addEventListener('offline', () => { disconnect(); for (const controller of requests) controller.abort(); });
  window.addEventListener('online', () => schedule(0));
  window.addEventListener('focus', () => schedule(0));
  document.addEventListener('visibilitychange', () => { if (!document.hidden) schedule(0); });
  // Keep local tab navigation available while preventing every stage action, including shortcuts.
  document.addEventListener('click', event => {
    if (!connection.ready && event.target.closest('#roll, #confirm, .competition-card, #competition-roll, #competition-confirm, #competition-cancel')) { event.preventDefault(); event.stopImmediatePropagation(); }
  }, true);
  window.addEventListener('pagehide', () => { disposed = true; clearTimeout(timer); for (const controller of requests) controller.abort(); }, {once:true});
  notify();
})();
