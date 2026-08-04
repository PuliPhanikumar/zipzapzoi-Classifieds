<?php
/**
 * ZipZapZoi - Razorpay Callback Handler
 * POST /api/razorpay_callback.php
 * 
 * Handles redirects from Razorpay (iframe overlay or mobile redirect).
 * Uses window.top.location.href to break out of any iframe restrictions.
 */
require_once __DIR__ . '/config.php';

function jsRedirect($url) {
    echo "<!DOCTYPE html><html><head><title>Redirecting...</title></head><body style='background:#fff;display:flex;justify-content:center;align-items:center;height:100vh;font-family:sans-serif;'>";
    echo "<h2>Processing Payment...</h2>";
    echo "<script>window.top.location.href = '" . addslashes($url) . "';</script>";
    echo "</body></html>";
    exit;
}

$user = requireAuth();

// Razorpay sends via POST if redirect:false, or GET if redirect:true
$payment_id = $_POST['razorpay_payment_id'] ?? $_GET['razorpay_payment_id'] ?? '';
$order_id   = $_POST['razorpay_order_id']   ?? $_GET['razorpay_order_id'] ?? '';
$signature  = $_POST['razorpay_signature']  ?? $_GET['razorpay_signature'] ?? '';

$action = $_GET['action'] ?? '';
$error  = $_POST['error'] ?? $_GET['error'] ?? '';

// If payment failed or user cancelled, redirect back to the correct page
if ($error || !$payment_id) {
    if ($action === 'plan') {
        jsRedirect("/Payment.html?error=payment_failed");
    } else {
        jsRedirect("/Seller Dashboard.html?error=payment_failed");
    }
}

if ($action === 'plan') {
    $plan_id   = $_GET['plan_id'] ?? '';
    $plan_name = $_GET['plan_name'] ?? 'Plan';
    $amount    = (int)($_GET['amount'] ?? 0);
    $ads       = (int)($_GET['ads'] ?? 0);
    $days      = (int)($_GET['days'] ?? 0);
    
    $keys = getRazorpayKeys();
    $secret = $keys['razorpay_secret'] ?? '';
    
    $expected = hash_hmac('sha256', $order_id . '|' . $payment_id, $secret);
    if (!hash_equals($expected, $signature)) {
        jsRedirect("/Payment.html?error=signature_mismatch");
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
    jsRedirect("/Payment.html?success=1&txn=" . urlencode($payment_id) . "&plan=" . urlencode($plan_id) . "&amount=" . urlencode($amount));

} elseif ($action === 'boost') {
    $listing_id = (int)($_GET['listing_id'] ?? 0);
    $days       = (int)($_GET['days'] ?? 0);
    $amount     = (int)($_GET['amount'] ?? 0);
    
    $keys = getRazorpayKeys();
    $secret = $keys['razorpay_secret'] ?? '';
    $expected = hash_hmac('sha256', $order_id . '|' . $payment_id, $secret);
    if (!hash_equals($expected, $signature)) {
        jsRedirect("/Seller Dashboard.html?error=signature_mismatch");
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
    
    jsRedirect("/Seller Dashboard.html?boost_success=1");

} elseif ($action === 'renewal') {
    $listing_id = (int)($_GET['listing_id'] ?? 0);
    $amount     = (int)($_GET['amount'] ?? 0);
    
    $keys = getRazorpayKeys();
    $secret = $keys['razorpay_secret'] ?? '';
    $expected = hash_hmac('sha256', $order_id . '|' . $payment_id, $secret);
    if (!hash_equals($expected, $signature)) {
        jsRedirect("/Seller Dashboard.html?error=signature_mismatch");
    }
    
    $db = getDB();
    $newExpiry = date('Y-m-d H:i:s', strtotime("+30 days"));
    $db->prepare("UPDATE listings SET expires_at = ?, status = 'active' WHERE id = ? AND user_id = ?")
       ->execute([$newExpiry, $listing_id, $user['id']]);
       
    $db->prepare(
        "INSERT INTO user_transactions (user_id, plan_id, plan_name, amount, type, txn_id)
         VALUES (?, 'renewal', 'Ad Renewal', ?, 'renewal', ?)"
    )->execute([$user['id'], $amount, $payment_id]);
    
    jsRedirect("/Seller Dashboard.html?renew_success=1");
}

jsRedirect("/Seller Dashboard.html");

