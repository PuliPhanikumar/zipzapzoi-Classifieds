<?php
require_once __DIR__ . '/config.php';
$db = getDB();
$sql = file_get_contents(__DIR__ . '/../migration_promotions_privacy.sql');
try {
    $db->exec($sql);
    echo "Migration completed successfully!";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage();
}
