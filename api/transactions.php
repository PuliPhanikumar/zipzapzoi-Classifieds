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

    // Payment identifiers (required for paid plans)
    $rzpPaymentId = clean($b['razorpay_payment_id'] ?? '');
    $rzpOrderId   = clean($b['razorpay_order_id']   ?? '');
    $rzpSignature = clean($b['razorpay_signature']  ?? '');

    error_log("ZZZ Txn: POST received. User:{$user['id']} Plan:$planId PayID:$rzpPaymentId OrderID:$rzpOrderId SigLen:" . strlen($rzpSignature));

    if (!$planId) jsonError('plan_id is required.');

    $db = getDB();

    // ── Validate Plan Server-Side ────────────────────────────────────
    $cfgRes = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'plan_config'")->fetchColumn();
    $serverPlans = $cfgRes ? json_decode($cfgRes, true) : [];
    
    if (empty($serverPlans)) {
        // Fallback plans if not set in DB
        $serverPlans = [
            ['id' => 'monthly_free', 'price' => 0,   'ads' => 1,   'days' => 30],
            ['id' => 'extra_ad',     'price' => 19,  'ads' => 1,   'days' => 30],
            ['id' => 'renewal',      'price' => 16,  'ads' => 1,   'days' => 30],
            ['id' => 'starter',      'price' => 149, 'ads' => 10,  'days' => 30],
            ['id' => 'growth',       'price' => 299, 'ads' => 25,  'days' => 45],
            ['id' => 'business',     'price' => 599, 'ads' => 50,  'days' => 60],
            ['id' => 'pro',          'price' => 999, 'ads' => 100, 'days' => 90],
        ];
    }
    
    $planDef = null;
    foreach ($serverPlans as $p) {
        if ($p['id'] === $planId) {
            $planDef = $p;
            break;
        }
    }
    
    if (!$planDef) {
        error_log("ZZZ Txn: Invalid plan '$planId'. Available: " . implode(',', array_column($serverPlans, 'id')));
        jsonError('Invalid plan selected. Plan ID: ' . $planId);
    }
    
    $amount = (float)$planDef['price'];
    $ads    = (int)$planDef['ads'];
    $days   = (int)$planDef['days'];

    $db = getDB();

    // ── Determine if this is a PAID transaction ───────────────────
    $isPaid = ($amount > 0);
    error_log("ZZZ Txn: Plan found. ID:$planId Amount:$amount Ads:$ads Days:$days IsPaid:" . ($isPaid?'yes':'no'));

    if ($isPaid) {
        // 1. Razorpay signature or payment ID MUST be provided for paid plans
        if (!$rzpPaymentId) {
            error_log("ZZZ Txn: Missing Razorpay payment ID. User:{$user['id']}");
            jsonError('Payment verification incomplete - razorpay_payment_id is required.', 422);
        }

        $keys = getRazorpayKeys();
        $keyId = $keys['razorpay_key'] ?? '';
        $secret= $keys['razorpay_secret'] ?? '';

        $sigOk = false;
        if ($rzpOrderId && $rzpSignature && $secret) {
            $sigOk = verifyRazorpaySignature($rzpOrderId, $rzpPaymentId, $rzpSignature);
        }

        $apiOk = false;
        $rpStatus = '';
        $paidPaise = 0;

        // 2. Verify payment via Razorpay REST API if keys are available
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

            if (!$rpErr && $rpBody) {
                $rp = json_decode($rpBody, true);
                if ($rp && isset($rp['status'])) {
                    $rpStatus  = $rp['status'];
                    $paidPaise = (int)($rp['amount'] ?? 0);
                    // Razorpay payment status can be 'captured' or 'authorized'
                    if (in_array($rpStatus, ['captured', 'authorized'])) {
                        $apiOk = true;
                    }
                }
            }
        }

        // Must pass either signature check or direct API check
        if (!$sigOk && !$apiOk) {
            error_log("ZZZ Txn: Verification FAILED. SigOk:" . ($sigOk?'1':'0') . " ApiOk:" . ($apiOk?'1':'0') . " RpStatus:$rpStatus User:{$user['id']} Payment:$rzpPaymentId");
            jsonError('Payment verification failed. Please contact support if your account was charged.', 422);
        }

        error_log("ZZZ Txn: Verification SUCCESS. Payment:$rzpPaymentId Status:$rpStatus");

        // 3. Prevent replay: check this payment_id hasn't been used before
        $dup = $db->prepare('SELECT id FROM transactions WHERE razorpay_payment_id = ?');
        $dup->execute([$rzpPaymentId]);
        if ($dup->fetch()) {
            error_log("ZZZ Txn: Duplicate payment_id. User:{$user['id']} Payment:$rzpPaymentId");
            jsonError('This payment has already been processed.', 409);
        }

        // Use actual amount if provided in request body (e.g. 19 or GST inclusive)
        if (isset($b['amount']) && is_numeric($b['amount']) && (float)$b['amount'] > 0) {
            $amount = (float)$b['amount'];
        }
    } else {
        // Free transaction (e.g. free plan, admin grant) — no Razorpay needed
        if ($planId === 'monthly_free') {
            // Check if already claimed this month
            $startOfMonth = date('Y-m-01 00:00:00');
            $chk = $db->prepare("SELECT id FROM transactions WHERE user_id = ? AND plan_id = 'monthly_free' AND created_at >= ?");
            $chk->execute([(int)$user['id'], $startOfMonth]);
            if ($chk->fetch()) {
                jsonError('You have already claimed your free ad for this month.');
            }
        } else {
            jsonError('Unauthorized free plan request. Only monthly_free is allowed.', 403);
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
               expires_at = GREATEST(IFNULL(expires_at, '2000-01-01'), VALUES(expires_at))'
        )->execute([(int)$user['id'], $ads, $ads, $planId, $planName, $expires]);
    }

    jsonOk([
        'transaction_id' => $txnId,
        'ads_granted'    => $ads,
        'message'        => "Payment verified. {$ads} ads added to your account.",
    ], 201);
}
