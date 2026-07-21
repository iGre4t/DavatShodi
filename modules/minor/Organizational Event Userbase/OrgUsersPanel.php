<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../../api/lib/common.php';

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

function orgUsersEnsureTable(PDO $pdo): bool
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `organizational_event_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `work_id` VARCHAR(128) NOT NULL DEFAULT '',
  `first_name` VARCHAR(191) NOT NULL DEFAULT '',
  `last_name` VARCHAR(191) NOT NULL DEFAULT '',
  `national_id` VARCHAR(32) NOT NULL DEFAULT '',
  `phone_number` VARCHAR(32) NOT NULL DEFAULT '',
  `deputy` VARCHAR(191) NOT NULL DEFAULT '',
  `general_department` VARCHAR(191) NOT NULL DEFAULT '',
  `department` VARCHAR(191) NOT NULL DEFAULT '',
  `gender` VARCHAR(32) NOT NULL DEFAULT '',
  `postal_level` VARCHAR(64) NOT NULL DEFAULT '',
  `source_row` INT UNSIGNED NOT NULL,
  `imported_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_org_users_work_id` (`work_id`),
  KEY `idx_org_users_national_id` (`national_id`),
  KEY `idx_org_users_phone_number` (`phone_number`),
  KEY `idx_org_users_structure` (`deputy`, `general_department`, `department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    try {
        $pdo->exec($sql);
        $column = $pdo->query("SHOW COLUMNS FROM `organizational_event_users` LIKE 'postal_level'")->fetch();
        if (!$column) {
            $pdo->exec("ALTER TABLE `organizational_event_users` ADD COLUMN `postal_level` VARCHAR(64) NOT NULL DEFAULT '' AFTER `gender`");
        }
        return true;
    } catch (PDOException $error) {
        error_log('Failed to ensure organizational users table: ' . $error->getMessage());
        return false;
    }
}

function orgUsersCleanValue($value, int $maxLength): string
{
    $clean = trim(is_scalar($value) ? (string)$value : '');
    if (function_exists('mb_substr')) {
        return mb_substr($clean, 0, $maxLength, 'UTF-8');
    }
    return substr($clean, 0, $maxLength);
}

function orgUsersNormalizeImportRows($value): array
{
    if (!is_array($value) || count($value) > 100000) {
        return [];
    }
    $normalized = [];
    foreach ($value as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $item = [
            'work_id' => orgUsersCleanValue($row['workId'] ?? '', 128),
            'first_name' => orgUsersCleanValue($row['firstName'] ?? '', 191),
            'last_name' => orgUsersCleanValue($row['lastName'] ?? '', 191),
            'national_id' => orgUsersCleanValue($row['nationalId'] ?? '', 32),
            'phone_number' => orgUsersCleanValue($row['phoneNumber'] ?? '', 32),
            'deputy' => orgUsersCleanValue($row['deputy'] ?? '', 191),
            'general_department' => orgUsersCleanValue($row['generalDepartment'] ?? '', 191),
            'department' => orgUsersCleanValue($row['department'] ?? '', 191),
            'gender' => orgUsersCleanValue($row['gender'] ?? '', 32),
            'postal_level' => orgUsersCleanValue($row['postalLevel'] ?? '', 64),
            'source_row' => max(2, (int)($row['sourceRow'] ?? ($index + 2)))
        ];
        if (count(array_filter(array_slice($item, 0, 10), static fn($field): bool => $field !== '')) > 0) {
            $normalized[] = $item;
        }
    }
    return $normalized;
}

