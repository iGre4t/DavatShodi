'use strict';
const http = require('node:http');
const {WebSocketServer, WebSocket} = require('ws');

function createLiveServer({upstream, publicOrigin, intervalMs = 1000, fetchSnapshot} = {}) {
  const base = new URL(upstream);
  const origin = new URL(publicOrigin).origin;
  if (!['http:', 'https:'].includes(base.protocol)) throw new Error('Invalid upstream');
  const server = http.createServer((req,res) => { res.writeHead(req.url === '/health' ? 200 : 404); res.end(req.url === '/health' ? 'ok' : 'Not found'); });
  const wss = new WebSocketServer({noServer:true, maxPayload:1024, perMessageDeflate:false});
  let pending = 0;
  const load = fetchSnapshot || (async (url,cookie) => {
    const response = await fetch(url, {headers:{Cookie:cookie,Accept:'application/json'},redirect:'manual',signal:AbortSignal.timeout(8000)});
    if ([301,302,303,307,308,401,403].includes(response.status)) throw Object.assign(new Error('Session expired'), {auth:true});
    if (!response.ok) throw new Error('Snapshot unavailable');
    const data = await response.json();
    if (data.status !== 'ok' || !Array.isArray(data.games) || !data.revision) throw new Error('Invalid snapshot');
    return data;
  });
  server.on('upgrade', async (req,socket,head) => {
    socket.on('error', () => {});
    let counted = false;
    const reject = status => { if (!socket.destroyed) socket.end(`HTTP/1.1 ${status}\r\nConnection: close\r\n\r\n`); };
    try {
      if (req.headers.origin !== origin || !req.headers.cookie) return reject('403 Forbidden');
      if (wss.clients.size + pending >= 100) return reject('503 Service Unavailable');
      const query = new URL(req.url, origin);
      if (!query.pathname.endsWith('/egm-games-live')) return reject('404 Not Found');
      const endpoint = query.searchParams.get('endpoint') || '';
      const decoded = decodeURIComponent(endpoint);
      if (!/^\/(?:[A-Za-z0-9_-]+\/)*mini apps\/(?:Event Guest Manager|EGMs\/[^/\\?#]+)\/games\.php$/.test(decoded) || decoded.split('/').some(part => part === '.' || part === '..')) return reject('400 Bad Request');
      const period = query.searchParams.get('period_code') || '';
      if (!/^[0-9]{1,20}$/.test(period)) return reject('400 Bad Request');
      const url = new URL(endpoint, base);
      if (url.origin !== base.origin) return reject('400 Bad Request');
      url.searchParams.set('action', 'monitor_snapshot');
      url.searchParams.set('period_code', period);
      pending++; counted = true;
      const snapshot = await load(url,req.headers.cookie);
      if (socket.destroyed) return;
      wss.handleUpgrade(req,socket,head,ws => {
        let revision = snapshot.revision, closed = false, timer;
        ws.on('error', () => ws.close());
        ws.send(JSON.stringify({type:'snapshot',snapshot}));
        const poll = async () => {
          if (closed || ws.readyState !== WebSocket.OPEN) return;
          try {
            // Revalidate the user's session and period permissions on every read.
            const next = await load(url,req.headers.cookie);
            if (closed || ws.readyState !== WebSocket.OPEN) return;
            if (ws.bufferedAmount > 2 * 1024 * 1024) return ws.close(1013, 'Slow connection');
            if (next.revision !== revision) { revision = next.revision; ws.send(JSON.stringify({type:'snapshot',snapshot:next})); }
            else ws.send(JSON.stringify({type:'heartbeat',server_time:next.server_time}));
          } catch (error) {
            if (ws.readyState === WebSocket.OPEN) {
              ws.send(JSON.stringify({type:'error',message:error.auth ? 'نشست یا دسترسی شما پایان یافته است.' : 'اتصال زنده موقتاً قطع شده است.'}));
              ws.close(error.auth ? 1008 : 1011);
            }
          }
          if (!closed) { timer = setTimeout(poll,intervalMs); timer.unref(); }
        };
        timer = setTimeout(poll,intervalMs); timer.unref();
        ws.on('close', () => { closed = true; clearTimeout(timer); });
      });
    } catch (error) { reject(error.auth ? '403 Forbidden' : '502 Bad Gateway'); }
    finally { if (counted) pending--; }
  });
  server.on('close', () => { for (const ws of wss.clients) ws.terminate(); wss.close(); });
  return server;
}

module.exports = {createLiveServer};
if (require.main === module) {
  if (!process.env.EGM_UPSTREAM_URL || !process.env.EGM_PUBLIC_ORIGIN) throw new Error('Set EGM_UPSTREAM_URL and EGM_PUBLIC_ORIGIN');
  const server = createLiveServer({upstream:process.env.EGM_UPSTREAM_URL,publicOrigin:process.env.EGM_PUBLIC_ORIGIN});
  server.listen(Number(process.env.PORT || 8091), '127.0.0.1', () => console.log('EGM game monitor listening on localhost'));
  for (const signal of ['SIGINT','SIGTERM']) process.on(signal, () => { server.close(); server.closeAllConnections(); });
}
