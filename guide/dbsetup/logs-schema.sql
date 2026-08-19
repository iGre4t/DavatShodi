-- Import this into the separate activity-logs database, not the core database.
-- TC_* and EGM_* activity tables are provisioned automatically by the app.

CREATE TABLE IF NOT EXISTS `activity_log_instances` (
  `instance_kind` VARCHAR(8) NOT NULL,
  `instance_code` VARCHAR(64) NOT NULL,
  `table_name` VARCHAR(191) NOT NULL,
  `schema_version` VARCHAR(32) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`instance_kind`, `instance_code`),
  UNIQUE KEY `uq_activity_log_table` (`table_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `panel_user_activity_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `timestamp` DATETIME NOT NULL,
  `level` VARCHAR(16) NOT NULL,
  `user_id` VARCHAR(64) NULL,
  `session_id` VARCHAR(128) NULL,
  `action` VARCHAR(128) NOT NULL,
  `entity_type` VARCHAR(64) NULL,
  `entity_id` VARCHAR(128) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(512) NULL,
  `status` VARCHAR(32) NOT NULL,
  `message` TEXT NULL,
  `metadata_json` LONGTEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_timestamp` (`timestamp`),
  KEY `idx_activity_user_action` (`user_id`, `action`),
  KEY `idx_activity_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
