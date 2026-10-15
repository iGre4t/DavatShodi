(() => {
  function init() {
    const root = document.querySelector('[data-general-draw-manager]');
    if (!root || root.dataset.ready) return;
    root.dataset.ready = '1';
    const pane = root.closest('.sub-pane');
    const form = root.querySelector('[data-general-form]');
    const status = root.querySelector('[data-general-status]');
    const list = root.querySelector('[data-general-list]');
    const endpoint = new URL(root.dataset.endpoint, location.href);
    let draws = [], busy = false;
    const field = name => form.elements.namedItem(name);
    const request = async (action, data = {}) => {
      const response = await fetch(endpoint, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action, ...data, csrf:document.querySelector('.egm-shell')?.dataset.egmCsrf})});
      const result = await response.json();
      if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'ذخیره ناموفق بود.');
      return result;
    };
    const button = (label, click) => { const b = document.createElement('button'); b.type='button'; b.className='btn ghost'; b.textContent=label; b.onclick=click; return b; };
    const close = () => { form.reset(); field('id').value=''; form.hidden=true; };
    const open = draw => { close(); if(draw) for(const name of ['id','name','title','prizeName','description','winnerLimit']) field(name).value=draw[name]; for(const name of ['keepConfirmedCards','preventRepeatedPrizeNames','preventRepeatedPrizes']) field(name).checked=draw?.[name] !== false; form.hidden=false; form.scrollIntoView({behavior:'smooth',block:'center'}); field('name').focus(); };
    async function load() {
      try {
        draws=(await request('list')).draws;
        list.replaceChildren();
        const destinations=document.getElementById('egm-linker-destination');
        destinations?.querySelectorAll('option[data-general-draw]').forEach(o=>o.remove());
        draws.forEach(draw=> {
          const article=document.createElement('article'); article.className='card';
          const heading=document.createElement('h4'); heading.textContent=draw.name;
          const summary=document.createElement('p'); summary.textContent=`${draw.winnerLimit} برنده در هر بازه · ${draw.prizeName}`;
          const url=new URL('general_draw.php',endpoint); url.searchParams.set('level_id',draw.id);
          const link=document.createElement('a'); link.className='btn primary'; link.textContent='باز کردن قرعه‌کشی'; link.href=url.href; link.target='_blank'; link.rel='noopener';
          const address=document.createElement('input'); address.readOnly=true; address.dir='ltr'; address.value=url.href; address.setAttribute('aria-label','لینک دائمی قرعه‌کشی'); address.className='field';
          const actions=document.createElement('div'); actions.className='egm-period-actions';
          actions.append(link,button('کپی لینک',async()=>{try{await navigator.clipboard.writeText(url.href);status.textContent='لینک کپی شد.';}catch{address.select();}}),button('ویرایش',()=>open(draw)),button('بازنشانی برندگان بازهٔ فعال',async()=>{if(confirm('برندگان بازهٔ فعال بازنشانی شوند؟'))await mutate('reset',{id:draw.id});}),button('حذف',async()=>{if(confirm('این قرعه‌کشی دائمی حذف شود؟'))await mutate('delete',{id:draw.id});}));
          actions.append(button('نهایی‌سازی بازهٔ فعال',()=>mutate('lock',{id:draw.id})),button('باز کردن قفل بازهٔ فعال',()=>mutate('unlock',{id:draw.id})));
          for(const [type,label] of [['winners','خروجی برندگان بازهٔ فعال'],['reached_non_winners','خروجی واجدان شرایط']]) { const exportLink=document.createElement('a'); const exportUrl=new URL(endpoint); exportUrl.search=new URLSearchParams({action:'export',id:draw.id,type}); exportLink.href=exportUrl.href; exportLink.className='btn ghost'; exportLink.textContent=label; actions.append(exportLink); }
          article.append(heading,summary,address,actions);list.append(article);
          if(destinations){const option=new Option(draw.name,`draw:${draw.id}`);option.dataset.generalDraw='1';destinations.append(option);}
        });
        if(!draws.length)list.textContent='هنوز قرعه‌کشی دائمی ایجاد نشده است.';
      } catch(error){status.textContent=error.message;}
    }
    async function mutate(action,data) {
      if(busy)return;busy=true;form.querySelectorAll('button').forEach(b=>b.disabled=true);
      try{await request(action,data);close();await load();status.textContent='ذخیره شد.';}catch(error){status.textContent=error.message;}finally{busy=false;form.querySelectorAll('button').forEach(b=>b.disabled=false);}
    }
    pane.querySelectorAll('[data-prize-pane]').forEach(b=>b.onclick=()=>{pane.querySelectorAll('[data-prize-content]').forEach(s=>s.hidden=s.dataset.prizeContent!==b.dataset.prizePane);pane.querySelectorAll('[data-prize-pane]').forEach(t=>t.classList.toggle('primary',t===b));});
    root.querySelector('[data-general-new]').onclick=()=>open();
    root.querySelector('[data-general-cancel]').onclick=close;
    form.onsubmit=e=>{e.preventDefault();const data=Object.fromEntries(new FormData(form));for(const name of ['keepConfirmedCards','preventRepeatedPrizeNames','preventRepeatedPrizes'])data[name]=field(name).checked;data.includeEntered=true;data.includeWalkIns=false;void mutate(data.id?'save':'create',data);};
    void load();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
