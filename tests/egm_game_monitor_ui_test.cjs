const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
(async()=>{
  const browser=await chromium.launch({headless:true,channel:'msedge'});
  try {
    const page=await browser.newPage({viewport:{width:1280,height:1100}});const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.addInitScript(()=>{window.testSockets=[];window.WebSocket=class{constructor(url){this.url=url;window.testSockets.push(this);}close(){this.onclose?.();}};});
    const game={id:'g',name:'چالش دیجیتال',has_levels:false,min_players:1,max_players:4,gender_mode:'separated',rooms:[{id:'r',name:'اتاق آبی',gender:'priority_female'}]};
    const person=(id,name,gender)=>({id,name,gender,work_id:'100'+id});
    let teams=[
      {id:1,name:'تیم امید',creator:{name:'داور اول'},members:[person(1,'علی محمدی','male')],score_attempts:{game_total:[{score:10}]},started_at:'2026-10-11T08:00:00Z',waiting_for_room:true},
      {id:2,name:'تیم ستاره',creator:{name:'داور دوم'},members:[person(2,'مریم احمدی','female')],scores:{game_total:{score:50}},started_at:'2026-10-11T08:00:00Z',room_assignment:{room_id:'r',room_name:'اتاق آبی',level_name:'چالش دیجیتال',assigned_at:new Date(Date.now()-120000).toISOString()}},
      {id:3,name:'تیم پرواز',creator:{name:'داور اول'},members:[person(3,'رضا حسینی','male')],scores:{game_total:{score:40}},ended_at:'2026-10-11T08:30:00Z'}
    ];
    let revision='one',memberSave;
    const snapshot=()=>({status:'ok',revision,server_time:new Date().toISOString(),games:[{...game,teams}],enabled:['g']});
    await page.route('https://fixture.test/games.php**',async route=>{
      const query=new URLSearchParams(route.request().postData()||new URL(route.request().url()).search);
      let data=snapshot();
      if(query.get('action')==='search_team_members')data={status:'ok',members:[person(4,'حسن کریمی','male'),{...person(5,'عضو اشغال‌شده','male'),already_in_team:true}]};
      if(query.get('action')==='update_team_members'){
        memberSave={ids:JSON.parse(query.get('member_ids')),expected:JSON.parse(query.get('expected_members'))};
        teams[0].members=[person(4,'حسن کریمی','male')];revision='three';data={status:'ok',team:teams[0]};
      }
      await route.fulfill({contentType:'application/json',body:JSON.stringify(data)});
    });
    await page.route('https://fixture.test/',route=>route.fulfill({contentType:'text/html',body:'<html lang="fa" dir="rtl"><meta charset="utf-8"><style>body{font-family:Tahoma;background:#f4f7fb;margin:20px}.card{background:white;padding:24px;border-radius:20px;max-width:1150px;margin:auto}.btn{font:inherit;border:1px solid #cbd5e1;border-radius:9px;padding:9px 12px;background:white;cursor:pointer}.primary{background:#0873ad;color:white}.muted{color:#64748b}.field span{display:block;margin-bottom:6px}.egm-task-top-section{display:block}</style><div class="egm-shell" data-egm-games-endpoint="/games.php" data-egm-can-manage-games="1" data-egm-can-end-games="1"><div class="sub-pane active" data-task-pane="1" data-task-tag-code="01"><button data-task-top-trigger="games">بازی‌ها</button><div class="card" data-egm-period-games><div data-period-games-list></div><p data-period-games-status role="status"></p></div></div></div></html>'}));
    await page.goto('https://fixture.test/');await page.addScriptTag({content:fs.readFileSync('assets/egm-games.js','utf8')});
    await page.locator('[data-task-top-trigger]').click();await page.locator('[data-monitor-team="1"]').waitFor();
    assert.deepEqual(await page.locator('[data-ranking-gender="male"] [data-monitor-team]').evaluateAll(nodes=>nodes.map(node=>node.dataset.monitorTeam)),['3','1']);
    assert.equal(await page.locator('[data-period-game-id]').count(),0,'Activation checkbox must be separated from rankings');
    const timer=await page.locator('[data-monitor-team="2"] time').textContent();await page.waitForTimeout(1100);assert.notEqual(await page.locator('[data-monitor-team="2"] time').textContent(),timer);
    await page.locator('[data-monitor-search]').fill('رضا');assert.equal(await page.locator('[data-monitor-team]').count(),1);await page.locator('[data-monitor-search]').fill('');
    await page.locator('[data-monitor-filter]').selectOption('waiting');assert.equal(await page.locator('[data-monitor-team]').count(),1);await page.locator('[data-monitor-filter]').selectOption('all');
    teams[0].score_attempts.game_total[0].score=70;revision='two';
    await page.evaluate(data=>window.testSockets[0].onmessage({data:JSON.stringify({type:'snapshot',snapshot:data})}),snapshot());
    assert.deepEqual(await page.locator('[data-ranking-gender="male"] [data-monitor-team]').evaluateAll(nodes=>nodes.map(node=>node.dataset.monitorTeam)),['1','3']);
    assert.equal(await page.locator('[data-monitor-connection]').getAttribute('data-live'),'1');
    await page.locator('[data-monitor-team="1"] [data-team-score-open]').click();await page.locator('[data-round-score]').fill('75');
    revision='room-change';await page.evaluate(data=>window.testSockets[0].onmessage({data:JSON.stringify({type:'snapshot',snapshot:data})}),snapshot());
    assert.equal(await page.locator('[data-round-score]').inputValue(),'75','Live update erased score draft');
    page.once('dialog',dialog=>dialog.accept());await page.locator('[data-team-score-back]').click();await page.locator('[data-monitor-team="1"]').waitFor();
    await page.locator('[data-monitor-team="1"] [data-team-members-open]').click();await page.locator('[data-member-search] input').fill('حسن');await page.locator('[data-member-search] button').click();
    await page.locator('[data-member-add="4"]').click();assert.equal(await page.locator('[data-member-add="5"]').isDisabled(),true);
    await page.locator('[data-member-remove="1"]').click();await page.locator('[data-members-save]').click();await page.getByText('اعضای تیم ذخیره شد.').waitFor();
    assert.deepEqual(memberSave,{ids:[4],expected:[1]});
    fs.mkdirSync('outputs/egm-monitor',{recursive:true});
    await page.screenshot({path:'outputs/egm-monitor/desktop.png',fullPage:true});
    await page.setViewportSize({width:390,height:844});await page.screenshot({path:'outputs/egm-monitor/mobile.png',fullPage:true});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'Mobile layout overflows');
    game.gender_mode='normal'; game.has_levels=true; game.levels=[{id:'game_total',name:'لیزر'},{id:'b',name:'گرید'}];
    teams[0].scores={game_total:{score:70}}; teams[0].score_attempts={game_total:{'0':{score:35},'1':{score:35}},b:{'1':{score:5}}};
    teams[2].scores.game_total.score=75; revision='normal-ties';
    await page.locator('[data-monitor-search]').fill('تیم');
    await page.evaluate(data=>window.testSockets[0].onmessage({data:JSON.stringify({type:'snapshot',snapshot:data})}),snapshot());
    assert.equal(await page.locator('[data-monitor-search]').evaluate(node=>node===document.activeElement),true,'Live update stole search focus');
    assert.deepEqual(await page.locator('[data-ranking-gender="all"] [data-monitor-team]').evaluateAll(nodes=>nodes.map(node=>[node.dataset.monitorTeam,node.dataset.rank])),[['1','1'],['3','1'],['2','3']],'Normal games must share ranking with ties and partial rounds');
    assert.equal(await page.locator('table.egm-ranking-table thead th').count(),7,'Each level needs a table column');
    assert.match(await page.locator('[data-monitor-team="1"] [data-ranking-level="game_total"]').textContent(),/۳۵/,'Separate round scores missing');
    await page.locator('[data-monitor-search]').fill('پرواز'); assert.equal(await page.locator('[data-monitor-team="3"]').getAttribute('data-rank'),'1','Filtering must preserve actual rank');
    await page.locator('[data-monitor-search]').fill('');
    await page.locator('[data-monitor-team="1"] [data-team-score-open]').click();
    assert.equal(await page.locator('[data-round-score][data-level-id="b"]').getAttribute('data-round-index'),'1','Legacy sparse round index must remain correct for saving');
    await page.locator('[data-team-score-back]').click();
    await page.evaluate(()=>window.testSockets[0].close());assert.equal(await page.locator('[data-monitor-connection]').getAttribute('data-live'),'0');
    teams[2].scores.game_total.score=99; revision='http-fallback';
    await page.waitForFunction(()=>document.querySelector('[data-ranking-gender="all"] [data-monitor-team]')?.dataset.monitorTeam==='3',{},{timeout:12000});
    assert.deepEqual(errors,[]);
    console.log('PASS: sorted gender rankings, filters, live reorder, room timers, preserved score drafts, member add/remove, mobile layout, normal-game ties, sparse rounds, focus preservation and HTTP polling fallback');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
