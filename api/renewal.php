<?php
/**
 * ZipZapZoi — Listing Renewal API
 *
 * POST /api/renewal.php
 *   Body: { listing_id, razorpay_payment_id, razorpay_order_id, razorpay_signature }
 *
 * Rules (tight, server-enforced):
 *  1. User must be authenticated
 *  2. User must OWN the listing
 *  3. Listing must be EXPIRED (expires_at < NOW())  — cannot renew active listings
 *  4. Listing renewal_count must be 0  — ONE renewal per listing, ever
 *  5. Payment must be verified (Razorpay signature check)
 *  6. After renewal: expires_at = NOW() + 30 days, renewal_count = 1, status = active
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$user = requireAuth();
$b    = getBody();

$listingId  = isset($b['listing_id'])           ? (int)$b['listing_id']           : 0;
$paymentId  = clean($b['razorpay_payment_id']   ?? '');
$orderId    = clean($b['razorpay_order_id']     ?? '');
$signature  = clean($b['razorpay_signature']    ?? '');

if (!$listingId) jsonError('listing_id is required.');
if (!$paymentId || !$orderId || !$signature) jsonError('Payment details are incomplete.');

$db = getDB();

// ── 1. Fetch the listing ──────────────────────────────────────────────
$stmt = $db->prepare('SELECT id, user_id, title, status, expires_at, renewal_count FROM listings WHERE id = ?');
$stmt->execute([$listingId]);
$listing = $stmt->fetch();

if (!$listing) jsonError('Listing not found.', 404);

// ── 2. Ownership check ───────────────────────────────────────────────
if ((int)$listing['user_id'] !== (int)$user['id']) {
    jsonError('You can only renew your own listings.', 403);
}

// ── 3. Must be expired (NOT active) ─────────────────────────────────
$expiresAt = strtotime($listing['expires_at']);
$now       = time();

if ($listing['status'] === 'active' && $expiresAt > $now) {
    $daysLeft = ceil(($expiresAt - $now) / 86400);
    jsonError("This listing is still active for {$daysLeft} more day(s). Renewal is only allowed after expiry.");
}

// ── 4. One renewal per listing — EVER ───────────────────────────────
if ((int)$listing['renewal_count'] >= 1) {
    jsonError('This listing has already been renewed once. To post again, please create a new listing.');
}

// ── 5. Verify Razorpay payment signature ────────────────────────────
$expectedSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, RAZORPAY_KEY_SECRET);
if (!hash_equals($expectedSignature, $signature)) {
    // Log the failed attempt
    error_log("Renewal signature mismatch: listing={$listingId} user={$user['id']} payment={$paymentId}");
    jsonError('Payment verification failed. Please contact support.');
}

// ── 6. Verify amount paid (fetch from Razorpay API) ──────────────────
// Expected: ₹16 = 1600 paise
$expectedAmount = 1600;
$ch = curl_init("https://api.razorpay.com/v1/payments/{$paymentId}");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 10,
]);
$rpResponse = curl_exec($ch);
$rpErr      = curl_error($ch);
curl_close($ch);

if ($rpErr) {
    error_log("Razorpay API error: {$rpErr}");
    jsonError('Could not verify payment amount. Please try again.');
}

$rpData = json_decode($rpResponse, true);
if (!$rpData || $rpData['status'] !== 'captured') {
    jsonError('Payment not captured. Please complete payment first.');
}
if ((int)$rpData['amount'] < $expectedAmount) {
    error_log("Renewal underpayment: paid={$rpData['amount']} expected={$expectedAmount} listing={$listingId}");
    jsonError('Incorrect payment amount for renewal.');
}

// ── 7. All checks passed — execute renewal ───────────────────────────
$newExpiry = date('Y-m-d H:i:s', strtotime('+30 days'));

$db->prepare(
    'UPDATE listings
     SET status        = "active",
         expires_at    = ?,
         renewal_count = renewal_count + 1,
         renewed_at    = NOW(),
         updated_at    = NOW()
     WHERE id = ?'
)->execute([$newExpiry, $listingId]);

// ── 8. Log the renewal transaction ──────────────────────────────────
$db->prepare(
    'INSERT INTO transactions (user_id, plan_id, plan_name, amount, razorpay_payment_id, razorpay_order_id, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
)->execute([
    (int)$user['id'],
    'renewal_single',
    'Ad Renewal (₹16)',
    16.00,
    $paymentId,
    $orderId,
    'success',
]);

jsonOk([
    'message'    => 'Listing renewed successfully! Active for 30 more days.',
    'listing_id' => $listingId,
    'expires_at' => $newExpiry,
]);
