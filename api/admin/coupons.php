<?php
/**
 * ZipZapZoi — Admin Coupons API
 * GET    /api/admin/coupons.php         → list coupons
 * POST   /api/admin/coupons.php         → create {code, discount_pct}
 * DELETE /api/admin/coupons.php?code=X  → delete coupon
 * PUT    /api/admin/coupons.php         → toggle active {code}
 */
require_once __DIR__ . '/../config.php';
$admin  = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET')    listCoupons();
elseif ($method === 'POST')   createCoupon($admin);
elseif ($method === 'DELETE') deleteCoupon($admin);
elseif ($method === 'PUT')    toggleCoupon($admin);
else jsonError('Method not allowed', 405);

function listCoupons(): void {
    $stmt = getDB()->query(
        'SELECT id, code, discount_pct, is_active, expires_at, created_at
         FROM coupons ORDER BY created_at DESC'
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id']           = (int)$r['id'];
        $r['discount_pct'] = (int)$r['discount_pct'];
        $r['is_active']    = (bool)$r['is_active'];
    }
    jsonOk($rows);
}

function createCoupon(array $admin): void {
    $db          = getDB();
    $b           = getBody();
    $code        = strtoupper(trim($b['code'] ?? ''));
    $discount    = (int)($b['discount_pct'] ?? ($b['discount'] ?? 0));
    $expiresAt   = $b['expires_at'] ?? null;

    if (!$code)                     jsonError('Coupon code required.');
    if ($discount < 1 || $discount > 100) jsonError('Discount must be 1–100%.');

    try {
        $db->prepare(
            'INSERT INTO coupons (code, discount_pct, expires_at) VALUES (?,?,?)'
        )->execute([$code, $discount, $expiresAt ?: null]);
        adminLog($admin, 'CREATE_COUPON', "$code — {$discount}%");
        jsonOk(['id' => (int)$db->lastInsertId(), 'code' => $code], 201);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') jsonError('Coupon code already exists.');
        jsonError('Database error.');
    }
}

function deleteCoupon(array $admin): void {
    $db   = getDB();
    $code = strtoupper(trim($_GET['code'] ?? ''));
    if (!$code) jsonError('code required.');
    $db->prepare('DELETE FROM coupons WHERE code=?')->execute([$code]);
    adminLog($admin, 'DELETE_COUPON', $code);
    jsonOk(['message' => "Coupon $code deleted."]);
}

function toggleCoupon(array $admin): void {
    $db   = getDB();
    $b    = getBody();
    $code = strtoupper(trim($b['code'] ?? ''));
    if (!$code) jsonError('code required.');
    $db->prepare('UPDATE coupons SET is_active = NOT is_active WHERE code=?')->execute([$code]);
    adminLog($admin, 'TOGGLE_COUPON', $code);
    jsonOk(['message' => "Coupon $code toggled."]);
}

function adminLog(array $admin, string $action, string $detail = ''): void {
    try {
        getDB()->prepare(
            'INSERT INTO admin_logs (admin_id, admin_name, action, detail) VALUES (?,?,?,?)'
        )->execute([(int)$admin['id'], $admin['name'], $action, $detail]);
    } catch (\Throwable $e) {}
}
