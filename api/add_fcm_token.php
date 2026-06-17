<?php
require_once __DIR__ . '/config.php';
try {
    $db = getDB();
    $db->exec("ALTER TABLE users ADD COLUMN fcm_token VARCHAR(255) NULL DEFAULT NULL AFTER avatar_url");
    echo "Column added successfully.\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
