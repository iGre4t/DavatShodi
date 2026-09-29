const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
for (const template of ['Task Club', 'taskclub-templates/iran-map']) {
  const source = fs.readFileSync(`${__dirname}/../mini apps/${template}/invitees.php`, 'utf8');
  const start = source.indexOf('  const inviteesTemplate =');
  const end = source.indexOf('  const setMsg =', start);
  class Element {}
  const rows = Array.from({length: 2758}, (_, index) => ({getAttribute: () => `person ${index}`}));
  const body = new Element();
  body.querySelector = selector => selector === 'template' ? {content: {querySelectorAll: () => rows}} : null;
  body.replaceChildren = (...visible) => {body.visible = visible;};
  const controls = {};
  const context = vm.createContext({HTMLElement: Element, allInviteesTableBody: body,
    allInviteesSearchInput: {value: ''}, allInviteesSearchMetaEl: new Element(),
    normalizeSearchValue: value => value.toLowerCase(), updateBulkSelection() {},
    getInviteeElement: id => controls[id] ||= {}});
  vm.runInContext(source.slice(start, end) + '\napplyAllInviteesSearch();', context);
  assert.equal(body.visible.length, 100);
  vm.runInContext('inviteesPage = 27; applyAllInviteesSearch();', context);
  assert.equal(body.visible.length, 58);
  context.allInviteesSearchInput.value = 'person 2757';
  vm.runInContext('inviteesPage = 0; applyAllInviteesSearch();', context);
  assert.equal(body.visible.length, 1);
  assert.equal(body.visible[0], rows[2757]);
  context.allInviteesSearchInput.value = 'missing';
  vm.runInContext('applyAllInviteesSearch();', context);
  assert.equal(body.visible.length, 0);
}
console.log('TaskClub pagination and full-list search tests passed.');
