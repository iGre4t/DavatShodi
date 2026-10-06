const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
(async () => {
  const browser = await chromium.launch({headless:true, channel:'msedge'});
  try {
    const page = await browser.newPage();
    const game = {id:'1234567890abcdef', name:'تست', has_levels:false, gender_mode:'normal', auto_room_manager:false, min_players:1, max_players:20, rooms:[{id:'r1',name:'اتاق',gender:'both'}], levels:[]};
    const calls = [];
    let blocked = false, validation = false;
    await page.route('http://egm.test/**', async route => {
      const request = route.request();
      if (!request.url().includes('games.php')) return route.fulfill({contentType:'text/html; charset=utf-8',body:'<div class="egm-shell" data-egm-csrf="test"><div class="active" data-egm-games-catalog><form data-games-create></form><div data-games-list></div><p data-games-status></p></div></div>'});
      if (request.method() === 'POST') {
        assert.match(request.headers()['content-type'], /application\/x-www-form-urlencoded/);
        const form = new URLSearchParams(request.postData());
        assert.equal(form.has('payload'), false);
        const input = Object.fromEntries(form);
        for(const key of ['enabled','required','has_levels'])if(key in input)input[key]=input[key]==='1';
        assert.equal(input.csrf, 'test');
        calls.push(input);
        if (blocked) return route.fulfill({status:403, contentType:'text/html',body:'<!DOCTYPE html><h1>Forbidden</h1>'});
        if (validation) return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'error',code:'validation_error',http_status:422,message:'پس از ثبت امتیاز یا پایان یک مرحله، روش ثبت نتیجه قابل تغییر نیست.'})});
        if (input.action === 'save_auto_mode') game.auto_room_manager = input.enabled;
        if (input.action === 'save_cover_color_requirement') game.require_cover_color = input.required;
        if (input.action === 'save_no_score_mode') game.no_score_needed = input.enabled;
      }
      return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'ok',games:[game]})});
    });
    await page.goto('http://egm.test/panel.php');
    await page.addScriptTag({content:fs.readFileSync(path.join(__dirname,'../assets/egm-games.js'),'utf8')});
    await page.locator('[data-open-game]').click();
    await page.locator('[name="auto_mode"]').selectOption('on');
    await page.locator('[data-game-settings] [type="submit"]').click();
    await page.waitForFunction(() => document.querySelector('[data-games-status]').textContent === 'تنظیمات ذخیره شد.' && !document.querySelector('[data-game-settings] [type=submit]').disabled);
    assert.equal(calls[0].action, 'save_auto_mode');
    assert.equal(calls[0].enabled, true);
    await page.locator('[name="require_cover_color"]').selectOption('on');
    await page.locator('[data-game-settings] [type="submit"]').click();
    await page.waitForFunction(() => document.querySelector('[data-games-status]').textContent === 'تنظیمات ذخیره شد.' && !document.querySelector('[data-game-settings] [type=submit]').disabled && document.querySelector('[name="require_cover_color"]').value === 'on');
    assert.equal(calls.at(-1).action, 'save_cover_color_requirement');
    assert.equal(calls.at(-1).required, true);
    await page.locator('[name="result_mode"]').selectOption('completion');
    await page.locator('[data-game-settings] [type="submit"]').click();
    await page.waitForFunction(() => document.querySelector('[data-games-status]').textContent === 'تنظیمات ذخیره شد.' && !document.querySelector('[data-game-settings] [type=submit]').disabled && document.querySelector('[name="result_mode"]').value === 'completion');
    assert.equal(calls.at(-1).action, 'save_no_score_mode');
    assert.equal(calls.at(-1).enabled, true);
    validation = true;
    await page.locator('[name="result_mode"]').selectOption('score');
    await page.locator('[data-game-settings] [type="submit"]').click();
    await page.waitForFunction(()=>document.querySelector('[data-games-status]').textContent.includes('روش ثبت نتیجه قابل تغییر نیست'));
    assert.equal(game.no_score_needed,true);validation=false;
    await page.locator('[name="result_mode"]').selectOption('completion');
    blocked = true;
    await page.locator('[name="auto_mode"]').selectOption('off');
    await page.locator('[data-game-settings] [type="submit"]').click();
    await page.waitForFunction(() => document.querySelector('[data-games-status]').textContent.includes('۴۰۳'));
    assert.equal(await page.locator('[data-game-settings] [type="submit"]').isEnabled(), true);
    console.log('Automatic-room, cover-color and no-score settings preserve booleans and CSRF; blocked host response is readable.');
  } finally {await browser.close();}
})().catch(error => {console.error(error);process.exitCode=1;});
