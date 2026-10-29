<?php
require_once __DIR__ . '/config.php';
$db = getDB();

// Get actual full token for user 7
$sess = $db->query("SELECT s.token, u.name, u.id FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.user_id = 7 AND s.expires_at > NOW() ORDER BY s.id DESC LIMIT 1")->fetch();

if (!$sess) {
    die(json_encode(['error' => 'No active session found for user 7']));
}

$token = $sess['token'];

// Now let's simulate calling getThread directly
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
$_GET['with'] = '14';

// Let's run the exact getThread logic and capture any exception
try {
    $user = getCurrentUser();
    if (!$user) {
        die(json_encode(['error' => 'getCurrentUser returned null', 'token' => $token, 'len' => strlen($token)]));
    }
    
    // Now test the getThread SQL
    $uid = (int)$user['id'];
    $otherId = 14;
    $sql  = 'SELECT m.*, u.name AS from_name, u.avatar AS from_avatar
             FROM messages m JOIN users u ON u.id = m.from_user_id
             WHERE ((m.from_user_id = ? AND m.to_user_id = ?) OR (m.from_user_id = ? AND m.to_user_id = ?))
               AND (m.deleted_for IS NULL OR FIND_IN_SET(?, m.deleted_for) = 0)
             ORDER BY m.created_at ASC LIMIT 200';
    $params = [$uid, $otherId, $otherId, $uid, (string)$uid];
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $msgs = $stmt->fetchAll();
    echo json_encode(['success' => true, 'msgs_count' => count($msgs), 'msgs' => $msgs]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
}
