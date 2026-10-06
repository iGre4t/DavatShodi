<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../api/lib/common.php';
require_once __DIR__ . '/../../api/lib/egm-registry.php';
require_once __DIR__ . '/../../api/lib/egm-instance-storage.php';
require_once __DIR__ . '/egm-security.php';

$egmCreatorIsJsonRequest = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
requireTabPermissionFromSession('event-guest-manager', $egmCreatorIsJsonRequest);
$egmCreatorCsrfToken = egmSecurityGetCsrfToken();

function egmCreatorProjectRoot(): string
{
  return dirname(__DIR__, 2);
}

function egmCreatorMissionsRoot(): string
{
  return egmCreatorProjectRoot() . DIRECTORY_SEPARATOR . 'mini apps' . DIRECTORY_SEPARATOR . 'EGMs';
}

function egmCreatorGenerateRoot(): string
{
  return egmCreatorMissionsRoot() . DIRECTORY_SEPARATOR . 'generate';
}

function egmCreatorDatabase(): PDO
{
  static $pdo = null;
  if ($pdo instanceof PDO) {
    return $pdo;
  }
  $config = loadConfig(egmCreatorProjectRoot() . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php');
  $pdo = connectDatabase($config);
  if (!$pdo instanceof PDO) {
    throw new RuntimeException('Unable to connect to the EGM registry database.');
  }
  ensureEgmRegistryTable($pdo);
  foreach (listEgmRegistry($pdo) as $record) {
    $code = (string)($record['code'] ?? '');
    if (normalizeEgmInstanceCode($code) === '') {
      continue;
    }
    ensureEgmInstanceTables($pdo, $code);
    egmInstanceWriteData($pdo, $code, 'metadata', [
      'code' => $code,
      'name' => (string)($record['name'] ?? ''),
      'directory' => (string)($record['directory'] ?? '')
    ]);
    if (egmInstanceReadData($pdo, $code, 'settings') === null) {
      $settingsPath = egmCreatorProjectRoot() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, (string)($record['directory'] ?? ''))
        . DIRECTORY_SEPARATOR . 'Setting.json';
      $settingsRaw = egmDbIsFile($settingsPath) ? egmDbFileGetContents($settingsPath) : false;
      $settings = is_string($settingsRaw) ? json_decode($settingsRaw, true) : null;
      if (is_array($settings)) {
        egmInstanceWriteData($pdo, $code, 'settings', $settings);
      }
    }
    if (egmInstanceReadData($pdo, $code, 'periods') === null) {
      $periodsPath = egmCreatorProjectRoot() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, (string)($record['directory'] ?? ''))
        . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'tasks.js';
      $periodsRaw = egmDbIsFile($periodsPath) ? egmDbFileGetContents($periodsPath) : false;
      $periods = [];
      if (is_string($periodsRaw) && preg_match('/window\.EGM_TASKS\s*=\s*(\[.*\])\s*;?\s*$/s', $periodsRaw, $matches) === 1) {
        $decodedPeriods = json_decode((string)$matches[1], true);
        $periods = is_array($decodedPeriods) ? array_values($decodedPeriods) : [];
      }
      egmInstanceWritePeriods($pdo, $code, $periods);
    }
    $storageMode = egmInstanceReadData($pdo, $code, 'storage_mode', null);
    if ((!is_array($storageMode) || ($storageMode['mode'] ?? '') !== 'database_only')
        && egmInstanceReadData($pdo, $code, 'database_sync') === null) {
      $missionDir = egmCreatorProjectRoot() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, (string)($record['directory'] ?? ''));
      if (is_dir($missionDir)) {
        egmInstanceMirrorMissionStorage($pdo, $code, $missionDir);
      }
    }
  }
  return $pdo;
}

function egmCreatorEnsureDirectory(string $path): void
{
  if (is_dir($path)) {
    return;
  }
  if (!mkdir($path, 0777, true) && !is_dir($path)) {
    throw new RuntimeException('Failed to create directory: ' . $path);
  }
}

function egmCreatorWriteJsonFile(string $path, array $payload): void
{
  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($json)) {
    throw new RuntimeException('Failed to encode JSON.');
  }
  egmCreatorEnsureDirectory(dirname($path));
  if (egmDbFilePutContents($path, $json . PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException('Failed to write file: ' . $path);
  }
}

function egmCreatorEnsureGeneratorStorage(): void
{
  egmCreatorEnsureDirectory(egmCreatorMissionsRoot());
  egmCreatorEnsureDirectory(egmCreatorGenerateRoot());

  $htaccessPath = egmCreatorGenerateRoot() . DIRECTORY_SEPARATOR . '.htaccess';
  if (!egmDbIsFile($htaccessPath)) {
    $rules = implode(PHP_EOL, [
      'Options -Indexes',
      '',
      '<IfModule mod_authz_core.c>',
      '  Require all denied',
      '</IfModule>',
      '<IfModule !mod_authz_core.c>',
      '  Order allow,deny',
      '  Deny from all',
      '</IfModule>',
      ''
    ]);
    if (egmDbFilePutContents($htaccessPath, $rules, LOCK_EX) === false) {
      throw new RuntimeException('Failed to write generator access rules.');
    }
  }
}