function orgUsersReplaceRows(PDO $pdo, array $rows): bool
{
    $sql = <<<'SQL'
INSERT INTO `organizational_event_users`
  (`work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, `deputy`, `general_department`, `department`, `gender`, `postal_level`, `source_row`)
VALUES
  (:work_id, :first_name, :last_name, :national_id, :phone_number, :deputy, :general_department, :department, :gender, :postal_level, :source_row)
SQL;
    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM `organizational_event_users`');
        $statement = $pdo->prepare($sql);
        foreach ($rows as $row) {
            $statement->execute([
                ':work_id' => $row['work_id'],
                ':first_name' => $row['first_name'],
                ':last_name' => $row['last_name'],
                ':national_id' => $row['national_id'],
                ':phone_number' => $row['phone_number'],
                ':deputy' => $row['deputy'],
                ':general_department' => $row['general_department'],
                ':department' => $row['department'],
                ':gender' => $row['gender'],
                ':postal_level' => $row['postal_level'],
                ':source_row' => $row['source_row']
            ]);
        }
        return $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Failed to replace organizational users: ' . $error->getMessage());
        return false;
    }
}

function orgUsersStats(?PDO $pdo, bool $tableReady): array
{
    if (!$pdo instanceof PDO || !$tableReady) {
        return ['count' => 0, 'updated_at' => '', 'database_ready' => false];
    }
    try {
        $row = $pdo->query('SELECT COUNT(*) AS `count`, MAX(`imported_at`) AS `updated_at` FROM `organizational_event_users`')->fetch();
    } catch (PDOException $error) {
        error_log('Failed to read organizational users stats: ' . $error->getMessage());
        return ['count' => 0, 'updated_at' => '', 'database_ready' => false];
    }
    return [
        'count' => (int)($row['count'] ?? 0),
        'updated_at' => trim((string)($row['updated_at'] ?? '')),
        'database_ready' => true
    ];
}

$orgUsersTableReady = $orgUsersPdo instanceof PDO && orgUsersEnsureTable($orgUsersPdo);

$orgUsersAction = trim((string)($_GET['action'] ?? ''));
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
    if (trim((string)($input['action'] ?? '')) !== 'save') {
        orgUsersJsonResponse(['status' => 'error', 'message' => 'Unsupported action.'], 400);
    }
    if (!$orgUsersPdo instanceof PDO || !$orgUsersTableReady) {
        orgUsersJsonResponse(['status' => 'error', 'message' => 'The organizational users database is unavailable.'], 503);
    }
    $rows = orgUsersNormalizeImportRows($input['rows'] ?? null);
    if (!$rows) {
        orgUsersJsonResponse(['status' => 'error', 'message' => 'The mapped spreadsheet does not contain any user rows.'], 422);
    }
    if (!orgUsersReplaceRows($orgUsersPdo, $rows)) {
        orgUsersJsonResponse(['status' => 'error', 'message' => 'Could not save organizational users to the database.'], 500);
    }
    orgUsersJsonResponse([
        'status' => 'ok',
        'message' => 'Organizational users were imported into the database.',
        'stats' => orgUsersStats($orgUsersPdo, true)
    ]);
}

$orgUsersCurrentStats = orgUsersStats($orgUsersPdo, $orgUsersTableReady);
?>

<div id="org-users-panel" dir="rtl">
  <div class="card">
    <div class="section-header">
      <div>
        <h3>کاربران سازمان</h3>
        <p class="muted">فایل Excel یا CSV را بارگذاری کنید، ستون‌ها را تطبیق دهید و کاربران را در پایگاه داده ذخیره کنید.</p>
      </div>
    </div>
    <div class="form grid two-columns">
      <div class="field">
        <span>فایل کاربران</span>
        <div class="field-block" style="padding:10px;">
          <input id="org-users-file" type="file" accept=".csv,.xls,.xlsx" hidden />
          <div class="field-controls" style="gap:8px; flex-wrap:wrap;">
            <button type="button" class="btn" id="org-users-pick">انتخاب Excel یا CSV</button>
            <span id="org-users-file-name" class="muted">فایلی انتخاب نشده است.</span>
          </div>
        </div>
      </div>
      <div class="field">
        <span>وضعیت پایگاه کاربران</span>
        <div class="field-block" style="padding:10px;">
          <strong id="org-users-count"><?= htmlspecialchars((string)$orgUsersCurrentStats['count'], ENT_QUOTES, 'UTF-8') ?></strong>
          <span class="muted"> کاربر ذخیره‌شده</span>
          <div id="org-users-updated" class="muted"><?php if (empty($orgUsersCurrentStats['database_ready'])): ?>پایگاه داده در دسترس نیست.<?php elseif ($orgUsersCurrentStats['updated_at'] !== ''): ?>آخرین ورود اطلاعات: <?= htmlspecialchars($orgUsersCurrentStats['updated_at'], ENT_QUOTES, 'UTF-8') ?><?php else: ?>هنوز کاربری در پایگاه داده ذخیره نشده است.<?php endif; ?></div>
        </div>
      </div>
      <div class="field full">
        <div class="field-controls" style="gap:8px; flex-wrap:wrap;">
          <button type="button" class="btn primary standard-primary-button" id="org-users-map">تطبیق ستون‌ها و ذخیره</button>
          <?php if (!empty($orgUsersCurrentStats['database_ready']) && $orgUsersCurrentStats['count'] > 0): ?>
            <a class="btn" id="org-users-download" href="modules/minor/Organizational%20Event%20Userbase/OrgUsersPanel.php?action=download">خروجی CSV از پایگاه داده</a>
          <?php else: ?>
            <a class="btn hidden" id="org-users-download" href="modules/minor/Organizational%20Event%20Userbase/OrgUsersPanel.php?action=download">خروجی CSV از پایگاه داده</a>
          <?php endif; ?>
        </div>
        <p id="org-users-message" class="hint" role="status" aria-live="polite"></p>
      </div>
    </div>
  </div>
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
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save', csrf: csrfToken, rows: mappedRows })
      });
      const result = await response.json();
      if (!response.ok || result?.status !== 'ok') throw new Error(result?.message || 'ذخیره فایل انجام نشد.');
      document.getElementById('org-users-count').textContent = String(result.stats?.count ?? 0);
      document.getElementById('org-users-updated').textContent = result.stats?.updated_at ? `آخرین ورود اطلاعات: ${result.stats.updated_at}` : '';
      document.getElementById('org-users-download')?.classList.remove('hidden');
      closeModal();
      setMessage(message, 'کاربران سازمان با موفقیت در پایگاه داده ذخیره شدند.');
    } catch (error) {
      setMessage(modalMessage, error?.message || 'ذخیره فایل با خطا مواجه شد.', true);
    } finally {
      saveButton.disabled = false;
    }
  });
})();
</script>
