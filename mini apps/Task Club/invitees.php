<?php
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/tc-security.php';
requireTabPermissionFromSession('task-club', false);
$tcInviteesCsrfToken = tcSecurityGetCsrfToken();

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'TC Event';
$mappedFile = $baseDir . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
$mapFile = $baseDir . DIRECTORY_SEPARATOR . 'TC Mapped.json';
$stats = [
  'total' => 0,
  'columns' => [],
  'conflicts' => [
    'workId' => [],
    'nationalId' => [],
    'phoneNumber' => []
  ]
];

function readMappedConfig(string $path): array {
  if (!is_file($path)) {
    return [];
  }
  $data = json_decode(file_get_contents($path), true);
  return is_array($data) ? $data : [];
}

function readCsvRows(string $path): array {
  if (!is_file($path)) {
    return [];
  }
  $rows = [];
  if (($handle = fopen($path, 'r')) !== false) {
    while (($data = fgetcsv($handle)) !== false) {
      $rows[] = $data;
    }
    fclose($handle);
  }
  return $rows;
}

$mapping = readMappedConfig($mapFile);
$rows = readCsvRows($mappedFile);
if ($rows) {
  $header = $rows[0] ?? [];
  $stats['total'] = max(0, count($rows) - 1);
  $stats['columns'] = [
    'workId' => $header[$mapping['workId'] ?? -1] ?? '',
    'firstName' => $header[$mapping['firstName'] ?? -1] ?? '',
    'lastName' => $header[$mapping['lastName'] ?? -1] ?? '',
    'nationalId' => $header[$mapping['nationalId'] ?? -1] ?? '',
    'phoneNumber' => $header[$mapping['phoneNumber'] ?? -1] ?? ''
  ];

  $seen = [
    'workId' => [],
    'nationalId' => [],
    'phoneNumber' => []
  ];

  for ($i = 1; $i < count($rows); $i++) {
    $row = $rows[$i];
    $first = trim((string)($row[$mapping['firstName'] ?? -1] ?? ''));
    $last = trim((string)($row[$mapping['lastName'] ?? -1] ?? ''));
    $full = trim($first . ' ' . $last);
    $rowNumber = $i + 1;

    $fields = [
      'workId' => trim((string)($row[$mapping['workId'] ?? -1] ?? '')),
      'nationalId' => trim((string)($row[$mapping['nationalId'] ?? -1] ?? '')),
      'phoneNumber' => trim((string)($row[$mapping['phoneNumber'] ?? -1] ?? ''))
    ];

    foreach ($fields as $key => $value) {
      if ($value === '') {
        continue;
      }
      if (isset($seen[$key][$value])) {
        $stats['conflicts'][$key][] = [
          'row' => $rowNumber,
          'name' => $full !== '' ? $full : 'نامشخص',
          'value' => $value
        ];
      } else {
        $seen[$key][$value] = $rowNumber;
      }
    }
  }
}
?>

<div class="card">
  <div class="section-header">
    <h3>Insert Invite List</h3>
  </div>
  <div class="form">
    <div class="field standard-width">
      <span>Insert Excel File</span>
      <div class="field-block" style="padding:10px;">
        <input id="tc-invite-file" type="file" accept=".csv,.xls,.xlsx" hidden />
        <div class="field-controls" style="gap:8px;">
          <button type="button" class="btn" id="tc-invite-pick">Insert Excel File</button>
          <div id="tc-invite-file-name" class="muted">No file selected.</div>
        </div>
      </div>
    </div>
    <div class="field full">
      <button type="button" class="btn primary standard-primary-button" id="tc-invite-map">Map and Upload</button>
    </div>
  </div>
</div>

