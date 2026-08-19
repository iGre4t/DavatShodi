<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/api/lib/common.php';
require_once $projectRoot . '/api/lib/tc-registry.php';
require_once $projectRoot . '/api/lib/tc-instance-storage.php';
require_once $projectRoot . '/api/lib/tc-database-runtime.php';

function migrateTcRelative(string $root, string $path): string
{
    return trim(str_replace('\\', '/', substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1)), '/');
}

function migrateTcShouldUpdate(string $relative, bool $directory): bool
{
    if ($relative === '') return true;
    if (in_array($relative, ['TCCreator.php', 'panel.php', 'mission.json', 'Setting.json'], true)) return false;
    foreach (['tasks', 'TC Event', 'InviteCards', 'data/pots', 'useractivitylogs/logs'] as $dataPath) {
        if ($relative === $dataPath || str_starts_with($relative, $dataPath . '/')) return false;
    }
    if (!$directory && preg_match('/\.json$/i', $relative)) return false;
    return true;
}

function migrateTcPatchCode(string $content, string $folder): string
{
    $webPath = 'mini%20apps/missions/' . rawurlencode($folder);
    $directory = 'mini apps/missions/' . $folder;
    return str_replace([
        'mini%20apps/Task%20Club', 'mini apps/Task Club',
        "__DIR__ . '/../../", '__DIR__ . "/../../', 'dirname(__DIR__, 2)',
        "session_name('TASKCLUBSESSID');", 'return "../../{$trimmed}";',
        "src: url('../../style/", "url('../../style/", 'href="../../style/', "= '../../style/",
    ], [
        $webPath, $directory,
        "__DIR__ . '/../../../", '__DIR__ . "/../../../', 'dirname(__DIR__, 3)',
        "session_name('TASKCLUB' . substr(hash('sha256', __DIR__), 0, 12));", 'return "../../../{$trimmed}";',
        "src: url('../../../style/", "url('../../../style/", 'href="../../../style/', "= '../../../style/",
    ], $content);
}

function migrateTcUpdateGeneratedCode(string $source, string $target): int
{
    $folder = basename($target);
    $updated = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = migrateTcRelative($source, $item->getPathname());
        if (!migrateTcShouldUpdate($relative, $item->isDir())) continue;
        $destination = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($item->isDir()) {
            if (!is_dir($destination)) mkdir($destination, 0775, true);
            continue;
        }
        if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0775, true);
        $content = file_get_contents($item->getPathname());
        if (!is_string($content)) throw new RuntimeException('Unable to read ' . $relative);
        if ($item->getFilename() === '.htaccess' || preg_match('/\.(?:php|js|css)$/i', $relative)) {
            $content = migrateTcPatchCode($content, $folder);
        }
        if (file_put_contents($destination, $content, LOCK_EX) === false) throw new RuntimeException('Unable to update ' . $relative);
        $updated++;
    }
    return $updated;
}

function migrateTcRemoveDocumentRows(array $context, callable $matches): int
{
    $table = $context['tables']['data'];
    $paths = $context['pdo']->query("SELECT DISTINCT `file_path` FROM `{$table}` WHERE `file_path` IS NOT NULL")
        ->fetchAll(PDO::FETCH_COLUMN);
    $deleted = 0;
    foreach ($paths as $path) {
        $relative = tcInstanceNormalizeRuntimeRelativePath((string)$path);
        if ($relative === '' || !$matches(strtolower($relative))) continue;
        $statement = $context['pdo']->prepare(
            "DELETE FROM `{$table}` WHERE (`storage_kind`='runtime_chunk' AND `file_path`=:path) "
            . "OR (`storage_kind`='runtime_file' AND `data_key`=:key)"
        );
        $statement->execute([':path' => $relative, ':key' => tcInstanceRuntimeFileDataKey($relative)]);
        $deleted += $statement->rowCount();
    }
    tcDatabaseRuntimeRefreshManifest($context);
    return $deleted;
}

function migrateTcNormalizeTextDocuments(array $context): int
{
    $table = $context['tables']['data'];
    $paths = $context['pdo']->query(
        "SELECT `file_path` FROM `{$table}` WHERE `storage_kind`='runtime_file' "
        . "AND (LOWER(`file_path`) LIKE '%.json' OR LOWER(`file_path`) LIKE '%.js')"
    )->fetchAll(PDO::FETCH_COLUMN);
    $normalized = 0;
    foreach ($paths as $relative) {
        $path = $context['root'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$relative);
        $document = tcDatabaseRuntimeRead($path);
        if (!is_array($document) || !str_starts_with((string)$document['content'], "\xEF\xBB\xBF")) continue;
        tcDatabaseRuntimeWrite($path, substr((string)$document['content'], 3), (int)$document['mtime']);
        $normalized++;
    }
    return $normalized;
}

