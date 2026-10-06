(() => {
  'use strict';
  const app = document.querySelector('[data-check-in-app]');
  const tools = app?.egmScanTools;
  if (!tools) return;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const digits = value => String(value ?? '').replace(/[۰-۹]/g, c => String(c.charCodeAt(0)-1776)).replace(/[٠-٩]/g, c => String(c.charCodeAt(0)-1632));
  const fa = value => digits(value).replace(/\d/g, c => '۰۱۲۳۴۵۶۷۸۹'[c]);
  const result = app.querySelector('[data-result]');
  const report = (message, error = false) => {result.className = `result ${error ? 'error' : 'success'}`;result.textContent = message;};
  const get = async (action, params = {}) => {
    const url = new URL(location.href);url.searchParams.delete('period');
    url.searchParams.set('action', action);
    for (const [key, value] of Object.entries(params)) url.searchParams.set(key, value);
    const response = await fetch(url, {credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}});
    const data = await response.json().catch(() => null);
    if (!response.ok || data?.status !== 'ok') throw new Error(data?.message || 'دریافت اطلاعات ناموفق بود. صفحه را تازه‌سازی کنید.');
    return data;
  };
  const modal = (title, content) => {
    const dialog = document.createElement('dialog');dialog.className = 'egm-scan-dialog';
    dialog.innerHTML = `<header><h2>${esc(title)}</h2><button type="button" data-close aria-label="بستن">×</button></header>${content}<p class="egm-tool-message" role="status"></p>`;
    document.body.append(dialog);
    dialog.querySelector('[data-close]').onclick = () => dialog.close();
    dialog.addEventListener('close', () => dialog.remove(), {once:true});
    dialog.showModal();
    return dialog;
  };
  const message = (dialog, text) => {dialog.querySelector('.egm-tool-message').textContent = text;};
  const overview = app.querySelector('[data-attendance-stats]');
  const scanSections = [...app.querySelectorAll(':scope > .guest-card')];
  const nav = document.createElement('nav');nav.className = 'egm-scan-tabs';nav.setAttribute('aria-label', 'بخش کنترل مهمان');
  nav.innerHTML = '<button type="button" aria-pressed="true" data-page="scan">اسکن و ورود</button><button type="button" aria-pressed="false" data-page="data">آمار و داده‌ها</button>';
  app.prepend(nav);
  const dataSection = document.createElement('section');dataSection.className = 'guest-card egm-data-page';dataSection.hidden = true;
  dataSection.innerHTML = '<h2>آمار بازه فعال</h2><div data-extra-stats class="egm-data-grid"></div>';
  if (overview) dataSection.insertBefore(overview, dataSection.lastElementChild);
  app.append(dataSection);
  nav.addEventListener('click', event => {
    const button = event.target.closest('[data-page]');if (!button) return;
    const data = button.dataset.page === 'data';
    scanSections.forEach(section => {section.hidden = data;});dataSection.hidden = !data;
    nav.querySelectorAll('button').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
    if (!data) app.querySelector('[data-national-id]')?.focus();
  });
  const renderData = stats => {
    const target = dataSection.querySelector('[data-extra-stats]');
    app.querySelectorAll('[data-active-tool]').forEach(button => {button.disabled = !stats?.active || app.dataset.canRegisterUninvited !== '1';});
    if (!stats?.active) {target.innerHTML = '<p>بازه فعالی وجود ندارد.</p>';return;}
    target.innerHTML = [ ['بلیت‌های مهمانان', stats.ticket_totals], ['بلیت‌های دستی', stats.manual_ticket_totals], ['ورود گروه‌ها', stats.groups] ].map(([title, rows]) => `<article><h3>${title}</h3>${rows?.length ? rows.map(row => `<div class="egm-data-row"><span>${esc(row.title)}</span><strong>${esc(row.sum !== undefined ? fa(row.sum) : `${fa(row.entered)} از ${fa(row.total)}`)}</strong></div>`).join('') : '<p class="muted">موردی ثبت نشده است.</p>'}</article>`).join('');
    app.querySelectorAll('[data-active-tool]').forEach(button => {button.disabled = app.dataset.canRegisterUninvited !== '1';});
  };
  app.addEventListener('egm-scan-stats', event => renderData(event.detail));
  const toolbar = document.createElement('div');toolbar.className = 'egm-scan-tools';
  toolbar.innerHTML = '<button type="button" data-active-tool data-pending>دعوت‌شدگان در انتظار ورود</button><button type="button" data-active-tool data-manual>چاپ بلیت دستی</button>';
  scanSections[0]?.append(toolbar);
  renderData(app.egmStats);
  toolbar.querySelector('[data-pending]').onclick = () => {
    const dialog = modal('دعوت‌شدگان در انتظار ورود', '<input type="search" data-search placeholder="نام، کد ملی یا کد پرسنلی" aria-label="جست‌وجوی دعوت‌شده" autocomplete="off"><div data-list class="egm-pending-list"></div><footer><button type="button" data-prev>قبلی</button><span data-count></span><button type="button" data-next>بعدی</button></footer>');
    let page = 1, revision = 0, timer, busy = false;
    const load = async () => {
      const version = ++revision;message(dialog, 'در حال دریافت…');
      try {
        const data = await get('pending_invitees', {q:digits(dialog.querySelector('[data-search]').value), page});
        if (version !== revision || !dialog.isConnected) return;
        dialog.querySelector('[data-list]').innerHTML = data.invitees.length ? data.invitees.map(row => `<article><div><strong>${esc(row.name)}</strong><small>${esc(fa(row.guest_code))}${row.department ? ` · ${esc(row.department)}` : ''}</small></div><button type="button" data-enter="${esc(row.guest_code)}">ثبت ورود</button></article>`).join('') : '<p>دعوت‌شده‌ای در انتظار ورود نیست.</p>';
        dialog.querySelector('[data-count]').textContent = `${fa(data.total)} نفر`;
        dialog.querySelector('[data-prev]').disabled = page <= 1;
        dialog.querySelector('[data-next]').disabled = page * data.page_size >= data.total;
        message(dialog, '');
      } catch (error) {if (version === revision) message(dialog, error.message);}
    };
    dialog.querySelector('[data-search]').oninput = () => {revision++;page = 1;clearTimeout(timer);timer = setTimeout(load, 250);};
    dialog.querySelector('[data-prev]').onclick = () => {page--;void load();};
    dialog.querySelector('[data-next]').onclick = () => {page++;void load();};
    dialog.querySelector('[data-list]').onclick = async event => {
      const button = event.target.closest('[data-enter]');if (!button || busy) return;
      busy = true;button.disabled = true;
      try {const data = await tools.enter(button.dataset.enter);message(dialog, data?.message || 'ورود لغو شد.');await load();}
      catch (error) {message(dialog, error.message);button.disabled = false;}
      finally {busy = false;}
    };
    dialog.addEventListener('close', () => {revision++;clearTimeout(timer);}, {once:true});
    void load();dialog.querySelector('input').focus();
  };
  tools.choosePrint = async row => {
    if (!row) return;
    try {
      const data = await get('guest_print_profile', {guest_code:row.national_id || row.work_id, period_code:row.period_code});
      const profile = data.print_profile;
      row = {...row, ...data.print_guest};
      const options = [];
      if (!profile.ticket_only && profile.configured) options.push({title:'کارت مهمان', profile});
      if (profile.ticket_active) for (const ticket of profile.tickets || []) if (ticket.configured) options.push({title:ticket.title, ticket, profile:{configured:true, card:ticket.card}});
      if (!options.length) throw new Error('طرحی برای چاپ این مهمان آماده نیست.');
      const dialog = modal(`چاپ برای ${row.full_name || 'مهمان'}`, `<div class="egm-print-options">${options.map((option, index) => `<button type="button" data-output="${index}">${esc(option.title)}</button>`).join('')}</div>`);
      let busy = false;
      dialog.onclick = async event => {
        const button = event.target.closest('[data-output]');if (!button || busy) return;
        busy = true;button.disabled = true;
        try {
          const option = options[Number(button.dataset.output)];
          if (option.ticket) {
            const saved = row.ticket_numbers?.[option.ticket.id] || (option.ticket.id === 'default' ? row.number_of_ticket : '');
            const number = saved || await tools.requestTicketNumber(row, option.title);
            if (number === null) return;
            if (!saved) {await tools.recordTicketNumber(row, option.ticket, number);row.ticket_numbers = {...row.ticket_numbers, [option.ticket.id]:number};await tools.refresh();}
            row = {...row, number_of_ticket:number, ticket_title:option.title};
          }
          await tools.printCardForRow(row, option.profile, 1);message(dialog, 'پنجره چاپ باز شد.');
        } catch (error) {message(dialog, error.message);}
        finally {busy = false;button.disabled = false;}
      };
    } catch (error) {report(error.message, true);}
  };
  app.addEventListener('click', async event => {
    const reportButton = event.target.closest('[data-report-log]');
    if (reportButton) {
      const row = tools.row(Number(reportButton.dataset.reportLog));
      if (!row?.log_id) return;
      reportButton.disabled = true;
      try {const data = await tools.post({action:'report_to_management', log_id:row.log_id});report(data.message);}
      catch (error) {report(error.message, true);}
      finally {reportButton.disabled = false;}
      return;
    }
    const button = event.target.closest('[data-reset-log]');if (!button) return;
    const row = tools.row(Number(button.dataset.resetLog));
    if (!row || !confirm(`ورود ${row.full_name || 'این مهمان'} بازنشانی شود؟ صندلی و بلیت‌های ثبت‌شده آزاد می‌شوند.`)) return;
    button.disabled = true;
    try {const data = await tools.post({action:'reset_guest_entry', guest_code:row.national_id || row.work_id, period_code:row.period_code});tools.accept(data);report(data.message || 'ورود بازنشانی شد.');}
    catch (error) {report(error.message, true);button.disabled = false;}
  });
  toolbar.querySelector('[data-manual]').onclick = async () => {
    try {
      const {print_profile:profile} = await get('manual_print_profile');
      const tickets = (profile.tickets || []).filter(ticket => ticket.configured);
      if (!tickets.length) throw new Error('ابتدا طرح بلیت شماره‌دار را تنظیم کنید.');
      const dialog = modal('چاپ بلیت دستی', `<form><label>نوع بلیت<select name="ticket">${tickets.map(ticket => `<option value="${esc(ticket.id)}">${esc(ticket.title)}</option>`).join('')}</select></label><label>تعداد بلیت<input name="quantity" inputmode="numeric" maxlength="32" required autocomplete="off"></label><label>صندلی<select name="seat"><option value="none">بدون رزرو صندلی</option><option value="assigned">رزرو صندلی</option></select></label><button type="submit" class="egm-tool-primary">ثبت و چاپ</button></form>`);
      if (app.dataset.seatMapEnabled !== '1') {
        dialog.querySelector('[name=seat] option[value=assigned]').remove();
        dialog.querySelector('[name=seat]').closest('label').hidden = true;
      }
      let busy = false, saved = null;
      const bytes = new Uint8Array(16);crypto.getRandomValues(bytes);
      const token = Array.from(bytes, byte => byte.toString(16).padStart(2,'0')).join('');
      dialog.querySelector('form').onsubmit = async event => {
        event.preventDefault();if (busy) return;
        const form = event.target, quantity = digits(form.elements.quantity.value);
        if (!/^\d{1,32}$/.test(quantity)) {message(dialog, 'تعداد معتبر وارد کنید.');return;}
        busy = true;const submit = form.querySelector('[type=submit]');submit.disabled = true;
        try {
          const ticket = tickets.find(item => item.id === form.elements.ticket.value);
          if (!saved) {
            const data = await tools.post({action:'record_manual_ticket', ticket_id:ticket.id, quantity, seat_mode:form.elements.seat.value, client_token:token});
            saved = {ticket, quantity, seat_assignment:data.seat_assignment};tools.accept(data);
            [...form.querySelectorAll('input,select')].forEach(field => {field.disabled = true;});
          }
          await tools.printCardForRow({first_name:'مهمان', national_id:'000000000', number_of_ticket:saved.quantity, ticket_title:saved.ticket.title, seat_assignment:saved.seat_assignment}, {configured:true, card:saved.ticket.card}, 1);
          message(dialog, 'بلیت ثبت شد و پنجره چاپ باز شد.');submit.textContent = 'چاپ مجدد';
        } catch (error) {message(dialog, `${saved ? 'بلیت ثبت شده است. ' : ''}${error.message}`);}
        finally {busy = false;submit.disabled = false;}
      };
      dialog.querySelector('input').focus();
    } catch (error) {report(error.message, true);}
  };
})();
