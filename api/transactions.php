<?php
/**
 * ZipZapZoi Classifieds — Transactions API
 * GET  /api/transactions.php → my transaction history
 * POST /api/transactions.php → record verified payment + assign quota
 *
 * SECURITY: All POST requests MUST include a valid Razorpay signature.
 * The server verifies the signature against the secret key before
 * granting any quota. Frontend cannot self-grant quota.
 */
require_once __DIR__ . '/config.php';

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET')       getTransactions($user);
elseif ($method === 'POST')  recordTransaction($user);
else jsonError('Method not allowed', 405);

// ── GET — my payment history ──────────────────────────────────────
function getTransactions(array $user): void {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT id, plan_id, plan_name, amount, currency,
                razorpay_payment_id, razorpay_order_id, status, created_at
         FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 50'
    );
    $stmt->execute([(int)$user['id']]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) { $r['amount'] = (float)$r['amount']; }
    jsonOk($rows);
}

// ── POST — record plan purchase + grant quota ─────────────────────
function recordTransaction(array $user): void {
    $b = getBody();

    $planId   = clean($b['plan_id']   ?? '');
    $planName = clean($b['plan_name'] ?? '');
    $amount   = (float)($b['amount'] ?? 0);
    $ads      = (int)($b['ads']  ?? 0);
    $days     = (int)($b['days'] ?? 30);

    // Payment identifiers (required for paid plans)
    $rzpPaymentId = clean($b['razorpay_payment_id'] ?? '');
    $rzpOrderId   = clean($b['razorpay_order_id']   ?? '');
    $rzpSignature = clean($b['razorpay_signature']  ?? '');

    if (!$planId) jsonError('plan_id is required.');
    if ($ads < 0 || $days < 0) jsonError('Invalid ads/days value.');

    $db = getDB();

    // ── Determine if this is a PAID transaction ───────────────────
    $isPaid = ($amount > 0);

    if ($isPaid) {
        // 1. Razorpay signature MUST be provided for paid plans
        if (!$rzpPaymentId || !$rzpOrderId || !$rzpSignature) {
            error_log("ZZZ Txn: Missing Razorpay fields for paid plan. User:{$user['id']} Plan:$planId");
            jsonError('Payment verification incomplete — signature fields are required for paid plans.', 422);
        }

        // 2. Verify signature (server-side, no client trust)
        if (!verifyRazorpaySignature($rzpOrderId, $rzpPaymentId, $rzpSignature)) {
            error_log("ZZZ Txn: Signature mismatch. User:{$user['id']} Payment:$rzpPaymentId");
            jsonError('Payment signature verification failed. Do not retry — contact support.', 422);
        }

        // 3. Prevent replay: check this payment_id hasn't been used before
        $dup = $db->prepare('SELECT id FROM transactions WHERE razorpay_payment_id = ?');
        $dup->execute([$rzpPaymentId]);
        if ($dup->fetch()) {
            error_log("ZZZ Txn: Duplicate payment_id. User:{$user['id']} Payment:$rzpPaymentId");
            jsonError('This payment has already been processed.', 409);
        }

        // 4. Verify actual amount via Razorpay API
        $keys = getRazorpayKeys();
        $keyId = $keys['razorpay_key'] ?? '';
        $secret= $keys['razorpay_secret'] ?? '';
        if ($keyId && $secret) {
            $ch = curl_init("https://api.razorpay.com/v1/payments/{$rzpPaymentId}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => "$keyId:$secret",
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 10,
            ]);
            $rpBody = curl_exec($ch);
            $rpErr  = curl_error($ch);
            curl_close($ch);

            if ($rpErr) {
                error_log("ZZZ Txn: Razorpay API error: $rpErr");
                jsonError('Could not verify payment with Razorpay. Try again in a moment.');
            }

            $rp = json_decode($rpBody, true);
            if (!$rp || ($rp['status'] ?? '') !== 'captured') {
                error_log("ZZZ Txn: Payment not captured. User:{$user['id']} Status:{$rp['status']}");
                jsonError('Payment has not been captured yet. Please complete payment first.');
            }

            // Amount check: Razorpay returns paise (₹1 = 100 paise)
            $paidPaise    = (int)($rp['amount'] ?? 0);
            $expectedPaise= (int)round($amount * 100);
            if ($paidPaise < $expectedPaise) {
                error_log("ZZZ Txn: Amount mismatch. Paid:{$paidPaise} Expected:{$expectedPaise} User:{$user['id']}");
                jsonError('Payment amount does not match the plan price. Contact support.', 422);
            }
        }
    } else {
        // Free transaction (e.g. free plan, admin grant) — no Razorpay needed
        // For safety: ensure ads granted doesn't exceed what's defined for free plans
        if ($ads > 10) {
            jsonError('Free plan cannot grant more than 10 ads. Contact admin.', 422);
        }
    }

    // ── Record the transaction ────────────────────────────────────
    $status = 'success';
    $db->prepare(
        'INSERT INTO transactions
         (user_id, plan_id, plan_name, amount, razorpay_payment_id, razorpay_order_id, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        (int)$user['id'], $planId, $planName, $amount,
        $rzpPaymentId ?: null, $rzpOrderId ?: null, $status
    ]);
    $txnId = (int)$db->lastInsertId();

    // ── Grant quota ───────────────────────────────────────────────
    if ($ads > 0) {
        $expires = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        $db->prepare(
            'INSERT INTO user_quotas (user_id, ads_remaining, total_granted, plan_id, plan_name, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               ads_remaining = ads_remaining + VALUES(ads_remaining),
               total_granted = total_granted + VALUES(total_granted),
               plan_id    = VALUES(plan_id),
               plan_name  = VALUES(plan_name),
               expires_at = VALUES(expires_at)'
        )->execute([(int)$user['id'], $ads, $ads, $planId, $planName, $expires]);
    }

    jsonOk([
        'transaction_id' => $txnId,
        'ads_granted'    => $ads,
        'message'        => "Payment verified. {$ads} ads added to your account.",
    ], 201);
}
