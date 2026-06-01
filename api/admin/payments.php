<?php
/**
 * ZipZapZoi — Admin Payments API
 * GET  /api/admin/payments.php            → transaction ledger
 * POST /api/admin/payments.php            → refund {id}
 */
require_once __DIR__ . '/../config.php';
$admin  = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET')  listPayments();
elseif ($method === 'POST') processRefund($admin);
else jsonError('Method not allowed', 405);

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