function egmCreatorNormalizeUniqueCode($value): string
{
  return normalizeEgmSequenceCode($value);
}

function egmCreatorAllocateUniqueCode(): string
{
  return allocateEgmRegistryCode(egmCreatorDatabase());
}

function egmCreatorNormalizeMissionName(string $value): string
{
  $name = str_replace(["\r", "\n", "\t"], ' ', trim($value));
  $name = preg_replace('/\s+/u', ' ', $name);
  if (!is_string($name)) {
    $name = '';
  }
  $name = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]+/u', '-', $name);
  if (!is_string($name)) {
    $name = '';
  }
  $name = trim($name, " .-\t\n\r\0\x0B");
  if ($name === '') {
    return '';
  }
  if (function_exists('mb_substr')) {
    $name = mb_substr($name, 0, 80, 'UTF-8');
  } else {
    $name = substr($name, 0, 80);
  }
  $name = trim($name, " .-\t\n\r\0\x0B");
  $reserved = [
    'generate', 'con', 'prn', 'aux', 'nul',
    'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
    'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9'
  ];
  return in_array(strtolower($name), $reserved, true) ? '' : $name;
}

function egmCreatorMissionWebPath(string $folderName): string
{
  return 'mini%20apps/EGMs/' . rawurlencode($folderName);
}

function egmCreatorMissionDirectoryLabel(string $folderName): string
{
  return 'mini apps/EGMs/' . $folderName;
}

function egmCreatorMissionTabId(string $folderName, string $code = ''): string
{
  $normalizedCode = egmCreatorNormalizeUniqueCode($code);
  if ($normalizedCode !== '') {
    return 'event-guest-manager-mission-' . $normalizedCode;
  }
  return 'event-guest-manager-mission-' . substr(hash('sha256', $folderName), 0, 12);
}

function egmCreatorIsWithinPath(string $path, string $root): bool
{
  $realPath = realpath($path);
  $realRoot = realpath($root);
  if (!is_string($realPath) || !is_string($realRoot)) {
    return false;
  }
  $normalizedPath = str_replace('\\', '/', rtrim($realPath, DIRECTORY_SEPARATOR));
  $normalizedRoot = str_replace('\\', '/', rtrim($realRoot, DIRECTORY_SEPARATOR));
  return $normalizedPath === $normalizedRoot || strpos($normalizedPath, $normalizedRoot . '/') === 0;
}

function egmCreatorRemoveTree(string $path, string $allowedRoot): void
{
  if (!is_dir($path) || !egmCreatorIsWithinPath($path, $allowedRoot)) {
    return;
  }
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach ($iterator as $item) {
    $itemPath = $item->getPathname();
    if ($item->isDir()) {
      @rmdir($itemPath);
    } else {
      @egmDbUnlink($itemPath);
    }
  }
  @rmdir($path);
}

function egmCreatorRelativePath(string $root, string $path): string
{
  $relative = substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
  return str_replace('\\', '/', is_string($relative) ? $relative : '');
}

function egmCreatorShouldCopyRelativePath(string $relativePath, bool $isDir): bool
{
  $relative = trim(str_replace('\\', '/', $relativePath), '/');
  if ($relative === '') {
    return true;
  }
  if ($relative === 'EGMCreator.php' || $relative === 'panel.php' || $relative === 'mission.json') {
    return false;
  }
  if ($relative === 'tasks' || $relative === 'EGM Event' || $relative === 'vendor') {
    return true;
  }
  if ($relative === 'InviteCards') {
    return true;
  }
  if (strpos($relative, 'InviteCards/') === 0) {
    return $relative === 'InviteCards/.htaccess';
  }
  if (strpos($relative, 'tasks/') === 0) {
    return false;
  }
  if (strpos($relative, 'EGM Event/') === 0) {
    return false;
  }
  if (!$isDir && preg_match('/\.json$/i', $relative)) {
    return false;
  }
  return true;
}

function egmCreatorShouldUpdateRelativePath(string $relativePath, bool $isDir): bool
{
  $relative = trim(str_replace('\\', '/', $relativePath), '/');
  if ($relative === '') {
    return true;
  }
  if (in_array($relative, ['EGMCreator.php', 'panel.php', 'mission.json', 'Setting.json'], true)) {
    return false;
  }
  foreach (['tasks', 'EGM Event', 'useractivitylogs/logs'] as $dataPath) {
    if ($relative === $dataPath || strpos($relative, $dataPath . '/') === 0) {
      return false;
    }
  }
  if ($relative === 'InviteCards') {
    return true;
  }
  if (strpos($relative, 'InviteCards/') === 0) {
    return $relative === 'InviteCards/.htaccess';
  }
  if ($relative === 'vendor' || strpos($relative, 'vendor/') === 0) {
    return true;
  }
  if (!$isDir && preg_match('/\.json$/i', $relative)) {
    return false;
  }
  return true;
}

