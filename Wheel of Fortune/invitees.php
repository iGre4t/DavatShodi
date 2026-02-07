<div class="card">
  <div class="section-header">
    <h3>Insert Invite List</h3>
  </div>
  <div class="form">
    <div class="field standard-width">
      <span>Insert Excel File</span>
      <div class="field-block" style="padding:10px;">
        <input id="wf-invite-file" type="file" accept=".csv,.xls,.xlsx" hidden />
        <div class="field-controls" style="gap:8px;">
          <button type="button" class="btn" id="wf-invite-pick">Insert Excel File</button>
          <div id="wf-invite-file-name" class="muted">No file selected.</div>
        </div>
      </div>
    </div>
    <div class="field full">
      <button type="button" class="btn primary standard-primary-button" id="wf-invite-map">Map and Upload</button>
    </div>
  </div>
</div>

<div id="wf-invite-modal" class="modal hidden" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-card-header">
      <div class="modal-card-header-start">
        <h3>Map Columns</h3>
      </div>
      <button type="button" class="icon-btn" data-close-invite-modal aria-label="Close">×</button>
    </div>
    <div id="wf-invite-progress" class="modal-progress hidden" role="status" aria-live="polite">
      <div class="loader-ring" aria-hidden="true">
        <span></span>
        <span></span>
      </div>
      <p class="modal-progress__message" data-invite-progress-message>در حال آماده‌سازی...</p>
    </div>
    <div class="modal-card-body">
      <div class="form grid one-column">
        <label class="field">
          <span>Work ID</span>
          <select id="wf-map-work"></select>
        </label>
        <label class="field">
          <span>First Name</span>
          <select id="wf-map-first"></select>
        </label>
        <label class="field">
          <span>Last Name</span>
          <select id="wf-map-last"></select>
        </label>
        <label class="field">
          <span>National ID</span>
          <select id="wf-map-national"></select>
        </label>
        <label class="field">
          <span>Phone Number</span>
          <select id="wf-map-phone"></select>
        </label>
      </div>
      <p id="wf-invite-msg" class="hint" aria-live="polite"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-invite-modal>Cancel</button>
      <button type="button" class="btn primary" id="wf-invite-upload">Upload</button>
    </div>
  </div>
</div>

