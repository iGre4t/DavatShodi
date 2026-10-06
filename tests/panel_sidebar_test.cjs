const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('playwright');
(async () => {
  const browser = await chromium.launch({headless:true, channel:'msedge'});
  try {
    const page = await browser.newPage({viewport:{width:1200,height:900}});
    const tabs = ['home','settings','event-guest-manager','event-guest-manager-mission-0001','event-guest-manager-creator','task-club','task-club-mission-one','task-club-creator','utm-service'];
    const html = '<div id="app-view"><aside class="sidebar"><nav class="nav">' + tabs.map(tab => `<button class="nav-item ${tab==='home'?'active':''}" data-tab="${tab}">${tab}</button>`).join('') + '</nav></aside></div>';
    await page.route('http://panel.test/**', route => route.fulfill({contentType:'text/html',body:html}));
    await page.goto('http://panel.test/');
    await page.evaluate(() => {
      window.clicks = 0;
      document.querySelector('[data-tab="event-guest-manager"]').addEventListener('click',()=>window.clicks++);
    });
    await page.addStyleTag({content:fs.readFileSync('assets/panel-sidebar.css','utf8')});
    await page.addScriptTag({content:fs.readFileSync('assets/panel-sidebar.js','utf8')});
    assert.equal(await page.locator('.nav > details').count(),2);
    assert.equal(await page.locator('[data-nav-group="egm"] .nav-item').count(),3);
    assert.equal(await page.locator('[data-nav-group="egm"] .nav-item').first().getAttribute('data-tab'),'event-guest-manager-creator');
    await page.locator('[data-nav-group="features"] > summary').click();
    await page.locator('[data-nav-group="egm"] > summary').click();
    await page.locator('[data-tab="event-guest-manager"]').click();
    assert.equal(await page.evaluate(()=>window.clicks),1);
    await page.evaluate(()=>window.dispatchEvent(new CustomEvent('panel-sidebar-visibility',{detail:{tabId:'event-guest-manager-mission-0001',visible:false}})));
    assert.equal(await page.locator('[data-tab="event-guest-manager-mission-0001"]').isVisible(),false);
    await page.evaluate(()=>window.dispatchEvent(new CustomEvent('panel-sidebar-visibility',{detail:{tabId:'event-guest-manager-mission-0001',visible:true}})));
    assert.equal(await page.locator('[data-tab="event-guest-manager-mission-0001"]').isVisible(),true);
    await page.locator('[data-nav-group="egm"] > summary').click();
    await page.waitForTimeout(100);
    assert.equal(await page.evaluate(()=>JSON.parse(localStorage.getItem('panel-sidebar-groups:local')).egm),false);
    await page.evaluate(()=>{
      document.querySelector('.nav-item.active').classList.remove('active');
      document.querySelector('[data-tab="task-club-mission-one"]').classList.add('active');
    });
    await page.waitForTimeout(100);
    assert.equal(await page.locator('[data-nav-group="clubs"]').getAttribute('open'),'');
    console.log('PASS: category grouping, management order, expand/collapse, remembered state, navigation handlers, live visibility and active category opening.');
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1});
