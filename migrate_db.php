<?php
require_once __DIR__ . '/api/config.php';
$db = getDB();
try {
    $db->exec('ALTER TABLE users ADD COLUMN referral_code VARCHAR(20) UNIQUE DEFAULT NULL');
    echo "Added referral_code\n";
} catch (Exception $e) { echo $e->getMessage() . "\n"; }
try {
    $db->exec('ALTER TABLE users ADD COLUMN referred_by INT UNSIGNED DEFAULT NULL');
    echo "Added referred_by\n";
} catch (Exception $e) { echo $e->getMessage() . "\n"; }
echo "Done.";
