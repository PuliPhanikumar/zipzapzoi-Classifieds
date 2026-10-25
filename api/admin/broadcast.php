<?php
/**
 * ZipZapZoi Classifieds — Admin Push Broadcast API
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../fcm_helper.php';

$user = requireAdmin();  // SECURITY: was requireAuth() — any user could broadcast

$b     = getBody();
$title = trim($b['title'] ?? '');
$body  = trim($b['body'] ?? '');

if (!$title || !$body) {
    jsonError('Title and body are required.');
}

$db = getDB();
$stmt = $db->prepare('SELECT fcm_token FROM users WHERE fcm_token IS NOT NULL AND fcm_token != "" AND is_active = 1');
$stmt->execute();
$users = $stmt->fetchAll();

$sentCount = 0;
foreach ($users as $u) {
    if (sendFcmPush($u['fcm_token'], $title, $body, ['type' => 'broadcast'])) {
        $sentCount++;
    }
}

// Also persist broadcast notification to in-app notification inbox for all active users
try {
    $db->prepare("INSERT INTO user_notifications (user_id, type, title, message) SELECT id, 'broadcast', ?, ? FROM users WHERE is_active = 1")
       ->execute([$title, $body]);
} catch (\Throwable $e) {}

jsonOk(['message' => "Broadcast sent to $sentCount mobile devices and added to user notifications."]);
