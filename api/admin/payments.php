<?php
/**
 * ZipZapZoi — Admin Payments API
 * GET  /api/admin/payments.php            → transaction ledger
 * GET  /api/admin/payments.php?stats=1   → revenue stats for dashboard
 * POST /api/admin/payments.php            → refund {id}
 */
require_once __DIR__ . '/../config.php';
$admin  = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && ($_GET['stats'] ?? '') === '1') getPaymentStats();
elseif ($method === 'GET')  listPayments();
elseif ($method === 'POST') processRefund($admin);
else jsonError('Method not allowed', 405);

function getPaymentStats(): void {
    $db   = getDB();
    $rev  = $db->query("SELECT COALESCE(SUM(amount),0) AS total FROM transactions WHERE status='success'")->fetchColumn();
    $paid = $db->query("SELECT COUNT(*) FROM transactions WHERE status='success'")->fetchColumn();
    $free = $db->query("SELECT COUNT(*) FROM users u LEFT JOIN user_quotas q ON q.user_id=u.id WHERE COALESCE(q.plan_id,'free')='free' AND u.is_active=1")->fetchColumn();
    jsonOk(['total_revenue'=>(float)$rev,'paid_plans'=>(int)$paid,'free_users'=>(int)$free]);
}

function listPayments(): void {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT t.id, t.plan_id, t.plan_name, t.amount, t.currency,
                t.razorpay_payment_id, t.razorpay_order_id, t.status, t.created_at,
                u.name AS user_name, u.email AS user_email
         FROM transactions t
         JOIN users u ON u.id = t.user_id
         ORDER BY t.created_at DESC
         LIMIT 500"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id']     = (int)$r['id'];
        $r['amount'] = (float)$r['amount'];
    }
    jsonOk($rows);
}

function processRefund(array $admin): void {
    $db  = getDB();
    $b   = getBody();
    $id  = (int)($b['id'] ?? 0);
    if (!$id) jsonError('Transaction id required.');

    $txn = $db->prepare('SELECT * FROM transactions WHERE id=?');
    $txn->execute([$id]);
    $t = $txn->fetch();
    if (!$t) jsonError('Transaction not found.', 404);
    if ($t['status'] !== 'success') jsonError('Only successful transactions can be refunded.');

    $db->prepare("UPDATE transactions SET status='refunded' WHERE id=?")->execute([$id]);
    adminLog($admin, 'REFUND', "Txn ID: $id | Amount: ₹{$t['amount']}");
    jsonOk(['message' => "Transaction $id marked as refunded."]);
}

function adminLog(array $admin, string $action, string $detail = ''): void {
    try {
        getDB()->prepare(
            'INSERT INTO admin_logs (admin_id, admin_name, action, detail) VALUES (?,?,?,?)'
        )->execute([(int)$admin['id'], $admin['name'], $action, $detail]);
    } catch (\Throwable $e) {}
}
