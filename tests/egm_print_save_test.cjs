const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const script = fs.readFileSync(path.join(__dirname, '../mini apps/Event Guest Manager/EGMSetting.js'), 'utf8');
const settings = {active:true, customNumberTicketSettings:{active:true, ticketOnly:false, tickets:[{id:'default', title:'بلیت', prefix:'', digits:4}]}};
const html = `<div class="egm-shell" data-egm-csrf="test"><div class="sub-nav"><button data-pane="egm-print-card">چاپ</button><button data-pane="egm-custom-number-ticket-default">بلیت</button></div><div class="sub-pane" data-pane="egm-print-card"></div><div class="sub-pane" data-pane="egm-custom-number-ticket-default"><h3>بلیت</h3><textarea id="unsaved-design">draft</textarea></div><div id="egm-ticket-types"></div><button id="egm-save-ticket-types">ذخیره</button><p id="egm-ticket-types-status"></p><input id="unrelated" value="keep"></div>`;
(async () => {
  const browser = await chromium.launch({headless:true, channel:'msedge'});
  try {
    const page = await browser.newPage();
    const errors = [];
    let documents = 0, saves = 0, fail = false;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('http://egm.test/**', async route => {
      const request = route.request();
      if (request.url().includes('EGMSetting.js')) return route.fulfill({contentType:'application/javascript; charset=utf-8',body:script});
      if (request.url().includes('egm_store.php')) {
        if (request.method() === 'POST') {
          saves++;
          settings.customNumberTicketSettings = request.postDataJSON().settings.customNumberTicketSettings;
          return route.fulfill({status:fail?500:200, contentType:'application/json', body:JSON.stringify(fail?{status:'error',message:'اختبار خطا'}:{status:'ok'})});
        }
        return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'ok',data:settings})});
      }
      documents++;
      return route.fulfill({contentType:'text/html; charset=utf-8',body:html.replaceAll('>بلیت<', '>'+settings.customNumberTicketSettings.tickets[0].title+'<')});
    });
    await page.goto('http://egm.test/panel.php');
    await page.addScriptTag({url:'http://egm.test/mini%20apps/Event%20Guest%20Manager/EGMSetting.js'});
    await page.waitForSelector('[data-ticket-type-row]');
    await page.locator('#unrelated').fill('unsaved value');
    await page.locator('[data-ticket-type-row] input').first().fill('نام جدید');
    await page.locator('#egm-save-ticket-types').click();
    await page.waitForFunction(() => document.querySelector('#egm-ticket-types-status').textContent === 'تنظیمات چاپ و بلیت ذخیره شد.', null, {timeout:5000}).catch(async error => { console.error(await page.locator('#egm-ticket-types-status').textContent(), errors); throw error; });
    assert.equal(await page.locator('#unrelated').inputValue(), 'unsaved value');
    assert.equal(await page.locator('#unsaved-design').inputValue(), 'draft');
    assert.equal(await page.locator('.sub-nav [data-pane="egm-custom-number-ticket-default"]').textContent(), 'نام جدید');
    assert.equal(await page.locator('#egm-save-ticket-types').isEnabled(), true);
    assert.equal(saves, 1);
    assert.equal(documents, 2, 'Should fetch ticket markup once without navigation');
    await page.locator('#egm-save-ticket-types').click();
    await page.waitForFunction(() => !document.querySelector('#egm-save-ticket-types').disabled);
    assert.equal(documents, 2, 'Unchanged ticket types should not fetch the panel');
    fail = true;
    await page.locator('#egm-save-ticket-types').click();
    await page.waitForFunction(() => document.querySelector('#egm-ticket-types-status').textContent === 'اختبار خطا');
    assert.equal(await page.locator('#egm-save-ticket-types').isEnabled(), true);
    assert.deepEqual(errors, []);
    console.log('Print save preserves page and editor drafts; ticket tabs refresh; failure retry passed.');
  } finally {await browser.close();}
})().catch(error => {console.error(error);process.exitCode=1;});
