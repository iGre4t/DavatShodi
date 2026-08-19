<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const DESTINATION_FOLDER = 'BeDastAvardim';
const SOURCE_LABEL = 'mini apps/Task Club';
const GENERATOR_VERSION = 1;

function failMigration(string $message): never
{
    throw new RuntimeException($message);
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        failMigration("Unable to create directory: {$path}");
    }
}

function readJson(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) failMigration("Unable to read JSON: {$path}");
    $value = json_decode($raw, true);
    if (!is_array($value)) failMigration("Invalid JSON: {$path}");
    return $value;
}

function writeJson(string $path, array $value): void
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        failMigration("Unable to write JSON: {$path}");
    }
}

function relativePath(string $root, string $path): string
{
    $relative = substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
    return str_replace('\\', '/', is_string($relative) ? $relative : '');
}

function shouldExclude(string $relative): bool
{
    $relative = trim(str_replace('\\', '/', $relative), '/');
    return in_array($relative, ['TCCreator.php', 'mission.json'], true);
}

function isPatchableText(string $relative): bool
{
    $relative = trim(str_replace('\\', '/', $relative), '/');
    if ($relative === '' || str_starts_with($relative, 'vendor/')) return false;
    if (str_starts_with($relative, 'tasks/') || str_starts_with($relative, 'TC Event/')) return false;
    if (str_starts_with($relative, 'useractivitylogs/logs/')) return false;
    $name = basename($relative);
    return $name === '.htaccess' || (bool)preg_match('/\.(php|js|css)$/i', $name);
}

function patchGeneratedFile(string $path, string $relative, string $folder, string $webPath): void
{
    if (!isPatchableText($relative)) return;
    $content = @file_get_contents($path);
    if (!is_string($content)) failMigration("Unable to read generated source: {$relative}");
    $replacements = [
        'mini%20apps/Task%20Club' => $webPath,
        'mini apps/Task Club' => 'mini apps/missions/' . $folder,
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
        failMigration("Unable to patch generated source: {$relative}");
    }
}

function copySnapshot(string $source, string $destination, string $folder, string $webPath): array
{
    ensureDirectory($destination);
    $counts = ['files' => 0, 'bytes' => 0, 'patchedFiles' => 0];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = relativePath($source, $item->getPathname());
        if (shouldExclude($relative)) continue;
        $target = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($item->isDir()) {
            ensureDirectory($target);
            continue;
        }
        ensureDirectory(dirname($target));
        if (!copy($item->getPathname(), $target)) failMigration("Unable to copy: {$relative}");
        $counts['files']++;
        $counts['bytes'] += max(0, (int)$item->getSize());
        if (isPatchableText($relative)) {
            patchGeneratedFile($target, $relative, $folder, $webPath);
            $counts['patchedFiles']++;
        }
    }
    return $counts;
}

function isWithin(string $path, string $root): bool
{
    $realPath = realpath($path);
    $realRoot = realpath($root);
    if (!is_string($realPath) || !is_string($realRoot)) return false;
    $pathValue = str_replace('\\', '/', rtrim($realPath, DIRECTORY_SEPARATOR));
    $rootValue = str_replace('\\', '/', rtrim($realRoot, DIRECTORY_SEPARATOR));
    return $pathValue === $rootValue || str_starts_with($pathValue, $rootValue . '/');
}

function removeTree(string $path, string $allowedRoot): void
{
    if (!is_dir($path) || !isWithin($path, $allowedRoot)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

function missionTabId(string $folder): string
{
    return 'task-club-mission-' . substr(hash('sha256', $folder), 0, 12);
}

function missionWebPath(string $folder): string
{
    return 'mini%20apps/missions/' . rawurlencode($folder);
}

function listMissions(string $missionsRoot): array
{
    $missions = [];
    foreach (new DirectoryIterator($missionsRoot) as $entry) {
        if ($entry->isDot() || !$entry->isDir()) continue;
        $folder = $entry->getFilename();
        if ($folder === 'generate' || str_starts_with($folder, '.')) continue;
        $directory = $entry->getPathname();
        if (!is_file($directory . DIRECTORY_SEPARATOR . 'TC Panel.php')) continue;
        $metaPath = $directory . DIRECTORY_SEPARATOR . 'mission.json';
        $meta = is_file($metaPath) ? readJson($metaPath) : [];
        $name = trim((string)($meta['name'] ?? $folder));
        if ($name === '') $name = $folder;
        $createdAt = trim((string)($meta['createdAt'] ?? ''));
        $webPath = missionWebPath($folder);
        $missions[] = [
            'name' => $name,
            'folder' => $folder,
            'tabId' => missionTabId($folder),
            'directory' => 'mini apps/missions/' . $folder,
            'webPath' => $webPath,
            'appUrl' => $webPath . '/TCM.php',
            'panelUrl' => 'panel.php?tab=' . rawurlencode(missionTabId($folder)),
            'createdAt' => $createdAt,
            'createdAtLabel' => $createdAt !== '' ? $createdAt : '-'
        ];
    }
    usort($missions, static function (array $left, array $right): int {
        $leftCreated = (string)($left['createdAt'] ?? '');
        $rightCreated = (string)($right['createdAt'] ?? '');
        return $leftCreated !== $rightCreated
            ? strcmp($rightCreated, $leftCreated)
            : strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
    });
    return $missions;
}

function taskCount(string $registryPath): int
{
    $raw = @file_get_contents($registryPath);
    if (!is_string($raw) || !preg_match('/=\s*(\[.*\])\s*;?\s*$/s', $raw, $matches)) return 0;
    $tasks = json_decode($matches[1], true);
    return is_array($tasks) ? count($tasks) : 0;
}

function csvDataRowCount(string $path): int
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) return 0;
    $count = -1;
    while (fgetcsv($handle, 0, ',', '"', '\\') !== false) $count++;
    fclose($handle);
    return max(0, $count);
}

