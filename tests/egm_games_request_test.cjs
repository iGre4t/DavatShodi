const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
(async () => {
  const browser = await chromium.launch({headless:true, channel:'msedge'});
  try {
    const page = await browser.newPage();
    const game = {id:'1234567890abcdef', name:'تست', has_levels:false, gender_mode:'separated', auto_room_manager:false, min_players:1, max_players:20, rooms:[{id:'r1',name:'اتاق',gender:'both'}], levels:[]};
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
        if (input.action === 'set_room_enabled') {
          const room = game.rooms.find(room => room.id === input.room_id);
          if (input.room_id === 'r1' && !input.enabled && input.confirmed !== '1') return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'ok',requires_confirmation:true,teams:[{id:1,name:'تیم فعال'}]})});
          room.enabled = input.enabled;
        }
        if (input.action === 'save_room') {
          assert.notEqual(input.room_id, 'undefined');
          if (input.room_id === '') game.rooms.push({id:'r2',name:input.name,gender:input.gender});
          else {
            const room = game.rooms.find(room => room.id === input.room_id);
            assert.ok(room, 'Editing must send the existing room ID');
            Object.assign(room, {name:input.name,gender:input.gender});
          }
        }
        if (blocked) return route.fulfill({status:403, contentType:'text/html',body:'<!DOCTYPE html><h1>Forbidden</h1>'});
        if (validation) return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'error',code:'validation_error',http_status:422,message:'پس از ثبت امتیاز یا پایان یک مرحله، روش ثبت نتیجه قابل تغییر نیست.'})});
        if (input.action === 'save_auto_mode') game.auto_room_manager = input.enabled;
        if (input.action === 'save_cover_color_requirement') game.require_cover_color = input.required;
        if (input.action === 'save_no_score_mode') game.no_score_needed = input.enabled;
        if (input.action === 'save_level') {
          assert.ok(!input.level_id, 'A new level must not carry an existing level ID');
          game.levels.push({id:'l2',name:input.name,rooms:[]});
        }
      }
      return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'ok',games:[game]})});
    });
    await page.goto('http://egm.test/panel.php');
    await page.addScriptTag({content:fs.readFileSync(path.join(__dirname,'../assets/egm-games.js'),'utf8')});
    await page.locator('[data-open-game]').click();
    assert.equal(await page.locator('[data-game-score-count] [name=score_count]').isVisible(),true,'Score count must be visible in the game settings');
    assert.equal(await page.locator('[name=score_count]').count(),1,'Score count should have exactly one editable field');
    await page.locator('[data-room-add]').click();
    assert.equal(await page.locator('[data-room-form] [name="gender"]').inputValue(), 'both', 'Separated games default new rooms to both genders');
    assert.deepEqual(await page.locator('[data-room-form] [name="gender"] option').evaluateAll(options => options.map(option => option.value)), ['both','male','female','priority_male','priority_female']);
    await page.locator('[data-room-form] [name="name"]').fill('اتاق جدید');
    await page.locator('[data-room-form] [type="submit"]').click();
    await page.locator('[data-room-edit="r2"]').waitFor();
    assert.equal(calls.at(-1).room_id, '');
    assert.equal(calls.at(-1).level_id, 'game_total');
    assert.equal(calls.at(-1).gender, 'both');
    await page.locator('[data-room-edit="r2"]').click();
    assert.equal(await page.locator('[data-room-form] [name="gender"]').inputValue(), 'both', 'Editing preserves the shared room gender');
    await page.locator('[data-room-form] [name="gender"]').selectOption('priority_female');
    await page.locator('[data-room-form] [name="name"]').fill('اتاق ویرایش‌شده');
    await page.locator('[data-room-form] [type="submit"]').click();
    await page.waitForFunction(() => !document.querySelector('[data-room-form]'));
    assert.equal(calls.at(-1).room_id, 'r2');
    assert.equal(calls.at(-1).gender, 'priority_female');
    assert.equal(game.rooms[1].gender, 'priority_female');
    assert.equal(game.rooms.length, 2);
    page.once('dialog', dialog => dialog.dismiss());
    await page.locator('[data-room-enabled="r1"]').uncheck();
    await page.waitForFunction(() => document.querySelector('[data-room-enabled="r1"]').checked && !document.querySelector('[data-room-enabled="r1"]').disabled);
    assert.notEqual(game.rooms[0].enabled, false, 'Cancelled confirmation keeps occupied room enabled');
    page.once('dialog', dialog => dialog.accept());
    await page.locator('[data-room-enabled="r1"]').uncheck();
    await page.waitForFunction(() => document.querySelector('[data-games-status]').textContent === 'اتاق غیرفعال شد.');
    assert.equal(game.rooms[0].enabled, false);
    assert.equal(calls.at(-1).confirmed, '1');
    await page.locator('[data-room-enabled="r2"]').uncheck();
    await page.waitForFunction(() => !document.querySelector('[data-room-enabled="r2"]').checked && !document.querySelector('[data-room-enabled="r2"]').disabled);
    assert.equal(game.rooms[1].enabled, false, 'Unused room switches immediately');
    calls.length = 0;
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
    blocked = false;
    game.has_levels = true;
    game.auto_room_manager = true;
    game.levels = [{id:'l1',name:'مرحله اول',rooms:[{id:'r1',name:'اتاق',gender:'both'}]}];
    await page.reload();
    await page.addScriptTag({content:fs.readFileSync(path.join(__dirname,'../assets/egm-games.js'),'utf8')});
    await page.addStyleTag({content:'.egm-flow-managed-save {display:none}'});
    await page.addScriptTag({content:fs.readFileSync(path.join(__dirname,'../mini apps/Event Guest Manager/egm-admin-ux.js'),'utf8')});
    await page.locator('[data-open-game]').click();
    await page.getByRole('tab', {name:/مراحل و اتاق‌ها/}).click();
    assert.equal(await page.locator('[data-level-add]').isVisible(), true);
    const beforeAdd = calls.length;
    await page.locator('[data-level-add]').click();
    assert.equal(calls.length, beforeAdd, 'Preparing the settings must not save without clicking save');
    assert.equal(await page.locator('[name="auto_mode"]').inputValue(), 'on', 'Adding a level preserves automatic rooms');
    await page.locator('[data-level-create]').waitFor();
    await page.locator('[data-level-create] [name="name"]').fill('مرحله دوم');
    await page.locator('[data-level-create] [type="submit"]').click();
    await page.locator('[data-open-level="l2"]').waitFor();
    assert.equal(game.levels.length, 2);
    console.log('Automatic-room, cover-color and no-score settings preserve booleans and CSRF; blocked host response is readable.');
  } finally {await browser.close();}
})().catch(error => {console.error(error);process.exitCode=1;});
