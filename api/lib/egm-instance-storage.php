<?php
declare(strict_types=1);

const EGM_INSTANCE_SCHEMA_VERSION = '2026-08-19.2';
const EGM_INSTANCE_SCHEMA_VERSION_KEY = '__egm_schema_version';
const EGM_INSTANCE_COMPATIBLE_SCHEMA_VERSIONS = ['2026-08-19.1', EGM_INSTANCE_SCHEMA_VERSION];

require_once __DIR__ . '/egm-registry.php';
require_once __DIR__ . '/activity-log-storage.php';

const EGM_DEVELOP_CODE = '00000';
const EGM_DEVELOP_NAME = 'EGM Develop';
const EGM_DEVELOP_DIRECTORY = 'mini apps/Event Guest Manager';

function normalizeEgmInstanceCode($value): string
{
    $code = trim((string)$value);
    return preg_match('/^[0-9]{4,}$/D', $code) === 1 ? $code : '';
}

/** @return array<string, string> */
function egmInstanceTableNames(string $code): array
{
    $normalized = normalizeEgmInstanceCode($code);
    if ($normalized === '') {
        throw new InvalidArgumentException('Invalid EGM instance code.');
    }
    return [
        'data' => 'egm_' . $normalized,
        'users' => 'egm_' . $normalized . '_users',
        'user_periods' => 'egm_' . $normalized . '_user_periods',
        'answers' => 'egm_' . $normalized . '_answers',
        'teams' => 'egm_' . $normalized . '_teams',
        'team_members' => 'egm_' . $normalized . '_team_members',
        'photo_submissions' => 'egm_' . $normalized . '_photo_submissions',
        'prize_awards' => 'egm_' . $normalized . '_prize_awards',
        'pot_winners' => 'egm_' . $normalized . '_pot_winners',
        'login_attempts' => 'egm_' . $normalized . '_login_attempts',
        'activity_logs' => 'egm_' . $normalized . '_activity_logs',
    ];
}

function egmInstanceTableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM `information_schema`.`tables` WHERE `table_schema` = DATABASE() AND `table_name` = :table'
    );
    $statement->execute([':table' => $table]);
    return (int)$statement->fetchColumn() > 0;
}

function egmInstanceColumnExists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM `information_schema`.`columns` '
        . 'WHERE `table_schema` = DATABASE() AND `table_name` = :table AND `column_name` = :column'
    );
    $statement->execute([':table' => $table, ':column' => $column]);
    return (int)$statement->fetchColumn() > 0;
}

function egmInstanceColumnIsNullable(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT `IS_NULLABLE` FROM `information_schema`.`columns` '
        . 'WHERE `table_schema` = DATABASE() AND `table_name` = :table AND `column_name` = :column LIMIT 1'
    );
    $statement->execute([':table' => $table, ':column' => $column]);
    return strtoupper((string)$statement->fetchColumn()) === 'YES';
}

function egmInstanceIndexExists(PDO $pdo, string $table, string $index): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM `information_schema`.`statistics` '
        . 'WHERE `table_schema` = DATABASE() AND `table_name` = :table AND `index_name` = :index'
    );
    $statement->execute([':table' => $table, ':index' => $index]);
    return (int)$statement->fetchColumn() > 0;
}

/** @return array<string, bool> */
function &egmInstanceEnsureCache(): array
{
    static $cache = [];
    return $cache;
}

function egmInstanceAddColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!egmInstanceColumnExists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function egmInstanceMigrateLegacyDirectoryPaths(PDO $pdo, array $tables): void
{
    $legacyPrefix = EGM_LEGACY_INSTANCES_DIRECTORY . '/';
    $canonicalPrefix = EGM_INSTANCES_DIRECTORY . '/';
    $userPeriodsTable = (string)($tables['user_periods'] ?? '');
    $dataTable = (string)($tables['data'] ?? '');
    if ($userPeriodsTable !== '') {
        $statement = $pdo->prepare(
            "UPDATE `{$userPeriodsTable}` SET `invite_card_file` = REPLACE(`invite_card_file`, :legacy, :canonical) "
            . "WHERE `invite_card_file` LIKE :legacy_pattern"
        );
        $statement->execute([
            ':legacy' => $legacyPrefix,
            ':canonical' => $canonicalPrefix,
            ':legacy_pattern' => $legacyPrefix . '%',
        ]);
    }
    if ($dataTable !== '') {
        $statement = $pdo->prepare(
            "UPDATE `{$dataTable}` SET `payload` = REPLACE(`payload`, :legacy_payload_value, :canonical_payload_value), "
            . "`file_path` = CASE WHEN `file_path` IS NULL THEN NULL ELSE REPLACE(`file_path`, :legacy_file_value, :canonical_file_value) END "
            . "WHERE `payload` LIKE :legacy_payload OR `file_path` LIKE :legacy_file"
        );
        $statement->execute([
            ':legacy_payload_value' => $legacyPrefix,
            ':canonical_payload_value' => $canonicalPrefix,
            ':legacy_file_value' => $legacyPrefix,
            ':canonical_file_value' => $canonicalPrefix,
            ':legacy_payload' => '%' . $legacyPrefix . '%',
            ':legacy_file' => '%' . $legacyPrefix . '%',
        ]);
    }
}

