const assert = require('node:assert/strict');
const path = require('node:path');
const {chromium} = require('playwright');

(async () => {
  const browser = await chromium.launch({headless:true, channel:'msedge'});
  try {
    const page = await browser.newPage({viewport:{width:390, height:844}});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const profile = {configured:true, card:{}, ticket_active:true, tickets:[{id:'food', title:'غذا', configured:true, card:{}}]};
    await page.route('http://egm.test/**', async route => {
      const action = new URL(route.request().url()).searchParams.get('action');
      let data = {status:'ok'};
      if (action === 'pending_invitees') data = {...data, invitees:[{name:'مهمان آزمایشی', guest_code:'12'}], total:1, page_size:30};
      if (action === 'manual_print_profile') data.print_profile = profile;
      if (action === 'guest_print_profile') data = {...data, print_profile:profile, print_guest:{first_name:'آزمایش', ticket_numbers:{food:'7'}}};
      await route.fulfill({contentType:'application/json', body:JSON.stringify(data)});
    });
    await page.goto('http://egm.test/scan');
    await page.setContent('<main data-check-in-app data-can-register-uninvited="1"><section class="guest-card"><input data-national-id><div data-result></div><div data-attendance-stats></div></section><section class="guest-card"><button data-reset-log="0">بازنشانی</button><button data-report-log="0">گزارش به مدیریت</button></section></main>');
    await page.evaluate(() => {
      const app = document.querySelector('main');window.calls = [];window.failPrint = true;
      app.egmStats = {active:true, ticket_totals:[{title:'غذا', sum:'99999999999999999999999999999999'}], groups:[{title:'الف', entered:2, total:4}]};
      app.egmScanTools = {
        row:() => ({log_id:42, full_name:'آزمایش', national_id:'1234567890', period_code:'01'}),
        post:async payload => {calls.push(payload);return {status:'ok'};},
        accept:() => {}, refresh:async () => {},
        enter:async code => {calls.push({action:'enter', code});return {message:'ثبت شد'};},
        requestTicketNumber:async () => {throw new Error('Saved ticket should not prompt');},
        recordTicketNumber:async () => {throw new Error('Reprint must not record again');},
        printCardForRow:async row => {calls.push({action:'print', number:row.number_of_ticket});if (failPrint) {failPrint=false;throw new Error('آزمایش خطای چاپ');}}
      };
    });
    await page.addStyleTag({path:path.resolve('assets/egm-web-scan.css')});
    await page.addScriptTag({path:path.resolve('assets/egm-web-scan.js')});
    await page.locator('[data-page=data]').click();
    assert.equal(await page.locator('.egm-data-page').isVisible(), true);
    assert.match(await page.locator('[data-extra-stats]').innerText(), /۹{32}/);
    await page.locator('[data-page=scan]').click();
    await page.locator('[data-pending]').click();
    await page.locator('[data-enter]').click();
    assert.equal(await page.evaluate(() => calls.some(call => call.action === 'enter' && call.code === '12')), true);
    await page.locator('[data-close]').click();
    await page.locator('[data-manual]').click();
    await page.locator('[name=quantity]').fill('۳');
    await page.locator('.egm-tool-primary').click();
    await page.waitForFunction(() => document.querySelector('.egm-tool-message').textContent.includes('آزمایش خطای چاپ'));
    await page.locator('.egm-tool-primary').click();
    await page.waitForFunction(() => document.querySelector('.egm-tool-message').textContent.includes('پنجره چاپ'));
    assert.equal(await page.evaluate(() => calls.filter(call => call.action === 'record_manual_ticket').length), 1);
    assert.match(await page.evaluate(() => calls.find(call => call.action === 'record_manual_ticket').client_token), /^[a-f0-9]{32}$/);
    await page.locator('[data-close]').click();
    await page.evaluate(() => document.querySelector('main').egmScanTools.choosePrint({national_id:'1234567890', period_code:'01'}));
    await page.locator('[data-output="1"]').click();
    assert.equal(await page.evaluate(() => calls.at(-1).number), '7');
    await page.locator('[data-close]').click();
    page.once('dialog', dialog => dialog.accept());
    await page.locator('[data-reset-log]').click();
    await page.waitForFunction(() => calls.some(call => call.action === 'reset_guest_entry'));
    assert.equal(await page.evaluate(() => calls.find(call => call.action === 'reset_guest_entry').period_code), '01');
    await page.locator('[data-report-log]').click();
    await page.waitForFunction(() => calls.some(call => call.action === 'report_to_management'));
    assert.equal(await page.evaluate(() => calls.find(call => call.action === 'report_to_management').log_id), 42);
    await page.evaluate(() => {
      const app = document.querySelector('main');app.dispatchEvent(new CustomEvent('egm-scan-stats', {detail:{active:false}}));
    });
    assert.equal(await page.locator('[data-pending]').isDisabled(), true);
    assert.equal(await page.locator('[data-manual]').isDisabled(), true);
    assert.deepEqual(errors, []);
    console.log('Web scan/data flows passed: entry, totals, print retry, reprint, reset, inactive period.');
  } finally {await browser.close();}
})().catch(error => {console.error(error);process.exit(1);});
