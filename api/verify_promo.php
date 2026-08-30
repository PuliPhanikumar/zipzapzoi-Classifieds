<?php
/**
 * ZipZapZoi Classifieds - Verify Promo Code API
 * GET /api/verify_promo.php?code=XYZ
 */
require_once __DIR__ . '/config.php';

$code = clean($_GET['code'] ?? '');
if (!$code) jsonError('Promo code required.');

$db = getDB();
$promoConfig = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'promo_codes'")->fetchColumn();
$promoCodes  = $promoConfig ? json_decode($promoConfig, true) : [];

if (!is_array($promoCodes) || empty($promoCodes)) {
    // If admin hasn't set up any promos, default to allowing 100% bypass 
    // ONLY if the promo code matches ZOI100 as a hardcoded emergency fallback.
    // Otherwise, reject.
    if (strtoupper($code) === 'ZOI100') {
        jsonOk(['valid' => true, 'discount' => 100]);
        exit;
    }
    jsonError('Invalid coupon code.');
}

$validCoupon = null;
foreach ($promoCodes as $pc) {
    $c = is_array($pc) ? ($pc['code'] ?? '') : $pc;
    if (strtoupper(trim($c)) === strtoupper($code)) {
        $validCoupon = is_array($pc) ? $pc : ['code' => $c, 'discount' => 100];
        break;
    }
}

if ($validCoupon) {
    jsonOk(['valid' => true, 'discount' => (float)($validCoupon['discount'] ?? 100)]);
} else {
    jsonError('Invalid coupon code.');
}