<div class="card">
  <div class="section-header">
    <h3>Stats</h3>
  </div>
  <div class="form" style="gap:12px;">
    <div class="field">
      <span>Total Invitees</span>
      <strong><?= htmlspecialchars((string)$stats['total'], ENT_QUOTES, 'UTF-8') ?></strong>
    </div>
    <div class="field">
      <span>Mapped Columns</span>
      <div class="field-block">
        <div class="muted">Work ID: <?= htmlspecialchars($stats['columns']['workId'] ?: '—', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="muted">First Name: <?= htmlspecialchars($stats['columns']['firstName'] ?: '—', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="muted">Last Name: <?= htmlspecialchars($stats['columns']['lastName'] ?: '—', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="muted">National ID: <?= htmlspecialchars($stats['columns']['nationalId'] ?: '—', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="muted">Phone Number: <?= htmlspecialchars($stats['columns']['phoneNumber'] ?: '—', ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>
    <div class="field">
      <span>Conflicts</span>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Type</th>
              <th>Row</th>
              <th>Full Name</th>
              <th>Value</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $conflictRows = [];
            foreach (['workId' => 'Work ID', 'nationalId' => 'National ID', 'phoneNumber' => 'Phone Number'] as $key => $label) {
              foreach ($stats['conflicts'][$key] as $item) {
                $conflictRows[] = [
                  'type' => $label,
                  'row' => $item['row'],
                  'name' => $item['name'],
                  'value' => $item['value']
                ];
              }
            }
            if (!$conflictRows): ?>
              <tr>
                <td colspan="4" class="muted">No conflicts found.</td>
              </tr>
            <?php else:
              foreach ($conflictRows as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['type'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string)$row['row'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars($row['value'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div id="tc-invite-modal" class="modal hidden" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-card-header">
      <div class="modal-card-header-start">
        <h3>Map Columns</h3>
      </div>
      <button type="button" class="icon-btn" data-close-invite-modal aria-label="Close">×</button>
    </div>
    <div id="tc-invite-progress" class="modal-progress hidden" role="status" aria-live="polite">
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
          <select id="tc-map-work"></select>
        </label>
        <label class="field">
          <span>First Name</span>
          <select id="tc-map-first"></select>
        </label>
        <label class="field">
          <span>Last Name</span>
          <select id="tc-map-last"></select>
        </label>
        <label class="field">
          <span>National ID</span>
          <select id="tc-map-national"></select>
        </label>
        <label class="field">
          <span>Phone Number</span>
          <select id="tc-map-phone"></select>
        </label>
      </div>
      <p id="tc-invite-msg" class="hint" aria-live="polite"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-invite-modal>Cancel</button>
      <button type="button" class="btn primary" id="tc-invite-upload">Upload</button>
    </div>
  </div>
</div>

<script src="mini%20apps/Task%20Club/vendor/xlsx/xlsx.full.min.js" defer></script>
<script>
(() => {
  const csrfToken = <?= json_encode($tcInviteesCsrfToken, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  const pickBtn = document.getElementById('tc-invite-pick');
  const fileInput = document.getElementById('tc-invite-file');
  const fileNameEl = document.getElementById('tc-invite-file-name');
  const mapBtn = document.getElementById('tc-invite-map');
  const modal = document.getElementById('tc-invite-modal');
  const closeBtns = modal ? modal.querySelectorAll('[data-close-invite-modal]') : [];
  const mapWork = document.getElementById('tc-map-work');
  const mapFirst = document.getElementById('tc-map-first');
  const mapLast = document.getElementById('tc-map-last');
  const mapNational = document.getElementById('tc-map-national');
  const mapPhone = document.getElementById('tc-map-phone');
  const uploadBtn = document.getElementById('tc-invite-upload');
  const msgEl = document.getElementById('tc-invite-msg');
  const progressEl = document.getElementById('tc-invite-progress');
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
    rows[0] = [
      ...rows[0],
      'password',
      'logins counts',
      'logins',
      'count of rolls',
      'prize won'
    ];
    for (let i = 1; i < rows.length; i += 1) {
      rows[i] = [
        ...rows[i],
        generatePassword(),
        '',
        '',
        '',
        ''
      ];
    }

    const csv = rows.map((row) => row.map(csvEscape).join(',')).join('\n');

    const payload = {
      csrf: csrfToken,
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
      const response = await fetch('mini%20apps/Task%20Club/invitees_upload.php', {
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

