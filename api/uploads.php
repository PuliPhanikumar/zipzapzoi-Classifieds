<?php
/**
 * ZipZapZoi Classifieds — Image Upload API
 * POST /api/uploads.php
 * Accepts: multipart/form-data
 *   - Single file:   field name 'image'
 *   - Multi files:   field name 'images[]'
 * Returns: { success: true, data: { url, urls[], files[] } }
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('POST only', 405);
$user = requireAuth();

// Create upload directory if not exists
if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0755, true);
}

$allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$maxBytes = MAX_UPLOAD_MB * 1024 * 1024;
$finfo    = new finfo(FILEINFO_MIME_TYPE);

function processFile(array $file, array $allowed, int $maxBytes, finfo $finfo): ?array {
    if ($file['error'] !== UPLOAD_ERR_OK)  return null;
    if ($file['size'] > $maxBytes)          return null;
    $mimeType = $finfo->file($file['tmp_name']);
    if (!array_key_exists($mimeType, $allowed)) return null;

    $ext      = $allowed[$mimeType];
    $filename = 'lst_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $destPath = UPLOAD_DIR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) return null;

    return [
        'url'      => UPLOAD_URL . $filename,
        'filename' => $filename,
        'size'     => $file['size'],
        'type'     => $mimeType,
    ];
}

$results = [];

// ── Handle multi-file field: images[] ────────────────────────────────
if (!empty($_FILES['images'])) {
    $images = $_FILES['images'];
    // Re-organise PHP's multi-file array format
    $count = is_array($images['name']) ? count($images['name']) : 1;
    for ($i = 0; $i < min($count, 10); $i++) {
        if (is_array($images['name'])) {
            $file = [
                'name'     => $images['name'][$i],
                'type'     => $images['type'][$i],
                'tmp_name' => $images['tmp_name'][$i],
                'error'    => $images['error'][$i],
                'size'     => $images['size'][$i],
            ];
        } else {
            $file = $images;
        }
        $result = processFile($file, $allowed, $maxBytes, $finfo);
        if ($result) $results[] = $result;
    }
}

// ── Handle single file field: image ──────────────────────────────────
if (!empty($_FILES['image']) && empty($results)) {
    $result = processFile($_FILES['image'], $allowed, $maxBytes, $finfo);
    if ($result) $results[] = $result;
}

if (empty($results)) {
    jsonError('No valid image files received. Accepted: JPG, PNG, WebP, GIF under ' . MAX_UPLOAD_MB . 'MB each.');
}

$urls = array_column($results, 'url');

jsonOk([
    'url'   => $urls[0],       // backwards compat for single-upload callers
    'urls'  => $urls,          // new multi-upload callers
    'files' => $results,
]);
