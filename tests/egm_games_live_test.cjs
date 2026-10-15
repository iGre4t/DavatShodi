const assert = require('node:assert/strict');
const {once} = require('node:events');
const http = require('node:http');
const {createRequire} = require('node:module');
const {WebSocket} = createRequire(require.resolve('../services/egm-games-live/server.cjs'))('ws');
const {createLiveServer} = require('../services/egm-games-live/server.cjs');
(async () => {
  let revision = 'one', revoked = false, loads = 0;
  const upstream = http.createServer((req,res) => {
    const url = new URL(req.url,'http://php.test');
    assert.equal(url.searchParams.get('action'),'monitor_snapshot');
    assert.equal(url.searchParams.get('period_code'),'01');
    assert.equal(req.headers.cookie,'EGMSESSID=valid'); loads++;
    if (revoked) {res.writeHead(403);res.end('Forbidden');return;}
    res.writeHead(200,{'Content-Type':'application/json'});
    res.end(JSON.stringify({status:'ok',revision,server_time:new Date().toISOString(),games:[{id:'g',teams:[]}],enabled:['g']}));
  });
  upstream.listen(0,'127.0.0.1'); await once(upstream,'listening');
  const server = createLiveServer({upstream:`http://127.0.0.1:${upstream.address().port}`,publicOrigin:'https://panel.test',intervalMs:30});
  server.listen(0,'127.0.0.1'); await once(server,'listening');
  const root=`ws://127.0.0.1:${server.address().port}/mini%20apps/Event%20Guest%20Manager/egm-games-live`;
  const query='?endpoint='+encodeURIComponent('/mini%20apps/Event%20Guest%20Manager/games.php')+'&period_code=01';
  const headers={Origin:'https://panel.test',Cookie:'EGMSESSID=valid'};
  const clients=[];
  const rejected = (url, requestHeaders, status) => new Promise((resolve,reject) => {
    const client=new WebSocket(url,{headers:requestHeaders});clients.push(client);
    client.on('open',()=>reject(new Error('Unauthorized socket opened')));
    client.on('unexpected-response',(_req,response)=>{assert.equal(response.statusCode,status);response.resume();client.terminate();resolve();});
    client.on('error',()=>{});
  });
  try {
    await rejected(root+query,{...headers,Origin:'https://evil.test'},403);
    await rejected(root+query,{Origin:headers.Origin},403);
    await rejected(root+'?endpoint='+encodeURIComponent('https://evil.test/games.php')+'&period_code=01',headers,400);
    await rejected(root+'?endpoint='+encodeURIComponent('/mini apps/EGMs/../games.php')+'&period_code=01',headers,400);
    await rejected(root+query.replace('period_code=01','period_code=bad'),headers,400);
    assert.equal(loads,0,'Invalid upgrades reached PHP');
    const client=new WebSocket(root+query,{headers});clients.push(client);
    const messages=[];client.on('message',message=>messages.push(JSON.parse(message)));
    await once(client,'open');
    const waitFor = async predicate => { const deadline=Date.now()+3000;while(!predicate()){if(Date.now()>deadline)throw new Error('Timed out');await new Promise(resolve=>setTimeout(resolve,10));} };
    await waitFor(()=>messages.some(message=>message.type==='snapshot'&&message.snapshot.revision==='one'));
    await waitFor(()=>messages.some(message=>message.type==='heartbeat'));
    revision='two';await waitFor(()=>messages.some(message=>message.type==='snapshot'&&message.snapshot.revision==='two'));
    revoked=true;const [code]=await once(client,'close');assert.equal(code,1008);
    assert.equal(messages.filter(message=>message.type==='snapshot').length,2,'Unchanged state broadcast repeatedly');
    console.log('PASS: authenticated WebSocket snapshots, revision changes, heartbeats, origin/path/cookie validation and session revocation');
  } finally {for(const client of clients)client.terminate();server.close();server.closeAllConnections();upstream.close();upstream.closeAllConnections();}
})().catch(error=>{console.error(error);process.exitCode=1;});
