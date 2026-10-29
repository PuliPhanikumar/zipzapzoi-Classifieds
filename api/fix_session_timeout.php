<?php
/**
 * ZipZapZoi — Fix session timeout to 30 days
 * Run once: GET /api/fix_session_timeout.php
 */
require_once __DIR__ . '/config.php';

$db = getDB();

// 1. Fix session_timeout_mins to 30 days (43200 minutes)
$db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('session_timeout_mins', '43200')
              ON DUPLICATE KEY UPDATE setting_value = '43200'")->execute();

// 2. Extend all currently stored (but expired) sessions for both test users
// so they can use the app without re-logging-in
// This gives them new tokens valid for 30 days
$newExpiry = date('Y-m-d H:i:s', strtotime('+30 days'));

// Re-extend the LATEST session per user
$db->prepare("UPDATE sessions s
              INNER JOIN (
                  SELECT user_id, MAX(id) as max_id FROM sessions GROUP BY user_id
              ) latest ON latest.user_id = s.user_id AND latest.max_id = s.id
              SET s.expires_at = ?, s.last_activity = NOW()
              WHERE s.user_id IN (7, 14)")->execute([$newExpiry]);

// 3. Verify
$sessions = $db->query("SELECT s.user_id, u.name, LEFT(s.token,30) as token, s.expires_at, (s.expires_at > NOW()) as is_valid
                         FROM sessions s JOIN users u ON u.id=s.user_id
                         WHERE s.user_id IN (7,14) ORDER BY s.id DESC LIMIT 6")->fetchAll();

$setting = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='session_timeout_mins'")->fetchColumn();

jsonOk([
    'action'               => 'session_timeout_fixed',
    'new_timeout_mins'     => $setting,
    'sessions_after_fix'   => $sessions,
    'server_time'          => date('Y-m-d H:i:s'),
]);
