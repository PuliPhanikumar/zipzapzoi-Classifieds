<?php
/**
 * ZipZapZoi Classifieds — Video Upload API
 * POST /api/upload_video.php
 * Accepts: multipart/form-data
 *   - Single file:   field name 'video'
 * Returns: { success: true, data: { url, filename, size, type } }
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('POST only', 405);
$user = requireAuth();

// Create upload directory if not exists
if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0755, true);
}

$allowed  = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
$maxBytes = 20 * 1024 * 1024; // 20MB limit for video
$finfo    = new finfo(FILEINFO_MIME_TYPE);

function processVideo(array $file, array $allowed, int $maxBytes, finfo $finfo): ?array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if ($file['size'] > $maxBytes) {
        return null;
    }
    $mimeType = $finfo->file($file['tmp_name']);
    if (!array_key_exists($mimeType, $allowed)) {
        return null;
    }

    $ext      = $allowed[$mimeType];
    $filename = 'vid_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $destPath = UPLOAD_DIR . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return null;
    }

    return [
        'url'      => UPLOAD_URL . $filename,
        'filename' => $filename,
        'size'     => $file['size'],
        'type'     => $mimeType,
    ];
}

if (empty($_FILES['video'])) {
    jsonError('No valid video file received. Field name must be "video".');
}

$result = processVideo($_FILES['video'], $allowed, $maxBytes, $finfo);

if (!$result) {
    jsonError('Failed to upload video. Ensure it is a valid MP4, WEBM, or MOV under 20MB.');
}

jsonOk($result);
