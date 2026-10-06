self.addEventListener('push', event => {
  event.waitUntil((async () => {
    let payload;
    try { payload = event.data?.json(); } catch { return; }
    if (!payload?.title || !payload?.url) return;
    const url = new URL(payload.url, self.registration.scope);
    if (url.origin !== self.location.origin || !url.href.startsWith(self.registration.scope)) return;
    const clients = await self.clients.matchAll({type:'window', includeUncontrolled:true});
    const focused = clients.find(client => client.focused && new URL(client.url).pathname === url.pathname);
    if (focused) focused.postMessage({type:'ref-room-ready',payload});
    await self.registration.showNotification(payload.title, {
      body:payload.body,tag:payload.tag,renotify:true,requireInteraction:true,
      icon:new URL('RefMonitor-icon.php',self.registration.scope).href,
      badge:new URL('RefMonitor-icon.php',self.registration.scope).href,
      ...(focused ? {silent:true} : {vibrate:[300,120,300,120,500]}),data:{url:url.href,gameId:payload.game_id,periodCode:payload.period_code,teamId:payload.team_id}
    });
  })());
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil((async () => {
    const data=event.notification.data || {};const url=new URL(data.url || 'RefMonitor.php',self.registration.scope);
    if(url.origin!==self.location.origin || !url.href.startsWith(self.registration.scope))return;
    const clients=await self.clients.matchAll({type:'window',includeUncontrolled:true});
    const client=clients.find(client=>new URL(client.url).pathname===url.pathname);
    if(client){await client.focus();client.postMessage({type:'ref-room-open',payload:data});}
    else await self.clients.openWindow(url.href);
  })());
});
