<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/api/lib/common.php';
require_once $projectRoot . '/api/lib/egm-registry.php';
require_once $projectRoot . '/api/lib/egm-instance-storage.php';
require_once $projectRoot . '/api/lib/egm-database-runtime.php';

function egmDatabaseOnlyAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = connectDatabase(loadConfig($projectRoot . '/api/config.php'));
egmDatabaseOnlyAssert($pdo instanceof PDO, 'Database connection failed.');
$logsPdo = connectActivityLogDatabase(loadConfig($projectRoot . '/api/config.php'));
egmDatabaseOnlyAssert($logsPdo instanceof PDO, 'Logs database connection failed.');

foreach (listEgmRegistry($pdo) as $registry) {
    $code = normalizeEgmInstanceCode($registry['code'] ?? '');
    $directory = normalizeEgmRegistryDirectory($registry['directory'] ?? '');
    if ($code === '' || $directory === '') continue;
    $missionDir = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    if (!is_dir($missionDir)) continue;
    $mode = egmInstanceReadData($pdo, $code, 'storage_mode', null);
    egmDatabaseOnlyAssert(is_array($mode) && ($mode['mode'] ?? '') === 'database_only', "{$code} is not database-only.");
    egmDatabaseOnlyAssert(egmInstanceScanRuntimeFiles($missionDir) === [], "{$code} still has runtime data files on disk.");

    $tables = ensureEgmInstanceTables($pdo, $code);
    egmDatabaseOnlyAssert(egmInstanceTableExists($logsPdo, $tables['activity_logs']), "Missing logs table {$tables['activity_logs']}.");
    $csvCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$tables['data']}` WHERE `file_path` IS NOT NULL AND LOWER(`file_path`) LIKE '%.csv'")->fetchColumn();
    egmDatabaseOnlyAssert($csvCount === 0, "{$code} still stores CSV documents.");
    $paths = $pdo->query("SELECT `file_path` FROM `{$tables['data']}` WHERE `storage_kind`='runtime_file' ORDER BY `file_path`")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($paths as $relative) {
        $document = egmDatabaseRuntimeRead($missionDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$relative));
        egmDatabaseOnlyAssert(is_array($document), "{$code} cannot read DB document {$relative}.");
    }

    $mapping = egmDatabaseRuntimeRead($missionDir . DIRECTORY_SEPARATOR . 'EGM Event' . DIRECTORY_SEPARATOR . 'EGM Mapped.json');
    $mappingPayload = is_array($mapping) ? json_decode((string)$mapping['content'], true) : null;
    egmDatabaseOnlyAssert(is_array($mappingPayload) && ($mappingPayload['workId'] ?? -1) === 0, "{$code} has no relational invitee mapping.");

    $invitees = egmDatabaseRuntimeRead($missionDir . DIRECTORY_SEPARATOR . 'EGM Event' . DIRECTORY_SEPARATOR . 'Invitees mapped.csv');
    egmDatabaseOnlyAssert(is_array($invitees), "{$code} cannot build its participant compatibility view.");
    $activeUsers = (int)$pdo->query("SELECT COUNT(*) FROM `{$tables['users']}` WHERE `is_active`=1")->fetchColumn();
    $rows = egmDatabaseRuntimeCsvDecode((string)$invitees['content']);
    egmDatabaseOnlyAssert(count($rows) === $activeUsers + 1, "{$code} participant view does not match the users table.");
}

echo "EGM database-only runtime test passed.\n";
