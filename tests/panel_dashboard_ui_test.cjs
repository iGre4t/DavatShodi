const {chromium}=require('playwright');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const html=execFileSync('C:/xampp/php/php.exe',['tests/panel_dashboard_fixture.php'],{encoding:'utf8'});
 fs.writeFileSync('outputs/event-dashboard-preview.html',html);
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try {
  const page=await browser.newPage({viewport:{width:1366,height:1000}});
  await page.setContent(html);
  assert.match(await page.locator('h1').innerText(),/آزاده محمدی/);
  assert.equal(await page.locator('.ed-event').count(),2);
  assert.equal(await page.locator('.ed-event').filter({has:page.locator('[data-dashboard-tab=event-guest-manager-mission-67890]')}).locator('[data-dashboard-pane=egm-games]').count(),0,'Unauthorized game shortcut exposed');
  assert.equal(await page.locator('.ed-event').filter({has:page.locator('[data-dashboard-tab=event-guest-manager]')}).locator('[data-dashboard-pane=egm-games]').count(),1);
  assert.equal(await page.locator('.ed-period-link').first().getAttribute('data-dashboard-pane'),'egm-task-01');
  const app=fs.readFileSync('app.js','utf8');
  const handler=app.slice(app.indexOf("  qs('#tab-home')?.addEventListener"),app.indexOf('  // Clicking a nav-item'));
  await page.addScriptTag({content:`const qs=selector=>document.querySelector(selector); async function activateTab(tab){window.openedDashboardTab=tab; const section=document.createElement('section');section.id='tab-'+tab;document.body.append(section);setTimeout(()=>{const button=document.createElement('button');button.className='sub-item';button.dataset.pane='egm-task-01';button.onclick=()=>window.openedDashboardPane=button.dataset.pane;section.append(button);},20);} ${handler}`});
  await page.locator('.ed-period-link').click();
  await page.waitForFunction(()=>window.openedDashboardPane==='egm-task-01');
  assert.equal(await page.evaluate(()=>openedDashboardTab),'event-guest-manager');
  await page.screenshot({path:'outputs/event-dashboard-desktop.png'});
  await page.setViewportSize({width:390,height:844});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Mobile overflow');
  await page.screenshot({path:'outputs/event-dashboard-mobile.png',fullPage:true});
  console.log('PASS: greeting, event cards, authorized shortcuts, period target, desktop and mobile fit.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
