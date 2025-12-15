-- SQL helper for setting up the local MySQL store.
-- Execute this script once after creating the database user so the
-- application has a table ready for JSON payloads.

CREATE DATABASE IF NOT EXISTS `great_panel` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `great_panel`;

CREATE TABLE IF NOT EXISTS `great_panel_store` (
  `id` varchar(64) NOT NULL,
  `payload` longtext NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
