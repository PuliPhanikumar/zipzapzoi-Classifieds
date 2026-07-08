-- ================================================================
-- Migration: Add Promotions and Contact Privacy to Listings
-- ================================================================

ALTER TABLE `listings`
ADD COLUMN `is_highlight` TINYINT(1) NOT NULL DEFAULT 0 AFTER `video_url`,
ADD COLUMN `is_top` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_highlight`,
ADD COLUMN `hide_phone` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_top`,
ADD COLUMN `allow_whatsapp` TINYINT(1) NOT NULL DEFAULT 0 AFTER `hide_phone`,
ADD COLUMN `contact_phone` VARCHAR(20) DEFAULT NULL AFTER `allow_whatsapp`;
