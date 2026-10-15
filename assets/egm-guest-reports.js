(() => {
  const root = document.querySelector('[data-guest-reports]');
  if (!root) return;
  const list = root.querySelector('[data-report-list]'), status = root.querySelector('[data-report-status]');
  const period = root.querySelector('[data-report-period]'), state = root.querySelector('[data-report-state]'), search = root.querySelector('[data-report-search]');
  const activeOption = new Option('بازهٔ فعال', 'active', true, true);
  period.prepend(activeOption);
  period.value = 'active';
  if (![...state.options].some(option=>option.value==='information')) state.add(new Option('صرفاً اطلاع‌رسانی','information'));
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const fa = number => Number(number).toLocaleString('fa-IR');
  let page = 1, pages = 1, revision = 0, timer, busy = false;
  let rendered = '';
  const drafts = new Map();
  const request = async payload => {
    const url = new URL(root.dataset.endpoint, location.href);
    const post = payload.action !== 'list';
    if (!post) for (const [key,value] of Object.entries(payload)) url.searchParams.set(key,value);
    url.searchParams.set('_', `${Date.now()}-${Math.random().toString(36).slice(2)}`);
    const response = await fetch(url, {credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json', ...(post ? {'Content-Type':'application/json'} : {})}, ...(post ? {method:'POST', body:JSON.stringify({...payload, csrf:root.closest('.egm-shell')?.dataset.egmCsrf || document.querySelector('.egm-shell')?.dataset.egmCsrf || ''})} : {})});
    let data;try {data = await response.json();} catch {throw new Error('پاسخ سرور معتبر نیست؛ صفحه را تازه‌سازی کنید.');}
    if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'عملیات انجام نشد.');
    return data;
  };
  const load = async (silent = false) => {
    if (busy || (silent && drafts.size)) return;
    clearTimeout(timer);
    const version = ++revision;
    if (!silent) status.textContent = 'در حال دریافت…';
    try {
      const data = await request({action:'list',period:period.value,state:state.value,q:search.value.trim(),page});
      if (version !== revision) return;
      page = data.page;pages = data.pages;
      for (const item of data.periods) {
        if (![...period.options].some(option => option.value === item.code)) period.add(new Option(item.title || item.code,item.code));
      }
      const activePeriod = data.periods.find(item => item.code === data.active_period_code);
      activeOption.textContent = activePeriod ? `بازهٔ فعال: ${activePeriod.title || activePeriod.code}` : 'بازهٔ فعالی وجود ندارد';
      const signature = JSON.stringify(data.reports);
      if (signature !== rendered) {
      list.innerHTML = data.reports.length ? data.reports.map(report => {
        const guest = report.guest, resolved = !!report.decision, actionable = report.actionable !== false;
        const summary = report.summary || {reason:'نیاز به بررسی', effect:'نیاز به تصمیم مدیر', tone:'warning'};
        const identity = `<dl class="egm-report-identity">${[['کد پرسنلی',guest.work_id],['کد ملی',guest.national_id],['شماره همراه',guest.phone_number]].map(([label,value]) => `<div><dt>${label}</dt><dd><bdi dir="ltr">${esc(String(value ?? '').trim() || '—')}</bdi></dd></div>`).join('')}</dl>`;
        const headline = `<div class="egm-report-summary ${summary.tone === 'danger' ? 'is-danger' : 'is-warning'}"><strong>${esc(summary.reason)}</strong><span>${esc(summary.effect)}</span></div>`;
        return `<article class="card egm-guest-report" data-report-id="${esc(report.id)}"><div class="section-header"><h3>${esc(guest.full_name || guest.national_id || guest.work_id || 'مهمان نامشخص')}</h3><span>${!actionable ? 'صرفاً اطلاع‌رسانی' : resolved ? '✅ حل شده' : 'نیاز به اقدام'}</span></div>${identity}<p class="egm-report-input-method"><strong>روش ورود کد:</strong> ${report.input_methods && Object.keys(report.input_methods).length>1 ? Object.entries(report.input_methods).map(([method,count])=>`${esc(method)}: ${fa(count)}`).join(' · ') : esc(guest.input_method_label || 'نامشخص (سابقهٔ قدیمی)')}</p>${headline}${Number(report.repeat_count)>1 ? `<p class="egm-report-repeat">${fa(report.repeat_count)} بار تکرار · آخرین بار: ${esc(report.last_attempt_at)}</p>` : ''}<p class="egm-report-details">${esc(guest.message)}</p><p class="muted">${esc(guest.period_title || 'بدون بازه')} · ${esc(guest.attempted_at || report.created_at)}</p>${!actionable ? `<p class="egm-report-information">${esc(report.information_label || 'این گزارش نیاز به اقدام ندارد.')}</p>` : resolved ? `<p>${esc(report.decision_label)}</p><button type="button" class="btn ghost" data-report-reopen>برگرداندن به بررسی</button>` : `<form data-report-resolve><div class="egm-switch-grid">${[['games','شرکت در بازی'],['draw','شرکت در قرعه‌کشی'],['gift','دریافت هدیه']].map(([name,label])=>`<label class="switch egm-switch"><span class="switch-label">${label}</span><span class="switch-toggle"><input name="${name}" type="checkbox" ${name==='games'||(name==='gift'&&guest.should_get_gift)||(name==='draw'&&guest.draw_eligible) ? 'checked' : ''}><span class="switch-track"><span class="switch-thumb"></span></span></span></label>`).join('')}</div><div class="egm-period-actions"><button type="submit" class="btn primary">تأیید و حل گزارش</button><button type="button" class="btn ghost" data-report-deny>رد همه</button></div></form>`}</article>`;
      }).join('') : `<p class="muted">${period.value === 'active' && !data.active_period_code ? 'بازهٔ فعالی وجود ندارد؛ برای مشاهدهٔ گزارش‌ها بازه را انتخاب کنید.' : 'گزارشی وجود ندارد.'}</p>`;
      rendered = signature;
      for (const article of list.querySelectorAll('[data-report-id]')) {
        const draft = drafts.get(article.dataset.reportId);
        if (draft) for (const input of article.querySelectorAll('input[type=checkbox]')) input.checked = !!draft[input.name];
      }
      }
      root.querySelector('[data-report-page]').textContent = `${fa(page)} از ${fa(pages)} · ${fa(data.total)} گزارش`;
      root.querySelector('[data-report-prev]').disabled = page <= 1;
      root.querySelector('[data-report-next]').disabled = page >= pages;
      status.textContent = '';
      if (data.more_logs) timer = setTimeout(()=>void load(true),1000);
    } catch (error) {if (version === revision) status.textContent = error.message;}
  };
  const decide = async (article, choice) => {
    if (busy) return;
    busy = true;
    revision++;
    clearTimeout(timer);
    article.querySelectorAll('button,input').forEach(button => {button.disabled = true;});
    try {const data = await request({action:choice === 'reopen' ? 'reopen' : 'resolve',id:article.dataset.reportId,choice});drafts.delete(article.dataset.reportId);busy=false;await load();status.textContent=data.message;}
    catch(error) {status.textContent=error.message;article.querySelectorAll('button,input').forEach(button=>{button.disabled=false;});}
    finally {busy=false;}
  };
  root.addEventListener('change',event=>{
    if (!event.target.matches('[data-report-resolve] input[type=checkbox]')) return;
    const article=event.target.closest('[data-report-id]');
    drafts.set(article.dataset.reportId,Object.fromEntries([...article.querySelectorAll('input[type=checkbox]')].map(input=>[input.name,input.checked])));
    clearTimeout(timer);
  });
  root.addEventListener('submit', event => {
    if (!event.target.matches('[data-report-resolve]')) return;
    event.preventDefault();const form=event.target;
    const mask=(form.elements.games.checked?4:0)+(form.elements.draw.checked?2:0)+(form.elements.gift.checked?1:0);
    void decide(form.closest('[data-report-id]'),['deny','gift','draw','benefits','games','games_gift','games_draw','all'][mask]);
  });
  root.addEventListener('click',event=>{
    const button=event.target.closest('[data-report-reopen],[data-report-deny]');
    if (button) void decide(button.closest('[data-report-id]'),button.hasAttribute('data-report-reopen')?'reopen':'deny');
  });
  root.querySelector('[data-report-refresh]').onclick=()=>void load();
  root.querySelector('[data-report-prev]').onclick=()=>{page--;void load();};
  root.querySelector('[data-report-next]').onclick=()=>{page++;void load();};
  for (const field of [period,state]) field.onchange=()=>{page=1;void load();};
  search.oninput=()=>{revision++;clearTimeout(timer);page=1;timer=setTimeout(load,250);};
  // Fetch on entry to this tab, so hidden panels do not load the entire history.
  document.addEventListener('click',event=>{if(event.target.closest('button[data-pane="egm-guest-reports"],a[data-pane="egm-guest-reports"]'))void load();});
})();
