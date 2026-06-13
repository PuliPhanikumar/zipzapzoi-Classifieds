<?php
/**
 * ZipZapZoi - Feedback API
 */
require_once 'config.php';

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
} catch(PDOException $e) {}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $b = getBody();
    
    $name = clean($b['name'] ?? '');
    $email = clean($b['email'] ?? '');
    $type = clean($b['type'] ?? 'General');
    $message = clean($b['message'] ?? '');
    
    // Try to get user_id from token if provided
    $userId = null;
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? '';
    if ($auth && preg_match('/Bearer\s+(.*)$/i', $auth, $m)) {
        $db = getDB();
        $stmt = $db->prepare('SELECT user_id FROM sessions WHERE token=? AND expires_at > NOW()');
        $stmt->execute([$m[1]]);
        $userId = $stmt->fetchColumn() ?: null;
    }

    if (!$name || !$email || !$message) jsonError('Name, email, and message are required.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Invalid email address.');

    $db = getDB();
    $stmt = $db->prepare('INSERT INTO user_feedback (user_id, name, email, type, message) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $name, $email, $type, $message]);
    
    jsonOk(['message' => 'Feedback submitted successfully. Thank you!']);

} elseif ($method === 'GET') {
    $admin = requireAdmin();
    $db = getDB();
    $status = $_GET['status'] ?? 'all';
    
    if ($status === 'all') {
        $stmt = $db->query("SELECT * FROM user_feedback ORDER BY created_at DESC");
    } else {
        $stmt = $db->prepare("SELECT * FROM user_feedback WHERE status = ? ORDER BY created_at DESC");
        $stmt->execute([$status]);
    }
    jsonOk(['data' => $stmt->fetchAll()]);

} elseif ($method === 'PUT') {
    $admin = requireAdmin();
    $b = getBody();
    $id = $b['id'] ?? null;
    $status = $b['status'] ?? null;
    
    if (!$id || !in_array($status, ['unread', 'read', 'resolved'])) jsonError('Invalid ID or status.');
    
    getDB()->prepare("UPDATE user_feedback SET status = ? WHERE id = ?")->execute([$status, $id]);
    jsonOk(['message' => 'Feedback status updated.']);
} else {
    jsonError('Method not allowed', 405);
}
