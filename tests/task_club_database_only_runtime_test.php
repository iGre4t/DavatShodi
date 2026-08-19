<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/lib/common.php';
require_once $root . '/api/lib/tc-registry.php';
require_once $root . '/api/lib/tc-instance-storage.php';
require_once $root . '/api/lib/tc-database-runtime.php';

function tcDatabaseOnlyAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = connectDatabase(loadConfig($root . '/api/config.php'));
tcDatabaseOnlyAssert($pdo instanceof PDO, 'Database connection failed.');
$logsPdo = connectActivityLogDatabase(loadConfig($root . '/api/config.php'));
tcDatabaseOnlyAssert($logsPdo instanceof PDO, 'Logs database connection failed.');
$seen = 0;
foreach (listTcRegistry($pdo) as $registry) {
    $code = normalizeTcInstanceCode($registry['code'] ?? '');
    $directory = normalizeTcRegistryDirectory($registry['directory'] ?? '');
    if ($code === '' || $directory === '') continue;
    $missionDir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    if (!is_dir($missionDir)) continue;
    $seen++;
    $mode = tcInstanceReadData($pdo, $code, 'storage_mode', null);
    tcDatabaseOnlyAssert(is_array($mode) && ($mode['mode'] ?? '') === 'database_only', "{$code} is not database-only.");
    tcDatabaseOnlyAssert(tcInstanceScanRuntimeFiles($missionDir) === [], "{$code} still has runtime files on disk.");
    $tables = ensureTcInstanceTables($pdo, $code);
    foreach ($tables as $key => $table) {
        $tablePdo = $key === 'activity_logs' ? $logsPdo : $pdo;
        tcDatabaseOnlyAssert(tcInstanceTableExists($tablePdo, $table), "Missing table {$table}.");
    }

    $opaqueCsv = (int)$pdo->query("SELECT COUNT(*) FROM `{$tables['data']}` WHERE `file_path` IS NOT NULL AND LOWER(`file_path`) LIKE '%.csv'")->fetchColumn();
    tcDatabaseOnlyAssert($opaqueCsv === 0, "{$code} still stores CSV documents.");
    $opaqueResults = (int)$pdo->query("SELECT COUNT(*) FROM `{$tables['data']}` WHERE LOWER(COALESCE(`file_path`,'')) LIKE '%/response-results.json'")->fetchColumn();
    tcDatabaseOnlyAssert($opaqueResults === 0, "{$code} still stores shared-answer result blobs.");

    foreach (['Setting.json', 'tasks/tasks.js', 'TC Event/Invitees mapped.csv', 'TC Event/TC Mapped.json'] as $relative) {
        $document = tcDatabaseRuntimeRead($missionDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        tcDatabaseOnlyAssert(is_array($document), "{$code} cannot read {$relative} from the database.");
    }
    $invitees = tcDatabaseRuntimeRead($missionDir . DIRECTORY_SEPARATOR . 'TC Event' . DIRECTORY_SEPARATOR . 'Invitees mapped.csv');
    $rows = tcDatabaseRuntimeCsvDecode((string)$invitees['content']);
    $activeUsers = (int)$pdo->query("SELECT COUNT(*) FROM `{$tables['users']}` WHERE `is_active`=1")->fetchColumn();
    tcDatabaseOnlyAssert(count($rows) === $activeUsers + 1, "{$code} participant compatibility view has the wrong row count.");

    $sharedCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$tables['shared_answers_quiz_results']}`")->fetchColumn();
    if ($sharedCount > 0) {
        $shared = tcDatabaseRuntimeRead($missionDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . '009' . DIRECTORY_SEPARATOR . 'response-results.json');
        $payload = is_array($shared) ? json_decode((string)$shared['content'], true) : null;
        tcDatabaseOnlyAssert(is_array($payload) && count($payload) === $sharedCount, "{$code} shared-answer compatibility view has the wrong row count.");
    }
}
tcDatabaseOnlyAssert($seen === 3, 'Expected the TaskClub template and two mission instances.');
echo "TaskClub database-only runtime test passed.\n";
