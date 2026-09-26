const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

(async () => {
  for (const template of ['Task Club', 'taskclub-templates/iran-map']) {
    const source = fs.readFileSync(path.join(__dirname, '../mini apps', template, 'invitees.php'), 'utf8');
    class Root { constructor() { this.dataset = {}; } }
    const root = new Root();
    const otherRoot = new Root();
    const initialize = vm.createContext({
      HTMLElement: Root, CSS: { escape: id => id }, URL,
      document: { currentScript: { closest: () => root }, baseURI: 'https://example.test/panel.php' }
    });
    const initStart = source.indexOf('  const inviteesRoot =');
    const initEnd = source.indexOf('  const INVITEES_DOCUMENT_CLICK_HANDLER_KEY');
    const initCode = source.slice(initStart, initEnd).replace(/<\?=[\s\S]*?\?>/g, '"mini%20apps/missions/First/"');
    const initializeScript = '(() => {\n' + initCode + '\nglobalThis.initializations = (globalThis.initializations || 0) + 1;\n})();';
    vm.runInContext(initializeScript, initialize);
    vm.runInContext(initializeScript, initialize);
    assert.equal(initialize.initializations, 1, 'The same controls must not receive duplicate file state handlers.');
    assert.equal(otherRoot.dataset.tcInviteesInitialized, undefined);
    const handlers = {};
    const control = name => ({ disabled: false, value: '0', addEventListener: (event, fn) => { handlers[name + ':' + event] = fn; }, appendChild() {}, click() {} });
    const pickBtn = control('pick');
    const fileInput = control('file');
    fileInput.files = [{ name: 'invitees.xlsx', arrayBuffer: async () => new ArrayBuffer(0) }];
    const mapBtn = control('map');
    const uploadBtn = control('upload');
    const messages = [];
    const requests = [];
    let opened = 0;
    let release;
    fileInput.files[0].arrayBuffer = () => new Promise(resolve => { release = resolve; });
    const context = vm.createContext({
      pickBtn, fileInput, mapBtn, uploadBtn, fileNameEl: {}, closeBtns: [],
      mapWork: control('work'), mapFirst: control('first'), mapLast: control('last'), mapNational: control('national'), mapPhone: control('phone'),
      document: { createElement: () => ({}) },
      window: { XLSX: { read: () => ({ SheetNames: ['Sheet1'], Sheets: { Sheet1: {} } }), utils: { sheet_to_json: () => [['Work ID', 'Name'], ['100', 'Test']] } } },
      URL, INVITEES_BASE_URL: 'https://example.test/mini%20apps/missions/First/', csrfToken: 'test-token',
      setMsg: message => messages.push(message), showProgress() {}, hideProgress() {}, closeModal() {}, openModal: () => { opened++; },
      fetch: async (url, options) => { requests.push({ url, options }); return { ok: true, status: 200, url, text: async () => '{"status":"ok"}' }; }
    });
    const block = source.slice(source.indexOf('  const csvEscape ='), source.indexOf('  anyPasswordSaveBtn?.addEventListener'));
    vm.runInContext('let parsedRows = []; let headerRow = []; let parsingFile = false;\n' + block, context);
    const parsing = handlers['file:change']();
    assert.equal(mapBtn.disabled, true);
    await handlers['map:click']();
    assert.equal(messages.includes('ابتدا فایل را انتخاب کنید.'), false);
    release(new ArrayBuffer(0));
    await parsing;
    assert.equal(mapBtn.disabled, false);
    await handlers['map:click']();
    assert.equal(opened, 1);
    await handlers['upload:click']();
    assert.equal(requests[0].url, context.INVITEES_BASE_URL + 'invitees_upload.php');
    const payload = JSON.parse(requests[0].options.body);
    assert.equal(payload.csrf, 'test-token');
    assert.ok(payload.csv.includes('100'));
    assert.equal(uploadBtn.disabled, false);
    context.fetch = async url => ({ ok: true, status: 200, url, text: async () => '<!DOCTYPE html>' });
    await handlers['upload:click']();
    assert.match(messages.at(-1), /non-JSON.*HTTP 200.*invitees_upload.php/);
    const inline = source.slice(source.indexOf('<script>') + '<script>'.length, source.lastIndexOf('</script>'));
    new vm.Script(inline.replace(/<\?=[\s\S]*?\?>/g, 'null'));
  }
  console.log('TaskClub invitee parse, mapping, instance upload and error tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
