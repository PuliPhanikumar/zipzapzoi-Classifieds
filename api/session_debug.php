<?php
/**
 * ZipZapZoi Session Debug — check all sessions including expired ones
 */
require_once __DIR__ . '/config.php';

$db = getDB();

// All sessions (expired or not)
$allSessions = $db->query("SELECT s.user_id, u.name, LEFT(s.token,20) as token_preview, s.created_at, s.expires_at, s.last_activity, (s.expires_at > NOW()) as is_valid FROM sessions s JOIN users u ON u.id=s.user_id ORDER BY s.last_activity DESC LIMIT 20")->fetchAll();

// Session timeout setting
$timeout = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='session_timeout_mins'")->fetchColumn();

// Count of sessions
$counts = $db->query("SELECT COUNT(*) as total, SUM(expires_at > NOW()) as active FROM sessions")->fetch();

// Test if messages table has the 'deleted_for' column
$msgCols = $db->query("DESCRIBE messages")->fetchAll(PDO::FETCH_ASSOC);

// Check if there's a no_auth issue with getBody
$testBody = json_decode('{"to_user_id":7,"body":"test"}', true);

jsonOk([
    'all_sessions'           => $allSessions,
    'session_timeout_mins'   => $timeout,
    'session_counts'         => $counts,
    'messages_columns'       => array_column($msgCols, 'Field'),
    'current_server_time'    => date('Y-m-d H:i:s'),
]);
