<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/common.php';
require_once dirname(__DIR__) . '/api/lib/activity-log-storage.php';
require_once dirname(__DIR__) . '/api/lib/activity-logger.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$config = loadConfig($root . '/api/config.php');
$main = connectDatabase($config);
$logs = connectActivityLogDatabase($config);
if (!$main instanceof PDO || !$logs instanceof PDO) {
    fwrite(STDERR, "Main or logs database connection failed.\n");
    exit(1);
}

$mainDatabase = sanitizeDatabaseIdentifier((string)($config['dbname'] ?? ''), 'MCI');
$logsConfig = activityLogDatabaseConfig($config);
$logsDatabase = sanitizeDatabaseIdentifier((string)($logsConfig['dbname'] ?? ''), 'MCI_logs');
if (strcasecmp($mainDatabase, $logsDatabase) === 0) {
    fwrite(STDERR, "The logs database must differ from the main database for this migration.\n");
    exit(1);
}

$dropSource = in_array('--drop-source', $argv, true);
$tableStatement = $main->prepare(
    "SELECT `table_name` FROM `information_schema`.`tables` WHERE `table_schema`=:schema "
    . "AND (`table_name` REGEXP '^(TC|EGM)_[0-9]+_activity_logs$' OR `table_name`='panel_user_activity_logs') "
    . "ORDER BY `table_name`"
);
$tableStatement->execute([':schema' => $mainDatabase]);
$tables = array_map('strval', $tableStatement->fetchAll(PDO::FETCH_COLUMN));
if (!$tables) {
    fwrite(STDERR, "No source activity log tables were found.\n");
    exit(1);
}

$copied = [];
foreach ($tables as $table) {
    if ($table === 'panel_user_activity_logs') {
        activityLoggerEnsureAuditTable($logs, ACTIVITY_LOGGER_CHANNEL_PANEL);
    } elseif (preg_match('/^(TC|EGM)_([0-9]+)_activity_logs$/iD', $table, $match) === 1) {
        ensureActivityLogTable($logs, strtoupper($match[1]), $match[2]);
    } else {
        continue;
    }
    $sourceCount = (int)$main->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    if ($table === 'panel_user_activity_logs') {
        $logs->exec("INSERT IGNORE INTO `{$table}` SELECT * FROM `{$mainDatabase}`.`{$table}`");
    } else {
        $columns = '`id`,`source_key`,`user_id`,`work_id`,`session_id`,`level`,`action`,`entity_type`,`entity_id`,'
            . '`ip_address`,`user_agent`,`status`,`message`,`metadata_json`,`occurred_at`,`created_at`';
        $logs->exec(
            "INSERT IGNORE INTO `{$table}` ({$columns}) SELECT {$columns} FROM `{$mainDatabase}`.`{$table}`"
        );
    }
    $targetCount = (int)$logs->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    if ($targetCount < $sourceCount) {
        throw new RuntimeException("Target is missing rows for {$table}: source={$sourceCount}, target={$targetCount}");
    }
    if ($table === 'panel_user_activity_logs') {
        $sourceDigest = (string)$main->query(
            "SELECT CONCAT(COUNT(*),':',COALESCE(MIN(`id`),0),':',COALESCE(MAX(`id`),0),':',"
            . "COALESCE(SUM(CRC32(CONCAT_WS('|',`id`,COALESCE(`action`,''),COALESCE(`timestamp`,'')))),0)) FROM `{$table}`"
        )->fetchColumn();
        $targetDigest = (string)$logs->query(
            "SELECT CONCAT(COUNT(*),':',COALESCE(MIN(`id`),0),':',COALESCE(MAX(`id`),0),':',"
            . "COALESCE(SUM(CRC32(CONCAT_WS('|',`id`,COALESCE(`action`,''),COALESCE(`timestamp`,'')))),0)) FROM `{$table}` "
            . "WHERE `id` IN (SELECT `id` FROM `{$mainDatabase}`.`{$table}`)"
        )->fetchColumn();
    } else {
        $sourceDigest = (string)$main->query(
            "SELECT CONCAT(COUNT(*),':',COALESCE(MIN(`id`),0),':',COALESCE(MAX(`id`),0),':',"
            . "COALESCE(SUM(CRC32(CONCAT_WS('|',`id`,COALESCE(`source_key`,''),COALESCE(`action`,''),COALESCE(`occurred_at`,'')))),0)) FROM `{$table}`"
        )->fetchColumn();
        $targetDigest = (string)$logs->query(
            "SELECT CONCAT(COUNT(*),':',COALESCE(MIN(`id`),0),':',COALESCE(MAX(`id`),0),':',"
            . "COALESCE(SUM(CRC32(CONCAT_WS('|',`id`,COALESCE(`source_key`,''),COALESCE(`action`,''),COALESCE(`occurred_at`,'')))),0)) FROM `{$table}` "
            . "WHERE `source_key` IN (SELECT `source_key` FROM `{$mainDatabase}`.`{$table}`)"
        )->fetchColumn();
    }
    if ($sourceDigest !== $targetDigest) {
        throw new RuntimeException("Digest mismatch for {$table}.");
    }
    $copied[$table] = $targetCount;
    echo "Verified {$table}: {$targetCount} rows\n";
}

if ($dropSource) {
    foreach (array_keys($copied) as $table) {
        $main->exec("DROP TABLE `{$table}`");
        echo "Removed migrated source table {$mainDatabase}.{$table}\n";
    }
}

echo 'Activity logs migration passed: ' . array_sum($copied) . " rows in {$logsDatabase}.\n";
