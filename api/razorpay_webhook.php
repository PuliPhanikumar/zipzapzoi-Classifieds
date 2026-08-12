<?php
/**
 * ZipZapZoi — Razorpay Webhook Handler
 * POST /api/razorpay_webhook.php
 *
 * Server-to-server callback from Razorpay — fires even if the browser redirect fails.
 * This is the SAFETY NET that ensures payment is never lost.
 *
 * Setup in Razorpay Dashboard:
 *   Webhooks → Add → https://www.zipzapzoi.com/api/razorpay_webhook.php
 *   Events: payment.captured
 *   Secret: (set in system_settings as 'razorpay_webhook_secret')
 *
 * NO session/cookie auth — this is called server-to-server by Razorpay.
 */

// Only load DB helpers — NOT session auth (this is server-to-server)
require_once __DIR__ . '/config.php';

// ── 1. Read raw body (must be before any PHP processing) ──────────────────
$rawBody = file_get_contents('php://input');

// ── 2. Verify Razorpay Webhook Signature ──────────────────────────────────
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

$db = getDB();
$webhookSecretRow = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'razorpay_webhook_secret'")->fetchColumn();
$webhookSecret = $webhookSecretRow ?: '';

if ($webhookSecret) {
    $expected = hash_hmac('sha256', $rawBody, $webhookSecret);
    if (!hash_equals($expected, $signature)) {
        http_response_code(400);
        error_log('[ZZZ Webhook] SIGNATURE MISMATCH. Expected: ' . $expected . ' Got: ' . $signature);
        die(json_encode(['error' => 'Invalid signature']));
    }
}

// ── 3. Parse event ────────────────────────────────────────────────────────
$event = json_decode($rawBody, true);
if (!$event || !isset($event['event'])) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid payload']));
}

error_log('[ZZZ Webhook] Event received: ' . $event['event']);

// ── 4. Handle payment.captured ─────────────────────────────────────────────
if ($event['event'] === 'payment.captured') {
    $payment    = $event['payload']['payment']['entity'] ?? [];
    $paymentId  = $payment['id']        ?? '';
    $orderId    = $payment['order_id']  ?? '';
    $amountPaise = (int)($payment['amount'] ?? 0);
    $amount     = $amountPaise / 100;  // Convert from paise to INR
    $notes      = $payment['notes']    ?? [];

    // Extract metadata from order notes (set in create_order.php)
    $userId     = (int)($notes['user_id']    ?? 0);
    $action     = $notes['action']           ?? '';   // plan|boost|renewal
    $planId     = $notes['plan_id']          ?? '';
    $planName   = $notes['plan_name']        ?? 'Payment';
    $listingId  = (int)($notes['listing_id'] ?? 0);
    $days       = (int)($notes['days']       ?? 30);
    $ads        = (int)($notes['ads']        ?? 0);

    if (!$paymentId || !$userId) {
        error_log('[ZZZ Webhook] Missing paymentId or userId in notes. Payment: ' . json_encode($payment));
        http_response_code(200); // Tell Razorpay we got it (don't retry)
        die(json_encode(['status' => 'skipped_missing_data']));
    }

    // ── Idempotency: skip if already processed ────────────────────────
    $dup = $db->prepare('SELECT id FROM transactions WHERE razorpay_payment_id = ?');
    $dup->execute([$paymentId]);
    if ($dup->fetch()) {
        error_log('[ZZZ Webhook] Already processed payment: ' . $paymentId);
        http_response_code(200);
        die(json_encode(['status' => 'already_processed']));
    }

    // ── Record transaction ────────────────────────────────────────────
    $db->prepare(
        'INSERT INTO transactions (user_id, plan_id, plan_name, amount, razorpay_payment_id, razorpay_order_id, status)
         VALUES (?, ?, ?, ?, ?, ?, "success")'
    )->execute([$userId, $planId ?: $action, $planName, $amount, $paymentId, $orderId]);

    error_log("[ZZZ Webhook] Transaction recorded. User:$userId Action:$action Payment:$paymentId Amount:$amount");

    // ── Grant quota for plan purchases ───────────────────────────────
    if ($action === 'plan' && $ads > 0) {
        $expires = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        $db->prepare(
            'INSERT INTO user_quotas (user_id, ads_remaining, total_granted, plan_id, plan_name, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               ads_remaining = ads_remaining + VALUES(ads_remaining),
               total_granted = total_granted + VALUES(total_granted),
               plan_id    = VALUES(plan_id),
               plan_name  = VALUES(plan_name),
               expires_at = GREATEST(IFNULL(expires_at, "2000-01-01"), VALUES(expires_at))'
        )->execute([$userId, $ads, $ads, $planId, $planName, $expires]);
        error_log("[ZZZ Webhook] Quota granted. User:$userId Ads:$ads Expires:$expires");
    }

    // ── Activate boost for boost purchases ───────────────────────────
    if ($action === 'boost' && $listingId) {
        $expiry = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        $db->prepare(
            "INSERT INTO promoted_listings (listing_id, promoted_until, type, txn_id)
             VALUES (?, ?, 'featured', ?)
             ON DUPLICATE KEY UPDATE
               promoted_until = GREATEST(promoted_until, VALUES(promoted_until)),
               txn_id = VALUES(txn_id)"
        )->execute([$listingId, $expiry, $paymentId]);
        error_log("[ZZZ Webhook] Boost applied. Listing:$listingId Until:$expiry");
    }

    // ── Renew listing for renewal purchases ──────────────────────────
    if ($action === 'renewal' && $listingId) {
        $newExpiry = date('Y-m-d H:i:s', strtotime('+30 days'));
        $db->prepare("UPDATE listings SET expires_at = ?, status = 'active' WHERE id = ? AND user_id = ?")
           ->execute([$newExpiry, $listingId, $userId]);
        error_log("[ZZZ Webhook] Listing renewed. Listing:$listingId Until:$newExpiry");
    }

    http_response_code(200);
    echo json_encode(['status' => 'processed']);
    exit;
}

// ── 5. Acknowledge all other events (don't retry) ─────────────────────────
http_response_code(200);
echo json_encode(['status' => 'ignored', 'event' => $event['event']]);
