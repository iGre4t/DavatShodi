<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/api/lib/common.php';
require_once $projectRoot . '/api/lib/egm-registry.php';
require_once $projectRoot . '/api/lib/egm-instance-storage.php';
require_once $projectRoot . '/api/lib/egm-database-runtime.php';

function migrateEgmRelative(string $root, string $path): string
{
    return trim(str_replace('\\', '/', substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1)), '/');
}

function migrateEgmShouldUpdate(string $relative, bool $directory): bool
{
    if ($relative === '') return true;
    if (in_array($relative, ['EGMCreator.php', 'panel.php', 'mission.json', 'Setting.json'], true)) return false;
    foreach (['tasks', 'EGM Event', 'InviteCards', 'useractivitylogs/logs'] as $dataPath) {
        if ($relative === $dataPath || str_starts_with($relative, $dataPath . '/')) return false;
    }
    if (!$directory && preg_match('/\.json$/i', $relative)) return false;
    return true;
}

function migrateEgmPatchCode(string $content, string $folder): string
{
    $webPath = 'mini%20apps/EGMs/' . rawurlencode($folder);
    $directory = 'mini apps/EGMs/' . $folder;
    return str_replace([
        'mini%20apps/Event%20Guest%20Manager', 'mini apps/Event Guest Manager',
        "__DIR__ . '/../../", '__DIR__ . "/../../', 'dirname(__DIR__, 2)',
        "session_name('EGMSESSID');", 'return "../../{$trimmed}";',
        "src: url('../../style/", "url('../../style/", 'href="../../style/', "= '../../style/",
    ], [
        $webPath, $directory,
        "__DIR__ . '/../../../", '__DIR__ . "/../../../', 'dirname(__DIR__, 3)',
        "session_name('EGM' . substr(hash('sha256', __DIR__), 0, 12));", 'return "../../../{$trimmed}";',
        "src: url('../../../style/", "url('../../../style/", 'href="../../../style/', "= '../../../style/",
    ], $content);
}

function migrateEgmUpdateGeneratedCode(string $source, string $target): int
{
    $folder = basename($target);
    $updated = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = migrateEgmRelative($source, $item->getPathname());
        if (!migrateEgmShouldUpdate($relative, $item->isDir())) continue;
        $destination = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($item->isDir()) {
            if (!is_dir($destination)) mkdir($destination, 0775, true);
            continue;
        }
        if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0775, true);
        $content = file_get_contents($item->getPathname());
        if (!is_string($content)) throw new RuntimeException('Unable to read ' . $relative);
        if ($item->getFilename() === '.htaccess' || preg_match('/\.(?:php|js|css)$/i', $relative)) {
            $content = migrateEgmPatchCode($content, $folder);
        }
        if (file_put_contents($destination, $content, LOCK_EX) === false) {
            throw new RuntimeException('Unable to update ' . $relative);
        }
        $updated++;
    }
    return $updated;
}

function migrateEgmRemoveCsvRows(array $context): int
{
    $table = $context['tables']['data'];
    $paths = $context['pdo']->query(
        "SELECT DISTINCT `file_path` FROM `{$table}` WHERE `file_path` IS NOT NULL AND LOWER(`file_path`) LIKE '%.csv'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $deleted = 0;
    foreach ($paths as $path) {
        $relative = egmInstanceNormalizeRuntimeRelativePath((string)$path);
        if ($relative === '') continue;
        $statement = $context['pdo']->prepare(
            "DELETE FROM `{$table}` WHERE (`storage_kind`='runtime_chunk' AND `file_path`=:path) "
            . "OR (`storage_kind`='runtime_file' AND `data_key`=:key)"
        );
        $statement->execute([':path' => $relative, ':key' => egmInstanceRuntimeFileDataKey($relative)]);
        $deleted += $statement->rowCount();
    }
    egmDatabaseRuntimeRefreshManifest($context);
    return $deleted;
}

function migrateEgmNormalizeTextDocuments(array $context): int
{
    $table = $context['tables']['data'];
    $paths = $context['pdo']->query(
        "SELECT `file_path` FROM `{$table}` WHERE `storage_kind`='runtime_file' "
        . "AND (LOWER(`file_path`) LIKE '%.json' OR LOWER(`file_path`) LIKE '%.js')"
    )->fetchAll(PDO::FETCH_COLUMN);
    $normalized = 0;
    foreach ($paths as $relative) {
        $path = $context['root'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$relative);
        $document = egmDatabaseRuntimeRead($path);
        if (!is_array($document) || !str_starts_with((string)$document['content'], "\xEF\xBB\xBF")) continue;
        egmDatabaseRuntimeWrite($path, substr((string)$document['content'], 3), (int)$document['mtime']);
        $normalized++;
    }
    return $normalized;
}

function migrateEgmDeleteRuntimeFiles(string $missionDir): int
{
    $files = egmInstanceScanRuntimeFiles($missionDir);
    $deleted = 0;
    foreach ($files as $relative => $file) {
        $database = egmDatabaseRuntimeRead($file['path']);
        if (!is_array($database)) throw new RuntimeException('Database copy is missing for ' . $relative);
        if (!str_ends_with(strtolower($relative), '.csv')
            && hash('sha256', (string)$database['content']) !== (string)$file['sha256']) {
            throw new RuntimeException('Database copy does not match ' . $relative);
        }
        if (!unlink($file['path'])) throw new RuntimeException('Unable to remove migrated runtime file ' . $relative);
        $deleted++;
    }
    foreach (['EGM Event', 'tasks', 'data/pots', 'InviteCards', 'useractivitylogs/logs'] as $relativeDir) {
        $directory = $missionDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
        if (!is_dir($directory)) continue;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (!$item->isFile()) continue;
            $name = strtolower($item->getFilename());
            if (preg_match('/\.(?:csv|json|js|txt|log|jpg|jpeg|png|gif|webp|svg|pdf)(?:\.(?:lock|tmp|bak|rollback|corrupt|backup-unsynced))?$/i', $name)
                || preg_match('/\.(?:lock|tmp|bak|rollback|corrupt|backup-unsynced)$/i', $name)) {
                if (!unlink($item->getPathname())) throw new RuntimeException('Unable to remove runtime sidecar ' . $item->getPathname());
                $deleted++;
            }
        }
    }
    return $deleted;
}

