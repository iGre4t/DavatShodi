(() => {
 const root = document.querySelector('[data-windows-releases]');
 if (!root || root.dataset.bound) return;
 root.dataset.bound = '1';
 root.querySelector('form').addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget, button = form.querySelector('button'), status = root.querySelector('[data-release-status]');
  button.disabled = true; status.textContent = 'در حال آپلود و انتشار…';
  try {
   const response = await fetch(`api/windows-release.php?t=${Date.now()}`, {method:'POST', body:new FormData(form), credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}});
   const text = await response.text(); let data;
   try { data = JSON.parse(text); } catch { throw new Error('پاسخ سرور معتبر نیست؛ حجم فایل و محدودیت آپلود سرور را بررسی کنید.'); }
   if (data.status !== 'ok') throw new Error(data.message || 'انتشار انجام نشد.');
   root.querySelector('[data-release-version]').textContent = data.release.version;
   const link = root.querySelector('[data-release-download]'); link.href = data.release.url; link.hidden = false;
   form.querySelector('[name=build]').value = '';
   status.textContent = 'نسخه منتشر شد؛ کاربران در زنگ اعلان برنامه، به‌روزرسانی را می‌بینند.';
  } catch (error) { status.textContent = error.message; }
  finally { button.disabled = false; }
 });
})();
