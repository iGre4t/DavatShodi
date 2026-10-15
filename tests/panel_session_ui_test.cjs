const fs=require('node:fs');const assert=require('node:assert/strict');const {chromium}=require('playwright');
(async()=>{const browser=await chromium.launch({headless:true,channel:'msedge'});try{
 const page=await browser.newPage();let expiry=0,now=1700000000;await page.clock.install({time:new Date(now*1000)});let alive=true;let continues=0;let initialChecks=0;
 const advance=async(ms)=>{now+=ms/1000;await page.clock.runFor(ms);};
 const state=()=>({authenticated:alive,server_now:now,expires_at:expiry,warning_seconds:2,csrf:'test-csrf'});
 await page.route('http://panel.test/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/panel.php'){expiry=now+5;alive=true;return route.fulfill({contentType:'text/html; charset=utf-8',body:`<html><body><h1>Private panel</h1><script>window.PANEL_SESSION=${JSON.stringify(state())}</script></body></html>`});}
   if(url.pathname==='/login.php'||url.pathname==='/logout.php')return route.fulfill({contentType:'text/html',body:'<h1>Login</h1>'});
   if(url.pathname==='/denied')return route.fulfill({status:403,contentType:'application/json',body:'{"message":"permission denied"}'});
   if(url.pathname==='/unauthorized')return route.fulfill({status:401,contentType:'application/json',body:'{}'});
   if(url.pathname==='/api/panel-session.php'){
    initialChecks++;if(now>=expiry)alive=false;
    if(!alive)return route.fulfill({status:401,headers:{'X-Panel-Session-Expired':'1'},json:{authenticated:false}});
    if(route.request().method()==='POST'){const input=route.request().postDataJSON();assert.equal(input.csrf,'test-csrf');assert.equal(input.action,'continue');continues++;expiry=now+10;}
    return route.fulfill({json:state()});
   }
 });
 const open=async()=>{await page.goto('http://panel.test/panel.php');await page.addScriptTag({content:fs.readFileSync('assets/panel-session.js','utf8')});await page.waitForFunction(()=>document.documentElement.style.visibility!=='hidden');};
 await open();await advance(3500);const dialog=page.getByRole('dialog');await dialog.waitFor();assert.ok((await dialog.innerText()).includes('نشست شما رو به پایان است'));await dialog.getByRole('button',{name:'ادامه',exact:true}).click();await page.waitForFunction(()=>!document.querySelector('dialog').open);assert.equal(continues,1);
 await advance(6000);assert.ok(page.url().endsWith('panel.php'),'Continue extends the session');
 await advance(5000);await page.waitForURL('**/login.php?session=expired');assert.equal(await page.getByRole('heading').innerText(),'Login','No response leads to login');
 await open();await page.evaluate(()=>fetch('/denied'));assert.ok(page.url().endsWith('panel.php'),'Permission errors do not log out');await page.evaluate(()=>fetch('/unauthorized'));await page.waitForURL('**/login.php?session=expired');
 await open();await page.evaluate(()=>{const xhr=new XMLHttpRequest();xhr.open('GET','/unauthorized');xhr.send();});await page.waitForURL('**/login.php?session=expired');
 await open();await advance(3500);await page.getByRole('dialog').getByRole('button',{name:'خروج',exact:true}).click();await page.waitForURL('**/logout.php');
 assert.ok(initialChecks>0);console.log('PASS: countdown warning, explicit renewal, automatic logout, fetch/XHR expiry and legitimate 403 preservation');
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
