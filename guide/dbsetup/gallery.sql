-- Schema for the gallery feature.
-- Run this against the `great_panel` database after the main schema,
-- e.g. `mysql < api/schema.sql && mysql < api/gallery.sql`.

CREATE TABLE IF NOT EXISTS `gallery_category` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `slug` VARCHAR(64) NOT NULL,
  `mother_category_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gallery_category_slug` (`slug`),
  KEY `idx_gallery_category_mother` (`mother_category_id`),
  CONSTRAINT `fk_gallery_category_mother` FOREIGN KEY (`mother_category_id`)
    REFERENCES `gallery_category` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gallery` (
  `photo_id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
  `filename` VARCHAR(255) NOT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL,
  `alt_text` VARCHAR(255) NOT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`photo_id`),
  KEY `idx_gallery_category` (`category_id`),
  CONSTRAINT `fk_gallery_category` FOREIGN KEY (`category_id`) REFERENCES `gallery_category` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
