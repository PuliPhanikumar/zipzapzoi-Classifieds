-- ZipZapZoi Live Database Patch
-- Run this in your MySQL console (e.g., phpMyAdmin, Workbench) to safely add the new columns.

-- 1. Add coordinates if they don't exist
ALTER TABLE listings ADD COLUMN lat DECIMAL(10, 8) NULL AFTER location_area;
ALTER TABLE listings ADD COLUMN lng DECIMAL(11, 8) NULL AFTER lat;

-- 2. Add Premium Story features if they don't exist
ALTER TABLE listings ADD COLUMN is_story TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE listings ADD COLUMN video_url VARCHAR(255) NULL;
