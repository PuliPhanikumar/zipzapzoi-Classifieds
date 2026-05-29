-- ================================================================
-- ZipZapZoi Classifieds — MySQL Schema
-- Database: u572945141_Classifieds_db
-- Paste this entire file into phpMyAdmin > SQL tab and Run
-- ================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';

-- ── 1. USERS ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`                 VARCHAR(100)  NOT NULL,
  `email`                VARCHAR(150)  NOT NULL,
  `phone`                VARCHAR(15)   DEFAULT NULL,
  `password_hash`        VARCHAR(255)  NOT NULL,
  `role`                 ENUM('user','admin','super_admin') NOT NULL DEFAULT 'user',
  `avatar`               VARCHAR(500)  DEFAULT NULL,
  `city`                 VARCHAR(100)  DEFAULT NULL,
  `state`                VARCHAR(100)  DEFAULT NULL,
  `is_verified`          TINYINT(1)    NOT NULL DEFAULT 1,
  `is_active`            TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- First super admin account (password: Admin@ZipZap2026 — change after first login!)
-- Password hash below is bcrypt of 'Admin@ZipZap2026'
INSERT INTO `users` (`name`, `email`, `phone`, `password_hash`, `role`, `is_verified`) VALUES
('Super Admin', 'admin@zipzapzoi.com', '9999999999',
 '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uAtmkPS9u',
 'super_admin', 1);

-- ── 2. SESSIONS ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sessions` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`       INT UNSIGNED NOT NULL,
  `token`         VARCHAR(128) NOT NULL,
  `ip_address`    VARCHAR(45)  DEFAULT NULL,
  `user_agent`    VARCHAR(255) DEFAULT NULL,
  `last_activity` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`    DATETIME     NOT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_token` (`token`),
  KEY `idx_user` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. OTP TOKENS ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `otp_tokens` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email`      VARCHAR(150) NOT NULL,
  `otp_code`   VARCHAR(10)  NOT NULL,
  `action`     ENUM('register','login','sensitive_action') NOT NULL DEFAULT 'login',
  `meta`       TEXT         DEFAULT NULL,
  `expires_at` DATETIME     NOT NULL,
  `used`       TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_email_action` (`email`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. RESET TOKENS ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `reset_tokens` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `token`      VARCHAR(64)  NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `used`       TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_token` (`token`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. LISTINGS ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `listings` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT UNSIGNED  NOT NULL,
  `title`          VARCHAR(255)  NOT NULL,
  `description`    TEXT          DEFAULT NULL,
  `category`       VARCHAR(100)  DEFAULT NULL,
  `subcategory`    VARCHAR(100)  DEFAULT NULL,
  `price`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `price_type`     ENUM('fixed','negotiable','free') NOT NULL DEFAULT 'fixed',
  `location_city`  VARCHAR(100)  DEFAULT NULL,
  `location_state` VARCHAR(100)  DEFAULT NULL,
  `location_area`  VARCHAR(150)  DEFAULT NULL,
  `status`         ENUM('active','pending_review','sold','expired','rejected','draft') NOT NULL DEFAULT 'active',
  `images`         JSON          DEFAULT NULL,
  `fields`         JSON          DEFAULT NULL,
  `views`          INT UNSIGNED  NOT NULL DEFAULT 0,
  `expires_at`     DATETIME      DEFAULT NULL,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_category`   (`category`),
  KEY `idx_status`     (`status`),
  KEY `idx_city`       (`location_city`),
  KEY `idx_user`       (`user_id`),
  KEY `idx_created`    (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. FAVORITES ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `favorites` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `listing_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_fav` (`user_id`, `listing_id`),
  FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)    ON DELETE CASCADE,
  FOREIGN KEY (`listing_id`) REFERENCES `listings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 7. MESSAGES ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `messages` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `from_user_id` INT UNSIGNED NOT NULL,
  `to_user_id`   INT UNSIGNED NOT NULL,
  `listing_id`   INT UNSIGNED DEFAULT NULL,
  `subject`      VARCHAR(255) DEFAULT NULL,
  `body`         TEXT         NOT NULL,
  `is_read`      TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_to`   (`to_user_id`),
  KEY `idx_from` (`from_user_id`),
  FOREIGN KEY (`from_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`to_user_id`)   REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 8. TRANSACTIONS ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `transactions` (
  `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`              INT UNSIGNED   NOT NULL,
  `plan_id`              VARCHAR(50)    DEFAULT NULL,
  `plan_name`            VARCHAR(100)   DEFAULT NULL,
  `amount`               DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  `currency`             VARCHAR(10)    NOT NULL DEFAULT 'INR',
  `razorpay_payment_id`  VARCHAR(100)   DEFAULT NULL,
  `razorpay_order_id`    VARCHAR(100)   DEFAULT NULL,
  `status`               ENUM('success','failed','refunded','pending') NOT NULL DEFAULT 'pending',
  `created_at`           DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 9. USER QUOTAS ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `user_quotas` (
  `id`                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`                INT UNSIGNED NOT NULL,
  `ads_remaining`          INT          NOT NULL DEFAULT 0,
  `total_granted`          INT          NOT NULL DEFAULT 0,
  `plan_id`                VARCHAR(50)  DEFAULT NULL,
  `plan_name`              VARCHAR(100) DEFAULT NULL,
  `expires_at`             DATETIME     DEFAULT NULL,
  `monthly_free_granted`   VARCHAR(10)  DEFAULT NULL,
  `new_user_free_granted`  TINYINT(1)   NOT NULL DEFAULT 0,
  `updated_at`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_user` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Give the super admin unlimited quota
INSERT INTO `user_quotas` (`user_id`, `ads_remaining`, `total_granted`, `plan_id`, `plan_name`)
SELECT id, 9999, 9999, 'admin_unlimited', 'Admin Unlimited'
FROM `users` WHERE `email` = 'admin@zipzapzoi.com';
