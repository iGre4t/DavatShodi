(() => {
  const labels={first_name:'نام',last_name:'نام خانوادگی',national_id:'کد ملی',work_id:'کد پرسنلی',phone_number:'شماره همراه',deputy:'معاونت',general_department:'اداره کل',department:'اداره',gender:'جنسیت',postal_level:'سطح سازمانی'};
  const escape=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  window.EGMOeuImportReview = (reviews, apply) => new Promise(resolve => {
    const byId=new Map(), conflicts=new Set();
    for(const review of reviews) for(const row of review.updates||[]) {
      const previous=byId.get(row.id);
      if(previous && JSON.stringify(previous.changes)!==JSON.stringify(row.changes)) conflicts.add(row.id);
      if(!previous)byId.set(row.id,{...row,token:review.token});
    }
    const rows=[...byId.values()].filter(row=>!conflicts.has(row.id));
    const issues=reviews.flatMap(review=>review.issues||[]);
    if(!rows.length && !issues.length && !conflicts.size){resolve(0);return;}
    const dialog=document.createElement('dialog'); dialog.className='card';dialog.dir='rtl';dialog.setAttribute('aria-label','به‌روزرسانی اطلاعات OEU');
    dialog.style.cssText='width:min(900px,calc(100vw - 32px));max-height:90dvh;overflow:auto;border:1px solid #e1e6ef;border-radius:24px;padding:24px;color:inherit';
    dialog.innerHTML=`<h3>به‌روزرسانی اطلاعات کاربران سازمان</h3><p>${rows.length} کاربر با اطلاعات متفاوت در فایل پیدا شد. تغییرات فقط با تأیید شما در OEU ذخیره می‌شوند.</p>
      ${issues.length||conflicts.size?'<p class="hint">ردیف‌های دارای تعارض هویتی یا اطلاعات تکراری متفاوت، به‌روزرسانی نمی‌شوند؛ آن‌ها را در فهرست تعارض‌ها بررسی کنید.</p>':''}
      ${rows.length?'<label style="display:flex;align-items:center;gap:8px;margin-bottom:16px"><input type="checkbox" data-oeu-all checked>انتخاب همه</label>':''}
      <div style="display:grid;gap:16px;max-height:52dvh;overflow:auto">${rows.map(row=>`<section class="card" style="padding:16px"><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" data-oeu-id="${escape(row.id)}" checked><strong>${escape(row.name||'کاربر '+row.id)}</strong><span>کد پرسنلی: ${escape(row.work_id||'—')}</span></label>
      <div style="overflow-x:auto;margin-top:12px"><table style="width:100%;text-align:right;border-collapse:collapse"><thead><tr><th>اطلاعات</th><th>فعلی در OEU</th><th>از فایل اکسل</th></tr></thead><tbody>${Object.entries(row.changes).map(([field,value])=>`<tr><td style="padding:8px">${escape(labels[field]||field)}</td><td style="padding:8px;overflow-wrap:anywhere">${escape(row.before[field]||'—')}</td><td style="padding:8px;overflow-wrap:anywhere">${escape(value||'خالی می‌شود')}</td></tr>`).join('')}</tbody></table></div></section>`).join('')}</div>
      <p role="status" aria-live="polite"></p><div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:20px"><button type="button" class="btn primary" data-oeu-apply ${rows.length?'':'disabled'}>به‌روزرسانی انتخاب‌شده‌ها</button><button type="button" class="btn ghost" data-oeu-skip>ادامه بدون به‌روزرسانی</button></div>`;
    let busy=false, updated=0;const completed=new Set();
    const checks=()=>[...dialog.querySelectorAll('[data-oeu-id]')];
    const refresh=()=>{const active=checks().filter(check=>!completed.has(Number(check.dataset.oeuId)));const all=dialog.querySelector('[data-oeu-all]');if(all){all.checked=active.length>0&&active.every(check=>check.checked);all.indeterminate=active.some(check=>check.checked)&&!all.checked;}dialog.querySelector('[data-oeu-apply]').disabled=busy||!active.some(check=>check.checked);};
    dialog.addEventListener('change',event=>{if(event.target.matches('[data-oeu-all]'))checks().forEach(check=>{if(!completed.has(Number(check.dataset.oeuId)))check.checked=event.target.checked;});refresh();});
    dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
    dialog.addEventListener('close',()=>{dialog.remove();resolve(updated);});
    dialog.querySelector('[data-oeu-skip]').onclick=()=>{if(!busy)dialog.close();};
    dialog.querySelector('[data-oeu-apply]').onclick=async()=>{
      if(busy)return;busy=true;checks().forEach(check=>check.disabled=true);if(dialog.querySelector('[data-oeu-all]'))dialog.querySelector('[data-oeu-all]').disabled=true;dialog.querySelector('[data-oeu-skip]').disabled=true;refresh();
      const status=dialog.querySelector('[role=status]');status.textContent='در حال ذخیره تغییرات…';
      const selected=checks().filter(check=>check.checked&&!completed.has(Number(check.dataset.oeuId))).map(check=>byId.get(Number(check.dataset.oeuId)));
      try {
        for(const token of new Set(selected.map(row=>row.token))) {
          const ids=selected.filter(row=>row.token===token).map(row=>row.id);const result=await apply(token,ids);updated+=Number(result.updated||0);ids.forEach(id=>completed.add(id));
        }
        dialog.close();
      } catch(error){status.textContent=(updated?`${updated} کاربر ذخیره شد. `:'')+(error.message||'به‌روزرسانی انجام نشد.');}
      finally{busy=false;if(dialog.isConnected){checks().forEach(check=>check.disabled=completed.has(Number(check.dataset.oeuId)));if(dialog.querySelector('[data-oeu-all]'))dialog.querySelector('[data-oeu-all]').disabled=false;dialog.querySelector('[data-oeu-skip]').disabled=false;refresh();}}
    };
    document.body.append(dialog);dialog.showModal();
  });
})();
