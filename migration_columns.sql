-- ================================================================
-- ZipZapZoi — Combined Migration (run AFTER migration_admin.sql)
-- Paste into: Hostinger phpMyAdmin → u572945141_Classifieds_db → SQL
-- ================================================================

-- 1. Add missing columns to listings table
ALTER TABLE `listings`
  ADD COLUMN IF NOT EXISTS `renewal_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `expires_at`,
  ADD COLUMN IF NOT EXISTS `renewed_at`    DATETIME         DEFAULT NULL AFTER `renewal_count`,
  ADD COLUMN IF NOT EXISTS `condition`     VARCHAR(50)      DEFAULT NULL AFTER `price_type`;

-- 2. Add Razorpay secret to system_settings
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
  ('razorpay_secret', '');

-- 3. Add index on expires_at for fast expiry checks
ALTER TABLE `listings` ADD INDEX IF NOT EXISTS `idx_expires` (`expires_at`);

-- Verify (optional — check your columns)
-- DESCRIBE listings;
-- SELECT setting_key, setting_value FROM system_settings;
