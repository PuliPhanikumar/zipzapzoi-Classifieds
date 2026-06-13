<?php
/**
 * ZipZapZoi Classifieds — Boost API
 *
 * POST /api/boost.php
 *   Body: { listing_id, boost_days, razorpay_payment_id, razorpay_order_id, razorpay_signature }
 *
 * Boost Pricing (server-enforced):
 *   1 day  → ₹29
 *   7 days → ₹149
 *  30 days → ₹399
 *
 * Server-enforced rules:
 *  1. User must own the listing
 *  2. Listing must be active and not expired
 *  3. Payment signature verified via HMAC-SHA256
 *  4. Payment amount verified via Razorpay API
 *  5. Replay protection: payment_id must be unique
 *  6. Extends boosted_until if already boosted (stacks correctly)
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

$user = requireAuth();
$b    = getBody();

$listingId  = (int)($b['listing_id']          ?? 0);
$boostDays  = (int)($b['boost_days']           ?? 0);
$paymentId  = clean($b['razorpay_payment_id'] ?? '');
$orderId    = clean($b['razorpay_order_id']   ?? '');
$signature  = clean($b['razorpay_signature']  ?? '');

if (!$listingId)  jsonError('listing_id is required.');
if (!in_array($boostDays, [1, 7, 30])) jsonError('Invalid boost duration. Choose 1, 7, or 30 days.');
if (!$paymentId || !$orderId || !$signature) jsonError('Payment details are incomplete.');

// Server-side price table (tamper-proof)
$pricingTable = [1 => 29, 7 => 149, 30 => 399];
$expectedAmount = $pricingTable[$boostDays];

$db = getDB();

// ── 1. Fetch the listing ──────────────────────────────────────────
$stmt = $db->prepare(
    'SELECT id, user_id, title, status, expires_at, boosted, boosted_until FROM listings WHERE id = ?'
);
$stmt->execute([$listingId]);
$listing = $stmt->fetch();
if (!$listing) jsonError('Listing not found.', 404);

// ── 2. Ownership check ────────────────────────────────────────────
if ((int)$listing['user_id'] !== (int)$user['id']) {
    jsonError('You can only boost your own listings.', 403);
}

// ── 3. Listing must be active and not expired ─────────────────────
if ($listing['status'] !== 'active') {
    jsonError("Only active listings can be boosted. Current status: {$listing['status']}.");
}
$expiresAt = $listing['expires_at'] ? strtotime($listing['expires_at']) : 0;
if ($expiresAt > 0 && $expiresAt < time()) {
    jsonError('This listing has expired. Renew it first before boosting.');
}

// ── 4. Verify Razorpay signature ──────────────────────────────────
if (!verifyRazorpaySignature($orderId, $paymentId, $signature)) {
    error_log("Boost sig mismatch: listing={$listingId} user={$user['id']} payment={$paymentId}");
    jsonError('Payment verification failed. Please contact support.', 422);
}

// ── 5. Replay protection ──────────────────────────────────────────
$dup = $db->prepare('SELECT id FROM transactions WHERE razorpay_payment_id = ?');
$dup->execute([$paymentId]);
if ($dup->fetch()) {
    error_log("Boost replay: listing={$listingId} payment={$paymentId}");
    jsonError('This payment has already been used.', 409);
}

// ── 6. Verify amount via Razorpay API ─────────────────────────────
$expectedPaise = $expectedAmount * 100;
$keys   = getRazorpayKeys();
$keyId  = $keys['razorpay_key']    ?? '';
$secret = $keys['razorpay_secret'] ?? '';

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
        error_log("Boost Razorpay API error: $rpErr");
        jsonError('Could not verify payment with Razorpay. Please try again.');
    }

    $rp = json_decode($rpBody, true);
    if (!$rp || ($rp['status'] ?? '') !== 'captured') {
        jsonError('Payment has not been captured yet. Complete payment first.');
    }
    if ((int)($rp['amount'] ?? 0) < $expectedPaise) {
        error_log("Boost underpayment: paid={$rp['amount']} expected={$expectedPaise} listing={$listingId}");
        jsonError("Incorrect boost payment. Expected ₹{$expectedAmount} for {$boostDays}-day boost.", 422);
    }
} else {
    error_log("ZZZ Boost: Razorpay keys not set. Proceeding without amount verify. Listing:{$listingId}");
}

// ── 7. All checks passed — apply boost ───────────────────────────
// If already boosted and still active, extend from current boosted_until
// Otherwise start fresh from NOW()
$baseTime = 'NOW()';
if ($listing['boosted'] && $listing['boosted_until'] && strtotime($listing['boosted_until']) > time()) {
    $baseTime = "'" . $listing['boosted_until'] . "'";
}
$newBoostUntil = date('Y-m-d H:i:s', strtotime("+{$boostDays} days", $listing['boosted_until'] && strtotime($listing['boosted_until']) > time() ? strtotime($listing['boosted_until']) : time()));

$db->prepare(
    "UPDATE listings
     SET boosted       = 1,
         boosted_until = ?,
         updated_at    = NOW()
     WHERE id = ?"
)->execute([$newBoostUntil, $listingId]);

// ── 8. Record the boost transaction ──────────────────────────────
$db->prepare(
    'INSERT INTO transactions
     (user_id, plan_id, plan_name, amount, razorpay_payment_id, razorpay_order_id, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
)->execute([
    (int)$user['id'],
    "boost_{$boostDays}d",
    "Ad Boost ({$boostDays} Day" . ($boostDays > 1 ? 's' : '') . ") — ₹{$expectedAmount}",
    $expectedAmount,
    $paymentId,
    $orderId,
    'success',
]);

jsonOk([
    'message'      => "⚡ Your listing is now Boosted for {$boostDays} day" . ($boostDays > 1 ? 's' : '') . "! It will appear at the top of search results.",
    'listing_id'   => $listingId,
    'boosted_until' => $newBoostUntil,
    'boost_days'   => $boostDays,
    'amount_paid'  => $expectedAmount,
]);
