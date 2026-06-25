<?php
/**
 * ZipZapZoi — Notifications API
 * GET    /api/notifications.php           - Get current user's notifications
 * POST   /api/notifications.php?action=read - Mark notifications as read
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php'; // ensure auth logic is available

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Auto-create table
try {
    getDB()->exec("
        CREATE TABLE IF NOT EXISTS user_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(255),
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (Exception $e) {}

$user = getCurrentUser();
if (!$user) {
    jsonError('Unauthorized', 401);
}

if ($method === 'GET') {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$user['id']]);
    $notifications = $stmt->fetchAll();
    
    $unread_stmt = $db->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0");
    $unread_stmt->execute([$user['id']]);
    $unread_count = $unread_stmt->fetchColumn();

    jsonOk([
        'notifications' => $notifications,
        'unread_count' => (int)$unread_count
    ]);
} elseif ($method === 'POST' && $action === 'read') {
    $db = getDB();
    $stmt = $db->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    jsonOk(['message' => 'Notifications marked as read']);
} else {
    jsonError('Method not allowed', 405);
}
