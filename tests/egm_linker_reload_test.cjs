const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const base = path.join(__dirname, '../mini apps/Event Guest Manager');
const source = fs.readFileSync(path.join(base,'EGMSetting.js'),'utf8');
const helpers = source.slice(source.indexOf('  function getEl'), source.indexOf('  let settingsLoadErrorMessage'));
const linker = source.slice(source.indexOf('  function initCampaignLinker()'),source.indexOf('  async function initRewardGuide'));
const panel = fs.readFileSync(path.join(base,'EGM Panel.php'),'utf8');
const card = panel.slice(panel.indexOf('<div class="card" id="egm-campaign-linker-card">'),panel.indexOf('</section>',panel.indexOf('<div class="card" id="egm-campaign-linker-card">')));
const target = '/mini%20apps/EGMs/event/RefMonitor.php';
const stored = new Map();
let failList = false;
function state(key,existing=stored.has(key),owned=true) {
 return {path:key,campaign_url:'/campaigns/'+key,target,available:!existing,can_create:!existing,owned_by_egm:existing&&owned,existing:existing?{path:key,target,redirect_type_label:'پنل داور'}:null};
}
(async()=>{
 const browser = await chromium.launch({channel:'msedge',headless:true});
 try {
  const page = await browser.newPage();
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.route('http://egm.test/**',async route=>{
   const req=route.request(),url=new URL(req.url());
   if(url.pathname.endsWith('EGMSetting.js')) return route.fulfill({contentType:'text/javascript',body:helpers+linker+'initCampaignLinker();'});
   if(url.pathname.endsWith('egm_store.php')) {
    const action=url.searchParams.get('action');
    if(action==='list_campaign_links')return route.fulfill({status:failList?503:200,contentType:'application/json',body:JSON.stringify(failList?{status:'error',message:'خطا در دریافت لینک‌ها'}:{status:'ok',data:[...stored.keys()].map(key=>state(key))})});
    const body=JSON.parse(req.postData());assert.equal(body.csrf,'test');
    if(action==='create_campaign_link')stored.set(body.path,true);
    const data=body.path==='occupied'?state('occupied',true,false):state(body.path);
    return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'ok',data})});
   }
   return route.fulfill({contentType:'text/html;charset=utf-8',body:`<meta charset="utf-8"><div class="egm-shell" data-egm-csrf="test">${card}</div><script src="/mini%20apps/EGMs/event/EGMSetting.js"></script>`});
  });
  await page.goto('http://egm.test/panel.php');
  await page.locator('#egm-linker-path').waitFor();
  await page.waitForFunction(()=>!document.querySelector('#egm-linker-path').disabled);
  for(const key of ['ref-a','ref-b']) {
   await page.locator('#egm-linker-path').fill(key);
   await page.locator('#egm-linker-check').click();
   await page.waitForFunction(()=>!document.querySelector('#egm-linker-create').disabled);
   await page.locator('#egm-linker-create').click();
   await page.locator(`#egm-linker-current-body a[href="/campaigns/${key}"]`).waitFor();
  }
  await page.reload();
  await page.locator('#egm-linker-current-body a').first().waitFor();
  assert.equal(await page.locator('#egm-linker-current-body a').count(),2);
  assert.equal(await page.locator('#egm-linker-current-body a').first().getAttribute('href'),'/campaigns/ref-a');
  await page.locator('#egm-linker-path').fill('occupied');
  assert.equal(await page.locator('#egm-linker-current-body a').count(),2);
  await page.locator('#egm-linker-check').click();
  await page.waitForFunction(()=>document.querySelector('#egm-linker-current-body').textContent.includes('اشغال‌شده'));
  assert.equal(await page.locator('#egm-linker-current-body a').count(),3);
  assert.ok(await page.locator('#egm-linker-create').isDisabled());
  await page.locator('#egm-linker-path').fill('another');
  assert.equal(await page.locator('#egm-linker-current-body a').count(),2);
  failList=true;await page.reload();
  await page.waitForFunction(()=>document.querySelector('#egm-linker-status').textContent.includes('خطا'));
  assert.equal(errors.length,0,errors.join('\n'));
  console.log('Saved links load after reload, survive input/checks, avoid duplicate rows, use campaign URLs, and report load failures.');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1});
