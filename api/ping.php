<?php
// ZipZapZoi API — Health Check
// Test URL: https://www.zipzapzoi.com/api/ping.php
header('Content-Type: application/json');
echo json_encode([
    'ok'      => true,
    'php'     => phpversion(),
    'time'    => date('Y-m-d H:i:s'),
    'server'  => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
]);
