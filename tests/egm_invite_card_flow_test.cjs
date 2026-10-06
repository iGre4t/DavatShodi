const assert=require('node:assert/strict');
const fs=require('node:fs');const path=require('node:path');const {execFileSync}=require('node:child_process');
const {chromium}=require('playwright');const root=path.resolve(__dirname,'..');
const php='C:/xampp/php/php.exe';
const cards=execFileSync(php,['-r','require '+JSON.stringify((root+'/api/lib/egm-invite-card-pane.php').replaceAll('\\','/'))+';renderEgmInviteCardPane("card.php",true);'],{encoding:'utf8'});
const rect={x:10,y:10,width:40,height:20};
let config={textHtml:'<p>متن اصلی اولیه</p>',text:'متن اصلی اولیه',imageName:'sample.png',imageWidth:600,imageHeight:800,qrRect:rect,textRect:rect,textAreas:[{id:'old',text:'متن مستقل اولیه',textHtml:'<p>متن مستقل اولیه</p>',rect:{x:10,y:40,width:70,height:20},fontFamily:'Tahoma'}]};
const fixture='<html dir="rtl" lang="fa"><head><meta charset="utf-8"></head><body><div class="egm-shell" data-egm-csrf="test">'+cards+'</div></body></html>';
let drafts=[];
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});
 try{
  const page=await browser.newPage({viewport:{width:1280,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('http://egm.test/**',async route=>{
   const request=route.request();const url=new URL(request.url());
   if(request.resourceType()==='document')return route.fulfill({contentType:'text/html',body:fixture});
   const payload=request.method()==='POST'?request.postDataJSON():null;
   if(url.searchParams.get('action')==='draft'){
    drafts.push(payload);config=JSON.parse(execFileSync(php,['-r','require '+JSON.stringify((root+'/api/lib/egm-invite-card-store.php').replaceAll('\\','/'))+'; $p=json_decode(stream_get_contents(STDIN),true); echo json_encode(egmInviteCardMergeDraft($p["stored"],$p["payload"]));'],{input:JSON.stringify({stored:config,payload}),encoding:'utf8'}));return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'ok'})});
   }
   return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'ok',data:config,items:[],periods:[]})});
  });
  await page.goto('http://egm.test/panel.php');
  config.imageData=await page.evaluate(()=>{const c=document.createElement('canvas');c.width=600;c.height=800;c.getContext('2d').fillRect(0,0,600,800);return c.toDataURL();});
  for(const file of ['style/styles.css','mini apps/Event Guest Manager/egm-panel.css','assets/egm-invite-card.css'])await page.addStyleTag({content:fs.readFileSync(path.join(root,file),'utf8')});
  for(const file of ['assets/egm-invite-card.js','mini apps/Event Guest Manager/egm-admin-ux.js'])await page.addScriptTag({content:fs.readFileSync(path.join(root,file),'utf8')});
  const pane=page.locator('[data-egm-invite-card-pane]');
  await page.waitForFunction(()=>document.querySelector('[data-invite-card-editor]').textContent.includes('اولیه'));
  assert.deepEqual(await pane.locator(':scope > .egm-flow-nav .egm-flow-step').allTextContents(),['۱متغیرهای شرطی','۲متن‌ها','۳چیدمان کارت','۴پیش‌نمایش و ذخیره']);
  await pane.locator(':scope > .egm-flow-nav .egm-flow-step').nth(1).click();
  const editor=pane.locator('[data-invite-card-editor]');
  await editor.fill('متن اصلی جدید');
  await pane.locator('[data-edit-text-area="old"]').click();
  assert.equal(await editor.innerText(),'متن مستقل اولیه');
  await editor.fill('متن مستقل جدید');
  await pane.locator('[data-editor-command="bold"]').click();
  await pane.locator('[data-invite-card-font-file]').setInputFiles(path.join(root,'style/fonts/IRANSansXFaNum-Bold.ttf'));
  await page.waitForFunction(()=>document.querySelector('[data-invite-card-font-status]').textContent.includes('IRANSans'));
  await pane.locator('[data-edit-text-area=""]').click();
  assert.equal(await editor.innerText(),'متن اصلی جدید');
  await page.waitForFunction(()=>document.querySelector('[data-invite-card-autosave-status]').textContent.includes('همه تغییرات'));
  assert.equal(config.textHtml,'<p>متن اصلی جدید</p>');assert(config.textAreas[0].textHtml.includes('متن مستقل جدید'));
  assert(config.textAreas[0].fontData.startsWith('data:font/ttf;base64,'));assert(!config.fontData,'Independent font overwrote main font');
  await pane.locator('[data-action="add-print-text-area"]').click();
  assert.equal(await editor.innerText(),'');
  await editor.fill('متن سوم');
  await pane.locator('[data-edit-text-area=""]').click();
  assert.equal(await editor.innerText(),'متن اصلی جدید');
  await pane.locator(':scope > .egm-flow-nav .egm-flow-step').nth(2).click();
  await pane.locator('[data-action="open-invite-card-selection"]').click();
  const modal=pane.locator('[data-invite-card-selection-modal]');
  const picker=modal.locator('[data-placement-text]');assert.equal(await picker.locator('option').count(),3);
  async function draw(){const box=await modal.locator('[data-invite-card-selection-layer]').boundingBox();await page.mouse.move(box.x+box.width*.15,box.y+box.height*.65);await page.mouse.down();await page.mouse.move(box.x+box.width*.75,box.y+box.height*.85);await page.mouse.up();}
  await picker.selectOption('old');await draw();
  await picker.selectOption('');await draw();
  await modal.locator('[data-action="confirm-invite-card-selection"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-invite-card-autosave-status]').textContent.includes('همه تغییرات'));
  assert(config.textAreas[0].rect.y>60 && config.textRect.y>60,'Switching placement lost a rectangle');
  const committed=structuredClone(config.textAreas[0].rect);
  await pane.locator('[data-action="open-invite-card-selection"]').click();await picker.selectOption('old');
  await modal.locator('[data-action="clear-current-selection"]').click();
  await modal.locator('button[data-action="cancel-invite-card-selection"]').first().click();
  await pane.locator(':scope > .egm-flow-nav .egm-flow-step').nth(1).click();
  await pane.locator('[data-edit-text-area="old"]').click();
  assert.equal(await editor.innerText(),'متن مستقل جدید');
  await page.waitForTimeout(800);assert.deepEqual(config.textAreas[0].rect,committed,'Cancel changed saved placement');
  await page.screenshot({path:path.join(root,'outputs/invite-card-texts-ux.png'),fullPage:true});
  assert.deepEqual(errors,[]);
  console.log('PASS: reordered card flow, one shared rich editor, independent uploaded font, isolated autosave, multiple-area placement and cancel.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
