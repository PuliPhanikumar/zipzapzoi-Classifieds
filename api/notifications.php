<?php
/**
 * ZipZapZoi — Notifications API
 * GET    /api/notifications.php             → get current user's notifications
 * POST   /api/notifications.php?action=read → mark all as read
 * DELETE /api/notifications.php?id=X       → delete single notification
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

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

// requireAuth() — consistent with all other API files (was getCurrentUser())
$user = requireAuth();

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
        'unread_count'  => (int)$unread_count
    ]);

} elseif ($method === 'POST' && $action === 'read') {
    $db = getDB();
    $db->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ?")
       ->execute([$user['id']]);
    jsonOk(['message' => 'All notifications marked as read']);

} elseif ($method === 'DELETE' && $id) {
    $db = getDB();
    $db->prepare("DELETE FROM user_notifications WHERE id = ? AND user_id = ?")
       ->execute([$id, $user['id']]);
    jsonOk(['message' => 'Notification deleted']);

} else {
    jsonError('Method not allowed', 405);
}
