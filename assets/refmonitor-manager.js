(() => {
  const root = document.getElementById('ref-manager-content');
  const api = window.RefMonitorManagerAPI;
  if (!root || !api) return;
  const head = api.role === 'head_referee';
  const status = document.getElementById('ref-manager-status');
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const fa = value => Number(value || 0).toLocaleString('fa-IR');
  let games = [], enabled = [], owners = [], team = null, state = {}, generation = 0, busy = false, searchTimer;
  let pendingShow = null;
  let selected = new Map();
  const request = (method, fields = {}, silent = false) => api.request('manager_request', {payload:JSON.stringify({method, game_id:state.gameId || '', period_code:api.period?.code || '', ...fields})}, {silent});
  const navigate = (step, gameId = null, teamId = null, replace = false) => api[replace ? 'replaceView' : 'open']('manager', gameId, api.period?.code || '', teamId, step);
  const game = () => games.find(item => item.id === state.gameId);
  // Older PHP records can serialize sparse round arrays as JSON objects.
  const attemptsFor = (levelId) => {
    const complete = team?.scores?.[levelId];
    const stored = team?.score_attempts?.[levelId] || complete?.attempts || (complete ? [complete] : []);
    if (Array.isArray(stored)) return stored;
    if (!stored || typeof stored !== 'object') return [];
    const attempts = [];
    for (const [key,value] of Object.entries(stored)) {
      if (/^(0|[1-9]\d*)$/.test(key) && Number(key) < 20 && value && typeof value === 'object') attempts[Number(key)] = value;
    }
    return attempts;
  };
  const levels = () => game()?.has_levels ? game().levels : [{id:'game_total',name:'بازی',rooms:game()?.rooms || []}];
  const field = (label, name, value, type = 'text', attrs = '') => `<label class="login-field"><span>${esc(label)}</span><input class="login-input" name="${name}" value="${esc(value)}" type="${type}" ${attrs}></label>`;
  const toggle = (label, name, checked) => `<label class="rm-toggle"><span>${esc(label)}</span><input type="checkbox" name="${name}" role="switch" ${checked ? 'checked' : ''}></label>`;
  const dock = label => `<div class="action-dock"><button type="submit" class="primary-action flow-action">${label}</button></div>`;
  const tabs = (entries, active) => `<nav class="rm-tabs" aria-label="بخش مدیریت">${entries.map(([id,label])=>`<button type="button" data-rm-tab="${id}" aria-selected="${active === id}">${label}</button>`).join('')}</nav>`;
  const title = text => `<h1 class="roadmap">${esc(text)}</h1>`;
  const confirmDialog = document.createElement('dialog');
  confirmDialog.className = 'score-confirm rm-confirm';
  confirmDialog.innerHTML = '<h2></h2><p></p><div class="rm-confirm-actions"><button type="button" class="secondary-action" data-cancel>انصراف</button><button type="button" class="primary-action" data-confirm>تأیید</button></div>';
  document.body.append(confirmDialog);
  const confirm = (heading, text) => new Promise(resolve => {
    confirmDialog.querySelector('h2').textContent = heading; confirmDialog.querySelector('p').textContent = text;
    let accepted = false;
    confirmDialog.querySelector('[data-cancel]').onclick = () => confirmDialog.close();
    confirmDialog.querySelector('[data-confirm]').onclick = () => {accepted = true;confirmDialog.close();};
    confirmDialog.addEventListener('close', () => resolve(accepted), {once:true});confirmDialog.showModal();
  });
  function renderPicker(mode) {
    const catalog = mode === 'teams' ? games.filter(item=>enabled.includes(item.id)) : games;
    root.innerHTML = title(mode === 'teams' ? (head ? 'مشاهدهٔ تیم‌ها' : 'مدیریت تیم‌ها') : (head ? 'مدیریت اتاق‌ها' : 'مدیریت بازی و اتاق')) + `<ul class="games-list">${catalog.map(item=>`<li><button type="button" class="game-card" data-rm-game="${esc(item.id)}"><span class="game-icon">${mode === 'teams' ? '♟' : '⚙'}</span><span>${esc(item.name)}</span><span class="game-arrow">‹</span></button></li>`).join('')}</ul>`;
    if (mode === 'teams' && !api.period) root.innerHTML += '<p class="status-text">بازهٔ فعالی وجود ندارد.</p>';
  }
  function renderGame() {
    const item = game();
    if (!item) throw new Error('بازی پیدا نشد.');
    const pane = head ? 'rooms' : state.step || 'settings';
    root.innerHTML = title(item.name) + (head ? '' : tabs([['settings','تنظیمات'],['levels','مراحل'],['rooms','اتاق‌ها']], pane));
    if (pane === 'settings') {
      root.innerHTML += `<form data-rm-settings class="rm-form">${field('نام بازی','name',item.name,'text','required maxlength="100"')}
        <div class="rm-two">${field('حداقل اعضا','min_players',item.min_players,'number','min="1" max="100" required')}${field('حداکثر اعضا','max_players',item.max_players,'number','min="1" max="100" required')}</div>
        ${field('تعداد امتیاز در هر مرحله','score_count',item.score_count,'number','min="1" max="20" required')}
        <label class="login-field"><span>جنسیت تیم‌ها</span><select class="login-input" name="gender_mode"><option value="normal" ${item.gender_mode==='normal'?'selected':''}>عادی</option><option value="separated" ${item.gender_mode==='separated'?'selected':''}>تفکیک جنسیتی</option></select></label>
        ${toggle('بازی دارای مرحله است','has_levels',item.has_levels)}${toggle('انتخاب اتاق خودکار','auto_room_manager',item.auto_room_manager)}${toggle('بدون نیاز به امتیاز','no_score_needed',item.no_score_needed)}${toggle('انتخاب رنگ پوشش','require_cover_color',item.require_cover_color)}${dock('ذخیره تنظیمات')}</form>`;
    } else if (pane === 'levels') {
      if (!item.has_levels) { root.innerHTML += '<p class="status-text">این بازی بدون مرحله است.</p>';return; }
      root.innerHTML += `<div class="stack">${item.levels.map(level=>`<form data-rm-level="${esc(level.id)}" class="rm-card rm-form">${field('نام مرحله','name',level.name,'text','required maxlength="100"')}<div class="rm-row"><button type="submit" class="secondary-action">ذخیره نام</button><button type="button" class="inline-action rm-danger" data-rm-delete-level="${esc(level.id)}">حذف مرحله</button></div></form>`).join('')}</div><form data-rm-level="" class="rm-form rm-card">${field('مرحلهٔ جدید','name','','text','required maxlength="100"')}<button type="submit" class="primary-action">افزودن مرحله</button></form>`;
    } else {
      root.innerHTML += levels().map(level=>`<section class="rm-card"><h2>${esc(level.name)}</h2>${level.rooms.length ? level.rooms.map(room=>`<label class="rm-toggle"><span><strong>${esc(room.name)}</strong><small>${esc({male:'آقایان',female:'بانوان',both:'همه',priority_male:'اولویت آقایان',priority_female:'اولویت بانوان'}[room.gender])}</small></span><input type="checkbox" role="switch" data-rm-room="${esc(room.id)}" data-level="${esc(level.id)}" aria-label="فعال بودن ${esc(room.name)}" ${room.enabled ? 'checked' : ''} ${head && !room.enabled ? 'disabled' : ''}></label>`).join('') : '<p class="status-text">اتاقی تعریف نشده است.</p>'}</section>`).join('');
    }
  }
  function renderTeams(teams) {
    root.innerHTML = title(`${game()?.name || ''} · تیم‌ها`) + `<p class="status-text">${esc(api.period?.title || '')}</p><ul class="team-list">${teams.map(item=>`<li><button type="button" class="team-card" data-rm-team="${item.id}"><span><strong>${esc(item.name)}</strong><small>داور: ${esc(item.creator?.name || item.creator?.username || item.created_by)}</small><small>${item.ended_at ? 'پایان‌یافته' : item.started_at ? 'در حال بازی' : 'آمادهٔ شروع'} · ${fa(item.members.length)} عضو · ${fa(item.total_score)} امتیاز</small></span><span class="game-arrow">‹</span></button></li>`).join('')}</ul>`;
    if (!teams.length) root.innerHTML += '<p class="status-text">تیمی ثبت نشده است.</p>';
  }
  function renderMembers() {
    const list = root.querySelector('[data-rm-members]');
    if (!list) return;
    list.innerHTML = [...selected].map(([key,person])=>`<li class="person-card"><span><strong>${esc(person.name)}</strong><small>${esc(person.work_id || person.national_id)}</small></span><button type="button" class="inline-action rm-danger" data-rm-remove="${esc(key)}">حذف</button></li>`).join('');
    root.querySelector('[data-rm-member-count]').textContent = `${fa(selected.size)} عضو`;
  }
  function renderTeam() {
    if (head) {
      root.innerHTML = title(team.name) + `<p class="status-text">${esc(game()?.name)} · داور: ${esc(team.creator?.name || team.creator?.username || team.created_by)}</p><section class="rm-card"><h2>وضعیت تیم</h2><p>${team.ended_at ? 'پایان‌یافته' : team.started_at ? 'در حال بازی' : 'آمادهٔ شروع'}</p>${team.room_assignment ? `<p>${esc(team.room_assignment.level_name)} · ${esc(team.room_assignment.room_name)}</p>` : team.waiting_for_room ? '<p>در انتظار اتاق</p>' : ''}<p class="rm-total">امتیاز کل: <strong>${fa(team.total_score)}</strong></p></section><section class="rm-card"><h2>اعضا · ${fa(team.members.length)}</h2><ul class="selected-list">${team.members.map(person=>`<li class="person-card"><span><strong>${esc(person.name)}</strong><small>${esc(person.work_id || person.national_id)}</small></span></li>`).join('')}</ul></section>` + levels().map(level=>{
        const complete = team.scores?.[level.id];
        const attempts = attemptsFor(level.id);
        return `<section class="rm-card"><h2>${esc(level.name)}</h2><p>${complete ? 'تکمیل شده' : attempts.length ? 'در حال انجام' : 'انجام نشده'}</p>${attempts.map((attempt,index)=>attempt ? `<p>نوبت ${fa(index+1)}: ${fa(attempt.score)} امتیاز</p>` : '').join('')}</section>`;
      }).join('');
      return;
    }
    const pane = state.step || 'details';
    root.innerHTML = title(team.name) + `<p class="status-text">${esc(game()?.name)} · داور: ${esc(team.creator?.name || team.creator?.username || team.created_by)}</p>` + tabs([['details','مشخصات'],['members','اعضا'],['scores','امتیازها']],pane);
    if (pane === 'details') {
      const options = [...owners];
      if (!options.some(owner=>owner.code === team.created_by)) options.unshift({code:team.created_by,name:team.creator?.name || 'حساب قبلی'});
      root.innerHTML += `<form data-rm-details class="rm-form">${field('نام تیم','name',team.name,'text','required maxlength="100"')}
        <label class="login-field"><span>داور مسئول</span><select name="owner" class="login-input">${options.map(owner=>`<option value="${esc(owner.code)}" ${owner.code === team.created_by ? 'selected' : ''}>${esc(owner.name || owner.username)} (${esc(owner.username || '')})</option>`).join('')}</select></label>
        <label class="login-field"><span>رنگ پوشش</span><select name="cover_color" class="login-input"><option value="">بدون رنگ</option>${['سفید','مشکی','خاکستری','نقره‌ای','سرمه‌ای','آبی','آبی آسمانی','فیروزه‌ای','سبز','سبز فسفری','زرد','نارنجی','قرمز','زرشکی','صورتی','سرخابی','بنفش','قهوه‌ای',team.cover_color].filter((value,index,array)=>value&&array.indexOf(value)===index).map(color=>`<option ${color === team.cover_color ? 'selected' : ''}>${esc(color)}</option>`).join('')}</select></label>${dock('ذخیره مشخصات')}</form>
        <section class="rm-card rm-form"><h2>وضعیت بازی</h2><p class="status-text">${team.ended_at ? 'پایان‌یافته' : team.started_at ? 'در حال بازی' : 'شروع نشده'} · ${fa(team.total_score)} امتیاز</p>${team.room_assignment ? `<p>${esc(team.room_assignment.level_name)} · ${esc(team.room_assignment.room_name)}</p>` : team.waiting_for_room ? '<p>در انتظار اتاق</p>' : ''}<button type="button" class="secondary-action" data-rm-control="${team.ended_at ? 'reopen' : team.started_at ? 'end' : 'start'}">${team.ended_at ? 'بازگشایی بازی' : team.started_at ? 'پایان بازی' : 'شروع بازی'}</button><button type="button" class="inline-action rm-danger" data-rm-control="disband">انحلال تیم</button></section>`;
    } else if (pane === 'members') {
      selected = new Map(team.members.map(person=>['e:'+person.id,person]));
      root.innerHTML += `<form data-rm-save-members class="rm-form"><p class="status-text" data-rm-member-count></p><ul class="selected-list" data-rm-members></ul><label class="login-field"><span>افزودن عضو از رویداد یا کاربران سازمان</span><input class="login-input" data-rm-search autocomplete="off" placeholder="نام، کد ملی یا کد پرسنلی"></label><p class="status-text" data-rm-search-status></p><ul class="result-list" data-rm-results></ul>${dock('ذخیره اعضا')}</form>`;
      renderMembers();
    } else {
      const required = game().no_score_needed ? 1 : team.score_count || game().score_count;
      root.innerHTML += `<p class="rm-total">امتیاز کل: <strong>${fa(team.total_score)}</strong></p><form data-rm-scores class="rm-form">${levels().map(level=>{
        const complete = team.scores?.[level.id];
        const attempts = attemptsFor(level.id);
        return `<section class="rm-card"><h2>${esc(level.name)} <small>جمع: ${fa(attempts.reduce((sum,item)=>sum+Number(item?.score || 0),0))}</small></h2>${game().no_score_needed ? `<button type="button" class="secondary-action" data-rm-complete="${esc(level.id)}" ${complete ? 'disabled' : ''}>${complete ? 'تکمیل شده' : 'تکمیل مرحله'}</button>` : Array.from({length:Math.max(required,attempts.length)},(_,index)=>`<label class="login-field"><span>نوبت ${fa(index+1)}</span><input class="login-input" type="number" min="0" max="999999.99" step="0.01" data-level="${esc(level.id)}" data-index="${index}" data-original="${attempts[index]?.score ?? ''}" value="${attempts[index]?.score ?? ''}" ${!team.started_at ? 'disabled' : ''}></label>`).join('')}</section>`;
      }).join('')}${!game().no_score_needed ? dock('ذخیره امتیازها') : ''}</form>`;
      if (!team.started_at) root.innerHTML += '<p class="status-text">ابتدا بازی تیم را از بخش مشخصات شروع کنید.</p>';
    }
  }
  async function show(next) {
    // A room/settings save must finish before a new view replaces its state.
    if (busy) return new Promise(resolve=>{
      pendingShow?.resolve();
      pendingShow={next,resolve};
    });
    state = next; const version = ++generation; clearTimeout(searchTimer); status.textContent = '';status.classList.remove('error');root.innerHTML = '<p class="status-text">در حال دریافت…</p>';
    try {
      const catalog = await request('games');
      if (version !== generation) return;
      if (!Array.isArray(catalog.games) || !Array.isArray(catalog.enabled)) throw new Error('اطلاعات بازی‌ها معتبر نیست؛ دوباره تلاش کنید.');
      games = catalog.games;enabled = catalog.enabled;api.refreshGames?.(games);
      if (Object.prototype.hasOwnProperty.call(catalog,'period')) api.period = catalog.period;
      if (state.teamId && state.periodCode && state.periodCode !== api.period?.code) throw new Error('بازهٔ فعال تغییر کرده است؛ از فهرست بازی‌ها تیم را دوباره انتخاب کنید.');
      if (!state.gameId) return renderPicker(state.step || 'games');
      if (state.step === 'teams') {
        if (!api.period) throw new Error('بازهٔ فعالی وجود ندارد.');
        const data = await request('teams');if (version !== generation) return;owners = data.owners;renderTeams(data.teams);
      } else if (state.teamId) {
        const data = await request('team',{team_id:state.teamId});if(version !== generation)return;team=data.team;owners=data.owners;renderTeam();
      } else renderGame();
    } catch(error) {if(version === generation){
      root.innerHTML='<div class="rm-card"><h2>بارگذاری انجام نشد</h2><button type="button" class="secondary-action" data-rm-retry>تلاش دوباره</button><button type="button" class="inline-action" data-rm-picker>فهرست بازی‌ها</button></div>';
      status.classList.add('error');status.textContent=error.message;
    }}
  }
  async function run(work) {
    if (busy) return;
    busy=true;status.textContent='';status.classList.remove('error');root.setAttribute('aria-busy','true');
    const controls=[...root.querySelectorAll('button,input,select')];const disabled=controls.map(node=>node.disabled);controls.forEach(node=>node.disabled=true);
    try {await work();} catch(error){status.classList.add('error');status.textContent=error.message;}
    finally {
      controls.forEach((node,index)=>node.disabled=disabled[index]);root.removeAttribute('aria-busy');busy=false;
      if(pendingShow){const pending=pendingShow;pendingShow=null;void show(pending.next).finally(pending.resolve);}
    }
  }
  const changeTeam = async (method,fields={}) => {
    const data=await request(method,{team_id:team.id,revision:team.revision,...fields});
    if (data.team.disbanded) navigate('teams',state.gameId,null,true);
    else {team=data.team;renderTeam();}
  };
  document.querySelectorAll('[data-manager-open]').forEach(button=>button.addEventListener('click',()=>navigate(button.dataset.managerOpen)));
  root.addEventListener('click', async event=>{
    if (busy) return;
    const button=event.target.closest('button');if(!button)return;
    if (button.hasAttribute('data-rm-retry')) {
      if (state.teamId && state.periodCode && state.periodCode !== api.period?.code) navigate('teams',null,null,true);
      else void show(state);
    }
    else if (button.hasAttribute('data-rm-picker')) navigate(state.teamId || state.step === 'teams' ? 'teams' : 'games',null,null,true);
    else if (button.dataset.rmGame) navigate(state.step === 'teams' ? 'teams' : head ? 'rooms' : 'settings',button.dataset.rmGame);
    else if (button.dataset.rmTeam) navigate('details',state.gameId,Number(button.dataset.rmTeam));
    else if (button.dataset.rmTab) navigate(button.dataset.rmTab,state.gameId,state.teamId,null);
    else if (button.dataset.rmRemove) {selected.delete(button.dataset.rmRemove);renderMembers();}
    else if (button.dataset.rmAdd) {
      const person=JSON.parse(button.dataset.person);selected.set(person.key,person);renderMembers();button.disabled=true;button.textContent='انتخاب شد';
    } else if (button.dataset.rmControl) {
      const method=button.dataset.rmControl;
      if (['disband','end','reopen'].includes(method) && !await confirm(method==='disband'?'انحلال تیم':method==='end'?'پایان بازی':'بازگشایی بازی',method==='disband'?'تیم از فهرست فعال حذف و اعضا و اتاق آزاد می‌شوند. سوابق در پایگاه داده نگه داشته می‌شود.':'وضعیت بازی این تیم تغییر کند؟')) return;
      void run(()=>changeTeam(method,{confirmed:true}));
    } else if (button.dataset.rmDeleteLevel) {
      if(!await confirm('حذف مرحله','این مرحله حذف شود؟'))return;
      void run(async()=>{games=(await request('delete_level',{level_id:button.dataset.rmDeleteLevel})).games;renderGame();});
    } else if (button.dataset.rmComplete) void run(()=>changeTeam('score',{level_id:button.dataset.rmComplete,index:0,score:'',expected_score:null}));
  });
  root.addEventListener('change',event=>{
    const input=event.target;if(!input.matches('[data-rm-room]'))return;
    const enabled=input.checked;input.checked=!enabled;
    if (head && enabled) return;
    void run(async()=>{
      const fields={level_id:input.dataset.level,room_id:input.dataset.rmRoom,enabled};
      let data=await request('room',fields);
      if(data.requires_confirmation){
        const names=data.teams.map(item=>item.name).join('، ');
        if(!await confirm('غیرفعال‌کردن اتاق',`تیم‌های ${names} در این اتاق هستند؛ به اتاق دیگر یا انتظار منتقل شوند؟`))return;
        data=await request('room',{...fields,confirmed:true});
      }
      games=(await request('games')).games;renderGame();
    });
  });
  root.addEventListener('input',event=>{
    if(!event.target.matches('[data-rm-search]'))return;
    clearTimeout(searchTimer);const query=event.target.value.trim();const version=++generation;
    root.querySelector('[data-rm-results]').innerHTML='';root.querySelector('[data-rm-search-status]').textContent=query.length<2?'حداقل ۲ نویسه':'';
    if(query.length<2)return;
    searchTimer=setTimeout(async()=>{
      try{
        const data=await request('search',{query},true);if(version!==generation)return;
        root.querySelector('[data-rm-search-status]').textContent=data.people.length?'':'موردی پیدا نشد.';
        root.querySelector('[data-rm-results]').innerHTML=data.people.map(person=>`<li class="person-card"><span><strong>${esc(person.name)}</strong><small>${esc(person.source)} · ${esc(person.work_id || person.national_id)}</small></span><button type="button" class="secondary-action" data-rm-add="${esc(person.key)}" data-person="${esc(JSON.stringify(person))}" ${selected.has(person.key)?'disabled':''}>${selected.has(person.key)?'انتخاب شد':'افزودن'}</button></li>`).join('');
      }catch(error){if(version===generation){status.classList.add('error');status.textContent=error.message;}}
    },250);
  });
  root.addEventListener('submit',event=>{
    event.preventDefault();if(busy)return;const form=event.target;if(!form.reportValidity())return;
    const values=Object.fromEntries(new FormData(form));
    void run(async()=>{
      if(form.matches('[data-rm-settings]')){
        for(const key of ['has_levels','auto_room_manager','no_score_needed','require_cover_color'])values[key]=form.elements[key].checked;
        values.revision=game().revision;
        games=(await request('settings',values)).games;api.refreshGames?.(games);renderGame();status.textContent='تنظیمات ذخیره شد.';
      }else if(form.matches('[data-rm-level]')){
        games=(await request('save_level',{level_id:form.dataset.rmLevel,name:values.name})).games;api.refreshGames?.(games);renderGame();
      }else if(form.matches('[data-rm-details]'))await changeTeam('details',values);
      else if(form.matches('[data-rm-save-members]'))await changeTeam('members',{members:[...selected.keys()]});
      else if(form.matches('[data-rm-scores]')){
        const changes=[...form.querySelectorAll('input[data-level]')].filter(input=>input.value!==input.dataset.original && input.value!=='');
        if(!changes.length){status.textContent='امتیازی تغییر نکرده است.';return;}
        let saved=0;
        try{
          for(const input of changes){
            const data=await request('score',{team_id:team.id,revision:team.revision,level_id:input.dataset.level,index:Number(input.dataset.index),score:input.value,expected_score:input.dataset.original===''?null:Number(input.dataset.original)});
            team=data.team;input.dataset.original=input.value;saved++;
          }
          renderTeam();status.textContent='امتیازها ذخیره شد.';
        }catch(error){throw new Error(`${saved ? fa(saved)+' امتیاز ذخیره شد. ' : ''}${error.message}`);}
      }
    });
  });
  window.RefMonitorManager={show,back(current){
    if(busy)return;
    if(current.teamId)navigate('teams',current.gameId,null,true);
    else if(current.gameId)navigate(current.step==='teams'?'teams':'games',null,null,true);
    else api.replaceView('home');
  }};
})();
