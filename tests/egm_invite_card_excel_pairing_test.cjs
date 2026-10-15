const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const XLSX = require('../mini apps/Event Guest Manager/vendor/xlsx/xlsx.full.min.js');

(async () => {
  const source = fs.readFileSync(path.join(__dirname, '../mini apps/Event Guest Manager/egm-panel-local.js'), 'utf8');
  const start = source.indexOf('  async function exportPeriodInviteCardLinks(pane)');
  const end = source.indexOf('  function periodSourceLabel', start);
  assert.ok(start >= 0 && end > start);
  const rows = Array.from({length: 300}, (_, index) => {
    const id = (index * 137) % 300;
    return {full_name: `مهمان ${id}`, national_id: String(id).padStart(10, '0'),
      work_id: String(id).padStart(5, '0'), phone_number: `0912${String(id).padStart(7, '0')}`,
      invite_url: `https://example.test/Invited/StableGuest${id}`};
  });
  let bytes;
  let message = '';
  const status = {get textContent() {return message;}, set textContent(value) {message = value;}};
  const context = vm.createContext({
    window: {XLSX: {...XLSX, writeFile: workbook => {bytes = XLSX.write(workbook, {type: 'buffer', bookType: 'xlsx'});}}},
    HTMLButtonElement: class {},
    requestPeriodInviteCards: async () => ({rows, period_date: '2026-10-09'}),
    periodCodeForPane: () => '01', periodDateForPane: () => '2026-10-09',
    periodExportDatedFilename: () => 'cards.xlsx',
  });
  vm.runInContext(source.slice(start, end) + '\nthis.runExport = exportPeriodInviteCardLinks;', context);
  await context.runExport({querySelector: selector => selector.includes('status') ? status : null});
  assert.ok(bytes, message);
  const workbook = XLSX.read(bytes, {type: 'buffer'});
  const sheet = workbook.Sheets[workbook.SheetNames[0]];
  const result = XLSX.utils.sheet_to_json(sheet, {header: 1, raw: true});
  assert.equal(result.length, rows.length + 1);
  rows.forEach((row, index) => {
    assert.deepEqual(result[index + 1], [row.full_name, row.national_id, row.work_id, row.phone_number, row.invite_url]);
    assert.equal(sheet[`E${index + 2}`].l.Target, row.invite_url, 'Clickable link must match the same guest row');
    for (const column of ['B', 'C', 'D']) assert.equal(sheet[`${column}${index + 2}`].t, 's');
  });
  console.log('300 invite-card XLSX rows preserve names, IDs, phone numbers and matching clickable links after serialization.');
})().catch(error => {console.error(error); process.exitCode = 1;});
