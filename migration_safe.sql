-- ================================================================
-- ZipZapZoi — SAFE Migration (MySQL 5.7 / MySQL 8.0 / MariaDB)
-- Run in: Hostinger phpMyAdmin → u572945141_Classifieds_db → SQL
-- This script checks before altering — safe to run multiple times.
-- ================================================================

-- ── STEP 1: Add missing columns to `listings` ─────────────────────
-- Uses a stored procedure to check before adding (no duplicate errors)

DROP PROCEDURE IF EXISTS zzz_safe_migrate;

DELIMITER //
CREATE PROCEDURE zzz_safe_migrate()
BEGIN

  -- renewal_count: tracks how many times a listing was renewed (max 1)
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'listings'
      AND COLUMN_NAME  = 'renewal_count'
  ) THEN
    ALTER TABLE `listings`
      ADD COLUMN `renewal_count` TINYINT UNSIGNED NOT NULL DEFAULT 0
      COMMENT 'Max 1 renewal per listing. 0=never renewed, 1=renewed once (final).'
      AFTER `expires_at`;
    SELECT 'Added: renewal_count' AS result;
  ELSE
    SELECT 'Skipped: renewal_count already exists' AS result;
  END IF;

  -- renewed_at: timestamp of last renewal
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'listings'
      AND COLUMN_NAME  = 'renewed_at'
  ) THEN
    ALTER TABLE `listings`
      ADD COLUMN `renewed_at` DATETIME DEFAULT NULL
      COMMENT 'Timestamp of last renewal.'
      AFTER `renewal_count`;
    SELECT 'Added: renewed_at' AS result;
  ELSE
    SELECT 'Skipped: renewed_at already exists' AS result;
  END IF;

  -- condition: item condition for listings (new / used / refurbished)
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'listings'
      AND COLUMN_NAME  = 'condition'
  ) THEN
    ALTER TABLE `listings`
      ADD COLUMN `condition` VARCHAR(50) DEFAULT NULL
      AFTER `price_type`;
    SELECT 'Added: condition' AS result;
  ELSE
    SELECT 'Skipped: condition already exists' AS result;
  END IF;

  -- idx_renewal index (skip if already exists)
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'listings'
      AND INDEX_NAME   = 'idx_renewal'
  ) THEN
    ALTER TABLE `listings` ADD INDEX `idx_renewal` (`renewal_count`);
    SELECT 'Added: idx_renewal index' AS result;
  ELSE
    SELECT 'Skipped: idx_renewal already exists' AS result;
  END IF;

  -- idx_expires index
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'listings'
      AND INDEX_NAME   = 'idx_expires'
  ) THEN
    ALTER TABLE `listings` ADD INDEX `idx_expires` (`expires_at`);
    SELECT 'Added: idx_expires index' AS result;
  ELSE
    SELECT 'Skipped: idx_expires already exists' AS result;
  END IF;

END//
DELIMITER ;

-- Run the migration
CALL zzz_safe_migrate();

-- Clean up procedure after use
DROP PROCEDURE IF EXISTS zzz_safe_migrate;


-- ── STEP 2: Admin tables (admin_logs, coupons, reports, system_settings) ──

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

CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key`   VARCHAR(80) NOT NULL PRIMARY KEY,
  `setting_value` TEXT        DEFAULT NULL,
  `updated_at`    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── STEP 3: Seed system_settings defaults (safe — skips if already exist) ──

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
  ('listing_expiry_days',  '30'),
  ('max_upload_mb',        '5'),
  ('allowed_formats',      'jpg,png,webp'),
  ('session_timeout_mins', '60'),
  ('emailjs_service',      ''),
  ('emailjs_public',       ''),
  ('emailjs_otp_template', ''),
  ('emailjs_reset_template',''),
  ('razorpay_key',         ''),
  ('razorpay_secret',      ''),
  ('razorpay_currency',    'INR');


-- ── STEP 4: Verify — run these SELECTs to confirm everything is OK ─

SELECT
  COLUMN_NAME,
  COLUMN_TYPE,
  IS_NULLABLE,
  COLUMN_DEFAULT,
  COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'listings'
  AND COLUMN_NAME  IN ('renewal_count', 'renewed_at', 'condition', 'expires_at', 'status')
ORDER BY ORDINAL_POSITION;

SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key;
