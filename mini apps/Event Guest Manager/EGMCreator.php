<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/egm-security.php';

$egmCreatorIsJsonRequest = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
requireTabPermissionFromSession('event-guest-manager', $egmCreatorIsJsonRequest);
$egmCreatorCsrfToken = egmSecurityGetCsrfToken();

const EGM_CREATOR_SOURCE_LABEL = 'mini apps/Event Guest Manager';
const EGM_CREATOR_GENERATOR_VERSION = 1;

function egmCreatorProjectRoot(): string
{
  return dirname(__DIR__, 2);
}

function egmCreatorMissionsRoot(): string
{
  return egmCreatorProjectRoot() . DIRECTORY_SEPARATOR . 'mini apps' . DIRECTORY_SEPARATOR . 'missions';
}

function egmCreatorGenerateRoot(): string
{
  return egmCreatorMissionsRoot() . DIRECTORY_SEPARATOR . 'generate';
}

function egmCreatorRegistryPath(): string
{
  return egmCreatorGenerateRoot() . DIRECTORY_SEPARATOR . 'clubs.json';
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
  if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException('Failed to write file: ' . $path);
  }
}

function egmCreatorReadJsonFile(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return [];
  }
  $decoded = json_decode($content, true);
  return is_array($decoded) ? $decoded : [];
}

function egmCreatorEnsureGeneratorStorage(): void
{
  egmCreatorEnsureDirectory(egmCreatorMissionsRoot());
  egmCreatorEnsureDirectory(egmCreatorGenerateRoot());

  $htaccessPath = egmCreatorGenerateRoot() . DIRECTORY_SEPARATOR . '.htaccess';
  if (!is_file($htaccessPath)) {
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
    if (file_put_contents($htaccessPath, $rules, LOCK_EX) === false) {
      throw new RuntimeException('Failed to write generator access rules.');
    }
  }

  if (!is_file(egmCreatorRegistryPath())) {
    egmCreatorWriteJsonFile(egmCreatorRegistryPath(), ['clubs' => []]);
  }
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
  return 'mini%20apps/missions/' . rawurlencode($folderName);
}

function egmCreatorMissionDirectoryLabel(string $folderName): string
{
  return 'mini apps/missions/' . $folderName;
}

