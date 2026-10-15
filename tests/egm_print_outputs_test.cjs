const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const root = path.join(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/egm-web-scan.js'), 'utf8');
const choose = source.slice(source.indexOf('  tools.choosePrint ='), source.indexOf('  app.addEventListener', source.indexOf('  tools.choosePrint =')));
const scan = fs.readFileSync(path.join(root, 'api/lib/egm-check-in.php'), 'utf8');
const automatic = scan.slice(scan.indexOf('  const autoPrintEntry='), scan.indexOf('\n', scan.indexOf('  const autoPrintEntry=')));
const ticket = {id:'cnt', title:'بلیت', configured:true, card:{kind:'cnt'}};
const guest = {national_id:'0012345678', status:'success', ticket_numbers:{cnt:'42'}};
async function scenario(card, cnt, twice, auto) {
  const jobs = [], dialogs = [];
  const profile = {card_active:card, ticket_only:!card, configured:card, card:{kind:'invite'}, ticket_active:cnt, tickets:[ticket], double_print:twice, auto_print:auto};
  const tools = {printCardForRow:async(row, output, copies)=>jobs.push({kind:output.card.kind, copies, number:row.number_of_ticket})};
  const context = vm.createContext({tools, get:async()=>({print_profile:profile, print_guest:guest}), report:()=>{}, esc:v=>v,
    modal:()=>{const dialog={};dialogs.push(dialog);return dialog}, message:()=>{}, result:{}, console,
    loadPrintProfile:async()=>profile, normalize:v=>v, printCardForRow:tools.printCardForRow,
    requestTicketNumber:async()=>{throw new Error('Saved ticket must not prompt')}, recordTicketNumber:async()=>{}});
  vm.runInContext(choose + automatic + '\nglobalThis.autoPrintEntry=autoPrintEntry;', context);
  await tools.choosePrint(guest);
  if(card && cnt) {
    assert.equal(dialogs.length,1);
    for(let index=0;index<2;index++) await dialogs[0].onclick({target:{closest:()=>({dataset:{output:String(index)}})}});
  } else assert.equal(dialogs.length,0,'Single output must print without a selection dialog');
  const expected = [...(card?[{kind:'invite',copies:twice?2:1}]:[]), ...(cnt?[{kind:'cnt',copies:1}]:[])];
  assert.deepEqual(jobs.map(({kind,copies})=>({kind,copies})), expected);
  jobs.length=0;
  await context.autoPrintEntry({result:'success',logs:[guest]},guest.national_id);
  assert.deepEqual(jobs.map(({kind,copies})=>({kind,copies})), auto?expected:[]);
}
(async()=>{
  for(const [card,cnt] of [[true,false],[false,true],[true,true],[false,false]])
    for(const twice of [false,true]) for(const auto of [false,true]) await scenario(card,cnt,twice,auto);
  console.log('PASS: output switches, auto/manual printing, invitation-only double copies, and single-output reprint across 16 scenarios.');
})().catch(error=>{console.error(error);process.exitCode=1});