function egmCreatorIsPatchableTextFile(string $relativePath): bool
{
  $relative = trim(str_replace('\\', '/', $relativePath), '/');
  if ($relative === '' || strpos($relative, 'vendor/') === 0) {
    return false;
  }
  $name = basename($relative);
  if ($name === '.htaccess') {
    return true;
  }
  return (bool)preg_match('/\.(php|js|css)$/i', $name);
}

function egmCreatorPatchGeneratedFile(string $path, string $relativePath, string $folderName, string $webPath): void
{
  if (!egmCreatorIsPatchableTextFile($relativePath)) {
    return;
  }
  $content = egmDbFileGetContents($path);
  if (!is_string($content)) {
    throw new RuntimeException('Failed to read copied file: ' . $relativePath);
  }
  $replacements = [
    'mini%20apps/Event%20Guest%20Manager' => $webPath,
    'mini apps/Event Guest Manager' => egmCreatorMissionDirectoryLabel($folderName),
    "__DIR__ . '/../../" => "__DIR__ . '/../../../",
    '__DIR__ . "/../../' => '__DIR__ . "/../../../',
    'dirname(__DIR__, 2)' => 'dirname(__DIR__, 3)',
    "session_name('EGMSESSID');" => "session_name('EGM' . substr(hash('sha256', __DIR__), 0, 12));",
    'return "../../{$trimmed}";' => 'return "../../../{$trimmed}";',
    "src: url('../../style/" => "src: url('../../../style/",
    "url('../../style/" => "url('../../../style/",
    'href="../../style/' => 'href="../../../style/',
    "= '../../style/" => "= '../../../style/"
  ];
  $patched = str_replace(array_keys($replacements), array_values($replacements), $content);
  if ($patched !== $content && egmDbFilePutContents($path, $patched, LOCK_EX) === false) {
    throw new RuntimeException('Failed to patch copied file: ' . $relativePath);
  }
}

function egmCreatorCopyEventGuestManagerTemplate(string $sourceDir, string $targetDir, string $folderName, string $webPath): void
{
  egmCreatorEnsureDirectory($targetDir);
  $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($iterator as $item) {
    $sourcePath = $item->getPathname();
    $relative = egmCreatorRelativePath($sourceDir, $sourcePath);
    if (!egmCreatorShouldCopyRelativePath($relative, $item->isDir())) {
      continue;
    }
    $destination = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if ($item->isDir()) {
      egmCreatorEnsureDirectory($destination);
      continue;
    }
    egmCreatorEnsureDirectory(dirname($destination));
    if (!egmDbCopy($sourcePath, $destination)) {
      throw new RuntimeException('کپی فایل ناموفق بود: ' . $relative);
    }
    egmCreatorPatchGeneratedFile($destination, $relative, $folderName, $webPath);
  }
}

function egmCreatorCopyEventGuestManagerUpdates(string $sourceDir, string $targetDir, string $folderName, string $webPath): void
{
  if (!is_dir($targetDir) || !egmCreatorIsWithinPath($targetDir, egmCreatorMissionsRoot())) {
    throw new RuntimeException('مسیر رویداد معتبر نیست.');
  }
  $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($iterator as $item) {
    $sourcePath = $item->getPathname();
    $relative = egmCreatorRelativePath($sourceDir, $sourcePath);
    if (!egmCreatorShouldUpdateRelativePath($relative, $item->isDir())) {
      continue;
    }
    $destination = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if ($item->isDir()) {
      egmCreatorEnsureDirectory($destination);
      continue;
    }
    egmCreatorEnsureDirectory(dirname($destination));
    if (!egmDbCopy($sourcePath, $destination)) {
      throw new RuntimeException('به‌روزرسانی فایل ناموفق بود: ' . $relative);
    }
    egmCreatorPatchGeneratedFile($destination, $relative, $folderName, $webPath);
  }
}

function egmCreatorInitializeMission(string $targetDir, string $name): void
{
  egmCreatorEnsureDirectory($targetDir . DIRECTORY_SEPARATOR . 'tasks');
  egmCreatorEnsureDirectory($targetDir . DIRECTORY_SEPARATOR . 'EGM Event');

  if (egmDbFilePutContents($targetDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'tasks.js', "window.EGM_TASKS = [];\n", LOCK_EX) === false) {
    throw new RuntimeException('آماده‌سازی بازه‌ها ناموفق بود.');
  }
  egmCreatorWriteJsonFile($targetDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'period-invites.json', [
    'source' => 'oeu',
    'periods' => []
  ]);
  if (egmDbFilePutContents($targetDir . DIRECTORY_SEPARATOR . 'EGM Event' . DIRECTORY_SEPARATOR . 'Answers.csv', "Work ID\n", LOCK_EX) === false) {
    throw new RuntimeException('آماده‌سازی اطلاعات رویداد ناموفق بود.');
  }
  egmCreatorWriteJsonFile($targetDir . DIRECTORY_SEPARATOR . 'Setting.json', [
    'active' => false,
    'duration' => false,
    'maintenanceMode' => false,
    'eventAccessLocked' => false,
    'startDate' => '',
    'startTime' => '',
    'endDate' => '',
    'endTime' => '',
    'eventName' => $name,
    'eventLogo' => '',
    'eventColors' => [
      'secondary' => '#2F8FFF',
      'highlight' => '#20C997',
      'accentSoft' => '#FFB347'
    ],
    'landing' => [
      'title' => $name,
      'subtitle' => '',
      'sections' => []
    ]
  ]);

}

