<?php
require_once __DIR__ . '/config.php';
$db = getDB();

try {
    // Add columns if they don't exist
    $db->exec("ALTER TABLE listings ADD COLUMN lat DECIMAL(10, 8) NULL AFTER location_area");
    $db->exec("ALTER TABLE listings ADD COLUMN lng DECIMAL(11, 8) NULL AFTER lat");
    echo "Columns lat/lng added successfully.";
} catch (PDOException $e) {
    // 1060 is Duplicate column name error
    if ($e->getCode() == '42S21') {
        echo "Columns already exist.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