function migrateTcDeleteRuntimeFiles(string $missionDir): int
{
    $files = tcInstanceScanRuntimeFiles($missionDir);
    $deleted = 0;
    foreach ($files as $relative => $file) {
        $database = tcDatabaseRuntimeRead($file['path']);
        if (!is_array($database)) throw new RuntimeException('Database copy is missing for ' . $relative);
        $lowerRelative = strtolower($relative);
        $structured = str_ends_with($lowerRelative, '.csv')
            || str_ends_with($lowerRelative, '/response-results.json')
            || $lowerRelative === 'tc event/tc mapped.json';
        if (!$structured && hash('sha256', (string)$database['content']) !== (string)$file['sha256']) {
            throw new RuntimeException('Database copy does not match ' . $relative);
        }
        if (!unlink($file['path'])) throw new RuntimeException('Unable to remove migrated runtime file ' . $relative);
        $deleted++;
    }
    foreach (['TC Event', 'tasks', 'data/pots', 'InviteCards', 'useractivitylogs/logs'] as $relativeDir) {
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
    foreach (glob($missionDir . DIRECTORY_SEPARATOR . '*.lock') ?: [] as $lockPath) {
        if (is_file($lockPath) && !unlink($lockPath)) {
            throw new RuntimeException('Unable to remove runtime lock ' . $lockPath);
        }
        $deleted++;
    }
    return $deleted;
}

function migrateTcMissionName(string $missionDir): string
{
    $path = $missionDir . DIRECTORY_SEPARATOR . 'mission.json';
    $meta = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    return trim((string)(is_array($meta) ? ($meta['name'] ?? '') : '')) ?: basename($missionDir);
}

$pdo = connectDatabase(loadConfig($projectRoot . '/api/config.php'));
if (!$pdo instanceof PDO) throw new RuntimeException('Database is unavailable.');
ensureTcRegistryTable($pdo);

$sourceDir = $projectRoot . '/mini apps/Task Club';
upsertTcRegistry($pdo, TC_DEVELOP_CODE, TC_DEVELOP_NAME, TC_DEVELOP_DIRECTORY);
$missionsRoot = $projectRoot . '/mini apps/missions';
foreach (glob($missionsRoot . '/*', GLOB_ONLYDIR) ?: [] as $missionDir) {
    if (basename($missionDir) === 'generate' || !is_file($missionDir . '/TC Panel.php')) continue;
    $directory = TC_INSTANCES_DIRECTORY . '/' . basename($missionDir);
    if (!findTcRegistryByDirectory($pdo, $directory)) {
        insertTcRegistry($pdo, allocateTcRegistryCode($pdo), migrateTcMissionName($missionDir), $directory);
    }
}

$registries = listTcRegistry($pdo);
$report = [];
foreach ($registries as $registry) {
    $code = normalizeTcInstanceCode($registry['code'] ?? '');
    $directory = normalizeTcRegistryDirectory($registry['directory'] ?? '');
    $missionDir = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    if ($code === '' || !is_dir($missionDir)) continue;
    $storageMode = tcInstanceReadData($pdo, $code, 'storage_mode', []);
    $alreadyDatabaseOnly = is_array($storageMode) && ($storageMode['mode'] ?? '') === 'database_only';
    $report[$code] = [
        'directory' => $directory,
        'mirror' => $alreadyDatabaseOnly
            ? ['status' => 'already_database_only']
            : tcInstanceMirrorMissionStorage($pdo, $code, $missionDir),
    ];
    tcInstanceWriteData($pdo, $code, 'metadata', [
        'code' => $code,
        'name' => trim((string)($registry['name'] ?? '')),
        'directory' => $directory,
    ]);
    if ($code === TC_DEVELOP_CODE) {
        tcInstanceWriteData($pdo, $code, 'development_instance', ['enabled' => true, 'initializedAt' => gmdate('c')]);
    }
}

foreach ($registries as $registry) {
    $directory = normalizeTcRegistryDirectory($registry['directory'] ?? '');
    if (!str_starts_with($directory, TC_INSTANCES_DIRECTORY . '/')) continue;
    $target = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    if (is_dir($target)) $report[(string)$registry['code']]['code_files_updated'] = migrateTcUpdateGeneratedCode($sourceDir, $target);
}

foreach ($registries as $registry) {
    $code = normalizeTcInstanceCode($registry['code'] ?? '');
    $directory = normalizeTcRegistryDirectory($registry['directory'] ?? '');
    $missionDir = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    if ($code === '' || !is_dir($missionDir)) continue;
    $context = tcDatabaseRuntimeContextForPath($missionDir . DIRECTORY_SEPARATOR . 'Setting.json');
    if (!is_array($context)) throw new RuntimeException('Unable to resolve database context for ' . $directory);
    $report[$code]['structured_rows_removed'] = migrateTcRemoveDocumentRows(
        $context,
        static fn(string $relative): bool => str_ends_with($relative, '.csv') || str_ends_with($relative, '/response-results.json')
    );
    $report[$code]['text_documents_normalized'] = migrateTcNormalizeTextDocuments($context);
    tcDatabaseRuntimeWrite(
        $missionDir . DIRECTORY_SEPARATOR . 'TC Event' . DIRECTORY_SEPARATOR . 'TC Mapped.json',
        json_encode(tcDatabaseRuntimeDefaultInviteeMapping(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
    );
    tcInstanceWriteData($pdo, $code, 'storage_mode', ['mode' => 'database_only', 'version' => 1, 'migratedAt' => gmdate('c')]);
    $report[$code]['files_removed'] = migrateTcDeleteRuntimeFiles($missionDir);
}

$legacyRegistry = $missionsRoot . '/generate/clubs.json';
if (is_file($legacyRegistry)) unlink($legacyRegistry);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
