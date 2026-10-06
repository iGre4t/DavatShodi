const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {spawn} = require('node:child_process');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
(async () => {
  const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'egm-telegram-diagnostic-'));
  let server, browser;
  const write = (file, data) => {fs.mkdirSync(path.dirname(path.join(fixture,file)), {recursive:true}); fs.writeFileSync(path.join(fixture,file),data);};
  try {
    write('api/config.php', '<?php');
    // Three-deep generated instance verifies project paths as well as the page behavior.
    write('mini apps/EGMs/test/telegram-diagnostics.php', fs.readFileSync(path.join(__dirname,'../mini apps/Event Guest Manager/telegram-diagnostics.php')));
    write('mini apps/EGMs/test/egm-security.php', `<?php session_start(); function egmSecurityCreateCspNonce(){return 'testnonce';} function egmSecuritySendPageHeaders($nonce){header('Cache-Control: no-store');} function egmSecurityGetCsrfToken(){return 'test-csrf';} function egmSecurityIsValidCsrfToken($value){return $value==='test-csrf';}`);
    write('api/lib/tab-permissions.php', `<?php function requireTabPermissionFromSession($tab,$json){return ['allowed'=>empty($_GET['deny'])];} function userHasPermissionId($user,$id){return $user['allowed'];}`);
    write('api/lib/system-telegram.php', `<?php function systemTelegramConfig(){return ['proxy_url'=>'socks5h://private.example:1080'];} function systemTelegramDiagnose(){return ['active_route'=>'proxy','checks'=>['direct'=>['ok'=>false,'message'=>'زمان اتصال تمام شد.','curl_code'=>28,'http_status'=>0,'elapsed_ms'=>4000],'proxy'=>['ok'=>true,'message'=>'سرور به تلگرام دسترسی دارد و توکن معتبر است.','http_status'=>200,'elapsed_ms'=>120,'bot_username'=>'test_bot','webhook'=>['configured'=>true,'host'=>'host.example','pending_updates'=>3,'last_error_message'=>'HTTP 500 <unsafe>','last_error_date'=>1700000000]]]];}`);
    fs.appendFileSync(path.join(fixture,'mini apps/EGMs/test/egm-security.php'), ` if(!empty($_GET['https']))$_SERVER['HTTPS']='on';`);
    fs.appendFileSync(path.join(fixture,'api/lib/system-telegram.php'), ` function systemTelegramRedact($text,$config){return $text;} function systemBaleSetWebhook($url){file_put_contents(__DIR__.'/registered-url.txt',$url);}`);
    const portServer = net.createServer(); await new Promise(resolve=>portServer.listen(0,'127.0.0.1',resolve)); const port=portServer.address().port; await new Promise(resolve=>portServer.close(resolve));
    server=spawn('C:/xampp/php/php.exe',['-S',`127.0.0.1:${port}`,'-t',fixture],{stdio:'ignore'});
    const url=`http://127.0.0.1:${port}/mini%20apps/EGMs/test/telegram-diagnostics.php`;
    for(let attempt=0; attempt<40; attempt++){try{await fetch(url);break;}catch{await new Promise(resolve=>setTimeout(resolve,50));}}
    browser=await chromium.launch({channel:'msedge',headless:true}); const page=await browser.newPage({viewport:{width:390,height:844}});
    await page.goto(url); assert.equal(await page.locator('h1').textContent(),'بررسی اتصال ربات تلگرام');
    assert.ok((await page.locator('a').getAttribute('href')).includes('../../../panel.php'));
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth <= window.innerWidth),true,'Mobile page overflow');
    const invalid=await page.request.post(url,{form:{csrf:'wrong'}}); assert.equal(invalid.status(),403);
    await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'بررسی اتصال',exact:true}).click()]);
    assert.equal(await page.locator('.state').count(),2); assert.ok((await page.locator('main').textContent()).includes('مسیر فعال ربات'));
    assert.equal(await page.locator('unsafe').count(),0,'Webhook error inserted as markup');
    assert.ok((await page.locator('main').textContent()).includes('HTTP 500 <unsafe>'));
    const limited=await page.request.post(url,{form:{csrf:'test-csrf'}}); assert.equal(limited.status(),429);
    const denied=await page.request.get(url+'?deny=1'); assert.equal(denied.status(),403);
    const baleContext=await browser.newContext(); const balePage=await baleContext.newPage();
    await balePage.goto(url+'?platform=bale&https=1');
    assert.equal(await balePage.locator('h1').textContent(),'بررسی اتصال ربات بله');
    await Promise.all([balePage.waitForNavigation(),balePage.getByRole('button',{name:'فعال‌سازی دریافت پیام',exact:true}).click()]);
    assert.ok((await balePage.locator('main').textContent()).includes('دریافت پیام‌های بله فعال شد'));
    assert.equal(fs.readFileSync(path.join(fixture,'api/lib/registered-url.txt'),'utf8'),`https://127.0.0.1:${port}/api/bale-webhook.php`,'Generated instance registered wrong webhook path');
    await baleContext.close();
    console.log('Persian diagnostics page, generated-instance paths, mobile layout, escaping, CSRF, rate limit and access control passed.');
  } finally {if(browser)await browser.close(); if(server)server.kill(); fs.rmSync(fixture,{recursive:true,force:true});}
})().catch(error=>{console.error(error);process.exit(1);});
