<?php
/**
 * ZipZapZoi — Admin Settings API
 * GET  /api/admin/settings.php            → all settings (optionally ?section=api)
 * POST /api/admin/settings.php            → bulk update {settings: {key:value,...}}
 */
require_once __DIR__ . '/../config.php';
$admin  = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET')  getSettings();
elseif ($method === 'POST') {
    $b = getBody();
    if (isset($b['action']) && $b['action'] === 'change_password') {
        changePassword($admin, $b);
    } else {
        saveSettings($admin);
    }
}
else jsonError('Method not allowed', 405);

function getSettings(): void {
    $db   = getDB();
    $rows = $db->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll();
    $out  = [];
    foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
    jsonOk($out);
}

function changePassword(array $admin, array $b): void {
    $db = getDB();
    $pwd = $b['password'] ?? '';
    if (strlen($pwd) < 8) jsonError('Password must be at least 8 characters.');
    
    $hash = password_hash($pwd, PASSWORD_DEFAULT);
    $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, (int)$admin['id']]);
    
    adminLog($admin, 'CHANGE_PASSWORD', 'Admin updated their password.');
    jsonOk(['message' => 'Password updated.']);
}

function saveSettings(array $admin): void {
    $db       = getDB();
    $b        = getBody();
    $settings = $b['settings'] ?? [];
    if (!is_array($settings) || empty($settings)) jsonError('settings object required.');

    $allowed = [
        'listing_expiry_days', 'max_upload_mb', 'allowed_formats', 'session_timeout_mins',
        'emailjs_service', 'emailjs_public', 'emailjs_otp_template', 'emailjs_reset_template',
        'razorpay_key', 'razorpay_secret', 'razorpay_currency', 'razorpay_env',
        'plan_config', 'featured_prices', 'max_images_per_listing',
        'site_name', 'site_tagline', 'support_email', 'otp_expiry_mins',
        'security_2fa', 'ip_allowlist', 'ai_blacklist', 'auto_approve_listings',
    ];

    $stmt = $db->prepare(
        'INSERT INTO system_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    $saved = [];
    foreach ($settings as $key => $val) {
        if (!in_array($key, $allowed)) continue;
        $stmt->execute([$key, (string)$val]);
        $saved[] = $key;
    }

    adminLog($admin, 'SAVE_SETTINGS', implode(', ', $saved));
    jsonOk(['saved' => $saved, 'message' => 'Settings saved.']);
}

function adminLog(array $admin, string $action, string $detail = ''): void {
    try {
        getDB()->prepare(
            'INSERT INTO admin_logs (admin_id, admin_name, action, detail) VALUES (?,?,?,?)'
        )->execute([(int)$admin['id'], $admin['name'], $action, $detail]);
    } catch (\Throwable $e) {}
}
