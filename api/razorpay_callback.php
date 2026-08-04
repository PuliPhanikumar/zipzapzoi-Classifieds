<?php
/**
 * ZipZapZoi - Razorpay Callback Handler
 * POST /api/razorpay_callback.php
 * 
 * Handles POST redirects from Razorpay (especially on Mobile devices
 * where iframe fallback causes a POST to the callback_url).
 */
require_once __DIR__ . '/config.php';

$user = requireAuth();

$payment_id = $_POST['razorpay_payment_id'] ?? '';
$order_id   = $_POST['razorpay_order_id']   ?? '';
$signature  = $_POST['razorpay_signature']  ?? '';

$action = $_GET['action'] ?? '';

// If payment failed or user cancelled, redirect back to dashboard
if (isset($_POST['error']) || !$payment_id) {
    header("Location: /Seller Dashboard.html?error=payment_failed");
    exit;
}

if ($action === 'plan') {
    $plan_id   = $_GET['plan_id'] ?? '';
    $plan_name = $_GET['plan_name'] ?? 'Plan';
    $amount    = (int)($_GET['amount'] ?? 0);
    $ads       = (int)($_GET['ads'] ?? 0);
    $days      = (int)($_GET['days'] ?? 0);
    
    // Simulate the JSON body that /api/transactions.php expects
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [];
    $input_json = json_encode([
        'plan_id' => $plan_id,
        'plan_name' => $plan_name,
        'amount' => $amount,
        'ads' => $ads,
        'days' => $days,
        'razorpay_payment_id' => $payment_id,
        'razorpay_order_id' => $order_id,
        'razorpay_signature' => $signature
    ]);
    
    // Mock getBody() by overriding the function? No, we can't redefine functions.
    // Instead, let's just do the verification right here!
    
    $keys = getRazorpayKeys();
    $secret = $keys['razorpay_secret'] ?? '';
    
    $expected = hash_hmac('sha256', $order_id . '|' . $payment_id, $secret);
    if (!hash_equals($expected, $signature)) {
        header("Location: /Payment.html?error=signature_mismatch");
        exit;
    }
    
    $db = getDB();
    // Grant Quota
    $db->prepare("UPDATE users SET quota = quota + ?, is_verified = 1 WHERE id = ?")
       ->execute([$ads, $user['id']]);
       
    // Insert Transaction
    $db->prepare(
        "INSERT INTO user_transactions (user_id, plan_id, plan_name, amount, type, txn_id)
         VALUES (?, ?, ?, ?, 'plan', ?)"
    )->execute([$user['id'], $plan_id, $plan_name, $amount, $payment_id]);
    
    // Redirect to Success Receipt
    header("Location: /Payment.html?success=1&txn=" . urlencode($payment_id) . "&plan=" . urlencode($plan_id) . "&amount=" . urlencode($amount));
    exit;

} elseif ($action === 'boost') {
    $listing_id = (int)($_GET['listing_id'] ?? 0);
    $days       = (int)($_GET['days'] ?? 0);
    $amount     = (int)($_GET['amount'] ?? 0);
    
    $keys = getRazorpayKeys();
    $secret = $keys['razorpay_secret'] ?? '';
    $expected = hash_hmac('sha256', $order_id . '|' . $payment_id, $secret);
    if (!hash_equals($expected, $signature)) {
        header("Location: /Seller Dashboard.html?error=signature_mismatch");
        exit;
    }
    
    $db = getDB();
    $expiry = date('Y-m-d H:i:s', strtotime("+$days days"));
    $db->prepare(
        "INSERT INTO promoted_listings (listing_id, promoted_until, type, txn_id)
         VALUES (?, ?, 'featured', ?)
         ON DUPLICATE KEY UPDATE promoted_until = GREATEST(promoted_until, VALUES(promoted_until))"
    )->execute([$listing_id, $expiry, $payment_id]);
    
    $db->prepare(
        "INSERT INTO user_transactions (user_id, plan_id, plan_name, amount, type, txn_id)
         VALUES (?, 'boost', 'Ad Boost', ?, 'boost', ?)"
    )->execute([$user['id'], $amount, $payment_id]);
    
    header("Location: /Seller Dashboard.html?boost_success=1");
    exit;

} elseif ($action === 'renewal') {
    $listing_id = (int)($_GET['listing_id'] ?? 0);
    $amount     = (int)($_GET['amount'] ?? 0);
    
    $keys = getRazorpayKeys();
    $secret = $keys['razorpay_secret'] ?? '';
    $expected = hash_hmac('sha256', $order_id . '|' . $payment_id, $secret);
    if (!hash_equals($expected, $signature)) {
        header("Location: /Seller Dashboard.html?error=signature_mismatch");
        exit;
    }
    
    $db = getDB();
    $newExpiry = date('Y-m-d H:i:s', strtotime("+30 days"));
    $db->prepare("UPDATE listings SET expires_at = ?, status = 'active' WHERE id = ? AND user_id = ?")
       ->execute([$newExpiry, $listing_id, $user['id']]);
       
    $db->prepare(
        "INSERT INTO user_transactions (user_id, plan_id, plan_name, amount, type, txn_id)
         VALUES (?, 'renewal', 'Ad Renewal', ?, 'renewal', ?)"
    )->execute([$user['id'], $amount, $payment_id]);
    
    header("Location: /Seller Dashboard.html?renew_success=1");
    exit;
}

header("Location: /Seller Dashboard.html");
exit;