function egmCreatorListMissions(): array
{
  egmCreatorEnsureGeneratorStorage();
  $items = [];
  foreach (listEgmRegistry(egmCreatorDatabase()) as $record) {
    $code = egmCreatorNormalizeUniqueCode($record['code'] ?? '');
    $directory = normalizeEgmRegistryDirectory($record['directory'] ?? '');
    if ($code === '' || $directory === '') {
      continue;
    }
    $folder = basename(str_replace('\\', '/', $directory));
    $missionDir = egmCreatorProjectRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    if (!is_dir($missionDir) || !egmCreatorIsWithinPath($missionDir, egmCreatorMissionsRoot())) {
      continue;
    }
    $name = trim((string)($record['name'] ?? '')) ?: $folder;
    $webPath = egmCreatorMissionWebPath($folder);
    $createdAt = trim((string)($record['created_at'] ?? ''));
    $items[] = [
      'name' => $name,
      'code' => $code,
      'folder' => $folder,
      'tabId' => egmCreatorMissionTabId($folder, $code),
      'directory' => $directory,
      'webPath' => $webPath,
      'panelUrl' => 'panel.php?tab=' . rawurlencode(egmCreatorMissionTabId($folder, $code)),
      'sidebarVisible' => (int)($record['sidebar_visible'] ?? 1) === 1,
      'createdAt' => $createdAt,
      'createdAtLabel' => $createdAt !== '' ? $createdAt : '-'
    ];
  }
  usort($items, static function (array $left, array $right): int {
    $leftCreated = (string)($left['createdAt'] ?? '');
    $rightCreated = (string)($right['createdAt'] ?? '');
    if ($leftCreated !== $rightCreated) {
      return strcmp($rightCreated, $leftCreated);
    }
    return strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
  });
  return $items;
}

