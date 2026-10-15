const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync('app.js', 'utf8');
const root = 'event-guest-manager-mission-1001';
const capabilities = ['main','invitees','manage-tasks','task-access','monitoring','export','linker','logs','games','prizes','scan'];
const context = vm.createContext({window:{
 PANEL_TAB_CATALOG:[{id:'home'},{id:'settings'},{id:'event-guest-manager'},{id:root},{id:'event-guest-manager-creator'}],
 PANEL_PERMISSION_CATALOG:[{id:'home'},{id:'settings'},{id:'event-guest-manager'},{id:root}],
 PANEL_CHILD_TAB_MAP:{'event-guest-manager':capabilities.map(s=>'event-guest-manager:'+s),[root]:capabilities.map(s=>root+':'+s)},
 PANEL_ALLOWED_TABS:['home','event-guest-manager-creator']
}});
vm.runInContext(source.slice(0,source.indexOf('const HOME_ASSET_METRICS_CACHE_MS')) + '\n' + source.slice(source.indexOf('function getAllPanelTabIds()'),source.indexOf('function getUserTabPermissions(')),context);
const evaluate = code => JSON.parse(vm.runInContext('JSON.stringify('+code+')',context));
assert.deepEqual(evaluate('PANEL_ALLOWED_TABS'),['home','event-guest-manager-creator'],'Navigation aliases are still available');
assert.equal(evaluate('PANEL_TAB_IDS').includes('event-guest-manager-creator'),false,'Navigation aliases are never stored as permission IDs');
assert.deepEqual(evaluate('normalizeUserTabPermissions([])'),[]);
assert.deepEqual(evaluate('normalizeUserTabPermissions(["bogus"])'),[]);
assert.deepEqual(evaluate('normalizeUserTabPermissions(["home","settings"])'),['home','settings']);
const scoped = [root,root+':custom',root+':games'];
assert.deepEqual(evaluate('normalizeUserTabPermissions('+JSON.stringify(scoped)+')'),scoped,'Scoped selection survives editing');
assert.equal(evaluate('normalizeUserTabPermissions(["'+root+'"])').includes(root+':games'),true);
const legacy=['event-guest-manager',...capabilities.slice(0,8).map(s=>'event-guest-manager:'+s)];
assert.equal(evaluate('normalizeUserTabPermissions('+JSON.stringify(legacy)+')').includes('event-guest-manager:games'),true,'Legacy full EGM permissions retain new capabilities');
console.log('PASS: dynamic event selection, navigation aliases, explicit denial, safe defaults and PHP-compatible legacy upgrades');
