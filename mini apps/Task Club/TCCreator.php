<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/tc-security.php';

$tcCreatorIsJsonRequest = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
requireTabPermissionFromSession('task-club', $tcCreatorIsJsonRequest);
$tcCreatorCsrfToken = tcSecurityGetCsrfToken();

const TC_CREATOR_SOURCE_LABEL = 'mini apps/Task Club';
const TC_CREATOR_GENERATOR_VERSION = 1;

function tcCreatorProjectRoot(): string
{
  return dirname(__DIR__, 2);
}

function tcCreatorMissionsRoot(): string
{
  return tcCreatorProjectRoot() . DIRECTORY_SEPARATOR . 'mini apps' . DIRECTORY_SEPARATOR . 'missions';
}

function tcCreatorGenerateRoot(): string
{
  return tcCreatorMissionsRoot() . DIRECTORY_SEPARATOR . 'generate';
}

function tcCreatorRegistryPath(): string
{
  return tcCreatorGenerateRoot() . DIRECTORY_SEPARATOR . 'clubs.json';
}

function tcCreatorEnsureDirectory(string $path): void
{
  if (is_dir($path)) {
    return;
  }
  if (!mkdir($path, 0777, true) && !is_dir($path)) {
    throw new RuntimeException('Failed to create directory: ' . $path);
  }
}

function tcCreatorWriteJsonFile(string $path, array $payload): void
{
  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($json)) {
    throw new RuntimeException('Failed to encode JSON.');
  }
  tcCreatorEnsureDirectory(dirname($path));
  if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException('Failed to write file: ' . $path);
  }
}

function tcCreatorReadJsonFile(string $path): array
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

function tcCreatorEnsureGeneratorStorage(): void
{
  tcCreatorEnsureDirectory(tcCreatorMissionsRoot());
  tcCreatorEnsureDirectory(tcCreatorGenerateRoot());

  $htaccessPath = tcCreatorGenerateRoot() . DIRECTORY_SEPARATOR . '.htaccess';
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

  if (!is_file(tcCreatorRegistryPath())) {
    tcCreatorWriteJsonFile(tcCreatorRegistryPath(), ['clubs' => []]);
  }
}

function tcCreatorNormalizeMissionName(string $value): string
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

function tcCreatorMissionWebPath(string $folderName): string
{
  return 'mini%20apps/missions/' . rawurlencode($folderName);
}

function tcCreatorMissionDirectoryLabel(string $folderName): string
{
  return 'mini apps/missions/' . $folderName;
}

function tcCreatorMissionTabId(string $folderName): string
{
  return 'task-club-mission-' . substr(hash('sha256', $folderName), 0, 12);
}

function tcCreatorIsWithinPath(string $path, string $root): bool
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

