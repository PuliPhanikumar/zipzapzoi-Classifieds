<?php
/**
 * ZipZapZoi — Feedback API
 * POST /api/feedback.php { name, email, type, message }
 * GET  /api/feedback.php?status=all (Admin only)
 */
require_once __DIR__ . '/config.php';

// Auto-create table
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

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $b = getBody();

    $user = getCurrentUser();

    $name    = clean($b['name'] ?? ($user['name'] ?? 'App User'));
    $email   = clean($b['email'] ?? ($user['email'] ?? 'feedback@zipzapzoi.com'));
    $type    = clean($b['type'] ?? 'General Feedback');
    $message = clean($b['message'] ?? '');
    $userId  = $user ? (int)$user['id'] : null;

    if (empty($message)) {
        jsonError('Feedback message is required.', 400);
    }
    if (empty($name)) {
        $name = 'Anonymous User';
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = $user['email'] ?? 'user@zipzapzoi.com';
    }

    $db = getDB();
    $stmt = $db->prepare('INSERT INTO user_feedback (user_id, name, email, type, message) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $name, $email, $type, $message]);

    jsonOk(['message' => 'Feedback submitted successfully. Thank you for helping us improve ZipZapZoi!']);

} elseif ($method === 'GET') {
    $admin = requireAdmin();
    $db = getDB();
    $status = $_GET['status'] ?? 'all';

    if ($status === 'all') {
        $stmt = $db->query("SELECT * FROM user_feedback ORDER BY created_at DESC LIMIT 200");
    } else {
        $stmt = $db->prepare("SELECT * FROM user_feedback WHERE status = ? ORDER BY created_at DESC LIMIT 200");
        $stmt->execute([$status]);
    }
    $feedbacks = $stmt->fetchAll();
    jsonOk(['feedbacks' => $feedbacks, 'data' => $feedbacks]);

} elseif ($method === 'PUT') {
    $admin = requireAdmin();
    $b = getBody();
    $id = isset($b['id']) ? (int)$b['id'] : 0;
    $status = $b['status'] ?? null;

    if (!$id || !in_array($status, ['unread', 'read', 'resolved'])) {
        jsonError('Invalid ID or status.', 400);
    }

    getDB()->prepare("UPDATE user_feedback SET status = ? WHERE id = ?")->execute([$status, $id]);
    jsonOk(['message' => 'Feedback status updated.']);
} else {
    jsonError('Method not allowed', 405);
}