$pdo = connectDatabase(loadConfig($projectRoot . '/api/config.php'));
if (!$pdo instanceof PDO) throw new RuntimeException('Database is unavailable.');
$sourceDir = $projectRoot . '/mini apps/Event Guest Manager';
$registries = listEgmRegistry($pdo);
$report = [];

// First capture every legacy runtime byte while it is still present.
foreach ($registries as $registry) {
    $code = normalizeEgmInstanceCode($registry['code'] ?? '');
    $directory = normalizeEgmRegistryDirectory($registry['directory'] ?? '');
    $missionDir = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    if ($code === '' || !is_dir($missionDir)) continue;
    $storageMode = egmInstanceReadData($pdo, $code, 'storage_mode', null);
    if (is_array($storageMode) && ($storageMode['mode'] ?? '') === 'database_only') {
        $report[$code] = ['directory' => $directory, 'mirror' => 'already_database_only'];
        continue;
    }
    $mirror = egmInstanceMirrorMissionStorage($pdo, $code, $missionDir);
    $report[$code] = ['directory' => $directory, 'mirror' => $mirror];
}

// Generated instances receive the DB-only runtime adapter before files vanish.
foreach ($registries as $registry) {
    $directory = normalizeEgmRegistryDirectory($registry['directory'] ?? '');
    if (!str_starts_with($directory, EGM_INSTANCES_DIRECTORY . '/')) continue;
    $target = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    if (is_dir($target)) $report[(string)$registry['code']]['code_files_updated'] = migrateEgmUpdateGeneratedCode($sourceDir, $target);
}

foreach ($registries as $registry) {
    $code = normalizeEgmInstanceCode($registry['code'] ?? '');
    $directory = normalizeEgmRegistryDirectory($registry['directory'] ?? '');
    $missionDir = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    if ($code === '' || !is_dir($missionDir)) continue;
    $context = egmDatabaseRuntimeContextForPath($missionDir . DIRECTORY_SEPARATOR . 'Setting.json');
    if (!is_array($context)) throw new RuntimeException('Unable to resolve DB context for ' . $directory);
    $report[$code]['csv_rows_removed'] = migrateEgmRemoveCsvRows($context);
    $report[$code]['text_documents_normalized'] = migrateEgmNormalizeTextDocuments($context);
    egmDatabaseRuntimeWrite(
        $missionDir . DIRECTORY_SEPARATOR . 'EGM Event' . DIRECTORY_SEPARATOR . 'EGM Mapped.json',
        json_encode(egmDatabaseRuntimeDefaultInviteeMapping(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
    );
    egmInstanceWriteData($pdo, $code, 'storage_mode', ['mode' => 'database_only', 'version' => 1, 'migratedAt' => gmdate('c')]);
    $report[$code]['files_removed'] = migrateEgmDeleteRuntimeFiles($missionDir);

    $prefix = trim($directory, '/') . '/InviteCards/';
    $replacement = trim($directory, '/') . '/egm_asset.php?path=InviteCards%2F';
    $tables = ensureEgmInstanceTables($pdo, $code);
    $updatePeriods = $pdo->prepare(
        "UPDATE `{$tables['user_periods']}` SET `invite_card_file`=CONCAT(:replacement,SUBSTRING(`invite_card_file`,:position)) "
        . "WHERE `invite_card_file` LIKE :pattern"
    );
    $updatePeriods->execute([':replacement' => $replacement, ':position' => strlen($prefix) + 1, ':pattern' => $prefix . '%']);
    if (egmInstanceTableExists($pdo, 'egm_invite_card_routes')) {
        $updateRoutes = $pdo->prepare(
            "UPDATE `egm_invite_card_routes` SET `image_web_path`=CONCAT(:replacement,SUBSTRING(`image_web_path`,:position)) "
            . "WHERE `egm_code`=:code AND `image_web_path` LIKE :pattern"
        );
        $updateRoutes->execute([':replacement' => $replacement, ':position' => strlen($prefix) + 1, ':code' => $code, ':pattern' => $prefix . '%']);
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
