<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../api/lib/common.php';
require_once __DIR__ . '/../../api/lib/egm-registry.php';
require_once __DIR__ . '/../../api/lib/egm-instance-storage.php';
$campaignRedirectsFile = __DIR__ . '/../../api/lib/campaign-redirects.php';
if (egmDbIsFile($campaignRedirectsFile)) {
  require_once $campaignRedirectsFile;
}
require_once __DIR__ . '/useractivitylogs/activity-logger.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/invitees_csv_safety.php';
require_once __DIR__ . '/prize_inventory_store.php';
require_once __DIR__ . '/prize_levels_store.php';
require_once __DIR__ . '/pot_service.php';
$egmStoreSessionUser = requireTabPermissionFromSession('event-guest-manager', true);
egmSecurityGetCsrfToken();

header('Content-Type: application/json; charset=utf-8');

$baseDir = __DIR__;
$prizesFile = $baseDir . DIRECTORY_SEPARATOR . 'EGM Prizes.json';
$prizeLevelsFile = $baseDir . DIRECTORY_SEPARATOR . 'EGM Prize Levels.json';
$settingsFile = $baseDir . DIRECTORY_SEPARATOR . 'Setting.json';
$legacySettingsFile = $baseDir . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'store.json';
$inviteesMappedFile = $baseDir . DIRECTORY_SEPARATOR . 'EGM Event' . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
$inviteesMapFile = $baseDir . DIRECTORY_SEPARATOR . 'EGM Event' . DIRECTORY_SEPARATOR . 'EGM Mapped.json';

function egmStoreProjectRoot(): string
{
  return dirname(__DIR__, 2);
}

