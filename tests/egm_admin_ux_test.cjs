const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const php = process.env.PHP_BINARY || 'php';
const cards = execFileSync(php, ['-r',
  'require ' + JSON.stringify(path.join(root, 'api/lib/egm-invite-card-pane.php').replaceAll('\\', '/')) +
  '; renderEgmInviteCardPane("card.php", true);'], {encoding: 'utf8'});
const period = {id:'p1', tagCode:'001', title:'بازه آزمایشی', order:1, active:false, duration:false};
const game = {id:'1234567890abcdef', name:'بازی آزمایشی', has_levels:true, levels:[], rooms:[], min_players:1, max_players:20, gender_mode:'normal', auto_room_manager:false};
const fixture = '<html lang="fa" dir="rtl"><div class="egm-shell" data-egm-can-manage-games="1">' +
  '<div data-egm-sub-layout><aside class="sub-sidebar"><div class="sub-nav">' +
  '<button class="sub-item active" data-pane="egm-invite-card">کارت دعوت</button><button class="sub-item" data-pane="egm-games">بازی‌ها</button><div data-egm-task-subtab-nav></div></div></aside>' +
  cards + '<div data-egm-task-subtab-panes></div>' +
  '<div class="sub-pane" data-pane="egm-games" data-egm-games-catalog><form data-games-create><input name="name" required></form>' +
  '<div data-games-list></div><p data-games-status></p></div></div></div></html>';