$projectRoot = dirname(__DIR__);
$sourceRoot = $projectRoot . DIRECTORY_SEPARATOR . 'mini apps' . DIRECTORY_SEPARATOR . 'Task Club';
$missionsRoot = $projectRoot . DIRECTORY_SEPARATOR . 'mini apps' . DIRECTORY_SEPARATOR . 'missions';
$generateRoot = $missionsRoot . DIRECTORY_SEPARATOR . 'generate';
$registryPath = $generateRoot . DIRECTORY_SEPARATOR . 'clubs.json';
$destinationRoot = $missionsRoot . DIRECTORY_SEPARATOR . DESTINATION_FOLDER;
$webPath = missionWebPath(DESTINATION_FOLDER);

if (!is_dir($sourceRoot)) failMigration('The main Task Club source directory is missing.');
ensureDirectory($missionsRoot);
ensureDirectory($generateRoot);
if (file_exists($destinationRoot)) failMigration('Mission BeDastAvardim already exists.');

$settings = readJson($sourceRoot . DIRECTORY_SEPARATOR . 'Setting.json');
$eventName = trim((string)($settings['eventName'] ?? ''));
if ($eventName === '') $eventName = 'به دست آوردیم';
$createdAt = gmdate('c');
$buildRoot = $generateRoot . DIRECTORY_SEPARATOR . '.build-history-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
$backupRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'DavatShodi-main-taskclub-migration-' . date('Ymd-His');
ensureDirectory($backupRoot);
if (is_file($registryPath) && !copy($registryPath, $backupRoot . DIRECTORY_SEPARATOR . 'clubs.json')) {
    failMigration('Unable to back up the Missions registry.');
}

$published = false;
try {
    $copyStats = copySnapshot($sourceRoot, $buildRoot, DESTINATION_FOLDER, $webPath);
    writeJson($buildRoot . DIRECTORY_SEPARATOR . 'mission.json', [
        'name' => $eventName,
        'folder' => DESTINATION_FOLDER,
        'directory' => 'mini apps/missions/' . DESTINATION_FOLDER,
        'webPath' => $webPath,
        'source' => SOURCE_LABEL,
        'generatorVersion' => GENERATOR_VERSION,
        'migrationType' => 'full-history-snapshot',
        'sourceEventStart' => trim((string)($settings['startDate'] ?? '')),
        'sourceEventEnd' => trim((string)($settings['endDate'] ?? '')),
        'createdAt' => $createdAt,
        'updatedAt' => $createdAt
    ]);
    if (!rename($buildRoot, $destinationRoot)) failMigration('Unable to publish the migrated mission.');
    $published = true;
    $missions = listMissions($missionsRoot);
    writeJson($registryPath, ['updatedAt' => gmdate('c'), 'clubs' => $missions]);
} catch (Throwable $error) {
    if ($published && is_dir($destinationRoot)) removeTree($destinationRoot, $missionsRoot);
    if (is_dir($buildRoot)) removeTree($buildRoot, $generateRoot);
    $registryBackup = $backupRoot . DIRECTORY_SEPARATOR . 'clubs.json';
    if (is_file($registryBackup)) @copy($registryBackup, $registryPath);
    throw $error;
}

$taskAnswers = [];
foreach (new DirectoryIterator($destinationRoot . DIRECTORY_SEPARATOR . 'tasks') as $entry) {
    if ($entry->isDot() || !$entry->isDir()) continue;
    $answersPath = $entry->getPathname() . DIRECTORY_SEPARATOR . 'Answers.csv';
    if (is_file($answersPath)) $taskAnswers[$entry->getFilename()] = csvDataRowCount($answersPath);
}
ksort($taskAnswers, SORT_NATURAL);

echo json_encode([
    'status' => 'ok',
    'mission' => [
        'name' => $eventName,
        'folder' => DESTINATION_FOLDER,
        'tabId' => missionTabId(DESTINATION_FOLDER),
        'panelUrl' => 'panel.php?tab=' . missionTabId(DESTINATION_FOLDER),
        'appUrl' => $webPath . '/TCM.php'
    ],
    'snapshot' => [
        'files' => $copyStats['files'],
        'bytes' => $copyStats['bytes'],
        'patchedFiles' => $copyStats['patchedFiles'],
        'tasks' => taskCount($destinationRoot . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'tasks.js'),
        'inviteeRows' => csvDataRowCount($destinationRoot . DIRECTORY_SEPARATOR . 'TC Event' . DIRECTORY_SEPARATOR . 'Invitees mapped.csv'),
        'taskAnswerRows' => $taskAnswers
    ],
    'registeredMissions' => count($missions),
    'backupDirectory' => $backupRoot
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
