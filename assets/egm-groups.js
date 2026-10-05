(() => {
  const panes = Array.from(document.querySelectorAll('[data-egm-groups-pane]'));
  if (!panes.length) return;
  const csrf = document.querySelector('.egm-shell')?.dataset.egmCsrf || '';
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));

  panes.forEach(pane => {
    const endpoint = pane.dataset.endpoint || '';
    const list = pane.querySelector('[data-egm-groups-list]');
    const status = pane.querySelector('[data-egm-groups-status]');
    const modal = pane.querySelector('[data-egm-groups-modal]');
    const settingsForm = pane.querySelector('[data-egm-group-settings]');
    let groups = [];
    let tickets = [];
    const setStatus = (message, error = false) => { if (status) { status.textContent = message || ''; status.style.color = error ? '#b91c1c' : ''; } };
    const request = async (action = 'list', payload = null) => {
      const options = {credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}};
      let url = endpoint;
      if (payload) { options.method = 'POST'; options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify({action, csrf, ...payload}); }
      else url += `${endpoint.includes('?') ? '&' : '?'}action=${encodeURIComponent(action)}&_=${Date.now()}`;
      const response = await fetch(url, options);
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'دریافت اطلاعات گروه‌ها ناموفق بود.');
      return data;
    };
    const labels = group => {
      const ticketMap = new Map(tickets.map(ticket => [`ticket:${ticket.id}`, ticket.title]));
      return (group.outputs || []).map(output => output === 'print_card' ? 'کارت اصلی' : ticketMap.get(output)).filter(Boolean);
    };
    const render = () => {
      if (!list) return;
      if (!groups.length) { list.innerHTML = '<tr><td colspan="3" class="muted">هنوز گروهی ساخته نشده است.</td></tr>'; return; }
      list.innerHTML = groups.map(group => {
        const outputLabels = labels(group);
        return `<tr><td>${esc(group.title)}</td><td><div class="egm-group-output-summary">${outputLabels.length ? outputLabels.map(label => `<span>${esc(label)}</span>`).join('') : '<span>بدون چاپ</span>'}</div></td><td><button type="button" class="btn ghost" data-egm-group-edit="${esc(group.id)}">تنظیم کارت‌ها و بلیت‌ها</button></td></tr>`;
      }).join('');
    };
    const load = async () => {
      try { setStatus('در حال بازخوانی گروه‌ها…'); const data = await request(); groups = Array.isArray(data.groups) ? data.groups : []; tickets = Array.isArray(data.tickets) ? data.tickets : []; render(); setStatus(''); window.dispatchEvent(new CustomEvent('egm-groups-updated', {detail:{groups, tickets}})); }
      catch (error) { setStatus(error instanceof Error ? error.message : 'خطا در دریافت گروه‌ها.', true); }
    };
    const open = group => {
      if (!modal || !settingsForm) return;
      settingsForm.elements.id.value = group.id;
      settingsForm.elements.title.value = group.title;
      const outputs = new Set(group.outputs || []);
      const outputBox = pane.querySelector('[data-egm-group-outputs]');
      outputBox.innerHTML = `<label class="egm-groups-output-option"><input type="checkbox" value="print_card" ${outputs.has('print_card') ? 'checked' : ''}><span>کارت اصلی</span></label>` + tickets.map(ticket => `<label class="egm-groups-output-option"><input type="checkbox" value="ticket:${esc(ticket.id)}" ${outputs.has(`ticket:${ticket.id}`) ? 'checked' : ''}><span>${esc(ticket.title)}</span></label>`).join('');
      modal.hidden = false;
      settingsForm.elements.title.focus();
    };
    pane.querySelector('[data-egm-group-create]')?.addEventListener('submit', async event => {
      event.preventDefault(); const form = event.currentTarget; const title = String(new FormData(form).get('title') || '').trim(); if (!title) return;
      try { const data = await request('save', {title, outputs:[]}); groups = data.groups || groups; form.reset(); render(); setStatus(data.message || 'گروه افزوده شد.'); window.dispatchEvent(new CustomEvent('egm-groups-updated', {detail:{groups, tickets}})); }
      catch (error) { setStatus(error instanceof Error ? error.message : 'ذخیره گروه ناموفق بود.', true); }
    });
    list?.addEventListener('click', event => { const button = event.target.closest('[data-egm-group-edit]'); if (!button) return; const group = groups.find(item => item.id === button.dataset.egmGroupEdit); if (group) open(group); });
    settingsForm?.addEventListener('submit', async event => {
      event.preventDefault(); const outputs = Array.from(pane.querySelectorAll('[data-egm-group-outputs] input:checked')).map(input => input.value);
      try { const data = await request('save', {id:settingsForm.elements.id.value, title:settingsForm.elements.title.value, outputs}); groups = data.groups || groups; modal.hidden = true; render(); setStatus(data.message || 'تنظیمات ذخیره شد.'); window.dispatchEvent(new CustomEvent('egm-groups-updated', {detail:{groups, tickets}})); }
      catch (error) { setStatus(error instanceof Error ? error.message : 'ذخیره تنظیمات ناموفق بود.', true); }
    });
    pane.querySelector('[data-egm-group-delete]')?.addEventListener('click', async () => {
      const id = settingsForm?.elements.id.value || ''; if (!id || !confirm('این گروه حذف شود؟ عضویت مهمانان این گروه نیز پاک خواهد شد.')) return;
      try { const data = await request('delete', {id}); groups = data.groups || []; modal.hidden = true; render(); setStatus(data.message || 'گروه حذف شد.'); window.dispatchEvent(new CustomEvent('egm-groups-updated', {detail:{groups, tickets}})); }
      catch (error) { setStatus(error instanceof Error ? error.message : 'حذف گروه ناموفق بود.', true); }
    });
    pane.querySelector('[data-egm-group-close]')?.addEventListener('click', () => { modal.hidden = true; });
    pane.querySelector('[data-egm-groups-refresh]')?.addEventListener('click', load);
    modal?.addEventListener('click', event => { if (event.target === modal) modal.hidden = true; });
    void load();
  });
})();
