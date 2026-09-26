const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

(async () => {
  for (const template of ['Task Club', 'taskclub-templates/iran-map']) {
    const prizeSource = fs.readFileSync(path.join(__dirname, '../mini apps', template, 'TC Prizes.js'), 'utf8');
    const prizePrefix = prizeSource.slice(prizeSource.indexOf('  const currentScriptSrc'), prizeSource.indexOf('  const TC_PRIZE_STATUS_INTERVAL_KEY'));
    for (const club of ['First', '%D9%87%D9%86%D9%88%D8%B2']) {
      class Script { constructor(src) { this.src = src; } }
      const directory = `https://example.test/mini%20apps/missions/${club}/`;
      const context = vm.createContext({ URL, HTMLScriptElement: Script,
        document: { currentScript: new Script(directory + 'TC%20Prizes.js?v=1') },
        window: { location: { href: 'https://example.test/panel.php' } }
      });
      vm.runInContext(prizePrefix + '\nglobalThis.store = API_URL; globalThis.pot = POT_API_URL;', context);
      assert.equal(context.store, directory + 'tc_store.php');
      assert.equal(context.pot, directory + 'pot_api.php');
      class El {
        constructor(active = true) { this.classList = { contains: () => active }; this.dataset = { tcCsrf: club }; }
        closest(selector) { return selector === '.tc-shell' ? shell : tab; }
        querySelector(selector) { return selector.includes('sub-pane') ? pane : { owner: club }; }
      }
      const tab = new El();
      const pane = new El();
      const shell = new El();
      const script = new El();
      const scoped = vm.createContext({
        HTMLElement: El, document: { currentScript: script },
        CSS: { escape: id => id },
        window: {}, API_URL: directory + 'tc_store.php'
      });
      const scopedCode = prizeSource.slice(prizeSource.indexOf('  const scriptEl'), prizeSource.indexOf('  function getActiveRewardSectionKey'));
      vm.runInContext('(() => {\n' + scopedCode + '\nglobalThis.active = isRewardsPaneActive(); globalThis.control = getClubElement("tc-prize-level-form"); globalThis.token = csrfToken;\n})();', scoped);
      assert.equal(scoped.active, true, 'Generated club must initialize independently of the standard tab.');
      assert.equal(scoped.control.owner, club, 'Controls must belong to the script’s club.');
      assert.equal(scoped.token, club, 'CSRF must come from the script’s club.');
    }
    const source = fs.readFileSync(path.join(__dirname, '../mini apps', template, 'TCEventStyle.js'), 'utf8');
    const prefix = source.slice(source.indexOf('  const currentScriptSrc'), source.indexOf('  const tcShellEl'));
    for (const directory of ['mini%20apps/Task%20Club', 'mini%20apps/missions/%D9%87%D9%86%D9%88%D8%B2']) {
      class Script { constructor(src) { this.src = src; } }
      const context = vm.createContext({
        URL, HTMLScriptElement: Script,
        document: { currentScript: new Script(`https://example.test/${directory}/TCEventStyle.js?v=1`) },
        window: { location: { href: 'https://example.test/panel.php?tab=club' } }
      });
      vm.runInContext(prefix + '\nglobalThis.testApi = API_URL; globalThis.testRead = readStoreResponse;', context);
      assert.equal(context.testApi, `https://example.test/${directory}/tc_store.php`);
      assert.equal((await context.testRead({ text: async () => '{"status":"ok"}' })).status, 'ok');
      await assert.rejects(context.testRead({ text: async () => '<!DOCTYPE html>', status: 500, url: context.testApi }), /non-JSON response \(HTTP 500\).*tc_store.php/);
    }
  }
  console.log('TaskClub style endpoint and HTML response tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
