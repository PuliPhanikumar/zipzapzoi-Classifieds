<?php
/**
 * TEMPORARY DIAGNOSTIC SCRIPT - DELETE AFTER USE
 * Visit: https://www.zipzapzoi.com/api/debug_uploads.php?key=zzz_debug_2026
 */

if (($_GET['key'] ?? '') !== 'zzz_debug_2026') {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

$uploadDir = UPLOAD_DIR;
$uploadUrl = UPLOAD_URL;

// Check if directory exists
$dirExists = is_dir($uploadDir);

// Check if directory is writable
$dirWritable = $dirExists && is_writable($uploadDir);

// Try to write a test file
$testFile = $uploadDir . 'test_write_' . time() . '.txt';
$writeTest = false;
$writeError = '';
if ($dirWritable) {
    $result = @file_put_contents($testFile, 'test');
    if ($result !== false) {
        $writeTest = true;
        @unlink($testFile); // clean up
    } else {
        $writeError = error_get_last()['message'] ?? 'Unknown error';
    }
}

// List existing files in the uploads directory
$files = [];
if ($dirExists) {
    $allFiles = @scandir($uploadDir);
    if ($allFiles) {
        foreach ($allFiles as $f) {
            if ($f === '.' || $f === '..') continue;
            $fp = $uploadDir . $f;
            $files[] = [
                'name' => $f,
                'size_bytes' => @filesize($fp),
                'modified' => @date('Y-m-d H:i:s', @filemtime($fp)),
                'url' => $uploadUrl . $f,
            ];
        }
    }
}

// Check a recent listing from DB
$db = getDB();
$recentListings = $db->query(
    "SELECT id, title, images, status, created_at FROM listings ORDER BY created_at DESC LIMIT 5"
)->fetchAll();

foreach ($recentListings as &$l) {
    $imgs = json_decode($l['images'] ?? '[]', true) ?: [];
    $l['images_parsed'] = $imgs;
    // Check if file actually exists on disk
    $l['image_files_on_disk'] = array_map(function($url) use ($uploadDir, $uploadUrl) {
        $filename = basename($url);
        $diskPath = $uploadDir . $filename;
        return [
            'url' => $url,
            'filename' => $filename,
            'exists_on_disk' => file_exists($diskPath),
            'disk_path_checked' => $diskPath,
        ];
    }, $imgs);
    unset($l['images']);
}

echo json_encode([
    'server_info' => [
        'php_version'       => PHP_VERSION,
        'server_software'   => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
        'document_root'     => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
        'script_filename'   => __FILE__,
    ],
    'upload_config' => [
        'UPLOAD_DIR'        => $uploadDir,
        'UPLOAD_URL'        => $uploadUrl,
        'dir_exists'        => $dirExists,
        'dir_writable'      => $dirWritable,
        'write_test_passed' => $writeTest,
        'write_error'       => $writeError,
    ],
    'files_in_uploads_dir' => $files,
    'recent_listings_db'   => $recentListings,
], JSON_PRETTY_PRINT);
