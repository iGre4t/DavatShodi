const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
const root=path.resolve(__dirname,'../mini apps/Event Guest Manager');
const source=fs.readFileSync(path.join(root,'period_draw.php'),'utf8');
const css=source.match(/<style[^>]*>([\s\S]*?)<\/style>/)[1];
let markup=source.slice(source.indexOf('<body>')+6,source.indexOf('  <script',source.indexOf('<body>')));
markup=markup.replace(/<\?php for \(\$card[\s\S]*?<\?php endfor; \?>/,Array.from({length:9},(_,i)=>`<button type="button" class="competition-card" data-card="${i+1}"><span class="competition-card-inner"><span class="competition-card-face competition-card-front">${i+1}</span><span class="competition-card-face competition-card-back"></span></span></button>`).join(''));
markup=markup.replace(/<\?=[\s\S]*?\?>/g,'test').replace(/<\?php[\s\S]*?\?>/g,'').replace('data-endpoint="test"','data-endpoint="competition_prizes.php"');
const inlineStart=source.indexOf('    (() => {');
const inline=source.slice(inlineStart,source.indexOf('  </script>',inlineStart));
const script=inline.replace(/<\?=([\s\S]*?)\?>/g,(_match,expression)=>{
  if(expression.includes('$periodCode'))return '"01"';
  if(expression.includes('$levelId'))return '"draw1"';
  if(expression.includes('$csrf'))return '"test"';
  if(expression.includes('$limit'))return '4';
  if(expression.includes('$drawCanRun'))return 'true';
  if(expression.includes('potSettings'))return 'false';
  if(expression.includes('general_draws.php'))return '"general_draws.php?runtime=1"';
  throw new Error('Unhandled PHP expression '+expression);
});
(async()=>{
  const browser=await chromium.launch({channel:'msedge',headless:true});
  try {
    const context=await browser.newContext();const page=await context.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
    let fail=false,rejectReset=false,loseAction=null,pending=null,drawPending=null,drawWinners=[],calls=[];
    let confirmed=[1,2,3].map(card=>({card,prizeName:'Prize '+card,rank:1}));
    const participant={key:'1',code:'0001',fullName:'Winner One',workId:'1001'};
    await page.route('http://stage.test/**',async route=>{
      if(route.request().resourceType()==='document')return route.fulfill({contentType:'text/html',body:`<html lang="fa" dir="rtl"><meta charset="utf-8"><style>${css}</style><body>${markup}</body></html>`});
      const input=route.request().postDataJSON();
      if (!input) return route.fulfill({body:''});
      calls.push({url:route.request().url(),...input});
      if(fail)return route.abort('failed');
      if(rejectReset && input.action==='reset')return route.fulfill({status:422,contentType:'text/html',body:'<!DOCTYPE html><title>Proxy error</title>'});
      const competition=route.request().url().includes('competition_prizes');
      if(competition){
        if(input.action==='roll')pending={card:input.card,token:'saved-token',prizeName:'Fourth Prize'};
        if(input.action==='confirm'){confirmed.push(pending);pending=null;}
      }else{
        if(input.action==='roll')drawPending=participant;
        if(input.action==='confirm'){drawWinners.push(participant);drawPending=null;}
      }
      if(input.action===loseAction){loseAction=null;fail=true;return route.abort('failed');}
      const state=competition ? {status:'ok',confirmed,pending,nextRank:1,queue:[1,1,1,1,1],resetVersion:'v1'} : {status:'ok',winners:drawWinners,eligibleParticipants:drawWinners.length?[]:[participant],eligibleCount:drawWinners.length?0:1,pendingParticipant:drawPending};
      await route.fulfill({json:state});
    });
    await page.goto('http://stage.test/');
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'draw-connection.js'),'utf8')});
    await page.addScriptTag({content:script});
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'competition-prizes.js'),'utf8')});
    assert.deepEqual(errors,[]);
    const connected=()=>page.waitForFunction(()=>window.EGMDrawConnection.ready);
    await connected();await page.locator('#competition-tab').click();await page.locator('[data-card="4"]').click();
    assert.equal(await page.locator('#competition-history').locator('.competition-history-item').count(),3);
    rejectReset=true;await page.keyboard.press('Shift+Delete');
    await page.waitForFunction(()=>document.getElementById('competition-status').textContent.includes('422'));
    assert.equal(await page.evaluate(()=>window.EGMDrawConnection.ready),true,'Rejected reset disconnected the draw');
    assert.equal(await page.locator('#competition-roll').isEnabled(),true,'Rejected reset locked roll');
    assert.equal(await page.locator('[data-card="5"]').isEnabled(),true,'Rejected reset locked card selection');
    rejectReset=false;
    await context.setOffline(true);await page.waitForFunction(()=>!window.EGMDrawConnection.ready);
    const prior=calls.length;await page.keyboard.press('Shift+5');await page.keyboard.press('Enter');await page.keyboard.press('Space');await page.keyboard.press('Shift+Delete');
    assert.equal(calls.length,prior,'Offline shortcuts made requests');
    assert.equal(await page.locator('[data-card="4"]').evaluate(card=>card.classList.contains('selected')),true,'Selected card was lost');
    assert.equal(await page.locator('#competition-roll').isDisabled(),true);
    assert.equal(await page.locator('#competition-history .competition-history-item').count(),3);
    await context.setOffline(false);await connected();
    loseAction='roll';await page.locator('#competition-roll').click();await page.waitForFunction(()=>!window.EGMDrawConnection.ready);
    assert.equal(await page.locator('#competition-status').textContent(),'');
    fail=false;await connected();assert.equal(await page.locator('[data-card="4"]').evaluate(card=>card.classList.contains('winner')),true,'Lost roll response was not reconciled');
    loseAction='confirm';await page.locator('#competition-confirm').click();await page.waitForFunction(()=>!window.EGMDrawConnection.ready);
    fail=false;await connected();assert.equal(await page.locator('#competition-history .competition-history-item').count(),4);
    assert.equal(calls.filter(call=>call.url.includes('competition_prizes')&&call.action==='confirm').length,1,'Lost confirmation was replayed');
    await page.locator('#draw-tab').click();loseAction='roll';await page.locator('#roll').click();await page.waitForFunction(()=>!window.EGMDrawConnection.ready);
    fail=false;await connected();assert.equal(await page.locator('#winner-name').textContent(),'Winner One');assert.equal(await page.locator('#confirm').isEnabled(),true);
    loseAction='confirm';await page.locator('#confirm').click();await page.waitForFunction(()=>!window.EGMDrawConnection.ready);
    fail=false;await connected();assert.equal(await page.locator('#winner-count').textContent(),'1');assert.equal(await page.locator('#confirm').isDisabled(),true);
    assert.equal(calls.filter(call=>!call.url.includes('competition_prizes')&&call.action==='confirm').length,1);
    fail=true;await page.waitForFunction(()=>!window.EGMDrawConnection.ready);
    assert.equal(await page.locator('#winner-count').textContent(),'1','Idle connection check erased saved winners');
    fail=false;await connected();
    assert.ok(!(await page.locator('body').innerText()).includes('Failed to fetch'),'Raw network error reached wall screen');
    assert.deepEqual(errors,[]);assert.equal(page.url(),'http://stage.test/#draw','Page reloaded or navigated during recovery');
    console.log('PASS: offline controls/shortcuts, automatic recovery, preserved cards/history, lost roll/confirm reconciliation for both draws, no duplicate mutations or raw stage errors');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
