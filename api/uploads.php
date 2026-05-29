<?php
/**
 * ZipZapZoi Classifieds — Image Upload API
 * POST /api/uploads.php
 * Accepts: multipart/form-data with file field 'image'
 * Returns: { url: '/uploads/listings/filename.webp' }
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('POST only', 405);
$user = requireAuth();

// Check file was sent
if (empty($_FILES['image'])) jsonError('No image file received.');
$file = $_FILES['image'];
if ($file['error'] !== UPLOAD_ERR_OK) jsonError('Upload error: ' . $file['error']);

// Validate size (5MB max)
if ($file['size'] > MAX_UPLOAD_MB * 1024 * 1024) jsonError('Image must be under ' . MAX_UPLOAD_MB . 'MB.');

// Validate MIME type (allow only images)
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
$allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
if (!array_key_exists($mimeType, $allowed)) jsonError('Only JPG, PNG, WebP, or GIF images allowed.');

// Create upload directory if not exists
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Generate unique filename
$ext      = $allowed[$mimeType];
$filename = 'img_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$destPath = UPLOAD_DIR . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    jsonError('Failed to save image. Check server permissions.');
}

// Return public URL path
$publicUrl = UPLOAD_URL . $filename;
jsonOk([
    'url'      => $publicUrl,
    'filename' => $filename,
    'size'     => $file['size'],
    'type'     => $mimeType,
]);