function egmCreatorJsonResponse(array $payload, int $statusCode = 200): void
{
  http_response_code($statusCode);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function egmCreatorResolveMissionFolder(string $rawFolder): string
{
  $folderName = egmCreatorNormalizeMissionName($rawFolder);
  if ($folderName === '') {
    throw new InvalidArgumentException('یک رویداد معتبر انتخاب کنید.');
  }
  return $folderName;
}

function egmCreatorCreateMission(string $rawName): array
{
  egmCreatorEnsureGeneratorStorage();
  $folderName = egmCreatorNormalizeMissionName($rawName);
  if ($folderName === '') {
    throw new InvalidArgumentException('نام رویداد را وارد کنید.');
  }

  $missionsRoot = egmCreatorMissionsRoot();
  $targetDir = $missionsRoot . DIRECTORY_SEPARATOR . $folderName;
  if (egmDbFileExists($targetDir)) {
    throw new InvalidArgumentException('رویدادی با این نام پوشه وجود دارد.');
  }

  $buildDir = egmCreatorGenerateRoot() . DIRECTORY_SEPARATOR . '.build-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
  $webPath = egmCreatorMissionWebPath($folderName);
  $code = egmCreatorAllocateUniqueCode();
  $registryInserted = false;
  try {
    egmCreatorCopyEventGuestManagerTemplate(__DIR__, $buildDir, $folderName, $webPath);
    if (!egmDbRename($buildDir, $targetDir)) {
      throw new RuntimeException('ساخت فایل‌های رویداد ناموفق بود.');
    }
    insertEgmRegistry(
      egmCreatorDatabase(),
      $code,
      $folderName,
      egmCreatorMissionDirectoryLabel($folderName)
    );
    $registryInserted = true;
    egmCreatorInitializeMission($targetDir, $folderName);
    $pdo = egmCreatorDatabase();
    ensureEgmInstanceTables($pdo, $code);
    egmInstanceWriteData($pdo, $code, 'metadata', [
      'code' => $code,
      'name' => $folderName,
      'directory' => egmCreatorMissionDirectoryLabel($folderName)
    ]);
    $settingsRaw = egmDbFileGetContents($targetDir . DIRECTORY_SEPARATOR . 'Setting.json');
    $settings = is_string($settingsRaw) ? json_decode($settingsRaw, true) : null;
    if (!is_array($settings)) {
      throw new RuntimeException('بارگذاری تنظیمات اولیه رویداد ناموفق بود.');
    }
    egmInstanceWriteData($pdo, $code, 'settings', $settings);
    egmInstanceWritePeriods($pdo, $code, []);
    egmInstanceWriteData($pdo, $code, 'invitee_mapping', egmDatabaseRuntimeDefaultInviteeMapping());
    egmInstanceWriteData($pdo, $code, 'invitee_source', ['source' => 'oeu', 'updatedAt' => gmdate('c')]);
    egmInstanceWriteData($pdo, $code, 'storage_mode', ['mode' => 'database_only', 'version' => 1]);
  } catch (Throwable $error) {
    if ($registryInserted) {
      try {
        deleteEgmRegistry(egmCreatorDatabase(), $code);
        dropEgmInstanceTables(egmCreatorDatabase(), $code);
      } catch (Throwable $cleanupError) {
        error_log('Failed to clean up EGM database provisioning: ' . $cleanupError->getMessage());
      }
    }
    egmCreatorRemoveTree($buildDir, egmCreatorGenerateRoot());
    egmCreatorRemoveTree($targetDir, egmCreatorMissionsRoot());
    throw $error;
  }

  $missions = egmCreatorListMissions();
  foreach ($missions as $mission) {
    if (($mission['folder'] ?? '') === $folderName) {
      return $mission;
    }
  }
  return [
    'name' => $folderName,
    'code' => $code,
    'folder' => $folderName,
    'tabId' => egmCreatorMissionTabId($folderName, $code),
    'directory' => egmCreatorMissionDirectoryLabel($folderName),
    'webPath' => $webPath,
    'panelUrl' => 'panel.php?tab=' . rawurlencode(egmCreatorMissionTabId($folderName, $code)),
    'createdAt' => gmdate('c'),
    'createdAtLabel' => gmdate('c')
  ];
}

function egmCreatorUpdateMissionBranchSetting(string $rawFolder): array
{
  egmCreatorEnsureGeneratorStorage();
  $folderName = egmCreatorResolveMissionFolder($rawFolder);
  $targetDir = egmCreatorMissionsRoot() . DIRECTORY_SEPARATOR . $folderName;
  if (!is_dir($targetDir) || !egmCreatorIsWithinPath($targetDir, egmCreatorMissionsRoot())) {
    throw new InvalidArgumentException('رویداد یافت نشد.');
  }
  $registryDirectory = egmCreatorMissionDirectoryLabel($folderName);
  $registryRecord = findEgmRegistryByDirectory(egmCreatorDatabase(), $registryDirectory);
  if (!is_array($registryRecord)) {
    throw new InvalidArgumentException('رویداد در پایگاه داده ثبت نشده است.');
  }

  $webPath = egmCreatorMissionWebPath($folderName);
  egmCreatorCopyEventGuestManagerUpdates(__DIR__, $targetDir, $folderName, $webPath);

  if (!updateEgmRegistry(egmCreatorDatabase(), (string)$registryRecord['code'], (string)$registryRecord['name'], $registryDirectory)) {
    throw new RuntimeException('ذخیره مشخصات رویداد ناموفق بود.');
  }
  ensureEgmInstanceTables(egmCreatorDatabase(), (string)$registryRecord['code']);
  egmInstanceWriteData(egmCreatorDatabase(), (string)$registryRecord['code'], 'metadata', [
    'code' => (string)$registryRecord['code'],
    'name' => (string)$registryRecord['name'],
    'directory' => $registryDirectory
  ]);
  egmInstanceWriteData(
    egmCreatorDatabase(),
    (string)$registryRecord['code'],
    'storage_mode',
    ['mode' => 'database_only', 'version' => 1]
  );

  $missions = egmCreatorListMissions();
  foreach ($missions as $mission) {
    if (($mission['folder'] ?? '') === $folderName) {
      return $mission;
    }
  }
  throw new RuntimeException('بازخوانی رویداد ناموفق بود.');
}

function egmCreatorDeleteMission(string $rawFolder): array
{
  egmCreatorEnsureGeneratorStorage();
  $folderName = egmCreatorResolveMissionFolder($rawFolder);
  $targetDir = egmCreatorMissionsRoot() . DIRECTORY_SEPARATOR . $folderName;
  if (!is_dir($targetDir) || !egmCreatorIsWithinPath($targetDir, egmCreatorMissionsRoot())) {
    throw new InvalidArgumentException('رویداد یافت نشد.');
  }
  $registryRecord = findEgmRegistryByDirectory(egmCreatorDatabase(), egmCreatorMissionDirectoryLabel($folderName));
  if (!is_array($registryRecord)) {
    throw new InvalidArgumentException('رویداد در پایگاه داده ثبت نشده است.');
  }
  $stagingDir = egmCreatorGenerateRoot() . DIRECTORY_SEPARATOR . '.delete-' . (string)$registryRecord['code'] . '-' . bin2hex(random_bytes(4));
  if (!egmDbRename($targetDir, $stagingDir)) {
    throw new RuntimeException('آماده‌سازی حذف رویداد ناموفق بود.');
  }
  $registryDeleted = false;
  try {
    if (!deleteEgmRegistry(egmCreatorDatabase(), (string)$registryRecord['code'])) {
      throw new RuntimeException('حذف رویداد از پایگاه داده ناموفق بود.');
    }
    $registryDeleted = true;
    dropEgmInstanceTables(egmCreatorDatabase(), (string)$registryRecord['code']);
  } catch (Throwable $error) {
    if ($registryDeleted) {
      try {
        insertEgmRegistry(
          egmCreatorDatabase(),
          (string)$registryRecord['code'],
          (string)$registryRecord['name'],
          (string)$registryRecord['directory']
        );
      } catch (Throwable $restoreError) {
        error_log('Failed to restore EGM registry after table deletion failure: ' . $restoreError->getMessage());
      }
    }
    @egmDbRename($stagingDir, $targetDir);
    throw $error;
  }
  egmCreatorRemoveTree($stagingDir, egmCreatorGenerateRoot());
  $missions = egmCreatorListMissions();
  return $missions;
}

if ($egmCreatorIsJsonRequest) {
  $payload = json_decode((string)egmDbFileGetContents('php://input'), true);
  if (!is_array($payload)) {
    egmCreatorJsonResponse(['status' => 'error', 'message' => 'Invalid request payload.'], 400);
  }
  if (!egmSecurityIsValidCsrfToken(egmSecurityReadCsrfFromRequest($payload))) {
    egmCreatorJsonResponse(['status' => 'error', 'message' => 'Invalid security token.'], 403);
  }

  $action = strtolower(trim((string)($payload['action'] ?? '')));
  try {
    if ($action === 'sidebar_visibility') {
      $directory = 'mini apps/EGMs/' . basename((string)($payload['folder'] ?? ''));
      $pdo = egmCreatorDatabase();
      $record = findEgmRegistryByDirectory($pdo, $directory);
      if (!$record) throw new InvalidArgumentException('مورد انتخاب‌شده پیدا نشد.');
      $visible = filter_var($payload['visible'] ?? true, FILTER_VALIDATE_BOOLEAN);
      $statement = $pdo->prepare('UPDATE `egm` SET `sidebar_visible`=:visible WHERE `code`=:code');
      $statement->execute([':visible'=>$visible ? 1 : 0, ':code'=>$record['code']]);
      egmCreatorJsonResponse(['status'=>'ok', 'message'=>'نمایش در نوار کناری ذخیره شد.', 'clubs'=>egmCreatorListMissions()]);
    }
    if ($action === 'create') {
      $mission = egmCreatorCreateMission((string)($payload['name'] ?? ''));
      $missions = egmCreatorListMissions();
      egmCreatorJsonResponse([
        'status' => 'ok',
        'message' => 'رویداد ساخته شد.',
        'club' => $mission,
        'clubs' => $missions
      ]);
    }
    if ($action === 'update_branch_setting') {
      $mission = egmCreatorUpdateMissionBranchSetting((string)($payload['folder'] ?? ''));
      $missions = egmCreatorListMissions();
      egmCreatorJsonResponse([
        'status' => 'ok',
        'message' => 'تنظیمات شعبه ذخیره شد.',
        'club' => $mission,
        'clubs' => $missions
      ]);
    }
    if ($action === 'delete') {
      $missions = egmCreatorDeleteMission((string)($payload['folder'] ?? ''));
      egmCreatorJsonResponse([
        'status' => 'ok',
        'message' => 'رویداد حذف شد.',
        'clubs' => $missions
      ]);
    }
    egmCreatorJsonResponse(['status' => 'error', 'message' => 'Unsupported action.'], 400);
  } catch (InvalidArgumentException $error) {
    egmCreatorJsonResponse(['status' => 'error', 'message' => $error->getMessage()], 400);
  } catch (Throwable $error) {
    egmCreatorJsonResponse(['status' => 'error', 'message' => 'انجام عملیات رویداد ناموفق بود.'], 500);
  }
}

egmCreatorEnsureGeneratorStorage();
$egmCreatorMissions = egmCreatorListMissions();
$egmCreatorPanelCssVer = (string)(@egmDbFilemtime(__DIR__ . '/egm-panel.css') ?: time());
$egmCreatorEndpoint = 'mini%20apps/Event%20Guest%20Manager/EGMCreator.php';
?>

<section id="tab-event-guest-manager-creator" class="tab">
<link rel="stylesheet" href="mini%20apps/Event%20Guest%20Manager/egm-panel.css?v=<?= htmlspecialchars($egmCreatorPanelCssVer, ENT_QUOTES, 'UTF-8') ?>" />
<style>
  .egm-creator-shell { display: grid; gap: 16px; }
  .egm-creator-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; align-items: start; }
  .egm-creator-muted { color: var(--muted, #6b7280); font-size: 13px; line-height: 1.7; }
  .egm-creator-status { min-height: 22px; margin: 0; }
  .egm-creator-status[data-tone="error"] { color: #b91c1c; }
  .egm-creator-status[data-tone="ok"] { color: #15803d; }
  .egm-creator-list { display: grid; gap: 10px; }
  .egm-creator-empty { padding: 12px; border: 1px dashed var(--border, #e5e7eb); border-radius: 8px; color: var(--muted, #6b7280); }
  .egm-creator-mission { border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 12px; background: #ffffff; display: grid; gap: 10px; }
  .egm-creator-mission-title { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
  .egm-creator-mission-title strong { font-size: 15px; color: var(--text, #111111); }
  .egm-creator-mission code { direction: ltr; unicode-bidi: plaintext; overflow-wrap: anywhere; }
  .egm-creator-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .egm-creator-actions .btn { min-width: 110px; justify-content: center; }
  @media (max-width: 900px) {
    .egm-creator-grid { grid-template-columns: 1fr; }
  }
</style>

<div
  class="egm-creator-shell"
  data-egm-creator-root
  data-endpoint="<?= htmlspecialchars($egmCreatorEndpoint, ENT_QUOTES, 'UTF-8') ?>"
  data-csrf="<?= htmlspecialchars($egmCreatorCsrfToken, ENT_QUOTES, 'UTF-8') ?>"
>
  <div class="egm-creator-grid">
    <div class="card settings-section">
      <div class="section-header">
        <h3>رویداد جدید</h3>
      </div>
      <form class="form" data-egm-creator-form>
        <label class="field standard-width">
          <span>نام رویداد</span>
          <input type="text" class="egm-standard-control" data-egm-club-name maxlength="80" autocomplete="off" required />
        </label>
        <div class="section-footer">
          <button type="submit" class="btn primary" data-egm-create-submit>ساخت رویداد</button>
        </div>
        <p class="egm-creator-status egm-creator-muted" data-egm-creator-status aria-live="polite"></p>
      </form>
    </div>

  </div>

  <div class="card settings-section">
    <div class="section-header">
      <h3>رویدادهای ساخته‌شده</h3>
    </div>
    <div class="egm-creator-list" data-egm-creator-list>
      <?php if (empty($egmCreatorMissions)): ?>
        <div class="egm-creator-empty">هنوز رویدادی ساخته نشده است.</div>
      <?php else: ?>
        <?php foreach ($egmCreatorMissions as $mission): ?>
          <div class="egm-creator-mission">
            <div class="egm-creator-mission-title">
              <strong><?= htmlspecialchars((string)$mission['name'], ENT_QUOTES, 'UTF-8') ?></strong>
              <span class="egm-creator-muted"><?= htmlspecialchars((string)$mission['createdAtLabel'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="egm-creator-muted">کد یکتا: <code><?= htmlspecialchars((string)$mission['code'], ENT_QUOTES, 'UTF-8') ?></code></div>
            <label class="panel-sidebar-visibility"><input type="checkbox" role="switch" data-egm-sidebar-visible data-folder="<?= htmlspecialchars((string)$mission['folder'], ENT_QUOTES, 'UTF-8') ?>" <?= ($mission['sidebarVisible'] ?? true) ? 'checked' : '' ?> /> نمایش در نوار کناری</label>
            <div class="egm-creator-actions">
              <a class="btn primary" href="<?= htmlspecialchars((string)$mission['panelUrl'], ENT_QUOTES, 'UTF-8') ?>">بخش پنل</a>
              <button type="button" class="btn ghost" data-egm-update-branch-setting data-folder="<?= htmlspecialchars((string)$mission['folder'], ENT_QUOTES, 'UTF-8') ?>">به‌روزرسانی تنظیمات شعبه</button>
              <button type="button" class="btn ghost egm-btn-danger" data-egm-delete-club data-folder="<?= htmlspecialchars((string)$mission['folder'], ENT_QUOTES, 'UTF-8') ?>">حذف رویداد</button>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<script type="application/json" data-egm-creator-state><?= json_encode($egmCreatorMissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(() => {
  const root = document.querySelector('[data-egm-creator-root]');
  if (!(root instanceof HTMLElement) || root.dataset.ready === '1') {
    return;
  }
  root.dataset.ready = '1';

  const stateEl = document.querySelector('script[data-egm-creator-state]');
  const listEl = root.querySelector('[data-egm-creator-list]');
  const form = root.querySelector('[data-egm-creator-form]');
  const input = root.querySelector('[data-egm-club-name]');
  const submitBtn = root.querySelector('[data-egm-create-submit]');
  const statusEl = root.querySelector('[data-egm-creator-status]');
  const endpoint = String(root.dataset.endpoint || '');
  const csrf = String(root.dataset.csrf || '');
  let clubs = [];

  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const setStatus = (message, tone = '') => {
    if (!(statusEl instanceof HTMLElement)) return;
    statusEl.textContent = message || '';
    if (tone) {
      statusEl.dataset.tone = tone;
    } else {
      delete statusEl.dataset.tone;
    }
  };

  const renderClubs = () => {
    if (!(listEl instanceof HTMLElement)) return;
    if (!clubs.length) {
      listEl.innerHTML = '<div class="egm-creator-empty">هنوز رویدادی ساخته نشده است.</div>';
      return;
    }
    listEl.innerHTML = clubs.map((club) => {
      const name = escapeHtml(club?.name || club?.folder || 'Event Guest Manager');
      const code = escapeHtml(club?.code || '');
      const createdAt = escapeHtml(club?.createdAtLabel || club?.createdAt || '-');
      const directory = escapeHtml(club?.directory || '');
      const panelUrl = escapeHtml(club?.panelUrl || '#');
      const folder = escapeHtml(club?.folder || '');
      return `
        <div class="egm-creator-mission">
          <div class="egm-creator-mission-title">
            <strong>${name}</strong>
            <span class="egm-creator-muted">${createdAt}</span>
          </div>
          <div class="egm-creator-muted">کد یکتا: <code>${code}</code></div>
          <label class="panel-sidebar-visibility"><input type="checkbox" role="switch" data-egm-sidebar-visible data-folder="${folder}" ${club?.sidebarVisible !== false ? 'checked' : ''} /> نمایش در نوار کناری</label>
          <div class="egm-creator-actions">
            <a class="btn primary" href="${panelUrl}">بخش پنل</a>
            <button type="button" class="btn ghost" data-egm-update-branch-setting data-folder="${folder}">به‌روزرسانی تنظیمات شعبه</button>
            <button type="button" class="btn ghost egm-btn-danger" data-egm-delete-club data-folder="${folder}">حذف رویداد</button>
          </div>
        </div>
      `;
    }).join('');
  };

  const postCreatorAction = async (body) => {
    const response = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/json',
        'X-EGM-CSRF': csrf
      },
      body: JSON.stringify({ ...body, csrf })
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload?.status !== 'ok') {
      throw new Error(payload?.message || 'انجام عملیات رویداد ناموفق بود.');
    }
    return payload;
  };

  try {
    clubs = JSON.parse(stateEl?.textContent || '[]');
    if (!Array.isArray(clubs)) clubs = [];
  } catch (error) {
    clubs = [];
  }

  if (form instanceof HTMLFormElement) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const name = String(input instanceof HTMLInputElement ? input.value : '').trim();
      if (!name) {
        setStatus('نام رویداد را وارد کنید.', 'error');
        return;
      }
      if (submitBtn instanceof HTMLButtonElement) {
        submitBtn.disabled = true;
      }
      setStatus('در حال ساخت رویداد…');
      try {
        const payload = await postCreatorAction({ action: 'create', name });
        clubs = Array.isArray(payload?.clubs) ? payload.clubs : clubs;
        renderClubs();
        if (input instanceof HTMLInputElement) {
          input.value = '';
          input.focus();
        }
        setStatus('رویداد ساخته شد؛ در حال تازه‌سازی پنل…', 'ok');
        window.setTimeout(() => {
          window.location.href = payload?.club?.panelUrl || 'panel.php';
        }, 500);
      } catch (error) {
        setStatus(error?.message || 'ساخت رویداد ناموفق بود.', 'error');
      } finally {
        if (submitBtn instanceof HTMLButtonElement) {
          submitBtn.disabled = false;
        }
      }
    });
  }

  if (listEl instanceof HTMLElement) {
    listEl.addEventListener('change', async event => {
      const input = event.target;
      if (!(input instanceof HTMLInputElement) || !input.hasAttribute('data-egm-sidebar-visible')) return;
      const visible = input.checked;
      input.disabled = true;
      try {
        const payload = await postCreatorAction({action:'sidebar_visibility', folder:input.dataset.folder, visible});
        clubs = Array.isArray(payload.clubs) ? payload.clubs : clubs;
        const club = clubs.find(item => item.folder === input.dataset.folder);
        window.dispatchEvent(new CustomEvent('panel-sidebar-visibility', {detail:{tabId:club?.tabId, visible}}));
        renderClubs();
        setStatus(payload.message, 'ok');
      } catch (error) { input.checked = !visible; setStatus(error.message, 'error'); }
      finally { input.disabled = false; }
    });

    listEl.addEventListener('click', async (event) => {
      const target = event.target instanceof Element
        ? event.target.closest('[data-egm-update-branch-setting], [data-egm-delete-club]')
        : null;
      if (!(target instanceof HTMLButtonElement)) {
        return;
      }
      const folder = String(target.dataset.folder || '').trim();
      if (!folder) {
        setStatus('پوشه رویداد یافت نشد.', 'error');
        return;
      }
      const isDelete = target.hasAttribute('data-egm-delete-club');
      const action = isDelete ? 'delete' : 'update_branch_setting';
      if (isDelete) {
        const clubName = target.closest('.egm-creator-mission')?.querySelector('strong')?.textContent?.trim() || folder;
        if (!window.confirm(`رویداد «${clubName}» و همه اطلاعات آن حذف شوند؟ این کار قابل بازگشت نیست.`)) {
          return;
        }
      } else if (!window.confirm('فایل‌های این رویداد به‌روز شوند؟ اطلاعات رویداد حفظ می‌شود.')) {
        return;
      }

      const buttons = Array.from(listEl.querySelectorAll('button'));
      buttons.forEach((button) => {
        button.disabled = true;
      });
      setStatus(isDelete ? 'در حال حذف رویداد…' : 'در حال ذخیره تنظیمات شعبه…');
      try {
        const payload = await postCreatorAction({ action, folder });
        clubs = Array.isArray(payload?.clubs) ? payload.clubs : clubs;
        renderClubs();
        setStatus(payload?.message || (isDelete ? 'رویداد حذف شد.' : 'رویداد به‌روز شد.'), 'ok');
      } catch (error) {
        setStatus(error?.message || 'انجام عملیات رویداد ناموفق بود.', 'error');
      } finally {
        Array.from(listEl.querySelectorAll('button')).forEach((button) => {
          button.disabled = false;
        });
      }
    });
  }
})();
</script>
</section>
