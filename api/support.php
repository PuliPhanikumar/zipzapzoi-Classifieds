<?php
/**
 * ZipZapZoi — User Support API
 * POST /api/support.php
 *   Body: { subject, message, priority (optional) }
 */
require_once __DIR__ . '/config.php';
$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') jsonError('Method not allowed', 405);

$b        = getBody();
$subject  = clean($b['subject'] ?? '');
$message  = clean($b['message'] ?? '');
$priority = in_array($b['priority'] ?? '', ['low','medium','high']) ? $b['priority'] : 'low';

if (strlen($subject) < 5) jsonError('Please provide a descriptive subject (min 5 characters).');
if (strlen($message) < 10) jsonError('Please provide more details in your message (min 10 characters).');

$db = getDB();

// Auto-create support_tickets table if not exists
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS support_tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            subject VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            priority ENUM('low','medium','high') DEFAULT 'low',
            sla_hours INT DEFAULT 48,
            status ENUM('open','resolved') DEFAULT 'open',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ticket_user (user_id),
            INDEX idx_ticket_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (\Throwable $e) {}

// Anti-spam: prevent user from submitting more than 3 open tickets at a time
$openStmt = $db->prepare('SELECT COUNT(*) FROM support_tickets WHERE user_id = ? AND status = "open"');
$openStmt->execute([$user['id']]);
$openCount = (int)$openStmt->fetchColumn();

if ($openCount >= 3) {
    jsonError('You already have 3 open support tickets. Please wait for them to be resolved before submitting a new one.');
}

// SLA logic: High = 12h, Medium = 24h, Low = 48h
$slaMap = ['high' => 12, 'medium' => 24, 'low' => 48];
$slaHours = $slaMap[$priority];

$db->prepare(
    'INSERT INTO support_tickets (user_id, subject, message, priority, sla_hours, status, created_at)
     VALUES (?, ?, ?, ?, ?, "open", NOW())'
)->execute([$user['id'], $subject, $message, $priority, $slaHours]);

jsonOk(['message' => 'Your ticket has been submitted. Our support team will get back to you shortly.']);
