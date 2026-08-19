<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../../api/lib/common.php';
require_once __DIR__ . '/../../../api/lib/egm-instance-storage.php';
require_once __DIR__ . '/org_users_store.php';

$orgUsersSessionUser = requireAuthenticatedSessionUser(false);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['org_users_csrf']) || !is_string($_SESSION['org_users_csrf'])) {
    $_SESSION['org_users_csrf'] = bin2hex(random_bytes(32));
}
$orgUsersCsrfToken = (string)$_SESSION['org_users_csrf'];
$orgUsersConfig = loadConfig(__DIR__ . '/../../../api/config.php');
$orgUsersPdo = connectDatabase($orgUsersConfig);

function orgUsersJsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$orgUsersTableReady = $orgUsersPdo instanceof PDO && orgUsersEnsureTable($orgUsersPdo);

$orgUsersAction = trim((string)($_GET['action'] ?? ''));
if ($orgUsersAction === 'filter_options') {
    if (!$orgUsersPdo instanceof PDO || !$orgUsersTableReady) {
        orgUsersJsonResponse(['status' => 'error', 'message' => 'The organizational users database is unavailable.'], 503);
    }
    try {
        orgUsersJsonResponse([
            'status' => 'ok',
            'options' => orgUsersFilterOptions($orgUsersPdo, trim((string)($_GET['type'] ?? 'active')))
        ]);
    } catch (Throwable $error) {
        error_log('Failed to load organizational users filter options: ' . $error->getMessage());
        orgUsersJsonResponse(['status' => 'error', 'message' => 'Could not load filter options.'], 500);
    }
}
if ($orgUsersAction === 'list') {
    if (!$orgUsersPdo instanceof PDO || !$orgUsersTableReady) {
        orgUsersJsonResponse(['status' => 'error', 'message' => 'The organizational users database is unavailable.'], 503);
    }
    $listType = trim((string)($_GET['type'] ?? 'active'));
    $filterKeys = array_merge(['q', 'source_row', 'imported_from', 'imported_to', 'quit_reason', 'quit_batch_id', 'original_user_id', 'quit_from', 'quit_to'], orgUsersDataFields());
    $filters = [];
    foreach ($filterKeys as $key) {
        $filters[$key] = orgUsersCleanValue($_GET[$key] ?? '', 191);
    }
    try {
        orgUsersJsonResponse([
            'status' => 'ok',
            'data' => orgUsersListRows(
                $orgUsersPdo,
                $listType,
                $filters,
                (int)($_GET['page'] ?? 1),
                (int)($_GET['page_size'] ?? 50)
            )
        ]);
    } catch (Throwable $error) {
        error_log('Failed to list organizational users: ' . $error->getMessage());
        orgUsersJsonResponse(['status' => 'error', 'message' => 'Could not load the organizational users list.'], 500);
    }
}
if ($orgUsersAction === 'download') {
    if (!$orgUsersPdo instanceof PDO || !$orgUsersTableReady) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'The organizational users database is unavailable.';
        exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="organizational-users.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        exit;
    }
    fputcsv($output, ['Work ID', 'First Name', 'Last Name', 'National ID', 'Phone Number', 'معاونت', 'اداره کل', 'اداره', 'جنسیت', 'سطح پستی'], ',', '"', '\\');
    $statement = $orgUsersPdo->query(
        'SELECT `work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, `deputy`, `general_department`, `department`, `gender`, `postal_level` '
        . 'FROM `organizational_event_users` ORDER BY `source_row`, `id`'
    );
    while ($row = $statement->fetch(PDO::FETCH_NUM)) {
        fputcsv($output, $row, ',', '"', '\\');
    }
    fclose($output);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        orgUsersJsonResponse(['status' => 'error', 'message' => 'Invalid request payload.'], 400);
    }
    $requestToken = (string)($input['csrf'] ?? '');
    if ($requestToken === '' || !hash_equals($orgUsersCsrfToken, $requestToken)) {
        orgUsersJsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token.'], 403);
    }
    $syncAction = trim((string)($input['action'] ?? ''));
    if (!in_array($syncAction, ['preview', 'save', 'edit_conflict', 'archive_conflict'], true)) {
        orgUsersJsonResponse(['status' => 'error', 'message' => 'Unsupported action.'], 400);
    }
    if (!$orgUsersPdo instanceof PDO || !$orgUsersTableReady) {
        orgUsersJsonResponse(['status' => 'error', 'message' => 'The organizational users database is unavailable.'], 503);
    }
    if ($syncAction === 'edit_conflict') {
        try {
            $updatedUser = orgUsersUpdateActiveUser($orgUsersPdo, (int)($input['id'] ?? 0), is_array($input['user'] ?? null) ? $input['user'] : []);
            orgUsersJsonResponse([
                'status' => 'ok',
                'message' => 'The organizational user was updated.',
                'user' => $updatedUser,
                'stats' => orgUsersStats($orgUsersPdo, true)
            ]);
        } catch (InvalidArgumentException $error) {
            orgUsersJsonResponse(['status' => 'error', 'message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Failed to edit organizational user conflict: ' . $error->getMessage());
            orgUsersJsonResponse(['status' => 'error', 'message' => 'Could not update the organizational user.'], 500);
        }
    }
    if ($syncAction === 'archive_conflict') {
        try {
            orgUsersArchiveActiveUser($orgUsersPdo, (int)($input['id'] ?? 0));
            orgUsersJsonResponse([
                'status' => 'ok',
                'message' => 'The organizational user was moved to OEU Quit.',
                'stats' => orgUsersStats($orgUsersPdo, true)
            ]);
        } catch (InvalidArgumentException $error) {
            orgUsersJsonResponse(['status' => 'error', 'message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Failed to archive organizational user conflict: ' . $error->getMessage());
            orgUsersJsonResponse(['status' => 'error', 'message' => 'Could not move the organizational user to OEU Quit.'], 500);
        }
    }
    $preparedImport = orgUsersPrepareImportRows($input['rows'] ?? null);
    if ($preparedImport['errors']) {
        orgUsersJsonResponse([
            'status' => 'error',
            'message' => implode("\n", $preparedImport['errors']),
            'errors' => $preparedImport['errors']
        ], 422);
    }
    $rows = $preparedImport['rows'];
    if (!$rows) {
        orgUsersJsonResponse(['status' => 'error', 'message' => 'The mapped spreadsheet does not contain any user rows.'], 422);
    }
    try {
        if ($syncAction === 'preview') {
            orgUsersJsonResponse([
                'status' => 'ok',
                'message' => 'Synchronization preview is ready.',
                'summary' => orgUsersPreviewSync($orgUsersPdo, $rows)
            ]);
        }
        if (($input['confirmSync'] ?? false) !== true) {
            orgUsersJsonResponse(['status' => 'error', 'message' => 'The synchronization must be previewed and confirmed first.'], 422);
        }
        $syncSummary = orgUsersSyncRows($orgUsersPdo, $rows);
        try {
            $syncSummary['egm_users_refreshed'] = egmInstanceRefreshAllUsersFromOeu($orgUsersPdo);
        } catch (Throwable $egmRefreshError) {
            error_log('Failed to refresh EGM users from OEU: ' . $egmRefreshError->getMessage());
            $syncSummary['egm_users_refreshed'] = 0;
            $syncSummary['egm_users_refresh_warning'] = true;
        }
    } catch (Throwable $error) {
        error_log('Organizational users synchronization failed: ' . $error->getMessage());
        orgUsersJsonResponse(['status' => 'error', 'message' => 'Could not synchronize organizational users with the database.'], 500);
    }
    orgUsersJsonResponse([
        'status' => 'ok',
        'message' => 'Organizational users were synchronized with the SAP upload.',
        'summary' => $syncSummary,
        'stats' => orgUsersStats($orgUsersPdo, true)
    ]);
}

$orgUsersCurrentStats = orgUsersStats($orgUsersPdo, $orgUsersTableReady);
?>

<?php $orgUsersCssVersion = (string)(@filemtime(__DIR__ . '/org-users-panel.css') ?: time()); ?>
<link rel="stylesheet" href="modules/minor/Organizational%20Event%20Userbase/org-users-panel.css?v=<?= htmlspecialchars($orgUsersCssVersion, ENT_QUOTES, 'UTF-8') ?>" />

<div id="org-users-panel" class="oeu-shell" dir="rtl">
  <div class="oeu-top-nav" role="tablist" aria-label="Organizational Event Users">
    <button type="button" class="oeu-top-item active" data-oeu-pane-trigger="active" aria-selected="true">OEU <span data-oeu-nav-count="active"><?= htmlspecialchars((string)$orgUsersCurrentStats['count'], ENT_QUOTES, 'UTF-8') ?></span></button>
    <button type="button" class="oeu-top-item" data-oeu-pane-trigger="conflicts" aria-selected="false">Conflicts <span data-oeu-nav-count="conflicts">—</span></button>
    <button type="button" class="oeu-top-item" data-oeu-pane-trigger="upload" aria-selected="false">Upload</button>
    <button type="button" class="oeu-top-item" data-oeu-pane-trigger="quit" aria-selected="false">OEU Quit <span data-oeu-nav-count="quit"><?= htmlspecialchars((string)$orgUsersCurrentStats['quit_count'], ENT_QUOTES, 'UTF-8') ?></span></button>
  </div>

  <?php foreach (['active' => 'Organizational Event Users', 'conflicts' => 'Conflicts', 'quit' => 'OEU Quit'] as $orgListType => $orgListTitle): ?>
  <section class="oeu-pane<?= $orgListType === 'active' ? ' active' : '' ?>" data-oeu-pane="<?= htmlspecialchars($orgListType, ENT_QUOTES, 'UTF-8') ?>"<?= $orgListType === 'active' ? '' : ' hidden' ?>>
    <div class="card oeu-filter-card">
      <div class="section-header">
        <div>
          <h3><?= htmlspecialchars($orgListTitle, ENT_QUOTES, 'UTF-8') ?></h3>
          <p class="muted"><?= $orgListType === 'conflicts' ? 'Duplicate or invalid National IDs, Work IDs, and phone numbers.' : 'Search globally or narrow results with database-backed dropdowns.' ?></p>
        </div>
      </div>
      <form class="oeu-filter-form" data-oeu-filter-form="<?= htmlspecialchars($orgListType, ENT_QUOTES, 'UTF-8') ?>">
        <label class="field oeu-global-search">
          <span>Search everything</span>
          <input type="search" name="q" placeholder="Search names, IDs, or organizational information..." autocomplete="off" />
        </label>
        <div class="oeu-filter-grid" data-oeu-filter-fields="<?= htmlspecialchars($orgListType, ENT_QUOTES, 'UTF-8') ?>"></div>
        <div class="oeu-filter-actions">
          <button type="submit" class="btn primary">Apply filters</button>
          <button type="reset" class="btn ghost">Clear</button>
        </div>
      </form>
    </div>

    <div class="card oeu-list-card">
      <div class="oeu-list-meta">
        <strong><span data-oeu-total="<?= htmlspecialchars($orgListType, ENT_QUOTES, 'UTF-8') ?>">0</span> records</strong>
        <span class="hint" data-oeu-list-status="<?= htmlspecialchars($orgListType, ENT_QUOTES, 'UTF-8') ?>" aria-live="polite"></span>
      </div>
      <div class="table-wrapper oeu-table-wrap">
        <table class="tct-list-table oeu-table">
          <thead data-oeu-table-head="<?= htmlspecialchars($orgListType, ENT_QUOTES, 'UTF-8') ?>"></thead>
          <tbody data-oeu-table-body="<?= htmlspecialchars($orgListType, ENT_QUOTES, 'UTF-8') ?>"></tbody>
        </table>
      </div>
      <div class="oeu-pagination" data-oeu-pagination="<?= htmlspecialchars($orgListType, ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" class="btn ghost" data-oeu-page-action="prev">Previous</button>
        <span data-oeu-page-meta>Page 1 of 1</span>
        <button type="button" class="btn ghost" data-oeu-page-action="next">Next</button>
        <label class="field oeu-page-size"><span>Rows</span><select data-oeu-page-size><option>25</option><option selected>50</option><option>100</option></select></label>
      </div>
    </div>
  </section>
  <?php endforeach; ?>

  <section class="oeu-pane" data-oeu-pane="upload" hidden>
    <div class="card">
      <div class="section-header">
        <div>
          <h3>Upload SAP Users</h3>
          <p class="muted">Upload Excel or CSV, map its columns, preview the changes, and confirm synchronization.</p>
        </div>
      </div>
      <div class="form grid two-columns">
        <div class="field">
          <span>Users file</span>
          <div class="field-block" style="padding:10px;">
            <input id="org-users-file" type="file" accept=".csv,.xls,.xlsx" hidden />
            <div class="field-controls" style="gap:8px; flex-wrap:wrap;">
              <button type="button" class="btn" id="org-users-pick">Choose Excel or CSV</button>
              <span id="org-users-file-name" class="muted">No file selected.</span>
            </div>
          </div>
        </div>
        <div class="field">
          <span>Database status</span>
          <div class="field-block oeu-stat-block">
            <div><strong id="org-users-count"><?= htmlspecialchars((string)$orgUsersCurrentStats['count'], ENT_QUOTES, 'UTF-8') ?></strong><span class="muted"> active users</span></div>
            <div><strong id="org-users-quit-count"><?= htmlspecialchars((string)$orgUsersCurrentStats['quit_count'], ENT_QUOTES, 'UTF-8') ?></strong><span class="muted"> OEU Quit records</span></div>
            <div id="org-users-updated" class="muted"><?php if (empty($orgUsersCurrentStats['database_ready'])): ?>Database unavailable.<?php elseif ($orgUsersCurrentStats['updated_at'] !== ''): ?>Last synchronization: <?= htmlspecialchars($orgUsersCurrentStats['updated_at'], ENT_QUOTES, 'UTF-8') ?><?php else: ?>No users have been imported yet.<?php endif; ?></div>
          </div>
        </div>
        <div class="field full">
          <div class="field-controls" style="gap:8px; flex-wrap:wrap;">
            <button type="button" class="btn primary standard-primary-button" id="org-users-map">Map columns and synchronize</button>
            <?php if (!empty($orgUsersCurrentStats['database_ready']) && $orgUsersCurrentStats['count'] > 0): ?>
              <a class="btn" id="org-users-download" href="modules/minor/Organizational%20Event%20Userbase/OrgUsersPanel.php?action=download">Download active OEU CSV</a>
            <?php else: ?>
              <a class="btn hidden" id="org-users-download" href="modules/minor/Organizational%20Event%20Userbase/OrgUsersPanel.php?action=download">Download active OEU CSV</a>
            <?php endif; ?>
          </div>
          <p class="muted">National ID is the permanent identity key. SAP updates active employees and moves absent employees to OEU Quit.</p>
          <p id="org-users-message" class="hint" role="status" aria-live="polite"></p>
        </div>
      </div>
    </div>
  </section>
</div>

<div id="org-users-map-modal" class="modal hidden" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-card-header">
      <div class="modal-card-header-start"><h3>تطبیق ستون‌های کاربران</h3></div>
      <button type="button" class="icon-btn" data-close-org-users-modal aria-label="بستن">×</button>
    </div>
    <div class="modal-card-body">
      <div class="form grid one-column">
        <label class="field"><span>شناسه کاری (Work ID)</span><select id="org-map-work"></select></label>
        <label class="field"><span>نام</span><select id="org-map-first"></select></label>
        <label class="field"><span>نام خانوادگی</span><select id="org-map-last"></select></label>
        <label class="field"><span>کد ملی</span><select id="org-map-national"></select></label>
        <label class="field"><span>شماره تلفن</span><select id="org-map-phone"></select></label>
        <label class="field"><span>معاونت</span><select id="org-map-deputy"></select></label>
        <label class="field"><span>اداره کل</span><select id="org-map-general-department"></select></label>
        <label class="field"><span>اداره</span><select id="org-map-department"></select></label>
        <label class="field"><span>جنسیت</span><select id="org-map-gender"></select></label>
        <label class="field"><span>سطح پستی</span><select id="org-map-postal-level"></select></label>
      </div>
      <p id="org-users-modal-message" class="hint" aria-live="polite"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-org-users-modal>انصراف</button>
      <button type="button" class="btn primary" id="org-users-save">ذخیره در پایگاه داده</button>
    </div>
  </div>
</div>

<div id="org-users-conflict-modal" class="modal hidden" aria-hidden="true">
  <div class="modal-card oeu-conflict-modal-card">
    <div class="modal-card-header">
      <div class="modal-card-header-start"><h3>Resolve employee conflict</h3></div>
      <button type="button" class="icon-btn" data-close-oeu-conflict-modal aria-label="Close">×</button>
    </div>
    <div class="modal-card-body">
      <input type="hidden" id="oeu-conflict-id" />
      <div class="form grid two-columns" id="oeu-conflict-fields"></div>
      <p id="oeu-conflict-modal-message" class="hint" aria-live="polite"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-oeu-conflict-modal>Cancel</button>
      <button type="button" class="btn primary" id="oeu-conflict-save">Save changes</button>
    </div>
  </div>
</div>

<script src="mini%20apps/Task%20Club/vendor/xlsx/xlsx.full.min.js"></script>
<script>
(() => {
  const csrfToken = <?= json_encode($orgUsersCsrfToken, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const endpoint = 'modules/minor/Organizational%20Event%20Userbase/OrgUsersPanel.php';
  const fileInput = document.getElementById('org-users-file');
  const pickButton = document.getElementById('org-users-pick');
  const mapButton = document.getElementById('org-users-map');
  const saveButton = document.getElementById('org-users-save');
  const fileName = document.getElementById('org-users-file-name');
  const message = document.getElementById('org-users-message');
  const modalMessage = document.getElementById('org-users-modal-message');
  const modal = document.getElementById('org-users-map-modal');
  const selects = {
    workId: document.getElementById('org-map-work'),
    firstName: document.getElementById('org-map-first'),
    lastName: document.getElementById('org-map-last'),
    nationalId: document.getElementById('org-map-national'),
    phoneNumber: document.getElementById('org-map-phone'),
    deputy: document.getElementById('org-map-deputy'),
    generalDepartment: document.getElementById('org-map-general-department'),
    department: document.getElementById('org-map-department'),
    gender: document.getElementById('org-map-gender'),
    postalLevel: document.getElementById('org-map-postal-level')
  };
  let rows = [];
  let headers = [];

  const setMessage = (element, text, error = false) => {
    if (!element) return;
    element.textContent = text;
    element.style.color = error ? 'var(--danger, #b42318)' : '';
  };
  const panel = document.getElementById('org-users-panel');
  const conflictModal = document.getElementById('org-users-conflict-modal');
  const conflictModalMessage = document.getElementById('oeu-conflict-modal-message');
  const conflictSaveButton = document.getElementById('oeu-conflict-save');
  const conflictFieldsContainer = document.getElementById('oeu-conflict-fields');
  const conflictIdInput = document.getElementById('oeu-conflict-id');
  const employeeFields = [
    ['work_id', 'Work ID'],
    ['first_name', 'First Name'],
    ['last_name', 'Last Name'],
    ['national_id', 'National ID'],
    ['phone_number', 'Phone Number'],
    ['deputy', 'Deputy'],
    ['general_department', 'General Department'],
    ['department', 'Department'],
    ['gender', 'Gender'],
    ['postal_level', 'Postal Level']
  ];
  const listColumns = {
    active: [
      ['id', 'DB ID'], ['work_id', 'Work ID'], ['first_name', 'First Name'], ['last_name', 'Last Name'],
      ['national_id', 'National ID'], ['phone_number', 'Phone Number'], ['deputy', 'Deputy'],
      ['general_department', 'General Department'], ['department', 'Department'], ['gender', 'Gender'],
      ['postal_level', 'Postal Level'], ['source_row', 'SAP Row'], ['imported_at', 'Last Sync']
    ],
    conflicts: [
      ['id', 'DB ID'], ['work_id', 'Work ID'], ['first_name', 'First Name'], ['last_name', 'Last Name'],
      ['national_id', 'National ID'], ['phone_number', 'Phone Number'], ['conflicts', 'Conflict'],
      ['related_users', 'Related users'], ['actions', 'Actions']
    ],
    quit: [
      ['id', 'Archive ID'], ['original_user_id', 'Original DB ID'], ['work_id', 'Work ID'],
      ['first_name', 'First Name'], ['last_name', 'Last Name'], ['national_id', 'National ID'],
      ['phone_number', 'Phone Number'], ['deputy', 'Deputy'], ['general_department', 'General Department'],
      ['department', 'Department'], ['gender', 'Gender'], ['postal_level', 'Postal Level'],
      ['source_row', 'SAP Row'], ['quit_reason', 'Quit Reason'], ['quit_at', 'Quit At']
    ]
  };
  const listState = Object.fromEntries(['active', 'conflicts', 'quit'].map((type) => [type, {
    page: 1,
    pages: 1,
    pageSize: 50,
    rowsById: new Map(),
    loaded: false,
    filterOptionsLoaded: false,
    filterOptionsRequest: null,
    controller: null
  }]));

  const createElement = (tagName, className = '', text = '') => {
    const element = document.createElement(tagName);
    if (className) element.className = className;
    if (text !== '') element.textContent = text;
    return element;
  };
  const postJson = async (payload) => {
    const response = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...payload, csrf: csrfToken })
    });
    const result = await response.json().catch(() => ({ status: 'error', message: 'The server returned an invalid response.' }));
    if (!response.ok || result?.status !== 'ok') throw new Error(result?.message || 'The request failed.');
    return result;
  };
  const filterDefinitionsFor = (type) => {
    const fields = [
      ['deputy', 'Deputy'],
      ['general_department', 'General Department'],
      ['department', 'Department'],
      ['gender', 'Gender'],
      ['postal_level', 'Postal Level']
    ];
    if (type === 'quit') fields.push(['quit_reason', 'Quit Reason']);
    return fields;
  };
  document.querySelectorAll('[data-oeu-filter-fields]').forEach((container) => {
    const type = container.getAttribute('data-oeu-filter-fields');
    filterDefinitionsFor(type).forEach(([name, label]) => {
      const field = createElement('label', 'field');
      field.appendChild(createElement('span', '', label));
      const select = document.createElement('select');
      select.name = name;
      select.dataset.oeuFilterSelect = name;
      const allOption = document.createElement('option');
      allOption.value = '';
      allOption.textContent = `All ${label}`;
      select.appendChild(allOption);
      field.appendChild(select);
      container.appendChild(field);
    });
  });
  Object.entries(listColumns).forEach(([type, columns]) => {
    const head = panel?.querySelector(`[data-oeu-table-head="${type}"]`);
    if (!head) return;
    const row = document.createElement('tr');
    columns.forEach(([, label]) => row.appendChild(createElement('th', '', label)));
    head.replaceChildren(row);
  });
  const updateStats = (stats) => {
    if (!stats) return;
    const activeCount = String(stats.count ?? 0);
    const quitCount = String(stats.quit_count ?? 0);
    document.getElementById('org-users-count').textContent = activeCount;
    document.getElementById('org-users-quit-count').textContent = quitCount;
    document.querySelector('[data-oeu-nav-count="active"]')?.replaceChildren(activeCount);
    document.querySelector('[data-oeu-nav-count="quit"]')?.replaceChildren(quitCount);
  };
  const loadFilterOptions = async (type, force = false) => {
    const state = listState[type];
    if (!state || (state.filterOptionsLoaded && !force)) return;
    if (state.filterOptionsRequest && !force) return state.filterOptionsRequest;
    state.filterOptionsRequest = (async () => {
      const response = await fetch(`${endpoint}?${new URLSearchParams({ action: 'filter_options', type })}`, { credentials: 'same-origin' });
      const result = await response.json();
      if (!response.ok || result?.status !== 'ok') throw new Error(result?.message || 'Could not load filter options.');
      const form = panel?.querySelector(`[data-oeu-filter-form="${type}"]`);
      filterDefinitionsFor(type).forEach(([name, label]) => {
        const select = form?.querySelector(`[data-oeu-filter-select="${name}"]`);
        if (!select) return;
        const selectedValue = select.value;
        const fragment = document.createDocumentFragment();
        const allOption = document.createElement('option');
        allOption.value = '';
        allOption.textContent = `All ${label}`;
        fragment.appendChild(allOption);
        const values = Array.isArray(result.options?.[name]) ? result.options[name] : [];
        values.forEach((value) => {
          const option = document.createElement('option');
          option.value = String(value);
          option.textContent = String(value);
          fragment.appendChild(option);
        });
        select.replaceChildren(fragment);
        const field = select.closest('.field');
        if (field) field.hidden = values.length === 0;
        if (Array.from(select.options).some((option) => option.value === selectedValue)) select.value = selectedValue;
      });
      state.filterOptionsLoaded = true;
    })();
    try {
      await state.filterOptionsRequest;
    } finally {
      state.filterOptionsRequest = null;
    }
  };
  const renderList = (type, data) => {
    const body = panel?.querySelector(`[data-oeu-table-body="${type}"]`);
    const status = panel?.querySelector(`[data-oeu-list-status="${type}"]`);
    const total = panel?.querySelector(`[data-oeu-total="${type}"]`);
    const pagination = panel?.querySelector(`[data-oeu-pagination="${type}"]`);
    if (!body || !pagination) return;
    const state = listState[type];
    state.page = Number(data.page || 1);
    state.pages = Number(data.pages || 1);
    state.rowsById = new Map((data.rows || []).map((row) => [String(row.id), row]));
    state.loaded = true;
    if (total) total.textContent = String(data.total ?? 0);
    if (type === 'conflicts') document.querySelector('[data-oeu-nav-count="conflicts"]')?.replaceChildren(String(data.total ?? 0));
    body.replaceChildren();
    if (!Array.isArray(data.rows) || data.rows.length === 0) {
      const row = document.createElement('tr');
      const cell = createElement('td', 'muted', type === 'conflicts' ? 'No conflicts found.' : 'No matching records found.');
      cell.colSpan = listColumns[type].length;
      row.appendChild(cell);
      body.appendChild(row);
    } else {
      data.rows.forEach((record) => {
        const row = document.createElement('tr');
        listColumns[type].forEach(([key]) => {
          const cell = document.createElement('td');
          if (['id', 'original_user_id', 'work_id', 'national_id', 'phone_number', 'source_row', 'quit_batch_id'].includes(key)) cell.classList.add('oeu-ltr');
          if (key === 'conflicts') {
            cell.classList.add('oeu-wrap-cell');
            (record.conflicts || []).forEach((issue) => cell.appendChild(createElement('span', 'oeu-conflict-badge', String(issue))));
          } else if (key === 'related_users') {
            cell.classList.add('oeu-wrap-cell');
            const peers = Array.isArray(record.conflict_peers) ? record.conflict_peers : [];
            if (!peers.length) {
              cell.textContent = 'No matching user — invalid individual value';
              cell.classList.add('muted');
            } else {
              peers.forEach((peer) => {
                const peerCard = createElement('div', 'oeu-conflict-peer');
                const peerName = `${peer.first_name || ''} ${peer.last_name || ''}`.trim() || 'Unnamed employee';
                peerCard.appendChild(createElement('strong', '', `${peerName} · DB ID ${peer.id}`));
                peerCard.appendChild(createElement('span', 'oeu-ltr', `Work: ${peer.work_id || '—'} | National: ${peer.national_id || '—'} | Phone: ${peer.phone_number || '—'}`));
                cell.appendChild(peerCard);
              });
            }
          } else if (key === 'actions') {
            const actions = createElement('div', 'oeu-row-actions');
            const edit = createElement('button', 'btn ghost', 'Edit');
            edit.type = 'button';
            edit.dataset.oeuConflictEdit = String(record.id);
            const archive = createElement('button', 'btn ghost', 'Move to OEU Quit');
            archive.type = 'button';
            archive.dataset.oeuConflictArchive = String(record.id);
            actions.append(edit, archive);
            cell.appendChild(actions);
          } else {
            cell.textContent = String(record[key] ?? '');
          }
          row.appendChild(cell);
        });
        body.appendChild(row);
      });
    }
    const meta = pagination.querySelector('[data-oeu-page-meta]');
    if (meta) meta.textContent = `Page ${state.page} of ${state.pages}`;
    const prev = pagination.querySelector('[data-oeu-page-action="prev"]');
    const next = pagination.querySelector('[data-oeu-page-action="next"]');
    if (prev) prev.disabled = state.page <= 1;
    if (next) next.disabled = state.page >= state.pages;
    if (status) setMessage(status, '');
  };
  const loadList = async (type, page = listState[type]?.page || 1) => {
    const state = listState[type];
    const form = panel?.querySelector(`[data-oeu-filter-form="${type}"]`);
    const status = panel?.querySelector(`[data-oeu-list-status="${type}"]`);
    if (!state || !form) return;
    state.controller?.abort();
    state.controller = new AbortController();
    const params = new URLSearchParams({ action: 'list', type, page: String(page), page_size: String(state.pageSize) });
    new FormData(form).forEach((value, key) => {
      const clean = String(value ?? '').trim();
      if (clean !== '') params.set(key, clean);
    });
    setMessage(status, 'Loading...');
    try {
      const response = await fetch(`${endpoint}?${params}`, { credentials: 'same-origin', signal: state.controller.signal });
      const result = await response.json();
      if (!response.ok || result?.status !== 'ok') throw new Error(result?.message || 'Could not load records.');
      renderList(type, result.data || {});
    } catch (error) {
      if (error?.name === 'AbortError') return;
      setMessage(status, error?.message || 'Could not load records.', true);
    }
  };
  const refreshListsAndFilters = async (types, resetPage = false) => {
    await Promise.all(types.map((type) => loadList(type, resetPage ? 1 : listState[type].page)));
    await Promise.allSettled(types.map((type) => loadFilterOptions(type, true)));
  };
  const activatePane = (type) => {
    panel?.querySelectorAll('[data-oeu-pane-trigger]').forEach((button) => {
      const active = button.getAttribute('data-oeu-pane-trigger') === type;
      button.classList.toggle('active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    panel?.querySelectorAll('[data-oeu-pane]').forEach((pane) => {
      const active = pane.getAttribute('data-oeu-pane') === type;
      pane.classList.toggle('active', active);
      pane.hidden = !active;
    });
    if (listState[type]) {
      if (!listState[type].filterOptionsLoaded) {
        void loadFilterOptions(type).catch((error) => {
          setMessage(panel?.querySelector(`[data-oeu-list-status="${type}"]`), error?.message || 'Could not load filter options.', true);
        });
      }
      if (!listState[type].loaded) void loadList(type, 1);
    }
  };
  panel?.querySelectorAll('[data-oeu-pane-trigger]').forEach((button) => button.addEventListener('click', () => activatePane(button.getAttribute('data-oeu-pane-trigger'))));
  panel?.querySelectorAll('[data-oeu-filter-form]').forEach((form) => {
    const type = form.getAttribute('data-oeu-filter-form');
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      void loadList(type, 1);
    });
    form.addEventListener('reset', () => setTimeout(() => void loadList(type, 1), 0));
  });
  panel?.querySelectorAll('[data-oeu-pagination]').forEach((pagination) => {
    const type = pagination.getAttribute('data-oeu-pagination');
    pagination.addEventListener('click', (event) => {
      const action = event.target.closest('[data-oeu-page-action]')?.getAttribute('data-oeu-page-action');
      if (!action) return;
      const delta = action === 'next' ? 1 : -1;
      void loadList(type, Math.max(1, Math.min(listState[type].pages, listState[type].page + delta)));
    });
    pagination.querySelector('[data-oeu-page-size]')?.addEventListener('change', (event) => {
      listState[type].pageSize = Number(event.target.value || 50);
      void loadList(type, 1);
    });
  });

  if (conflictFieldsContainer) {
    employeeFields.forEach(([name, label]) => {
      const field = createElement('label', 'field');
      field.appendChild(createElement('span', '', label));
      const input = document.createElement('input');
      input.name = name;
      input.autocomplete = 'off';
      field.appendChild(input);
      conflictFieldsContainer.appendChild(field);
    });
  }
  const closeConflictModal = () => {
    conflictModal?.classList.add('hidden');
    conflictModal?.setAttribute('aria-hidden', 'true');
  };
  const openConflictModal = (record) => {
    if (!conflictModal || !record) return;
    conflictIdInput.value = String(record.id || '');
    employeeFields.forEach(([name]) => {
      const input = conflictFieldsContainer?.querySelector(`[name="${name}"]`);
      if (input) input.value = String(record[name] ?? '');
    });
    setMessage(conflictModalMessage, 'Change the incorrect value so this employee becomes unique.');
    conflictModal.classList.remove('hidden');
    conflictModal.setAttribute('aria-hidden', 'false');
  };
  document.querySelectorAll('[data-close-oeu-conflict-modal]').forEach((button) => button.addEventListener('click', closeConflictModal));
  panel?.addEventListener('click', async (event) => {
    const editId = event.target.closest('[data-oeu-conflict-edit]')?.getAttribute('data-oeu-conflict-edit');
    if (editId) {
      openConflictModal(listState.conflicts.rowsById.get(String(editId)));
      return;
    }
    const archiveId = event.target.closest('[data-oeu-conflict-archive]')?.getAttribute('data-oeu-conflict-archive');
    if (!archiveId) return;
    const record = listState.conflicts.rowsById.get(String(archiveId));
    const name = `${record?.first_name || ''} ${record?.last_name || ''}`.trim() || `DB ID ${archiveId}`;
    if (!window.confirm(`Move ${name} out of active OEU and into OEU Quit?`)) return;
    try {
      const result = await postJson({ action: 'archive_conflict', id: Number(archiveId) });
      updateStats(result.stats);
      await refreshListsAndFilters(['active', 'conflicts', 'quit']);
    } catch (error) {
      window.alert(error?.message || 'Could not move the employee to OEU Quit.');
    }
  });
  conflictSaveButton?.addEventListener('click', async () => {
    const user = Object.fromEntries(employeeFields.map(([name]) => [name, conflictFieldsContainer?.querySelector(`[name="${name}"]`)?.value || '']));
    conflictSaveButton.disabled = true;
    setMessage(conflictModalMessage, 'Saving changes...');
    try {
      const result = await postJson({ action: 'edit_conflict', id: Number(conflictIdInput.value || 0), user });
      updateStats(result.stats);
      closeConflictModal();
      await refreshListsAndFilters(['active', 'conflicts']);
    } catch (error) {
      setMessage(conflictModalMessage, error?.message || 'Could not update the employee.', true);
    } finally {
      conflictSaveButton.disabled = false;
    }
  });
  void loadFilterOptions('active').catch((error) => {
    setMessage(panel?.querySelector('[data-oeu-list-status="active"]'), error?.message || 'Could not load filter options.', true);
  });
  void loadList('active', 1);

  const closeModal = () => {
    modal?.classList.add('hidden');
    modal?.setAttribute('aria-hidden', 'true');
  };
  const openModal = () => {
    modal?.classList.remove('hidden');
    modal?.setAttribute('aria-hidden', 'false');
  };
  const normalizeHeader = (value) => String(value ?? '').trim().toLowerCase().replace(/[-_]/g, ' ');
  const suggestedIndex = (aliases) => {
    const normalizedAliases = aliases.map(normalizeHeader);
    return headers.findIndex((header) => normalizedAliases.includes(normalizeHeader(header)));
  };
  const fillSelect = (select, preferred) => {
    if (!select) return;
    select.replaceChildren();
    headers.forEach((header, index) => {
      const option = document.createElement('option');
      option.value = String(index);
      option.textContent = String(header || `Column ${index + 1}`);
      select.appendChild(option);
    });
    select.value = String(preferred >= 0 ? preferred : 0);
  };
  pickButton?.addEventListener('click', () => fileInput?.click());
  fileInput?.addEventListener('change', async () => {
    const file = fileInput.files?.[0];
    if (!file) return;
    fileName.textContent = file.name;
    setMessage(message, 'در حال خواندن فایل...');
    try {
      if (!window.XLSX) throw new Error('Spreadsheet reader is unavailable.');
      const workbook = window.XLSX.read(await file.arrayBuffer(), { type: 'array' });
      const worksheet = workbook.Sheets[workbook.SheetNames[0]];
      rows = window.XLSX.utils.sheet_to_json(worksheet, { header: 1, defval: '', raw: false });
      headers = Array.from(rows[0] || [], (value) => String(value ?? '').trim());
      if (!headers.length) throw new Error('The file has no header row.');
      setMessage(message, `${Math.max(0, rows.length - 1)} ردیف آماده تطبیق است.`);
    } catch (error) {
      rows = [];
      headers = [];
      setMessage(message, error?.message || 'خواندن فایل با خطا مواجه شد.', true);
    }
  });

  mapButton?.addEventListener('click', () => {
    if (!rows.length || !headers.length) {
      setMessage(message, 'ابتدا یک فایل Excel یا CSV معتبر انتخاب کنید.', true);
      return;
    }
    fillSelect(selects.workId, suggestedIndex(['work id', 'workid', 'username', 'شناسه کاری', 'کد پرسنلی']));
    fillSelect(selects.firstName, suggestedIndex(['first name', 'firstname', 'name', 'نام']));
    fillSelect(selects.lastName, suggestedIndex(['last name', 'lastname', 'family', 'surname', 'نام خانوادگی']));
    fillSelect(selects.nationalId, suggestedIndex(['national id', 'nationalid', 'کد ملی', 'شماره ملی']));
    fillSelect(selects.phoneNumber, suggestedIndex(['phone number', 'phone', 'mobile', 'شماره تلفن', 'شماره موبایل']));
    fillSelect(selects.deputy, suggestedIndex(['deputy', 'deputy office', 'معاونت']));
    fillSelect(selects.generalDepartment, suggestedIndex(['general department', 'directorate general', 'اداره کل']));
    fillSelect(selects.department, suggestedIndex(['department', 'office', 'اداره']));
    fillSelect(selects.gender, suggestedIndex(['gender', 'sex', 'جنسیت']));
    fillSelect(selects.postalLevel, suggestedIndex(['postal level', 'job level', 'position level', 'سطح پستی']));
    setMessage(modalMessage, '');
    openModal();
  });

  document.querySelectorAll('[data-close-org-users-modal]').forEach((button) => button.addEventListener('click', closeModal));
  saveButton?.addEventListener('click', async () => {
    const indexes = Object.fromEntries(Object.entries(selects).map(([key, select]) => [key, Number(select?.value ?? -1)]));
    if (Object.values(indexes).some((index) => !Number.isInteger(index) || index < 0 || index >= headers.length)) {
      setMessage(modalMessage, 'همه ستون‌ها را انتخاب کنید.', true);
      return;
    }
    const mappedRows = rows.slice(1).map((row, index) => ({
      workId: row[indexes.workId],
      firstName: row[indexes.firstName],
      lastName: row[indexes.lastName],
      nationalId: row[indexes.nationalId],
      phoneNumber: row[indexes.phoneNumber],
      deputy: row[indexes.deputy],
      generalDepartment: row[indexes.generalDepartment],
      department: row[indexes.department],
      gender: row[indexes.gender],
      postalLevel: row[indexes.postalLevel],
      sourceRow: index + 2
    })).filter((row) => Object.entries(row).some(([key, value]) => key !== 'sourceRow' && String(value ?? '').trim() !== ''));
    if (!mappedRows.length) {
      setMessage(modalMessage, 'فایل انتخاب‌شده هیچ ردیف کاربری ندارد.', true);
      return;
    }
    saveButton.disabled = true;
    setMessage(modalMessage, 'در حال ذخیره کاربران در پایگاه داده...');
    try {
      const previewResponse = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'preview', csrf: csrfToken, rows: mappedRows })
      });
      const previewResult = await previewResponse.json();
      if (!previewResponse.ok || previewResult?.status !== 'ok') {
        throw new Error(previewResult?.message || 'Could not preview the SAP synchronization.');
      }
      const preview = previewResult.summary || {};
      const confirmed = window.confirm([
        'SAP synchronization preview',
        '',
        `New employees: ${preview.added ?? 0}`,
        `Changed employees: ${preview.updated ?? 0}`,
        `Unchanged employees: ${preview.unchanged ?? 0}`,
        `Move to OEU Quit: ${preview.quit ?? 0}`,
        `Duplicate active rows consolidated: ${preview.deduplicated ?? 0}`,
        `Final active employees: ${preview.final_active ?? 0}`,
        '',
        'Continue with this synchronization?'
      ].join('\n'));
      if (!confirmed) {
        setMessage(modalMessage, 'Synchronization cancelled. No database data was changed.');
        return;
      }
      setMessage(modalMessage, 'Synchronizing organizational users with SAP data...');
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save', confirmSync: true, csrf: csrfToken, rows: mappedRows })
      });
      const result = await response.json();
      if (!response.ok || result?.status !== 'ok') throw new Error(result?.message || 'ذخیره فایل انجام نشد.');
      updateStats(result.stats);
      document.getElementById('org-users-updated').textContent = result.stats?.updated_at ? `آخرین ورود اطلاعات: ${result.stats.updated_at}` : '';
      document.getElementById('org-users-download')?.classList.remove('hidden');
      const completedSummary = result.summary || {};
      closeModal();
      setMessage(message, `SAP synchronization complete: ${completedSummary.added ?? 0} added, ${completedSummary.updated ?? 0} updated, ${completedSummary.quit ?? 0} moved to OEU Quit.`);
      await refreshListsAndFilters(['active', 'conflicts', 'quit'], true);
    } catch (error) {
      setMessage(modalMessage, error?.message || 'ذخیره فایل با خطا مواجه شد.', true);
    } finally {
      saveButton.disabled = false;
    }
  });
})();
</script>
