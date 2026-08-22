const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const panelFiles = [
  path.join(root, 'mini apps', 'Event Guest Manager', 'egm-panel-local.js'),
  path.join(root, 'mini apps', 'EGMs', 'EGM', 'egm-panel-local.js')
];

function loadMappingApi(file) {
  const source = fs.readFileSync(file, 'utf8');
  const start = source.indexOf('  function normalizePeriodExcelHeader');
  const end = source.indexOf('  function applyPeriodExcelSheet', start);
  assert.notEqual(start, -1, `Could not find Excel normalization in ${file}`);
  assert.notEqual(end, -1, `Could not find the end of Excel mapping helpers in ${file}`);
  const context = {};
  vm.runInNewContext(
    `${source.slice(start, end)}\nthis.mappingApi = { normalizePeriodExcelHeader, suggestPeriodExcelColumn, periodExcelMappings };`,
    context,
    { filename: file }
  );
  return context.mappingApi;
}

const uploadedHeaders = [
  'نام',
  'نام خانوادگي',
  'شماره پرسنلي',
  'شماره موبایل شخصی',
  'ایمیل',
  'جنسیت ',
  'کد ملی',
  'اداره',
  'اداره کل',
  'معاونت',
  'سطح پست',
  'عنوان شغل'
];

const expectedIndexes = {
  national: '6',
  work: '2',
  first: '0',
  last: '1',
  phone: '3',
  deputy: '9',
  'general-department': '8',
  department: '7',
  gender: '5',
  'postal-level': '10'
};

for (const file of panelFiles) {
  const api = loadMappingApi(file);
  const aliasesByKey = Object.fromEntries(api.periodExcelMappings);

  assert.equal(api.normalizePeriodExcelHeader('نام خانوادگي'), 'نام خانوادگی');
  assert.equal(api.normalizePeriodExcelHeader('كد ملى'), 'کد ملی');
  assert.equal(api.normalizePeriodExcelHeader('شماره\u200cپرسنلي'), 'شماره پرسنلی');

  for (const [key, expectedIndex] of Object.entries(expectedIndexes)) {
    assert.equal(
      api.suggestPeriodExcelColumn(uploadedHeaders, aliasesByKey[key]),
      expectedIndex,
      `${key} was not detected from the uploaded workbook headers in ${file}`
    );
  }

  assert.equal(api.suggestPeriodExcelColumn(['frist name'], ['first name']), '0');
  assert.equal(api.suggestPeriodExcelColumn(['last nmae'], ['last name']), '0');
  assert.equal(api.suggestPeriodExcelColumn(['unrelated organizational value'], ['work id']), '');
}

console.log('EGM Excel column mapping tests passed.');
