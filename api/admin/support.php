<?php
/**
 * ZipZapZoi — Admin Support Tickets API
 * GET /api/admin/support.php           → list tickets
 * PUT /api/admin/support.php           → resolve ticket {id, status}
 */
require_once __DIR__ . '/../config.php';
$admin  = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') listTickets();
elseif ($method === 'PUT') updateTicket($admin);
else jsonError('Method not allowed', 405);

function listTickets(): void {
    $db   = getDB();
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
    
    try {
        $db->prepare(
            'INSERT INTO admin_logs (admin_id, admin_name, action, detail) VALUES (?,?,?,?)'
        )->execute([(int)$admin['id'], $admin['name'], 'RESOLVE_TICKET', "Ticket ID: $id → $status"]);
    } catch (\Throwable $e) {}

    jsonOk(['message' => "Ticket $id marked as $status."]);
}
