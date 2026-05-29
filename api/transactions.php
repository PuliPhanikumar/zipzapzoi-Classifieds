<?php
/**
 * ZipZapZoi Classifieds — Transactions API
 * GET  /api/transactions.php          → my transaction history
 * POST /api/transactions.php          → record payment + assign quota
 */
require_once __DIR__ . '/config.php';

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET')  getTransactions($user);
elseif ($method === 'POST') recordTransaction($user);
else jsonError('Method not allowed', 405);

function getTransactions(array $user): void {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 50'
    );
    $stmt->execute([(int)$user['id']]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) { $r['amount'] = (float)$r['amount']; }
    jsonOk($rows);
}

function recordTransaction(array $user): void {
    $b = getBody();
    $planId   = clean($b['plan_id']   ?? '');
    $planName = clean($b['plan_name'] ?? '');
    $amount   = (float)($b['amount'] ?? 0);
    $rzpId    = clean($b['razorpay_payment_id'] ?? '');
    $rzpOrder = clean($b['razorpay_order_id']   ?? '');
    $status   = in_array($b['status'] ?? '', ['success','failed','refunded']) ? $b['status'] : 'success';
    $ads      = (int)($b['ads'] ?? 0);
    $days     = (int)($b['days'] ?? 30);

    if (!$planId)  jsonError('plan_id is required.');
    if ($amount < 0) jsonError('Invalid amount.');

    $db = getDB();
    $db->prepare(
        'INSERT INTO transactions (user_id, plan_id, plan_name, amount, razorpay_payment_id, razorpay_order_id, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([(int)$user['id'], $planId, $planName, $amount, $rzpId, $rzpOrder, $status]);
    $txnId = (int)$db->lastInsertId();

    // Assign quota if payment successful and ads granted
    if ($status === 'success' && $ads > 0) {
        $expires = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        $db->prepare(
            'INSERT INTO user_quotas (user_id, ads_remaining, total_granted, plan_id, plan_name, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               ads_remaining = ads_remaining + VALUES(ads_remaining),
               total_granted = total_granted + VALUES(total_granted),
               plan_id = VALUES(plan_id), plan_name = VALUES(plan_name),
               expires_at = VALUES(expires_at)'
        )->execute([(int)$user['id'], $ads, $ads, $planId, $planName, $expires]);
    }

    jsonOk(['transaction_id' => $txnId, 'message' => 'Payment recorded and quota updated.'], 201);
}
