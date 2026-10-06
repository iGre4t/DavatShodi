const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const {chromium} = require('playwright');
const root = path.join(__dirname,'..');
const XLSX = require('../mini apps/Event Guest Manager/vendor/xlsx/xlsx.full.min.js');
const panel = fs.readFileSync(path.join(root,'mini apps/Event Guest Manager/EGM Panel.php'),'utf8');
const start = panel.indexOf('<div class="card" id="egm-facilitators"');
const card = panel.slice(start,panel.indexOf('</section>',start)).replace(/<\?[\s\S]*?\?>/g,'');
const html = `<html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/style/styles.css"><link rel="stylesheet" href="/mini%20apps/Event%20Guest%20Manager/egm-panel.css"><style>body{display:block;padding:20px;margin:0}.egm-shell{max-width:1000px;margin:auto}</style><div class="egm-shell" data-egm-csrf="test">${card}</div><script src="/mini%20apps/Event%20Guest%20Manager/vendor/xlsx/xlsx.full.min.js"></script><script src="/assets/egm-facilitators.js"></script></html>`;
let users = [], nextId = 1, blocked = false;
let imports=[];
const publicRows = ()=>users.map(({password,...row})=>row);
function excel(rows) {
 const workbook = XLSX.utils.book_new();
 XLSX.utils.book_append_sheet(workbook,XLSX.utils.aoa_to_sheet(rows),'تسهیلگرها');
 return {name:'facilitators.xlsx',mimeType:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',buffer:Buffer.from(XLSX.write(workbook,{type:'buffer',bookType:'xlsx'}))};
}
(async()=>{
 const browser=await chromium.launch({channel:'msedge',headless:true});
 try {
  const page=await browser.newPage({viewport:{width:1100,height:850}}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('http://egm.test/**',async route=>{
   const req=route.request(),url=new URL(req.url());
   if(url.pathname.endsWith('facilitators.php')) {
    if(blocked)return route.fulfill({status:403,contentType:'application/json',body:JSON.stringify({status:'error',message:'دسترسی ندارید.'})});
    if(req.method()==='POST') {
     const data=JSON.parse(new URLSearchParams(req.postData()).get('payload'));assert.equal(data.csrf,'test');
     const user=users.find(user=>user.id===data.id);
     if(data.action==='save') {
      if(users.some(u=>u.username.toLowerCase()===data.username.toLowerCase() && u.id!==data.id))return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({status:'error',message:'نام کاربری تکراری است.'})});
      if(user){user.name=data.name;user.username=data.username;if(data.password)user.password=data.password;}
      else users.push({id:String(nextId++),name:data.name,username:data.username,password:data.password});
     }
     if(data.action==='reveal')return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'ok',password:user.password})});
     if(data.action==='delete')users=users.filter(u=>u.id!==data.id);
     if(data.action==='import') {
      imports.push(data);
      if(data.rows.some(row=>!row.name||!row.username||!row.password))return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({status:'error',message:'ردیف نامعتبر است.'})});
      users.push(...data.rows.map(row=>({...row,id:String(nextId++)})));
     }
    }
    return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'ok',users:publicRows()})});
   }
   if(url.pathname==='/panel.php')return route.fulfill({contentType:'text/html;charset=utf-8',body:html});
   const filename=path.join(root,decodeURIComponent(url.pathname));
   if(fs.existsSync(filename))return route.fulfill({body:fs.readFileSync(filename),contentType:filename.endsWith('.css')?'text/css':filename.endsWith('.js')?'text/javascript':'font/woff2'});
   return route.fulfill({status:404,body:''});
  });
  await page.goto('http://egm.test/panel.php');
  await page.locator('[data-fac-add]').click();
  const form=page.locator('[data-fac-form]');
  await form.locator('[name="name"]').fill('علی رضایی');
  await form.locator('[name="username"]').fill('ref01');
  await form.locator('[name="password"]').fill('001234');
  await form.locator('[type="submit"]').click();
  await page.locator('[data-fac-list] [data-fac-action="edit"]').waitFor();
  assert.equal(users[0].password,'001234');
  assert.ok(!(await page.locator('[data-fac-list]').textContent()).includes('001234'));
  await page.reload();await page.locator('[data-fac-list] [data-fac-action="edit"]').waitFor();
  await page.locator('[data-fac-action="edit"]').click();
  assert.equal(await form.locator('[name="password"]').inputValue(),'');
  await form.locator('[name="name"]').fill('نام تازه');await form.locator('[type="submit"]').click();
  await page.waitForFunction(()=>!document.querySelector('[data-fac-editor]').open);
  assert.equal(users[0].password,'001234');
  await page.locator('[data-fac-action="password"]').click();
  const passwordForm=page.locator('[data-fac-password-form]');
  await page.locator('[data-fac-password-dialog]').waitFor({state:'visible'});
  assert.equal(await passwordForm.locator('input').inputValue(),'001234');
  await passwordForm.locator('[data-fac-toggle]').click();assert.equal(await passwordForm.locator('input').getAttribute('type'),'password');
  await passwordForm.locator('input').fill('changed');await passwordForm.locator('[type="submit"]').click();
  await page.waitForFunction(()=>!document.querySelector('[data-fac-password-dialog]').open);
  assert.equal(await passwordForm.locator('input').inputValue(),'');assert.equal(users[0].password,'changed');
  await page.locator('[data-fac-file]').setInputFiles(excel([['پرسنل','ورود','کلمه عبور'],['زهرا احمدی','0002','000045'],['مهدی','0003','pass3']]));
  await page.locator('[data-fac-import-dialog]').waitFor({state:'visible'});
  for(const [name,index] of [['name','0'],['username','1'],['password','2']])await page.locator(`[data-fac-column="${name}"]`).selectOption(index);
  assert.ok(!(await page.locator('[data-fac-preview]').textContent()).includes('000045'));
  await page.screenshot({path:path.join(os.tmpdir(),'egm-facilitators-import.png')});
  await page.locator('[data-fac-import-save]').click();
  await page.waitForFunction(()=>!document.querySelector('[data-fac-import-dialog]').open);
  assert.equal(imports[0].rows[0].username,'0002');assert.equal(imports[0].rows[0].password,'000045');
  assert.equal(imports[0].update_existing,false);assert.equal(users.length,3);
  await page.locator('[data-fac-file]').setInputFiles(excel([['name','username','password'],['Bad','bad','']]));
  await page.locator('[data-fac-import-dialog]').waitFor({state:'visible'});
  await page.locator('[data-fac-import-save]').click();
  await page.waitForFunction(()=>document.querySelector('[data-fac-import-status]').textContent.includes('نامعتبر'));
  assert.equal(users.length,3);await page.locator('[data-fac-import-dialog] [data-fac-close]').first().click();
  await page.locator('[data-fac-action="delete"]').first().click();
  await page.locator('[data-fac-delete-dialog] [data-fac-close]').click();assert.equal(users.length,3);
  await page.locator('[data-fac-action="delete"]').first().click();
  await page.locator('[data-fac-delete-form] [type="submit"]').click();
  await page.waitForFunction(()=>!document.querySelector('[data-fac-delete-dialog]').open);assert.equal(users.length,2);
  await page.screenshot({path:path.join(os.tmpdir(),'egm-facilitators-list.png')});
  await page.setViewportSize({width:390,height:844});await page.locator('[data-fac-add]').click();
  assert.ok(await page.locator('[data-fac-editor]').evaluate(el=>el.scrollWidth<=el.clientWidth));
  await page.screenshot({path:path.join(os.tmpdir(),'egm-facilitators-mobile.png')});
  await form.locator('[data-fac-close]').first().click();
  blocked=true;await page.reload();await page.waitForFunction(()=>document.querySelector('[data-fac-status]').textContent.includes('دسترسی'));
  assert.equal(errors.length,0,errors.join('\n'));
  console.log('Facilitator list reload, CRUD, masked/revealed passwords, password edits, Excel mapping/leading zeros, rejected imports, confirmation cancellation and mobile dialog passed.');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1});
