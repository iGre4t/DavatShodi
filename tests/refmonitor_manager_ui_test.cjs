const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
(async()=>{
  const browser=await chromium.launch({channel:'chrome',headless:true});
  try {
    const page=await browser.newPage({viewport:{width:390,height:844}});
    const source=fs.readFileSync('mini apps/Event Guest Manager/RefMonitor.php','utf8');
    const styles=[...source.matchAll(/<style nonce="[^\n]+?">([\s\S]*?)<\/style>/g)].map(match=>match[1]).join('\n');
    await page.setContent(`<meta name="viewport" content="width=device-width, initial-scale=1"><style>${styles}\n${fs.readFileSync('assets/refmonitor-manager.css','utf8')}</style><main class="app" dir="rtl"><section class="phone"><header class="topbar"><button data-back>برگشت</button></header><div class="main-area" data-ref-view="home"><button data-manager-open="games">مدیریت بازی و اتاق</button><button data-manager-open="teams">مدیریت تیم‌ها</button></div><div class="main-area hidden" data-ref-view="manager"><div id="ref-manager-content"></div><p id="ref-manager-status" role="status"></p></div></section></main>`);
    await page.evaluate(()=>{
      const game={id:'g',name:'بازی آزمایش',has_levels:true,gender_mode:'normal',auto_room_manager:true,no_score_needed:false,require_cover_color:false,min_players:1,max_players:20,score_count:2,levels:[{id:'l',name:'لیزر',rooms:[{id:'r',name:'اتاق اول',gender:'both',enabled:true}]}],rooms:[],revision:'game-1'};
      let team={id:1,name:'تیم اول',created_by:'facilitator:a',creator:{name:'داور اول'},members:[{id:1,name:'عضو اول',work_id:'101'}],scores:{},score_attempts:{l:[{score:10}]},score_count:2,started_at:'now',ended_at:null,cover_color:'',revision:'team-1',total_score:0};
      const owners=[{code:'facilitator:a',name:'داور اول',username:'first'},{code:'facilitator:b',name:'داور دوم',username:'second'}];
      window.calls=[];
      window.managerFixture={setRounds(value){team.score_attempts.l=value;}};
      window.RefMonitorManagerAPI={period:{code:'01',title:'بازهٔ اول'},request:async(action,fields)=>{
        const input=JSON.parse(fields.payload);window.calls.push(input);
        if(input.method==='games')return{games:[game],enabled:['g']};
        if(input.method==='settings'){Object.assign(game,input);return{games:[game]};}
        if(input.method==='teams')return{teams:[team,{...team,id:2,name:'تیم دوم',created_by:'facilitator:b',creator:{name:'داور دوم'}}],owners};
        if(input.method==='team')return{team:structuredClone(team),owners};
        if(input.method==='room'){
          if(!input.confirmed)return{requires_confirmation:true,teams:[{name:'تیم اول'}]};
          game.levels[0].rooms[0].enabled=input.enabled;return{requires_confirmation:false};
        }
        if(input.method==='search')return{people:[{key:'o:7',name:'کاربر سازمان',source:'کاربر سازمان',work_id:'107'}]};
        if(input.method==='members'){team.members=[{id:7,name:'کاربر سازمان',work_id:'107'}];team.revision='members';return{team:structuredClone(team)};}
        if(input.method==='details'){team.name=input.name;team.created_by=input.owner;team.creator=owners.find(owner=>owner.code===input.owner);team.revision='details';return{team:structuredClone(team)};}
        if(input.method==='score'){team.score_attempts.l[input.index]={score:Number(input.score)};team.revision='score-'+input.index;return{team:structuredClone(team)};}
        if(input.method==='disband')return{team:{disbanded:true}};
        throw Error('Unexpected '+input.method);
      },open(view,gameId,periodCode,teamId,step){
        const state={view,gameId,periodCode,teamId,step};window.managerState=state;
        document.querySelector('[data-ref-view=home]').classList.toggle('hidden',view!=='home');
        document.querySelector('[data-ref-view=manager]').classList.toggle('hidden',view!=='manager');
        if(view==='manager')window.RefMonitorManager.show(state);
      }};
      window.RefMonitorManagerAPI.replaceView=window.RefMonitorManagerAPI.open;
      document.querySelector('[data-back]').onclick=()=>window.RefMonitorManager.back(window.managerState);
    });
    await page.addScriptTag({path:path.resolve('assets/refmonitor-manager.js')});
    await page.locator('[data-manager-open=games]').click();
    await page.locator('[data-rm-game=g]').click();
    await page.locator('[name=score_count]').fill('3');
    await page.locator('[data-rm-settings] button[type=submit]').click();
    await page.waitForFunction(()=>document.querySelector('#ref-manager-status').textContent.includes('ذخیره'));
    assert.equal(await page.locator('[name=score_count]').inputValue(),'3');
    await page.locator('[data-rm-tab=rooms]').click();
    await page.locator('[data-rm-room=r]').click();
    await page.locator('[data-confirm]').click();
    await page.waitForFunction(()=>!document.querySelector('[data-rm-room]').checked&&!document.querySelector('#ref-manager-content').hasAttribute('aria-busy'));
    assert.equal(await page.locator('[data-rm-delete-room]').count(),0,'Room deletion exposed');
    assert.equal(await page.locator('[data-rm-room-name]').count(),0,'Room rename exposed');
    await page.locator('[data-back]').click();await page.locator('[data-back]').click();
    await page.locator('[data-manager-open=teams]').click();await page.locator('[data-rm-game=g]').click();
    assert.match(await page.locator('#ref-manager-content').innerText(),/داور اول/);assert.match(await page.locator('#ref-manager-content').innerText(),/داور دوم/);
    await page.locator('[data-rm-team="1"]').click();
    await page.locator('[name=name]').fill('نام جدید');await page.locator('[name=owner]').selectOption('facilitator:b');
    await page.locator('[data-rm-details] button[type=submit]').click();
    await page.waitForFunction(()=>document.querySelector('#ref-manager-content h1').textContent==='نام جدید');
    await page.locator('[data-rm-tab=members]').click();
    await page.locator('[data-rm-remove]').click();await page.locator('[data-rm-search]').fill('107');
    await page.locator('[data-rm-add="o:7"]').click();await page.locator('[data-rm-save-members] button[type=submit]').click();
    await page.waitForFunction(()=>document.querySelector('[data-rm-members]').textContent.includes('کاربر سازمان'));
    await page.locator('[data-rm-tab=scores]').click();
    await page.locator('[data-level=l][data-index="0"]').fill('15');await page.locator('[data-level=l][data-index="1"]').fill('20');
    await page.locator('[data-rm-scores] button[type=submit]').click();
    await page.waitForFunction(()=>document.querySelector('#ref-manager-status').textContent.includes('امتیازها ذخیره'));
    const scores=await page.evaluate(()=>calls.filter(call=>call.method==='score'));
    assert.equal(scores[0].expected_score,10);assert.equal(scores[1].revision,'score-0','Score batch used stale team revision');
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Mobile content overflowed screen');
    await page.screenshot({path:'outputs/refmonitor-manager-mobile.png'});
    await page.locator('[data-rm-tab=details]').click();await page.locator('[data-rm-control=disband]').click();
    await page.locator('[data-cancel]').click();assert.equal(await page.evaluate(()=>calls.some(call=>call.method==='disband')),false);
    await page.locator('[data-rm-control=disband]').click();await page.locator('[data-confirm]').click();
    await page.locator('[data-rm-team="2"]').waitFor();
    assert.equal(await page.evaluate(()=>calls.filter(call=>call.method==='disband').length),1);
    // Reload the component with the Head Referee role on a fresh document.
    await page.evaluate(()=>{
      window.headApi=window.RefMonitorManagerAPI;
      window.headApi.role='head_referee';
      document.body.innerHTML='<div id="ref-manager-content"></div><p id="ref-manager-status"></p>';
    });
    await page.addScriptTag({path:path.resolve('assets/refmonitor-manager.js')});
    await page.evaluate(()=>RefMonitorManager.show({gameId:'g',step:'settings'}));
    await page.locator('[data-rm-room]').waitFor();
    assert.equal(await page.locator('[data-rm-room]').isDisabled(),true,'Head could re-enable a room');
    assert.equal(await page.locator('[data-rm-tab],form').count(),0,'Head saw game editing controls');
    await page.evaluate(async()=>{
      await headApi.request('manager_request',{payload:JSON.stringify({method:'room',confirmed:true,enabled:true})});
      await RefMonitorManager.show({gameId:'g',step:'rooms'});
    });
    await page.locator('[data-rm-room]').click();await page.locator('dialog[open] [data-confirm]').click();
    await page.waitForFunction(()=>document.querySelector('[data-rm-room]').disabled);
    await page.evaluate(()=>RefMonitorManager.show({gameId:'g',step:'teams'}));
    await page.locator('[data-rm-team="2"]').waitFor();
    assert.match(await page.locator('#ref-manager-content').innerText(),/داور دوم/);
    await page.evaluate(()=>RefMonitorManager.show({gameId:'g',teamId:1,step:'scores'}));
    await page.waitForFunction(()=>document.querySelector('#ref-manager-content').textContent.includes('وضعیت تیم'));
    assert.equal(await page.locator('#ref-manager-content input,#ref-manager-content select,#ref-manager-content form,#ref-manager-content button').count(),0,'Head could edit team or scores');
    assert.match(await page.locator('#ref-manager-content').innerText(),/کاربر سازمان/);
    assert.match(await page.locator('#ref-manager-content').innerText(),/نوبت/);
    await page.evaluate(async()=>{
      managerFixture.setRounds({'1':{score:17}});
      await RefMonitorManager.show({gameId:'g',teamId:1,step:'scores'});
    });
    assert.match(await page.locator('#ref-manager-content').innerText(),/۱۷/,'Sparse legacy rounds failed to render');
    await page.evaluate(async()=>{
      const normal=headApi.request;
      let failed=false;
      headApi.request=async(...args)=>{if(!failed){failed=true;throw Error('HTTP 503');}return normal(...args);};
      await RefMonitorManager.show({gameId:'g',step:'rooms'});
    });
    await page.locator('[data-rm-retry]').click();
    await page.locator('[data-rm-room]').waitFor();
    await page.evaluate(async()=>{
      const normal=headApi.request;
      headApi.request=async(...args)=>{
        const value=await normal(...args);
        if(JSON.parse(args[1].payload).method==='games')value.period={code:'02',title:'بازهٔ دوم'};
        return value;
      };
      await RefMonitorManager.show({gameId:'g',teamId:1,step:'scores',periodCode:'01'});
    });
    await page.locator('[data-rm-retry]').waitFor();
    assert.match(await page.locator('#ref-manager-status').innerText(),/بازهٔ فعال تغییر/,'Old-period team was opened under a new period');
    console.log('PASS: phone layout, settings save, occupied-room confirmation, all-referee teams, ownership/name, OEU member search, score revisions, disband confirmation and back navigation.');
  }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
