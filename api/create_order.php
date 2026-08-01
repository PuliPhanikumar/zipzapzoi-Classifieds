<?php
/**
 * ZipZapZoi — Create Razorpay Order
 * POST /api/create_order.php
 */
require_once __DIR__ . '/config.php';

$user = requireAuth();
$b = getBody();

$amount   = (int)($b['amount'] ?? 0);
$currency = clean($b['currency'] ?? 'INR');
$receipt  = clean($b['receipt'] ?? 'receipt_' . time() . '_' . $user['id']);

if ($amount <= 0) jsonError('Invalid amount');

$keys   = getRazorpayKeys();
$key    = $keys['razorpay_key'] ?? '';
$secret = $keys['razorpay_secret'] ?? '';

if (!$key || !$secret) {
    jsonError('Razorpay is not configured on the server.', 500);
}

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt($ch, CURLOPT_USERPWD, $key . ':' . $secret);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'amount'   => $amount,
    'currency' => $currency,
    'receipt'  => $receipt
]));

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

jsonOk(['order_id' => $data['id']]);
