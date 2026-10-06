(() => {
  const card = document.getElementById('egm-facilitators');
  if (!card || card.dataset.initialized) return;
  card.dataset.initialized = '1';
  const $ = selector => card.querySelector(selector);
  const endpoint = new URL(card.dataset.endpoint, window.location.href);
  const csrf = card.closest('.egm-shell')?.dataset.egmCsrf || '';
  const editor = $('[data-fac-editor]'), passwordDialog = $('[data-fac-password-dialog]');
  const deleteDialog = $('[data-fac-delete-dialog]'), importDialog = $('[data-fac-import-dialog]');
  let users = [], editingId = '', passwordId = '', deleteId = '', workbook = null, sheetRows = [];
  const status = (selector, message = '', error = false) => {
    const el = $(selector); el.textContent = message; el.classList.toggle('egm-fac-error', error);
  };
  async function request(action = '', fields = {}) {
    const response = await fetch(endpoint, action ? {
      method:'POST', credentials:'same-origin', cache:'no-store',
      body:new URLSearchParams({payload:JSON.stringify({action, ...fields, csrf})}),
    } : {credentials:'same-origin', cache:'no-store'});
    const payload = await response.json().catch(() => null);
    if (!response.ok || payload?.status !== 'ok') throw new Error(payload?.message || 'درخواست ناموفق بود. دوباره امتحان کنید.');
    return payload;
  }
  function render() {
    const list = $('[data-fac-list]'); list.replaceChildren();
    if (!users.length) {
      const row = list.insertRow(), cell = row.insertCell(); cell.colSpan = 3; cell.className = 'muted';
      cell.textContent = 'هنوز تسهیلگری اضافه نشده است.'; return;
    }
    for (const user of users) {
      const row = list.insertRow(); row.insertCell().textContent = user.name;
      const username = row.insertCell(); username.textContent = user.username; username.dir = 'auto';
      const actions = row.insertCell(); actions.className = 'egm-fac-actions';
      for (const [action,label] of [['edit','ویرایش'],['password','نمایش رمز'],['delete','حذف']]) {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'btn ghost';
        button.dataset.facAction = action; button.dataset.id = user.id; button.textContent = label;
        button.setAttribute('aria-label', `${label} · ${user.name}`); actions.append(button);
      }
    }
  }
  function openEditor(user = null) {
    const form = $('[data-fac-form]'); form.reset(); editingId = user?.id || '';
    $('#egm-fac-editor-title').textContent = user ? 'ویرایش تسهیلگر' : 'افزودن تسهیلگر';
    form.elements.name.value = user?.name || ''; form.elements.username.value = user?.username || '';
    form.elements.password.required = !user; form.elements.password.type = 'password';
    form.querySelector('[data-fac-toggle]').textContent = 'نمایش';
    $('[data-fac-password-hint]').textContent = user ? 'برای حفظ رمز فعلی، این قسمت را خالی بگذارید.' : '';
    status('[data-fac-editor-status]'); editor.showModal();
  }
  async function submit(form, selector, action, fields, dialog) {
    const buttons = [...form.querySelectorAll('button')]; buttons.forEach(button => button.disabled = true);
    status(selector,'در حال ذخیره…');
    try {
      const payload = await request(action, fields); users = payload.users; render();
      dialog.close(); status('[data-fac-status]','ذخیره شد.');
    } catch (error) {status(selector,error.message,true);}
    finally {buttons.forEach(button => button.disabled = false);}
  }
  $('[data-fac-add]').addEventListener('click',()=>openEditor());
  card.addEventListener('click',async event => {
    const close = event.target.closest('[data-fac-close]');
    if (close) {close.closest('dialog').close(); return;}
    const toggle = event.target.closest('[data-fac-toggle]');
    if (toggle) {
      const input = toggle.previousElementSibling; input.type = input.type === 'password' ? 'text' : 'password';
      toggle.textContent = input.type === 'password' ? 'نمایش' : 'پنهان'; return;
    }
    const trigger = event.target.closest('[data-fac-action]'); if (!trigger || trigger.disabled) return;
    const user = users.find(user => user.id === trigger.dataset.id); if (!user) return;
    if (trigger.dataset.facAction === 'edit') openEditor(user);
    if (trigger.dataset.facAction === 'delete') {
      deleteId = user.id; $('[data-fac-delete-name]').textContent = `«${user.name}» حذف شود؟`;
      status('[data-fac-delete-status]'); deleteDialog.showModal();
    }
    if (trigger.dataset.facAction === 'password') {
      trigger.disabled = true;
      try {
        const payload = await request('reveal',{id:user.id}); passwordId = user.id;
        $('[data-fac-password-name]').textContent = user.name;
        const input = $('[data-fac-password-form]').elements.password; input.value = payload.password; input.type = 'text';
        passwordDialog.querySelector('[data-fac-toggle]').textContent = 'پنهان';
        status('[data-fac-password-status]'); passwordDialog.showModal();
      } catch (error) {status('[data-fac-status]',error.message,true);}
      finally {trigger.disabled = false;}
    }
  });
  editor.addEventListener('close',()=>{$('[data-fac-form]').reset(); editingId = '';});
  passwordDialog.addEventListener('close',()=>{$('[data-fac-password-form]').reset(); passwordId = '';});
  $('[data-fac-form]').addEventListener('submit',event=>{
    event.preventDefault(); const form = event.currentTarget;
    void submit(form,'[data-fac-editor-status]','save',{
      id:editingId,name:form.elements.name.value,username:form.elements.username.value,password:form.elements.password.value,
    },editor);
  });
  $('[data-fac-password-form]').addEventListener('submit',event=>{
    event.preventDefault(); const user = users.find(user=>user.id===passwordId); if (!user) return;
    void submit(event.currentTarget,'[data-fac-password-status]','save',{
      id:user.id,name:user.name,username:user.username,password:event.currentTarget.elements.password.value,
    },passwordDialog);
  });
  $('[data-fac-delete-form]').addEventListener('submit',event=>{
    event.preventDefault(); void submit(event.currentTarget,'[data-fac-delete-status]','delete',{id:deleteId},deleteDialog);
  });
  const columns = [...card.querySelectorAll('[data-fac-column]')];
  function mappedRows() {
    if (!sheetRows.length || columns.some(select=>select.value==='')) return [];
    return sheetRows.slice(1).map(row => Object.fromEntries(columns.map(select=>[select.dataset.facColumn,String(row[Number(select.value)] ?? '')])));
  }
  function preview() {
    const rows = mappedRows(), list = $('[data-fac-preview]'); list.replaceChildren();
    const distinct = new Set(columns.map(select=>select.value)).size === 3;
    const valid = rows.length > 0 && rows.length <= 1000 && distinct;
    $('[data-fac-import-save]').disabled = !valid;
    $('[data-fac-import-count]').textContent = rows.length ? `${rows.length.toLocaleString('fa-IR')} تسهیلگر · پیش‌نمایش ۵ ردیف اول` : '';
    status('[data-fac-import-status]', !distinct && rows.length ? 'برای هر مورد یک ستون متفاوت انتخاب کنید.' : rows.length > 1000 ? 'حداکثر ۱۰۰۰ ردیف قابل ورود است.' : '', !distinct || rows.length > 1000);
    for (const row of rows.slice(0,5)) {
      const tr = list.insertRow(); tr.insertCell().textContent = row.name;
      tr.insertCell().textContent = row.username; tr.insertCell().textContent = row.password ? '••••••' : 'خالی';
    }
  }
  function selectSheet() {
    sheetRows = XLSX.utils.sheet_to_json(workbook.Sheets[$('[data-fac-sheet]').value],{header:1,raw:false,defval:'',blankrows:false});
    const headers = sheetRows[0] || [];
    for (const select of columns) {
      select.replaceChildren(new Option('انتخاب ستون',''));
      headers.forEach((header,index)=>select.add(new Option(`${index+1} · ${String(header || 'بدون عنوان')}`,String(index))));
      const patterns = {name:/^(نام|نام و نام خانوادگی|نام کامل|name|fullname|full name)$/i,username:/^(نام کاربری|username|user name)$/i,password:/^(رمز عبور|رمز|password|pass)$/i};
      const guessed = headers.findIndex(header=>patterns[select.dataset.facColumn].test(String(header).trim()));
      if (guessed>=0) select.value = String(guessed);
    }
    preview();
  }
  $('[data-fac-upload]').addEventListener('click',()=>{$('[data-fac-file]').value='';$('[data-fac-file]').click();});
  $('[data-fac-file]').addEventListener('change',async event=>{
    const file = event.target.files[0]; if (!file) return;
    try {
      if (file.size > 5*1024*1024) throw new Error('حجم فایل باید حداکثر ۵ مگابایت باشد.');
      if (!window.XLSX) throw new Error('ابزار اکسل بارگذاری نشده است. صفحه را تازه‌سازی کنید.');
      workbook = XLSX.read(await file.arrayBuffer(),{type:'array'});
      if (!workbook.SheetNames.length) throw new Error('فایل برگه‌ای ندارد.');
      $('[data-fac-sheet]').replaceChildren(...workbook.SheetNames.map(name=>new Option(name,name)));
      $('[data-fac-update]').checked = false; selectSheet(); importDialog.showModal();
    } catch (error) {workbook=null;sheetRows=[];status('[data-fac-status]',error.message,true);}
  });
  $('[data-fac-sheet]').addEventListener('change',selectSheet);
  columns.forEach(select=>select.addEventListener('change',preview));
  importDialog.addEventListener('close',()=>{workbook=null;sheetRows=[];$('[data-fac-preview]').replaceChildren();$('[data-fac-file]').value='';});
  $('[data-fac-import-form]').addEventListener('submit',event=>{
    event.preventDefault(); const rows = mappedRows();
    if (!rows.length || rows.length>1000 || new Set(columns.map(select=>select.value)).size!==3) return;
    void submit(event.currentTarget,'[data-fac-import-status]','import',{rows,update_existing:$('[data-fac-update]').checked},importDialog);
  });
  const entryButtons = [$('[data-fac-add]'),$('[data-fac-upload]')];
  entryButtons.forEach(button=>button.disabled=true);
  request().then(payload=>{users=payload.users;render();}).catch(error=>status('[data-fac-status]',error.message,true))
    .finally(()=>entryButtons.forEach(button=>button.disabled=false));
})();
