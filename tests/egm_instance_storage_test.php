<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/common.php';
require_once dirname(__DIR__) . '/api/lib/egm-registry.php';
require_once dirname(__DIR__) . '/api/lib/egm-instance-storage.php';

function egmInstanceStorageAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$tables = egmInstanceTableNames('0001');
egmInstanceStorageAssert(
    normalizeEgmRegistryDirectory('miniapps/EGMs/Example') === 'mini apps/EGMs/Example',
    'Legacy generated-EGM directories are not normalized to the single mini apps tree'
);
egmInstanceStorageAssert(
    is_dir(dirname(__DIR__) . '/mini apps/EGMs') && !is_dir(dirname(__DIR__) . '/miniapps'),
    'Generated EGMs were not consolidated under mini apps/EGMs'
);
egmInstanceStorageAssert($tables['data'] === 'egm_0001', 'The scoped EGM data table name is incorrect');
egmInstanceStorageAssert($tables['users'] === 'egm_0001_users', 'The scoped EGM users table name is incorrect');
foreach ([
    'user_periods', 'answers', 'teams', 'team_members', 'photo_submissions',
    'prize_awards', 'pot_winners', 'login_attempts', 'activity_logs',
] as $suffix) {
    egmInstanceStorageAssert(
        $tables[$suffix] === 'egm_0001_' . $suffix,
        "The scoped EGM {$suffix} table name is incorrect"
    );
}

$invalidRejected = false;
try {
    egmInstanceTableNames('bad-code');
} catch (InvalidArgumentException $error) {
    $invalidRejected = true;
}
egmInstanceStorageAssert($invalidRejected, 'Unsafe dynamic EGM table codes are not rejected');

$config = loadConfig(dirname(__DIR__) . '/api/config.php');
$pdo = connectDatabase($config);
egmInstanceStorageAssert($pdo instanceof PDO, 'Could not connect to the EGM registry database');

$oeuColumns = array_column(
    $pdo->query('SHOW COLUMNS FROM `organizational_event_users`')->fetchAll(PDO::FETCH_ASSOC),
    'Field'
);

foreach (listEgmRegistry($pdo) as $record) {
    $code = (string)($record['code'] ?? '');
    if ($code !== EGM_DEVELOP_CODE) {
        egmInstanceStorageAssert(
            str_starts_with((string)($record['directory'] ?? ''), EGM_INSTANCES_DIRECTORY . '/'),
            "EGM {$code} still uses the legacy generated-instance directory"
        );
    }
    $instanceTables = egmInstanceTableNames($code);
    ensureEgmInstanceTables($pdo, $code);
    $logsPdo = connectActivityLogDatabase(loadConfig(dirname(__DIR__) . '/api/config.php'));
    egmInstanceStorageAssert($logsPdo instanceof PDO, 'Could not connect to the EGM logs database');
    ensureActivityLogTable($logsPdo, 'EGM', $code);
    foreach ($instanceTables as $key => $instanceTable) {
        egmInstanceStorageAssert(egmInstanceTableExists($key === 'activity_logs' ? $logsPdo : $pdo, $instanceTable), "Missing {$instanceTable}");
    }
    egmInstanceStorageAssert(
        egmInstanceColumnExists($pdo, $instanceTables['data'], 'periods'),
        "{$instanceTables['data']} does not contain the periods column"
    );
    foreach (['entered_date', 'entered_time', 'quit_date', 'quit_time'] as $entryColumn) {
        egmInstanceStorageAssert(
            egmInstanceColumnExists($pdo, $instanceTables['user_periods'], $entryColumn),
            "{$instanceTables['user_periods']} is missing check-in column {$entryColumn}"
        );
    }
    foreach (['storage_kind', 'file_path', 'file_data', 'content_sha256', 'file_size', 'file_mtime', 'file_chunk', 'sequence_value'] as $runtimeColumn) {
        egmInstanceStorageAssert(
            egmInstanceColumnExists($pdo, $instanceTables['data'], $runtimeColumn),
            "{$instanceTables['data']} is missing runtime-file column {$runtimeColumn}"
        );
    }
    $instanceColumns = array_column(
        $pdo->query("SHOW COLUMNS FROM `{$instanceTables['users']}`")->fetchAll(PDO::FETCH_ASSOC),
        'Field'
    );
    foreach ($oeuColumns as $oeuColumn) {
        egmInstanceStorageAssert(
            in_array($oeuColumn, $instanceColumns, true),
            "{$instanceTables['users']} is missing OEU column {$oeuColumn}"
        );
    }
    foreach (['password_hash', 'is_admin', 'is_active', 'login_count', 'total_score', 'roll_count', 'state_json', 'guest_number'] as $eventColumn) {
        egmInstanceStorageAssert(
            in_array($eventColumn, $instanceColumns, true),
            "{$instanceTables['users']} is missing event column {$eventColumn}"
        );
    }
    egmInstanceStorageAssert(
        is_array(egmInstanceReadData($pdo, $code, 'metadata')),
        "{$instanceTables['data']} does not contain EGM metadata"
    );
    egmInstanceStorageAssert(
        is_array(egmInstanceReadData($pdo, $code, 'settings')),
        "{$instanceTables['data']} does not contain EGM settings"
    );
    egmInstanceStorageAssert(
        is_array(egmInstanceReadPeriods($pdo, $code)),
        "{$instanceTables['data']} does not contain valid period data"
    );
}

$creatorSource = file_get_contents(dirname(__DIR__) . '/mini apps/Event Guest Manager/EGMCreator.php');
egmInstanceStorageAssert(is_string($creatorSource), 'Could not inspect EGMCreator.php');
egmInstanceStorageAssert(str_contains($creatorSource, 'ensureEgmInstanceTables'), 'EGM creation does not provision instance tables');
egmInstanceStorageAssert(str_contains($creatorSource, 'dropEgmInstanceTables'), 'EGM deletion does not remove instance tables');

fwrite(STDOUT, "EGM instance storage test passed.\n");
