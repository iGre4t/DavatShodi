(() => {
  const shell = document.querySelector('.egm-shell');
  if (!shell) return;
  const endpoint = shell.dataset.egmGamesEndpoint || 'mini%20apps/Event%20Guest%20Manager/games.php';
  const csrf = shell.dataset.egmCsrf || '';
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const genderOptions = selected => [['both','هر دو'], ['male','مرد'], ['female','زن'], ['priority_male','اولویت با مرد'], ['priority_female','اولویت با زن']].map(([value, label]) => `<option value="${value}" ${selected === value ? 'selected' : ''}>${label}</option>`).join('');
  const roomGenderLabel = value => ({male:'مرد', female:'زن', both:'هر دو', priority_male:'اولویت با مرد', priority_female:'اولویت با زن'})[value] || 'هر دو';
  let games = [];
  let selectedGameId = null;
  let selectedLevelId = null;
  let editingRoomId = null;
  let addingRoom = false;
  let addingLevel = false;
  const roomsFor = (game, level) => level ? (level.rooms || []) : (game.rooms || []);
  const renderRoomEditor = (game, level, room) => `<form data-room-form class="egm-games-inline-form" data-level-id="${escapeHtml(level?.id || 'game_total')}" ${room ? `data-room-id="${escapeHtml(room.id)}"` : ''}>
    <label class="field"><span>نام اتاق</span><input type="text" name="name" maxlength="100" required value="${escapeHtml(room?.name || '')}" placeholder="نام اتاق"></label>
    <label class="field"><span>مخصوص</span><select name="gender">${genderOptions(room?.gender || 'both')}</select></label>
    <div class="egm-games-form-actions"><button class="btn primary" type="submit">${room ? 'ذخیره اتاق' : 'افزودن اتاق'}</button><button class="btn ghost" type="button" data-room-cancel>انصراف</button>${room ? '<button class="btn ghost" type="button" data-room-delete>حذف اتاق</button>' : ''}</div></form>`;
  const renderRooms = (game, level) => `<section class="egm-games-section"><div class="egm-games-section-head"><h4>اتاق‌ها</h4><button type="button" class="btn ghost" data-room-add>+ افزودن اتاق</button></div>
    ${roomsFor(game, level).length ? roomsFor(game, level).map(room => `<div class="egm-games-item"><div><strong>${escapeHtml(room.name)}</strong><small>${roomGenderLabel(room.gender)} · ${room.enabled === false ? 'غیرفعال' : 'فعال'}</small></div><label class="switch egm-switch" style="width:auto;min-width:110px"><span class="switch-label">فعال</span><span class="switch-toggle"><input type="checkbox" data-room-enabled="${escapeHtml(room.id)}" data-room-level="${escapeHtml(level?.id || 'game_total')}" ${room.enabled !== false ? 'checked' : ''} aria-label="فعال بودن ${escapeHtml(room.name)}"><span class="switch-track"><span class="switch-thumb"></span></span></span></label><button type="button" class="btn ghost" data-room-edit="${escapeHtml(room.id)}">ویرایش</button></div>`).join('') : '<p class="muted">هنوز اتاقی ثبت نشده است.</p>'}
    ${addingRoom ? renderRoomEditor(game, level, null) : editingRoomId ? renderRoomEditor(game, level, roomsFor(game, level).find(room => room.id === editingRoomId)) : ''}</section>`;
  async function request(action = 'list', data = {}) {
    const url = new URL(endpoint, location.href);
    const fields = new URLSearchParams({action, csrf});
    for (const [key, value] of Object.entries(data)) {
      if (value == null) continue;
      fields.set(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    }
    const read = action === 'list' || action === 'monitor_snapshot';
    const options = read ? undefined : {method:'POST', body:fields};
    if (read) {
      url.searchParams.set('action', action);
      if (data.period_code) url.searchParams.set('period_code', data.period_code);
    }
    let response;
    try {
      response = await fetch(url, {...options, credentials:'same-origin', headers:{...options?.headers, Accept:'application/json'}});
    } catch {
      throw new Error('ارتباط با سرور بازی‌ها برقرار نشد. اتصال اینترنت را بررسی و دوباره تلاش کنید.');
    }
    const text = await response.text();
    let result;
    try { result = JSON.parse(text); } catch {
      if (response.redirected && /(?:login|signin)\.php(?:[?#]|$)/i.test(response.url)) {
        throw new Error('نشست شما پایان یافته است. دوباره وارد پنل شوید.');
      }
      if (response.status === 403) throw new Error('سرور درخواست بازی‌ها را مسدود کرد (۴۰۳). دسترسی حساب و گزارش امنیتی هاست را بررسی کنید.');
      if (response.status === 404) throw new Error('فایل مدیریت بازی‌های این رویداد روی هاست پیدا نشد (۴۰۴). فایل‌های به‌روزرسانی را کامل بارگذاری کنید.');
      if (response.status === 422) throw new Error('سرور درخواست تنظیمات را رد کرد و دلیل را نمایش نداد. فایل games.php و فایل‌های مشترک را به‌روز کنید؛ اگر ادامه داشت، گزارش امنیتی هاست را بررسی کنید.');
      throw new Error(`سرور به‌جای پاسخ بازی‌ها، صفحهٔ غیرمعتبر برگرداند (HTTP ${response.status}). گزارش خطای PHP هاست را بررسی کنید.`);
    }
    if (!result || typeof result !== 'object') throw new Error('پاسخ سرور بازی‌ها نامعتبر است.');
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
      list.innerHTML = games.length ? `<div class="egm-games-list">${games.map(item => `<button type="button" class="egm-games-nav" data-open-game="${escapeHtml(item.id)}"><span><strong>${escapeHtml(item.name)}</strong><small>${item.has_levels ? `${(item.levels || []).length} مرحله` : item.no_score_needed ? 'بدون امتیاز' : 'یک امتیاز'} · ${item.auto_room_manager ? 'تخصیص خودکار اتاق' : 'تخصیص دستی اتاق'}</small></span><span aria-hidden="true">‹</span></button>`).join('')}</div>` : '<p class="muted">هنوز بازی‌ای ساخته نشده است.</p>';
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
      ${game.has_levels ? '' : '<button type="button" class="btn ghost" data-level-add>+ افزودن مرحله</button>'}
      <form data-game-settings class="egm-games-settings">
        <h4>تنظیمات بازی</h4>
        <div class="egm-games-fields">
          <label class="field"><span>نام بازی</span><input type="text" name="name" maxlength="100" required value="${escapeHtml(game.name)}"></label>
          <label class="field"><span>ساختار بازی</span><select name="mode"><option value="single" ${game.has_levels ? '' : 'selected'}>بازی بدون مرحله</option><option value="levels" ${game.has_levels ? 'selected' : ''}>بازی چندمرحله‌ای</option></select></label>
          <label class="field"><span>روش ثبت نتیجه</span><select name="result_mode"><option value="score" ${game.no_score_needed ? '' : 'selected'}>ثبت امتیاز</option><option value="completion" ${game.no_score_needed ? 'selected' : ''}>بدون امتیاز · تأیید پایان بازی یا مرحله</option></select></label>
          <label class="field"><span>ترکیب تیم</span><select name="gender_mode"><option value="normal" ${game.gender_mode === 'normal' ? 'selected' : ''}>عادی</option><option value="separated" ${game.gender_mode === 'separated' ? 'selected' : ''}>تفکیک جنسیتی</option></select></label>
          <label class="field"><span>تخصیص اتاق</span><select name="auto_mode"><option value="off" ${game.auto_room_manager ? '' : 'selected'}>دستی</option><option value="on" ${game.auto_room_manager ? 'selected' : ''}>خودکار</option></select></label>
          <label class="field"><span>حداقل اعضای تیم</span><input name="min_players" type="number" min="1" max="100" required value="${escapeHtml(game.min_players)}"></label>
          <label class="field"><span>حداکثر اعضای تیم</span><input name="max_players" type="number" min="1" max="100" required value="${escapeHtml(game.max_players)}"></label>
        </div><section class="egm-cover-color-setting" data-game-score-count><h4>نوبت‌های امتیاز</h4><label class="field"><span>تعداد امتیاز در هر مرحله</span><input name="score_count" type="number" min="1" max="20" required value="${escapeHtml(game.score_count || 1)}"></label></section><div class="egm-cover-color-setting"><label class="field"><span>شناسایی تیم با رنگ پوشش</span><select name="require_cover_color"><option value="off" ${game.require_cover_color ? '' : 'selected'}>ثبت رنگ پوشش الزامی نیست</option><option value="on" ${game.require_cover_color ? 'selected' : ''}>داور باید رنگ پوشش تیم را ثبت کند</option></select></label><p>با فعال کردن این گزینه، داور هنگام تشکیل تیم رنگ لباس یا کاور اعضا را مشخص می‌کند. این رنگ در فهرست تیم‌ها و صفحهٔ داور نمایش داده می‌شود.</p></div><div class="egm-games-form-actions"><button class="btn primary" type="submit">ذخیره تنظیمات</button></div></form>
      ${game.has_levels ? `<section class="egm-games-section"><div class="egm-games-section-head"><h4>مراحل</h4><button type="button" class="btn ghost" data-level-add>+ افزودن مرحله</button></div>
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
        if (newAuto && !game.auto_room_manager) {
          const levels = newMode ? (game.has_levels ? game.levels || [] : []) : [{rooms:game.rooms || []}];
          if (!levels.length) throw new Error('ابتدا مراحل و اتاق‌های بازی را اضافه کنید، سپس تخصیص خودکار را فعال کنید.');
          if (levels.some(level => !level.rooms?.length)) throw new Error('ابتدا برای هر مرحله اتاق اضافه کنید، سپس تخصیص خودکار را فعال کنید.');
        }
        if (!newMode && game.has_levels && game.levels?.length && !confirm('با حذف مراحل، نام و ترتیب آن‌ها پاک می‌شود. ادامه می‌دهید؟')) {
          form.dispatchEvent(new CustomEvent('egm-game-settings-result', {bubbles:true, detail:{ok:false}}));
          return;
        }
        const noScoreNeeded = form.elements.result_mode.value === 'completion';
        if (noScoreNeeded !== !!game.no_score_needed) await request('save_no_score_mode', {game_id:game.id, enabled:noScoreNeeded});
        if (game.auto_room_manager && !newAuto) await request('save_auto_mode', {game_id:game.id, enabled:false});
        if (newMode !== game.has_levels) await request('save_mode', {game_id:game.id, has_levels:newMode});
        if (newGender !== game.gender_mode) await request('save_gender_mode', {game_id:game.id, gender_mode:newGender});
        const scoreCount = Number(form.elements.score_count.value);
        if (min !== Number(game.min_players) || max !== Number(game.max_players) || scoreCount !== Number(game.score_count || 1)) await request('save_limits', {game_id:game.id, min_players:min, max_players:max, score_count:scoreCount});
        const requireCoverColor = form.elements.require_cover_color.value === 'on';
        if (requireCoverColor !== !!game.require_cover_color) await request('save_cover_color_requirement', {game_id:game.id, required:requireCoverColor});
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
        await request('save_room', {game_id:game.id, level_id:form.dataset.levelId, room_id:form.dataset.roomId || '', name:form.elements.name.value, gender:form.elements.gender.value});
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
  catalog?.addEventListener('change', async event => {
    const input = event.target.closest('[data-room-enabled]');
    if (!input) return;
    const game = games.find(item => item.id === selectedGameId);
    const status = catalog.querySelector('[data-games-status]');
    const enabled = input.checked;
    input.disabled = true;
    try {
      const fields = {game_id:game.id, level_id:input.dataset.roomLevel, room_id:input.dataset.roomEnabled, enabled};
      let result = await request('set_room_enabled', fields);
      if (result.requires_confirmation) {
        const names = result.teams.map(team => team.name || 'تیم ' + team.id).join('، ');
        if (!confirm('این اتاق در اختیار '+names+' است. با غیرفعال کردن، تیم به اتاق دیگری در همین مرحله منتقل می‌شود یا در صف انتظار قرار می‌گیرد. ادامه می‌دهید؟')) { input.checked = !enabled; return; }
        result = await request('set_room_enabled', {...fields, confirmed:true});
      }
      await loadCatalog();
      status.textContent = enabled ? 'اتاق فعال شد.' : 'اتاق غیرفعال شد.';
    } catch (error) { input.checked = !enabled; status.textContent = error.message; }
    finally { if (input.isConnected) input.disabled = false; }
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
    if (button.hasAttribute('data-level-add')) {
      addingLevel = true;
      const game = games.find(item => item.id === selectedGameId);
      if (game && !game.has_levels) {
        const settings = catalog.querySelector('[data-game-settings]');
        if (!game.has_levels) settings.elements.mode.value = 'levels';

        catalog.querySelector('[data-games-status]').textContent = 'ابتدا ساختار بازی را چندمرحله‌ای انتخاب و ذخیره کنید.';
        const host = settings.closest('[data-game-id]');
        host.dispatchEvent(new CustomEvent('egm-flow-open', {detail: {index: 0}}));
        (host.querySelector('.egm-flow-footer .primary') || settings.querySelector('[type="submit"]')).focus();
        settings.scrollIntoView({block:'nearest'});
        return;
      }
      renderCatalog();
      catalog.querySelector('[data-level-create] input')?.focus();
      return;
    }
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
  const periodTeams = new WeakMap();
  const scoreNumber = value => Number(value || 0).toLocaleString('fa-IR', {maximumFractionDigits:2});
  const roundsFor = (team, levelId) => Object.entries(team.score_attempts?.[levelId] || team.scores?.[levelId]?.attempts || (team.scores?.[levelId] ? [team.scores[levelId]] : [])).filter(([,round]) => round && typeof round === 'object').map(([index,round]) => ({...round, roundIndex:Number(index)}));
  const levelTotal = (team, id) => team.scores?.[id] ? Number(team.scores[id].score || 0) : roundsFor(team, id).reduce((sum, round) => sum + Number(round.score || 0), 0);
  const teamScoreTotal = team => [...new Set([...Object.keys(team.scores || {}), ...Object.keys(team.score_attempts || {})])].reduce((sum, id) => sum + levelTotal(team, id), 0);
  const scoreTime = value => value && Number.isFinite(Date.parse(value)) ? new Date(value).toLocaleString('fa-IR') : '';
  const teamStyles = document.createElement('style');
  teamStyles.textContent = `.egm-team-score-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px}.egm-team-score-head h3{margin:0}.egm-team-score-total{font-size:1.2rem;font-weight:700;color:#169ce0}.egm-team-score-level{border:1px solid #e1e7ef;border-radius:16px;padding:18px;margin:14px 0;background:#f9fbfe}.egm-team-score-level header{display:flex;justify-content:space-between;gap:12px;margin-bottom:14px}.egm-team-score-rounds{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px}.egm-team-score-rounds input{width:100%;box-sizing:border-box;border:1px solid #dce4ee;border-radius:12px;padding:12px;background:white;color:inherit;font:inherit}.egm-team-score-rounds small{display:block;color:#798597;margin-top:6px}.egm-team-score-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.egm-team-score-status{color:#798597}.egm-game-teams .egm-game-row{gap:12px;flex-wrap:wrap}.egm-team-score-members{color:#66758b;line-height:1.9;margin:16px 0}`;
  teamStyles.textContent += '.egm-team-table-scroll{overflow-x:auto;margin:12px 0 24px;border:1px solid #e1e7ef;border-radius:14px}.egm-team-score-table{width:100%;border-collapse:collapse;font-size:.9rem}.egm-team-score-table th{background:#f0f6fd;white-space:nowrap;color:#51637d}.egm-team-score-table th,.egm-team-score-table td{padding:14px;text-align:right;vertical-align:top;border-bottom:1px solid #e1e7ef;min-width:115px}.egm-team-score-table td:first-child{min-width:170px}.egm-team-score-table small{display:block;color:#798597;line-height:1.8}.egm-team-score-table button{white-space:nowrap;margin-bottom:8px}.egm-team-score-table tbody tr:last-child td{border-bottom:0}';
  shell.append(teamStyles);
  function showTeamScores(pane, gameId, teamId) {
    const entry = periodTeams.get(pane)?.get(gameId);
    const team = entry?.teams.find(item => Number(item.id) === Number(teamId));
    if (!team) return;
    const {game, target} = entry;
    const levels = game.has_levels ? game.levels : [{id:'game_total', name:'امتیاز بازی'}];
    const canEdit = shell.dataset.egmCanManageGames === '1' && !game.no_score_needed;
    target.innerHTML = `<div class="egm-team-score-head"><div><h3>${escapeHtml(team.name)}</h3><span class="muted">تسهیلگر: ${escapeHtml(team.creator?.name || 'نامشخص')}</span></div><button type="button" class="btn ghost" data-team-score-back>بازگشت به تیم‌ها</button></div>
      <form data-team-score-form data-game-id="${escapeHtml(gameId)}" data-team-id="${team.id}"><div class="egm-team-score-total">امتیاز کل: <span data-score-team-total>${scoreNumber(teamScoreTotal(team))}</span></div>
      ${levels.map(level => {
        const rounds = roundsFor(team, level.id);
        const required = Number(team.score_count ?? game.score_count ?? 1);
        const finished = Boolean(team.scores?.[level.id]);
        const total = rounds.reduce((sum, round) => sum + Number(round.score || 0), 0);
        return `<section class="egm-team-score-level" data-score-level><header><strong>${escapeHtml(level.name)}</strong><span>${game.no_score_needed ? (finished ? 'انجام شده' : 'انجام نشده') : `${finished ? 'تکمیل شده' : `${scoreNumber(rounds.length)} از ${scoreNumber(required)} نوبت`} · جمع: <b data-score-level-total>${scoreNumber(total)}</b>`}</span></header><div class="egm-team-score-rounds">${rounds.length ? rounds.map((round, index) => `<label class="field"><span>نوبت ${scoreNumber(index + 1)}</span>${canEdit && !round.completion_only ? `<input type="number" min="0" max="999999.99" step="0.01" required value="${escapeHtml(round.score)}" data-round-score data-level-id="${escapeHtml(level.id)}" data-round-index="${round.roundIndex}" data-original-score="${escapeHtml(round.score)}" aria-label="امتیاز ${escapeHtml(level.name)} نوبت ${index + 1}">` : `<strong>${round.completion_only ? 'انجام شده' : scoreNumber(round.score)}</strong>`}<small>${escapeHtml(round.room_name || '')}</small><small>${escapeHtml(scoreTime(round.submitted_at))}</small><small>${escapeHtml(round.submitted_by || '')}</small></label>`).join('') : '<span class="muted">هنوز امتیازی ثبت نشده است.</span>'}</div></section>`;
      }).join('')}
      ${canEdit ? '<div class="egm-team-score-actions"><button type="submit" class="btn primary" disabled data-score-save>ذخیره اصلاحات</button><span class="egm-team-score-status" role="status" data-score-save-status></span></div>' : ''}</form>
      <div class="egm-team-score-members"><strong>اعضای تیم</strong><div class="egm-team-table-scroll"><table class="egm-team-score-table"><thead><tr><th>نام</th><th>کد ملی</th><th>کد پرسنلی</th><th>شماره همراه</th></tr></thead><tbody>${(team.members || []).map(member => `<tr><th scope="row">${escapeHtml(memberName(member))}</th><td dir="ltr">${escapeHtml(member.national_id || '—')}</td><td dir="ltr">${escapeHtml(member.work_id || '—')}</td><td dir="ltr">${escapeHtml(member.phone_number || '—')}</td></tr>`).join('')}</tbody></table></div>${shell.dataset.egmCanManageGames === '1' ? `<button type="button" class="btn ghost" data-team-members-open="${team.id}" data-score-game="${escapeHtml(gameId)}">ویرایش اعضا</button>` : ''}</div>`;
    target.querySelector('[data-team-score-back]').addEventListener('click', () => {
      const changed = [...target.querySelectorAll('[data-round-score]')].some(input => Number(input.value) !== Number(input.dataset.originalScore));
      if (changed && !confirm('اصلاحات امتیاز ذخیره نشده است. بازگردید؟')) return;
      void loadPeriod(pane);
    });
    const form = target.querySelector('[data-team-score-form]');
    form.addEventListener('input', () => {
      if (!canEdit) return;
      let total = teamScoreTotal(team);
      for (const section of form.querySelectorAll('[data-score-level]')) {
        const values = [...section.querySelectorAll('[data-round-score]')];
        if (!values.length) continue;
        const sum = values.reduce((n, input) => n + (Number(input.value) || 0), 0);
        section.querySelector('[data-score-level-total]').textContent = scoreNumber(sum);
        const id = values[0].dataset.levelId;
        total += sum - roundsFor(team, id).reduce((n, round) => n + Number(round.score || 0), 0);
      }
      form.querySelector('[data-score-team-total]').textContent = scoreNumber(total);
      const changed = [...form.querySelectorAll('[data-round-score]')].some(input => Number(input.value) !== Number(input.dataset.originalScore));
      form.querySelector('[data-score-save]').disabled = !changed;
    });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const button = form.querySelector('[data-score-save]');
      const message = form.querySelector('[data-score-save-status]');
      const changes = [...form.querySelectorAll('[data-round-score]')].filter(input => Number(input.value) !== Number(input.dataset.originalScore)).map(input => ({level_id:input.dataset.levelId,index:Number(input.dataset.roundIndex),score:Number(input.value),expected_score:Number(input.dataset.originalScore)}));
      if (!changes.length) return;
      button.disabled = true;
      form.querySelectorAll('input').forEach(input => input.disabled = true);
      try {
        const result = await request('correct_team_scores', {period_code:pane.dataset.taskTagCode,game_id:gameId,team_id:team.id,changes:JSON.stringify(changes)});
        Object.assign(team, result.team);
        showTeamScores(pane, gameId, team.id);
        target.querySelector('[data-score-save-status]').textContent = 'اصلاحات ذخیره شد.';
      } catch (error) { message.textContent = error.message; button.disabled = false; form.querySelectorAll('input').forEach(input => input.disabled = false); }
    });
  }
  const monitors = new Map();
  const memberName = member => member.full_name || member.name || [member.first_name, member.last_name].filter(Boolean).join(' ') || String(member.id);
  const genderOf = team => {
    if (team.gender) return team.gender;
    const genders = new Set((team.members || []).map(member => member.gender));
    return genders.size === 1 && ['male','female'].includes([...genders][0]) ? [...genders][0] : genders.has('male') && genders.has('female') ? 'mixed' : '';
  };
  const stateOf = team => team.ended_at ? 'ended' : team.room_assignment ? 'room' : team.waiting_for_room ? 'waiting' : team.started_at ? 'playing' : 'ready';
  const stateLabel = team => ({ended:'پایان‌یافته',room:'در اتاق',waiting:'در انتظار اتاق',playing:'در جریان',ready:'آماده شروع'})[stateOf(team)];
  const elapsed = (timestamp, offset = 0) => {
    const start = Date.parse(timestamp);
    if (!Number.isFinite(start)) return '—';
    const seconds = Math.max(0, Math.floor((Date.now() + offset - start) / 1000));
    return [Math.floor(seconds / 3600), Math.floor(seconds / 60) % 60, seconds % 60].map(value => String(value).padStart(2, '0')).join(':');
  };
  const clockHtml = timestamp => timestamp ? `<time data-room-clock="${escapeHtml(timestamp)}" dir="ltr">${elapsed(timestamp)}</time>` : '—';
  teamStyles.textContent += `
    .egm-ranking-scroll{max-width:100%;overflow:auto;border:1px solid #e0e8f0;border-radius:16px;max-height:70vh;scrollbar-width:thin}.egm-ranking-table{width:100%;border-collapse:separate;border-spacing:0;text-align:right;font-size:.9rem}.egm-ranking-table thead th{position:sticky;top:0;z-index:1;background:#eef5fb;color:#52677d;white-space:nowrap}.egm-ranking-table th,.egm-ranking-table td{padding:16px 14px;border-bottom:1px solid #e0e8f0;vertical-align:top;min-width:115px}.egm-ranking-table th:first-child,.egm-ranking-table td:first-child{min-width:40px}.egm-ranking-table tbody th{min-width:170px;font-weight:400}.egm-ranking-table tbody tr:nth-child(even){background:#f8fbfe}.egm-ranking-table tbody tr:hover{background:#eef8ff}.egm-ranking-table small{display:block;color:#64748b;font-size:.78rem;line-height:1.9;margin-top:4px}.egm-ranking-table .egm-monitor-team-actions{min-width:170px}.egm-ranking-table [data-rank="1"] .egm-monitor-rank{background:#fff1cf;color:#94600e}.egm-ranking-state{display:inline-block;padding:5px 9px;border-radius:8px;background:#eef3fa;white-space:nowrap}.egm-ranking-state[data-state="room"]{background:#ddf5eb;color:#087454}.egm-ranking-state[data-state="waiting"]{background:#fff0d1;color:#8c5b0a}.egm-team-score-actions{position:sticky;bottom:12px;background:white;border:1px solid #e0e8f0;border-radius:14px;padding:12px;z-index:2}.egm-game-teams{min-width:0}
    .egm-monitor{--monitor-border:#e0e8f0;--monitor-muted:#64748b;color:#162c44}
    .egm-monitor-head,.egm-monitor-toolbar,.egm-monitor-game-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
    .egm-monitor-head h3{margin:0;font-size:1.35rem}.egm-monitor-head p{margin:6px 0;color:var(--monitor-muted)}
    .egm-monitor-connection{display:flex;align-items:center;gap:8px;font-size:.85rem;color:var(--monitor-muted)}
    .egm-monitor-connection:before{content:'';width:9px;height:9px;border-radius:50%;background:#e4a52a}.egm-monitor-connection[data-live="1"]:before{background:#17a786;box-shadow:0 0 0 4px #17a78618}
    .egm-monitor-tabs{display:flex;gap:10px;overflow-x:auto;padding:18px 0}.egm-monitor-tabs button{flex-shrink:0;border:1px solid var(--monitor-border);border-radius:14px;background:#fff;padding:12px 18px;color:inherit;font:inherit;text-align:right;cursor:pointer}.egm-monitor-tabs button[aria-selected="true"]{border-color:#178fca;background:#eef8ff;color:#0873ad}.egm-monitor-tabs small{display:block;margin-top:5px;color:var(--monitor-muted)}
    .egm-monitor-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0}.egm-monitor-stat{padding:16px;border:1px solid var(--monitor-border);border-radius:16px;background:#f8fbff}.egm-monitor-stat strong{display:block;font-size:1.7rem;margin-bottom:5px}.egm-monitor-stat span{font-size:.85rem;color:var(--monitor-muted)}
    .egm-monitor-settings{border:1px solid var(--monitor-border);padding:12px 16px;border-radius:12px;margin:12px 0}.egm-monitor-settings summary{cursor:pointer;color:var(--monitor-muted)}.egm-monitor-settings .btn{margin-top:12px}
    .egm-monitor-toolbar{margin:24px 0 14px}.egm-monitor-toolbar .field{margin:0;min-width:180px;flex:1}.egm-monitor-toolbar input,.egm-monitor-toolbar select{width:100%;box-sizing:border-box;padding:11px;border:1px solid var(--monitor-border);border-radius:10px;font:inherit}
    .egm-monitor-group{border-top:2px solid var(--monitor-border);padding-top:18px;margin-top:24px}.egm-monitor-group h4{display:flex;align-items:center;gap:12px;margin:0 0 16px;font-size:1.1rem}.egm-monitor-group h4 small{font-weight:400;font-size:.8rem;color:var(--monitor-muted)}
    .egm-monitor-team{display:grid;grid-template-columns:42px minmax(120px,1.4fr) minmax(130px,1fr) minmax(150px,1fr) auto;gap:18px;align-items:center;padding:18px 0;border-bottom:1px solid var(--monitor-border)}.egm-monitor-rank{display:grid;place-items:center;background:#edf4fa;border-radius:12px;width:36px;height:36px;font-weight:700}.egm-monitor-team[data-rank="1"] .egm-monitor-rank{background:#fff1cf;color:#94600e}.egm-monitor-team strong{display:block}.egm-monitor-team small{display:block;font-size:.8rem;line-height:1.8;color:var(--monitor-muted)}.egm-monitor-score{font-size:1.4rem;color:#0873ad}.egm-monitor-team-actions{display:flex;gap:6px;flex-wrap:wrap}.egm-monitor-team time,.egm-monitor-room time{font-variant-numeric:tabular-nums}
    .egm-monitor-rooms{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}.egm-monitor-room{border:1px solid var(--monitor-border);padding:16px;border-radius:14px;background:#f8fbff}.egm-monitor-room[data-occupied="1"]{border-color:#67c8b2;background:#f0fbf7}.egm-monitor-room strong,.egm-monitor-room small{display:block;margin-bottom:6px}.egm-monitor-room small{color:var(--monitor-muted)}
    .egm-monitor-empty{padding:24px;text-align:center;color:var(--monitor-muted);border:1px dashed var(--monitor-border);border-radius:14px}.egm-monitor-updated{padding:10px;border-radius:10px;background:#fff4db;color:#865d18;margin-bottom:16px}.egm-monitor-members-list{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}.egm-monitor-member{display:flex;align-items:center;gap:12px;border:1px solid var(--monitor-border);padding:10px;border-radius:12px}.egm-monitor-search-results{display:grid;gap:8px;margin:14px 0}
    @media(max-width:760px){.egm-monitor-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.egm-monitor-team{grid-template-columns:36px 1fr;gap:10px}.egm-monitor-team>div:nth-child(n+3){grid-column:2}.egm-monitor-team-actions .btn{flex:1;min-width:90px}.egm-monitor-team-actions [data-team-score-open]{flex-basis:100%}.egm-monitor-toolbar{align-items:stretch}.egm-monitor-tabs button{padding:10px 12px}}
  `;
  function renderMonitor(pane) {
    const monitor = monitors.get(pane);
    const list = pane.querySelector('[data-period-games-list]');
    if (!monitor?.data || !list) return;
    // Incoming updates never replace a score or membership draft.
    if (list.querySelector('[data-team-score-form], [data-member-editor]')) {
      if (!list.querySelector('[data-monitor-draft-notice]')) list.insertAdjacentHTML('afterbegin', '<p class="egm-monitor-updated" data-monitor-draft-notice>اطلاعات زنده به‌روز شد؛ پیش‌نویس شما حفظ شده است. برای دیدن آخرین وضعیت به فهرست تیم‌ها برگردید.</p>');
      return;
    }
    const {data} = monitor;
    const enabled = new Set(data.enabled || []);
    if (!data.games.some(game => game.id === monitor.selected)) monitor.selected = data.games.find(game => enabled.has(game.id))?.id || data.games[0]?.id;
    const game = data.games.find(item => item.id === monitor.selected);
    const teams = game?.teams || [];
    const active = teams.filter(team => !team.ended_at && team.started_at);
    list.innerHTML = `<section class="egm-monitor"><header class="egm-monitor-head"><div><h3>مرکز پایش بازی‌ها</h3><p>رتبه‌بندی، وضعیت تیم‌ها و زمان حضور در اتاق‌ها</p></div><div><span class="egm-monitor-connection" data-monitor-connection data-live="${monitor.live ? '1' : '0'}">${monitor.live ? 'اتصال زنده' : 'به‌روزرسانی خودکار'} · <time data-monitor-last-update>${new Date(monitor.updated || Date.now()).toLocaleTimeString('fa-IR')}</time></span><button type="button" class="btn ghost" data-monitor-refresh>تازه‌سازی</button></div></header>
      <div class="egm-monitor-tabs" role="tablist" aria-label="بازی‌های بازه">${data.games.map(item => `<button type="button" role="tab" data-monitor-game="${escapeHtml(item.id)}" aria-selected="${item.id === monitor.selected}">${escapeHtml(item.name)}<small>${enabled.has(item.id) ? 'فعال' : 'غیرفعال'} · ${scoreNumber(item.teams?.length || 0)} تیم</small></button>`).join('')}</div>
      ${game ? `<div class="egm-monitor-game-head"><h3>${escapeHtml(game.name)}</h3><span>${enabled.has(game.id) ? 'فعال در این بازه' : 'غیرفعال در این بازه'}</span></div><details class="egm-monitor-settings"><summary>تنظیمات فعال‌سازی بازی در این بازه</summary><p class="muted">غیرفعال‌سازی بازی دارای تیم ثبت‌شده امکان‌پذیر نیست.</p><button type="button" class="btn ghost" data-monitor-toggle="${escapeHtml(game.id)}" data-enabled="${enabled.has(game.id) ? '0' : '1'}">${enabled.has(game.id) ? 'غیرفعال کردن بازی' : 'فعال کردن بازی'}</button></details>
      <div class="egm-monitor-stats">${[[teams.length,'کل تیم‌ها'],[active.length,'در جریان'],[teams.filter(team => team.waiting_for_room).length,'در انتظار اتاق'],[teams.filter(team => team.ended_at).length,'پایان‌یافته']].map(([value,label]) => `<div class="egm-monitor-stat"><strong>${scoreNumber(value)}</strong><span>${label}</span></div>`).join('')}</div>
      <details open><summary>پایش اتاق‌ها</summary><div class="egm-monitor-rooms">${(game.has_levels ? game.levels || [] : [{name:'بازی', rooms:game.rooms || []}]).flatMap(level => (level.rooms || []).map(room => {
        const occupants = teams.filter(team => !team.ended_at && team.room_assignment?.room_id === room.id);
        return `<article class="egm-monitor-room" data-occupied="${occupants.length ? '1' : '0'}"><strong>${escapeHtml(room.name)}</strong><small>${escapeHtml(level.name)} · ${roomGenderLabel(room.gender)}</small>${room.enabled === false ? 'غیرفعال' : occupants.length ? occupants.map(team => `<strong>${escapeHtml(team.name)}</strong><small>زمان حضور: ${clockHtml(team.room_assignment.assigned_at)}</small>`).join('') : 'آزاد'}</article>`;
      })).join('') || '<p class="muted">اتاقی تعریف نشده است. وضعیت تیم‌ها در فهرست زیر نمایش داده می‌شود.</p>'}</div></details>
      <div class="egm-monitor-toolbar"><label class="field"><span>جستجوی تیم، عضو یا تسهیلگر</span><input type="search" data-monitor-search value="${escapeHtml(monitor.search || '')}" placeholder="نام یا کد پرسنلی…"></label><label class="field"><span>وضعیت تیم</span><select data-monitor-filter>${[['all','همه تیم‌ها'],['room','در اتاق'],['waiting','در انتظار اتاق'],['playing','در جریان'],['ready','آماده شروع'],['ended','پایان‌یافته']].map(([value,label]) => `<option value="${value}" ${(monitor.filter || 'all') === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label></div><div class="egm-game-teams" data-period-game-teams="${escapeHtml(game.id)}"></div>` : '<p class="egm-monitor-empty">ابتدا از تب بازی‌ها یک بازی بسازید.</p>'}</section>`;
    periodTeams.set(pane, new Map());
    if (game) {
      const target = list.querySelector('[data-period-game-teams]');
      periodTeams.get(pane).set(game.id, {game, teams, target});
      renderRankings(pane);
    }
    tickClocks();
  }
  function renderRankings(pane) {
    const monitor = monitors.get(pane);
    const entry = periodTeams.get(pane)?.get(monitor.selected);
    if (!entry) return;
    const {game, teams, target} = entry;
    if (target.querySelector('[data-team-score-form], [data-member-editor]')) return;
    const query = (monitor.search || '').trim().toLocaleLowerCase();
    const visible = team => (!query || [team.name, team.creator?.name, ...(team.members || []).flatMap(member => [memberName(member),member.work_id])].join(' ').toLocaleLowerCase().includes(query)) && (!monitor.filter || monitor.filter === 'all' || stateOf(team) === monitor.filter);
    const sorted = [...teams].sort((a,b) => (game.has_levels ? game.levels || [] : [{id:'game_total'}]).reduce((sum,level)=>sum+levelTotal(b,level.id)-levelTotal(a,level.id),0) || Number(a.id) - Number(b.id));
    const levels = game.has_levels ? game.levels || [] : [{id:'game_total',name:'امتیاز بازی'}];
    const groups = game.gender_mode === 'separated' ? [['female','رتبه‌بندی بانوان'],['male','رتبه‌بندی آقایان'],['mixed','تیم‌های ترکیبی'],['','جنسیت نامشخص']] : [['all','رتبه‌بندی تیم‌ها']];
    target.innerHTML = groups.map(([gender,label]) => {
      const group = sorted.filter(team => gender === 'all' || genderOf(team) === gender);
      if (!group.length && !['all','female','male'].includes(gender)) return '';
      let previousScore = null, rank = 0;
      const ranked = group.map((team,index) => {
        const total = levels.reduce((sum, level) => sum + levelTotal(team, level.id), 0);
        if (total !== previousScore) rank = index + 1;
        previousScore = total;
        return {team, rank, total};
      }).filter(({team}) => visible(team));
      return `<section class="egm-monitor-group" data-ranking-gender="${gender || 'unknown'}"><h4>${label}<small>${scoreNumber(ranked.length)} از ${scoreNumber(group.length)} تیم</small></h4>${ranked.length ? `<div class="egm-ranking-scroll" tabindex="0" aria-label="جدول ${label}"><table class="egm-ranking-table"><thead><tr><th scope="col">رتبه</th><th scope="col">تیم و تسهیلگر</th><th scope="col">امتیاز کل</th>${levels.map(level => `<th scope="col">${escapeHtml(level.name)}</th>`).join('')}<th scope="col">وضعیت و اتاق</th><th scope="col">عملیات</th></tr></thead><tbody>${ranked.map(({team,rank,total}) => `<tr data-monitor-team="${Number(team.id)}" data-rank="${rank}"><td><span class="egm-monitor-rank">${game.no_score_needed ? '—' : scoreNumber(rank)}</span></td><th scope="row"><strong>${escapeHtml(team.name)}</strong><small>${escapeHtml(team.creator?.name || 'تسهیلگر نامشخص')}</small><small>${scoreNumber(team.members?.length || 0)} عضو · ${scoreNumber(Object.keys(team.scores || {}).length)} از ${scoreNumber(levels.length)} مرحله${team.cover_color ? ' · '+escapeHtml(team.cover_color) : ''}</small></th><td><strong class="egm-monitor-score">${game.no_score_needed ? '—' : scoreNumber(total)}</strong></td>${levels.map(level => {
        const rounds = roundsFor(team,level.id); const complete = Boolean(team.scores?.[level.id]);
        return `<td data-ranking-level="${escapeHtml(level.id)}"><strong>${game.no_score_needed ? (complete ? '✓' : '—') : rounds.length || complete ? scoreNumber(levelTotal(team,level.id)) : '—'}</strong><small>${complete ? 'تکمیل شده' : `${scoreNumber(rounds.length)} از ${scoreNumber(team.score_count ?? game.score_count ?? 1)} نوبت`}</small>${!game.no_score_needed && rounds.length ? `<small>${rounds.map((round,index) => `نوبت ${scoreNumber(index+1)}: ${scoreNumber(round.score)}`).join(' · ')}</small>` : ''}</td>`;
      }).join('')}<td><span class="egm-ranking-state" data-state="${stateOf(team)}">${stateLabel(team)}</span><small>${team.room_assignment ? escapeHtml(team.room_assignment.level_name || '')+' / '+escapeHtml(team.room_assignment.room_name || '') : ''}</small>${team.room_assignment && !team.ended_at ? `<small>زمان حضور: ${clockHtml(team.room_assignment.assigned_at)}</small>` : ''}</td><td><div class="egm-monitor-team-actions"><button type="button" class="btn ghost" data-team-score-open="${Number(team.id)}" data-score-game="${escapeHtml(game.id)}">${shell.dataset.egmCanManageGames === '1' && !game.no_score_needed ? 'جزئیات و ویرایش امتیاز' : 'جزئیات تیم'}</button>${shell.dataset.egmCanManageGames === '1' ? `<button type="button" class="btn ghost" data-team-members-open="${Number(team.id)}" data-score-game="${escapeHtml(game.id)}">ویرایش اعضا</button>` : ''}${!team.ended_at && shell.dataset.egmCanEndGames === '1' ? `<button type="button" class="btn ghost" data-end-team="${Number(team.id)}" data-end-game="${escapeHtml(game.id)}">پایان بازی</button>` : ''}</div></td></tr>`).join('')}</tbody></table></div>` : '<p class="egm-monitor-empty">تیمی برای نمایش وجود ندارد.</p>'}</section>`;
    }).join('');
    tickClocks();
  }
  function tickClocks() {
    for (const [pane, monitor] of monitors) for (const clock of pane.querySelectorAll('[data-room-clock]')) clock.textContent = elapsed(clock.dataset.roomClock, monitor.offset || 0);
  }
  function acceptSnapshot(pane, data, live = false) {
    const monitor = monitors.get(pane);
    if (!monitor || !Array.isArray(data.games)) return;
    const changed = !data.revision || data.revision !== monitor.data?.revision;
    monitor.data = data; monitor.live = live; monitor.updated = Date.now();
    if (data.server_time) monitor.offset = Date.parse(data.server_time) - Date.now();
    if (changed) {
      const focused = pane.contains(document.activeElement) ? document.activeElement : null;
      const searchFocused = focused?.matches('[data-monitor-search]');
      const filterFocused = focused?.matches('[data-monitor-filter]');
      const selection = searchFocused ? [focused.selectionStart, focused.selectionEnd] : null;
      const scrolls = [...pane.querySelectorAll('[data-ranking-gender]')].map(group => [group.dataset.rankingGender, group.querySelector('.egm-ranking-scroll')?.scrollLeft || 0]);
      renderMonitor(pane);
      for (const [gender, left] of scrolls) {
        const group = [...pane.querySelectorAll('[data-ranking-gender]')].find(node => node.dataset.rankingGender === gender);
        const scroll = group?.querySelector('.egm-ranking-scroll'); if (scroll) scroll.scrollLeft = left;
      }
      const input = pane.querySelector(searchFocused ? '[data-monitor-search]' : filterFocused ? '[data-monitor-filter]' : '[data-do-not-focus]');
      if (input) { input.focus({preventScroll:true}); if (selection && input.setSelectionRange) { try { input.setSelectionRange(...selection); } catch {} } }
    }
    const connection = pane.querySelector('[data-monitor-connection]');
    if (connection) { connection.dataset.live = live ? '1' : '0'; connection.firstChild.textContent = (live ? 'اتصال زنده' : 'به‌روزرسانی خودکار')+' · '; }
    const updated = pane.querySelector('[data-monitor-last-update]');
    if (updated) updated.textContent = new Date(monitor.updated).toLocaleTimeString('fa-IR');
    pane.querySelector('[data-period-games-status]').textContent = '';
  }
  function startSocket(pane) {
    const monitor = monitors.get(pane);
    if (monitor.socket || !window.WebSocket || Date.now() < (monitor.retryAt || 0)) return;
    const url = new URL('egm-games-live', new URL(endpoint, location.href));
    url.protocol = location.protocol === 'https:' ? 'wss:' : 'ws:';
    url.searchParams.set('endpoint', new URL(endpoint, location.href).pathname);
    url.searchParams.set('period_code', pane.dataset.taskTagCode);
    let socket;
    try { socket = new WebSocket(url); } catch { monitor.retryAt = Date.now()+30000; return; }
    monitor.socket = socket;
    monitor.socketStarted = Date.now(); monitor.lastMessage = 0;
    socket.onmessage = event => {
      try {
        const data = JSON.parse(event.data);
        if (data.type === 'snapshot') { monitor.lastMessage = Date.now(); acceptSnapshot(pane, data.snapshot, true); }
        if (data.type === 'heartbeat') { monitor.lastMessage = Date.now(); acceptSnapshot(pane, {...monitor.data,server_time:data.server_time}, true); }
        if (data.type === 'error') { pane.querySelector('[data-period-games-status]').textContent = data.message; socket.close(); }
      } catch { socket.close(); }
    };
    socket.onclose = () => {
      if (monitor.socket !== socket) return;
      monitor.socket = null; monitor.live = false; monitor.retryAt = Date.now()+30000;
      const connection = pane.querySelector('[data-monitor-connection]');
      if (connection) { connection.dataset.live = '0'; connection.firstChild.textContent = 'به‌روزرسانی خودکار · '; }
    };
    socket.onerror = () => socket.close();
  }
  async function loadPeriod(pane, background = false) {
    const card = pane.querySelector('[data-egm-period-games]');
    if (!card || !pane.dataset.taskTagCode) return;
    if (!monitors.has(pane)) monitors.set(pane, {selected:null,data:null,loading:false});
    const monitor = monitors.get(pane);
    if (monitor.loading) return;
    monitor.loading = true;
    monitor.attempted = Date.now();
    try {
      const data = await request('monitor_snapshot', {period_code:pane.dataset.taskTagCode});
      // Compatibility with older endpoint responses during a rolling update.
      await Promise.all((data.games || []).filter(game => !Array.isArray(game.teams) && (data.enabled || []).includes(game.id)).map(async game => {
        game.teams = (await request('list_teams', {period_code:pane.dataset.taskTagCode,game_id:game.id})).teams;
      }));
      if (!background) {
        const editor = card.querySelector('[data-team-score-form], [data-member-editor]');
        if (editor) editor.closest('[data-period-game-teams]').innerHTML = '';
        monitor.data = null;
      }
      acceptSnapshot(pane, data, monitor.live);
      startSocket(pane);
    } catch (error) { card.querySelector('[data-period-games-status]').textContent = error.message+' آخرین اطلاعات نمایش داده می‌شود؛ اتصال دوباره بررسی خواهد شد.'; }
    finally { monitor.loading = false; }
  }
  function showTeamMembers(pane, gameId, teamId) {
    const entry = periodTeams.get(pane)?.get(gameId);
    const team = entry?.teams.find(team => Number(team.id) === Number(teamId));
    if (!team || shell.dataset.egmCanManageGames !== '1') return;
    const {target,game} = entry;
    let members = [...team.members];
    const expected = members.map(member => Number(member.id));
    target.innerHTML = `<section data-member-editor><div class="egm-team-score-head"><h3>ویرایش اعضای ${escapeHtml(team.name)}</h3><button type="button" class="btn ghost" data-members-back>بازگشت به تیم‌ها</button></div><p class="muted">${scoreNumber(game.min_players || 1)} تا ${scoreNumber(game.max_players || 20)} عضو. اصلاح مدیر ثبت می‌شود؛ امتیازها و پیشرفت تیم حفظ می‌شوند.</p><div class="egm-monitor-members-list" data-members-list></div><form data-member-search class="egm-monitor-toolbar"><label class="field"><span>افزودن عضو حاضر در بازه</span><input name="query" minlength="2" maxlength="128" required placeholder="نام، کد ملی یا کد پرسنلی" autocomplete="off"></label><button type="submit" class="btn ghost">جستجو</button></form><div class="egm-monitor-search-results" data-member-results></div><button type="button" class="btn primary" data-members-save>ذخیره اعضا</button><p role="status" data-members-status></p></section>`;
    const editor = target.querySelector('[data-member-editor]');
    const status = editor.querySelector('[data-members-status]');
    const render = () => {
      editor.dataset.dirty = JSON.stringify(members.map(member => Number(member.id))) !== JSON.stringify(expected) ? '1' : '0';
      editor.querySelector('[data-members-list]').innerHTML = members.map(member => `<div class="egm-monitor-member"><span>${escapeHtml(memberName(member))}<small>${escapeHtml(member.work_id || '')}</small></span><button type="button" class="btn ghost" data-member-remove="${Number(member.id)}" aria-label="حذف ${escapeHtml(memberName(member))}">حذف</button></div>`).join('') || '<p class="muted">هیچ عضوی انتخاب نشده است.</p>';
    };
    render();
    editor.querySelector('[data-members-back]').onclick = () => { if (JSON.stringify(members.map(member => Number(member.id))) !== JSON.stringify(expected) && !confirm('تغییرات اعضا ذخیره نشده است. بازگردید؟')) return; void loadPeriod(pane); };
    editor.addEventListener('click', event => {
      const remove = event.target.closest('[data-member-remove]');
      if (remove) { members = members.filter(member => Number(member.id) !== Number(remove.dataset.memberRemove)); render(); }
    });
    editor.querySelector('[data-member-search]').onsubmit = async event => {
      event.preventDefault();
      const button = event.target.querySelector('button'); button.disabled = true; status.textContent = '';
      try {
        const result = await request('search_team_members', {period_code:pane.dataset.taskTagCode,game_id:gameId,query:event.target.elements.query.value});
        const candidates = result.members || [];
        editor.querySelector('[data-member-results]').innerHTML = candidates.map(member => {
          const occupied = member.already_in_team && !expected.includes(Number(member.id));
          return `<button type="button" class="btn ghost" data-member-add="${Number(member.id)}" ${occupied || members.some(item => Number(item.id) === Number(member.id)) ? 'disabled' : ''}>${escapeHtml(memberName(member))} · ${escapeHtml(member.work_id || '')}${occupied ? ' · عضو یک تیم' : ''}</button>`;
        }).join('') || '<p class="muted">عضو واجد شرایطی پیدا نشد.</p>';
        editor.querySelectorAll('[data-member-add]').forEach(button => button.onclick = () => {
          if (members.length >= Number(game.max_players || 20)) { status.textContent = 'حداکثر تعداد اعضای تیم انتخاب شده است.'; return; }
          const member = candidates.find(item => Number(item.id) === Number(button.dataset.memberAdd));
          if (!members.some(item => Number(item.id) === Number(member.id))) members.push(member);
          button.disabled = true; render();
        });
      } catch (error) { status.textContent = error.message; }
      finally { button.disabled = false; }
    };
    editor.querySelector('[data-members-save]').onclick = async event => {
      if (members.length < Number(game.min_players || 1)) { status.textContent = 'تعداد اعضا کمتر از حداقل بازی است.'; return; }
      const buttons = [...editor.querySelectorAll('button,input')]; const disabled = buttons.map(button => button.disabled);
      buttons.forEach(button => button.disabled = true);
      try {
        await request('update_team_members', {period_code:pane.dataset.taskTagCode,game_id:gameId,team_id:teamId,member_ids:JSON.stringify(members.map(member => Number(member.id))),expected_members:JSON.stringify(expected)});
        await loadPeriod(pane); pane.querySelector('[data-period-games-status]').textContent = 'اعضای تیم ذخیره شد.';
      } catch (error) { status.textContent = error.message; buttons.forEach((button,index) => button.disabled = disabled[index]); }
    };
  }
  const monitorTimer = window.setInterval(() => {
    tickClocks();
    for (const [pane, monitor] of monitors) {
      if (!shell.isConnected || !pane.isConnected) { monitor.socket?.close(); monitors.delete(pane); continue; }
      const visible = !document.hidden && !pane.closest('[hidden]') && getComputedStyle(pane).display !== 'none' && (!pane.querySelector('[data-task-top-section="games"]') || !pane.querySelector('[data-task-top-section="games"]').hidden);
      if (!visible) { monitor.socket?.close(); continue; }
      if (monitor.socket && Date.now()-(monitor.lastMessage || monitor.socketStarted || 0) > 20000) monitor.socket.close();
      if (!monitor.live && Date.now()-(monitor.attempted || monitor.updated || 0) > 5000) void loadPeriod(pane,true);
      if (!monitor.live) startSocket(pane);
    }
    if (!shell.isConnected) window.clearInterval(monitorTimer);
  }, 1000);
  window.addEventListener('pagehide', () => { window.clearInterval(monitorTimer); for (const monitor of monitors.values()) monitor.socket?.close(); }, {once:true});
  shell.addEventListener('input', event => {
    if (!event.target.matches('[data-monitor-search]')) return;
    const pane = event.target.closest('.sub-pane[data-task-pane="1"]');
    monitors.get(pane).search = event.target.value; renderRankings(pane);
  });
  shell.addEventListener('change', event => {
    if (!event.target.matches('[data-monitor-filter]')) return;
    const pane = event.target.closest('.sub-pane[data-task-pane="1"]');
    monitors.get(pane).filter = event.target.value; renderRankings(pane);
  });
  shell.addEventListener('click', async event => {
    const button = event.target.closest('[data-monitor-game],[data-monitor-refresh],[data-monitor-toggle],[data-team-members-open]');
    if (!button) return;
    const pane = button.closest('.sub-pane[data-task-pane="1"]');
    if (!pane) return;
    const monitor = monitors.get(pane);
    if (button.hasAttribute('data-team-members-open')) { showTeamMembers(pane,button.dataset.scoreGame,Number(button.dataset.teamMembersOpen)); return; }
    if (button.hasAttribute('data-monitor-game') || button.hasAttribute('data-monitor-refresh')) {
      const dirty = pane.querySelector('[data-member-editor][data-dirty="1"]') || [...pane.querySelectorAll('[data-round-score]')].some(input => Number(input.value) !== Number(input.dataset.originalScore));
      if (dirty && !confirm('تغییرات ذخیره نشده است. ادامه می‌دهید؟')) return;
      if (button.hasAttribute('data-monitor-game')) {
        const editor = pane.querySelector('[data-team-score-form], [data-member-editor]');
        if (editor) editor.closest('[data-period-game-teams]').innerHTML = '';
        monitor.selected = button.dataset.monitorGame; renderMonitor(pane);
      } else void loadPeriod(pane);
      return;
    }
    if (button.hasAttribute('data-monitor-toggle')) {
      const enabled = button.dataset.enabled === '1';
      if (!enabled && !confirm('این بازی برای این بازه غیرفعال شود؟')) return;
      button.disabled = true;
      try { await request('set_enabled',{period_code:pane.dataset.taskTagCode,id:button.dataset.monitorToggle,enabled}); await loadPeriod(pane); }
      catch (error) { pane.querySelector('[data-period-games-status]').textContent = error.message; button.disabled = false; }
    }
  });
  shell.addEventListener('click', event => {
    const details = event.target.closest('[data-team-score-open]');
    if (details) { const pane = details.closest('.sub-pane[data-task-pane="1"]'); if (pane) showTeamScores(pane, details.dataset.scoreGame, Number(details.dataset.teamScoreOpen)); return; }
    const tab = event.target.closest('[data-task-top-trigger="games"]');
    if (tab) { const pane = tab.closest('.sub-pane[data-task-pane="1"]'); if (pane) void loadPeriod(pane,true); }
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