function tcCreatorRemoveTree(string $path, string $allowedRoot): void
{
  if (!is_dir($path) || !tcCreatorIsWithinPath($path, $allowedRoot)) {
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

function tcCreatorRelativePath(string $root, string $path): string
{
  $relative = substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
  return str_replace('\\', '/', is_string($relative) ? $relative : '');
}

function tcCreatorShouldCopyRelativePath(string $relativePath, bool $isDir): bool
{
  $relative = trim(str_replace('\\', '/', $relativePath), '/');
  if ($relative === '') {
    return true;
  }
  if ($relative === 'TCCreator.php' || $relative === 'panel.php' || $relative === 'mission.json') {
    return false;
  }
  if ($relative === 'tasks' || $relative === 'TC Event' || $relative === 'vendor') {
    return true;
  }
  if (strpos($relative, 'tasks/') === 0) {
    return $relative === 'tasks/tasks.js';
  }
  if (strpos($relative, 'TC Event/') === 0) {
    return $relative === 'TC Event/Answers.csv';
  }
  if (!$isDir && preg_match('/\.json$/i', $relative)) {
    return false;
  }
  return true;
}

function tcCreatorShouldUpdateRelativePath(string $relativePath, bool $isDir): bool
{
  $relative = trim(str_replace('\\', '/', $relativePath), '/');
  if ($relative === '') {
    return true;
  }
  if (in_array($relative, ['TCCreator.php', 'panel.php', 'mission.json', 'Setting.json'], true)) {
    return false;
  }
  foreach (['tasks', 'TC Event', 'useractivitylogs/logs'] as $dataPath) {
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

function tcCreatorIsPatchableTextFile(string $relativePath): bool
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

function tcCreatorPatchGeneratedFile(string $path, string $relativePath, string $folderName, string $webPath): void
{
  if (!tcCreatorIsPatchableTextFile($relativePath)) {
    return;
  }
  $content = file_get_contents($path);
  if (!is_string($content)) {
    throw new RuntimeException('Failed to read copied file: ' . $relativePath);
  }
  $replacements = [
    'mini%20apps/Task%20Club' => $webPath,
    'mini apps/Task Club' => tcCreatorMissionDirectoryLabel($folderName),
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

function tcCreatorCopyTaskClubTemplate(string $sourceDir, string $targetDir, string $folderName, string $webPath): void
{
  tcCreatorEnsureDirectory($targetDir);
  $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($iterator as $item) {
    $sourcePath = $item->getPathname();
    $relative = tcCreatorRelativePath($sourceDir, $sourcePath);
    if (!tcCreatorShouldCopyRelativePath($relative, $item->isDir())) {
      continue;
    }
    $destination = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if ($item->isDir()) {
      tcCreatorEnsureDirectory($destination);
      continue;
    }
    tcCreatorEnsureDirectory(dirname($destination));
    if (!copy($sourcePath, $destination)) {
      throw new RuntimeException('Failed to copy file: ' . $relative);
    }
    tcCreatorPatchGeneratedFile($destination, $relative, $folderName, $webPath);
  }
}

function tcCreatorCopyTaskClubUpdates(string $sourceDir, string $targetDir, string $folderName, string $webPath): void
{
  if (!is_dir($targetDir) || !tcCreatorIsWithinPath($targetDir, tcCreatorMissionsRoot())) {
    throw new RuntimeException('Invalid Task Club target directory.');
  }
  $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($iterator as $item) {
    $sourcePath = $item->getPathname();
    $relative = tcCreatorRelativePath($sourceDir, $sourcePath);
    if (!tcCreatorShouldUpdateRelativePath($relative, $item->isDir())) {
      continue;
    }
    $destination = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if ($item->isDir()) {
      tcCreatorEnsureDirectory($destination);
      continue;
    }
    tcCreatorEnsureDirectory(dirname($destination));
    if (!copy($sourcePath, $destination)) {
      throw new RuntimeException('Failed to update file: ' . $relative);
    }
    tcCreatorPatchGeneratedFile($destination, $relative, $folderName, $webPath);
  }
}

function tcCreatorInitializeMission(string $targetDir, string $name, string $folderName, string $webPath): void
{
  tcCreatorEnsureDirectory($targetDir . DIRECTORY_SEPARATOR . 'tasks');
  tcCreatorEnsureDirectory($targetDir . DIRECTORY_SEPARATOR . 'TC Event');

  if (file_put_contents($targetDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'tasks.js', "window.TC_TASKS = [];\n", LOCK_EX) === false) {
    throw new RuntimeException('Failed to initialize task store.');
  }
  if (file_put_contents($targetDir . DIRECTORY_SEPARATOR . 'TC Event' . DIRECTORY_SEPARATOR . 'Answers.csv', "Work ID\n", LOCK_EX) === false) {
    throw new RuntimeException('Failed to initialize answers store.');
  }
  tcCreatorWriteJsonFile($targetDir . DIRECTORY_SEPARATOR . 'Setting.json', [
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

  tcCreatorWriteJsonFile($targetDir . DIRECTORY_SEPARATOR . 'mission.json', [
    'name' => $name,
    'folder' => $folderName,
    'directory' => tcCreatorMissionDirectoryLabel($folderName),
    'webPath' => $webPath,
    'source' => TC_CREATOR_SOURCE_LABEL,
    'generatorVersion' => TC_CREATOR_GENERATOR_VERSION,
    'createdAt' => gmdate('c')
  ]);
}

function tcCreatorListMissions(): array
{
  tcCreatorEnsureGeneratorStorage();
  $root = tcCreatorMissionsRoot();
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
    $meta = tcCreatorReadJsonFile($missionDir . DIRECTORY_SEPARATOR . 'mission.json');
    $webPath = tcCreatorMissionWebPath($folder);
    $createdAt = trim((string)($meta['createdAt'] ?? ''));
    $items[] = [
      'name' => trim((string)($meta['name'] ?? $folder)) ?: $folder,
      'folder' => $folder,
      'tabId' => tcCreatorMissionTabId($folder),
      'directory' => tcCreatorMissionDirectoryLabel($folder),
      'webPath' => $webPath,
      'appUrl' => $webPath . '/TCM.php',
      'panelUrl' => 'panel.php?tab=' . rawurlencode(tcCreatorMissionTabId($folder)),
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

function tcCreatorSyncRegistry(array $missions): void
{
  tcCreatorWriteJsonFile(tcCreatorRegistryPath(), [
    'updatedAt' => gmdate('c'),
    'clubs' => $missions
  ]);
}

function tcCreatorJsonResponse(array $payload, int $statusCode = 200): void
{
  http_response_code($statusCode);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function tcCreatorResolveMissionFolder(string $rawFolder): string
{
  $folderName = tcCreatorNormalizeMissionName($rawFolder);
  if ($folderName === '') {
    throw new InvalidArgumentException('Select a valid Task Club.');
  }
  return $folderName;
}

function tcCreatorCreateMission(string $rawName): array
{
  tcCreatorEnsureGeneratorStorage();
  $folderName = tcCreatorNormalizeMissionName($rawName);
  if ($folderName === '') {
    throw new InvalidArgumentException('Enter a valid Task Club name.');
  }

  $missionsRoot = tcCreatorMissionsRoot();
  $targetDir = $missionsRoot . DIRECTORY_SEPARATOR . $folderName;
  if (file_exists($targetDir)) {
    throw new InvalidArgumentException('A Task Club with this folder name already exists.');
  }

  $buildDir = tcCreatorGenerateRoot() . DIRECTORY_SEPARATOR . '.build-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
  $webPath = tcCreatorMissionWebPath($folderName);
  try {
    tcCreatorCopyTaskClubTemplate(__DIR__, $buildDir, $folderName, $webPath);
    tcCreatorInitializeMission($buildDir, $folderName, $folderName, $webPath);
    if (!rename($buildDir, $targetDir)) {
      throw new RuntimeException('Failed to publish generated Task Club.');
    }
  } catch (Throwable $error) {
    tcCreatorRemoveTree($buildDir, tcCreatorGenerateRoot());
    throw $error;
  }

  $missions = tcCreatorListMissions();
  tcCreatorSyncRegistry($missions);
  foreach ($missions as $mission) {
    if (($mission['folder'] ?? '') === $folderName) {
      return $mission;
    }
  }
  return [
    'name' => $folderName,
    'folder' => $folderName,
    'tabId' => tcCreatorMissionTabId($folderName),
    'directory' => tcCreatorMissionDirectoryLabel($folderName),
    'webPath' => $webPath,
    'appUrl' => $webPath . '/TCM.php',
    'panelUrl' => 'panel.php?tab=' . rawurlencode(tcCreatorMissionTabId($folderName)),
    'createdAt' => gmdate('c'),
    'createdAtLabel' => gmdate('c')
  ];
}

function tcCreatorUpdateMissionBranchSetting(string $rawFolder): array
{
  tcCreatorEnsureGeneratorStorage();
  $folderName = tcCreatorResolveMissionFolder($rawFolder);
  $targetDir = tcCreatorMissionsRoot() . DIRECTORY_SEPARATOR . $folderName;
  if (!is_dir($targetDir) || !tcCreatorIsWithinPath($targetDir, tcCreatorMissionsRoot())) {
    throw new InvalidArgumentException('Task Club was not found.');
  }

  $webPath = tcCreatorMissionWebPath($folderName);
  tcCreatorCopyTaskClubUpdates(__DIR__, $targetDir, $folderName, $webPath);

  $metaPath = $targetDir . DIRECTORY_SEPARATOR . 'mission.json';
  $meta = tcCreatorReadJsonFile($metaPath);
  $meta['name'] = trim((string)($meta['name'] ?? $folderName)) ?: $folderName;
  $meta['folder'] = $folderName;
  $meta['directory'] = tcCreatorMissionDirectoryLabel($folderName);
  $meta['webPath'] = $webPath;
  $meta['source'] = TC_CREATOR_SOURCE_LABEL;
  $meta['generatorVersion'] = TC_CREATOR_GENERATOR_VERSION;
  $meta['updatedAt'] = gmdate('c');
  if (trim((string)($meta['createdAt'] ?? '')) === '') {
    $meta['createdAt'] = gmdate('c');
  }
  tcCreatorWriteJsonFile($metaPath, $meta);

  $missions = tcCreatorListMissions();
  tcCreatorSyncRegistry($missions);
  foreach ($missions as $mission) {
    if (($mission['folder'] ?? '') === $folderName) {
      return $mission;
    }
  }
  throw new RuntimeException('Updated Task Club could not be reloaded.');
}

function tcCreatorDeleteMission(string $rawFolder): array
{
  tcCreatorEnsureGeneratorStorage();
  $folderName = tcCreatorResolveMissionFolder($rawFolder);
  $targetDir = tcCreatorMissionsRoot() . DIRECTORY_SEPARATOR . $folderName;
  if (!is_dir($targetDir) || !tcCreatorIsWithinPath($targetDir, tcCreatorMissionsRoot())) {
    throw new InvalidArgumentException('Task Club was not found.');
  }

  tcCreatorRemoveTree($targetDir, tcCreatorMissionsRoot());
  if (is_dir($targetDir)) {
    throw new RuntimeException('Failed to delete Task Club.');
  }
  $missions = tcCreatorListMissions();
  tcCreatorSyncRegistry($missions);
  return $missions;
}

if ($tcCreatorIsJsonRequest) {
  $payload = json_decode((string)file_get_contents('php://input'), true);
  if (!is_array($payload)) {
    tcCreatorJsonResponse(['status' => 'error', 'message' => 'Invalid request payload.'], 400);
  }
  if (!tcSecurityIsValidCsrfToken(tcSecurityReadCsrfFromRequest($payload))) {
    tcCreatorJsonResponse(['status' => 'error', 'message' => 'Invalid security token.'], 403);
  }

  $action = strtolower(trim((string)($payload['action'] ?? '')));
  try {
    if ($action === 'create') {
      $mission = tcCreatorCreateMission((string)($payload['name'] ?? ''));
      $missions = tcCreatorListMissions();
      tcCreatorJsonResponse([
        'status' => 'ok',
        'message' => 'Task Club created.',
        'club' => $mission,
        'clubs' => $missions
      ]);
    }
    if ($action === 'update_branch_setting') {
      $mission = tcCreatorUpdateMissionBranchSetting((string)($payload['folder'] ?? ''));
      $missions = tcCreatorListMissions();
      tcCreatorJsonResponse([
        'status' => 'ok',
        'message' => 'Task Club branch setting updated.',
        'club' => $mission,
        'clubs' => $missions
      ]);
    }
    if ($action === 'delete') {
      $missions = tcCreatorDeleteMission((string)($payload['folder'] ?? ''));
      tcCreatorJsonResponse([
        'status' => 'ok',
        'message' => 'Task Club deleted.',
        'clubs' => $missions
      ]);
    }
    tcCreatorJsonResponse(['status' => 'error', 'message' => 'Unsupported action.'], 400);
  } catch (InvalidArgumentException $error) {
    tcCreatorJsonResponse(['status' => 'error', 'message' => $error->getMessage()], 400);
  } catch (Throwable $error) {
    tcCreatorJsonResponse(['status' => 'error', 'message' => 'Task Club creator action failed.'], 500);
  }
}

tcCreatorEnsureGeneratorStorage();
$tcCreatorMissions = tcCreatorListMissions();
tcCreatorSyncRegistry($tcCreatorMissions);
$tcCreatorPanelCssVer = (string)(@filemtime(__DIR__ . '/tc-panel.css') ?: time());
$tcCreatorEndpoint = 'mini%20apps/Task%20Club/TCCreator.php';
?>

<section id="tab-task-club-creator" class="tab">
<link rel="stylesheet" href="mini%20apps/Task%20Club/tc-panel.css?v=<?= htmlspecialchars($tcCreatorPanelCssVer, ENT_QUOTES, 'UTF-8') ?>" />
<style>
  .tc-creator-shell { display: grid; gap: 16px; }
  .tc-creator-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(260px, 0.7fr); gap: 16px; align-items: start; }
  .tc-creator-muted { color: var(--muted, #6b7280); font-size: 13px; line-height: 1.7; }
  .tc-creator-status { min-height: 22px; margin: 0; }
  .tc-creator-status[data-tone="error"] { color: #b91c1c; }
  .tc-creator-status[data-tone="ok"] { color: #15803d; }
  .tc-creator-list { display: grid; gap: 10px; }
  .tc-creator-empty { padding: 12px; border: 1px dashed var(--border, #e5e7eb); border-radius: 8px; color: var(--muted, #6b7280); }
  .tc-creator-mission { border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 12px; background: #ffffff; display: grid; gap: 10px; }
  .tc-creator-mission-title { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
  .tc-creator-mission-title strong { font-size: 15px; color: var(--text, #111111); }
  .tc-creator-mission code { direction: ltr; unicode-bidi: plaintext; overflow-wrap: anywhere; }
  .tc-creator-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .tc-creator-actions .btn { min-width: 110px; justify-content: center; }
  @media (max-width: 900px) {
    .tc-creator-grid { grid-template-columns: 1fr; }
  }
</style>

<div
  class="tc-creator-shell"
  data-tc-creator-root
  data-endpoint="<?= htmlspecialchars($tcCreatorEndpoint, ENT_QUOTES, 'UTF-8') ?>"
  data-csrf="<?= htmlspecialchars($tcCreatorCsrfToken, ENT_QUOTES, 'UTF-8') ?>"
>
  <div class="tc-creator-grid">
    <div class="card settings-section">
      <div class="section-header">
        <h3>Create Task Club</h3>
      </div>
      <form class="form" data-tc-creator-form>
        <label class="field standard-width">
          <span>Task Club name</span>
          <input type="text" data-tc-club-name maxlength="80" autocomplete="off" required />
        </label>
        <div class="section-footer">
          <button type="submit" class="btn primary" data-tc-create-submit>Create Task Club</button>
        </div>
        <p class="tc-creator-status tc-creator-muted" data-tc-creator-status aria-live="polite"></p>
      </form>
    </div>

    <div class="card settings-section">
      <div class="section-header">
        <h3>Generation storage</h3>
      </div>
      <p class="tc-creator-muted">
        New Task Clubs are created under <code>mini apps/missions/&lt;Task Club name&gt;</code> and appear as new tabs in this panel sidebar.
        Generator settings are kept under <code>mini apps/missions/generate</code>.
      </p>
      <p class="tc-creator-muted">
        The current default Task Club stays in <code>mini apps/Task Club</code>.
      </p>
    </div>
  </div>

  <div class="card settings-section">
    <div class="section-header">
      <h3>Task Clubs</h3>
    </div>
    <div class="tc-creator-list" data-tc-creator-list>
      <?php if (empty($tcCreatorMissions)): ?>
        <div class="tc-creator-empty">No generated Task Clubs yet.</div>
      <?php else: ?>
        <?php foreach ($tcCreatorMissions as $mission): ?>
          <div class="tc-creator-mission">
            <div class="tc-creator-mission-title">
              <strong><?= htmlspecialchars((string)$mission['name'], ENT_QUOTES, 'UTF-8') ?></strong>
              <span class="tc-creator-muted"><?= htmlspecialchars((string)$mission['createdAtLabel'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="tc-creator-muted">Directory: <code><?= htmlspecialchars((string)$mission['directory'], ENT_QUOTES, 'UTF-8') ?></code></div>
            <div class="tc-creator-actions">
              <a class="btn primary" href="<?= htmlspecialchars((string)$mission['panelUrl'], ENT_QUOTES, 'UTF-8') ?>">Panel tab</a>
              <a class="btn ghost" href="<?= htmlspecialchars((string)$mission['appUrl'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open app</a>
              <button type="button" class="btn ghost" data-tc-update-branch-setting data-folder="<?= htmlspecialchars((string)$mission['folder'], ENT_QUOTES, 'UTF-8') ?>">Update branch setting</button>
              <button type="button" class="btn ghost tc-btn-danger" data-tc-delete-club data-folder="<?= htmlspecialchars((string)$mission['folder'], ENT_QUOTES, 'UTF-8') ?>">Delete Task Club</button>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<script type="application/json" data-tc-creator-state><?= json_encode($tcCreatorMissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(() => {
  const root = document.querySelector('[data-tc-creator-root]');
  if (!(root instanceof HTMLElement) || root.dataset.ready === '1') {
    return;
  }
  root.dataset.ready = '1';

  const stateEl = document.querySelector('script[data-tc-creator-state]');
  const listEl = root.querySelector('[data-tc-creator-list]');
  const form = root.querySelector('[data-tc-creator-form]');
  const input = root.querySelector('[data-tc-club-name]');
  const submitBtn = root.querySelector('[data-tc-create-submit]');
  const statusEl = root.querySelector('[data-tc-creator-status]');
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
      listEl.innerHTML = '<div class="tc-creator-empty">No generated Task Clubs yet.</div>';
      return;
    }
    listEl.innerHTML = clubs.map((club) => {
      const name = escapeHtml(club?.name || club?.folder || 'Task Club');
      const createdAt = escapeHtml(club?.createdAtLabel || club?.createdAt || '-');
      const directory = escapeHtml(club?.directory || '');
      const panelUrl = escapeHtml(club?.panelUrl || '#');
      const appUrl = escapeHtml(club?.appUrl || '#');
      const folder = escapeHtml(club?.folder || '');
      return `
        <div class="tc-creator-mission">
          <div class="tc-creator-mission-title">
            <strong>${name}</strong>
            <span class="tc-creator-muted">${createdAt}</span>
          </div>
          <div class="tc-creator-muted">Directory: <code>${directory}</code></div>
          <div class="tc-creator-actions">
            <a class="btn primary" href="${panelUrl}">Panel tab</a>
            <a class="btn ghost" href="${appUrl}" target="_blank" rel="noopener">Open app</a>
            <button type="button" class="btn ghost" data-tc-update-branch-setting data-folder="${folder}">Update branch setting</button>
            <button type="button" class="btn ghost tc-btn-danger" data-tc-delete-club data-folder="${folder}">Delete Task Club</button>
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
        'X-TC-CSRF': csrf
      },
      body: JSON.stringify({ ...body, csrf })
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload?.status !== 'ok') {
      throw new Error(payload?.message || 'Task Club creator action failed.');
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
        setStatus('Enter a Task Club name.', 'error');
        return;
      }
      if (submitBtn instanceof HTMLButtonElement) {
        submitBtn.disabled = true;
      }
      setStatus('Creating Task Club...');
      try {
        const payload = await postCreatorAction({ action: 'create', name });
        clubs = Array.isArray(payload?.clubs) ? payload.clubs : clubs;
        renderClubs();
        if (input instanceof HTMLInputElement) {
          input.value = '';
          input.focus();
        }
        setStatus('Task Club created. Refreshing panel...', 'ok');
        window.setTimeout(() => {
          window.location.href = payload?.club?.panelUrl || 'panel.php';
        }, 500);
      } catch (error) {
        setStatus(error?.message || 'Failed to create Task Club.', 'error');
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
        ? event.target.closest('[data-tc-update-branch-setting], [data-tc-delete-club]')
        : null;
      if (!(target instanceof HTMLButtonElement)) {
        return;
      }
      const folder = String(target.dataset.folder || '').trim();
      if (!folder) {
        setStatus('Task Club folder is missing.', 'error');
        return;
      }
      const isDelete = target.hasAttribute('data-tc-delete-club');
      const action = isDelete ? 'delete' : 'update_branch_setting';
      if (isDelete) {
        const clubName = target.closest('.tc-creator-mission')?.querySelector('strong')?.textContent?.trim() || folder;
        if (!window.confirm(`Delete "${clubName}" and all of its mission data? This cannot be undone.`)) {
          return;
        }
      } else if (!window.confirm('Update this Task Club from the source Task Club files? Mission data will be kept.')) {
        return;
      }

      const buttons = Array.from(listEl.querySelectorAll('button'));
      buttons.forEach((button) => {
        button.disabled = true;
      });
      setStatus(isDelete ? 'Deleting Task Club...' : 'Updating branch setting...');
      try {
        const payload = await postCreatorAction({ action, folder });
        clubs = Array.isArray(payload?.clubs) ? payload.clubs : clubs;
        renderClubs();
        setStatus(payload?.message || (isDelete ? 'Task Club deleted.' : 'Task Club updated.'), 'ok');
      } catch (error) {
        setStatus(error?.message || 'Task Club creator action failed.', 'error');
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
