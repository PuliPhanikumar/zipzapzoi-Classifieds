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
elseif ($method === 'POST') saveSettings($admin);
else jsonError('Method not allowed', 405);

function getSettings(): void {
    $db   = getDB();
    $rows = $db->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll();
    $out  = [];
    foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
    jsonOk($out);
}

function saveSettings(array $admin): void {
    $db       = getDB();
    $b        = getBody();
    $settings = $b['settings'] ?? [];
    if (!is_array($settings) || empty($settings)) jsonError('settings object required.');

    $allowed = [
        'listing_expiry_days', 'max_upload_mb', 'allowed_formats', 'session_timeout_mins',
        'emailjs_service', 'emailjs_public', 'emailjs_otp_template', 'emailjs_reset_template',
        'razorpay_key', 'razorpay_secret', 'razorpay_currency', 'plan_config', 'featured_prices',
        'site_name', 'support_email', 'otp_expiry_mins',
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
