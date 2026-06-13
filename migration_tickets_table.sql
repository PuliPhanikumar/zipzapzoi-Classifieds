-- ================================================================
-- ZipZapZoi — Support Tickets Table Migration
-- Run this in phpMyAdmin > SQL tab
-- ================================================================

CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `priority`   ENUM('low','medium','high') NOT NULL DEFAULT 'low',
  `subject`    VARCHAR(255) NOT NULL,
  `message`    TEXT NOT NULL,
  `status`     ENUM('open','resolved') NOT NULL DEFAULT 'open',
  `sla_hours`  INT NOT NULL DEFAULT 48,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
