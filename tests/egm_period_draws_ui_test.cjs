const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(`${__dirname}/../mini apps/Event Guest Manager/egm-panel-local.js`, 'utf8');
const start = source.indexOf('  function periodDrawSection()');
const end = source.indexOf('  function setupPeriodDraws(', start);
const list = {textContent: '', innerHTML: '', replaceChildren() { this.loaded = true; }};
const pane = {dataset: {taskTagCode: '02'}, querySelector: () => list};
let request;
let fail = false;
const context = vm.createContext({
  URL, URLSearchParams, TASK_CLUB_CSRF: 'test-csrf',
  PERIOD_DRAWS_ENDPOINT: 'https://example.test/egm/period_draws.php',
  PERIOD_DRAW_PAGE: 'https://example.test/egm/period_draw.php',
  periodCodeForPane: p => p.dataset.taskTagCode,
  escapeHtml: s => String(s ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;'),
  window: {GlobalLazyLoader: {createInline: () => ({})}},
  fetch: async (url, options) => {
    request = JSON.parse(options.body);
    if (fail) throw new Error('Offline');
    return {ok: true, json: async () => ({status: 'ok', draws: [{id: 'abc', name: '<unsafe>', description: 'A',
      prizeName: 'Gift', winners: [], winnerLimit: 2, includeEntered: true, includeWalkIns: false}]})};
  }, pane,
});
vm.runInContext(source.slice(start, end), context);
(async () => {
  assert.match(vm.runInContext('periodDrawSection()', context), /name="includeWalkIns"/);
  await vm.runInContext('loadPeriodDraws(pane)', context);
  assert.equal(request.period_code, '02');
  assert.equal(request.csrf, 'test-csrf');
  assert.equal(list.loaded, true);
  assert.match(list.innerHTML, /&lt;unsafe>/);
  assert.match(list.innerHTML, /period_code=02&amp;level_id=abc/);
  assert.match(list.innerHTML, /type=reached_non_winners/);
  fail = true;
  await vm.runInContext('loadPeriodDraws(pane)', context);
  assert.equal(list.textContent, 'Offline');
  assert.equal(pane._drawLoading, false);
  fail = false;
  await vm.runInContext('loadPeriodDraws(pane)', context);
  assert.equal(pane._periodDraws.length, 1);
  console.log('EGM draw pane loading, scope, exports, escaping and retry tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
