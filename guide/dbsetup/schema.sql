-- SQL helper for setting up the local MySQL store.
-- Execute this script once after creating the database user so the
-- application has a table ready for JSON payloads.

CREATE DATABASE IF NOT EXISTS `MCI` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `MCI`;

CREATE TABLE IF NOT EXISTS `mci_store` (
  `id` varchar(64) NOT NULL,
  `payload` longtext NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `EGM` (
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `directory` VARCHAR(512) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`),
  UNIQUE KEY `uniq_egm_directory` (`directory`)
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

CREATE TABLE IF NOT EXISTS `taskclub_user_activity_logs` (
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

CREATE TABLE IF NOT EXISTS `organizational_event_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `work_id` VARCHAR(128) NOT NULL DEFAULT '',
  `first_name` VARCHAR(191) NOT NULL DEFAULT '',
  `last_name` VARCHAR(191) NOT NULL DEFAULT '',
  `national_id` VARCHAR(32) NOT NULL DEFAULT '',
  `phone_number` VARCHAR(32) NOT NULL DEFAULT '',
  `deputy` VARCHAR(191) NOT NULL DEFAULT '',
  `general_department` VARCHAR(191) NOT NULL DEFAULT '',
  `department` VARCHAR(191) NOT NULL DEFAULT '',
  `gender` VARCHAR(32) NOT NULL DEFAULT '',
  `postal_level` VARCHAR(64) NOT NULL DEFAULT '',
  `source_row` INT UNSIGNED NOT NULL,
  `imported_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_org_users_work_id` (`work_id`),
  KEY `idx_org_users_national_id` (`national_id`),
  KEY `idx_org_users_phone_number` (`phone_number`),
  KEY `idx_org_users_structure` (`deputy`, `general_department`, `department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
