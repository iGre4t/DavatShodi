<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

const ACTIVITY_LOG_DATABASE_SCHEMA_VERSION = '2026-08-19.1';

/** @return array<string,mixed> */
function activityLogDatabaseConfig(array $mainConfig): array
{
    $database = trim((string)($mainConfig['logs_dbname'] ?? ''));
    if ($database === '') {
        return $mainConfig;
    }
    return [
        'host' => trim((string)($mainConfig['logs_host'] ?? '')) ?: (string)($mainConfig['host'] ?? ''),
        'port' => (int)($mainConfig['logs_port'] ?? $mainConfig['port'] ?? 3306),
        'dbname' => sanitizeDatabaseIdentifier($database, 'MCI_logs'),
        'user' => array_key_exists('logs_user', $mainConfig)
            ? (string)$mainConfig['logs_user']
            : (string)($mainConfig['user'] ?? ''),
        'password' => array_key_exists('logs_password', $mainConfig)
            ? (string)$mainConfig['logs_password']
            : (string)($mainConfig['password'] ?? ''),
        'charset' => (string)($mainConfig['charset'] ?? 'utf8mb4'),
        'connect_timeout' => (int)($mainConfig['connect_timeout'] ?? 3),
    ];
}

function activityLogDatabaseIsSeparate(array $mainConfig): bool
{
    return trim((string)($mainConfig['logs_dbname'] ?? '')) !== '';
}

function connectActivityLogDatabase(array $mainConfig): ?PDO
{
    return connectDatabase(activityLogDatabaseConfig($mainConfig), false);
}

function activityLogTableName(string $kind, string $code): string
{
    $kind = strtoupper(trim($kind));
    $code = trim($code);
    if (!in_array($kind, ['TC', 'EGM'], true) || preg_match('/^[0-9]{4,}$/D', $code) !== 1) {
        throw new InvalidArgumentException('Invalid activity log instance identifier.');
    }
    // MySQL table names are case-sensitive on most Linux/cPanel hosts. Dumps
    // produced on Windows normalize names to lower case, so log tables use a
    // canonical lower-case identifier everywhere.
    return strtolower($kind . '_' . $code . '_activity_logs');
}

function ensureActivityLogRegistry(PDO $pdo): void
{
    static $ready = [];
    $key = spl_object_id($pdo);
    if (!empty($ready[$key])) return;
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `activity_log_instances` (
  `instance_kind` VARCHAR(8) NOT NULL,
  `instance_code` VARCHAR(64) NOT NULL,
  `table_name` VARCHAR(191) NOT NULL,
  `schema_version` VARCHAR(32) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`instance_kind`, `instance_code`),
  UNIQUE KEY `uq_activity_log_table` (`table_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $ready[$key] = true;
}

function ensureActivityLogTable(PDO $pdo, string $kind, string $code): string
{
    static $ready = [];
    $table = activityLogTableName($kind, $code);
    $key = spl_object_id($pdo) . ':' . $table;
    if (!empty($ready[$key])) return $table;
    ensureActivityLogRegistry($pdo);
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `{$table}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_key` CHAR(64) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `work_id` VARCHAR(128) NULL,
  `session_id` VARCHAR(191) NULL,
  `level` VARCHAR(16) NOT NULL DEFAULT 'info',
  `action` VARCHAR(128) NOT NULL,
  `entity_type` VARCHAR(64) NULL,
  `entity_id` VARCHAR(191) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(512) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'success',
  `message` TEXT NULL,
  `metadata_json` LONGTEXT NULL,
  `occurred_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_activity_source` (`source_key`),
  KEY `idx_activity_user_time` (`user_id`, `occurred_at`),
  KEY `idx_activity_work_time` (`work_id`, `occurred_at`),
  KEY `idx_activity_action_time` (`action`, `occurred_at`),
  KEY `idx_activity_action_work_time` (`action`, `work_id`, `occurred_at`),
  KEY `idx_activity_occurred` (`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $indexStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM `information_schema`.`statistics` WHERE `table_schema`=DATABASE() '
        . 'AND `table_name`=:table_name AND `index_name`=:index_name'
    );
    $indexStatement->execute([':table_name' => $table, ':index_name' => 'idx_activity_action_work_time']);
    if ((int)$indexStatement->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `{$table}` ADD KEY `idx_activity_action_work_time` (`action`,`work_id`,`occurred_at`)");
    }
    $statement = $pdo->prepare(
        'INSERT INTO `activity_log_instances` (`instance_kind`,`instance_code`,`table_name`,`schema_version`) '
        . 'VALUES (:kind,:code,:table_name,:version) ON DUPLICATE KEY UPDATE '
        . '`table_name`=VALUES(`table_name`),`schema_version`=VALUES(`schema_version`)'
    );
    $statement->execute([
        ':kind' => strtoupper($kind),
        ':code' => $code,
        ':table_name' => $table,
        ':version' => ACTIVITY_LOG_DATABASE_SCHEMA_VERSION,
    ]);
    $ready[$key] = true;
    return $table;
}

function activityLogProjectConfig(string $projectRoot): array
{
    return loadConfig(rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php');
}

function activityLogDatabaseForProject(string $projectRoot): PDO
{
    $pdo = connectActivityLogDatabase(activityLogProjectConfig($projectRoot));
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Unable to connect to the activity logs database.');
    }
    return $pdo;
}

function activityLogFindProjectRoot(string $path): string
{
    $root = is_dir($path) ? $path : dirname($path);
    while (!is_file($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php')) {
        $parent = dirname($root);
        if ($parent === $root) throw new RuntimeException('Unable to locate the project database configuration.');
        $root = $parent;
    }
    return $root;
}
