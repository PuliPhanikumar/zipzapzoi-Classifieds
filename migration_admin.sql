-- ================================================================
-- ZipZapZoi Admin Console — Additional Tables Migration
-- Paste into: Hostinger phpMyAdmin → u572945141_Classifieds_db → SQL tab → Run
-- ================================================================

-- Coupons table
CREATE TABLE IF NOT EXISTS `coupons` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code`         VARCHAR(50)      NOT NULL,
  `discount_pct` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`    TINYINT(1)       NOT NULL DEFAULT 1,
  `expires_at`   DATETIME         DEFAULT NULL,
  `created_by`   INT UNSIGNED     DEFAULT NULL,
  `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin Audit Logs
CREATE TABLE IF NOT EXISTS `admin_logs` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `admin_id`   INT UNSIGNED NOT NULL,
  `admin_name` VARCHAR(100) DEFAULT NULL,
  `action`     VARCHAR(80)  NOT NULL,
  `detail`     TEXT         DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_admin`  (`admin_id`),
  KEY `idx_action` (`action`),
  KEY `idx_time`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reports / Flags (from Listing Detail "Report" button)
CREATE TABLE IF NOT EXISTS `reports` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `listing_id`  INT UNSIGNED NOT NULL,
  `reporter_id` INT UNSIGNED NOT NULL,
  `reason`      TEXT         NOT NULL,
  `status`      ENUM('pending','reviewed','dismissed') NOT NULL DEFAULT 'pending',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_listing`  (`listing_id`),
  KEY `idx_reporter` (`reporter_id`),
  KEY `idx_status`   (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System Settings (key-value store for admin config)
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key`   VARCHAR(80) NOT NULL PRIMARY KEY,
  `setting_value` TEXT        DEFAULT NULL,
  `updated_at`    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default settings (will skip if already exist)
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
  ('listing_expiry_days', '30'),
  ('max_upload_mb', '5'),
  ('allowed_formats', 'jpg,png,webp'),
  ('session_timeout_mins', '60'),
  ('emailjs_service', ''),
  ('emailjs_public', ''),
  ('emailjs_otp_template', ''),
  ('emailjs_reset_template', ''),
  ('razorpay_key', ''),
  ('razorpay_currency', 'INR');
