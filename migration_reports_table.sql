-- ================================================================
-- ZipZapZoi — Reports Table Migration
-- Run this in phpMyAdmin > SQL tab
-- ================================================================

CREATE TABLE IF NOT EXISTS `reports` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `listing_id`  INT UNSIGNED NOT NULL,
  `reporter_id` INT UNSIGNED NOT NULL,
  `reason`      VARCHAR(100) NOT NULL,
  `status`      ENUM('pending','reviewed','dismissed') NOT NULL DEFAULT 'pending',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_report` (`listing_id`, `reporter_id`),
  KEY `idx_listing`  (`listing_id`),
  KEY `idx_reporter` (`reporter_id`),
  KEY `idx_status`   (`status`),
  FOREIGN KEY (`listing_id`)  REFERENCES `listings`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`reporter_id`) REFERENCES `users`(`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
