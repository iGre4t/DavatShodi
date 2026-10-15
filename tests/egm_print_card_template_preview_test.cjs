const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const php = 'C:/xampp/php/php.exe';
const pane = execFileSync(php, ['-r',
  'require ' + JSON.stringify(path.join(root, 'api/lib/egm-invite-card-pane.php').replaceAll('\\', '/')) +
  ';renderEgmInviteCardPane("print_card_store.php",true,["kind"=>"print","eventName"=>"رویداد نمونه"]);'
], { encoding: 'utf8' });

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    const page = await browser.newPage();
    const errors = [];
    const saved = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('http://egm.test/**', async route => {
      const request = route.request();
      const url = new URL(request.url());
      if (request.resourceType() === 'document') {
        return route.fulfill({ contentType: 'text/html', body: `<html lang="fa"><body><div class="egm-shell" data-egm-csrf="test">${pane}</div></body></html>` });
      }
      if (url.pathname.endsWith('.ttf')) {
        return route.fulfill({ contentType: 'font/ttf', body: fs.readFileSync(path.join(root, 'style/fonts/IRANSansXFaNum-Bold.ttf')) });
      }
      if (url.pathname.endsWith('/generate.php')) {
        return route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>' });
      }
      if (url.pathname.endsWith('/print_card_store.php')) {
        if (request.method() === 'POST') {
          saved.push(request.postDataJSON());
          return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: 'ok' }) });
        }
        await new Promise(resolve => setTimeout(resolve, 3000));
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: 'ok', data: null }) });
      }
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: 'ok', rows: [], periods: [] }) });
    });
    await page.goto('http://egm.test/panel.php');
    await page.addScriptTag({ content: fs.readFileSync(path.join(root, 'assets/egm-invite-card.js'), 'utf8') });
    const card = page.locator('[data-egm-invite-card-pane]');
    await card.locator('[data-receipt-template="mci-welcome-classic"]').click();
    await page.waitForFunction(() => document.querySelector('[data-receipt-template-status]').textContent.includes('اعمال و ذخیره شد'));
    assert.equal(saved.length, 1, 'template should save in one click');
    assert(saved[0].textAreas.some(area => area.text.includes('[guestnumber]')));
    await page.waitForTimeout(3200);
    assert.equal(await card.locator('[data-receipt-template="mci-welcome-classic"]').getAttribute('aria-pressed'), 'true');
    await card.locator('[data-action="test-generate-invite-card"]').click();
    await page.waitForFunction(() => !document.querySelector('[data-invite-card-preview]').hidden);
    assert.match(await card.locator('[data-invite-card-output-meta]').innerText(), /PNG/);
    assert.equal(saved.length, 1, 'preview must not write over the selected template');
    assert.deepEqual(errors, []);
    console.log('PASS: print-card template saves before initial GET completes and sample preview renders without a guest.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
