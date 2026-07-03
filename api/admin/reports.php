<?php
/**
 * ZipZapZoi — Admin Reports / Flags API
 * GET /api/admin/reports.php           → list pending reports
 * PUT /api/admin/reports.php           → update status {id, status}
 */
require_once __DIR__ . '/../config.php';
$admin  = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') listReports();
elseif ($method === 'PUT') updateReport($admin);
else jsonError('Method not allowed', 405);

function listReports(): void {
    $db   = getDB();
    $stmt = $db->query(
        "SELECT r.id, r.reason, r.status, r.created_at,
                l.id AS listing_id, l.title AS listing_title, l.status AS listing_status,
                u.name AS reporter_name, u.email AS reporter_email,
                s.name AS seller_name
         FROM reports r
         JOIN listings l ON l.id = r.listing_id
         JOIN users u ON u.id = r.reporter_id
         JOIN users s ON s.id = l.user_id
         ORDER BY r.created_at DESC
         LIMIT 200"
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id']         = (int)$r['id'];
        $r['listing_id'] = (int)$r['listing_id'];
    }

    // Abuse tracking: count reports per seller
    $abuseStmt = $db->query(
        "SELECT s.name AS seller_name, s.id AS seller_id, COUNT(r.id) AS report_count
         FROM reports r
         JOIN listings l ON l.id = r.listing_id
         JOIN users s ON s.id = l.user_id
         GROUP BY s.id
         ORDER BY report_count DESC
         LIMIT 20"
    );
    $abuse = $abuseStmt->fetchAll();
    foreach ($abuse as &$a) {
        $a['seller_id']    = (int)$a['seller_id'];
        $a['report_count'] = (int)$a['report_count'];
    }

    jsonOk(['reports' => $rows, 'abuse_tracking' => $abuse]);
}

function updateReport(array $admin): void {
    $db     = getDB();
    $b      = getBody();
    $id     = (int)($b['id'] ?? 0);
    $status = $b['status'] ?? '';
    $allowed = ['pending','reviewed','dismissed'];

    if (!$id) jsonError('Report id required.');

    if ($status === 'ban_user') {
        $userId = (int)($b['user_id'] ?? 0);
        if (!$userId) jsonError('User ID required to ban.');
        $db->prepare('UPDATE users SET is_banned = 1, is_active = 0 WHERE id=?')->execute([$userId]);
        $db->prepare('UPDATE reports SET status="reviewed" WHERE id=?')->execute([$id]);
        adminLog($admin, 'BAN_USER', "Banned User ID: $userId");
        jsonOk(['message' => "User banned successfully."]);
    }

    if ($status === 'deduct_trust') {
        $userId = (int)($b['user_id'] ?? 0);
        $amount = (int)($b['amount'] ?? 20);
        if (!$userId) jsonError('User ID required.');
        $db->prepare('UPDATE users SET trust_score = GREATEST(0, trust_score - ?) WHERE id=?')->execute([$amount, $userId]);
        $db->prepare('UPDATE reports SET status="reviewed" WHERE id=?')->execute([$id]);
        adminLog($admin, 'DEDUCT_TRUST', "Deducted $amount trust from User ID: $userId");
        jsonOk(['message' => "Trust score deducted successfully."]);
    }

    if (!in_array($status, $allowed)) jsonError('Invalid status.');

    $db->prepare('UPDATE reports SET status=? WHERE id=?')->execute([$status, $id]);
    adminLog($admin, 'UPDATE_REPORT', "Report ID: $id → $status");
    jsonOk(['message' => "Report $id marked $status."]);
}

function adminLog(array $admin, string $action, string $detail = ''): void {
    try {
        getDB()->prepare(
            'INSERT INTO admin_logs (admin_id, admin_name, action, detail) VALUES (?,?,?,?)'
        )->execute([(int)$admin['id'], $admin['name'], $action, $detail]);
    } catch (\Throwable $e) {}
}
