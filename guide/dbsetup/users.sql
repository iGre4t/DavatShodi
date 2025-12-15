-- Create and seed the users table.
-- This script can be executed after the main schema to keep credentials in sync.

CREATE TABLE IF NOT EXISTS `users` (
  `code` VARCHAR(16) NOT NULL COMMENT 'Unique 6-digit code with padding',
  `username` VARCHAR(64) NOT NULL,
  `fullname` VARCHAR(128) NOT NULL DEFAULT '0',
  `phone` CHAR(11) NOT NULL COMMENT 'Starts with 09 and contains only digits',
  `email` VARCHAR(255) NOT NULL,
  `id_number` CHAR(10) NOT NULL DEFAULT '0000000000',
  `work_id` VARCHAR(64) NOT NULL DEFAULT '0',
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`),
  UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (
  `code`, `username`, `fullname`, `phone`, `email`, `id_number`, `work_id`, `password_hash`
) VALUES (
  '000001',
  'kadkhodaee',
  '0',
  '09102024292',
  'graminkd@gmail.com',
  '0000000000',
  '0',
  '$2y$10$OB5XZZ9F6KOLyhUcvgvvJOi7gF1GvUbbXOBCrw7H2xM727BQxUbf.'
) ON DUPLICATE KEY UPDATE
  `username` = VALUES(`username`),
  `fullname` = VALUES(`fullname`),
  `phone` = VALUES(`phone`),
  `email` = VALUES(`email`),
  `id_number` = VALUES(`id_number`),
  `work_id` = VALUES(`work_id`),
  `password_hash` = VALUES(`password_hash`);
