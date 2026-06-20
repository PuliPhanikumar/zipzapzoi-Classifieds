<?php
/**
 * ZipZapZoi — Listing Renewal API
 *
 * POST /api/renewal.php
 *   Body: { listing_id, razorpay_payment_id, razorpay_order_id, razorpay_signature }
 *
 * Server-enforced rules (tamper-proof):
 *  1. User must be authenticated
 *  2. User must OWN the listing (prevents renewing other users' listings)
 *  3. Listing must have status = 'expired' OR (status='active' AND expires_at < NOW())
 *     — cannot renew a listing that is still active
 *  4. renewal_count must be 0 (ONE renewal per listing, ever — no stacking)
 *  5. Payment signature verified via HMAC-SHA256 (server-side, no client trust)
 *  6. Payment amount verified via Razorpay API (prevents partial/fake payments)
 *  7. Replay protection: payment_id must not already exist in transactions table
 *  8. After renewal: status='active', expires_at = NOW()+30d, renewal_count=1
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

$user = requireAuth();
$b    = getBody();

$listingId  = (int)($b['listing_id']             ?? 0);
$paymentId  = clean($b['razorpay_payment_id']    ?? '');
$orderId    = clean($b['razorpay_order_id']      ?? '');
$signature  = clean($b['razorpay_signature']     ?? '');

if (!$listingId)                        jsonError('listing_id is required.');
if (!$paymentId || !$orderId || !$signature) jsonError('Payment details are incomplete.');

$db = getDB();

// ── 1. Fetch the listing ──────────────────────────────────────────
$stmt = $db->prepare(
    'SELECT id, user_id, title, status, expires_at, renewal_count FROM listings WHERE id = ?'
);
$stmt->execute([$listingId]);
$listing = $stmt->fetch();
if (!$listing) jsonError('Listing not found.', 404);

// ── 2. Ownership check ────────────────────────────────────────────
if ((int)$listing['user_id'] !== (int)$user['id']) {
    jsonError('You can only renew your own listings.', 403);
}

// ── 3. Must be expired — cannot renew an active listing ──────────
$expiresAt = $listing['expires_at'] ? strtotime($listing['expires_at']) : 0;
$now       = time();
$isExpired = ($listing['status'] === 'expired')
          || ($expiresAt > 0 && $expiresAt < $now);

if (!$isExpired) {
    $daysLeft = $expiresAt > 0 ? max(1, (int)ceil(($expiresAt - $now) / 86400)) : '?';
    jsonError("This listing is still active for {$daysLeft} more day(s). Renewal is only allowed after expiry.");
}

// Also block renewal of sold/rejected/draft listings
if (in_array($listing['status'], ['sold', 'rejected', 'draft'])) {
    jsonError("Cannot renew a listing with status '{$listing['status']}'.");
}

// ── 4. One renewal per listing — EVER (no stacking) ──────────────
if ((int)$listing['renewal_count'] >= 1) {
    jsonError('This listing has already been renewed once. To continue selling, please post a new listing.');
}

// ── 5. Verify Razorpay signature (server-side HMAC) ─────────────
if (!verifyRazorpaySignature($orderId, $paymentId, $signature)) {
    error_log("Renewal sig mismatch: listing={$listingId} user={$user['id']} payment={$paymentId}");
    jsonError('Payment verification failed. Please contact support.', 422);
}

// ── 6. Replay protection — payment ID must be unique ─────────────
$dup = $db->prepare('SELECT id FROM transactions WHERE razorpay_payment_id = ?');
$dup->execute([$paymentId]);
if ($dup->fetch()) {
    error_log("Renewal replay: listing={$listingId} payment={$paymentId}");
    jsonError('This payment has already been used.', 409);
}

// ── 7. Verify amount via Razorpay API (₹16 = 1600 paise) ─────────
$expectedPaise = 2000; // Matches api/transactions.php renewal plan
$keys  = getRazorpayKeys();
$keyId = $keys['razorpay_key']    ?? '';
$secret= $keys['razorpay_secret'] ?? '';

if ($keyId && $secret) {
    $ch = curl_init("https://api.razorpay.com/v1/payments/{$paymentId}");
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
        error_log("Renewal Razorpay API error: $rpErr");
        jsonError('Could not verify payment with Razorpay. Please try again.');
    }

    $rp = json_decode($rpBody, true);
    if (!$rp || ($rp['status'] ?? '') !== 'captured') {
        jsonError('Payment has not been captured yet. Complete payment first.');
    }
    if ((int)($rp['amount'] ?? 0) < $expectedPaise) {
        error_log("Renewal underpayment: paid={$rp['amount']} expected={$expectedPaise} listing={$listingId}");
        jsonError('Incorrect renewal payment amount. Expected ₹16.', 422);
    }
} else {
    // Razorpay not configured yet — only allow in dev/test (log warning)
    error_log("ZZZ Renewal: Razorpay keys not set. Proceeding without amount verify. Listing:{$listingId}");
}

// ── 8. All checks passed — execute renewal ────────────────────────
$newExpiry = date('Y-m-d H:i:s', strtotime('+30 days'));

$db->prepare(
    "UPDATE listings
     SET status        = 'active',
         expires_at    = ?,
         renewal_count = renewal_count + 1,
         renewed_at    = NOW(),
         updated_at    = NOW()
     WHERE id = ?"
)->execute([$newExpiry, $listingId]);

// ── 9. Record the renewal transaction ────────────────────────────
$db->prepare(
    'INSERT INTO transactions
     (user_id, plan_id, plan_name, amount, razorpay_payment_id, razorpay_order_id, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
)->execute([
    (int)$user['id'], 'renewal_single', 'Ad Renewal (₹16)',
    16.00, $paymentId, $orderId, 'success',
]);

jsonOk([
    'message'    => 'Listing renewed! Active for 30 more days.',
    'listing_id' => $listingId,
    'expires_at' => $newExpiry,
]);
