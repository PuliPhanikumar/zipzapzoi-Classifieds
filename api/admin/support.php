<?php
/**
 * ZipZapZoi — Admin Support Tickets API
 * GET /api/admin/support.php           → list tickets
 * PUT /api/admin/support.php           → resolve ticket {id, status}
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../fcm_helper.php';
$admin  = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') listTickets();
elseif ($method === 'PUT') updateTicket($admin);
else jsonError('Method not allowed', 405);

function listTickets(): void {
    $db   = getDB();
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

    // In SLA management, highest priority and oldest un-resolved should be at top.
    $stmt = $db->query(
        "SELECT t.id, t.subject, t.message, t.status, t.priority, t.sla_hours, t.created_at,
                u.name AS user_name, u.email AS user_email
         FROM support_tickets t
         JOIN users u ON u.id = t.user_id
         ORDER BY 
            t.status ASC, -- 'open' comes before 'resolved'
            FIELD(t.priority, 'high', 'medium', 'low'),
            t.created_at ASC
         LIMIT 200"
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id']        = (int)$r['id'];
        $r['sla_hours'] = (int)$r['sla_hours'];
    }

    jsonOk($rows);
}

function updateTicket(array $admin): void {
    $db     = getDB();
    $b      = getBody();
    $id     = (int)($b['id'] ?? 0);
    $status = $b['status'] ?? '';
    
    if (!$id) jsonError('Ticket id required.');
    if ($status !== 'resolved' && $status !== 'open') jsonError('Invalid status.');

    $db->prepare('UPDATE support_tickets SET status=? WHERE id=?')->execute([$status, $id]);
    
    if ($status === 'resolved') {
        try {
            $tStmt = $db->prepare("SELECT t.user_id, t.subject, u.fcm_token FROM support_tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?");
            $tStmt->execute([$id]);
            $tData = $tStmt->fetch();
            if ($tData) {
                $uid  = (int)$tData['user_id'];
                $subj = $tData['subject'];
                $msg  = "Your support ticket #$id (\"$subj\") has been resolved by our support team.";
                $db->prepare("INSERT INTO user_notifications (user_id, type, title, message, link) VALUES (?, 'ticket_resolved', 'Support Ticket Resolved 💬', ?, 'support')")
                   ->execute([$uid, $msg]);
                if (!empty($tData['fcm_token'])) {
                    sendFcmPush($tData['fcm_token'], 'Support Ticket Resolved 💬', $msg, ['ticket_id' => (string)$id, 'type' => 'ticket_resolved']);
                }
            }
        } catch (\Throwable $e) {}
    }

    try {
        $db->prepare(
            'INSERT INTO admin_logs (admin_id, admin_name, action, detail) VALUES (?,?,?,?)'
        )->execute([(int)$admin['id'], $admin['name'], 'RESOLVE_TICKET', "Ticket ID: $id → $status"]);
    } catch (\Throwable $e) {}

    jsonOk(['message' => "Ticket $id marked as $status."]);
}
