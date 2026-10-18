<?php
/**
 * ZipZapZoi Classifieds â€” Image Upload API
 * POST /api/uploads.php
 * Accepts: multipart/form-data
 *   - Single file:   field name 'image'
 *   - Multi files:   field name 'images[]'
 * Returns: { success: true, data: { url, urls[], files[] } }
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('POST only', 405);
$user = requireAuth();

// --- Rate Limiting: Max 100 uploads per user per day ---
try {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS upload_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX(user_id, created_at))");
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM upload_logs WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $stmt->execute([(int)$user['id']]);
    if ((int)$stmt->fetchColumn() > 100) {
        jsonError('Upload rate limit exceeded. Please try again tomorrow.', 429);
    }
} catch (Exception $e) {}

// Create upload directory if not exists
if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0755, true);
}

$allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
              'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/3gpp' => '3gp'];
$maxBytes = MAX_UPLOAD_MB * 1024 * 1024;
$finfo    = new finfo(FILEINFO_MIME_TYPE);

function processFile(array $file, array $allowed, int $maxBytes, finfo $finfo): ?array {
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
    $filename = 'lst_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $destPath = UPLOAD_DIR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return null;
    }

    // Server-Side Auto-Compress & Auto-Resize to max 1600px
    $isImage = in_array($ext, ['jpg', 'png', 'webp', 'gif']);
    if ($isImage && extension_loaded('gd')) {
        try {
            $img = null;
            if ($ext === 'jpg' && function_exists('imagecreatefromjpeg')) $img = @imagecreatefromjpeg($destPath);
            elseif ($ext === 'png' && function_exists('imagecreatefrompng')) $img = @imagecreatefrompng($destPath);
            elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($destPath);
            elseif ($ext === 'gif' && function_exists('imagecreatefromgif')) $img = @imagecreatefromgif($destPath);

            if ($img) {
                $imgWidth  = imagesx($img);
                $imgHeight = imagesy($img);
                $maxPx     = 1600;

                // Resize if exceeding maxPx
                if ($imgWidth > $maxPx || $imgHeight > $maxPx) {
                    if ($imgWidth > $imgHeight) {
                        $newWidth  = $maxPx;
                        $newHeight = (int)round($imgHeight * ($maxPx / $imgWidth));
                    } else {
                        $newHeight = $maxPx;
                        $newWidth  = (int)round($imgWidth * ($maxPx / $imgHeight));
                    }

                    $resized = imagecreatetruecolor($newWidth, $newHeight);
                    if ($ext === 'png' || $ext === 'webp') {
                        imagealphablending($resized, false);
                        imagesavealpha($resized, true);
                        $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
                        imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
                    }
                    imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $imgWidth, $imgHeight);
                    imagedestroy($img);
                    $img = $resized;
                    $imgWidth = $newWidth;
                    $imgHeight = $newHeight;
                }

                // Optional Watermark
                $watermarkPath = __DIR__ . '/../images/watermark.png';
                if (file_exists($watermarkPath) && function_exists('imagecreatefrompng')) {
                    $watermark = @imagecreatefrompng($watermarkPath);
                    if ($watermark) {
                        $wmWidth  = imagesx($watermark);
                        $wmHeight = imagesy($watermark);
                        $scale    = 1;
                        if ($wmWidth > ($imgWidth * 0.15)) {
                            $scale = ($imgWidth * 0.15) / $wmWidth;
                        }
                        $newWmWidth  = max(1, (int)($wmWidth * $scale));
                        $newWmHeight = max(1, (int)($wmHeight * $scale));

                        $resizedWm = imagecreatetruecolor($newWmWidth, $newWmHeight);
                        imagealphablending($resizedWm, false);
                        imagesavealpha($resizedWm, true);
                        $transparent = imagecolorallocatealpha($resizedWm, 255, 255, 255, 127);
                        imagefilledrectangle($resizedWm, 0, 0, $newWmWidth, $newWmHeight, $transparent);
                        imagecopyresampled($resizedWm, $watermark, 0, 0, 0, 0, $newWmWidth, $newWmHeight, $wmWidth, $wmHeight);

                        $dstX = max(0, $imgWidth - $newWmWidth - 10);
                        $dstY = max(0, $imgHeight - $newWmHeight - 10);

                        // Merge watermark with transparency
                        if (function_exists('imagecopy')) {
                            imagealphablending($img, true);
                            imagecopy($img, $resizedWm, $dstX, $dstY, 0, 0, $newWmWidth, $newWmHeight);
                        }
                        imagedestroy($watermark);
                        imagedestroy($resizedWm);
                    }
                }

                // Save compressed image back (82% quality balances pristine clarity + tiny file size)
                if ($ext === 'jpg') {
                    imagejpeg($img, $destPath, 82);
                } elseif ($ext === 'webp') {
                    imagewebp($img, $destPath, 82);
                } elseif ($ext === 'png') {
                    // Try converting large non-transparent PNGs to JPEG or WebP to save up to 80% space
                    imagepng($img, $destPath, 6);
                }
                imagedestroy($img);
            }
        } catch (Exception $e) {
            error_log('Compression error: ' . $e->getMessage());
        }
    }

    $finalSize = file_exists($destPath) ? filesize($destPath) : $file['size'];

    return [
        'url'      => UPLOAD_URL . $filename,
        'filename' => $filename,
        'size'     => $finalSize,
        'type'     => $mimeType,
    ];
}



$results = [];

// â”€â”€ Handle multi-file field: images[] â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ Handle single file field: image â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$singleFile = $_FILES['image'] ?? $_FILES['file'] ?? $_FILES['avatar'] ?? null;
if (!empty($singleFile) && empty($results)) {
    $result = processFile($singleFile, $allowed, $maxBytes, $finfo);
    if ($result) $results[] = $result;
}

if (empty($results)) {
    jsonError('No valid image files received. Accepted: JPG, PNG, WebP, GIF under ' . MAX_UPLOAD_MB . 'MB each.');
}

$urls = array_map('toAbsoluteUrl', array_column($results, 'url'));
foreach ($results as &$r) { $r['url'] = toAbsoluteUrl($r['url']); }
unset($r);

// Log successful uploads for rate limiting
try {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO upload_logs (user_id) VALUES (?)");
    foreach ($results as $res) {
        $stmt->execute([(int)$user['id']]);
    }
} catch (Exception $e) {}

jsonOk([
    'url'   => $urls[0],       // backwards compat for single-upload callers
    'urls'  => $urls,          // new multi-upload callers
    'files' => $results,
]);


