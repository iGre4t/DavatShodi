const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const base = path.join(__dirname, '../mini apps/Event Guest Manager');
const panel = fs.readFileSync(path.join(base, 'EGM Panel.php'), 'utf8');
const form = panel.match(/<form id="egm-prize-form"[\s\S]*?<\/form>/)[0];
(async () => {
  const browser = await chromium.launch({channel: 'msedge', headless: true});
  try {
    const page = await browser.newPage();
    let saves = 0, documents = 0, mode = 'duplicate';
    const dialogs = [], errors = [];
    page.on('dialog', async dialog => { dialogs.push(dialog.message()); await dialog.dismiss(); });
    page.on('pageerror', error => errors.push(error.message));
    await page.route('http://prizes.test/**', async route => {
      const req = route.request();
      if (req.isNavigationRequest()) {
        documents++;
        return route.fulfill({contentType: 'text/html', body: `<div class="egm-shell"><p id="egm-prize-status" hidden></p>${form}<table><tbody id="egm-prize-list"></tbody></table></div>`});
      }
      if (req.method() === 'POST') {
        saves++;
        if (mode === 'network') return route.abort();
        if (mode === 'duplicate') return route.fulfill({status: 422, json: {status:'error', code:'duplicate_prize_name', message:'جایزه‌ای با این نام قبلاً ثبت شده است. نام دیگری وارد کنید.'}});
        return route.fulfill({json: {status:'ok', version:'v2'}});
      }
      return route.fulfill({json: {status:'ok', version:'v1', data:[{id:'p1', name:'قبلی', quantity:2, value:10}]}});
    });
    await page.goto('http://prizes.test/');
    await page.addScriptTag({content: fs.readFileSync(path.join(base, 'EGM Prizes.js'), 'utf8')});
    await page.locator('#egm-prize-list tr[data-index]').waitFor();
    await page.locator('#egm-prize-name').fill('قبلی');
    await page.locator('#egm-prize-value').fill('20');
    await page.locator('#egm-prize-form button').click();
    assert.equal(saves, 0);
    assert.match(await page.locator('#egm-prize-status').innerText(), /قبلاً/);
    await page.locator('#egm-prize-name').fill('جدید');
    await page.locator('#egm-prize-form button').click();
    await page.waitForFunction(() => document.querySelector('#egm-prize-status').textContent.includes('قبلاً'));
    assert.equal(await page.locator('#egm-prize-name').inputValue(), 'جدید');
    assert.equal(await page.locator('#egm-prize-value').inputValue(), '20');
    mode = 'network';
    await page.locator('#egm-prize-form button').click();
    await page.waitForFunction(() => document.querySelector('#egm-prize-status').textContent.includes('ارتباط'));
    mode = 'ok';
    await page.locator('#egm-prize-form button').click();
    await page.waitForFunction(() => document.querySelector('#egm-prize-status').dataset.kind === 'success');
    assert.equal(await page.locator('#egm-prize-list tr[data-index]').count(), 2);
    assert.equal(await page.locator('#egm-prize-name').inputValue(), '');
    assert.equal(documents, 1);
    assert.deepEqual(dialogs, []);
    assert.deepEqual(errors, []);
    console.log('Prize entry: duplicate, server rejection, network retry and success passed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
