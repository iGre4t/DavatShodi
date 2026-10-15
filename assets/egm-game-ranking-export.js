(() => {
  const escape = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-game-ranking-export]');
    if (!button || button.disabled) return;
    const url = new URL(button.dataset.gameRankingExport, location.href);
    url.searchParams.set('type', 'game_rankings');
    const dialog = document.createElement('dialog');
    dialog.dir = 'rtl'; dialog.className = 'card egm-ranking-dialog';
    dialog.setAttribute('aria-label', 'خروجی رتبه‌بندی بازی‌ها');
    dialog.style.cssText = 'width:min(520px,calc(100vw - 32px));max-height:90dvh;overflow:auto;border:1px solid #e1e6ef;border-radius:24px;padding:24px;color:inherit';
    dialog.innerHTML = '<div class="section-header"><h3>خروجی رتبه‌بندی بازی‌ها</h3><button type="button" class="btn ghost" data-ranking-close aria-label="بستن">×</button></div><p role="status">در حال دریافت بازی‌ها…</p>';
    document.body.append(dialog); dialog.showModal();
    dialog.addEventListener('close', () => dialog.remove());
    dialog.addEventListener('click', e => { if (e.target.closest('[data-ranking-close]')) dialog.close(); });
    try {
      const optionsUrl = new URL(url); optionsUrl.searchParams.set('options', '1');
      const response = await fetch(optionsUrl, {credentials:'same-origin'});
      if (!response.ok) throw new Error('دریافت بازی‌ها انجام نشد. دوباره تلاش کنید.');
      let data;
      try { data = await response.json(); }
      catch { throw new Error('پاسخ بازی‌ها معتبر نیست. دوباره وارد پنل شوید.'); }
      if (!dialog.isConnected) return;
      if (!data.games?.length) throw new Error('بازی امتیازی در این بازه فعال نیست.');
      dialog.querySelector('[role=status]').remove();
      dialog.insertAdjacentHTML('beforeend', `<form class="form" data-ranking-form>
        <section data-ranking-step="1"><label class="field"><span>بازی</span><select name="game_id"><option value="">همه بازی‌ها</option>${data.games.map(game=>`<option value="${escape(game.id)}">${escape(game.name)}</option>`).join('')}</select></label>
        <label class="field"><span>از رتبه اول تا</span><input name="rank_to" type="number" min="1" max="1000" value="3" required></label>
        <p class="hint">در بازی‌های تفکیک‌شده، رتبه‌ها برای آقایان و بانوان جداست. امتیازهای برابر رتبه یکسان دارند.</p>
        <button type="button" class="btn primary" data-ranking-next>ادامه</button></section>
        <section data-ranking-step="2" hidden><label class="field"><span>نوع خروجی</span><select name="format"><option value="full">کامل — اکسل اعضا و امتیازها</option><option value="presenter">مجری — فایل ورد اسامی برندگان</option></select></label>
        <div style="display:flex;gap:12px;margin-top:20px"><button type="submit" class="btn primary">دریافت فایل</button><button type="button" class="btn ghost" data-ranking-back>برگشت</button></div></section>
        <p role="status" aria-live="polite"></p></form>`);
      const form = dialog.querySelector('form');
      const step = value => { form.querySelectorAll('[data-ranking-step]').forEach(section => { section.hidden = section.dataset.rankingStep !== value; }); };
      form.querySelector('[data-ranking-next]').onclick = () => { if (form.elements.rank_to.reportValidity()) step('2'); };
      form.querySelector('[data-ranking-back]').onclick = () => step('1');
      form.addEventListener('submit', async e => {
        e.preventDefault(); const submit = form.querySelector('[type=submit]'); const status = form.querySelector('[role=status]');
        submit.disabled = true; status.textContent = 'در حال ساخت فایل…';
        try {
          const download = new URL(url);
          for (const [key,value] of new FormData(form)) download.searchParams.set(key,value);
          const response = await fetch(download, {credentials:'same-origin'});
          if (!response.ok) { const text = (await response.text()).replace(/<[^>]*>/g,' ').trim(); throw new Error(text || 'ساخت فایل انجام نشد.'); }
          const type = response.headers.get('content-type') || '';
          if (!type.includes('openxmlformats')) throw new Error('پاسخ خروجی معتبر نیست. دوباره وارد پنل شوید.');
          const blob = await response.blob(); const objectUrl = URL.createObjectURL(blob); const link = document.createElement('a');
          const disposition = response.headers.get('content-disposition') || '';
          const encoded = disposition.match(/filename\*=UTF-8''([^;]+)/i);
          link.href = objectUrl; link.download = encoded ? decodeURIComponent(encoded[1]) : ('رتبه‌بندی بازی‌ها.'+(form.elements.format.value==='full'?'xlsx':'docx'));
          document.body.append(link); link.click(); link.remove(); setTimeout(()=>URL.revokeObjectURL(objectUrl),10000);
          status.textContent = 'فایل آماده شد.';
        } catch (error) { status.textContent = error.message; }
        finally { submit.disabled = false; }
      });
    } catch (error) { if (dialog.isConnected) dialog.querySelector('[role=status]').textContent = error.message; }
  });
})();
