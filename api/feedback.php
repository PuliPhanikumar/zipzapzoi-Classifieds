<?php
require_once 'config.php';

header('Content-Type: application/json');

// Ensure table exists
try {
    $pdo->exec("
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
} catch(PDOException $e) {
    error_log("Failed to create user_feedback table: " . $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $name = trim($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    $type = trim($data['type'] ?? 'General');
    $message = trim($data['message'] ?? '');
    $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

    if (empty($name) || empty($email) || empty($message)) {
        jsonError('Name, email, and message are required.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError('Invalid email address.');
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO user_feedback (user_id, name, email, type, message) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $name, $email, $type, $message]);
        jsonOk(['message' => 'Feedback submitted successfully. Thank you!']);
    } catch(PDOException $e) {
        error_log("Feedback Insert Error: " . $e->getMessage());
        jsonError('Failed to submit feedback. Please try again later.');
    }

} elseif ($method === 'GET') {
    // Admin only
    requireAdmin();
    
    $status = $_GET['status'] ?? 'all';
    
    try {
        if ($status === 'all') {
            $stmt = $pdo->query("SELECT * FROM user_feedback ORDER BY created_at DESC");
        } else {
            $stmt = $pdo->prepare("SELECT * FROM user_feedback WHERE status = ? ORDER BY created_at DESC");
            $stmt->execute([$status]);
        }
        $feedbacks = $stmt->fetchAll();
        jsonOk(['feedbacks' => $feedbacks]);
    } catch(PDOException $e) {
        jsonError('Failed to fetch feedback.');
    }

} elseif ($method === 'PUT') {
    // Admin only
    requireAdmin();
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id = $data['id'] ?? null;
    $status = $data['status'] ?? null;
    
    if (!$id || !in_array($status, ['unread', 'read', 'resolved'])) {
        jsonError('Invalid ID or status.');
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE user_feedback SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        jsonOk(['message' => 'Feedback status updated.']);
    } catch(PDOException $e) {
        jsonError('Failed to update feedback.');
    }
} else {
    jsonError('Method not allowed.');
}
