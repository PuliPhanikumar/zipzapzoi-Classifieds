<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

try {
    include_once __DIR__ . '/zoi_ai.php';
    echo "Syntax OK!";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " on line " . $e->getLine();
}
