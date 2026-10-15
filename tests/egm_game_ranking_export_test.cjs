const assert=require('node:assert/strict');
const fs=require('node:fs');
const {chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});
 try{
  const page=await browser.newPage({acceptDownloads:true});const exports=[];let fail=false;
  await page.route('http://egm.test/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/panel')return route.fulfill({contentType:'text/html; charset=utf-8',body:'<button data-game-ranking-export="/period_exports.php?period_code=01">خروجی رتبه‌بندی</button>'});
   assert.equal(url.searchParams.get('period_code'),'01');assert.equal(url.searchParams.get('type'),'game_rankings');
   if(url.searchParams.get('options')==='1')return route.fulfill({contentType:'application/json',body:JSON.stringify({games:[{id:'game1',name:'بازی دیجیتال',gender_mode:'separated'}]})});
   exports.push(Object.fromEntries(url.searchParams));
   if(fail)return route.fulfill({status:422,contentType:'text/plain; charset=utf-8',body:'تیمی برای رتبه‌بندی ثبت نشده است.'});
   const doc=url.searchParams.get('format')==='presenter';
   return route.fulfill({contentType:doc?'application/vnd.openxmlformats-officedocument.wordprocessingml.document':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',body:fs.readFileSync('outputs/ranking-preview.'+(doc?'docx':'xlsx'))});
  });
  await page.goto('http://egm.test/panel');await page.addScriptTag({content:fs.readFileSync('assets/egm-game-ranking-export.js','utf8')});
  await page.getByRole('button',{name:'خروجی رتبه‌بندی',exact:true}).click();const dialog=page.getByRole('dialog');await dialog.locator('[name=game_id]').selectOption('game1');
  await dialog.locator('[name=rank_to]').fill('0');await dialog.locator('[data-ranking-next]').click();assert.equal(await dialog.locator('[data-ranking-step="2"]').isVisible(),false,'Invalid rank cannot proceed');
  await dialog.locator('[name=rank_to]').fill('5');await dialog.locator('[data-ranking-next]').click();assert.equal(await dialog.locator('[data-ranking-step="2"]').isVisible(),true);
  let downloadPromise=page.waitForEvent('download');await dialog.getByRole('button',{name:'دریافت فایل'}).click();assert.match((await downloadPromise).suggestedFilename(),/\.xlsx$/);await dialog.getByRole('status').filter({hasText:'فایل آماده شد.'}).waitFor();
  await dialog.locator('[name=format]').selectOption('presenter');downloadPromise=page.waitForEvent('download');await dialog.getByRole('button',{name:'دریافت فایل'}).click();assert.match((await downloadPromise).suggestedFilename(),/\.docx$/);await page.waitForFunction(()=>!document.querySelector('[type=submit]').disabled);
  assert.equal(exports[1].rank_to,'5');assert.equal(exports[1].game_id,'game1');assert.equal(exports[1].format,'presenter');
  fail=true;await dialog.getByRole('button',{name:'دریافت فایل'}).click();await dialog.getByRole('status').filter({hasText:'تیمی برای رتبه‌بندی ثبت نشده است.'}).waitFor();assert.equal(page.url(),'http://egm.test/panel');
  await dialog.getByRole('button',{name:'بستن'}).click();assert.equal(await page.getByRole('dialog').count(),0);
  console.log('PASS: sequential dialog, rank validation, period/game parameters, XLSX/DOCX downloads and inline errors without reload');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});

