<?php
require __DIR__ . '/config.php';
$keys = getRazorpayKeys();
// Only return public key - never expose secret
jsonOk(['key_id' => $keys['key_id'] ?? '']);
