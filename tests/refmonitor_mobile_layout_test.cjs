const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const source = fs.readFileSync(path.join(__dirname, '../mini apps/Event Guest Manager/RefMonitor.php'), 'utf8');
const css = [...source.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map(m => m[1]).join('\n');
(async () => {
  const browser = await chromium.launch({channel:'msedge', headless:true});
  try {
    const page = await browser.newPage({isMobile:true, deviceScaleFactor:3});
    for (const width of [320, 390, 430, 600, 1024]) {
      await page.setViewportSize({width, height:844});
      for (const content of ['<form class="login-form"><label class="login-field">نام کاربری<input class="login-input"></label><button class="login-btn">ورود</button></form>', '<div class="flow-panel"><h1>تیم نمونه</h1><ul class="detail-members">' + '<li><span class="member-info"><strong class="member-name">نام طولانی عضو تیم برای بررسی عرض صفحه</strong></span></li>'.repeat(30) + '</ul><div class="action-dock"><button class="primary-action flow-action">ثبت امتیاز</button></div></div>']) {
        await page.setContent(`<html dir="rtl"><head><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><style>${css}</style></head><body><div class="app"><div class="phone"><header class="topbar"><span class="brand">پنل تسهیلگر</span><button>برگشت</button></header><main class="main-area">${content}</main></div></div></body></html>`);
        const sizes = await page.evaluate(() => ({width:innerWidth, body:document.body.scrollWidth, app:document.querySelector('.app').getBoundingClientRect().width, height:document.querySelector('.phone').getBoundingClientRect().height, documentHeight:document.documentElement.scrollHeight}));
        assert.equal(sizes.width, width);
        assert.ok(sizes.body <= width, JSON.stringify(sizes));
        assert.equal(Math.round(sizes.app), width<=600 ? width : 460);
        assert.equal(Math.round(sizes.height), width<=600 ? 844 : 808);
        assert.ok(sizes.documentHeight <= 844, JSON.stringify(sizes));
      }
    }
    const desktop = await browser.newPage();
    for (const viewport of [{width:1366,height:768},{width:1920,height:1080},{width:800,height:480}]) {
      await desktop.setViewportSize(viewport);
      await desktop.setContent(`<html dir="rtl"><style>${css}</style><body><main class="app"><section class="phone"><header class="topbar">پنل تسهیلگر</header><div class="main-area"><h1>خوش آمدید</h1><div class="games-list">${'<button class="game-card">بازی نمونه</button>'.repeat(30)}</div><div class="action-dock"><button class="primary-action flow-action">ادامه</button></div></div></section></main></body></html>`);
      const frame = await desktop.evaluate(()=>{
        const phone=document.querySelector('.phone'),main=document.querySelector('.main-area'),r=phone.getBoundingClientRect();
        return {width:r.width,height:r.height,left:r.left,top:r.top,radius:getComputedStyle(phone).borderRadius,scrollable:main.scrollHeight>main.clientHeight,pageHeight:document.documentElement.scrollHeight};
      });
      assert.equal(frame.width,460); assert.equal(frame.height,viewport.height-36);
      assert.equal(frame.left,(viewport.width-460)/2);assert.equal(frame.top,18);assert.equal(frame.radius,'28px');
      assert.ok(frame.scrollable);assert.ok(frame.pageHeight<=viewport.height);
    }
    // A phone requesting a desktop-sized viewport must still fit the full screen.
    await page.setViewportSize({width:980,height:844});
    await page.setContent(`<html class="ref-mobile-layout" dir="rtl"><style>${css}</style><body><main class="app"><section class="phone"><header class="topbar">تسهیلگر</header><div class="main-area">بازی‌ها</div></section></main></body></html>`);
    assert.equal(await page.locator('.phone').evaluate(el=>el.getBoundingClientRect().width),980);
    assert.equal(await page.locator('.phone').evaluate(el=>getComputedStyle(el).borderRadius),'0px');
    assert.ok(source.includes("classList.toggle('ref-mobile-layout', mobile)"));
    console.log('RefMonitor layout passed: full-width mobile, desktop-mode phone, centered TCM-sized desktop frame, and scrolling at laptop/large/short screen sizes.');
  } finally {await browser.close();}
})().catch(error => {console.error(error);process.exitCode=1;});