(async () => {
  const browser = await chromium.launch({headless:true, channel:process.env.BROWSER_CHANNEL || 'msedge'});
  try {
    const page = await browser.newPage({viewport:{width:1200, height:900}});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    let savedGame = false;
    await page.route('http://egm.test/**', async route => {
      if (route.request().resourceType() === 'document') return route.fulfill({contentType:'text/html', body:fixture});
      const url = route.request().url();
      let response = {status:'ok', tasks:[period], draws:[], groups:[], items:[], rows:[], filters:{}, source:'oeu', total:0, page:1, pages:1, data:{}};
      if (url.includes('games.php')) {
        const posted = route.request().postData();
        const body = posted ? JSON.parse(new URLSearchParams(posted).get('payload')) : null;
        if (body?.action === 'save' && body.id) { game.name = body.name; savedGame = true; }
        response = {status:'ok', games:[game], enabled:[]};
      }
      await route.fulfill({contentType:'application/json', body:JSON.stringify(response)});
    });
    await page.goto('http://egm.test/panel.php');
    for (const file of ['style/styles.css','mini apps/Event Guest Manager/egm-panel.css']) {
      await page.addStyleTag({content:fs.readFileSync(path.join(root,file),'utf8')});
    }
    for (const file of ['mini apps/Event Guest Manager/egm-panel-local.js','assets/egm-games.js','mini apps/Event Guest Manager/egm-admin-ux.js']) {
      await page.addScriptTag({content:fs.readFileSync(path.join(root,file),'utf8')});
    }
    await page.waitForSelector('[data-task-pane] [data-egm-flow]', {state:'attached'});
    const sectionSearch = page.locator('.egm-navigation-search');
    await sectionSearch.evaluate(input => {
      input.value = 'kadkhodaee';
      input.dispatchEvent(new Event('input', {bubbles:true}));
    });
    assert.equal(await sectionSearch.inputValue(), '', 'Login autofill must not filter EGM navigation');
    assert.equal(await page.locator('.sub-nav .sub-item[hidden]').count(), 0);
    await sectionSearch.pressSequentially('نام ناموجود');
    assert.equal(await page.locator('.sub-nav .sub-item:not([hidden])').count(), 0, 'Intentional typing must still filter');
    await sectionSearch.press('ControlOrMeta+A');
    await sectionSearch.press('Backspace');
    assert.equal(await page.locator('.sub-nav .sub-item[hidden]').count(), 0);
    const card = page.locator('[data-egm-invite-card-pane]');
    assert.equal(await card.locator('.egm-flow-step').count(), 4);
    await card.locator('.egm-flow-step').nth(1).click();
    const editor = card.locator('[contenteditable="true"]').first();
    await editor.fill('متن آزمایشی');
    await card.locator('.egm-flow-step').nth(3).click();
    assert.equal(await card.locator('[data-invite-card-invitee-search]').isVisible(), true);
    await card.locator('.egm-flow-step').nth(1).click();
    assert.equal(await editor.innerText(), 'متن آزمایشی');
    // The actual period renderer and delegated controls must still work after moving fields.
    await page.locator('.sub-nav [data-task-id="p1"]').click();
    const periodPane = page.locator('[data-task-pane]');
    const operations = periodPane.locator('[data-task-top-section="control"] > .egm-period-operations');
    assert.equal(await operations.isVisible(), true, 'Period operations must be immediately visible');
    assert.equal(await operations.locator('[data-action="reset-period-attendance"]').count(), 1);
    assert.equal(await operations.locator('.egm-period-guest-control').getAttribute('target'), '_blank');

    await periodPane.locator('[data-task-field="taskTitle"]').fill('نام جدید');
    await periodPane.locator('.egm-flow-step').nth(1).click();
    await periodPane.locator('[data-task-field="quitRequired"]').check();
    assert.equal(await operations.isVisible(), true, 'Period operations must stay visible on other settings steps');
    assert.equal(await periodPane.locator('[data-task-field="duration"]').isChecked(), true);
    await periodPane.locator('.egm-flow-step').nth(0).click();
    assert.equal(await periodPane.locator('[data-task-field="taskTitle"]').inputValue(), 'نام جدید');
    await periodPane.locator('[data-task-field="taskTitle"]').focus();
    await page.waitForTimeout(250);
    assert.equal(await periodPane.locator('[data-task-field="taskTitle"]').evaluate(node => document.activeElement === node), true);
    await periodPane.locator('[data-task-top-trigger="invite"]').click();
    const invitation = periodPane.locator('[data-task-top-section="invite"]');
    await invitation.locator('[data-period-invite-filter-form] input[name="q"]').fill('مهمان');
    await invitation.locator('[data-period-invite-filter-form] button[type="submit"]').click();
    assert.equal(await invitation.locator(':scope > .egm-flow-nav .egm-flow-step').nth(1).getAttribute('aria-selected'), 'true');
    await invitation.locator(':scope > .egm-flow-nav .egm-flow-step').first().click();
    assert.equal(await invitation.locator('[data-period-invite-filter-form] input[name="q"]').inputValue(), 'مهمان');
    // Runtime period navigation uses the same presentation as main-control navigation.
    const periodNav = periodPane.locator('.egm-task-top-shell > .egm-task-top-nav');
    const matchingStyles = await periodNav.evaluate(nav => {
      const read = () => {
        const tab = nav.querySelector('.egm-task-top-item.active');
        const styles = getComputedStyle(tab), layout = getComputedStyle(nav);
        return [styles.borderRadius, styles.backgroundColor, styles.borderBottomWidth, styles.marginInlineStart, layout.gap, layout.flexWrap, layout.overflowX];
      };
      const period = read(); nav.classList.add('egm-control-nav'); const main = read(); nav.classList.remove('egm-control-nav');
      return {period,main};
    });
    assert.deepEqual(matchingStyles.period, matchingStyles.main, 'Period and main tabs must share computed styles');
    assert.equal(matchingStyles.period[0], '10px');
    assert.equal(matchingStyles.period[5], 'wrap');
    for (const width of [1200,390]) {
      await page.setViewportSize({width,height:900});
      const layout = await periodNav.evaluate(nav => ({width:nav.clientWidth,scroll:nav.scrollWidth,rows:new Set([...nav.children].map(tab=>Math.round(tab.getBoundingClientRect().top))).size}));
      assert(layout.scroll<=layout.width+1, 'Period tabs must not scroll horizontally at '+width);
      if (width===390) assert(layout.rows>1, 'Mobile period tabs must wrap onto multiple rows');
      await periodPane.locator('[data-task-top-trigger="export"]').click();
      assert.equal(await periodPane.locator('[data-task-top-trigger="export"]').getAttribute('aria-selected'),'true');
      assert.equal(await periodPane.locator('[data-task-top-section="export"]').isVisible(),true);
      await periodPane.locator('[data-task-top-trigger="control"]').click();
    }
    await page.setViewportSize({width:1200,height:900});
    // One action saves the game before advancing to stages and rooms.
    await page.locator('.sub-nav [data-pane="egm-games"]').click();
    await page.locator('[data-open-game]').click();
    await page.waitForSelector('[data-egm-flow^="game:"]');
    await page.locator('[data-game-settings] input[name="name"]').fill('بازی جدید');
    await page.locator('[data-egm-flow^="game:"] .egm-flow-footer .primary').click();
    await page.waitForFunction(() => document.querySelector('[data-egm-flow^="game:"] .egm-flow-step:nth-child(2)')?.getAttribute('aria-selected') === 'true');
    assert.equal(savedGame, true);
    assert.equal(game.name, 'بازی جدید');
    // New forms rendered by existing game actions are styled without losing focus.
    await page.locator('[data-level-add]').click();
    await page.locator('[data-level-create] input').fill('مرحله جدید');
    await page.waitForTimeout(250);
    assert.equal(await page.locator('[data-level-create] input').evaluate(node => document.activeElement === node), true);
    assert.equal(await page.locator('[data-level-create] input').evaluate(node => getComputedStyle(node).borderRadius), '10px');
    await page.setViewportSize({width:390, height:844});
    assert.equal(await page.locator('[data-level-create] input').evaluate(node => node.getBoundingClientRect().right <= innerWidth), true);
    if (process.env.EGM_UX_SCREENSHOT) await page.screenshot({path:process.env.EGM_UX_SCREENSHOT, fullPage:true});
    assert.deepEqual(errors, []);
    console.log('EGM admin UX browser tests passed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