/** @return array<string, string> */
function ensureEgmInstanceTables(PDO $pdo, string $code): array
{
    $tables = egmInstanceTableNames($code);
    $cache =& egmInstanceEnsureCache();
    $cacheKey = spl_object_id($pdo) . ':' . normalizeEgmInstanceCode($code);
    if (isset($cache[$cacheKey])) {
        return $tables;
    }
    $dataTable = $tables['data'];
    $usersTable = $tables['users'];

    // Schema migration is an administrative operation, not request-time work.
    // The persisted marker makes ordinary document reads a single indexed
    // lookup instead of repeating all schema and legacy-data checks.
    try {
        $versionStatement = $pdo->prepare(
            "SELECT `payload` FROM `{$dataTable}` WHERE `data_key` = :data_key LIMIT 1"
        );
        $versionStatement->execute([':data_key' => EGM_INSTANCE_SCHEMA_VERSION_KEY]);
        $storedSchemaVersion = trim((string)$versionStatement->fetchColumn());
        // Version .2 moved activity logs to their separate database; it did
        // not change the core instance-table schema. Core dumps made just
        // before that split carry .1 and are safe to use without repeating
        // the expensive request-time table migration on shared hosting.
        if (in_array($storedSchemaVersion, EGM_INSTANCE_COMPATIBLE_SCHEMA_VERSIONS, true)) {
            $cache[$cacheKey] = true;
            return $tables;
        }
    } catch (Throwable) {
        // A missing table/column means the full schema migration is required.
    }

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `{$dataTable}` (
  `data_key` VARCHAR(128) NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `periods` LONGTEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`data_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    if (!egmInstanceColumnExists($pdo, $dataTable, 'periods')) {
        $pdo->exec("ALTER TABLE `{$dataTable}` ADD COLUMN `periods` LONGTEXT NULL AFTER `payload`");
    }
    egmInstanceAddColumnIfMissing($pdo, $dataTable, 'storage_kind', "VARCHAR(32) NOT NULL DEFAULT 'json'");
    egmInstanceAddColumnIfMissing($pdo, $dataTable, 'file_path', 'VARCHAR(1024) NULL');
    egmInstanceAddColumnIfMissing($pdo, $dataTable, 'file_data', 'LONGBLOB NULL');
    egmInstanceAddColumnIfMissing($pdo, $dataTable, 'content_sha256', 'CHAR(64) NULL');
    egmInstanceAddColumnIfMissing($pdo, $dataTable, 'file_size', 'BIGINT UNSIGNED NULL');
    egmInstanceAddColumnIfMissing($pdo, $dataTable, 'file_mtime', 'BIGINT UNSIGNED NULL');
    egmInstanceAddColumnIfMissing($pdo, $dataTable, 'file_chunk', 'INT UNSIGNED NULL');
    egmInstanceAddColumnIfMissing($pdo, $dataTable, 'sequence_value', 'BIGINT UNSIGNED NULL');
    if (!egmInstanceIndexExists($pdo, $dataTable, 'idx_egm_storage_kind')) {
        $pdo->exec("ALTER TABLE `{$dataTable}` ADD KEY `idx_egm_storage_kind` (`storage_kind`)");
    }

    if (!egmInstanceTableExists($pdo, $usersTable)) {
        if (egmInstanceTableExists($pdo, 'organizational_event_users')) {
            $pdo->exec("CREATE TABLE `{$usersTable}` LIKE `organizational_event_users`");
        } else {
            $pdo->exec(<<<SQL
CREATE TABLE `{$usersTable}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `work_id` VARCHAR(128) NOT NULL DEFAULT '',
  `first_name` VARCHAR(191) NOT NULL DEFAULT '',
  `last_name` VARCHAR(191) NOT NULL DEFAULT '',
  `national_id` VARCHAR(32) NULL DEFAULT NULL,
  `phone_number` VARCHAR(32) NOT NULL DEFAULT '',
  `deputy` VARCHAR(191) NOT NULL DEFAULT '',
  `general_department` VARCHAR(191) NOT NULL DEFAULT '',
  `department` VARCHAR(191) NOT NULL DEFAULT '',
  `gender` VARCHAR(32) NOT NULL DEFAULT '',
  `postal_level` VARCHAR(64) NOT NULL DEFAULT '',
  `source_row` INT UNSIGNED NOT NULL,
  `imported_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_users_national_id` (`national_id`),
  KEY `idx_org_users_work_id` (`work_id`),
  KEY `idx_org_users_phone_number` (`phone_number`),
  KEY `idx_org_users_structure` (`deputy`, `general_department`, `department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        }
    }

    egmInstanceAddColumnIfMissing($pdo, $usersTable, 'password_hash', 'VARCHAR(255) NULL');
    egmInstanceAddColumnIfMissing($pdo, $usersTable, 'is_admin', 'TINYINT(1) NOT NULL DEFAULT 0');
    egmInstanceAddColumnIfMissing($pdo, $usersTable, 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
    egmInstanceAddColumnIfMissing($pdo, $usersTable, 'login_count', 'INT UNSIGNED NOT NULL DEFAULT 0');
    egmInstanceAddColumnIfMissing($pdo, $usersTable, 'last_login_at', 'DATETIME NULL');
    egmInstanceAddColumnIfMissing($pdo, $usersTable, 'total_score', 'INT NOT NULL DEFAULT 0');
    egmInstanceAddColumnIfMissing($pdo, $usersTable, 'roll_count', 'INT UNSIGNED NOT NULL DEFAULT 0');
    egmInstanceAddColumnIfMissing($pdo, $usersTable, 'state_json', 'LONGTEXT NULL');
    egmInstanceAddColumnIfMissing($pdo, $usersTable, 'source_type', "VARCHAR(16) NOT NULL DEFAULT 'custom'");
    egmInstanceAddColumnIfMissing($pdo, $usersTable, 'source_user_id', 'BIGINT UNSIGNED NULL');
    egmInstanceAddColumnIfMissing($pdo, $usersTable, 'guest_number', 'VARCHAR(32) NULL');
    egmInstanceAddColumnIfMissing($pdo, $usersTable, 'is_uninvited_guest', 'TINYINT(1) NOT NULL DEFAULT 0');
    egmInstanceAddColumnIfMissing($pdo, $usersTable, 'outside_organization', 'TINYINT(1) NOT NULL DEFAULT 0');
    egmInstanceAddColumnIfMissing($pdo, $usersTable, 'uninvited_registered_at', 'DATETIME NULL');
    egmInstanceAddColumnIfMissing($pdo, $usersTable, 'uninvited_registered_by', 'VARCHAR(191) NULL');
    egmInstanceAddColumnIfMissing($pdo, $usersTable, 'egm_updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    if (!egmInstanceColumnIsNullable($pdo, $usersTable, 'national_id')) {
        $pdo->exec("ALTER TABLE `{$usersTable}` MODIFY COLUMN `national_id` VARCHAR(32) NULL DEFAULT NULL");
    }
    if (!egmInstanceIndexExists($pdo, $usersTable, 'uq_egm_users_national_id')) {
        $pdo->exec("ALTER TABLE `{$usersTable}` ADD UNIQUE KEY `uq_egm_users_national_id` (`national_id`)");
    }
    if (!egmInstanceIndexExists($pdo, $usersTable, 'idx_egm_users_source')) {
        $pdo->exec("ALTER TABLE `{$usersTable}` ADD KEY `idx_egm_users_source` (`source_type`, `source_user_id`)");
    }
    if (!egmInstanceIndexExists($pdo, $usersTable, 'uq_egm_users_guest_number')) {
        $pdo->exec("ALTER TABLE `{$usersTable}` ADD UNIQUE KEY `uq_egm_users_guest_number` (`guest_number`)");
    }
    if (!egmInstanceIndexExists($pdo, $usersTable, 'idx_egm_users_uninvited')) {
        $pdo->exec("ALTER TABLE `{$usersTable}` ADD KEY `idx_egm_users_uninvited` (`is_uninvited_guest`, `uninvited_registered_at`)");
    }

    $userPeriodsTable = $tables['user_periods'];
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `{$userPeriodsTable}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `period_code` VARCHAR(128) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'not_started',
  `score` INT NOT NULL DEFAULT 0,
  `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `started_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  `state_json` LONGTEXT NULL,
  `invitation_source` VARCHAR(16) NOT NULL DEFAULT 'custom',
  `invited_by` VARCHAR(191) NULL,
  `invited_at` DATETIME NULL,
  `entered_date` DATE NULL,
  `entered_time` TIME NULL,
  `quit_date` DATE NULL,
  `quit_time` TIME NULL,
  `attendance_state` VARCHAR(32) NOT NULL DEFAULT 'not_entered',
  `last_control_condition` VARCHAR(32) NULL,
  `last_control_action` VARCHAR(16) NULL,
  `last_control_message` VARCHAR(1000) NULL,
  `last_control_at` DATETIME NULL,
  `is_uninvited_guest` TINYINT(1) NOT NULL DEFAULT 0,
  `uninvited_registered_at` DATETIME NULL,
  `uninvited_registered_by` VARCHAR(191) NULL,
  `invite_card_code` VARCHAR(191) NULL,
  `invite_card_file` VARCHAR(512) NULL,
  `invite_card_generated_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_period` (`user_id`, `period_code`),
  UNIQUE KEY `uq_invite_card_code` (`invite_card_code`),
  KEY `idx_period_status` (`period_code`, `status`),
  CONSTRAINT `fk_{$userPeriodsTable}_user` FOREIGN KEY (`user_id`) REFERENCES `{$usersTable}` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'invitation_source', "VARCHAR(16) NOT NULL DEFAULT 'custom'");
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'invited_by', 'VARCHAR(191) NULL');
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'invited_at', 'DATETIME NULL');
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'entered_date', 'DATE NULL');
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'entered_time', 'TIME NULL');
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'quit_date', 'DATE NULL');
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'quit_time', 'TIME NULL');
    $attendanceStateAdded = !egmInstanceColumnExists($pdo, $userPeriodsTable, 'attendance_state');
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'attendance_state', "VARCHAR(32) NOT NULL DEFAULT 'not_entered'");
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'last_control_condition', 'VARCHAR(32) NULL');
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'last_control_action', 'VARCHAR(16) NULL');
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'last_control_message', 'VARCHAR(1000) NULL');
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'last_control_at', 'DATETIME NULL');
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'is_uninvited_guest', 'TINYINT(1) NOT NULL DEFAULT 0');
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'uninvited_registered_at', 'DATETIME NULL');
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'uninvited_registered_by', 'VARCHAR(191) NULL');
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'invite_card_code', 'VARCHAR(191) NULL');
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'invite_card_file', 'VARCHAR(512) NULL');
    egmInstanceAddColumnIfMissing($pdo, $userPeriodsTable, 'invite_card_generated_at', 'DATETIME NULL');
    if (!egmInstanceIndexExists($pdo, $userPeriodsTable, 'uq_invite_card_code')) {
        $pdo->exec("ALTER TABLE `{$userPeriodsTable}` ADD UNIQUE KEY `uq_invite_card_code` (`invite_card_code`)");
    }
    if (!egmInstanceIndexExists($pdo, $userPeriodsTable, 'idx_period_entry')) {
        $pdo->exec("ALTER TABLE `{$userPeriodsTable}` ADD KEY `idx_period_entry` (`period_code`, `entered_date`, `entered_time`)");
    }
    if (!egmInstanceIndexExists($pdo, $userPeriodsTable, 'idx_period_quit')) {
        $pdo->exec("ALTER TABLE `{$userPeriodsTable}` ADD KEY `idx_period_quit` (`period_code`, `quit_date`, `quit_time`)");
    }
    if (!egmInstanceIndexExists($pdo, $userPeriodsTable, 'idx_period_attendance')) {
        $pdo->exec("ALTER TABLE `{$userPeriodsTable}` ADD KEY `idx_period_attendance` (`period_code`, `attendance_state`)");
    }
    if (!egmInstanceIndexExists($pdo, $userPeriodsTable, 'idx_period_uninvited')) {
        $pdo->exec("ALTER TABLE `{$userPeriodsTable}` ADD KEY `idx_period_uninvited` (`period_code`, `is_uninvited_guest`)");
    }
    if ($attendanceStateAdded) {
        $pdo->exec(
            "UPDATE `{$userPeriodsTable}` SET `attendance_state` = CASE "
            . "WHEN `entered_date` IS NOT NULL AND `entered_time` IS NOT NULL AND `quit_date` IS NOT NULL AND `quit_time` IS NOT NULL THEN 'quit_completed' "
            . "WHEN `entered_date` IS NOT NULL AND `entered_time` IS NOT NULL THEN 'entered' "
            . "WHEN `quit_date` IS NOT NULL OR `quit_time` IS NOT NULL THEN 'invalid_quit_without_entry' "
            . "ELSE 'not_entered' END"
        );
    }

    $answersTable = $tables['answers'];
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `{$answersTable}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `period_code` VARCHAR(128) NOT NULL,
  `question_code` VARCHAR(191) NOT NULL,
  `attempt_number` INT UNSIGNED NOT NULL DEFAULT 1,
  `answer_text` LONGTEXT NULL,
  `answer_json` LONGTEXT NULL,
  `is_correct` TINYINT(1) NULL,
  `score` INT NOT NULL DEFAULT 0,
  `answered_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_period_question_attempt` (`user_id`, `period_code`, `question_code`, `attempt_number`),
  KEY `idx_period_question` (`period_code`, `question_code`),
  CONSTRAINT `fk_{$answersTable}_user` FOREIGN KEY (`user_id`) REFERENCES `{$usersTable}` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $teamsTable = $tables['teams'];
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `{$teamsTable}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_code` VARCHAR(128) NOT NULL,
  `team_code` VARCHAR(128) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `leader_user_id` BIGINT UNSIGNED NULL,
  `join_type` VARCHAR(32) NOT NULL DEFAULT 'private',
  `status` VARCHAR(32) NOT NULL DEFAULT 'forming',
  `challenge_code` VARCHAR(128) NULL,
  `payload_json` LONGTEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_period_team_code` (`period_code`, `team_code`),
  KEY `idx_team_leader` (`leader_user_id`),
  CONSTRAINT `fk_{$teamsTable}_leader` FOREIGN KEY (`leader_user_id`) REFERENCES `{$usersTable}` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $teamMembersTable = $tables['team_members'];
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `{$teamMembersTable}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `team_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role` VARCHAR(32) NOT NULL DEFAULT 'member',
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `payload_json` LONGTEXT NULL,
  `joined_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team_member` (`team_id`, `user_id`),
  KEY `idx_team_member_user` (`user_id`),
  CONSTRAINT `fk_{$teamMembersTable}_team` FOREIGN KEY (`team_id`) REFERENCES `{$teamsTable}` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_{$teamMembersTable}_user` FOREIGN KEY (`user_id`) REFERENCES `{$usersTable}` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $photoSubmissionsTable = $tables['photo_submissions'];
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `{$photoSubmissionsTable}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `period_code` VARCHAR(128) NOT NULL,
  `photo_code` VARCHAR(128) NOT NULL,
  `submission_text` LONGTEXT NULL,
  `media_path` VARCHAR(1024) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'submitted',
  `score` INT NOT NULL DEFAULT 0,
  `metadata_json` LONGTEXT NULL,
  `submitted_at` DATETIME NULL,
  `reviewed_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_period_photo` (`user_id`, `period_code`, `photo_code`),
  KEY `idx_photo_review` (`period_code`, `status`),
  CONSTRAINT `fk_{$photoSubmissionsTable}_user` FOREIGN KEY (`user_id`) REFERENCES `{$usersTable}` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $prizeAwardsTable = $tables['prize_awards'];
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `{$prizeAwardsTable}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `award_code` VARCHAR(191) NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `period_code` VARCHAR(128) NULL,
  `prize_code` VARCHAR(191) NULL,
  `level_code` VARCHAR(191) NULL,
  `prize_name` VARCHAR(255) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'awarded',
  `payload_json` LONGTEXT NULL,
  `awarded_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prize_award_code` (`award_code`),
  KEY `idx_prize_award_user` (`user_id`, `awarded_at`),
  CONSTRAINT `fk_{$prizeAwardsTable}_user` FOREIGN KEY (`user_id`) REFERENCES `{$usersTable}` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $potWinnersTable = $tables['pot_winners'];
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `{$potWinnersTable}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pot_level_code` VARCHAR(191) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `winner_position` INT UNSIGNED NULL,
  `prize_name` VARCHAR(255) NULL,
  `payload_json` LONGTEXT NULL,
  `won_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pot_level_user` (`pot_level_code`, `user_id`),
  KEY `idx_pot_winner_user` (`user_id`),
  CONSTRAINT `fk_{$potWinnersTable}_user` FOREIGN KEY (`user_id`) REFERENCES `{$usersTable}` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $loginAttemptsTable = $tables['login_attempts'];
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `{$loginAttemptsTable}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_key` CHAR(64) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `work_id` VARCHAR(128) NULL,
  `ip_address` VARCHAR(45) NULL,
  `was_successful` TINYINT(1) NOT NULL DEFAULT 0,
  `failure_reason` VARCHAR(191) NULL,
  `session_id` VARCHAR(191) NULL,
  `metadata_json` LONGTEXT NULL,
  `attempted_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_login_source` (`source_key`),
  KEY `idx_login_user_time` (`user_id`, `attempted_at`),
  KEY `idx_login_work_ip` (`work_id`, `ip_address`, `attempted_at`),
  CONSTRAINT `fk_{$loginAttemptsTable}_user` FOREIGN KEY (`user_id`) REFERENCES `{$usersTable}` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    egmInstanceMigrateLegacyDirectoryPaths($pdo, $tables);
    $cache[$cacheKey] = true;
    try {
        egmInstanceAssignGuestNumbers($pdo, $code);
    } catch (Throwable $error) {
        unset($cache[$cacheKey]);
        throw $error;
    }
    $schemaVersionStatement = $pdo->prepare(
        "INSERT INTO `{$dataTable}` (`data_key`, `payload`, `storage_kind`) VALUES (:data_key, :payload, 'json') "
        . "ON DUPLICATE KEY UPDATE `payload` = VALUES(`payload`), `storage_kind` = 'json', `updated_at` = CURRENT_TIMESTAMP"
    );
    $schemaVersionStatement->execute([
        ':data_key' => EGM_INSTANCE_SCHEMA_VERSION_KEY,
        ':payload' => EGM_INSTANCE_SCHEMA_VERSION,
    ]);
    return $tables;
}

function egmInstanceFormatGuestNumber(int $number): string
{
    if ($number < 1) {
        return '';
    }
    return str_pad((string)$number, 4, '0', STR_PAD_LEFT);
}

/**
 * Assigns stable, monotonically increasing guest numbers to EGM users.
 * Existing numbers are never changed or reused. The caller's transaction is
 * reused when one is already active, so user insertion and number allocation
 * can be committed atomically.
 *
 * @param array<int,int|string> $userIds Empty means every unnumbered EGM user.
 * @return array<int,string> Map of user id to formatted guest number.
 */
