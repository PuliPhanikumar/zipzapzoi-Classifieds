ALTER TABLE `listings`
ADD COLUMN `latitude` DECIMAL(10,8) DEFAULT NULL AFTER `location_area`,
ADD COLUMN `longitude` DECIMAL(11,8) DEFAULT NULL AFTER `latitude`;
