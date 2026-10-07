const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const {chromium}=require('playwright');
const source=fs.readFileSync(path.join(__dirname,'../mini apps/Event Guest Manager/RefMonitor.php'),'utf8');
const script=source.slice(source.indexOf('  // The same MCI silhouette'),source.indexOf("  const password = document.getElementById('ref-password');"));
const request=source.slice(source.indexOf('  const request = async'),source.indexOf('  const addPrizeHint ='));
const css=[...source.matchAll(/<style nonce=.*>([\s\S]*?)<\/style>/g)].map(match=>match[1]).join('\n');
const html=`<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><style>${css}</style><button id="underlying">ادامه</button><form method="post" id="login"><input name="action" value="login" type="hidden"><button type="submit">ورود</button></form><form method="post" id="logout" action="RefMonitor.php?logout=1"><input name="action" value="logout" type="hidden"><button type="submit">خروج</button></form><dialog id="confirmation"><button>تأیید</button></dialog><script>(()=>{${script}const csrf='test',activePeriod='001',activeGame={id:'1234567890abcdef'};${request}window.testRequest=request;window.testTransition=()=>withLoading(async()=>{});})();</script></html>`;
(async()=>{
 const browser=await chromium.launch({channel:'msedge',headless:true});
 try {
  const page=await browser.newPage({viewport:{width:390,height:844}});let mode='fast',requested=false,lastPostUrl='';
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.route('http://loader.test/**',async route=>{
   if(route.request().method()!=='POST')return route.fulfill({contentType:'text/html;charset=utf-8',body:html});
   requested=true;lastPostUrl=route.request().url();
   if(mode==='login')return route.fulfill({contentType:'text/html',body:'<p class="login-hint" role="alert">نام کاربری یا رمز عبور معتبر نیست.</p>'});
   if(mode==='slow')await new Promise(resolve=>setTimeout(resolve,2600));
   if(mode==='network')return route.abort();
   if(mode==='invalid')return route.fulfill({contentType:'text/html',body:'<!DOCTYPE html><h1>Error</h1>'});
   return route.fulfill({contentType:'application/json',status:mode==='denied'?403:200,body:JSON.stringify(mode==='denied'?{status:'error',message:'دسترسی ندارید.'}:{status:'ok',loaded:true})});
  });
  await page.goto('http://loader.test/RefMonitor.php');
  assert.equal(await page.locator('#ref-loader').evaluate(el=>el.open),true);
  await page.waitForFunction(()=>!document.querySelector('#ref-loader').open);
  const fast=await page.evaluate(async()=>{const started=performance.now();const data=await testRequest('list_teams');return {elapsed:performance.now()-started,data};});
  assert.ok(fast.elapsed>=1100);assert.equal(fast.data.loaded,true);
  mode='slow';requested=false;
  await page.evaluate(()=>{window.pending=testRequest('team_detail');});
  await page.waitForFunction(()=>document.querySelector('#ref-loader').open);
  await page.waitForTimeout(1600);assert.equal(requested,true);
  assert.equal(await page.locator('#ref-loader').evaluate(el=>el.open),true);
  assert.equal(await page.locator('.ref-loader-path').evaluate(el=>getComputedStyle(el).animationIterationCount),'infinite');
  await page.evaluate(()=>pending);assert.equal(await page.locator('#ref-loader').evaluate(el=>el.open),false);
  for(mode of ['invalid','network','denied']){
   await page.evaluate(()=>testRequest('list_teams').catch(error=>error.message));
   assert.equal(await page.locator('#ref-loader').evaluate(el=>el.open&&el.classList.contains('failed')),true);
   assert.equal(await page.locator('.ref-loader-path').evaluate(el=>getComputedStyle(el).animationName),'none');
   assert.ok((await page.locator('.ref-loader-message').textContent()).length>0);
   await page.screenshot({path:path.join(require('node:os').tmpdir(),`ref-loader-${mode}.png`)});
   await page.locator('[data-loader-close]').click();
  }
  mode='fast';
  await page.evaluate(()=>testRequest('period_status',{}, {silent:true}));
  assert.equal(await page.locator('#ref-loader').evaluate(el=>el.open),false);
  await page.evaluate(()=>{document.querySelector('#confirmation').showModal();window.pending=testRequest('submit_score');});
  assert.equal(await page.locator('#ref-loader').evaluate(el=>el.open),true);
  await page.evaluate(()=>pending);assert.equal(await page.locator('#confirmation').evaluate(el=>el.open),true);
  await page.evaluate(()=>document.querySelector('#confirmation').close());
  await page.emulateMedia({reducedMotion:'reduce'});
  const reduced=await page.evaluate(async()=>{const started=performance.now();await testTransition();return performance.now()-started;});
  assert.ok(reduced>=180&&reduced<1000);
  mode='login';requested=false;
  await page.locator('#login button').click();await page.waitForFunction(()=>document.querySelector('#ref-loader').classList.contains('failed'));
  assert.equal(requested,true);assert.match(await page.locator('.ref-loader-message').textContent(),/نام کاربری/);
  assert.equal(lastPostUrl,'http://loader.test/RefMonitor.php','Login must post to RefMonitor, not the hidden action field');
  await page.locator('[data-loader-close]').click();
  await page.locator('#logout button').click();await page.waitForFunction(()=>document.querySelector('#ref-loader').classList.contains('failed'));
  assert.equal(lastPostUrl,'http://loader.test/RefMonitor.php?logout=1','Logout must use the action attribute rather than the hidden field');
  await page.locator('[data-loader-close]').click();
  assert.equal(errors.length,0,errors.join('\n'));
  console.log('MCI boot/fast/slow loaders, immediate fetch, complete animation, JSON/network/403 failures, recovery, silent polling, confirmation stacking and reduced motion passed.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1});
