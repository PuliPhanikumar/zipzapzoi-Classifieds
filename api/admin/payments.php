<?php
/**
 * ZipZapZoi — Admin Payments API
 * GET  /api/admin/payments.php            → transaction ledger
 * GET  /api/admin/payments.php?stats=1   → revenue stats for dashboard
 * GET  /api/admin/payments.php?sync=1    → sync live Razorpay payments
 * POST /api/admin/payments.php            → refund {id} or sync
 */
require_once __DIR__ . '/../config.php';
$admin  = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && ($_GET['stats'] ?? '') === '1') getPaymentStats();
elseif ($method === 'GET' && ($_GET['sync'] ?? '') === '1') forceSyncPayments();
elseif ($method === 'GET')  listPayments();
elseif ($method === 'POST') processPostAction($admin);
else jsonError('Method not allowed', 405);

function forceSyncPayments(): void {
    $synced = syncRazorpayPaymentsInternal();
    listPayments();
}

function getPaymentStats(): void {
    syncRazorpayPaymentsInternal();
    $db   = getDB();
    $rev  = $db->query("SELECT COALESCE(SUM(amount),0) AS total FROM transactions WHERE status='success'")->fetchColumn();
    $paid = $db->query("SELECT COUNT(*) FROM transactions WHERE status='success'")->fetchColumn();
    $free = $db->query("SELECT COUNT(*) FROM users u LEFT JOIN user_quotas q ON q.user_id=u.id WHERE COALESCE(q.plan_id,'free')='free' AND u.is_active=1")->fetchColumn();
    jsonOk(['total_revenue'=>(float)$rev,'paid_plans'=>(int)$paid,'free_users'=>(int)$free]);
}

function listPayments(): void {
    syncRazorpayPaymentsInternal();
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT t.id, t.plan_id, t.plan_name, t.amount, t.currency,
                t.razorpay_payment_id, t.razorpay_order_id, t.status, t.created_at,
                u.name AS user_name, u.email AS user_email
         FROM transactions t
         LEFT JOIN users u ON u.id = t.user_id
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

function processPostAction(array $admin): void {
    $b = getBody();
    $action = $b['action'] ?? '';
    if ($action === 'sync') {
        $count = syncRazorpayPaymentsInternal();
        jsonOk(['message' => "Synced {$count} live payments from Razorpay.", 'synced_count' => $count]);
        return;
    }
    processRefund($admin);
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

function syncRazorpayPaymentsInternal(): int {
    static $synced = false;
    if ($synced) return 0;
    $synced = true;

    $keys   = getRazorpayKeys();
    $keyId  = $keys['razorpay_key']    ?? '';
    $secret = $keys['razorpay_secret'] ?? '';

    if (!$keyId || !$secret) return 0;

    $ch = curl_init("https://api.razorpay.com/v1/payments?count=100");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "$keyId:$secret",
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || !$res) return 0;

    $data = json_decode($res, true);
    if (!isset($data['items']) || !is_array($data['items'])) return 0;

    $db = getDB();
    $count = 0;

    foreach ($data['items'] as $item) {
        $status = $item['status'] ?? '';
        if (!in_array($status, ['captured', 'authorized'])) continue;

        $payId   = $item['id'] ?? '';
        $orderId = $item['order_id'] ?? null;
        if (!$payId) continue;

        // Check if already in DB
        $chk = $db->prepare('SELECT id FROM transactions WHERE razorpay_payment_id = ?');
        $chk->execute([$payId]);
        if ($chk->fetch()) continue;

        // Extract amount & contact details
        $paise   = (int)($item['amount'] ?? 0);
        $amount  = round($paise / 100, 2);
        $email   = clean($item['email'] ?? '');
        $contact = clean($item['contact'] ?? '');
        $created = (int)($item['created_at'] ?? time());
        $createdAt = date('Y-m-d H:i:s', $created);

        // Find user by email or phone
        $userId = null;
        if ($email || $contact) {
            $uStmt = $db->prepare('SELECT id FROM users WHERE (email = ? AND email != "") OR (phone = ? AND phone != "") LIMIT 1');
            $uStmt->execute([$email, $contact]);
            $userId = $uStmt->fetchColumn() ?: null;
        }
        if (!$userId) {
            // Fallback to latest active user
            $userId = (int)$db->query('SELECT id FROM users WHERE is_active=1 ORDER BY id ASC LIMIT 1')->fetchColumn();
        }
        if (!$userId) continue;

        // Determine plan by amount
        $planId = 'extra_ad';
        $planName = 'Extra Ad';
        $ads = 1;
        $days = 30;

        if ($amount >= 140 && $amount <= 200) {
            $planId = 'starter'; $planName = 'Starter Pack'; $ads = 10; $days = 30;
        } elseif ($amount >= 250 && $amount <= 400) {
            $planId = 'growth'; $planName = 'Growth Pack'; $ads = 25; $days = 45;
        } elseif ($amount >= 500 && $amount <= 750) {
            $planId = 'business'; $planName = 'Business Pack'; $ads = 50; $days = 60;
        } elseif ($amount >= 800) {
            $planId = 'pro'; $planName = 'Pro Pack'; $ads = 100; $days = 90;
        } elseif (abs($amount - 16) < 2) {
            $planId = 'renewal'; $planName = 'Renewal'; $ads = 1; $days = 30;
        }

        // Insert Transaction
        $db->prepare(
            'INSERT INTO transactions
             (user_id, plan_id, plan_name, amount, razorpay_payment_id, razorpay_order_id, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, "success", ?)'
        )->execute([(int)$userId, $planId, $planName, $amount, $payId, $orderId, $createdAt]);

        // Grant Quota
        if ($ads > 0) {
            $expires = date('Y-m-d H:i:s', strtotime("+{$days} days", $created));
            $db->prepare(
                'INSERT INTO user_quotas (user_id, ads_remaining, total_granted, plan_id, plan_name, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   ads_remaining = ads_remaining + VALUES(ads_remaining),
                   total_granted = total_granted + VALUES(total_granted),
                   plan_id    = VALUES(plan_id),
                   plan_name  = VALUES(plan_name),
                   expires_at = GREATEST(IFNULL(expires_at, "2000-01-01"), VALUES(expires_at))'
            )->execute([(int)$userId, $ads, $ads, $planId, $planName, $expires]);
        }

        $count++;
    }

    return $count;
}

function adminLog(array $admin, string $action, string $detail = ''): void {
    try {
        getDB()->prepare(
            'INSERT INTO admin_logs (admin_id, admin_name, action, detail) VALUES (?,?,?,?)'
        )->execute([(int)$admin['id'], $admin['name'], $action, $detail]);
    } catch (\Throwable $e) {}
}

