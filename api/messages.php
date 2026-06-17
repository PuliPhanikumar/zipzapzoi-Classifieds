<?php
/**
 * ZipZapZoi Classifieds — Messages API
 * GET  /api/messages.php              → my inbox (grouped by thread)
 * GET  /api/messages.php?thread=X     → messages in thread with user X
 * POST /api/messages.php              → send message { to_user_id, listing_id, subject, body }
 * PUT  /api/messages.php?id=X         → mark as read
 * GET  /api/messages.php?action=unread_count → unread count
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fcm_helper.php';

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$thread = isset($_GET['thread']) ? (int)$_GET['thread'] : null;

if ($method === 'GET' && $action === 'unread_count') getUnreadCount($user);
elseif ($method === 'GET' && $thread)                getThread($user, $thread);
elseif ($method === 'GET')                           getInbox($user);
elseif ($method === 'POST')                          sendMessage($user);
elseif ($method === 'PUT'  && $id)                   markRead($user, $id);
else jsonError('Method not allowed', 405);

function getInbox(array $user): void {
    $db   = getDB();
    // Get latest message per conversation thread
    $stmt = $db->prepare(
        'SELECT m.*,
                CASE WHEN m.from_user_id = :me THEN m.to_user_id ELSE m.from_user_id END AS other_user_id,
                u1.name AS from_name, u1.avatar AS from_avatar,
                u2.name AS to_name, u2.avatar AS to_avatar,
                l.title AS listing_title
         FROM messages m
         JOIN users u1 ON u1.id = m.from_user_id
         JOIN users u2 ON u2.id = m.to_user_id
         LEFT JOIN listings l ON l.id = m.listing_id
         WHERE m.from_user_id = :me2 OR m.to_user_id = :me3
         ORDER BY m.created_at DESC
         LIMIT 100'
    );
    $stmt->execute([':me' => (int)$user['id'], ':me2' => (int)$user['id'], ':me3' => (int)$user['id']]);
    $rows = $stmt->fetchAll();
    // Group by other_user_id + listing_id thread
    $threads = [];
    foreach ($rows as $r) {
        $key = $r['other_user_id'] . '_' . ($r['listing_id'] ?? '0');
        if (!isset($threads[$key])) {
            $threads[$key] = [
                'other_user_id'    => (int)$r['other_user_id'],
                'other_user_name'  => $r['from_user_id'] == $user['id'] ? $r['to_name'] : $r['from_name'],
                'listing_id'       => $r['listing_id'],
                'listing_title'    => $r['listing_title'],
                'last_message'     => $r['body'],
                'last_time'        => $r['created_at'],
                'unread_count'     => 0,
                'messages'         => [],
            ];
        }
        if (!$r['is_read'] && $r['to_user_id'] == $user['id']) $threads[$key]['unread_count']++;
    }
    jsonOk(array_values($threads));
}

function getThread(array $user, int $otherId): void {
    $db   = getDB();
    $lid  = isset($_GET['listing_id']) ? (int)$_GET['listing_id'] : null;
    $sql  = 'SELECT m.*, u.name AS from_name, u.avatar AS from_avatar
             FROM messages m JOIN users u ON u.id = m.from_user_id
             WHERE ((m.from_user_id = ? AND m.to_user_id = ?) OR (m.from_user_id = ? AND m.to_user_id = ?))'
          . ($lid ? ' AND m.listing_id = ?' : '')
          . ' ORDER BY m.created_at ASC LIMIT 200';
    $params = [(int)$user['id'], $otherId, $otherId, (int)$user['id']];
    if ($lid) $params[] = $lid;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $msgs = $stmt->fetchAll();
    // Mark received messages as read
    $db->prepare('UPDATE messages SET is_read = 1 WHERE to_user_id = ? AND from_user_id = ?')
       ->execute([(int)$user['id'], $otherId]);
    jsonOk($msgs);
}

function sendMessage(array $user): void {
    $b      = getBody();
    $toId   = (int)($b['to_user_id'] ?? 0);
    $body   = trim($b['body'] ?? '');
    if (!$toId)   jsonError('to_user_id is required.');
    if (!$body)   jsonError('Message body is required.');
    if ($toId === (int)$user['id']) jsonError('Cannot message yourself.');

    $db = getDB();
    // Verify recipient exists and fetch fcm_token
    $chk = $db->prepare('SELECT id, fcm_token FROM users WHERE id = ? AND is_active = 1');
    $chk->execute([$toId]);
    $recipient = $chk->fetch();
    if (!$recipient) jsonError('Recipient not found.', 404);

    $db->prepare(
        'INSERT INTO messages (from_user_id, to_user_id, listing_id, subject, body)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        (int)$user['id'], $toId,
        !empty($b['listing_id']) ? (int)$b['listing_id'] : null,
        clean($b['subject'] ?? ''),
        clean($body)
    ]);
    
    // Send Push Notification if FCM token exists
    if (!empty($recipient['fcm_token'])) {
        $senderName = $user['name'] ?: 'Someone';
        sendFcmPush($recipient['fcm_token'], "New Message from $senderName", clean($body));
    }

    jsonOk(['message' => 'Message sent.'], 201);
}

function markRead(array $user, int $id): void {
    getDB()->prepare('UPDATE messages SET is_read = 1 WHERE id = ? AND to_user_id = ?')
           ->execute([$id, (int)$user['id']]);
    jsonOk(['message' => 'Marked as read.']);
}

function getUnreadCount(array $user): void {
    $stmt = getDB()->prepare('SELECT COUNT(*) FROM messages WHERE to_user_id = ? AND is_read = 0');
    $stmt->execute([(int)$user['id']]);
    jsonOk(['count' => (int)$stmt->fetchColumn()]);
}