<script src="style/vendor/xlsx/xlsx.full.min.js" defer></script>
<script>
(() => {
  const pickBtn = document.getElementById('wf-invite-pick');
  const fileInput = document.getElementById('wf-invite-file');
  const fileNameEl = document.getElementById('wf-invite-file-name');
  const mapBtn = document.getElementById('wf-invite-map');
  const modal = document.getElementById('wf-invite-modal');
  const closeBtns = modal ? modal.querySelectorAll('[data-close-invite-modal]') : [];
  const mapWork = document.getElementById('wf-map-work');
  const mapFirst = document.getElementById('wf-map-first');
  const mapLast = document.getElementById('wf-map-last');
  const mapNational = document.getElementById('wf-map-national');
  const mapPhone = document.getElementById('wf-map-phone');
  const uploadBtn = document.getElementById('wf-invite-upload');
  const msgEl = document.getElementById('wf-invite-msg');
  const progressEl = document.getElementById('wf-invite-progress');
  const progressMsg = progressEl?.querySelector('[data-invite-progress-message]');

  let parsedRows = [];
  let headerRow = [];

  const setMsg = (text, isError = false) => {
    if (!msgEl) return;
    msgEl.textContent = text;
    msgEl.style.color = isError ? '#e11d2e' : '';
  };

  const openModal = () => {
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
  };

  const showProgress = (message) => {
    if (!progressEl) return;
    if (progressMsg) progressMsg.textContent = message || 'در حال آماده‌سازی...';
    progressEl.classList.remove('hidden');
  };

  const hideProgress = () => {
    if (!progressEl) return;
    progressEl.classList.add('hidden');
  };

  const closeModal = () => {
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    setMsg('');
    hideProgress();
  };

  const csvEscape = (value) => {
    const text = String(value ?? '');
    if (/[",\n]/.test(text)) {
      return `"${text.replace(/"/g, '""')}"`;
    }
    return text;
  };

  const generatePassword = () => {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let out = '';
    for (let i = 0; i < 5; i += 1) {
      out += chars[Math.floor(Math.random() * chars.length)];
    }
    return out;
  };

  const buildOptions = (selectEl) => {
    if (!selectEl) return;
    selectEl.innerHTML = '';
    headerRow.forEach((label, index) => {
      const opt = document.createElement('option');
      opt.value = String(index);
      opt.textContent = label || `Column ${index + 1}`;
      selectEl.appendChild(opt);
    });
  };

  const parseFile = async (file) => {
    const arrayBuffer = await file.arrayBuffer();
    const workbook = window.XLSX.read(arrayBuffer, { type: 'array' });
    const sheetName = workbook.SheetNames[0];
    const worksheet = workbook.Sheets[sheetName];
    const rows = window.XLSX.utils.sheet_to_json(worksheet, { header: 1, defval: '' });
    return rows;
  };

  pickBtn?.addEventListener('click', () => fileInput?.click());
  fileInput?.addEventListener('change', async () => {
    const file = fileInput.files?.[0];
    if (!file) return;
    fileNameEl.textContent = file.name;
    showProgress('در حال خواندن فایل...');
    try {
      parsedRows = await parseFile(file);
      headerRow = parsedRows[0] || [];
    } catch {
      setMsg('خواندن فایل با خطا مواجه شد.', true);
    }
    hideProgress();
  });

  mapBtn?.addEventListener('click', async () => {
    if (!parsedRows.length) {
      setMsg('ابتدا فایل را انتخاب کنید.', true);
      return;
    }
    if (!headerRow.length) {
      headerRow = parsedRows[0] || [];
    }
    if (!headerRow.length) {
      headerRow = new Array(parsedRows[0]?.length || 0).fill('').map((_, i) => `Column ${i + 1}`);
    }
    buildOptions(mapWork);
    buildOptions(mapFirst);
    buildOptions(mapLast);
    buildOptions(mapNational);
    buildOptions(mapPhone);
    openModal();
  });

  closeBtns.forEach(btn => btn.addEventListener('click', closeModal));

  uploadBtn?.addEventListener('click', async () => {
    if (!parsedRows.length) {
      setMsg('فایلی برای آپلود وجود ندارد.', true);
      return;
    }
    const workIdx = Number(mapWork?.value ?? -1);
    const firstIdx = Number(mapFirst?.value ?? -1);
    const lastIdx = Number(mapLast?.value ?? -1);
    const nationalIdx = Number(mapNational?.value ?? -1);
    const phoneIdx = Number(mapPhone?.value ?? -1);
    if (workIdx < 0 || firstIdx < 0 || lastIdx < 0 || nationalIdx < 0 || phoneIdx < 0) {
      setMsg('ستون‌ها را انتخاب کنید.', true);
      return;
    }

    const rows = parsedRows.map((row) => Array.from(row));
    if (!rows.length) {
      setMsg('فایل خالی است.', true);
      return;
    }
    rows[0] = [...rows[0], 'password'];
    for (let i = 1; i < rows.length; i += 1) {
      rows[i] = [...rows[i], generatePassword()];
    }

    const csv = rows.map((row) => row.map(csvEscape).join(',')).join('\n');

    const payload = {
      csv,
      mapping: {
        workId: workIdx,
        firstName: firstIdx,
        lastName: lastIdx,
        nationalId: nationalIdx,
        phoneNumber: phoneIdx
      }
    };

    try {
      showProgress('در حال آپلود و ساخت فایل...');
      const response = await fetch('Wheel%20of%20Fortune/invitees_upload.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const result = await response.json();
      if (response.ok && result?.status === 'ok') {
        setMsg('آپلود انجام شد.');
        closeModal();
      } else {
        setMsg(result?.message || 'خطا در آپلود.', true);
      }
    } catch {
      setMsg('خطا در آپلود.', true);
    } finally {
      hideProgress();
    }
  });
})();
</script>
