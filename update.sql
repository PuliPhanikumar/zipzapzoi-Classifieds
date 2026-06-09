-- Run this in your live database (phpMyAdmin > SQL tab) to apply the Gamification & Referral System updates!

ALTER TABLE `users`
  ADD COLUMN `referral_code` VARCHAR(20) UNIQUE DEFAULT NULL,
  ADD COLUMN `referred_by` INT UNSIGNED DEFAULT NULL;

ALTER TABLE `user_quotas`
  ADD COLUMN `new_user_free_granted` TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `reports` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `listing_id` INT UNSIGNED NOT NULL,
  `reporter_id` INT UNSIGNED DEFAULT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `status` ENUM('pending', 'reviewed', 'dismissed') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`listing_id`) REFERENCES `listings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
