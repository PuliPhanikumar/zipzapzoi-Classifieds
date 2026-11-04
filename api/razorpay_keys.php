<?php
require __DIR__ . '/config.php';
$keys = getRazorpayKeys();
// Only return public key - never expose secret
$pubKey = $keys['razorpay_key'] ?? $keys['key_id'] ?? '';
jsonOk([
    'key_id'       => $pubKey,
    'razorpay_key' => $pubKey
]);
