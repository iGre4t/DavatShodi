const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const path = require('node:path');
(async () => {
  const browser = await chromium.launch({channel:'msedge',headless:true});
  try {
    const page = await browser.newPage();
    let resolved = false, fail = false, informational = false, gets = 0;const posts = [], filters = [];
    await page.route('http://egm.test/**',async route => {
      const req=route.request();
      if (req.method()==='POST') {
        const body=req.postDataJSON();posts.push(body);
        if (fail) {fail=false;await route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({status:'error',message:'گزارش قبلاً بررسی شده است.'})});return;}
        resolved=body.action==='resolve';
        await route.fulfill({contentType:'application/json',body:JSON.stringify({status:'ok',message:'ثبت شد.'})});return;
      }
      gets++;
      filters.push(new URL(req.url()).searchParams.get('period'));
      const data={status:'ok',total:1,page:1,pages:1,more_logs:false,active_period_code:'01',periods:[{code:'01',title:'بازهٔ اول'},{code:'02',title:'بازهٔ دوم'}],reports:[{id:'a'.repeat(32),actionable:!informational,information_label:'تصمیم جدیدتر ثبت شده است.',repeat_count:5,last_attempt_at:'2026-10-12 08:00',decision:resolved?'games_draw':null,decision_label:'بازی و قرعه‌کشی',summary:{reason:'ورود بدون دعوت',effect:'عدم شرکت در بازی و قرعه‌کشی و دریافت هدیه',tone:'danger'},guest:{full_name:'<script>unsafe</script>',period_title:'اول',national_id:'0012345678',work_id:'02',phone_number:'09123456789',message:'این مهمان دعوت نشده است.'}}]};
      await route.fulfill({contentType:'application/json',body:JSON.stringify(data)});
    });
    await page.goto('http://egm.test/panel');
    await page.setContent(`<main class="egm-shell" data-egm-csrf="test-csrf"><button data-pane="egm-guest-reports">گزارش‌ها</button><section data-guest-reports data-endpoint="/guest_reports.php"><button data-report-refresh>تازه‌سازی</button><select data-report-period><option value="">همه</option></select><select data-report-state><option value="pending">بررسی</option><option value="all">همه</option></select><input data-report-search><p data-report-status></p><div data-report-list></div><button data-report-prev>قبلی</button><span data-report-page></span><button data-report-next>بعدی</button></section></main>`);
    await page.addScriptTag({path:path.resolve('assets/egm-guest-reports.js')});
    await page.locator('[data-pane]').click();
    await page.locator('[data-report-resolve]').waitFor();
    assert.deepEqual(await page.locator('.egm-report-identity dd').allTextContents(),['02','0012345678','09123456789'],'Guest identifiers must be displayed separately with leading zeros');
    assert.equal(filters.at(-1),'active','First request must use the active period');
    assert.match(await page.locator('.egm-report-summary').innerText(),/ورود بدون دعوت/);
    assert.match(await page.locator('.egm-report-details').innerText(),/دعوت نشده/);
    await page.locator('[data-report-period]').selectOption('02');
    await page.waitForFunction(()=>!document.querySelector('[data-report-status]').textContent);
    assert.equal(filters.at(-1),'02','Historical period selection was ignored');
    await page.locator('[data-report-period]').selectOption('');
    await page.waitForFunction(()=>!document.querySelector('[data-report-status]').textContent);
    assert.equal(filters.at(-1),'','All-period filter was ignored');
    assert.equal(await page.locator('[data-report-list] script').count(),0,'Guest name inserted as HTML');
    await page.locator('[data-guest-reports]').evaluate(el=>el.dataset.pane='egm-guest-reports');
    const beforeSwitch=gets;
    await page.locator('[name=draw]').check();
    await page.waitForTimeout(300);
    assert.equal(gets,beforeSwitch,'Clicking a switch must not reload its containing pane');
    assert.equal(await page.locator('[name=draw]').isChecked(),true);
    await page.locator('[data-report-resolve] button[type=submit]').click();
    await page.locator('[data-report-reopen]').waitFor();
    assert.equal(posts[0].choice,'games_draw');assert.equal(posts[0].csrf,'test-csrf');
    assert.match(await page.locator('[data-report-list]').innerText(),/✅ حل شده/);
    await page.locator('[data-report-reopen]').click();
    await page.locator('[data-report-resolve]').waitFor();
    assert.equal(posts[1].action,'reopen');
    fail=true;
    await page.locator('[data-report-deny]').click();
    await page.waitForFunction(()=>document.querySelector('[data-report-status]').textContent.includes('قبلاً'));
    assert.equal(await page.locator('[data-report-deny]').isEnabled(),true,'Failed review remained locked');
    assert.equal(page.url(),'http://egm.test/panel','Review caused a panel reload');
    await page.locator('[data-report-deny]').click();
    await page.locator('[data-report-reopen]').waitFor();
    assert.equal(posts.at(-1).choice,'deny');
    assert.match(await page.locator('.egm-report-repeat').innerText(),/۵/);
    informational=true;
    await page.locator('[data-report-state]').selectOption('information');
    await page.locator('.egm-report-information').waitFor();
    assert.equal(await page.locator('[data-report-list] button,[data-report-list] input').count(),0,'Information-only report has non-working controls');
    assert.match(await page.locator('[data-report-list]').innerText(),/صرفاً اطلاع‌رسانی/);
    console.log('PASS: independent permissions, resolve/reopen, CSRF, escaped guest data and recoverable inline errors without reload.');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