function egmCreatorMissionTabId(string $folderName): string
{
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
      @unlink($itemPath);
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
  if (strpos($relative, 'tasks/') === 0) {
    return $relative === 'tasks/tasks.js';
  }
  if (strpos($relative, 'EGM Event/') === 0) {
    return $relative === 'EGM Event/Answers.csv';
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
  $content = file_get_contents($path);
  if (!is_string($content)) {
    throw new RuntimeException('Failed to read copied file: ' . $relativePath);
  }
  $replacements = [
    'mini%20apps/Event%20Guest%20Manager' => $webPath,
    'mini apps/Event Guest Manager' => egmCreatorMissionDirectoryLabel($folderName),
    "__DIR__ . '/../../" => "__DIR__ . '/../../../",
    '__DIR__ . "/../../' => '__DIR__ . "/../../../',
    'dirname(__DIR__, 2)' => 'dirname(__DIR__, 3)',
    "session_name('TASKCLUBSESSID');" => "session_name('TASKCLUB' . substr(hash('sha256', __DIR__), 0, 12));",
    'return "../../{$trimmed}";' => 'return "../../../{$trimmed}";',
    "src: url('../../style/" => "src: url('../../../style/",
    "url('../../style/" => "url('../../../style/",
    'href="../../style/' => 'href="../../../style/',
    "= '../../style/" => "= '../../../style/"
  ];
  $patched = str_replace(array_keys($replacements), array_values($replacements), $content);
  if ($patched !== $content && file_put_contents($path, $patched, LOCK_EX) === false) {
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
    if (!copy($sourcePath, $destination)) {
      throw new RuntimeException('Failed to copy file: ' . $relative);
    }
    egmCreatorPatchGeneratedFile($destination, $relative, $folderName, $webPath);
  }
}

function egmCreatorCopyEventGuestManagerUpdates(string $sourceDir, string $targetDir, string $folderName, string $webPath): void
{
  if (!is_dir($targetDir) || !egmCreatorIsWithinPath($targetDir, egmCreatorMissionsRoot())) {
    throw new RuntimeException('Invalid Event Guest Manager target directory.');
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
    if (!copy($sourcePath, $destination)) {
      throw new RuntimeException('Failed to update file: ' . $relative);
    }
    egmCreatorPatchGeneratedFile($destination, $relative, $folderName, $webPath);
  }
}

function egmCreatorInitializeMission(string $targetDir, string $name, string $folderName, string $webPath): void
{
  egmCreatorEnsureDirectory($targetDir . DIRECTORY_SEPARATOR . 'tasks');
  egmCreatorEnsureDirectory($targetDir . DIRECTORY_SEPARATOR . 'EGM Event');

  if (file_put_contents($targetDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'tasks.js', "window.EGM_TASKS = [];\n", LOCK_EX) === false) {
    throw new RuntimeException('Failed to initialize task store.');
  }
  if (file_put_contents($targetDir . DIRECTORY_SEPARATOR . 'EGM Event' . DIRECTORY_SEPARATOR . 'Answers.csv', "Work ID\n", LOCK_EX) === false) {
    throw new RuntimeException('Failed to initialize answers store.');
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

  egmCreatorWriteJsonFile($targetDir . DIRECTORY_SEPARATOR . 'mission.json', [
    'name' => $name,
    'folder' => $folderName,
    'directory' => egmCreatorMissionDirectoryLabel($folderName),
    'webPath' => $webPath,
    'source' => EGM_CREATOR_SOURCE_LABEL,
    'generatorVersion' => EGM_CREATOR_GENERATOR_VERSION,
    'createdAt' => gmdate('c')
  ]);
}

function egmCreatorListMissions(): array
{
  egmCreatorEnsureGeneratorStorage();
  $root = egmCreatorMissionsRoot();
  if (!is_dir($root)) {
    return [];
  }
  $items = [];
  foreach (new DirectoryIterator($root) as $entry) {
    if ($entry->isDot() || !$entry->isDir()) {
      continue;
    }
    $folder = $entry->getFilename();
    if ($folder === 'generate' || strncmp($folder, '.', 1) === 0) {
      continue;
    }
    $missionDir = $entry->getPathname();
    $meta = egmCreatorReadJsonFile($missionDir . DIRECTORY_SEPARATOR . 'mission.json');
    $webPath = egmCreatorMissionWebPath($folder);
    $createdAt = trim((string)($meta['createdAt'] ?? ''));
    $items[] = [
      'name' => trim((string)($meta['name'] ?? $folder)) ?: $folder,
      'folder' => $folder,
      'tabId' => egmCreatorMissionTabId($folder),
      'directory' => egmCreatorMissionDirectoryLabel($folder),
      'webPath' => $webPath,
      'appUrl' => $webPath . '/EGMM.php',
      'panelUrl' => 'panel.php?tab=' . rawurlencode(egmCreatorMissionTabId($folder)),
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

function egmCreatorSyncRegistry(array $missions): void
{
  egmCreatorWriteJsonFile(egmCreatorRegistryPath(), [
    'updatedAt' => gmdate('c'),
    'clubs' => $missions
  ]);
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
    throw new InvalidArgumentException('Select a valid Event Guest Manager.');
  }
  return $folderName;
}

function egmCreatorCreateMission(string $rawName): array
{
  egmCreatorEnsureGeneratorStorage();
  $folderName = egmCreatorNormalizeMissionName($rawName);
  if ($folderName === '') {
    throw new InvalidArgumentException('Enter a valid Event Guest Manager name.');
  }

  $missionsRoot = egmCreatorMissionsRoot();
  $targetDir = $missionsRoot . DIRECTORY_SEPARATOR . $folderName;
  if (file_exists($targetDir)) {
    throw new InvalidArgumentException('A Event Guest Manager with this folder name already exists.');
  }

  $buildDir = egmCreatorGenerateRoot() . DIRECTORY_SEPARATOR . '.build-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
  $webPath = egmCreatorMissionWebPath($folderName);
  try {
    egmCreatorCopyEventGuestManagerTemplate(__DIR__, $buildDir, $folderName, $webPath);
    egmCreatorInitializeMission($buildDir, $folderName, $folderName, $webPath);
    if (!rename($buildDir, $targetDir)) {
      throw new RuntimeException('Failed to publish generated Event Guest Manager.');
    }
  } catch (Throwable $error) {
    egmCreatorRemoveTree($buildDir, egmCreatorGenerateRoot());
    throw $error;
  }

  $missions = egmCreatorListMissions();
  egmCreatorSyncRegistry($missions);
  foreach ($missions as $mission) {
    if (($mission['folder'] ?? '') === $folderName) {
      return $mission;
    }
  }
  return [
    'name' => $folderName,
    'folder' => $folderName,
    'tabId' => egmCreatorMissionTabId($folderName),
    'directory' => egmCreatorMissionDirectoryLabel($folderName),
    'webPath' => $webPath,
    'appUrl' => $webPath . '/EGMM.php',
    'panelUrl' => 'panel.php?tab=' . rawurlencode(egmCreatorMissionTabId($folderName)),
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
    throw new InvalidArgumentException('Event Guest Manager was not found.');
  }

  $webPath = egmCreatorMissionWebPath($folderName);
  egmCreatorCopyEventGuestManagerUpdates(__DIR__, $targetDir, $folderName, $webPath);

  $metaPath = $targetDir . DIRECTORY_SEPARATOR . 'mission.json';
  $meta = egmCreatorReadJsonFile($metaPath);
  $meta['name'] = trim((string)($meta['name'] ?? $folderName)) ?: $folderName;
  $meta['folder'] = $folderName;
  $meta['directory'] = egmCreatorMissionDirectoryLabel($folderName);
  $meta['webPath'] = $webPath;
  $meta['source'] = EGM_CREATOR_SOURCE_LABEL;
  $meta['generatorVersion'] = EGM_CREATOR_GENERATOR_VERSION;
  $meta['updatedAt'] = gmdate('c');
  if (trim((string)($meta['createdAt'] ?? '')) === '') {
    $meta['createdAt'] = gmdate('c');
  }
  egmCreatorWriteJsonFile($metaPath, $meta);

  $missions = egmCreatorListMissions();
  egmCreatorSyncRegistry($missions);
  foreach ($missions as $mission) {
    if (($mission['folder'] ?? '') === $folderName) {
      return $mission;
    }
  }
  throw new RuntimeException('Updated Event Guest Manager could not be reloaded.');
}

function egmCreatorDeleteMission(string $rawFolder): array
{
  egmCreatorEnsureGeneratorStorage();
  $folderName = egmCreatorResolveMissionFolder($rawFolder);
  $targetDir = egmCreatorMissionsRoot() . DIRECTORY_SEPARATOR . $folderName;
  if (!is_dir($targetDir) || !egmCreatorIsWithinPath($targetDir, egmCreatorMissionsRoot())) {
    throw new InvalidArgumentException('Event Guest Manager was not found.');
  }

  egmCreatorRemoveTree($targetDir, egmCreatorMissionsRoot());
  if (is_dir($targetDir)) {
    throw new RuntimeException('Failed to delete Event Guest Manager.');
  }
  $missions = egmCreatorListMissions();
  egmCreatorSyncRegistry($missions);
  return $missions;
}

if ($egmCreatorIsJsonRequest) {
  $payload = json_decode((string)file_get_contents('php://input'), true);
  if (!is_array($payload)) {
    egmCreatorJsonResponse(['status' => 'error', 'message' => 'Invalid request payload.'], 400);
  }
  if (!egmSecurityIsValidCsrfToken(egmSecurityReadCsrfFromRequest($payload))) {
    egmCreatorJsonResponse(['status' => 'error', 'message' => 'Invalid security token.'], 403);
  }

  $action = strtolower(trim((string)($payload['action'] ?? '')));
  try {
    if ($action === 'create') {
      $mission = egmCreatorCreateMission((string)($payload['name'] ?? ''));
      $missions = egmCreatorListMissions();
      egmCreatorJsonResponse([
        'status' => 'ok',
        'message' => 'Event Guest Manager created.',
        'club' => $mission,
        'clubs' => $missions
      ]);
    }
    if ($action === 'update_branch_setting') {
      $mission = egmCreatorUpdateMissionBranchSetting((string)($payload['folder'] ?? ''));
      $missions = egmCreatorListMissions();
      egmCreatorJsonResponse([
        'status' => 'ok',
        'message' => 'Event Guest Manager branch setting updated.',
        'club' => $mission,
        'clubs' => $missions
      ]);
    }
    if ($action === 'delete') {
      $missions = egmCreatorDeleteMission((string)($payload['folder'] ?? ''));
      egmCreatorJsonResponse([
        'status' => 'ok',
        'message' => 'Event Guest Manager deleted.',
        'clubs' => $missions
      ]);
    }
    egmCreatorJsonResponse(['status' => 'error', 'message' => 'Unsupported action.'], 400);
  } catch (InvalidArgumentException $error) {
    egmCreatorJsonResponse(['status' => 'error', 'message' => $error->getMessage()], 400);
  } catch (Throwable $error) {
    egmCreatorJsonResponse(['status' => 'error', 'message' => 'Event Guest Manager creator action failed.'], 500);
  }
}

egmCreatorEnsureGeneratorStorage();
$egmCreatorMissions = egmCreatorListMissions();
egmCreatorSyncRegistry($egmCreatorMissions);
$egmCreatorPanelCssVer = (string)(@filemtime(__DIR__ . '/egm-panel.css') ?: time());
$egmCreatorEndpoint = 'mini%20apps/Event%20Guest%20Manager/EGMCreator.php';
?>

<section id="tab-event-guest-manager-creator" class="tab">
<link rel="stylesheet" href="mini%20apps/Event%20Guest%20Manager/egm-panel.css?v=<?= htmlspecialchars($egmCreatorPanelCssVer, ENT_QUOTES, 'UTF-8') ?>" />
<style>
  .egm-creator-shell { display: grid; gap: 16px; }
  .egm-creator-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(260px, 0.7fr); gap: 16px; align-items: start; }
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
        <h3>Create Event Guest Manager</h3>
      </div>
      <form class="form" data-egm-creator-form>
        <label class="field standard-width">
          <span>Event Guest Manager name</span>
          <input type="text" data-egm-club-name maxlength="80" autocomplete="off" required />
        </label>
        <div class="section-footer">
          <button type="submit" class="btn primary" data-egm-create-submit>Create Event Guest Manager</button>
        </div>
        <p class="egm-creator-status egm-creator-muted" data-egm-creator-status aria-live="polite"></p>
      </form>
    </div>

    <div class="card settings-section">
      <div class="section-header">
        <h3>Generation storage</h3>
      </div>
      <p class="egm-creator-muted">
        New Event Guest Managers are created under <code>mini apps/missions/&lt;Event Guest Manager name&gt;</code> and appear as new tabs in this panel sidebar.
        Generator settings are kept under <code>mini apps/missions/generate</code>.
      </p>
      <p class="egm-creator-muted">
        The current default Event Guest Manager stays in <code>mini apps/Event Guest Manager</code>.
      </p>
    </div>
  </div>

  <div class="card settings-section">
    <div class="section-header">
      <h3>Event Guest Managers</h3>
    </div>
    <div class="egm-creator-list" data-egm-creator-list>
      <?php if (empty($egmCreatorMissions)): ?>
        <div class="egm-creator-empty">No generated Event Guest Managers yet.</div>
      <?php else: ?>
        <?php foreach ($egmCreatorMissions as $mission): ?>
          <div class="egm-creator-mission">
            <div class="egm-creator-mission-title">
              <strong><?= htmlspecialchars((string)$mission['name'], ENT_QUOTES, 'UTF-8') ?></strong>
              <span class="egm-creator-muted"><?= htmlspecialchars((string)$mission['createdAtLabel'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="egm-creator-muted">Directory: <code><?= htmlspecialchars((string)$mission['directory'], ENT_QUOTES, 'UTF-8') ?></code></div>
            <div class="egm-creator-actions">
              <a class="btn primary" href="<?= htmlspecialchars((string)$mission['panelUrl'], ENT_QUOTES, 'UTF-8') ?>">Panel tab</a>
              <a class="btn ghost" href="<?= htmlspecialchars((string)$mission['appUrl'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open app</a>
              <button type="button" class="btn ghost" data-egm-update-branch-setting data-folder="<?= htmlspecialchars((string)$mission['folder'], ENT_QUOTES, 'UTF-8') ?>">Update branch setting</button>
              <button type="button" class="btn ghost egm-btn-danger" data-egm-delete-club data-folder="<?= htmlspecialchars((string)$mission['folder'], ENT_QUOTES, 'UTF-8') ?>">Delete Event Guest Manager</button>
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
      listEl.innerHTML = '<div class="egm-creator-empty">No generated Event Guest Managers yet.</div>';
      return;
    }
    listEl.innerHTML = clubs.map((club) => {
      const name = escapeHtml(club?.name || club?.folder || 'Event Guest Manager');
      const createdAt = escapeHtml(club?.createdAtLabel || club?.createdAt || '-');
      const directory = escapeHtml(club?.directory || '');
      const panelUrl = escapeHtml(club?.panelUrl || '#');
      const appUrl = escapeHtml(club?.appUrl || '#');
      const folder = escapeHtml(club?.folder || '');
      return `
        <div class="egm-creator-mission">
          <div class="egm-creator-mission-title">
            <strong>${name}</strong>
            <span class="egm-creator-muted">${createdAt}</span>
          </div>
          <div class="egm-creator-muted">Directory: <code>${directory}</code></div>
          <div class="egm-creator-actions">
            <a class="btn primary" href="${panelUrl}">Panel tab</a>
            <a class="btn ghost" href="${appUrl}" target="_blank" rel="noopener">Open app</a>
            <button type="button" class="btn ghost" data-egm-update-branch-setting data-folder="${folder}">Update branch setting</button>
            <button type="button" class="btn ghost egm-btn-danger" data-egm-delete-club data-folder="${folder}">Delete Event Guest Manager</button>
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
      throw new Error(payload?.message || 'Event Guest Manager creator action failed.');
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
        setStatus('Enter a Event Guest Manager name.', 'error');
        return;
      }
      if (submitBtn instanceof HTMLButtonElement) {
        submitBtn.disabled = true;
      }
      setStatus('Creating Event Guest Manager...');
      try {
        const payload = await postCreatorAction({ action: 'create', name });
        clubs = Array.isArray(payload?.clubs) ? payload.clubs : clubs;
        renderClubs();
        if (input instanceof HTMLInputElement) {
          input.value = '';
          input.focus();
        }
        setStatus('Event Guest Manager created. Refreshing panel...', 'ok');
        window.setTimeout(() => {
          window.location.href = payload?.club?.panelUrl || 'panel.php';
        }, 500);
      } catch (error) {
        setStatus(error?.message || 'Failed to create Event Guest Manager.', 'error');
      } finally {
        if (submitBtn instanceof HTMLButtonElement) {
          submitBtn.disabled = false;
        }
      }
    });
  }

  if (listEl instanceof HTMLElement) {
    listEl.addEventListener('click', async (event) => {
      const target = event.target instanceof Element
        ? event.target.closest('[data-egm-update-branch-setting], [data-egm-delete-club]')
        : null;
      if (!(target instanceof HTMLButtonElement)) {
        return;
      }
      const folder = String(target.dataset.folder || '').trim();
      if (!folder) {
        setStatus('Event Guest Manager folder is missing.', 'error');
        return;
      }
      const isDelete = target.hasAttribute('data-egm-delete-club');
      const action = isDelete ? 'delete' : 'update_branch_setting';
      if (isDelete) {
        const clubName = target.closest('.egm-creator-mission')?.querySelector('strong')?.textContent?.trim() || folder;
        if (!window.confirm(`Delete "${clubName}" and all of its mission data? This cannot be undone.`)) {
          return;
        }
      } else if (!window.confirm('Update this Event Guest Manager from the source Event Guest Manager files? Mission data will be kept.')) {
        return;
      }

      const buttons = Array.from(listEl.querySelectorAll('button'));
      buttons.forEach((button) => {
        button.disabled = true;
      });
      setStatus(isDelete ? 'Deleting Event Guest Manager...' : 'Updating branch setting...');
      try {
        const payload = await postCreatorAction({ action, folder });
        clubs = Array.isArray(payload?.clubs) ? payload.clubs : clubs;
        renderClubs();
        setStatus(payload?.message || (isDelete ? 'Event Guest Manager deleted.' : 'Event Guest Manager updated.'), 'ok');
      } catch (error) {
        setStatus(error?.message || 'Event Guest Manager creator action failed.', 'error');
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
