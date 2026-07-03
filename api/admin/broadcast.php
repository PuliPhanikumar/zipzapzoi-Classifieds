<?php
/**
 * ZipZapZoi Classifieds — Admin Push Broadcast API
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../fcm_helper.php';

$user = requireAuth();
if ($user['role'] !== 'admin' && $user['role'] !== 'super_admin') {
    jsonError('Unauthorized', 403);
}

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
    if (sendFcmPush($u['fcm_token'], $title, $body)) {
        $sentCount++;
    }
}

jsonOk(['message' => "Broadcast sent to $sentCount devices."]);
