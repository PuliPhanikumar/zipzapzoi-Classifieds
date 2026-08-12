<?php
/**
 * ZipZapZoi — Create Razorpay Order
 * POST /api/create_order.php
 *
 * Body: {
 *   amount      : int (in paise, e.g. 14900 for ₹149),
 *   currency    : string (default INR),
 *   receipt     : string (optional),
 *   action      : string (plan|boost|renewal),
 *   plan_id     : string (optional),
 *   plan_name   : string (optional),
 *   listing_id  : int (optional, for boost/renewal),
 *   ads         : int (optional, ads granted for plan),
 *   days        : int (optional, validity days)
 * }
 *
 * Returns: { order_id, razorpay_key }
 * The notes are embedded so the webhook can process payment without browser session.
 */
require_once __DIR__ . '/config.php';

$user = requireAuth();
$b = getBody();

$amount   = (int)($b['amount'] ?? 0);
$currency = clean($b['currency'] ?? 'INR');
$receipt  = clean($b['receipt'] ?? 'zzz_' . time() . '_' . $user['id']);

// Metadata — embedded in Razorpay order notes for webhook processing
$action    = clean($b['action']    ?? '');
$planId    = clean($b['plan_id']   ?? '');
$planName  = clean($b['plan_name'] ?? 'Payment');
$listingId = (int)($b['listing_id'] ?? 0);
$ads       = (int)($b['ads']        ?? 0);
$days      = (int)($b['days']       ?? 30);

if ($amount <= 0) jsonError('Invalid amount');

$keys   = getRazorpayKeys();
$key    = $keys['razorpay_key'] ?? '';
$secret = $keys['razorpay_secret'] ?? '';

if (!$key || !$secret) {
    jsonError('Razorpay is not configured on the server.', 500);
}

$payload = [
    'amount'   => $amount,
    'currency' => $currency,
    'receipt'  => $receipt,
    // Notes embedded so webhook can process without browser session
    'notes'    => [
        'user_id'    => (string)$user['id'],
        'action'     => $action,
        'plan_id'    => $planId,
        'plan_name'  => $planName,
        'listing_id' => (string)$listingId,
        'ads'        => (string)$ads,
        'days'       => (string)$days,
    ]
];

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt($ch, CURLOPT_USERPWD, $key . ':' . $secret);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    jsonError('cURL Error: ' . $err, 500);
}

$data = json_decode($res, true);
if ($httpCode !== 200 || !isset($data['id'])) {
    jsonError('Failed to create Razorpay order: ' . ($data['error']['description'] ?? 'Unknown error'), 500);
}

jsonOk(['order_id' => $data['id'], 'razorpay_key' => $key]);
