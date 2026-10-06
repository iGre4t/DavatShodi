const fs=require('node:fs');
const path=require('node:path');
const os=require('node:os');
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {chromium}=require('playwright');
const XLSX=require('../mini apps/Event Guest Manager/vendor/xlsx/xlsx.full.min.js');
const filename=path.join(os.tmpdir(),`egm-games-report-${Date.now()}.xlsx`);
try {
 execFileSync('C:/xampp/php/php.exe',[path.join(__dirname,'egm_game_export_test.php'),filename]);
 const workbook=XLSX.read(fs.readFileSync(filename),{type:'buffer'});
 assert.equal(workbook.SheetNames.length,3);assert.equal(new Set(workbook.SheetNames).size,3);
 const rows=XLSX.utils.sheet_to_json(workbook.Sheets[workbook.SheetNames[0]]);
 assert.equal(rows[0]['نام کاربری سازنده'],'ref01');assert.equal(rows[0]['کد ملی عضو'],'0012345678');
 assert.equal(rows[0]['کد پرسنلی عضو'],'0007');assert.equal(rows[0]['تیم'],'=SUM(1,1)');
 assert.equal(rows[0]['نتیجه: 1 · مرحله اول'],'15');
 assert.ok(!Object.keys(rows[0]).some(key=>/password|رمز عبور/i.test(key)));
} finally {if(fs.existsSync(filename))fs.unlinkSync(filename);}
(async()=>{
 const browser=await chromium.launch({channel:'msedge',headless:true});
 try {
  const page=await browser.newPage();const game={id:'1234567890abcdef',name:'بازی دیجیتال',no_score_needed:false};
  const team={id:1,name:'تیم آفتاب',members:[{id:1,name:'علی'}],total_score:30,creator:{name:'تسهیلگر نمونه',username:'<script>ref01</script>'}};
  await page.route('http://egm.test/**',route=>{
   const request=route.request();const url=new URL(request.url());
   const action=request.method()==='POST'?new URLSearchParams(request.postData()).get('action'):url.searchParams.get('action');
   if(url.pathname.endsWith('games.php'))return route.fulfill({contentType:'application/json',body:JSON.stringify(action==='list_teams'?{status:'ok',teams:[team]}:{status:'ok',games:[game],enabled:[game.id]})});
   return route.fulfill({contentType:'text/html;charset=utf-8',body:'<div class="egm-shell" data-egm-games-endpoint="/games.php"><div class="sub-pane" data-task-pane="1" data-task-tag-code="02"><button data-task-top-trigger="games">بازی‌ها</button><div data-egm-period-games><div data-period-games-list></div><p data-period-games-status></p></div></div></div>'});
  });
  await page.goto('http://egm.test/panel.php');await page.addScriptTag({content:fs.readFileSync(path.join(__dirname,'../assets/egm-games.js'),'utf8')});
  await page.locator('[data-task-top-trigger="games"]').click();await page.locator('.egm-game-team-creator').waitFor();
  assert.match(await page.locator('.egm-game-team-creator').textContent(),/تسهیلگر نمونه/);
  assert.match(await page.locator('.egm-game-team-creator').textContent(),/<script>ref01<\/script>/);
  assert.equal(await page.locator('.egm-game-team-creator script').count(),0);
  assert.match(await page.locator('[data-period-game-teams]').textContent(),/امتیاز 30/);
  assert.match(fs.readFileSync(path.join(__dirname,'../mini apps/Event Guest Manager/egm-panel-local.js'),'utf8'),/type=games&amp;period_code=/);
  console.log('Game workbook opens with separate sheets, creator/member data and text identifiers; panel shows creator name/username safely alongside scores and includes the export link.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1});
