<?php
/**
 * ZipZapZoi Classifieds — Admin Feedback API
 * GET /api/admin/feedback.php?status=all|unread|resolved
 * PUT /api/admin/feedback.php { id, status }
 */
require_once __DIR__ . '/../config.php';

// Auto-create table if missing
try {
    getDB()->exec("
        CREATE TABLE IF NOT EXISTS user_feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL,
            type VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            status ENUM('unread', 'read', 'resolved') DEFAULT 'unread',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (\Throwable $e) {}

$admin = requireAdmin();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $status = $_GET['status'] ?? 'all';
    if ($status === 'all') {
        $stmt = $db->query("SELECT * FROM user_feedback ORDER BY created_at DESC LIMIT 200");
    } else {
        $stmt = $db->prepare("SELECT * FROM user_feedback WHERE status = ? ORDER BY created_at DESC LIMIT 200");
        $stmt->execute([$status]);
    }
    $rows = $stmt->fetchAll();
    jsonOk(['feedbacks' => $rows]);

} elseif ($method === 'PUT') {
    $b = getBody();
    $id = isset($b['id']) ? (int)$b['id'] : 0;
    $status = $b['status'] ?? '';

    if (!$id || !in_array($status, ['unread', 'read', 'resolved'])) {
        jsonError('Valid ID and status (unread, read, resolved) required.', 400);
    }

    $stmt = $db->prepare("UPDATE user_feedback SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);
    jsonOk(['message' => "Feedback status updated to $status."]);

} elseif ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) jsonError('Feedback ID required.', 400);

    $db->prepare("DELETE FROM user_feedback WHERE id = ?")->execute([$id]);
    jsonOk(['message' => 'Feedback deleted.']);

} else {
    jsonError('Method not allowed', 405);
}
