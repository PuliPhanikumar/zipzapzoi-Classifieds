<?php
/**
 * ZipZapZoi Chat Debug — temporary diagnostic endpoint
 * DELETE after diagnosis: GET /api/chat_debug.php
 */
require_once __DIR__ . '/config.php';

$db = getDB();

// 1. Check messages table structure
$cols = $db->query("DESCRIBE messages")->fetchAll();

// 2. Get last 10 messages
$msgs = $db->query("SELECT m.*, u1.name as from_name, u2.name as to_name FROM messages m 
                    JOIN users u1 ON u1.id = m.from_user_id 
                    JOIN users u2 ON u2.id = m.to_user_id 
                    ORDER BY m.id DESC LIMIT 10")->fetchAll();

// 3. Check FCM tokens for last 10 users
$fcm = $db->query("SELECT id, name, phone, fcm_token IS NOT NULL as has_fcm, LEFT(fcm_token,20) as fcm_preview FROM users WHERE is_active=1 ORDER BY id DESC LIMIT 10")->fetchAll();

// 4. Check sessions (auth tokens)
$sessions = $db->query("SELECT s.user_id, u.name, LEFT(s.token,20) as token_preview, s.expires_at, s.last_activity FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.expires_at > NOW() ORDER BY s.last_activity DESC LIMIT 10")->fetchAll();

jsonOk([
    'messages_table_columns' => array_column($cols, 'Field'),
    'last_10_messages'       => $msgs,
    'user_fcm_status'        => $fcm,
    'active_sessions'        => $sessions
]);
