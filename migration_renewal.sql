-- ================================================================
-- ZipZapZoi — Migration: Tighten Ad Renewal Rules
-- Run this in phpMyAdmin > SQL tab on your Hostinger database
-- ================================================================

-- 1. Add renewal tracking columns to listings table
ALTER TABLE `listings`
  ADD COLUMN `renewal_count` TINYINT UNSIGNED NOT NULL DEFAULT 0
      COMMENT 'Max 1 renewal per listing. 0 = never renewed, 1 = renewed once (final).'
      AFTER `expires_at`,
  ADD COLUMN `renewed_at` DATETIME DEFAULT NULL
      COMMENT 'Timestamp of when this listing was last renewed.'
      AFTER `renewal_count`,
  ADD INDEX `idx_renewal` (`renewal_count`);

-- 2. Add phone uniqueness to users (prevent new-account abuse for free ads)
--    NOTE: This will fail if duplicate phones already exist. Clean them first if needed.
ALTER TABLE `users`
  ADD COLUMN `phone_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `phone`;

-- 3. Add a unique constraint on phone (1 account per phone number)
--    Only run this if your existing data has unique phones!
-- ALTER TABLE `users` ADD UNIQUE KEY `uq_phone` (`phone`);
-- ^ Uncomment the above line ONLY after confirming no duplicate phones in your users table.

-- 4. Add RAZORPAY constants to config (reminder — add to api/config.php manually):
--    define('RAZORPAY_KEY_ID',     'rzp_live_XXXXXXXXXX');
--    define('RAZORPAY_KEY_SECRET', 'your_secret_here');

-- 5. Mark any listings older than 30 days as expired (cleanup)
UPDATE `listings`
SET `status` = 'expired'
WHERE `expires_at` IS NOT NULL
  AND `expires_at` < NOW()
  AND `status` = 'active';

-- Verify migration
SELECT
  COUNT(*) AS total_listings,
  SUM(renewal_count = 0) AS never_renewed,
  SUM(renewal_count >= 1) AS already_renewed,
  SUM(status = 'expired') AS expired_count
FROM listings;
