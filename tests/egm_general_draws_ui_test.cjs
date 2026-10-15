const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require('playwright');
const root=path.resolve(__dirname,'..');
(async()=>{
 const browser=await chromium.launch({channel:'msedge',headless:true});
 try{
  const page=await browser.newPage();let draws=[],writes=[];
  const panel=fs.readFileSync(path.join(root,'mini apps/Event Guest Manager/EGM Panel.php'),'utf8');
  const markup=panel.match(/<section data-prize-content="draws"[\s\S]*?<\/section>/)[0];
  await page.route('http://egm.test/**',async route=>{
   if(route.request().resourceType()==='document')return route.fulfill({contentType:'text/html',body:`<div class="egm-shell" data-egm-csrf="test"><div class="sub-pane"><button data-prize-pane="inventory">انبار جوایز</button><button data-prize-pane="draws">قرعه‌کشی‌های دائمی</button><section data-prize-content="inventory">Inventory</section>${markup}</div><select id="egm-linker-destination"><option value="refmonitor">پنل داور</option></select></div>`});
   const input=route.request().postDataJSON();
   if(input.action==='create'){writes.push(input);draws=[{...input,id:'abcdef0123456789abcdef0123456789'}];}
   return route.fulfill({json:{status:'ok',draws}});
  });
  await page.goto('http://egm.test/panel.php');
  await page.addScriptTag({content:fs.readFileSync(path.join(root,'assets/egm-general-draws.js'),'utf8')});
  await page.locator('[data-prize-pane="draws"]').click();
  await page.locator('[data-general-new]').click();
  await page.locator('[data-general-form] [name="name"]').fill('قرعه‌کشی اصلی');
  await page.locator('[data-general-form] [name="winnerLimit"]').fill('3');
  await page.locator('[name="keepConfirmedCards"]').uncheck();
  await page.locator('[name="preventRepeatedPrizeNames"]').uncheck();
  await page.locator('[name="preventRepeatedPrizes"]').uncheck();
  await page.locator('[data-general-form] [type="submit"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-general-list] a')!==null);
  assert.equal(writes[0].csrf,'test');assert.equal(writes[0].includeEntered,true);
  assert.equal(writes[0].keepConfirmedCards,false);
  assert.equal(writes[0].preventRepeatedPrizeNames,false);
  assert.equal(writes[0].preventRepeatedPrizes,false);
  assert.equal(await page.locator('[data-general-form]').isVisible(),false);
  assert.match(await page.locator('[data-general-list] a').first().getAttribute('href'),/general_draw.php\?level_id=abcdef/);
  assert.equal(await page.locator('#egm-linker-destination option[data-general-draw]').count(),1);
  await page.getByRole('button',{name:'ویرایش',exact:true}).click();
  assert.equal(await page.locator('[data-general-form] [name="winnerLimit"]').inputValue(),'3');
  assert.equal(await page.locator('[name="keepConfirmedCards"]').isChecked(),false);
  assert.equal(await page.locator('[name="preventRepeatedPrizeNames"]').isChecked(),false);
  assert.equal(await page.locator('[name="preventRepeatedPrizes"]').isChecked(),false);
  console.log('PASS: prize panes, create/edit flow, CSRF payload, permanent URL and short-link destination.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
