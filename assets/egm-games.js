(() => {
  const shell = document.querySelector('.egm-shell');
  if (!shell) return;
  const endpoint = 'mini%20apps/Event%20Guest%20Manager/games.php';
  const csrf = shell.dataset.egmCsrf || '';
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const genderOptions = (selected, separated = false) => (separated ? [['male','مرد'], ['female','زن']] : [['both','هر دو'], ['male','مرد'], ['female','زن']]).map(([value, label]) => `<option value="${value}" ${selected === value ? 'selected' : ''}>${label}</option>`).join('');
  const roomGenderLabel = value => ({male:'مرد', female:'زن', both:'هر دو'})[value] || 'هر دو';
  let games = [];
  let selectedGameId = null;
  let selectedLevelId = null;
  let editingRoomId = null;
  let addingRoom = false;
  let addingLevel = false;
  const roomsFor = (game, level) => level ? (level.rooms || []) : (game.rooms || []);
  const renderRoomEditor = (game, level, room) => `<form data-room-form class="egm-games-inline-form" data-level-id="${escapeHtml(level?.id || 'game_total')}" ${room ? `data-room-id="${escapeHtml(room.id)}"` : ''}>
    <label class="field"><span>نام اتاق</span><input type="text" name="name" maxlength="100" required value="${escapeHtml(room?.name || '')}" placeholder="نام اتاق"></label>
    <label class="field"><span>مخصوص</span><select name="gender">${genderOptions(room?.gender || (game.gender_mode === 'separated' ? 'male' : 'both'), game.gender_mode === 'separated')}</select></label>
    <div class="egm-games-form-actions"><button class="btn primary" type="submit">${room ? 'ذخیره اتاق' : 'افزودن اتاق'}</button><button class="btn ghost" type="button" data-room-cancel>انصراف</button>${room ? '<button class="btn ghost" type="button" data-room-delete>حذف اتاق</button>' : ''}</div></form>`;
  const renderRooms = (game, level) => `<section class="egm-games-section"><div class="egm-games-section-head"><h4>اتاق‌ها</h4><button type="button" class="btn ghost" data-room-add>+ افزودن اتاق</button></div>
    ${roomsFor(game, level).length ? roomsFor(game, level).map(room => `<div class="egm-games-item"><div><strong>${escapeHtml(room.name)}</strong><small>${roomGenderLabel(room.gender)}</small></div><button type="button" class="btn ghost" data-room-edit="${escapeHtml(room.id)}">ویرایش</button></div>`).join('') : '<p class="muted">هنوز اتاقی ثبت نشده است.</p>'}
    ${addingRoom ? renderRoomEditor(game, level, null) : editingRoomId ? renderRoomEditor(game, level, roomsFor(game, level).find(room => room.id === editingRoomId)) : ''}</section>`;
  async function request(action = 'list', data = {}) {
    const url = new URL(endpoint, location.href);
    const options = action === 'list' ? undefined : {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action, csrf, ...data})};
    if (action === 'list') {
      url.searchParams.set('action', 'list');
      if (data.period_code) url.searchParams.set('period_code', data.period_code);
    }
    const response = await fetch(url, options);
    const result = await response.json();
    if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'مدیریت بازی‌ها ناموفق بود.');
    return result;
  }
  const catalog = shell.querySelector('[data-egm-games-catalog]');
  const refMonitorLink = catalog?.querySelector('[data-refmonitor-open]');
  const refMonitorUrl = catalog?.querySelector('[data-refmonitor-url]');
  if (refMonitorLink && refMonitorUrl) refMonitorUrl.value = new URL(refMonitorLink.getAttribute('href'), location.href).href;
  catalog?.querySelector('[data-refmonitor-copy]')?.addEventListener('click', async event => {
    if (!refMonitorUrl?.value) return;
    const button = event.currentTarget;
    try {
      await navigator.clipboard.writeText(refMonitorUrl.value);
      button.textContent = 'کپی شد';
      window.setTimeout(() => { button.textContent = 'کپی لینک'; }, 1800);
    } catch (error) {
      refMonitorUrl.select();
      button.textContent = 'لینک را انتخاب کنید و کپی کنید';
    }
  });
  function renderCatalog() {
    if (!catalog) return;
    const list = catalog.querySelector('[data-games-list]');
    const game = games.find(item => item.id === selectedGameId);
    const level = game?.has_levels ? (game.levels || []).find(item => item.id === selectedLevelId) : null;
    catalog.querySelector('[data-games-create]').hidden = !!game;
    if (!game) {
      list.innerHTML = games.length ? `<div class="egm-games-list">${games.map(item => `<button type="button" class="egm-games-nav" data-open-game="${escapeHtml(item.id)}"><span><strong>${escapeHtml(item.name)}</strong><small>${item.has_levels ? `${(item.levels || []).length} مرحله` : 'یک امتیاز'} · ${item.auto_room_manager ? 'تخصیص خودکار اتاق' : 'تخصیص دستی اتاق'}</small></span><span aria-hidden="true">‹</span></button>`).join('')}</div>` : '<p class="muted">هنوز بازی‌ای ساخته نشده است.</p>';
      return;
    }
    const gameId = escapeHtml(game.id);
    if (level) {
      list.innerHTML = `<div data-game-id="${gameId}"><button type="button" class="egm-games-back" data-back-game>→ ${escapeHtml(game.name)}</button>
        <div class="egm-games-title"><h3>${escapeHtml(level.name)}</h3><p>مرحله ${(game.levels || []).findIndex(item => item.id === level.id) + 1} از ${game.levels.length}</p></div>
        <form data-level-form class="egm-games-inline-form" data-level-id="${escapeHtml(level.id)}"><label class="field"><span>نام مرحله</span><input type="text" name="name" maxlength="100" required value="${escapeHtml(level.name)}"></label><div class="egm-games-form-actions"><button class="btn primary" type="submit">ذخیره نام</button>${game.auto_room_manager ? '' : '<button class="btn ghost" type="button" data-level-delete>حذف مرحله</button>'}</div></form>
        ${renderRooms(game, level)}</div>`;
      return;
    }
    list.innerHTML = `<div data-game-id="${gameId}"><button type="button" class="egm-games-back" data-back-list>→ همه بازی‌ها</button>
      <div class="egm-games-title"><h3>${escapeHtml(game.name)}</h3><button type="button" class="btn ghost" data-game-delete>حذف بازی</button></div>
      <form data-game-settings class="egm-games-settings">
        <h4>تنظیمات بازی</h4>
        <div class="egm-games-fields">
          <label class="field"><span>نام بازی</span><input type="text" name="name" maxlength="100" required value="${escapeHtml(game.name)}"></label>
          <label class="field"><span>امتیازدهی</span><select name="mode"><option value="single" ${game.has_levels ? '' : 'selected'}>یک امتیاز برای کل بازی</option><option value="levels" ${game.has_levels ? 'selected' : ''}>امتیاز جداگانه برای هر مرحله</option></select></label>
          <label class="field"><span>ترکیب تیم</span><select name="gender_mode"><option value="normal" ${game.gender_mode === 'normal' ? 'selected' : ''}>عادی</option><option value="separated" ${game.gender_mode === 'separated' ? 'selected' : ''}>تفکیک جنسیتی</option></select></label>
          <label class="field"><span>تخصیص اتاق</span><select name="auto_mode"><option value="off" ${game.auto_room_manager ? '' : 'selected'}>دستی</option><option value="on" ${game.auto_room_manager ? 'selected' : ''}>خودکار</option></select></label>
          <label class="field"><span>حداقل اعضای تیم</span><input name="min_players" type="number" min="1" max="100" required value="${escapeHtml(game.min_players)}"></label>
          <label class="field"><span>حداکثر اعضای تیم</span><input name="max_players" type="number" min="1" max="100" required value="${escapeHtml(game.max_players)}"></label>
        </div><div class="egm-games-form-actions"><button class="btn primary" type="submit">ذخیره تنظیمات</button></div></form>
      ${game.has_levels ? `<section class="egm-games-section"><div class="egm-games-section-head"><h4>مراحل</h4>${game.auto_room_manager ? '' : '<button type="button" class="btn ghost" data-level-add>+ افزودن مرحله</button>'}</div>
        ${(game.levels || []).length ? game.levels.map((item, index) => `<button type="button" class="egm-games-nav" data-open-level="${escapeHtml(item.id)}"><span><strong>${index + 1}. ${escapeHtml(item.name)}</strong><small>${(item.rooms || []).length} اتاق</small></span><span aria-hidden="true">‹</span></button>`).join('') : '<p class="muted">هنوز مرحله‌ای ثبت نشده است.</p>'}
        ${addingLevel ? '<form data-level-create class="egm-games-inline-form"><label class="field"><span>نام مرحله جدید</span><input type="text" name="name" maxlength="100" required></label><div class="egm-games-form-actions"><button class="btn primary" type="submit">افزودن مرحله</button><button class="btn ghost" type="button" data-level-cancel>انصراف</button></div></form>' : ''}</section>` : renderRooms(game, null)}</div>`;
  }
  async function loadCatalog() {
    if (!catalog) return;
    const status = catalog.querySelector('[data-games-status]');
    try {
      const data = await request();
      games = data.games || [];
      if (!games.some(item => item.id === selectedGameId)) selectedGameId = null;
      if (!games.find(item => item.id === selectedGameId)?.levels?.some(item => item.id === selectedLevelId)) selectedLevelId = null;
      renderCatalog();
      status.textContent = '';
    } catch (error) { status.textContent = error.message; }
  }
  catalog?.querySelector('[data-games-create]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const status = catalog.querySelector('[data-games-status]');
    try { await request('save', {name:form.elements.name.value}); form.reset(); await loadCatalog(); status.textContent = 'بازی افزوده شد.'; }
    catch (error) { status.textContent = error.message; }
  });
  catalog?.addEventListener('submit', async event => {
    const form = event.target;
    if (!form.matches('[data-game-settings], [data-level-create], [data-level-form], [data-room-form]')) return;
    event.preventDefault();
    const game = games.find(item => item.id === selectedGameId);
    const status = catalog.querySelector('[data-games-status]');
    const button = form.querySelector('[type="submit"]');
    if (button) button.disabled = true;
    try {
      if (form.matches('[data-game-settings]')) {
        const min = Number(form.elements.min_players.value);
        const max = Number(form.elements.max_players.value);
        if (min > max) throw new Error('حداقل اعضا نباید از حداکثر بیشتر باشد.');
        const newMode = form.elements.mode.value === 'levels';
        const newGender = form.elements.gender_mode.value;
        const newAuto = form.elements.auto_mode.value === 'on';
        if (!newMode && game.has_levels && game.levels?.length && !confirm('با حذف مراحل، نام و ترتیب آن‌ها پاک می‌شود. ادامه می‌دهید؟')) {
          form.dispatchEvent(new CustomEvent('egm-game-settings-result', {bubbles:true, detail:{ok:false}}));
          return;
        }
        if (game.auto_room_manager && !newAuto) await request('save_auto_mode', {game_id:game.id, enabled:false});
        if (newMode !== game.has_levels) await request('save_mode', {game_id:game.id, has_levels:newMode});
        if (newGender !== game.gender_mode) await request('save_gender_mode', {game_id:game.id, gender_mode:newGender});
        if (min !== Number(game.min_players) || max !== Number(game.max_players)) await request('save_limits', {game_id:game.id, min_players:min, max_players:max});
        if (form.elements.name.value.trim() !== game.name) await request('save', {id:game.id, name:form.elements.name.value.trim()});
        if (!game.auto_room_manager && newAuto) await request('save_auto_mode', {game_id:game.id, enabled:true});
        status.textContent = 'تنظیمات ذخیره شد.';
        form.dispatchEvent(new CustomEvent('egm-game-settings-result', {bubbles: true, detail: {ok: true}}));
      } else if (form.matches('[data-level-create]')) {
        await request('save_level', {game_id:game.id, name:form.elements.name.value});
        addingLevel = false;
        status.textContent = 'مرحله افزوده شد.';
      } else if (form.matches('[data-level-form]')) {
        await request('save_level', {game_id:game.id, level_id:form.dataset.levelId, name:form.elements.name.value});
        status.textContent = 'نام مرحله ذخیره شد.';
      } else {
        await request('save_room', {game_id:game.id, level_id:form.dataset.levelId, room_id:form.dataset.roomId, name:form.elements.name.value, gender:form.elements.gender.value});
        addingRoom = false; editingRoomId = null;
        status.textContent = 'اتاق ذخیره شد.';
      }
      const message = status.textContent;
      await loadCatalog();
      status.textContent = message;
    } catch (error) {
      status.textContent = error.message;
      if (form.matches('[data-game-settings]')) form.dispatchEvent(new CustomEvent('egm-game-settings-result', {bubbles: true, detail: {ok: false}}));
    }
    finally { if (button?.isConnected) button.disabled = false; }
  });
  catalog?.addEventListener('click', async event => {
    const button = event.target.closest('button');
    if (!button) return;
    const status = catalog.querySelector('[data-games-status]');
    const game = games.find(item => item.id === selectedGameId);
    if (button.hasAttribute('data-open-game')) { selectedGameId = button.dataset.openGame; selectedLevelId = null; renderCatalog(); return; }
    if (button.hasAttribute('data-back-list')) { selectedGameId = null; selectedLevelId = null; renderCatalog(); return; }
    if (button.hasAttribute('data-open-level')) { selectedLevelId = button.dataset.openLevel; editingRoomId = null; addingRoom = false; renderCatalog(); return; }
    if (button.hasAttribute('data-back-game')) { selectedLevelId = null; editingRoomId = null; addingRoom = false; renderCatalog(); return; }
    if (button.hasAttribute('data-level-add')) { addingLevel = true; renderCatalog(); catalog.querySelector('[data-level-create] input')?.focus(); return; }
    if (button.hasAttribute('data-level-cancel')) { addingLevel = false; renderCatalog(); return; }
    if (button.hasAttribute('data-room-add')) { addingRoom = true; editingRoomId = null; renderCatalog(); catalog.querySelector('[data-room-form] input')?.focus(); return; }
    if (button.hasAttribute('data-room-edit')) { editingRoomId = button.dataset.roomEdit; addingRoom = false; renderCatalog(); catalog.querySelector('[data-room-form] input')?.focus(); return; }
    if (button.hasAttribute('data-room-cancel')) { editingRoomId = null; addingRoom = false; renderCatalog(); return; }
    try {
      if (button.hasAttribute('data-game-delete')) {
        if (!confirm('این بازی حذف شود؟ بازی دارای تیم ثبت‌شده قابل حذف نیست.')) return;
        await request('delete', {id:game.id});
        selectedGameId = null;
        status.textContent = 'بازی حذف شد.';
      } else if (button.hasAttribute('data-level-delete')) {
        if (!confirm('این مرحله حذف شود؟ مرحله‌ای که امتیاز دارد قابل حذف نیست.')) return;
        await request('delete_level', {game_id:game.id, level_id:selectedLevelId});
        selectedLevelId = null;
        status.textContent = 'مرحله حذف شد.';
      } else if (button.hasAttribute('data-room-delete')) {
        if (!confirm('این اتاق حذف شود؟')) return;
        const form = button.closest('[data-room-form]');
        await request('delete_room', {game_id:game.id, level_id:form.dataset.levelId, room_id:form.dataset.roomId});
        editingRoomId = null;
        status.textContent = 'اتاق حذف شد.';
      } else return;
      const message = status.textContent;
      await loadCatalog();
      status.textContent = message;
    } catch (error) { status.textContent = error.message; }
  });
  async function loadPeriod(pane) {
    const card = pane.querySelector('[data-egm-period-games]');
    if (!card) return;
    const list = card.querySelector('[data-period-games-list]');
    const status = card.querySelector('[data-period-games-status]');
    const periodCode = pane.dataset.taskTagCode || '';
    if (!periodCode) return;
    try {
      const data = await request('list', {period_code:periodCode});
      const enabled = new Set(data.enabled || []);
      list.innerHTML = data.games.length ? data.games.map(game => `<div class="egm-period-game-block"><label class="egm-game-row"><input type="checkbox" data-period-game-id="${escapeHtml(game.id)}" ${enabled.has(game.id) ? 'checked' : ''}><span>${escapeHtml(game.name)}</span></label>${enabled.has(game.id) ? `<div class="egm-game-teams" data-period-game-teams="${escapeHtml(game.id)}">در حال بارگذاری تیم‌ها…</div>` : ''}</div>`).join('') : '<p class="muted">ابتدا از تب بازی‌ها یک بازی بسازید.</p>';
      status.textContent = '';
      await Promise.all([...enabled].map(async gameId => {
        const target = [...list.querySelectorAll('[data-period-game-teams]')].find(node => node.dataset.periodGameTeams === gameId);
        if (!target) return;
        try {
          const teams = (await request('list_teams', {period_code:periodCode, game_id:gameId})).teams;
          target.innerHTML = teams.length ? teams.map(team => `<div class="egm-game-row"><strong>${escapeHtml(team.name)}</strong><span>${team.members.length} عضو · امتیاز ${escapeHtml(team.total_score)} · ${team.ended_at ? 'پایان‌یافته' : team.room_assignment ? `${escapeHtml(team.room_assignment.level_name)} / ${escapeHtml(team.room_assignment.room_name)}` : team.waiting_for_room ? 'در انتظار اتاق' : team.started_at ? 'در جریان' : 'در انتظار شروع'}</span>${!team.ended_at && shell.dataset.egmCanEndGames === '1' ? `<button type="button" class="btn ghost" data-end-team="${team.id}" data-end-game="${escapeHtml(gameId)}">پایان بازی</button>` : ''}</div>`).join('') : '<p class="muted">هنوز تیمی برای این بازی ثبت نشده است.</p>';
        } catch (error) { target.textContent = error.message; }
      }));
    } catch (error) { status.textContent = error.message; }
  }
  shell.addEventListener('click', event => {
    const tab = event.target.closest('[data-task-top-trigger="games"]');
    if (tab) { const pane = tab.closest('.sub-pane[data-task-pane="1"]'); if (pane) void loadPeriod(pane); }
    if (event.target.closest('.sub-nav .sub-item[data-pane="egm-games"]')) void loadCatalog();
  });
  shell.addEventListener('click', async event => {
    const button = event.target.closest('[data-end-team]');
    if (!button || !confirm('بازی این تیم پایان یابد؟ پس از آن نام تیم و امتیازهای باقی‌مانده قابل ثبت نیستند.')) return;
    const pane = button.closest('.sub-pane[data-task-pane="1"]');
    const status = pane?.querySelector('[data-period-games-status]');
    button.disabled = true;
    try { await request('end_team', {period_code:pane.dataset.taskTagCode, game_id:button.dataset.endGame, team_id:Number(button.dataset.endTeam)}); await loadPeriod(pane); status.textContent = 'بازی این تیم پایان یافت.'; }
    catch (error) { status.textContent = error.message; button.disabled = false; }
  });
  shell.addEventListener('change', async event => {
    const input = event.target.closest('[data-period-game-id]');
    if (!input) return;
    const pane = input.closest('.sub-pane[data-task-pane="1"]');
    const status = pane?.querySelector('[data-period-games-status]');
    if (!pane || !status) return;
    input.disabled = true;
    try {
      await request('set_enabled', {period_code:pane.dataset.taskTagCode, id:input.dataset.periodGameId, enabled:input.checked});
      await loadPeriod(pane);
      status.textContent = input.checked ? 'بازی فعال شد و جدول آن ساخته شد.' : 'بازی در این بازه غیرفعال شد.';
    } catch (error) { input.checked = !input.checked; status.textContent = error.message; }
    finally { input.disabled = false; }
  });
  if (catalog?.classList.contains('active')) void loadCatalog();
})();