function egmInstanceAssignGuestNumbers(PDO $pdo, string $code, array $userIds = []): array
{
    $normalizedCode = normalizeEgmInstanceCode($code);
    if ($normalizedCode === '') {
        throw new InvalidArgumentException('Invalid EGM instance code.');
    }
    $tables = ensureEgmInstanceTables($pdo, $normalizedCode);
    $dataTable = (string)$tables['data'];
    $usersTable = (string)$tables['users'];
    $normalizedIds = [];
    foreach ($userIds as $userId) {
        $id = (int)$userId;
        if ($id > 0) {
            $normalizedIds[$id] = $id;
        }
    }
    // The schema guard calls this on request startup. Avoid taking the global
    // sequence row lock when there is no assignment work; concurrent EGM tabs
    // previously deadlocked here even though every guest was already numbered.
    if (!$normalizedIds) {
        $missing = $pdo->query("SELECT 1 FROM `{$usersTable}` WHERE `guest_number` IS NULL LIMIT 1");
        if (!$missing || $missing->fetchColumn() === false) {
            return [];
        }
    } else {
        $placeholders = implode(',', array_fill(0, count($normalizedIds), '?'));
        $missing = $pdo->prepare("SELECT 1 FROM `{$usersTable}` WHERE `guest_number` IS NULL AND `id` IN ({$placeholders}) LIMIT 1");
        $missing->execute(array_values($normalizedIds));
        if ($missing->fetchColumn() === false) {
            return [];
        }
    }
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $initialize = $pdo->prepare(
            "INSERT IGNORE INTO `{$dataTable}` (`data_key`, `payload`, `sequence_value`) "
            . "VALUES ('guest_number_sequence', '{}', 1)"
        );
        $initialize->execute();
        $sequence = $pdo->query(
            "SELECT `sequence_value` FROM `{$dataTable}` WHERE `data_key` = 'guest_number_sequence' FOR UPDATE"
        );
        $next = max(1, (int)($sequence ? $sequence->fetchColumn() : 1));
        $maximum = (int)$pdo->query(
            "SELECT COALESCE(MAX(CAST(`guest_number` AS UNSIGNED)), 0) FROM `{$usersTable}` "
            . "WHERE `guest_number` REGEXP '^[0-9]+$'"
        )->fetchColumn();
        $next = max($next, $maximum + 1);

        $where = '`guest_number` IS NULL';
        $params = [];
        if ($normalizedIds) {
            $placeholders = [];
            foreach (array_values($normalizedIds) as $index => $userId) {
                $placeholder = ':user_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $userId;
            }
            $where .= ' AND `id` IN (' . implode(',', $placeholders) . ')';
        }
        $select = $pdo->prepare("SELECT `id` FROM `{$usersTable}` WHERE {$where} ORDER BY `id` FOR UPDATE");
        $select->execute($params);
        $missingIds = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
        $update = $pdo->prepare(
            "UPDATE `{$usersTable}` SET `guest_number` = :guest_number WHERE `id` = :id AND `guest_number` IS NULL"
        );
        foreach ($missingIds as $userId) {
            $guestNumber = egmInstanceFormatGuestNumber($next++);
            $update->execute([':guest_number' => $guestNumber, ':id' => $userId]);
        }
        $updateSequence = $pdo->prepare(
            "UPDATE `{$dataTable}` SET `sequence_value` = :next, `updated_at` = CURRENT_TIMESTAMP "
            . "WHERE `data_key` = 'guest_number_sequence'"
        );
        $updateSequence->execute([':next' => $next]);

        $result = [];
        if ($normalizedIds) {
            $placeholders = [];
            $lookupParams = [];
            foreach (array_values($normalizedIds) as $index => $userId) {
                $placeholder = ':lookup_' . $index;
                $placeholders[] = $placeholder;
                $lookupParams[$placeholder] = $userId;
            }
            $lookup = $pdo->prepare(
                "SELECT `id`, `guest_number` FROM `{$usersTable}` WHERE `id` IN (" . implode(',', $placeholders) . ')'
            );
            $lookup->execute($lookupParams);
            foreach ($lookup->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[(int)$row['id']] = (string)($row['guest_number'] ?? '');
            }
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function egmInstanceGuestNumberForMissionUser(string $missionDir, string $workId, string $nationalId = ''): string
{
    try {
        $resolvedMission = realpath($missionDir);
        if (!is_string($resolvedMission)) {
            return '';
        }
        $root = $resolvedMission;
        while (!is_file($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php')) {
            $parent = dirname($root);
            if ($parent === $root) {
                return '';
            }
            $root = $parent;
        }
        if (!function_exists('loadConfig') || !function_exists('connectDatabase')) {
            return '';
        }
        $pdo = connectDatabase(loadConfig($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php'));
        $registry = $pdo instanceof PDO ? egmInstanceRegistryForDirectory($pdo, $resolvedMission) : null;
        if (!$pdo instanceof PDO || !is_array($registry)) {
            return '';
        }
        $tables = ensureEgmInstanceTables($pdo, (string)$registry['code']);
        $clauses = [];
        $params = [];
        $nationalId = trim($nationalId);
        $workId = trim($workId);
        if ($nationalId !== '') {
            $clauses[] = '`national_id` = :national_id';
            $params[':national_id'] = $nationalId;
        }
        if ($workId !== '') {
            $clauses[] = '`work_id` = :work_id';
            $params[':work_id'] = $workId;
        }
        if (!$clauses) {
            return '';
        }
        $statement = $pdo->prepare(
            "SELECT `guest_number` FROM `{$tables['users']}` WHERE " . implode(' OR ', $clauses)
            . ' ORDER BY CASE WHEN `national_id` = :preferred_national THEN 0 ELSE 1 END, `id` LIMIT 1'
        );
        $params[':preferred_national'] = $nationalId;
        $statement->execute($params);
        return trim((string)$statement->fetchColumn());
    } catch (Throwable $error) {
        error_log('EGM guest-number lookup failed: ' . $error->getMessage());
        return '';
    }
}

function egmInstanceWriteData(PDO $pdo, string $code, string $key, $payload): void
{
    $tables = ensureEgmInstanceTables($pdo, $code);
    $normalizedKey = trim($key);
    if ($normalizedKey === '' || strlen($normalizedKey) > 128) {
        throw new InvalidArgumentException('Invalid EGM data key.');
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode EGM database payload.');
    }
    $table = $tables['data'];
    $statement = $pdo->prepare(
        "INSERT INTO `{$table}` (`data_key`, `payload`) VALUES (:data_key, :payload) "
        . 'ON DUPLICATE KEY UPDATE `payload` = VALUES(`payload`), `updated_at` = CURRENT_TIMESTAMP'
    );
    $statement->execute([':data_key' => $normalizedKey, ':payload' => $json]);
}

function egmInstanceReadData(PDO $pdo, string $code, string $key, $fallback = null)
{
    $tables = ensureEgmInstanceTables($pdo, $code);
    $table = $tables['data'];
    $statement = $pdo->prepare("SELECT `payload` FROM `{$table}` WHERE `data_key` = :data_key LIMIT 1");
    $statement->execute([':data_key' => trim($key)]);
    $payload = $statement->fetchColumn();
    if (!is_string($payload)) {
        return $fallback;
    }
    $decoded = json_decode($payload, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback;
}

function egmInstanceWritePeriods(PDO $pdo, string $code, array $periods): void
{
    $tables = ensureEgmInstanceTables($pdo, $code);
    $json = json_encode(array_values($periods), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode EGM periods.');
    }
    $table = $tables['data'];
    $statement = $pdo->prepare(
        "INSERT INTO `{$table}` (`data_key`, `payload`, `periods`) VALUES ('periods', :payload, :periods) "
        . 'ON DUPLICATE KEY UPDATE `payload` = VALUES(`payload`), `periods` = VALUES(`periods`), `updated_at` = CURRENT_TIMESTAMP'
    );
    $statement->execute([':payload' => $json, ':periods' => $json]);
}

function egmInstanceReadPeriods(PDO $pdo, string $code): array
{
    $tables = ensureEgmInstanceTables($pdo, $code);
    $table = $tables['data'];
    $statement = $pdo->query("SELECT `periods` FROM `{$table}` WHERE `data_key` = 'periods' LIMIT 1");
    $json = $statement ? $statement->fetchColumn() : false;
    if (!is_string($json)) {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? array_values($decoded) : [];
}

function egmInstanceWriteMissionPeriodsUsingProjectConfig(
    string $projectRoot,
    string $missionDir,
    array $periods
): bool {
    if (!function_exists('loadConfig') || !function_exists('connectDatabase')) {
        return false;
    }
    $config = loadConfig(rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php');
    $pdo = connectDatabase($config);
    if (!$pdo instanceof PDO) {
        return false;
    }
    $registry = egmInstanceRegistryForDirectory($pdo, $missionDir);
    if (!is_array($registry)) {
        return true;
    }
    egmInstanceWritePeriods($pdo, (string)$registry['code'], $periods);
    return true;
}

function dropEgmInstanceTables(PDO $pdo, string $code): void
{
    $normalizedCode = normalizeEgmInstanceCode($code);
    $tables = egmInstanceTableNames($code);
    if ($normalizedCode !== '' && egmInstanceTableExists($pdo, 'egm_invite_card_routes')) {
        $deleteRoutes = $pdo->prepare('DELETE FROM `egm_invite_card_routes` WHERE `egm_code` = :egm_code');
        $deleteRoutes->execute([':egm_code' => $normalizedCode]);
    }
    if ($normalizedCode !== '') {
        $logsConfig = loadConfig(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php');
        $logsPdo = connectActivityLogDatabase($logsConfig);
        if ($logsPdo instanceof PDO) {
            $logsPdo->exec("DROP TABLE IF EXISTS `{$tables['activity_logs']}`");
            if (egmInstanceTableExists($logsPdo, 'activity_log_instances')) {
                $deleteLogRegistry = $logsPdo->prepare(
                    "DELETE FROM `activity_log_instances` WHERE `instance_kind`='EGM' AND `instance_code`=:code"
                );
                $deleteLogRegistry->execute([':code' => $normalizedCode]);
            }
        }
    }
    foreach ([
        'team_members',
        'answers',
        'user_periods',
        'photo_submissions',
        'prize_awards',
        'pot_winners',
        'login_attempts',
        'activity_logs',
        'teams',
        'users',
        'data',
    ] as $key) {
        $pdo->exec("DROP TABLE IF EXISTS `{$tables[$key]}`");
    }
    $cache =& egmInstanceEnsureCache();
    unset($cache[spl_object_id($pdo) . ':' . $normalizedCode]);
}

/** @return array<string, int> */
function egmInstanceReadMapping(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return [];
    }
    $mapping = [];
    foreach ($decoded as $key => $value) {
        if (is_numeric($value)) {
            $mapping[(string)$key] = (int)$value;
        }
    }
    return $mapping;
}

function egmInstanceNormalizeHeader(string $value): string
{
    $normalized = strtolower(trim(str_replace(['-', '_'], ' ', $value)));
    return preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
}

function egmInstanceBoolValue($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (int)$value === 1;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on', 'admin', 'ادمین'], true);
}

function egmInstanceIntegerValue($value): int
{
    $text = trim((string)$value);
    return preg_match('/^-?\d+$/D', $text) === 1 ? (int)$text : 0;
}

function egmInstanceResolveColumn(array $header, array $mapping, string $mappingKey, array $fallbacks): int
{
    $mapped = $mapping[$mappingKey] ?? -1;
    if ($mapped >= 0 && $mapped < count($header)) {
        return $mapped;
    }
    $targets = [];
    foreach ($fallbacks as $fallback) {
        $targets[egmInstanceNormalizeHeader((string)$fallback)] = true;
    }
    foreach ($header as $index => $name) {
        if (isset($targets[egmInstanceNormalizeHeader((string)$name)])) {
            return (int)$index;
        }
    }
    return -1;
}

/** @return array<int, array<int, string>> */
function egmInstanceReadCsvRows(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }
    $rows = [];
    while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
        $rows[] = array_map(static fn($value): string => trim((string)$value), is_array($row) ? $row : []);
    }
    fclose($handle);
    return $rows;
}

function egmInstanceRegistryDirectoryForMission(string $missionDir): string
{
    $resolved = realpath($missionDir);
    if (!is_string($resolved)) return '';
    if (basename($resolved) === 'Event Guest Manager' && basename(dirname($resolved)) === 'mini apps') {
        return EGM_DEVELOP_DIRECTORY;
    }
    if (basename(dirname($resolved)) === 'EGMs') {
        return EGM_INSTANCES_DIRECTORY . '/' . basename($resolved);
    }
    return '';
}

/** @return array{code:string,name:string,directory:string} */
function egmInstanceEnsureDevelopmentInstance(PDO $pdo, string $missionDir): array
{
    static $provisioning = false;
    $resolved = realpath($missionDir);
    if (!is_string($resolved) || egmInstanceRegistryDirectoryForMission($resolved) !== EGM_DEVELOP_DIRECTORY) {
        throw new InvalidArgumentException('Invalid EGM development directory.');
    }
    ensureEgmRegistryTable($pdo);
    $pdo->prepare('DELETE FROM `egm` WHERE `directory` = :directory AND `code` <> :code')->execute([
        ':directory' => EGM_DEVELOP_DIRECTORY,
        ':code' => EGM_DEVELOP_CODE,
    ]);
    upsertEgmRegistry($pdo, EGM_DEVELOP_CODE, EGM_DEVELOP_NAME, EGM_DEVELOP_DIRECTORY);
    ensureEgmInstanceTables($pdo, EGM_DEVELOP_CODE);
    $marker = egmInstanceReadData($pdo, EGM_DEVELOP_CODE, 'development_instance', null);
    if (!is_array($marker) && !$provisioning) {
        $provisioning = true;
        try {
            egmInstanceMirrorMissionStorage($pdo, EGM_DEVELOP_CODE, $resolved);
            egmInstanceWriteData($pdo, EGM_DEVELOP_CODE, 'metadata', [
                'code' => EGM_DEVELOP_CODE,
                'name' => EGM_DEVELOP_NAME,
                'directory' => EGM_DEVELOP_DIRECTORY,
                'development' => true,
            ]);
            egmInstanceWriteData($pdo, EGM_DEVELOP_CODE, 'development_instance', [
                'enabled' => true,
                'initializedAt' => gmdate('c'),
            ]);
        } finally {
            $provisioning = false;
        }
    }
    return ['code' => EGM_DEVELOP_CODE, 'name' => EGM_DEVELOP_NAME, 'directory' => EGM_DEVELOP_DIRECTORY];
}

/** @return array{code:string,name:string,directory:string}|null */
function egmInstanceRegistryForDirectory(PDO $pdo, string $missionDir): ?array
{
    $directory = egmInstanceRegistryDirectoryForMission($missionDir);
    if ($directory === '') return null;
    if ($directory === EGM_DEVELOP_DIRECTORY) {
        return egmInstanceEnsureDevelopmentInstance($pdo, $missionDir);
    }
    $statement = $pdo->prepare('SELECT `code`, `name`, `directory` FROM `egm` WHERE `directory` = :directory LIMIT 1');
    $statement->execute([':directory' => $directory]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? [
        'code' => (string)$row['code'],
        'name' => (string)$row['name'],
        'directory' => (string)$row['directory'],
    ] : null;
}

function egmInstanceSyncUsersFromCsv(PDO $pdo, string $code, string $csvPath, string $mappingPath, bool $refreshPasswords = false): int
{
    $tables = ensureEgmInstanceTables($pdo, $code);
    $rows = egmInstanceReadCsvRows($csvPath);
    $mapping = egmInstanceReadMapping($mappingPath);
    $records = [];
    $existingPasswordHashes = [];
    $existingPasswordRows = $pdo->query("SELECT `national_id`, `password_hash` FROM `{$tables['users']}`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($existingPasswordRows as $existingPasswordRow) {
        $existingNationalId = trim((string)($existingPasswordRow['national_id'] ?? ''));
        $existingHash = trim((string)($existingPasswordRow['password_hash'] ?? ''));
        if ($existingNationalId !== '' && $existingHash !== '') {
            $existingPasswordHashes[$existingNationalId] = $existingHash;
        }
    }

    if ($rows && is_array($rows[0] ?? null)) {
        $header = $rows[0];
        $indexes = [
            'work_id' => egmInstanceResolveColumn($header, $mapping, 'workId', ['Work ID', 'work id', 'workid', 'username']),
            'first_name' => egmInstanceResolveColumn($header, $mapping, 'firstName', ['First Name', 'first name', 'name']),
            'last_name' => egmInstanceResolveColumn($header, $mapping, 'lastName', ['Last Name', 'last name', 'family', 'surname']),
            'national_id' => egmInstanceResolveColumn($header, $mapping, 'nationalId', ['National ID', 'national id']),
            'phone_number' => egmInstanceResolveColumn($header, $mapping, 'phoneNumber', ['Phone Number', 'phone number', 'phone', 'mobile']),
            'password' => egmInstanceResolveColumn($header, $mapping, 'password', ['password']),
            'admin' => egmInstanceResolveColumn($header, $mapping, 'admin', ['Admin', 'EGM Admin', 'is admin', 'ادمین']),
            'login_count' => egmInstanceResolveColumn($header, $mapping, 'loginCount', ['logins counts', 'login count']),
            'score' => egmInstanceResolveColumn($header, $mapping, 'score', ['score', 'total score']),
            'roll_count' => egmInstanceResolveColumn($header, $mapping, 'rollCount', ['count of rolls']),
        ];
        if ($indexes['work_id'] < 0 || $indexes['national_id'] < 0) {
            throw new RuntimeException('The EGM invitee mapping must include Work ID and National ID.');
        }

        $oeuByNationalId = [];
        $oeuByWorkId = [];
        if (egmInstanceTableExists($pdo, 'organizational_event_users')) {
            $oeuRows = $pdo->query('SELECT * FROM `organizational_event_users`')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($oeuRows as $oeuRow) {
                $nationalId = trim((string)($oeuRow['national_id'] ?? ''));
                $workId = trim((string)($oeuRow['work_id'] ?? ''));
                if ($nationalId !== '') {
                    $oeuByNationalId[$nationalId] = $oeuRow;
                }
                if ($workId !== '') {
                    $oeuByWorkId[$workId] = $oeuRow;
                }
            }
        }

        foreach (array_slice($rows, 1) as $offset => $row) {
            $workId = trim((string)($row[$indexes['work_id']] ?? ''));
            $nationalId = trim((string)($row[$indexes['national_id']] ?? ''));
            if ($workId === '' || $nationalId === '') {
                continue;
            }
            $oeu = $oeuByNationalId[$nationalId] ?? $oeuByWorkId[$workId] ?? [];
            $state = [];
            foreach ($header as $columnIndex => $columnName) {
                $normalizedHeader = egmInstanceNormalizeHeader((string)$columnName);
                if ($normalizedHeader !== '' && $normalizedHeader !== 'password') {
                    $state[$normalizedHeader] = (string)($row[$columnIndex] ?? '');
                }
            }
            $plainPassword = $indexes['password'] >= 0 ? trim((string)($row[$indexes['password']] ?? '')) : '';
            $stateJson = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $records[$nationalId] = [
                'work_id' => (string)($oeu['work_id'] ?? $workId),
                'first_name' => (string)($oeu['first_name'] ?? ($row[$indexes['first_name']] ?? '')),
                'last_name' => (string)($oeu['last_name'] ?? ($row[$indexes['last_name']] ?? '')),
                'national_id' => (string)($oeu['national_id'] ?? $nationalId),
                'phone_number' => (string)($oeu['phone_number'] ?? ($row[$indexes['phone_number']] ?? '')),
                'deputy' => (string)($oeu['deputy'] ?? ''),
                'general_department' => (string)($oeu['general_department'] ?? ''),
                'department' => (string)($oeu['department'] ?? ''),
                'gender' => (string)($oeu['gender'] ?? ''),
                'postal_level' => (string)($oeu['postal_level'] ?? ''),
                'source_row' => (int)($oeu['source_row'] ?? ($offset + 2)),
                'imported_at' => (string)($oeu['imported_at'] ?? date('Y-m-d H:i:s')),
                'password_hash' => $plainPassword !== '' && ($refreshPasswords || !isset($existingPasswordHashes[$nationalId]))
                    ? password_hash($plainPassword, PASSWORD_DEFAULT)
                    : null,
                'is_admin' => $indexes['admin'] >= 0 && egmInstanceBoolValue($row[$indexes['admin']] ?? '') ? 1 : 0,
                'login_count' => max(0, egmInstanceIntegerValue($indexes['login_count'] >= 0 ? ($row[$indexes['login_count']] ?? 0) : 0)),
                'total_score' => egmInstanceIntegerValue($indexes['score'] >= 0 ? ($row[$indexes['score']] ?? 0) : 0),
                'roll_count' => max(0, egmInstanceIntegerValue($indexes['roll_count'] >= 0 ? ($row[$indexes['roll_count']] ?? 0) : 0)),
                'state_json' => is_string($stateJson) ? $stateJson : '{}',
            ];
        }
    }

    $usersTable = $tables['users'];
    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE `{$usersTable}` SET `is_active` = 0 WHERE `source_type` = 'custom'");
        $insert = $pdo->prepare(<<<SQL
INSERT INTO `{$usersTable}`
(`work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, `deputy`, `general_department`, `department`, `gender`, `postal_level`, `source_row`, `imported_at`, `password_hash`, `is_admin`, `is_active`, `login_count`, `total_score`, `roll_count`, `state_json`, `source_type`)
VALUES
(:work_id, :first_name, :last_name, :national_id, :phone_number, :deputy, :general_department, :department, :gender, :postal_level, :source_row, :imported_at, :password_hash, :is_admin, 1, :login_count, :total_score, :roll_count, :state_json, 'custom')
ON DUPLICATE KEY UPDATE
  `work_id` = VALUES(`work_id`),
  `first_name` = VALUES(`first_name`),
  `last_name` = VALUES(`last_name`),
  `phone_number` = VALUES(`phone_number`),
  `deputy` = VALUES(`deputy`),
  `general_department` = VALUES(`general_department`),
  `department` = VALUES(`department`),
  `gender` = VALUES(`gender`),
  `postal_level` = VALUES(`postal_level`),
  `source_row` = VALUES(`source_row`),
  `imported_at` = VALUES(`imported_at`),
  `password_hash` = COALESCE(VALUES(`password_hash`), `password_hash`),
  `is_admin` = VALUES(`is_admin`),
  `is_active` = 1,
  `login_count` = VALUES(`login_count`),
  `total_score` = VALUES(`total_score`),
  `roll_count` = VALUES(`roll_count`),
  `state_json` = VALUES(`state_json`)
  ,`source_type` = 'custom'
SQL);
        foreach ($records as $record) {
            $insert->execute([
                ':work_id' => $record['work_id'],
                ':first_name' => $record['first_name'],
                ':last_name' => $record['last_name'],
                ':national_id' => $record['national_id'],
                ':phone_number' => $record['phone_number'],
                ':deputy' => $record['deputy'],
                ':general_department' => $record['general_department'],
                ':department' => $record['department'],
                ':gender' => $record['gender'],
                ':postal_level' => $record['postal_level'],
                ':source_row' => $record['source_row'],
                ':imported_at' => $record['imported_at'],
                ':password_hash' => $record['password_hash'],
                ':is_admin' => $record['is_admin'],
                ':login_count' => $record['login_count'],
                ':total_score' => $record['total_score'],
                ':roll_count' => $record['roll_count'],
                ':state_json' => $record['state_json'],
            ]);
        }
        egmInstanceAssignGuestNumbers($pdo, $code);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    return count($records);
}

function egmInstanceSyncMissionUsersFromCsv(PDO $pdo, string $missionDir, string $csvPath, string $mappingPath, bool $refreshPasswords = false): ?int
{
    $registry = egmInstanceRegistryForDirectory($pdo, $missionDir);
    if (!is_array($registry)) {
        return null;
    }
    return egmInstanceSyncUsersFromCsv($pdo, (string)$registry['code'], $csvPath, $mappingPath, $refreshPasswords);
}

function egmInstanceSyncMissionUsersUsingProjectConfig(
    string $projectRoot,
    string $missionDir,
    string $csvPath,
    string $mappingPath,
    bool $refreshPasswords = false
): ?int {
    if (!function_exists('loadConfig') || !function_exists('connectDatabase')) {
        throw new RuntimeException('Database configuration helpers are unavailable.');
    }
    $config = loadConfig(rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php');
    $pdo = connectDatabase($config);
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Unable to connect to the EGM database.');
    }
    return egmInstanceSyncMissionUsersFromCsv($pdo, $missionDir, $csvPath, $mappingPath, $refreshPasswords);
}

function egmInstanceUpdateMissionUserPasswordUsingProjectConfig(
    string $projectRoot,
    string $missionDir,
    string $workId,
    string $plainPassword
): bool {
    $workId = trim($workId);
    if ($workId === '' || $plainPassword === '' || !function_exists('loadConfig') || !function_exists('connectDatabase')) {
        return false;
    }
    $config = loadConfig(rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php');
    $pdo = connectDatabase($config);
    if (!$pdo instanceof PDO) {
        return false;
    }
    $registry = egmInstanceRegistryForDirectory($pdo, $missionDir);
    if (!is_array($registry)) {
        return false;
    }
    $tables = ensureEgmInstanceTables($pdo, (string)$registry['code']);
    $statement = $pdo->prepare("UPDATE `{$tables['users']}` SET `password_hash` = :password_hash WHERE `work_id` = :work_id");
    $statement->execute([
        ':password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
        ':work_id' => $workId,
    ]);
    return $statement->rowCount() > 0;
}

function egmInstanceOeuIdentityKey($value): string
{
    $text = strtr(trim((string)$value), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function egmInstanceOeuNationalIdKey($value): string
{
    $text = preg_replace('/[\s-]+/u', '', egmInstanceOeuIdentityKey($value)) ?? '';
    return preg_match('/^[0-9]{10}$/D', $text) === 1 ? $text : '';
}

/**
 * Fill incomplete EGM guest records from the active OEU directory.
 *
 * Matching is deliberately limited to a stored OEU row id, a valid National ID,
 * or a Work ID that occurs exactly once in OEU. Names and phone numbers are not
 * identities and are therefore never used for automatic matching.
 */
function egmInstanceEnrichUsersFromOeu(PDO $pdo, string $code, bool $refreshOeuSourced = true): int
{
    if (!egmInstanceTableExists($pdo, 'organizational_event_users')) {
        return 0;
    }

    $tables = ensureEgmInstanceTables($pdo, $code);
    $usersTable = (string)$tables['users'];
    $oeuRows = $pdo->query(
        'SELECT `id`, `work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, '
        . '`deputy`, `general_department`, `department`, `gender`, `postal_level`, `source_row`, `imported_at` '
        . 'FROM `organizational_event_users`'
    )->fetchAll(PDO::FETCH_ASSOC);
    if (!$oeuRows) {
        return 0;
    }

    $oeuById = [];
    $oeuByNational = [];
    $duplicateNationals = [];
    $oeuByWork = [];
    $duplicateWorkIds = [];
    foreach ($oeuRows as $oeu) {
        $oeuId = (int)($oeu['id'] ?? 0);
        if ($oeuId > 0) {
            $oeuById[$oeuId] = $oeu;
        }
        $nationalKey = egmInstanceOeuNationalIdKey($oeu['national_id'] ?? '');
        if ($nationalKey !== '') {
            if (isset($oeuByNational[$nationalKey])) {
                $duplicateNationals[$nationalKey] = true;
            } else {
                $oeuByNational[$nationalKey] = $oeu;
            }
        }
        $workKey = egmInstanceOeuIdentityKey($oeu['work_id'] ?? '');
        if ($workKey !== '') {
            if (isset($oeuByWork[$workKey])) {
                $duplicateWorkIds[$workKey] = true;
            } else {
                $oeuByWork[$workKey] = $oeu;
            }
        }
    }
    foreach (array_keys($duplicateNationals) as $key) {
        unset($oeuByNational[$key]);
    }
    foreach (array_keys($duplicateWorkIds) as $key) {
        unset($oeuByWork[$key]);
    }

    $users = $pdo->query(
        "SELECT `id`, `work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, "
        . "`deputy`, `general_department`, `department`, `gender`, `postal_level`, `source_row`, `imported_at`, "
        . "`source_type`, `source_user_id` FROM `{$usersTable}`"
    )->fetchAll(PDO::FETCH_ASSOC);

    $nationalOwners = [];
    foreach ($users as $user) {
        $nationalKey = egmInstanceOeuNationalIdKey($user['national_id'] ?? '');
        if ($nationalKey === '') {
            continue;
        }
        $userId = (int)($user['id'] ?? 0);
        if (isset($nationalOwners[$nationalKey]) && $nationalOwners[$nationalKey] !== $userId) {
            $nationalOwners[$nationalKey] = 0;
        } else {
            $nationalOwners[$nationalKey] = $userId;
        }
    }

    $dataFields = [
        'work_id', 'first_name', 'last_name', 'national_id', 'phone_number',
        'deputy', 'general_department', 'department', 'gender', 'postal_level',
    ];
    $updated = 0;
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($users as $user) {
            $userId = (int)($user['id'] ?? 0);
            if ($userId < 1) {
                continue;
            }
            $sourceUserId = (int)($user['source_user_id'] ?? 0);
            $oeu = $sourceUserId > 0 ? ($oeuById[$sourceUserId] ?? null) : null;
            if (!is_array($oeu)) {
                $nationalKey = egmInstanceOeuNationalIdKey($user['national_id'] ?? '');
                $oeu = $nationalKey !== '' ? ($oeuByNational[$nationalKey] ?? null) : null;
            }
            if (!is_array($oeu)) {
                $workKey = egmInstanceOeuIdentityKey($user['work_id'] ?? '');
                $oeu = $workKey !== '' ? ($oeuByWork[$workKey] ?? null) : null;
            }
            if (!is_array($oeu)) {
                continue;
            }

            $refreshCurrent = $refreshOeuSourced && strtolower(trim((string)($user['source_type'] ?? ''))) === 'oeu';
            $changes = [];
            foreach ($dataFields as $field) {
                $current = trim((string)($user[$field] ?? ''));
                $incoming = trim((string)($oeu[$field] ?? ''));
                if ($incoming === '' || (!$refreshCurrent && $current !== '')) {
                    continue;
                }
                if ($field === 'national_id') {
                    $incoming = egmInstanceOeuNationalIdKey($incoming);
                    if ($incoming === '') {
                        continue;
                    }
                    if (array_key_exists($incoming, $nationalOwners)
                        && (int)$nationalOwners[$incoming] !== $userId) {
                        continue;
                    }
                }
                if ($current !== $incoming) {
                    $changes[$field] = $incoming;
                }
            }
            if ($sourceUserId < 1 && (int)($oeu['id'] ?? 0) > 0) {
                $changes['source_user_id'] = (int)$oeu['id'];
            }
            $incomingSourceRow = (int)($oeu['source_row'] ?? 0);
            if ($incomingSourceRow > 0 && ($refreshCurrent || (int)($user['source_row'] ?? 0) < 1)) {
                if ((int)($user['source_row'] ?? 0) !== $incomingSourceRow) {
                    $changes['source_row'] = $incomingSourceRow;
                }
            }
            if ($refreshCurrent && trim((string)($oeu['imported_at'] ?? '')) !== ''
                && (string)($user['imported_at'] ?? '') !== (string)$oeu['imported_at']) {
                $changes['imported_at'] = (string)$oeu['imported_at'];
            }
            if (!$changes) {
                continue;
            }

            $sets = [];
            $params = [':id' => $userId];
            foreach ($changes as $field => $value) {
                $parameter = ':value_' . $field;
                $sets[] = "`{$field}` = {$parameter}";
                $params[$parameter] = $value;
            }
            $statement = $pdo->prepare(
                "UPDATE `{$usersTable}` SET " . implode(', ', $sets) . ' WHERE `id` = :id'
            );
            $statement->execute($params);
            $updated += $statement->rowCount() > 0 ? 1 : 0;

            $newNational = isset($changes['national_id'])
                ? egmInstanceOeuNationalIdKey($changes['national_id'])
                : egmInstanceOeuNationalIdKey($user['national_id'] ?? '');
            if ($newNational !== '') {
                $nationalOwners[$newNational] = $userId;
            }
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    return $updated;
}

function egmInstanceRefreshUsersFromOeu(PDO $pdo, string $code): int
{
    return egmInstanceEnrichUsersFromOeu($pdo, $code, true);
}

function egmInstanceRefreshAllUsersFromOeu(PDO $pdo): int
{
    if (!egmInstanceTableExists($pdo, 'EGM')) {
        return 0;
    }
    $updated = 0;
    $codes = $pdo->query('SELECT `code` FROM `egm`')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($codes as $code) {
        $normalized = normalizeEgmInstanceCode($code);
        if ($normalized !== '') {
            $updated += egmInstanceRefreshUsersFromOeu($pdo, $normalized);
        }
    }
    return $updated;
}

function egmInstanceReadJsonFile(string $path, $fallback = [])
{
    if (!is_file($path)) {
        return $fallback;
    }
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback;
}

function egmInstanceReadPeriodsFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || preg_match('/window\.EGM_TASKS\s*=\s*(\[[\s\S]*\])\s*;?\s*$/', $raw, $matches) !== 1) {
        return [];
    }
    $decoded = json_decode((string)$matches[1], true);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
}

function egmInstanceDateTimeValue($value): ?string
{
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($text))->format('Y-m-d H:i:s');
    } catch (Throwable $error) {
        return null;
    }
}

/** @return array{work:array<string,int>,national:array<string,int>} */
function egmInstanceUserIdMaps(PDO $pdo, string $usersTable): array
{
    $maps = ['work' => [], 'national' => []];
    $rows = $pdo->query("SELECT `id`, `work_id`, `national_id` FROM `{$usersTable}`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        $workId = trim((string)($row['work_id'] ?? ''));
        $nationalId = trim((string)($row['national_id'] ?? ''));
        if ($id < 1) {
            continue;
        }
        if ($workId !== '') {
            $maps['work'][$workId] = $id;
        }
        if ($nationalId !== '') {
            $maps['national'][$nationalId] = $id;
        }
    }
    return $maps;
}

function egmInstanceResolveUserId(array $maps, string $workId = '', string $nationalId = ''): ?int
{
    $nationalId = trim($nationalId);
    $workId = trim($workId);
    if ($nationalId !== '' && isset($maps['national'][$nationalId])) {
        return (int)$maps['national'][$nationalId];
    }
    if ($workId !== '' && isset($maps['work'][$workId])) {
        return (int)$maps['work'][$workId];
    }
    return null;
}

/** @return list<string> */
function egmInstanceParseStoredList(string $raw): array
{
    $parts = preg_split('/\s*(?:,|;)\s*/', trim($raw));
    if (!is_array($parts)) {
        return [];
    }
    $items = [];
    foreach ($parts as $part) {
        $value = trim((string)$part);
        if ($value !== '') {
            $items[$value] = true;
        }
    }
    return array_keys($items);
}

/** @return array<string, int> */
function egmInstanceParseScoreMap(string $raw, string $separator = ':'): array
{
    $scores = [];
    foreach (egmInstanceParseStoredList($raw) as $item) {
        $position = strpos($item, $separator);
        if ($position === false) {
            continue;
        }
        $period = trim(substr($item, 0, $position));
        if ($period !== '') {
            $scores[$period] = egmInstanceIntegerValue(substr($item, $position + strlen($separator)));
        }
    }
    return $scores;
}

/** @return array{by_id:array<string,string>,valid:array<string,bool>} */
function egmInstancePeriodCodeMaps(array $periods): array
{
    $maps = ['by_id' => [], 'valid' => []];
    foreach ($periods as $period) {
        if (!is_array($period)) {
            continue;
        }
        $id = trim((string)($period['id'] ?? ''));
        $code = trim((string)($period['tagCode'] ?? ($period['code'] ?? $id)));
        if ($code === '') {
            continue;
        }
        $maps['valid'][$code] = true;
        if ($id !== '') {
            $maps['by_id'][$id] = $code;
        }
    }
    return $maps;
}

function egmInstanceResolvePeriodCode(string $value, array $periodMaps): string
{
    $value = trim($value);
    return (string)($periodMaps['by_id'][$value] ?? $value);
}

function egmInstanceMirrorConfiguration(PDO $pdo, string $code, string $missionDir, array $periods): void
{
    $files = [
        'settings' => $missionDir . DIRECTORY_SEPARATOR . 'Setting.json',
        'invitee_mapping' => $missionDir . DIRECTORY_SEPARATOR . 'EGM Event' . DIRECTORY_SEPARATOR . 'EGM Mapped.json',
        'login_policy' => $missionDir . DIRECTORY_SEPARATOR . 'EGM Event' . DIRECTORY_SEPARATOR . 'any-password-login.json',
        'prizes' => $missionDir . DIRECTORY_SEPARATOR . 'EGM Prizes.json',
        'prize_levels' => $missionDir . DIRECTORY_SEPARATOR . 'EGM Prize Levels.json',
        'period_access' => $missionDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'task-access.json',
        'questions' => $missionDir . DIRECTORY_SEPARATOR . 'EGMQ list.json',
        'question_code_state' => $missionDir . DIRECTORY_SEPARATOR . 'EGMQ code state.json',
        'quiz_settings' => $missionDir . DIRECTORY_SEPARATOR . 'EGMQ settings.json',
    ];
    foreach ($files as $key => $path) {
        if (is_file($path)) {
            egmInstanceWriteData($pdo, $code, $key, egmInstanceReadJsonFile($path, []));
        }
    }
    egmInstanceWritePeriods($pdo, $code, $periods);

    foreach ($periods as $period) {
        if (!is_array($period)) {
            continue;
        }
        $periodCode = trim((string)($period['tagCode'] ?? ($period['code'] ?? $period['id'] ?? '')));
        if ($periodCode === '') {
            continue;
        }
        $periodDir = $missionDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . $periodCode;
        foreach ([
            'questions' => 'EGMQ list.json',
            'question_code_state' => 'EGMQ code state.json',
            'quiz_settings' => 'EGMQ settings.json',
            'score_settings' => 'task-score.json',
            'information_settings' => 'info-task.json',
            'information_scores' => 'info-task-scores.json',
            'team_settings' => 'team-settings.json',
            'team_challenges' => 'team-challenges.json',
            'photo_metadata' => 'photos' . DIRECTORY_SEPARATOR . 'photos.json',
        ] as $kind => $relative) {
            $path = $periodDir . DIRECTORY_SEPARATOR . $relative;
            if (is_file($path)) {
                egmInstanceWriteData($pdo, $code, 'period.' . $periodCode . '.' . $kind, egmInstanceReadJsonFile($path, []));
            }
        }
    }
}

function egmInstanceSyncUserPeriods(PDO $pdo, array $tables, array $periods): void
{
    $periodMaps = egmInstancePeriodCodeMaps($periods);
    $users = $pdo->query("SELECT `id`, `state_json` FROM `{$tables['users']}`")->fetchAll(PDO::FETCH_ASSOC);
    $upsert = $pdo->prepare(<<<SQL
INSERT INTO `{$tables['user_periods']}`
(`user_id`, `period_code`, `status`, `score`, `attempt_count`, `state_json`, `completed_at`)
VALUES (:user_id, :period_code, :status, :score, :attempt_count, :state_json, :completed_at)
ON DUPLICATE KEY UPDATE `status`=VALUES(`status`), `score`=VALUES(`score`), `attempt_count`=VALUES(`attempt_count`), `state_json`=VALUES(`state_json`), `completed_at`=COALESCE(`completed_at`, VALUES(`completed_at`))
SQL);
    foreach ($users as $user) {
        $state = json_decode((string)($user['state_json'] ?? ''), true);
        if (!is_array($state)) {
            continue;
        }
        $progress = [];
        foreach (egmInstanceParseStoredList((string)($state['task completed ids'] ?? '')) as $rawPeriod) {
            $periodCode = egmInstanceResolvePeriodCode($rawPeriod, $periodMaps);
            if ($periodCode !== '') {
                $progress[$periodCode]['status'] = 'completed';
            }
        }
        foreach ([
            ['key' => 'task score map', 'separator' => ':'],
            ['key' => 'info tasks', 'separator' => '::'],
            ['key' => 'describe photo task', 'separator' => '::'],
        ] as $source) {
            foreach (egmInstanceParseScoreMap((string)($state[$source['key']] ?? ''), $source['separator']) as $rawPeriod => $score) {
                $periodCode = egmInstanceResolvePeriodCode($rawPeriod, $periodMaps);
                if ($periodCode !== '') {
                    $progress[$periodCode]['score'] = max((int)($progress[$periodCode]['score'] ?? 0), $score);
                }
            }
        }
        foreach (egmInstanceParseStoredList((string)($state['team task'] ?? '')) as $entry) {
            $parts = explode('::', $entry);
            $periodCode = egmInstanceResolvePeriodCode((string)($parts[0] ?? ''), $periodMaps);
            if ($periodCode !== '') {
                $progress[$periodCode]['status'] = trim((string)($parts[2] ?? '')) ?: ($progress[$periodCode]['status'] ?? 'started');
                $progress[$periodCode]['score'] = max((int)($progress[$periodCode]['score'] ?? 0), egmInstanceIntegerValue($parts[3] ?? 0));
            }
        }
        foreach ($progress as $periodCode => $item) {
            $status = (string)($item['status'] ?? (((int)($item['score'] ?? 0)) > 0 ? 'completed' : 'started'));
            $payload = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $upsert->execute([
                ':user_id' => (int)$user['id'],
                ':period_code' => $periodCode,
                ':status' => $status,
                ':score' => (int)($item['score'] ?? 0),
                ':attempt_count' => $status === 'not_started' ? 0 : 1,
                ':state_json' => is_string($payload) ? $payload : '{}',
                ':completed_at' => $status === 'completed' ? date('Y-m-d H:i:s') : null,
            ]);
        }
    }
}

function egmInstanceSyncAnswers(PDO $pdo, array $tables, string $missionDir, array $periods): void
{
    $userMaps = egmInstanceUserIdMaps($pdo, $tables['users']);
    $upsert = $pdo->prepare(<<<SQL
INSERT INTO `{$tables['answers']}`
(`user_id`, `period_code`, `question_code`, `attempt_number`, `answer_text`, `answer_json`, `answered_at`)
VALUES (:user_id, :period_code, :question_code, 1, :answer_text, :answer_json, :answered_at)
ON DUPLICATE KEY UPDATE `answer_text`=VALUES(`answer_text`), `answer_json`=VALUES(`answer_json`), `answered_at`=VALUES(`answered_at`)
SQL);
    $answerSources = [[
        'period_code' => '_event',
        'path' => $missionDir . DIRECTORY_SEPARATOR . 'EGM Event' . DIRECTORY_SEPARATOR . 'Answers.csv',
    ]];
    foreach ($periods as $period) {
        if (!is_array($period)) {
            continue;
        }
        $periodCode = trim((string)($period['tagCode'] ?? ($period['code'] ?? $period['id'] ?? '')));
        if ($periodCode === '') {
            continue;
        }
        $answerSources[] = [
            'period_code' => $periodCode,
            'path' => $missionDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . $periodCode . DIRECTORY_SEPARATOR . 'Answers.csv',
        ];
    }
    foreach ($answerSources as $source) {
        $periodCode = (string)$source['period_code'];
        $path = (string)$source['path'];
        $rows = egmInstanceReadCsvRows($path);
        $pdo->prepare("DELETE FROM `{$tables['answers']}` WHERE `period_code` = :period_code")->execute([':period_code' => $periodCode]);
        if (count($rows) < 2) {
            continue;
        }
        $header = $rows[0];
        $workIndex = egmInstanceResolveColumn($header, [], 'workId', ['Work ID', 'work id', 'workid']);
        if ($workIndex < 0) {
            continue;
        }
        foreach (array_slice($rows, 1) as $row) {
            $workId = trim((string)($row[$workIndex] ?? ''));
            $userId = egmInstanceResolveUserId($userMaps, $workId);
            if ($userId === null) {
                continue;
            }
            foreach ($header as $index => $questionLabel) {
                if ($index === $workIndex) {
                    continue;
                }
                $answer = trim((string)($row[$index] ?? ''));
                if ($answer === '') {
                    continue;
                }
                $label = trim((string)$questionLabel);
                $questionCode = strlen($label) <= 191 ? $label : 'q_' . hash('sha256', $label);
                $answerJson = json_encode(['column' => $label], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $upsert->execute([
                    ':user_id' => $userId,
                    ':period_code' => $periodCode,
                    ':question_code' => $questionCode,
                    ':answer_text' => $answer,
                    ':answer_json' => is_string($answerJson) ? $answerJson : '{}',
                    ':answered_at' => is_file($path) ? date('Y-m-d H:i:s', (int)filemtime($path)) : date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
}

function egmInstanceSyncTeams(PDO $pdo, array $tables, string $missionDir, array $periods): void
{
    $userMaps = egmInstanceUserIdMaps($pdo, $tables['users']);
    foreach ($periods as $period) {
        if (!is_array($period)) {
            continue;
        }
        $periodCode = trim((string)($period['tagCode'] ?? ($period['code'] ?? $period['id'] ?? '')));
        if ($periodCode === '') {
            continue;
        }
        $runtimePath = $missionDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . $periodCode . DIRECTORY_SEPARATOR . 'team-runtime.json';
        $runtime = egmInstanceReadJsonFile($runtimePath, ['teams' => []]);
        $teams = is_array($runtime['teams'] ?? null) ? $runtime['teams'] : [];
        $pdo->prepare("DELETE FROM `{$tables['teams']}` WHERE `period_code` = :period_code")->execute([':period_code' => $periodCode]);
        $insertTeam = $pdo->prepare(<<<SQL
INSERT INTO `{$tables['teams']}`
(`period_code`, `team_code`, `name`, `leader_user_id`, `join_type`, `status`, `challenge_code`, `payload_json`)
VALUES (:period_code, :team_code, :name, :leader_user_id, :join_type, :status, :challenge_code, :payload_json)
SQL);
        $insertMember = $pdo->prepare(<<<SQL
INSERT INTO `{$tables['team_members']}` (`team_id`, `user_id`, `role`, `status`, `payload_json`, `joined_at`)
VALUES (:team_id, :user_id, :role, :status, :payload_json, :joined_at)
ON DUPLICATE KEY UPDATE `role`=VALUES(`role`), `status`=VALUES(`status`), `payload_json`=VALUES(`payload_json`)
SQL);
        foreach ($teams as $team) {
            if (!is_array($team)) {
                continue;
            }
            $teamCode = trim((string)($team['id'] ?? ''));
            $name = trim((string)($team['name'] ?? ''));
            if ($teamCode === '' || $name === '') {
                continue;
            }
            $leaderWorkId = trim((string)($team['leaderWorkId'] ?? ($team['leader_work_id'] ?? '')));
            $leaderUserId = egmInstanceResolveUserId($userMaps, $leaderWorkId);
            $payloadJson = json_encode($team, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $insertTeam->execute([
                ':period_code' => $periodCode,
                ':team_code' => $teamCode,
                ':name' => $name,
                ':leader_user_id' => $leaderUserId,
                ':join_type' => trim((string)($team['joinType'] ?? 'private')) ?: 'private',
                ':status' => !empty($team['started']) ? 'started' : 'forming',
                ':challenge_code' => trim((string)($team['challengeId'] ?? '')) ?: null,
                ':payload_json' => is_string($payloadJson) ? $payloadJson : '{}',
            ]);
            $teamId = (int)$pdo->lastInsertId();
            $memberStates = [];
            if ($leaderWorkId !== '') {
                $memberStates[$leaderWorkId] = ['role' => 'leader', 'status' => 'active'];
            }
            foreach ((array)($team['members'] ?? []) as $workId) {
                $value = trim((string)$workId);
                if ($value !== '') {
                    $memberStates[$value] = $memberStates[$value] ?? ['role' => 'member', 'status' => 'active'];
                }
            }
            foreach ((array)($team['invites'] ?? []) as $workId) {
                $value = trim((string)$workId);
                if ($value !== '' && !isset($memberStates[$value])) {
                    $memberStates[$value] = ['role' => 'member', 'status' => 'invited'];
                }
            }
            foreach ((array)($team['requests'] ?? []) as $workId) {
                $value = trim((string)$workId);
                if ($value !== '' && !isset($memberStates[$value])) {
                    $memberStates[$value] = ['role' => 'member', 'status' => 'requested'];
                }
            }
            foreach ($memberStates as $workId => $memberState) {
                $userId = egmInstanceResolveUserId($userMaps, $workId);
                if ($userId === null) {
                    continue;
                }
                $memberPayload = json_encode(['workId' => $workId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $insertMember->execute([
                    ':team_id' => $teamId,
                    ':user_id' => $userId,
                    ':role' => $memberState['role'],
                    ':status' => $memberState['status'],
                    ':payload_json' => is_string($memberPayload) ? $memberPayload : '{}',
                    ':joined_at' => $memberState['status'] === 'active' ? egmInstanceDateTimeValue($team['createdAt'] ?? null) : null,
                ]);
            }
        }
    }
}

function egmInstanceSyncPhotoSubmissions(PDO $pdo, array $tables, string $missionDir, array $periods): void
{
    $userMaps = egmInstanceUserIdMaps($pdo, $tables['users']);
    $users = $pdo->query("SELECT `id`, `work_id`, `state_json` FROM `{$tables['users']}`")->fetchAll(PDO::FETCH_ASSOC);
    $insert = $pdo->prepare(<<<SQL
INSERT INTO `{$tables['photo_submissions']}`
(`user_id`, `period_code`, `photo_code`, `submission_text`, `media_path`, `status`, `score`, `metadata_json`, `submitted_at`)
VALUES (:user_id, :period_code, :photo_code, :submission_text, :media_path, :status, :score, :metadata_json, :submitted_at)
ON DUPLICATE KEY UPDATE `submission_text`=VALUES(`submission_text`), `media_path`=VALUES(`media_path`), `status`=VALUES(`status`), `score`=VALUES(`score`), `metadata_json`=VALUES(`metadata_json`)
SQL);
    $periodMaps = egmInstancePeriodCodeMaps($periods);
    foreach ($periods as $period) {
        $periodCode = is_array($period) ? trim((string)($period['tagCode'] ?? ($period['code'] ?? $period['id'] ?? ''))) : '';
        if ($periodCode !== '') {
            $pdo->prepare("DELETE FROM `{$tables['photo_submissions']}` WHERE `period_code` = :period_code")->execute([':period_code' => $periodCode]);
        }
    }
    foreach ($users as $user) {
        $state = json_decode((string)($user['state_json'] ?? ''), true);
        if (!is_array($state)) {
            continue;
        }
        $scores = egmInstanceParseScoreMap((string)($state['describe photo task'] ?? ''), '::');
        foreach (egmInstanceParseStoredList((string)($state['describe photo picks'] ?? '')) as $entry) {
            $parts = explode('::', $entry, 3);
            if (count($parts) !== 3) {
                continue;
            }
            $periodCode = egmInstanceResolvePeriodCode((string)$parts[0], $periodMaps);
            $photoCode = trim((string)$parts[1]);
            $fileName = basename(trim((string)$parts[2]));
            if ($periodCode === '' || $photoCode === '' || $fileName === '') {
                continue;
            }
            $relativePath = 'tasks/' . $periodCode . '/photos/articles/' . $fileName;
            $absolutePath = $missionDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $text = is_file($absolutePath) ? file_get_contents($absolutePath) : '';
            $metadata = json_encode(['workId' => (string)$user['work_id'], 'fileName' => $fileName], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $insert->execute([
                ':user_id' => (int)$user['id'],
                ':period_code' => $periodCode,
                ':photo_code' => $photoCode,
                ':submission_text' => is_string($text) ? $text : '',
                ':media_path' => $relativePath,
                ':status' => 'submitted',
                ':score' => (int)($scores[$parts[0]] ?? $scores[$periodCode] ?? 0),
                ':metadata_json' => is_string($metadata) ? $metadata : '{}',
                ':submitted_at' => is_file($absolutePath) ? date('Y-m-d H:i:s', (int)filemtime($absolutePath)) : null,
            ]);
        }
    }
}

/** @return list<array<string,mixed>> */
function egmInstanceReadJsonList(string $path): array
{
    $value = egmInstanceReadJsonFile($path, []);
    return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
}

function egmInstanceSyncPrizeAwards(PDO $pdo, array $tables, string $missionDir): void
{
    $userMaps = egmInstanceUserIdMaps($pdo, $tables['users']);
    $eventDir = $missionDir . DIRECTORY_SEPARATOR . 'EGM Event';
    $mainPath = $eventDir . DIRECTORY_SEPARATOR . 'EGM Prize Awards Log.json';
    $paths = is_file($mainPath) ? [$mainPath] : [];
    $archiveDir = $mainPath . '.archive';
    if (is_dir($archiveDir)) {
        foreach (glob($archiveDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $archivePath) {
            $paths[] = $archivePath;
        }
    }
    $upsert = $pdo->prepare(<<<SQL
INSERT INTO `{$tables['prize_awards']}`
(`award_code`, `user_id`, `period_code`, `prize_code`, `level_code`, `prize_name`, `status`, `payload_json`, `awarded_at`)
VALUES (:award_code, :user_id, :period_code, :prize_code, :level_code, :prize_name, :status, :payload_json, :awarded_at)
ON DUPLICATE KEY UPDATE `user_id`=VALUES(`user_id`), `period_code`=VALUES(`period_code`), `prize_code`=VALUES(`prize_code`), `level_code`=VALUES(`level_code`), `prize_name`=VALUES(`prize_name`), `status`=VALUES(`status`), `payload_json`=VALUES(`payload_json`), `awarded_at`=VALUES(`awarded_at`)
SQL);
    foreach ($paths as $path) {
        foreach (egmInstanceReadJsonList($path) as $entry) {
            $awardCode = trim((string)($entry['awardId'] ?? ''));
            if ($awardCode === '') {
                continue;
            }
            $prize = is_array($entry['prize'] ?? null) ? $entry['prize'] : [];
            $level = is_array($entry['level'] ?? null) ? $entry['level'] : [];
            $payload = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $upsert->execute([
                ':award_code' => $awardCode,
                ':user_id' => egmInstanceResolveUserId($userMaps, (string)($entry['workId'] ?? ''), (string)($entry['nationalId'] ?? '')),
                ':period_code' => trim((string)($entry['periodCode'] ?? '')) ?: null,
                ':prize_code' => trim((string)($prize['id'] ?? '')) ?: null,
                ':level_code' => trim((string)($level['id'] ?? '')) ?: null,
                ':prize_name' => trim((string)($prize['name'] ?? '')) ?: null,
                ':status' => trim((string)($entry['status'] ?? 'awarded')) ?: 'awarded',
                ':payload_json' => is_string($payload) ? $payload : '{}',
                ':awarded_at' => egmInstanceDateTimeValue($entry['awardedAt'] ?? ($entry['selectedAt'] ?? null)),
            ]);
        }
    }
}

function egmInstanceSyncPotWinners(PDO $pdo, array $tables, string $missionDir): void
{
    $userMaps = egmInstanceUserIdMaps($pdo, $tables['users']);
    $potsDir = $missionDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'pots';
    if (!is_dir($potsDir)) {
        return;
    }
    $insert = $pdo->prepare(<<<SQL
INSERT INTO `{$tables['pot_winners']}`
(`pot_level_code`, `user_id`, `winner_position`, `prize_name`, `payload_json`, `won_at`)
VALUES (:pot_level_code, :user_id, :winner_position, :prize_name, :payload_json, :won_at)
ON DUPLICATE KEY UPDATE `winner_position`=VALUES(`winner_position`), `prize_name`=VALUES(`prize_name`), `payload_json`=VALUES(`payload_json`), `won_at`=VALUES(`won_at`)
SQL);
    foreach (glob($potsDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $levelDir) {
        $levelCode = basename($levelDir);
        $path = is_file($levelDir . DIRECTORY_SEPARATOR . 'winners.php')
            ? $levelDir . DIRECTORY_SEPARATOR . 'winners.php'
            : $levelDir . DIRECTORY_SEPARATOR . 'winners.json';
        if (!is_file($path)) {
            continue;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            continue;
        }
        $raw = preg_replace('/^<\?php\s+http_response_code\(404\);\s+exit;\s*\?>\s*/', '', $raw) ?? $raw;
        $decoded = json_decode($raw, true);
        $winners = is_array($decoded['winners'] ?? null) ? $decoded['winners'] : (is_array($decoded) ? $decoded : []);
        $pdo->prepare("DELETE FROM `{$tables['pot_winners']}` WHERE `pot_level_code` = :level_code")->execute([':level_code' => $levelCode]);
        foreach (array_values($winners) as $position => $winner) {
            if (!is_array($winner)) {
                continue;
            }
            $participant = is_array($winner['participant'] ?? null) ? $winner['participant'] : [];
            $workId = trim((string)($participant['workId'] ?? $winner['workId'] ?? $winner['participantKey'] ?? ''));
            $nationalId = trim((string)($participant['nationalId'] ?? $winner['nationalId'] ?? ''));
            $payload = json_encode($winner, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $insert->execute([
                ':pot_level_code' => $levelCode,
                ':user_id' => egmInstanceResolveUserId($userMaps, $workId, $nationalId),
                ':winner_position' => $position + 1,
                ':prize_name' => trim((string)($winner['prizeName'] ?? '')) ?: null,
                ':payload_json' => is_string($payload) ? $payload : '{}',
                ':won_at' => egmInstanceDateTimeValue($winner['selectedAt'] ?? null),
            ]);
        }
    }
}

function egmInstanceSyncLoginAttempts(PDO $pdo, array $tables, string $missionDir): void
{
    $path = $missionDir . DIRECTORY_SEPARATOR . 'EGM Event' . DIRECTORY_SEPARATOR . 'login_attempts.json';
    $attempts = egmInstanceReadJsonFile($path, []);
    if (!is_array($attempts)) {
        return;
    }
    $userMaps = egmInstanceUserIdMaps($pdo, $tables['users']);
    $insert = $pdo->prepare(<<<SQL
INSERT IGNORE INTO `{$tables['login_attempts']}`
(`source_key`, `user_id`, `work_id`, `ip_address`, `was_successful`, `failure_reason`, `metadata_json`, `attempted_at`)
VALUES (:source_key, :user_id, :work_id, :ip_address, 0, :failure_reason, :metadata_json, :attempted_at)
SQL);
    foreach ($attempts as $attemptKey => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $key = (string)$attemptKey;
        $workId = str_starts_with($key, 'user:') ? substr($key, 5) : '';
        $ip = str_starts_with($key, 'ip:') ? substr($key, 3) : '';
        foreach ((array)($entry['fails'] ?? []) as $failedAt) {
            $attemptedAt = egmInstanceDateTimeValue($failedAt);
            if ($attemptedAt === null && is_numeric($failedAt)) {
                $attemptedAt = date('Y-m-d H:i:s', (int)$failedAt);
            }
            if ($attemptedAt === null) {
                continue;
            }
            $sourceKey = hash('sha256', $key . '|' . (string)$failedAt);
            $metadata = json_encode(['attemptKey' => $key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $insert->execute([
                ':source_key' => $sourceKey,
                ':user_id' => egmInstanceResolveUserId($userMaps, $workId),
                ':work_id' => $workId !== '' ? $workId : null,
                ':ip_address' => $ip !== '' ? $ip : null,
                ':failure_reason' => 'invalid_credentials_or_rate_limit',
                ':metadata_json' => is_string($metadata) ? $metadata : '{}',
                ':attempted_at' => $attemptedAt,
            ]);
        }
    }
}

function egmInstanceSyncActivityLogs(PDO $pdo, array $tables, string $missionDir, ?int $changedSince = null): void
{
    $logsDir = $missionDir . DIRECTORY_SEPARATOR . 'useractivitylogs' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logsDir)) {
        return;
    }
    $logsPdo = activityLogDatabaseForProject(activityLogFindProjectRoot($missionDir));
    $code = preg_replace('/^EGM_([0-9]+)_activity_logs$/iD', '$1', (string)$tables['activity_logs']);
    ensureActivityLogTable($logsPdo, 'EGM', (string)$code);
    $userMaps = egmInstanceUserIdMaps($pdo, $tables['users']);
    $insert = $logsPdo->prepare(<<<SQL
INSERT IGNORE INTO `{$tables['activity_logs']}`
(`source_key`, `user_id`, `work_id`, `session_id`, `level`, `action`, `entity_type`, `entity_id`, `ip_address`, `user_agent`, `status`, `message`, `metadata_json`, `occurred_at`)
VALUES (:source_key, :user_id, :work_id, :session_id, :level, :action, :entity_type, :entity_id, :ip_address, :user_agent, :status, :message, :metadata_json, :occurred_at)
SQL);
    foreach (glob($logsDir . DIRECTORY_SEPARATOR . '*.log') ?: [] as $path) {
        if ($changedSince !== null && (int)filemtime($path) < ($changedSince - 86400)) {
            continue;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            continue;
        }
        $lineNumber = 0;
        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            $entry = json_decode(trim($line), true);
            if (!is_array($entry)) {
                continue;
            }
            $workId = trim((string)($entry['user_id'] ?? ''));
            $metadata = json_encode(is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $insert->execute([
                ':source_key' => hash('sha256', basename($path) . '|' . $lineNumber . '|' . trim($line)),
                ':user_id' => egmInstanceResolveUserId($userMaps, $workId),
                ':work_id' => $workId !== '' ? $workId : null,
                ':session_id' => trim((string)($entry['session_id'] ?? '')) ?: null,
                ':level' => trim((string)($entry['level'] ?? 'info')) ?: 'info',
                ':action' => trim((string)($entry['action'] ?? 'egm.unspecified')) ?: 'egm.unspecified',
                ':entity_type' => trim((string)($entry['entity_type'] ?? '')) ?: null,
                ':entity_id' => trim((string)($entry['entity_id'] ?? '')) ?: null,
                ':ip_address' => trim((string)($entry['ip_address'] ?? '')) ?: null,
                ':user_agent' => trim((string)($entry['user_agent'] ?? '')) ?: null,
                ':status' => trim((string)($entry['status'] ?? 'success')) ?: 'success',
                ':message' => trim((string)($entry['message'] ?? '')) ?: null,
                ':metadata_json' => is_string($metadata) ? $metadata : '{}',
                ':occurred_at' => egmInstanceDateTimeValue($entry['timestamp'] ?? null) ?? date('Y-m-d H:i:s'),
            ]);
        }
        fclose($handle);
    }
}

function egmInstanceNormalizeRuntimeRelativePath(string $relativePath): string
{
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || str_contains($relativePath, "\0")) {
        return '';
    }
    $parts = explode('/', $relativePath);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return '';
        }
    }
    return implode('/', $parts);
}

function egmInstanceIsManagedRuntimeFile(string $relativePath): bool
{
    $relativePath = egmInstanceNormalizeRuntimeRelativePath($relativePath);
    if ($relativePath === '') {
        return false;
    }
    $lower = strtolower($relativePath);
    foreach (['.lock', '.tmp', '.bak', '.rollback', '.replace-a', '.replace-b', '.corrupt', '.backup-unsynced'] as $suffix) {
        if (str_ends_with($lower, $suffix)) {
            return false;
        }
    }
    if (in_array($lower, [
        'setting.json',
        'egm prizes.json',
        'egm prize levels.json',
        'egmq list.json',
        'egmq code state.json',
        'egmq settings.json',
    ], true)) {
        return true;
    }
    if (!str_starts_with($lower, 'egm event/')
        && !str_starts_with($lower, 'tasks/')
        && !str_starts_with($lower, 'data/pots/')
        && !str_starts_with($lower, 'invitecards/')
        && !str_starts_with($lower, 'useractivitylogs/logs/')) {
        return false;
    }
    $extension = strtolower((string)pathinfo($lower, PATHINFO_EXTENSION));
    return in_array($extension, [
        'json', 'csv', 'js', 'txt', 'md', 'log', 'php',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf',
    ], true);
}

/** @return array<string, array{path:string,size:int,mtime:int,sha256:string}> */
function egmInstanceScanRuntimeFiles(string $missionDir, bool $withHashes = true): array
{
    $missionDir = rtrim($missionDir, DIRECTORY_SEPARATOR);
    if (!is_dir($missionDir)) {
        return [];
    }
    $files = [];
    $addFile = static function (string $absolutePath) use ($missionDir, $withHashes, &$files): void {
        if (!is_file($absolutePath) || is_link($absolutePath)) {
            return;
        }
        $relativePath = egmInstanceNormalizeRuntimeRelativePath(substr($absolutePath, strlen($missionDir) + 1));
        if (!egmInstanceIsManagedRuntimeFile($relativePath)) {
            return;
        }
        $hash = '';
        if ($withHashes) {
            $hash = hash_file('sha256', $absolutePath);
            if (!is_string($hash)) {
                throw new RuntimeException('Failed to hash EGM runtime file: ' . $relativePath);
            }
        }
        $files[$relativePath] = [
            'path' => $absolutePath,
            'size' => max(0, (int)filesize($absolutePath)),
            'mtime' => max(0, (int)filemtime($absolutePath)),
            'sha256' => $hash,
        ];
    };
    foreach ([
        'Setting.json',
        'EGM Prizes.json',
        'EGM Prize Levels.json',
        'EGMQ list.json',
        'EGMQ code state.json',
        'EGMQ settings.json',
    ] as $rootFile) {
        $addFile($missionDir . DIRECTORY_SEPARATOR . $rootFile);
    }
    foreach ([
        'EGM Event',
        'tasks',
        'data' . DIRECTORY_SEPARATOR . 'pots',
        'useractivitylogs' . DIRECTORY_SEPARATOR . 'logs',
    ] as $relativeDirectory) {
        $directory = $missionDir . DIRECTORY_SEPARATOR . $relativeDirectory;
        if (!is_dir($directory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $addFile($item->getPathname());
            }
        }
    }
    ksort($files, SORT_STRING);
    return $files;
}

function egmInstanceRuntimeFileDataKey(string $relativePath): string
{
    return 'runtime_file:' . hash('sha256', egmInstanceNormalizeRuntimeRelativePath($relativePath));
}

/** @return array<string,int> */
function egmInstanceCommitRuntimeFiles(PDO $pdo, string $code, string $missionDir): array
{
    $tables = ensureEgmInstanceTables($pdo, $code);
    $table = $tables['data'];
    $files = egmInstanceScanRuntimeFiles($missionDir);
    $existingRows = $pdo->query("SELECT `data_key`, `file_path`, `content_sha256`, `file_size`, `file_mtime`, (`file_data` IS NOT NULL) AS `has_legacy_blob` FROM `{$table}` WHERE `storage_kind` = 'runtime_file'")->fetchAll(PDO::FETCH_ASSOC);
    $existing = [];
    foreach ($existingRows as $row) {
        $path = egmInstanceNormalizeRuntimeRelativePath((string)($row['file_path'] ?? ''));
        if ($path !== '') {
            $existing[$path] = $row;
        }
    }
    $insert = $pdo->prepare(<<<SQL
INSERT INTO `{$table}`
(`data_key`, `payload`, `storage_kind`, `file_path`, `file_data`, `content_sha256`, `file_size`, `file_mtime`, `file_chunk`)
VALUES (:data_key, :payload, 'runtime_file', :file_path, NULL, :content_sha256, :file_size, :file_mtime, NULL)
ON DUPLICATE KEY UPDATE
  `payload`=VALUES(`payload`), `storage_kind`='runtime_file', `file_path`=VALUES(`file_path`),
  `file_data`=NULL, `content_sha256`=VALUES(`content_sha256`), `file_size`=VALUES(`file_size`),
  `file_mtime`=VALUES(`file_mtime`), `file_chunk`=NULL, `updated_at`=CURRENT_TIMESTAMP
SQL);
    $delete = $pdo->prepare("DELETE FROM `{$table}` WHERE `data_key` = :data_key AND `storage_kind` = 'runtime_file'");
    $deleteChunks = $pdo->prepare("DELETE FROM `{$table}` WHERE `storage_kind` = 'runtime_chunk' AND `file_path` = :file_path");
    $insertChunk = $pdo->prepare(<<<SQL
INSERT INTO `{$table}`
(`data_key`, `payload`, `storage_kind`, `file_path`, `file_data`, `content_sha256`, `file_size`, `file_mtime`, `file_chunk`)
VALUES (:data_key, :payload, 'runtime_chunk', :file_path, :file_data, :content_sha256, :file_size, :file_mtime, :file_chunk)
ON DUPLICATE KEY UPDATE `payload`=VALUES(`payload`), `storage_kind`='runtime_chunk', `file_path`=VALUES(`file_path`),
`file_data`=VALUES(`file_data`), `content_sha256`=VALUES(`content_sha256`), `file_size`=VALUES(`file_size`),
`file_mtime`=VALUES(`file_mtime`), `file_chunk`=VALUES(`file_chunk`), `updated_at`=CURRENT_TIMESTAMP
SQL);
    $written = 0;
    $unchanged = 0;
    $deleted = 0;
    $pdo->beginTransaction();
    try {
        foreach ($files as $relativePath => $file) {
            if (($existing[$relativePath]['content_sha256'] ?? null) === $file['sha256']
                && empty($existing[$relativePath]['has_legacy_blob'])) {
                $unchanged++;
                unset($existing[$relativePath]);
                continue;
            }
            $content = file_get_contents($file['path']);
            if (!is_string($content)) {
                throw new RuntimeException('Failed to read EGM runtime file: ' . $relativePath);
            }
            $chunkSize = 393216;
            $chunkCount = max(1, (int)ceil(strlen($content) / $chunkSize));
            $payload = json_encode([
                'path' => $relativePath,
                'sha256' => $file['sha256'],
                'size' => $file['size'],
                'mtime' => $file['mtime'],
                'chunks' => $chunkCount,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($payload)) {
                throw new RuntimeException('Failed to encode EGM runtime file metadata.');
            }
            $insert->bindValue(':data_key', egmInstanceRuntimeFileDataKey($relativePath));
            $insert->bindValue(':payload', $payload);
            $insert->bindValue(':file_path', $relativePath);
            $insert->bindValue(':content_sha256', $file['sha256']);
            $insert->bindValue(':file_size', $file['size'], PDO::PARAM_INT);
            $insert->bindValue(':file_mtime', $file['mtime'], PDO::PARAM_INT);
            $insert->execute();
            $deleteChunks->execute([':file_path' => $relativePath]);
            $pathHash = hash('sha256', $relativePath);
            for ($chunkIndex = 0; $chunkIndex < $chunkCount; $chunkIndex++) {
                $chunk = substr($content, $chunkIndex * $chunkSize, $chunkSize);
                $chunkPayload = json_encode(['path' => $relativePath, 'chunk' => $chunkIndex], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $insertChunk->bindValue(':data_key', 'runtime_chunk:' . $pathHash . ':' . str_pad((string)$chunkIndex, 8, '0', STR_PAD_LEFT));
                $insertChunk->bindValue(':payload', is_string($chunkPayload) ? $chunkPayload : '{}');
                $insertChunk->bindValue(':file_path', $relativePath);
                $insertChunk->bindValue(':file_data', $chunk, PDO::PARAM_LOB);
                $insertChunk->bindValue(':content_sha256', hash('sha256', $chunk));
                $insertChunk->bindValue(':file_size', strlen($chunk), PDO::PARAM_INT);
                $insertChunk->bindValue(':file_mtime', $file['mtime'], PDO::PARAM_INT);
                $insertChunk->bindValue(':file_chunk', $chunkIndex, PDO::PARAM_INT);
                $insertChunk->execute();
            }
            $written++;
            unset($existing[$relativePath]);
        }
        foreach ($existing as $row) {
            $existingPath = egmInstanceNormalizeRuntimeRelativePath((string)($row['file_path'] ?? ''));
            // Invite Card images are now filesystem assets. Keep a legacy blob
            // only until the public route or regeneration restores its JPG;
            // neither the runtime scan nor a general sync should discard it
            // before that safe one-time migration has happened.
            if (str_starts_with(strtolower($existingPath), 'invitecards/')) {
                continue;
            }
            $deleteChunks->execute([':file_path' => (string)$row['file_path']]);
            $delete->execute([':data_key' => (string)$row['data_key']]);
            $deleted += $delete->rowCount();
        }
        $manifest = [];
        foreach ($files as $relativePath => $file) {
            $manifest[] = [
                'path' => $relativePath,
                'sha256' => $file['sha256'],
                'size' => $file['size'],
                'mtime' => $file['mtime'],
            ];
        }
        egmInstanceWriteData($pdo, $code, 'runtime_file_manifest', $manifest);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    return ['files' => count($files), 'written' => $written, 'unchanged' => $unchanged, 'deleted' => $deleted];
}

function egmInstanceHydrateRuntimeFiles(PDO $pdo, string $code, string $missionDir): int
{
    $tables = ensureEgmInstanceTables($pdo, $code);
    $table = $tables['data'];
    $manifest = egmInstanceReadData($pdo, $code, 'runtime_file_manifest', null);
    $rows = $pdo->query(
        "SELECT `file_path`, `file_data`, `content_sha256`, `file_size`, `file_mtime` FROM `{$table}` "
        . "WHERE `storage_kind` = 'runtime_file' ORDER BY `file_path`"
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($manifest === null && !$rows) {
        egmInstanceCommitRuntimeFiles($pdo, $code, $missionDir);
        return 0;
    }
    $desired = [];
    foreach ($rows as $row) {
        $relativePath = egmInstanceNormalizeRuntimeRelativePath((string)($row['file_path'] ?? ''));
        if ($relativePath === '' || !egmInstanceIsManagedRuntimeFile($relativePath)) {
            continue;
        }
        $desired[$relativePath] = $row;
    }

    $localFiles = egmInstanceScanRuntimeFiles($missionDir, false);
    foreach ($localFiles as $relativePath => $file) {
        if (!isset($desired[$relativePath])) {
            @unlink($file['path']);
        }
    }

    $restored = 0;
    foreach ($desired as $relativePath => $row) {
        $absolutePath = rtrim($missionDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $expectedHash = trim((string)($row['content_sha256'] ?? ''));
        if (is_file($absolutePath) && $expectedHash !== '' && hash_file('sha256', $absolutePath) === $expectedHash) {
            $mtime = max(0, (int)($row['file_mtime'] ?? 0));
            if ($mtime > 0) {
                @touch($absolutePath, $mtime);
            }
            continue;
        }
        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to prepare EGM runtime directory: ' . dirname($relativePath));
        }
        $temporaryPath = $directory . DIRECTORY_SEPARATOR . '.egm-db-hydrate-' . bin2hex(random_bytes(8)) . '.tmp';
        $output = fopen($temporaryPath, 'wb');
        if ($output === false) {
            throw new RuntimeException('Failed to prepare EGM runtime file: ' . $relativePath);
        }
        $fileHash = hash_init('sha256');
        $bytesWritten = 0;
        try {
            $legacyContent = $row['file_data'] ?? null;
            if (is_resource($legacyContent)) {
                $legacyContent = stream_get_contents($legacyContent);
            }
            if (is_string($legacyContent)) {
                hash_update($fileHash, $legacyContent);
                if (fwrite($output, $legacyContent) !== strlen($legacyContent)) {
                    throw new RuntimeException('Failed to write legacy EGM runtime data.');
                }
                $bytesWritten = strlen($legacyContent);
            } else {
                $chunkStatement = $pdo->prepare(
                    "SELECT `file_data`, `content_sha256` FROM `{$table}` "
                    . "WHERE `storage_kind` = 'runtime_chunk' AND `file_path` = :file_path ORDER BY `file_chunk`"
                );
                $chunkStatement->execute([':file_path' => $relativePath]);
                while ($chunkRow = $chunkStatement->fetch(PDO::FETCH_ASSOC)) {
                    $chunk = $chunkRow['file_data'] ?? null;
                    if (is_resource($chunk)) {
                        $chunk = stream_get_contents($chunk);
                    }
                    if (!is_string($chunk)
                        || hash('sha256', $chunk) !== trim((string)($chunkRow['content_sha256'] ?? ''))) {
                        throw new RuntimeException('Corrupt EGM runtime database chunk: ' . $relativePath);
                    }
                    hash_update($fileHash, $chunk);
                    if (fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('Failed to restore an EGM runtime chunk.');
                    }
                    $bytesWritten += strlen($chunk);
                }
            }
        } catch (Throwable $error) {
            fclose($output);
            @unlink($temporaryPath);
            throw $error;
        }
        fflush($output);
        fclose($output);
        if ($bytesWritten !== (int)($row['file_size'] ?? -1) || hash_final($fileHash) !== $expectedHash) {
            @unlink($temporaryPath);
            throw new RuntimeException('EGM runtime database file failed integrity validation: ' . $relativePath);
        }
        if (is_file($absolutePath) && !@unlink($absolutePath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Failed to replace stale EGM runtime file: ' . $relativePath);
        }
        if (!@rename($temporaryPath, $absolutePath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Failed to publish EGM runtime file: ' . $relativePath);
        }
        $mtime = max(0, (int)($row['file_mtime'] ?? 0));
        if ($mtime > 0) {
            @touch($absolutePath, $mtime);
        }
        $restored++;
    }
    return $restored;
}

function egmInstanceHydrateMissionRuntimeUsingProjectConfig(string $projectRoot, string $missionDir): ?int
{
    $resolvedMissionDir = realpath($missionDir);
    if (!is_string($resolvedMissionDir) || egmInstanceRegistryDirectoryForMission($resolvedMissionDir) === '') {
        return null;
    }
    if (!function_exists('loadConfig') || !function_exists('connectDatabase')) {
        throw new RuntimeException('Database configuration helpers are unavailable.');
    }
    $config = loadConfig(rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php');
    $pdo = connectDatabase($config);
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Unable to connect to the EGM database.');
    }
    $registry = egmInstanceRegistryForDirectory($pdo, $missionDir);
    if (!is_array($registry)) {
        throw new RuntimeException('The EGM runtime is not registered in the database.');
    }
    return egmInstanceHydrateRuntimeFiles($pdo, (string)$registry['code'], $missionDir);
}

/** @return array<string,int> */
function egmInstanceMirrorMissionStorage(PDO $pdo, string $code, string $missionDir): array
{
    $tables = ensureEgmInstanceTables($pdo, $code);
    $missionDir = rtrim($missionDir, DIRECTORY_SEPARATOR);
    $previousSync = egmInstanceReadData($pdo, $code, 'database_sync', null);
    $previousSyncAt = is_array($previousSync)
        ? egmInstanceDateTimeValue($previousSync['syncedAt'] ?? null)
        : null;
    $changedSince = $previousSyncAt !== null ? strtotime($previousSyncAt) : null;
    $periods = egmInstanceReadPeriodsFile($missionDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'tasks.js');
    egmInstanceMirrorConfiguration($pdo, $code, $missionDir, $periods);

    $inviteesPath = $missionDir . DIRECTORY_SEPARATOR . 'EGM Event' . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
    $mappingPath = $missionDir . DIRECTORY_SEPARATOR . 'EGM Event' . DIRECTORY_SEPARATOR . 'EGM Mapped.json';
    if (is_file($inviteesPath)) {
        egmInstanceSyncUsersFromCsv($pdo, $code, $inviteesPath, $mappingPath);
    }
    egmInstanceSyncUserPeriods($pdo, $tables, $periods);
    egmInstanceSyncAnswers($pdo, $tables, $missionDir, $periods);
    egmInstanceSyncTeams($pdo, $tables, $missionDir, $periods);
    egmInstanceSyncPhotoSubmissions($pdo, $tables, $missionDir, $periods);
    egmInstanceSyncPrizeAwards($pdo, $tables, $missionDir);
    egmInstanceSyncPotWinners($pdo, $tables, $missionDir);
    egmInstanceSyncLoginAttempts($pdo, $tables, $missionDir);
    egmInstanceSyncActivityLogs($pdo, $tables, $missionDir, is_int($changedSince) ? $changedSince : null);

    $counts = [];
    foreach ($tables as $key => $table) {
        if ($key === 'data') {
            continue;
        }
        $counts[$key] = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }
    $counts['runtime_files'] = egmInstanceCommitRuntimeFiles($pdo, $code, $missionDir)['files'];
    egmInstanceWriteData($pdo, $code, 'database_sync', ['syncedAt' => gmdate('c'), 'counts' => $counts]);
    return $counts;
}

/** @return array<string,int>|null */
function egmInstanceMirrorMissionStorageUsingProjectConfig(string $projectRoot, string $missionDir): ?array
{
    $resolvedMissionDir = realpath($missionDir);
    if (!is_string($resolvedMissionDir) || egmInstanceRegistryDirectoryForMission($resolvedMissionDir) === '') {
        return null;
    }
    if (!function_exists('loadConfig') || !function_exists('connectDatabase')) {
        return null;
    }
    $config = loadConfig(rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php');
    $pdo = connectDatabase($config);
    if (!$pdo instanceof PDO) {
        return null;
    }
    $registry = egmInstanceRegistryForDirectory($pdo, $missionDir);
    if (!is_array($registry)) {
        return null;
    }
    return egmInstanceMirrorMissionStorage($pdo, (string)$registry['code'], $missionDir);
}