function egmStoreDatabase(): ?PDO
{
  static $resolved = false;
  static $pdo = null;
  if ($resolved) {
    return $pdo instanceof PDO ? $pdo : null;
  }
  $resolved = true;
  $config = loadConfig(egmStoreProjectRoot() . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php');
  $pdo = connectDatabase($config);
  if ($pdo instanceof PDO) {
    ensureEgmRegistryTable($pdo);
  }
  return $pdo instanceof PDO ? $pdo : null;
}

function egmStoreCampaignLinkTarget(): string
{
  return '/mini%20apps/Event%20Guest%20Manager/index.php';
}

function egmStoreCampaignRedirectsReady(): bool
{
  return function_exists('campaignRedirectsNormalizePath')
    && function_exists('campaignRedirectsPathError')
    && function_exists('campaignRedirectsFind')
    && function_exists('campaignRedirectsList')
    && function_exists('campaignRedirectsSaveList')
    && function_exists('campaignRedirectsTypeForTarget')
    && function_exists('campaignRedirectsTypeLabel');
}

function egmStoreRequireCampaignRedirects(): void
{
  global $campaignRedirectsFile;
  if (!egmStoreCampaignRedirectsReady() && is_string($campaignRedirectsFile ?? null) && egmDbIsFile($campaignRedirectsFile)) {
    require_once $campaignRedirectsFile;
  }
  if (egmStoreCampaignRedirectsReady()) {
    return;
  }
  http_response_code(503);
  echo json_encode([
    'status' => 'error',
    'message' => 'Linker Service is not available on this server. Missing api/lib/campaign-redirects.php.'
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

function egmStoreCampaignLinkResponse(string $path, ?array $redirect = null): array
{
  egmStoreRequireCampaignRedirects();
  $normalizedPath = campaignRedirectsNormalizePath($path);
  $target = egmStoreCampaignLinkTarget();
  $targetType = campaignRedirectsTypeForTarget($target);
  $existing = is_array($redirect) ? $redirect : campaignRedirectsFind($normalizedPath);
  $existingTarget = is_array($existing) ? (string)($existing['target'] ?? '') : '';
  $ownedByEventGuestManager = $existingTarget !== '' && $existingTarget === $target;
  $existingType = is_array($existing)
    ? (string)($existing['redirect_type'] ?? campaignRedirectsTypeForTarget((string)($existing['target'] ?? '')))
    : '';

  return [
    'path' => $normalizedPath,
    'campaign_url' => '/campaigns/' . $normalizedPath,
    'target' => $target,
    'redirect_type' => $targetType,
    'redirect_type_label' => campaignRedirectsTypeLabel($targetType),
    'available' => $existing === null,
    'owned_by_task_club' => $ownedByEventGuestManager,
    'can_create' => $existing === null,
    'existing' => $existing === null ? null : [
      'path' => (string)($existing['path'] ?? $normalizedPath),
      'campaign_url' => '/campaigns/' . (string)($existing['path'] ?? $normalizedPath),
      'target' => (string)($existing['target'] ?? ''),
      'redirect_type' => $existingType,
      'redirect_type_label' => campaignRedirectsTypeLabel($existingType),
      'status_code' => (int)($existing['status_code'] ?? 302),
      'created_at' => (string)($existing['created_at'] ?? ''),
      'updated_at' => (string)($existing['updated_at'] ?? '')
    ]
  ];
}

function egmStoreNormalizeMissionCode(string $value): string
{
  $code = str_replace(["\r", "\n", "\t"], ' ', trim($value));
  $code = preg_replace('/\s+/u', '-', $code);
  if (!is_string($code)) {
    $code = '';
  }
  $code = preg_replace('/[^A-Za-z0-9._-]+/', '-', $code);
  if (!is_string($code)) {
    $code = '';
  }
  $code = trim($code, ".-\t\n\r\0\x0B");
  if ($code === '') {
    return '';
  }
  $code = substr($code, 0, 80);
  $code = trim($code, ".-\t\n\r\0\x0B");
  $reserved = [
    'generate', 'con', 'prn', 'aux', 'nul',
    'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
    'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9'
  ];
  return in_array(strtolower($code), $reserved, true) ? '' : $code;
}

function egmStoreMissionContext(string $baseDir): array
{
  $missionDir = realpath($baseDir);
  $missionsRoot = realpath(dirname($baseDir));
  if (!is_string($missionDir) || !is_string($missionsRoot) || basename($missionsRoot) !== 'EGMs') {
    return ['isMission' => false];
  }
  $folder = basename($missionDir);
  if ($folder === '' || $folder === 'generate') {
    return ['isMission' => false];
  }
  $directory = 'mini apps/EGMs/' . $folder;
  $pdo = egmStoreDatabase();
  $registry = $pdo instanceof PDO ? findEgmRegistryByDirectory($pdo, $directory) : null;
  if (!is_array($registry)) {
    return ['isMission' => false];
  }
  return [
    'isMission' => true,
    'code' => (string)$registry['code'],
    'name' => (string)$registry['name'],
    'folder' => $folder,
    'missionDir' => $missionDir,
    'missionsRoot' => $missionsRoot,
    'webPath' => 'mini%20apps/EGMs/' . rawurlencode($folder),
    'directory' => $directory
  ];
}

function egmStorePatchMissionLinkStrings(string $missionDir, string $oldFolder, string $newFolder): void
{
  $oldWebPath = 'mini%20apps/EGMs/' . rawurlencode($oldFolder);
  $newWebPath = 'mini%20apps/EGMs/' . rawurlencode($newFolder);
  $oldDirectory = 'mini apps/EGMs/' . $oldFolder;
  $newDirectory = 'mini apps/EGMs/' . $newFolder;
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($missionDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  foreach ($iterator as $item) {
    if (!$item->isFile()) {
      continue;
    }
    $path = $item->getPathname();
    $name = $item->getFilename();
    if (!preg_match('/\.(php|js|css|json|htaccess)$/i', $name) && $name !== '.htaccess') {
      continue;
    }
    $content = egmDbFileGetContents($path);
    if (!is_string($content)) {
      continue;
    }
    $patched = str_replace([$oldWebPath, $oldDirectory], [$newWebPath, $newDirectory], $content);
    if ($patched !== $content) {
      egmDbFilePutContents($path, $patched, LOCK_EX);
    }
  }
}

function egmStoreSyncMissionMetadataName(string $baseDir, string $eventName): bool
{
  $context = egmStoreMissionContext($baseDir);
  if (empty($context['isMission'])) {
    return true;
  }
  $pdo = egmStoreDatabase();
  $code = (string)($context['code'] ?? '');
  $directory = (string)($context['directory'] ?? '');
  if (!$pdo instanceof PDO || !updateEgmRegistry($pdo, $code, trim($eventName), $directory)) {
    return false;
  }
  try {
    egmInstanceWriteData($pdo, $code, 'metadata', [
      'code' => $code,
      'name' => trim($eventName),
      'directory' => $directory
    ]);
  } catch (Throwable $error) {
    error_log('Failed to update EGM instance metadata: ' . $error->getMessage());
    return false;
  }
  return true;
}

function egmStoreReadScopedSettings(string $baseDir, string $settingsFile, ?string $legacySettingsFile = null): array
{
  $fileSettings = readSettingsFile($settingsFile, $legacySettingsFile);
  $context = egmStoreMissionContext($baseDir);
  $pdo = egmStoreDatabase();
  if (empty($context['isMission']) || !$pdo instanceof PDO) {
    return $fileSettings;
  }
  $code = (string)($context['code'] ?? '');
  try {
    $databaseSettings = egmInstanceReadData($pdo, $code, 'settings');
    if (is_array($databaseSettings)) {
      return $databaseSettings;
    }
    egmInstanceWriteData($pdo, $code, 'settings', $fileSettings);
  } catch (Throwable $error) {
    error_log('Failed to read EGM instance settings: ' . $error->getMessage());
  }
  return $fileSettings;
}

function egmStoreWriteScopedSettings(string $baseDir, array $settings): bool
{
  $context = egmStoreMissionContext($baseDir);
  if (empty($context['isMission'])) {
    return true;
  }
  $pdo = egmStoreDatabase();
  if (!$pdo instanceof PDO) {
    return false;
  }
  try {
    egmInstanceWriteData($pdo, (string)($context['code'] ?? ''), 'settings', $settings);
    return true;
  } catch (Throwable $error) {
    error_log('Failed to write EGM instance settings: ' . $error->getMessage());
    return false;
  }
}

function readJsonFile($path, $fallback) {
  if (!egmDbIsFile($path)) {
    return $fallback;
  }
  $content = egmDbFileGetContents($path);
  if ($content === false) {
    return $fallback;
  }
  $data = json_decode($content, true);
  return $data === null ? $fallback : $data;
}

function writeJsonFile($path, $data) {
  $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($encoded === false) {
    return false;
  }
  return egmDbFilePutContents($path, $encoded, LOCK_EX) !== false;
}

function normalizeSettingsFilePayload($data): array
{
  if (!is_array($data)) {
    return [];
  }
  return is_array($data['settings'] ?? null) ? $data['settings'] : $data;
}

function readSettingsFile(string $path, ?string $legacyPath = null): array
{
  $settings = normalizeSettingsFilePayload(readJsonFile($path, []));
  if ($settings !== [] || egmDbIsFile($path)) {
    return $settings;
  }
  if (is_string($legacyPath) && $legacyPath !== '') {
    return normalizeSettingsFilePayload(readJsonFile($legacyPath, []));
  }
  return [];
}

function normalizeLandingSettings($value): array
{
  $source = is_array($value) ? $value : [];
  $sections = [];
  $rawSections = is_array($source['sections'] ?? null) ? $source['sections'] : [];
  foreach ($rawSections as $index => $rawSection) {
    if (!is_array($rawSection)) {
      continue;
    }
    $title = trim((string)($rawSection['title'] ?? ''));
    $text = trim(str_replace(["\r\n", "\r"], "\n", (string)($rawSection['text'] ?? ($rawSection['html'] ?? ''))));
    if ($title === '' && $text === '') {
      continue;
    }
    $id = trim((string)($rawSection['id'] ?? ''));
    if ($id === '') {
      $id = 'landing_section_' . ((int)$index + 1);
    }
    $sections[] = [
      'id' => preg_replace('/[^A-Za-z0-9._-]+/', '_', $id) ?: ('landing_section_' . ((int)$index + 1)),
      'title' => $title,
      'text' => $text
    ];
  }
  return [
    'title' => trim((string)($source['title'] ?? '')),
    'subtitle' => trim(str_replace(["\r\n", "\r"], "\n", (string)($source['subtitle'] ?? ''))),
    'sections' => array_values($sections)
  ];
}

function defaultLandingSettings(): array
{
  return [
    'title' => "مسابقه «به‌دست آوردیم»\nبه دنیای دستاوردها خوش آمدید…",
    'subtitle' => 'در این مسابقه، هر محتوا فقط یک روایت نیست؛ یک فرصت برای ساختن امتیاز و نزدیک‌تر شدن به کارت‌های جایزه است.',
    'sections' => [
      [
        'id' => 'how-to-join',
        'title' => 'چگونه شرکت کنیم؟',
        'text' => "<ul>\n  <li>ویدیوها و پست‌های هر بخش را با دقت ببینید.</li>\n  <li>به سوالات مسابقه پاسخ دهید.</li>\n  <li>امتیاز جمع کنید و جایگاه خود را ارتقا دهید.</li>\n  <li>هرچه امتیاز بیشتری کسب کنید، شانس شما برای باز کردن کارت‌های جایزه بیشتر می‌شود.</li>\n</ul>"
      ],
      [
        'id' => 'scoring-system',
        'title' => 'سیستم امتیازدهی',
        'text' => "برای پاسخ دادن به هر چالش، ۲ روز فرصت طلایی در نظر گرفته شده است. اگر در این مدت به سوالات پاسخ دهید، می‌توانید امتیاز کامل آن چالش را دریافت کنید.\n\nدر هر چالش، ۳ سوال از شما پرسیده می‌شود و هر پاسخ صحیح، ۱۰ امتیاز دارد.\n\nدر صورتی که در فرصت طلایی به سوالات پاسخ ندهید، همچنان می‌توانید در چالش شرکت کنید؛ اما برای هر پاسخ صحیح، تنها ۵ امتیاز دریافت خواهید کرد."
      ],
      [
        'id' => 'questions-plan',
        'title' => 'طرح سوالات',
        'text' => "سوالات هر چالش با دقت و به‌صورت ریزبینانه، فقط از ویدیوی مربوط به «به دست آوردیم» معاونت‌ها طراحی می‌شود.\n\nپس قبل از شروع هر چالش، حتما ویدیوی «به دست آوردیم» آن معاونت را از طریق کانال ارتباطات کارکنان همراه اول با دقت مشاهده کنید."
      ],
      [
        'id' => 'prizes',
        'title' => 'نحوه دریافت جوایز',
        'text' => 'در این مسابقه، شما می‌توانید ۳ کارت اعتباری دریافت کنید. همچنین اگر به تمام سوالات در فرصت طلایی پاسخ صحیح بدهید، وارد قرعه‌کشی ویژه «به دست آوردیم» خواهید شد.'
      ],
      [
        'id' => 'ready',
        'title' => 'آماده‌ای؟',
        'text' => "ویدیوها را با دقت دنبال کنید، به سوالات درست پاسخ دهید و شانس خود را برای رسیدن به کارت‌های جایزه افزایش دهید.\n\nبه دست آوردیم… و حالا نوبت شماست."
      ],
      [
        'id' => 'account-info',
        'title' => 'ورود و اطلاعات حساب',
        'text' => 'نام کاربری و رمز عبور اختصاصی هر فرد از طریق سرشماره <b>8919</b> به شماره تلفن همراه ثبت‌شده در سازمان پیامک می‌شود.اطلاعات ورود کاملاً محرمانه و شخصی است و استفاده مشترک از حساب کاربری مجاز نیست.'
      ],
      [
        'id' => 'support',
        'title' => 'پشتیبانی',
        'text' => "در صورت وجود سوال یا ابهام، از طریق روبیکا با آیدی زیر با همکاران پشتیبان در ارتباط باشید.\n<b>@ero_admin</b>"
      ]
    ]
  ];
}

function readCsvFileRows(string $path): array
{
  if (egmInviteesCsvIsManagedPath($path)) {
    return egmInviteesCsvReadRowsForUpdate($path);
  }
  if (!egmDbIsFile($path)) {
    return [];
  }
  $rows = [];
  $handle = egmDbFopen($path, 'r');
  if ($handle === false) {
    return [];
  }
  if (!flock($handle, LOCK_SH)) {
    fclose($handle);
    return [];
  }
  while (($row = fgetcsv($handle)) !== false) {
    $rows[] = is_array($row) ? $row : [];
  }
  flock($handle, LOCK_UN);
  fclose($handle);
  return $rows;
}

function writeCsvFileRows(string $path, array $rows): bool
{
  if (egmInviteesCsvIsManagedPath($path)) {
    return egmInviteesCsvCommitRows($path, $rows);
  }
  $dir = dirname($path);
  if ($dir !== '' && !is_dir($dir) && !(mkdir($dir, 0777, true) || is_dir($dir))) {
    return false;
  }
  $handle = egmDbFopen($path, 'c+');
  if ($handle === false) {
    return false;
  }
  if (!flock($handle, LOCK_EX)) {
    fclose($handle);
    return false;
  }
  if (!ftruncate($handle, 0) || fseek($handle, 0) !== 0) {
    flock($handle, LOCK_UN);
    fclose($handle);
    return false;
  }
  foreach ($rows as $row) {
    if (fputcsv($handle, is_array($row) ? $row : []) === false) {
      flock($handle, LOCK_UN);
      fclose($handle);
      return false;
    }
  }
  fflush($handle);
  flock($handle, LOCK_UN);
  fclose($handle);
  return true;
}

function readInviteesMappingConfig(string $path): array
{
  if (!egmDbIsFile($path)) {
    return [];
  }
  $content = egmDbFileGetContents($path);
  if ($content === false) {
    return [];
  }
  $data = json_decode($content, true);
  return is_array($data) ? $data : [];
}

function normalizeUnicodeDigits(string $value): string
{
  return strtr($value, [
    '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
    '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
    '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'
  ]);
}

function normalizeLookupToken(string $value): string
{
  $normalized = normalizeUnicodeDigits($value);
  $normalized = preg_replace('/\s+/u', '', $normalized);
  if (!is_string($normalized)) {
    $normalized = '';
  }
  $normalized = trim($normalized);
  if ($normalized === '') {
    return '';
  }
  if (function_exists('mb_strtolower')) {
    return mb_strtolower($normalized, 'UTF-8');
  }
  return strtolower($normalized);
}

function normalizeHeaderToken(string $value): string
{
  $token = normalizeLookupToken($value);
  return str_replace(['-', '_'], ' ', $token);
}

function findHeaderIndexByNames(array $header, array $names): int
{
  $targets = [];
  foreach ($names as $name) {
    if (!is_scalar($name)) {
      continue;
    }
    $token = normalizeHeaderToken((string)$name);
    if ($token !== '') {
      $targets[$token] = true;
    }
  }
  if (!$targets) {
    return -1;
  }
  foreach ($header as $index => $value) {
    $token = normalizeHeaderToken((string)$value);
    if ($token !== '' && isset($targets[$token])) {
      return (int)$index;
    }
  }
  return -1;
}

function resolveMappedColumnIndex(array $header, array $mapping, array $mappingKeys, array $fallbackNames): int
{
  foreach ($mappingKeys as $mappingKey) {
    $mappedIndex = $mapping[(string)$mappingKey] ?? null;
    if (is_numeric($mappedIndex)) {
      $index = (int)$mappedIndex;
      if ($index >= 0 && isset($header[$index])) {
        return $index;
      }
    }
  }
  return findHeaderIndexByNames($header, $fallbackNames);
}

function normalizeRowsWidth(array &$rows, int $width): void
{
  if ($width <= 0) {
    return;
  }
  foreach ($rows as $index => $row) {
    if (!is_array($row)) {
      $rows[$index] = array_fill(0, $width, '');
      continue;
    }
    if (count($row) < $width) {
      $rows[$index] = array_pad($row, $width, '');
      continue;
    }
    if (count($row) > $width) {
      $rows[$index] = array_slice($row, 0, $width);
    }
  }
}

function ensureAdminColumn(array &$rows): int
{
  if (!$rows || !is_array($rows[0])) {
    return -1;
  }
  $header = $rows[0];
  $existingIndex = findHeaderIndexByNames($header, ['Admin', 'EGM Admin', 'is admin', 'ادمین']);
  if ($existingIndex >= 0) {
    normalizeRowsWidth($rows, count($header));
    return $existingIndex;
  }

  $header[] = 'Admin';
  $rows[0] = $header;
  normalizeRowsWidth($rows, count($header));
  return count($header) - 1;
}

function readAdminColumnIndex(array $rows): int
{
  if (!$rows || !is_array($rows[0])) {
    return -1;
  }
  return findHeaderIndexByNames($rows[0], ['Admin', 'EGM Admin', 'is admin', 'ادمین']);
}

function isAdminCellValue($value): bool
{
  if (is_bool($value)) {
    return $value;
  }
  if (is_numeric($value)) {
    return ((float)$value) > 0;
  }
  $token = normalizeLookupToken((string)$value);
  if ($token === '') {
    return false;
  }
  return in_array($token, ['1', 'true', 'yes', 'admin', 'ادمین'], true);
}

function resolveInviteeRowByWorkId(array $rows, int $workIdIndex, string $workId): int
{
  if ($workIdIndex < 0) {
    return -1;
  }
  $needle = normalizeLookupToken($workId);
  if ($needle === '') {
    return -1;
  }
  for ($i = 1; $i < count($rows); $i += 1) {
    $row = is_array($rows[$i] ?? null) ? $rows[$i] : [];
    $candidate = normalizeLookupToken((string)($row[$workIdIndex] ?? ''));
    if ($candidate !== '' && $candidate === $needle) {
      return $i;
    }
  }
  return -1;
}

function buildInviteeAdminItem(
  array $header,
  array $mapping,
  array $row,
  int $workIdIndex,
  int $adminIndex
): array {
  $firstNameIndex = resolveMappedColumnIndex(
    $header,
    $mapping,
    ['firstName', 'first_name', 'name', 'first name'],
    ['First Name', 'first name', 'name', 'نام']
  );
  $lastNameIndex = resolveMappedColumnIndex(
    $header,
    $mapping,
    ['lastName', 'last_name', 'family', 'surname', 'last name'],
    ['Last Name', 'last name', 'family', 'surname', 'نام خانوادگی']
  );
  $phoneIndex = resolveMappedColumnIndex(
    $header,
    $mapping,
    ['phoneNumber', 'phone', 'mobile', 'phone_number'],
    ['Phone Number', 'phone', 'mobile', 'شماره موبایل', 'شماره تلفن']
  );
  $nationalIdIndex = resolveMappedColumnIndex(
    $header,
    $mapping,
    ['nationalId', 'national_id', 'national id'],
    ['National ID', 'national id', 'کد ملی']
  );

  $firstName = trim((string)($row[$firstNameIndex] ?? ''));
  $lastName = trim((string)($row[$lastNameIndex] ?? ''));
  $workId = trim((string)($row[$workIdIndex] ?? ''));
  $phone = trim((string)($row[$phoneIndex] ?? ''));
  $nationalId = trim((string)($row[$nationalIdIndex] ?? ''));
  $fullName = trim($firstName . ' ' . $lastName);

  return [
    'workId' => $workId,
    'firstName' => $firstName,
    'lastName' => $lastName,
    'fullName' => $fullName,
    'phone' => $phone,
    'nationalId' => $nationalId,
    'isAdmin' => $adminIndex >= 0 ? isAdminCellValue($row[$adminIndex] ?? '') : false
  ];
}

function collectAssignedAdmins(string $inviteesMappedFile, string $inviteesMapFile): array
{
  $rows = readCsvFileRows($inviteesMappedFile);
  if (!$rows || !is_array($rows[0])) {
    return [];
  }
  $header = $rows[0];
  $mapping = readInviteesMappingConfig($inviteesMapFile);
  $workIdIndex = resolveMappedColumnIndex(
    $header,
    $mapping,
    ['workId', 'username'],
    ['Work ID', 'work id', 'workid', 'username', 'کد پرسنلی', 'کد ملی']
  );
  if ($workIdIndex < 0) {
    return [];
  }
  $adminIndex = readAdminColumnIndex($rows);
  if ($adminIndex < 0) {
    return [];
  }

  $items = [];
  for ($i = 1; $i < count($rows); $i += 1) {
    $row = is_array($rows[$i] ?? null) ? $rows[$i] : [];
    if (!isAdminCellValue($row[$adminIndex] ?? '')) {
      continue;
    }
    $item = buildInviteeAdminItem($header, $mapping, $row, $workIdIndex, $adminIndex);
    if ($item['workId'] === '') {
      continue;
    }
    $items[] = $item;
  }
  usort($items, static function ($left, $right) {
    $leftName = trim(((string)($left['firstName'] ?? '')) . ' ' . ((string)($left['lastName'] ?? '')));
    $rightName = trim(((string)($right['firstName'] ?? '')) . ' ' . ((string)($right['lastName'] ?? '')));
    if ($leftName === $rightName) {
      return strcmp((string)($left['workId'] ?? ''), (string)($right['workId'] ?? ''));
    }
    return strcmp($leftName, $rightName);
  });
  return $items;
}

function normalizePositiveNumber($value) {
  if (!is_scalar($value)) {
    return 0;
  }
  $normalized = preg_replace('/[,\s]+/', '', (string)$value);
  if (!is_string($normalized) || $normalized === '' || !is_numeric($normalized)) {
    return 0;
  }
  $parsed = (float)$normalized;
  if (!is_finite($parsed) || $parsed < 0) {
    return 0;
  }
  return $parsed;
}

function normalizePositiveInt($value) {
  if (!is_scalar($value)) {
    return 0;
  }
  $parsed = (int)$value;
  return $parsed > 0 ? $parsed : 0;
}

function normalizeLevelName($value, $fallback = '') {
  $name = trim((string)$value);
  if ($name === '') {
    $name = trim((string)$fallback);
  }
  return $name;
}

function normalizeLevelType($value) {
  $token = strtolower(trim((string)$value));
  if (in_array($token, ['out_of_value', 'pot'], true)) {
    return $token;
  }
  return 'value_sum';
}

function normalizePotSettings($value): array {
  $source = is_array($value) ? $value : [];
  $title = trim((string)($source['title'] ?? ''));
  $prizeName = trim((string)($source['prizeName'] ?? ($source['prize_name'] ?? '')));
  $winnerLimit = (int)($source['winnerLimit'] ?? ($source['winner_limit'] ?? 1));
  return [
    'title' => function_exists('mb_substr') ? mb_substr($title, 0, 160, 'UTF-8') : substr($title, 0, 160),
    'winnerLimit' => max(1, min(1000, $winnerLimit)),
    'prizeName' => function_exists('mb_substr') ? mb_substr($prizeName, 0, 160, 'UTF-8') : substr($prizeName, 0, 160),
    'locked' => !empty($source['locked'])
  ];
}

function normalizeHexColor($value, $fallback = '') {
  $color = strtoupper(trim((string)$value));
  if ($color === '' && is_string($fallback) && $fallback !== '') {
    $color = strtoupper(trim($fallback));
  }
  if (preg_match('/^#[0-9A-F]{6}$/', $color)) {
    return $color;
  }
  return '';
}

function normalizeRewardPrizeDisplaySettings($value): array
{
  $source = is_array($value) ? $value : [];
  $hiddenText = trim((string)($source['hiddenText'] ?? ($source['hidden_text'] ?? '')));
  if (function_exists('mb_substr')) {
    $hiddenText = mb_substr($hiddenText, 0, 160, 'UTF-8');
  } else {
    $hiddenText = substr($hiddenText, 0, 160);
  }
  return [
    'nonValuePrizeDescribe' => !empty($source['nonValuePrizeDescribe']) || !empty($source['non_value_prize_describe']),
    'showPrize' => array_key_exists('showPrize', $source) || array_key_exists('show_prize', $source)
      ? (bool)($source['showPrize'] ?? $source['show_prize'])
      : true,
    'hiddenText' => $hiddenText
  ];
}

function egmStoreNormalizeBool($value): bool
{
  if (is_bool($value)) return $value;
  if (is_int($value) || is_float($value)) return (int)$value === 1;
  return in_array(strtolower(trim((string)$value)), ['1', 'true', 'on', 'yes'], true);
}

function egmStoreParseNonnegativeInt($value): ?int
{
  if (is_int($value)) return $value >= 0 ? $value : null;
  if (is_float($value)) {
    return is_finite($value) && $value >= 0 && floor($value) === $value && $value <= PHP_INT_MAX
      ? (int)$value
      : null;
  }
  if (!is_string($value)) return null;
  $token = trim($value);
  if ($token === '' || preg_match('/^[0-9]+$/D', $token) !== 1) return null;
  $parsed = filter_var($token, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
  return is_int($parsed) ? $parsed : null;
}

function egmStoreParseNonnegativeNumber($value): ?float
{
  if (!is_scalar($value)) return null;
  $token = preg_replace('/[,\s]+/', '', (string)$value);
  if (!is_string($token) || $token === '' || !is_numeric($token)) return null;
  $parsed = (float)$token;
  return is_finite($parsed) && $parsed >= 0 ? $parsed : null;
}

function egmStoreStablePrizeLevelId(string $name, int $score, int $index): string
{
  return 'lvl_' . substr(hash('sha256', $name . "\0" . $score . "\0" . $index), 0, 20);
}

function requireTcStoreCsrf(?array $payload = null): void
{
  $csrfToken = egmSecurityReadCsrfFromRequest($payload, 'csrf');
  if (!egmSecurityIsValidCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
  }
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

$egmStoreMainActions = [
  'get_prizes',
  'save_prizes',
  'get_prize_levels',
  'save_prize_levels',
  'get_reward_guide',
  'save_reward_guide',
  'get_reward_prize_display',
  'save_reward_prize_display',
  'search_invitee_admin',
  'get_admin_assignments',
  'set_admin_assignment'
];

if (in_array($action, $egmStoreMainActions, true) && !userHasPermissionId($egmStoreSessionUser, 'event-guest-manager:main')) {
  denyPanelAccess(403, 'You do not have permission to access this Event Guest Manager section.', true);
}

if (in_array($action, ['get_settings', 'save_settings', 'get_mission_link', 'save_mission_link'], true)) {
  $canMain = userHasPermissionId($egmStoreSessionUser, 'event-guest-manager:main');
  $canEventStyle = userHasPermissionId($egmStoreSessionUser, 'event-guest-manager:event-style');
  if (!$canMain && !$canEventStyle) {
    denyPanelAccess(403, 'You do not have permission to access this Event Guest Manager section.', true);
  }
}

if (in_array($action, ['check_campaign_link', 'create_campaign_link'], true) && !userHasPermissionId($egmStoreSessionUser, 'event-guest-manager:linker')) {
  denyPanelAccess(403, 'You do not have permission to access this Event Guest Manager section.', true);
}

if ($action === 'check_campaign_link') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
  }
  $payload = json_decode(egmDbFileGetContents('php://input'), true);
  if (!is_array($payload)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
    exit;
  }
  requireTcStoreCsrf($payload);
  egmStoreRequireCampaignRedirects();
  $path = campaignRedirectsNormalizePath($payload['path'] ?? '');
  $pathError = campaignRedirectsPathError($path);
  if ($pathError !== '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => $pathError], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $redirect = campaignRedirectsFind($path);
  echo json_encode([
    'status' => 'ok',
    'data' => egmStoreCampaignLinkResponse($path, $redirect)
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($action === 'create_campaign_link') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
  }
  $payload = json_decode(egmDbFileGetContents('php://input'), true);
  if (!is_array($payload)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
    exit;
  }
  requireTcStoreCsrf($payload);
  egmStoreRequireCampaignRedirects();
  $path = campaignRedirectsNormalizePath($payload['path'] ?? '');
  $pathError = campaignRedirectsPathError($path);
  if ($pathError !== '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => $pathError], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $target = egmStoreCampaignLinkTarget();
  $redirects = campaignRedirectsList();
  $map = [];
  foreach ($redirects as $redirect) {
    $redirectPath = (string)($redirect['path'] ?? '');
    if ($redirectPath !== '') {
      $map[$redirectPath] = $redirect;
    }
  }
  $existing = $map[$path] ?? null;
  if (is_array($existing)) {
    if ((string)($existing['target'] ?? '') === $target) {
      echo json_encode([
        'status' => 'ok',
        'message' => 'This campaign already points to Event Guest Manager.',
        'data' => egmStoreCampaignLinkResponse($path, $existing)
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }
    http_response_code(409);
    echo json_encode([
      'status' => 'error',
      'message' => 'This /campaigns link is already occupied in Linker Service.',
      'data' => egmStoreCampaignLinkResponse($path, $existing)
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  $now = gmdate('c');
  $map[$path] = [
    'path' => $path,
    'target' => $target,
    'status_code' => 302,
    'created_at' => $now,
    'updated_at' => $now
  ];
  if (!campaignRedirectsSaveList(array_values($map))) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to save campaign redirect.'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $saved = campaignRedirectsFind($path);
  echo json_encode([
    'status' => 'ok',
    'message' => 'Event Guest Manager campaign redirect created.',
    'data' => egmStoreCampaignLinkResponse($path, $saved)
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($action === 'get_prizes') {
  $prizes = egmPrizeInventoryReadSnapshot($prizesFile);
  if (!is_array($prizes)) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Prize inventory is unavailable; no data was changed.']);
    exit;
  }
  echo json_encode([
    'status' => 'ok',
    'data' => $prizes,
    'version' => egmPrizeInventoryVersion($prizes)
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'save_prizes') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
  }
  $payload = json_decode(egmDbFileGetContents('php://input'), true);
  if (!is_array($payload)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
    exit;
  }
  requireTcStoreCsrf($payload);
  $prizes = $payload['prizes'] ?? [];
  $expectedVersion = trim((string)($payload['version'] ?? ''));
  if (!is_array($prizes)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid prizes.']);
    exit;
  }
  $normalized = [];
  $seenNames = [];
  $seenIds = [];
  foreach ($prizes as $prize) {
    if (!is_array($prize)) {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'Every prize row must be a valid object; no data was saved.']);
      exit;
    }
    foreach (['id', 'name', 'onWheelName', 'quantity', 'last', 'value', 'isFake'] as $field) {
      if (array_key_exists($field, $prize) && $prize[$field] !== null && !is_scalar($prize[$field])) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Prize fields must contain simple values; no data was saved.']);
        exit;
      }
    }
    $name = trim((string)($prize['name'] ?? ''));
    if ($name === '') {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'Every prize row must have a name; no data was saved.']);
      exit;
    }
    $nameKey = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    if (isset($seenNames[$nameKey])) {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'Prize names must be unique.']);
      exit;
    }
    $seenNames[$nameKey] = true;
    $id = trim((string)($prize['id'] ?? ''));
    if ($id !== '' && (!preg_match('/^[A-Za-z0-9._-]{1,96}$/', $id) || isset($seenIds[$id]))) {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'Prize IDs must be valid and unique.']);
      exit;
    }
    if ($id !== '') $seenIds[$id] = true;
    $onWheelName = trim((string)($prize['onWheelName'] ?? $name));
    if ($onWheelName === '') {
      $onWheelName = $name;
    }
    $quantity = egmStoreParseNonnegativeInt($prize['quantity'] ?? 0);
    $last = egmStoreParseNonnegativeInt($prize['last'] ?? $quantity);
    $value = egmStoreParseNonnegativeNumber($prize['value'] ?? 0);
    if ($quantity === null || $last === null || $value === null) {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'Prize quantity, remaining stock, and value must be valid non-negative numbers; no data was saved.']);
      exit;
    }
    $isFake = egmStoreNormalizeBool($prize['isFake'] ?? false);
    if ($last > $quantity) {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'Remaining prize stock cannot exceed total quantity; no data was saved.']);
      exit;
    }
    $normalized[] = [
      'id' => $id,
      'name' => $name,
      'onWheelName' => $onWheelName,
      'quantity' => $quantity,
      'last' => $last,
      'value' => $value,
      'isFake' => $isFake
    ];
  }
  if (!$normalized && ($payload['confirmEmptyInventory'] ?? null) !== true) {
    http_response_code(422);
    echo json_encode([
      'status' => 'error',
      'code' => 'empty_prize_inventory_confirmation_required',
      'message' => 'Refusing to erase the entire prize inventory without explicit confirmation; no data was saved.'
    ]);
    exit;
  }
  $actualVersion = null;
  $saved = null;
  $failureReason = null;
  if (!egmPrizeInventoryReplaceIfVersion($prizesFile, $normalized, $expectedVersion, $actualVersion, $saved, $failureReason)) {
    $isConflict = $failureReason === 'conflict';
    $hasPendingAwards = $failureReason === 'pending_awards';
    http_response_code($isConflict || $hasPendingAwards ? 409 : 503);
    echo json_encode([
      'status' => 'error',
      'code' => $hasPendingAwards ? 'prize_inventory_pending_awards' : ($isConflict ? 'prize_inventory_conflict' : 'prize_inventory_unavailable'),
      'message' => $hasPendingAwards
        ? 'A prize has an unfinished award transaction and cannot be deleted yet. Reload and try again; no data was overwritten.'
        : ($isConflict
          ? 'Prize inventory changed since it was loaded. Reload before saving; no data was overwritten.'
          : 'Prize inventory could not be saved safely. No data was overwritten.'),
      'version' => $actualVersion
    ]);
    exit;
  }
  echo json_encode([
    'status' => 'ok',
    'data' => $saved,
    'version' => $actualVersion
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'get_prize_levels') {
  $snapshot = egmPrizeLevelsReadSnapshot($prizeLevelsFile);
  if (empty($snapshot['ok'])) {
    http_response_code(503);
    echo json_encode([
      'status' => 'error',
      'code' => 'prize_levels_unavailable',
      'message' => 'Prize levels are unavailable or damaged; no data was changed.'
    ]);
    exit;
  }
  echo json_encode([
    'status' => 'ok',
    'data' => array_values((array)($snapshot['records'] ?? [])),
    'version' => (string)($snapshot['version'] ?? '')
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'save_prize_levels') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
  }
  $payload = json_decode(egmDbFileGetContents('php://input'), true);
  if (!is_array($payload)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
    exit;
  }
  requireTcStoreCsrf($payload);
  $levels = $payload['levels'] ?? [];
  $expectedVersion = trim((string)($payload['version'] ?? ''));
  if (!is_array($levels)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid levels.']);
    exit;
  }

  $normalized = [];
  $seenIds = [];
  $seenScores = [];
  foreach (array_values($levels) as $levelIndex => $item) {
    if (!is_array($item)) {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'Every prize level must be a valid object; no data was saved.']);
      exit;
    }
    $rawScore = $item['score'] ?? $item['levelScore'] ?? $item['level_score'] ?? null;
    $score = egmPrizeLevelsParsePositiveInt($rawScore);
    if ($score === null) {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'Every prize level must have a positive whole-number score; no data was saved.']);
      exit;
    }
    if (isset($seenScores[$score])) {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'Prize level scores must be unique.']);
      exit;
    }
    $seenScores[$score] = true;
    $rawName = $item['name'] ?? $item['levelName'] ?? $item['level_name'] ?? $item['label'] ?? null;
    if (!is_scalar($rawName)) {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'Every prize level must have a valid name; no data was saved.']);
      exit;
    }
    $name = normalizeLevelName($rawName, 'Level ' . $score);
    if ($name === '') {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'Every prize level must have a name; no data was saved.']);
      exit;
    }
    $rawType = $item['type'] ?? $item['levelType'] ?? $item['level_type'] ?? 'value_sum';
    if (!is_scalar($rawType)) {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'Prize level type is invalid; no data was saved.']);
      exit;
    }
    $type = strtolower(trim((string)$rawType));
    if (!in_array($type, ['value_sum', 'out_of_value', 'pot'], true)) {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'Prize level type is invalid; no data was saved.']);
      exit;
    }
    foreach (['id', 'description', 'describe', 'infoText', 'info_text', 'buttonText', 'button_text'] as $field) {
      if (array_key_exists($field, $item) && $item[$field] !== null && !is_scalar($item[$field])) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Prize level text fields are invalid; no data was saved.']);
        exit;
      }
    }
    $id = trim((string)($item['id'] ?? ''));
    if ($id === '') {
      $id = egmStoreStablePrizeLevelId($name, $score, (int)$levelIndex);
    }
    if (!preg_match('/^[A-Za-z0-9._-]{1,96}$/', $id) || isset($seenIds[$id])) {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'Prize level IDs must be valid and unique.']);
      exit;
    }
    $seenIds[$id] = true;
    $potReason = null;
    $potSettings = egmPrizeLevelsNormalizePotSettings($item['potSettings'] ?? ($item['pot_settings'] ?? []), $potReason);
    if (!is_array($potSettings)) {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'Pot settings are invalid; no data was saved.']);
      exit;
    }
    if ($type === 'pot' && $potSettings['locked'] && $potSettings['prizeName'] === '') {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'Prize name is required before locking a Pot.']);
      exit;
    }
    if ($type === 'pot' && $potSettings['locked'] && count(egmPotReadWinners($id)) < 1) {
      http_response_code(422);
      echo json_encode(['status' => 'error', 'message' => 'At least one confirmed winner is required before locking a Pot.']);
      exit;
    }
    $normalized[] = [
      'id' => $id,
      'name' => $name,
      'type' => $type,
      'score' => $score,
      'description' => trim((string)($item['description'] ?? ($item['describe'] ?? ($item['infoText'] ?? ($item['info_text'] ?? ''))))),
      'buttonText' => trim((string)($item['buttonText'] ?? ($item['button_text'] ?? ''))),
      'potSettings' => $potSettings
    ];
  }

  usort($normalized, static function ($a, $b) {
    return (int)($a['score'] ?? 0) <=> (int)($b['score'] ?? 0);
  });

  if (!$normalized && ($payload['confirmEmptyLevels'] ?? null) !== true) {
    http_response_code(422);
    echo json_encode([
      'status' => 'error',
      'code' => 'empty_prize_levels_confirmation_required',
      'message' => 'Refusing to erase every prize level without explicit confirmation; no data was saved.'
    ]);
    exit;
  }

  $result = egmPrizeLevelsReplaceIfVersion($prizeLevelsFile, $normalized, $expectedVersion);
  if (empty($result['ok'])) {
    $reason = (string)($result['reason'] ?? 'write_failed');
    $isConflict = $reason === 'conflict';
    $isValidationError = in_array($reason, [
      'malformed_row', 'invalid_score', 'duplicate_score', 'missing_level_name',
      'invalid_id', 'duplicate_id', 'invalid_type', 'invalid_text_field', 'invalid_pot_settings'
    ], true);
    http_response_code($isConflict ? 409 : ($isValidationError ? 422 : 503));
    echo json_encode([
      'status' => 'error',
      'code' => $isConflict ? 'prize_levels_conflict' : 'prize_levels_save_failed',
      'message' => $isConflict
        ? 'Prize levels changed since they were loaded. Reload before saving; no data was overwritten.'
        : 'Prize levels could not be saved safely. No data was overwritten.',
      'version' => (string)($result['version'] ?? '')
    ]);
    exit;
  }
  echo json_encode([
    'status' => 'ok',
    'data' => array_values((array)($result['records'] ?? [])),
    'version' => (string)($result['version'] ?? '')
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'get_reward_guide') {
  $settings = egmStoreReadScopedSettings($baseDir, $settingsFile, $legacySettingsFile);
  $guide = is_array($settings['rewardGuide'] ?? null) ? $settings['rewardGuide'] : [];
  echo json_encode([
    'status' => 'ok',
    'data' => [
      'title' => trim((string)($guide['title'] ?? 'راهنمای دریافت جایزه')),
      'text' => trim((string)($guide['text'] ?? ''))
    ]
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'save_reward_guide') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
  }
  $payload = json_decode(egmDbFileGetContents('php://input'), true);
  if (!is_array($payload)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
    exit;
  }
  requireTcStoreCsrf($payload);
  $guide = is_array($payload['rewardGuide'] ?? null) ? $payload['rewardGuide'] : [];
  $settings = egmStoreReadScopedSettings($baseDir, $settingsFile, $legacySettingsFile);
  $settings['rewardGuide'] = [
    'title' => trim((string)($guide['title'] ?? 'راهنمای دریافت جایزه')),
    'text' => trim((string)($guide['text'] ?? ''))
  ];
  if (!writeJsonFile($settingsFile, $settings)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save reward guide.']);
    exit;
  }
  if (!egmStoreWriteScopedSettings($baseDir, $settings)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Reward guide was saved to file, but failed to save to the EGM database table.']);
    exit;
  }
  echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'get_reward_prize_display') {
  $settings = egmStoreReadScopedSettings($baseDir, $settingsFile, $legacySettingsFile);
  echo json_encode([
    'status' => 'ok',
    'data' => normalizeRewardPrizeDisplaySettings($settings['rewardPrizeDisplay'] ?? [])
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'save_reward_prize_display') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
  }
  $payload = json_decode(egmDbFileGetContents('php://input'), true);
  if (!is_array($payload)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
    exit;
  }
  requireTcStoreCsrf($payload);
  $settings = egmStoreReadScopedSettings($baseDir, $settingsFile, $legacySettingsFile);
  $settings['rewardPrizeDisplay'] = normalizeRewardPrizeDisplaySettings($payload['rewardPrizeDisplay'] ?? []);
  if (!writeJsonFile($settingsFile, $settings)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save advanced prize setting.']);
    exit;
  }
  if (!egmStoreWriteScopedSettings($baseDir, $settings)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Prize settings were saved to file, but failed to save to the EGM database table.']);
    exit;
  }
  echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'get_mission_link') {
  $context = egmStoreMissionContext($baseDir);
  echo json_encode([
    'status' => 'ok',
    'data' => [
      'isMission' => !empty($context['isMission']),
      'code' => (string)($context['folder'] ?? ''),
      'path' => (string)($context['webPath'] ?? ''),
      'appUrl' => !empty($context['webPath']) ? ((string)$context['webPath'] . '/EGMM.php') : ''
    ]
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($action === 'save_mission_link') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
  }
  $payload = json_decode(egmDbFileGetContents('php://input'), true);
  if (!is_array($payload)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
    exit;
  }
  requireTcStoreCsrf($payload);
  $context = egmStoreMissionContext($baseDir);
  if (empty($context['isMission'])) {
    echo json_encode(['status' => 'error', 'message' => 'This Event Guest Manager link can only be changed for generated clubs.'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $currentCode = (string)($context['folder'] ?? '');
  $nextCode = egmStoreNormalizeMissionCode((string)($payload['code'] ?? ''));
  if ($nextCode === '') {
    echo json_encode(['status' => 'error', 'message' => 'Enter a valid link code using letters, numbers, dash, underscore, or dot.'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if (strcasecmp($nextCode, $currentCode) === 0) {
    echo json_encode([
      'status' => 'ok',
      'message' => 'Club link is unchanged.',
      'data' => [
        'code' => $currentCode,
        'path' => (string)$context['webPath'],
        'appUrl' => (string)$context['webPath'] . '/EGMM.php'
      ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
  $missionsRoot = (string)$context['missionsRoot'];
  $targetDir = $missionsRoot . DIRECTORY_SEPARATOR . $nextCode;
  foreach (new DirectoryIterator($missionsRoot) as $entry) {
    if ($entry->isDot()) {
      continue;
    }
    if (strcasecmp($entry->getFilename(), $nextCode) === 0) {
      echo json_encode(['status' => 'error', 'message' => 'This Event Guest Manager link is already occupied. Choose another code.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
  if (egmDbFileExists($targetDir)) {
    echo json_encode(['status' => 'error', 'message' => 'This Event Guest Manager link is already occupied. Choose another code.'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $missionDir = (string)$context['missionDir'];
  if (!egmDbRename($missionDir, $targetDir)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to rename the club folder.'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $pdo = egmStoreDatabase();
  $uniqueCode = (string)($context['code'] ?? '');
  $registryName = trim((string)($context['name'] ?? $nextCode)) ?: $nextCode;
  $nextDirectory = 'mini apps/EGMs/' . $nextCode;
  if (!$pdo instanceof PDO || !updateEgmRegistry($pdo, $uniqueCode, $registryName, $nextDirectory)) {
    @egmDbRename($targetDir, $missionDir);
    echo json_encode(['status' => 'error', 'message' => 'Failed to update the EGM database registry.'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  egmStorePatchMissionLinkStrings($targetDir, $currentCode, $nextCode);

  $nextWebPath = 'mini%20apps/EGMs/' . rawurlencode($nextCode);
  echo json_encode([
    'status' => 'ok',
    'message' => 'Club link updated.',
    'data' => [
      'code' => $nextCode,
      'path' => $nextWebPath,
      'appUrl' => $nextWebPath . '/EGMM.php'
    ]
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($action === 'get_settings') {
  $defaults = [
    'active' => false,
    'duration' => false,
    'maintenanceMode' => false,
    'eventAccessLocked' => false,
    'startDate' => '',
    'startTime' => '',
    'endDate' => '',
    'endTime' => '',
    'hint' => 'شانس خودت رو امتحان کن و جایزه ببر',
    'hintHtml' => '',
    'hintAlign' => 'right',
    'eventName' => '',
    'eventLogo' => '',
    'eventColors' => [
      'secondary' => '#2F8FFF',
      'highlight' => '#20C997',
      'accentSoft' => '#FFB347'
    ],
    'landing' => [
      'title' => '',
      'subtitle' => '',
      'sections' => []
    ],
    'rewardPrizeDisplay' => [
      'nonValuePrizeDescribe' => false,
      'showPrize' => true,
      'hiddenText' => ''
    ]
  ];
  $stored = egmStoreReadScopedSettings($baseDir, $settingsFile, $legacySettingsFile);
  $settings = array_merge($defaults, is_array($stored) ? $stored : []);
  $settings['maintenanceMode'] = (bool)($settings['maintenanceMode'] ?? false);
  $settings['eventAccessLocked'] = (bool)($settings['eventAccessLocked'] ?? false);
  $storedColors = is_array($settings['eventColors'] ?? null) ? $settings['eventColors'] : [];
  $settings['eventName'] = is_string($settings['eventName'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $settings['eventName'])) : '';
  $settings['eventLogo'] = trim((string)($settings['eventLogo'] ?? ''));
  $settings['eventColors'] = [
    'secondary' => normalizeHexColor($storedColors['secondary'] ?? '', $defaults['eventColors']['secondary']),
    'highlight' => normalizeHexColor($storedColors['highlight'] ?? '', $defaults['eventColors']['highlight']),
    'accentSoft' => normalizeHexColor($storedColors['accentSoft'] ?? '', $defaults['eventColors']['accentSoft'])
  ];
  $settings['landing'] = normalizeLandingSettings($settings['landing'] ?? []);
  $settings['rewardPrizeDisplay'] = normalizeRewardPrizeDisplaySettings($settings['rewardPrizeDisplay'] ?? []);
  echo json_encode(['status' => 'ok', 'data' => $settings], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'save_settings') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
  }
  $payload = json_decode(egmDbFileGetContents('php://input'), true);
  if (!is_array($payload)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
    exit;
  }
  requireTcStoreCsrf($payload);
  $incomingSettings = $payload['settings'] ?? [];
  if (!is_array($incomingSettings)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid settings.']);
    exit;
  }
  $storedSettings = egmStoreReadScopedSettings($baseDir, $settingsFile, $legacySettingsFile);
  $settings = array_merge(is_array($storedSettings) ? $storedSettings : [], $incomingSettings);
  $settings['duration'] = egmStoreNormalizeBool($settings['duration'] ?? false);
  $settings['active'] = $settings['duration'] ? false : egmStoreNormalizeBool($settings['active'] ?? false);
  $settings['hint'] = is_string($settings['hint'] ?? null) ? trim($settings['hint']) : '';
  $settings['hintHtml'] = is_string($settings['hintHtml'] ?? null) ? trim($settings['hintHtml']) : '';
  $settings['hintAlign'] = is_string($settings['hintAlign'] ?? null) ? trim($settings['hintAlign']) : 'right';
  $settings['maintenanceMode'] = egmStoreNormalizeBool($settings['maintenanceMode'] ?? false);
  $settings['eventAccessLocked'] = egmStoreNormalizeBool($settings['eventAccessLocked'] ?? false);
  $incomingColors = is_array($settings['eventColors'] ?? null) ? $settings['eventColors'] : [];
  $eventName = is_string($settings['eventName'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $settings['eventName'])) : '';
  $settings['eventName'] = function_exists('mb_substr') ? mb_substr($eventName, 0, 120, 'UTF-8') : substr($eventName, 0, 120);
  $settings['eventLogo'] = is_string($settings['eventLogo'] ?? null) ? trim($settings['eventLogo']) : '';
  $settings['eventColors'] = [
    'secondary' => normalizeHexColor($incomingColors['secondary'] ?? '', '#2F8FFF') ?: '#2F8FFF',
    'highlight' => normalizeHexColor($incomingColors['highlight'] ?? '', '#20C997') ?: '#20C997',
    'accentSoft' => normalizeHexColor($incomingColors['accentSoft'] ?? '', '#FFB347') ?: '#FFB347'
  ];
  if (array_key_exists('landing', $settings)) {
    $settings['landing'] = normalizeLandingSettings($settings['landing']);
  }
  $settings['rewardPrizeDisplay'] = normalizeRewardPrizeDisplaySettings($settings['rewardPrizeDisplay'] ?? []);
  if (!writeJsonFile($settingsFile, $settings)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save settings.']);
    exit;
  }
  if (!egmStoreWriteScopedSettings($baseDir, $settings)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Settings were saved to file, but failed to save to the EGM database table.']);
    exit;
  }
  if (!egmStoreSyncMissionMetadataName($baseDir, (string)$settings['eventName'])) {
    echo json_encode(['status' => 'error', 'message' => 'Settings saved, but failed to update the Event Guest Manager tab name.']);
    exit;
  }
  echo json_encode(['status' => 'ok']);
  exit;
}

if ($action === 'search_invitee_admin') {
  $workId = trim((string)($_GET['work_id'] ?? $_POST['work_id'] ?? ''));
  if ($workId === '') {
    echo json_encode(['status' => 'error', 'message' => 'Please enter Work ID.']);
    exit;
  }

  $rows = readCsvFileRows($inviteesMappedFile);
  if (!$rows || !is_array($rows[0])) {
    echo json_encode(['status' => 'error', 'message' => 'Invitees mapped CSV is not ready.']);
    exit;
  }
  $header = $rows[0];
  $mapping = readInviteesMappingConfig($inviteesMapFile);
  $workIdIndex = resolveMappedColumnIndex(
    $header,
    $mapping,
    ['workId', 'username'],
    ['Work ID', 'work id', 'workid', 'username', 'کد پرسنلی', 'کد ملی']
  );
  if ($workIdIndex < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Work ID column was not found in Invitees mapped CSV.']);
    exit;
  }
  $rowIndex = resolveInviteeRowByWorkId($rows, $workIdIndex, $workId);
  if ($rowIndex < 0) {
    echo json_encode(['status' => 'error', 'message' => 'No user was found with this Work ID.']);
    exit;
  }
  $adminIndex = readAdminColumnIndex($rows);
  $item = buildInviteeAdminItem(
    $header,
    $mapping,
    is_array($rows[$rowIndex] ?? null) ? $rows[$rowIndex] : [],
    $workIdIndex,
    $adminIndex
  );
  echo json_encode(['status' => 'ok', 'data' => ['invitee' => $item]], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'get_admin_assignments') {
  $admins = collectAssignedAdmins($inviteesMappedFile, $inviteesMapFile);
  echo json_encode(['status' => 'ok', 'data' => ['admins' => $admins]], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'set_admin_assignment') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
  }
  $payload = json_decode(egmDbFileGetContents('php://input'), true);
  if (!is_array($payload)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
    exit;
  }
  requireTcStoreCsrf($payload);

  $workId = trim((string)($payload['workId'] ?? $payload['work_id'] ?? ''));
  $isAdmin = (bool)($payload['isAdmin'] ?? $payload['is_admin'] ?? false);
  if ($workId === '') {
    echo json_encode(['status' => 'error', 'message' => 'Please enter Work ID.']);
    exit;
  }

  $rows = readCsvFileRows($inviteesMappedFile);
  if (!$rows || !is_array($rows[0])) {
    echo json_encode(['status' => 'error', 'message' => 'Invitees mapped CSV is not ready.']);
    exit;
  }
  $header = $rows[0];
  $mapping = readInviteesMappingConfig($inviteesMapFile);
  $workIdIndex = resolveMappedColumnIndex(
    $header,
    $mapping,
    ['workId', 'username'],
    ['Work ID', 'work id', 'workid', 'username', 'کد پرسنلی', 'کد ملی']
  );
  if ($workIdIndex < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Work ID column was not found in Invitees mapped CSV.']);
    exit;
  }
  $rowIndex = resolveInviteeRowByWorkId($rows, $workIdIndex, $workId);
  if ($rowIndex < 0) {
    echo json_encode(['status' => 'error', 'message' => 'No user was found with this Work ID.']);
    exit;
  }

  $adminIndex = ensureAdminColumn($rows);
  if ($adminIndex < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to prepare Admin column.']);
    exit;
  }
  if (!is_array($rows[$rowIndex])) {
    $rows[$rowIndex] = [];
  }
  $headerWidth = count(is_array($rows[0]) ? $rows[0] : []);
  if ($headerWidth > 0 && count($rows[$rowIndex]) < $headerWidth) {
    $rows[$rowIndex] = array_pad($rows[$rowIndex], $headerWidth, '');
  }
  $rows[$rowIndex][$adminIndex] = $isAdmin ? '1' : '';

  if (!writeCsvFileRows($inviteesMappedFile, $rows)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save Admin assignment.']);
    exit;
  }

  $updatedItem = buildInviteeAdminItem(
    is_array($rows[0]) ? $rows[0] : [],
    $mapping,
    is_array($rows[$rowIndex] ?? null) ? $rows[$rowIndex] : [],
    $workIdIndex,
    $adminIndex
  );
  $admins = collectAssignedAdmins($inviteesMappedFile, $inviteesMapFile);
  egmActivityLogUserActivity([
    'level' => 'info',
    'action' => 'taskclub.admin_assignment_changed',
    'entity_type' => 'taskclub_user',
    'entity_id' => $workId,
    'status' => 'success',
    'message' => $isAdmin ? 'Event Guest Manager admin assigned.' : 'Event Guest Manager admin removed.',
    'metadata' => [
      'work_id' => $workId,
      'is_admin' => $isAdmin,
      'full_name' => $updatedItem['fullName'] ?? null
    ],
    'audit' => true
  ]);
  echo json_encode([
    'status' => 'ok',
    'message' => $isAdmin ? 'Admin assigned successfully.' : 'Admin removed successfully.',
    'data' => [
      'invitee' => $updatedItem,
      'admins' => $admins
    ]
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);

