<?php
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    session_start();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (empty($_SESSION['authenticated'])) {
        http_response_code(403);
        echo json_encode([
            'status' => 'forbidden',
            'message' => 'Log in to the main panel before opening this deployment check.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit;
    }
}

require_once __DIR__ . '/lib/common.php';
require_once __DIR__ . '/lib/tc-instance-storage.php';
require_once __DIR__ . '/lib/egm-instance-storage.php';

$projectRoot = dirname(__DIR__);
$configPath = __DIR__ . '/config.php';
$report = [
    'status' => 'ok',
    'php' => PHP_VERSION,
    'config' => [
        'local_override' => is_file(getLocalDatabaseConfigPath($configPath)),
        'json_override' => is_file(getDatabaseConfigOverridesPath()),
        'environment_override' => loadDatabaseEnvironmentOverrides() !== [],
    ],
    'core' => [],
    'logs' => [],
    'instances' => [],
    'errors' => [],
    'warnings' => [],
];

$addError = static function (string $message) use (&$report): void {
    $report['status'] = 'failed';
    $report['errors'][] = $message;
};
$addWarning = static function (string $message) use (&$report): void {
    $report['warnings'][] = $message;
};

$config = loadConfig($configPath);
foreach (['pdo', 'pdo_mysql', 'json', 'mbstring'] as $extension) {
    if (!extension_loaded($extension)) {
        $addError("Missing PHP extension: {$extension}");
    }
}

$core = connectDatabase($config, false);
if (!$core instanceof PDO) {
    $addError('Unable to connect to the configured main database.');
} else {
    $actualDatabase = (string)$core->query('SELECT DATABASE()')->fetchColumn();
    $tableCaseMode = (int)$core->query('SELECT @@lower_case_table_names')->fetchColumn();
    $report['core'] = [
        'configured_database' => (string)($config['dbname'] ?? ''),
        'connected_database' => $actualDatabase,
        'lower_case_table_names' => $tableCaseMode,
        'tables' => 0,
    ];

    $tableStatement = $core->prepare(
        'SELECT `table_name` FROM `information_schema`.`tables` '
        . 'WHERE `table_schema` = DATABASE() AND `table_type` = \'BASE TABLE\' ORDER BY `table_name`'
    );
    $tableStatement->execute();
    $coreTables = array_map('strval', $tableStatement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $coreTableSet = array_fill_keys($coreTables, true);
    $report['core']['tables'] = count($coreTables);
    foreach (['tc', 'tc_sequence', 'egm', 'egm_sequence', 'organizational_event_users'] as $requiredTable) {
        if (!isset($coreTableSet[$requiredTable])) {
            $addError("Missing or incorrectly cased main table: {$requiredTable}");
        }
    }
    foreach ($coreTables as $table) {
        if (preg_match('/(?:activity.*log|audit.*log|_activity_logs$)/i', $table) === 1) {
            $addWarning("Activity/audit table remains in the main database: {$table}");
        }
    }

    $groups = [
        ['kind' => 'TC', 'registry' => 'tc', 'tables' => 'tcInstanceTableNames', 'panel' => 'TC Panel.php'],
        ['kind' => 'EGM', 'registry' => 'egm', 'tables' => 'egmInstanceTableNames', 'panel' => 'EGM Panel.php'],
    ];
    foreach ($groups as $group) {
        if (!isset($coreTableSet[$group['registry']])) {
            continue;
        }
        $records = $core->query(
            "SELECT `code`, `name`, `directory` FROM `{$group['registry']}` ORDER BY LENGTH(`code`), `code`"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($records as $record) {
            $code = trim((string)($record['code'] ?? ''));
            try {
                /** @var array<string,string> $tables */
                $tables = $group['tables']($code);
                $missing = [];
                $counts = [];
                foreach ($tables as $key => $table) {
                    if ($key === 'activity_logs') {
                        continue;
                    }
                    if (!isset($coreTableSet[$table])) {
                        $missing[] = $table;
                        continue;
                    }
                    $counts[$key] = (int)$core->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                }
                $periods = [];
                if (isset($coreTableSet[$tables['data']])) {
                    $periodStatement = $core->prepare(
                        "SELECT COALESCE(`periods`, `payload`) FROM `{$tables['data']}` WHERE `data_key`='periods' LIMIT 1"
                    );
                    $periodStatement->execute();
                    $decoded = json_decode((string)$periodStatement->fetchColumn(), true);
                    $periods = is_array($decoded) ? array_values($decoded) : [];
                }
                $directory = trim(str_replace('\\', '/', (string)($record['directory'] ?? '')), '/');
                $panelPath = $projectRoot . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $directory)
                    . DIRECTORY_SEPARATOR . $group['panel'];
                $instance = [
                    'kind' => $group['kind'],
                    'code' => $code,
                    'name' => (string)($record['name'] ?? ''),
                    'directory' => $directory,
                    'code_shell' => is_file($panelPath),
                    'periods' => count($periods),
                    'rows' => $counts,
                    'missing_tables' => $missing,
                ];
                $report['instances'][] = $instance;
                if ($missing !== []) {
                    $addError("{$group['kind']} {$code} is missing main tables: " . implode(', ', $missing));
                }
                if (!$instance['code_shell']) {
                    $addError("{$group['kind']} {$code} code shell is missing: {$directory}/{$group['panel']}");
                }
            } catch (Throwable $error) {
                $addError("{$group['kind']} {$code} validation failed: " . $error->getMessage());
            }
        }
    }
}

$logs = connectActivityLogDatabase($config);
if (!$logs instanceof PDO) {
    $addError('Unable to connect to the configured logs database.');
} else {
    $logsDatabase = (string)$logs->query('SELECT DATABASE()')->fetchColumn();
    $logsTableStatement = $logs->prepare(
        'SELECT `table_name` FROM `information_schema`.`tables` '
        . 'WHERE `table_schema` = DATABASE() AND `table_type` = \'BASE TABLE\' ORDER BY `table_name`'
    );
    $logsTableStatement->execute();
    $logsTables = array_map('strval', $logsTableStatement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $logsTableSet = array_fill_keys($logsTables, true);
    $report['logs'] = [
        'configured_database' => (string)($config['logs_dbname'] ?? ''),
        'connected_database' => $logsDatabase,
        'tables' => count($logsTables),
    ];
    if ($core instanceof PDO && $logsDatabase === (string)($report['core']['connected_database'] ?? '')) {
        $addError('Main and logs connections point to the same database.');
    }
    foreach (['activity_log_instances', 'panel_user_activity_logs'] as $requiredLogTable) {
        if (!isset($logsTableSet[$requiredLogTable])) {
            $addError("Missing logs table: {$requiredLogTable}");
        }
    }
    foreach ($report['instances'] as $instance) {
        $logTable = activityLogTableName((string)$instance['kind'], (string)$instance['code']);
        if (!isset($logsTableSet[$logTable])) {
            $addError("Missing instance logs table: {$logTable}");
        }
    }
}

if (!$report['config']['local_override'] && PHP_OS_FAMILY !== 'Windows') {
    $addWarning('api/config.local.php is absent; a Git update may replace production database settings.');
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
if ($isCli) {
    exit($report['status'] === 'ok' ? 0 : 1);
}
