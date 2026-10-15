const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const XLSX=require('../mini apps/Event Guest Manager/vendor/xlsx/xlsx.full.min.js');
const source=fs.readFileSync('mini apps/Event Guest Manager/egm-panel-local.js','utf8');
const a=source.indexOf('  function periodImportWorkId('),b=source.indexOf('  function renderPeriodUnmatchedRows',a);
const state={unmatchedRows:[
 {source_row:2,first_name:'بدون شناسه',work_id:'N/A',phone_number:'09123456789',can_invite:false},
 {source_row:3,first_name:'تعارض',national_id:'0012345678',work_id:'02',match_error:'کدها به دو کاربر متفاوت تعلق دارند.',can_invite:false},
 {source_row:4,first_name:'آماده',national_id:'0023456789',can_invite:true},
 {source_row:5,first_name:'دعوت قبلی',national_id:'0034567890',can_invite:false,invited:true}
]};
let bytes,message='',failed=false;const button={disabled:false};
const context=vm.createContext({window:{XLSX:{...XLSX,writeFile:wb=>{if(failed)throw new Error('دانلود مسدود شد');bytes=XLSX.write(wb,{type:'buffer',bookType:'xlsx'});}}},getPeriodInviteState:()=>state,periodExportDatedFilename:()=> 'blocked.xlsx'});
vm.runInContext(source.slice(a,b)+'\nthis.exportBlocked=exportPeriodUninviteable;this.cleanWork=periodImportWorkId;',context);
for(const value of ['-','N/A','—','NULL'])assert.equal(context.cleanWork(value),'');
assert.equal(context.cleanWork('02'),'02');
const pane={querySelector:key=>key.includes('status')?{set textContent(value){message=value;}}:button};
context.exportBlocked(pane);assert.ok(bytes,message);
const wb=XLSX.read(bytes,{type:'buffer'});const sheet=wb.Sheets[wb.SheetNames[0]];const rows=XLSX.utils.sheet_to_json(sheet,{header:1,raw:true,defval:''});
assert.equal(rows.length,3,'Should export missing-ID and conflict rows, not ready or previously invited rows');
assert.equal(rows[1][3],'');assert.equal(rows[1][4],'09123456789');assert.equal(rows[2][2],'0012345678');assert.equal(rows[2][3],'02');assert.match(rows[2][8],/دو کاربر/);
assert.equal(sheet.C3.t,'s');assert.equal(sheet.E2.t,'s');assert.equal(button.disabled,false);
failed=true;context.exportBlocked(pane);assert.equal(message,'دانلود مسدود شد');assert.equal(button.disabled,false,'Failed export left button locked');
console.log('PASS: real XLSX export includes conflict/missing IDs and reason, preserves text IDs/phone zeros, excludes prior invitations, shows errors and unlocks button');
