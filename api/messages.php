<?php
/**
 * ZipZapZoi Classifieds — Messages API
 * GET  /api/messages.php              → my inbox (grouped by user thread)
 * GET  /api/messages.php?with=X       → messages in thread with user X
 * POST /api/messages.php              → send message { to_user_id, listing_id, body, media_url }
 * PUT  /api/messages.php?id=X         → mark single message as read
 * GET  /api/messages.php?action=unread_count → unread count
 * DELETE /api/messages.php?conversation_id=X → soft-delete thread
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fcm_helper.php';

// Auto-migrate schema safety check (runs once per request, fast)
try {
    $db = getDB();
    $cols = $db->query("SHOW COLUMNS FROM messages")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('deleted_for', $cols)) {
        $db->exec("ALTER TABLE messages ADD COLUMN deleted_for TEXT DEFAULT NULL");
    }
    if (!in_array('media_url', $cols)) {
        $db->exec("ALTER TABLE messages ADD COLUMN media_url TEXT DEFAULT NULL");
    }
} catch (\Throwable $e) {}

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$thread = isset($_GET['with']) ? (int)$_GET['with'] : (isset($_GET['thread']) ? (int)$_GET['thread'] : null);

if ($method === 'GET' && $action === 'unread_count') getUnreadCount($user);
elseif ($method === 'GET' && $thread)                getThread($user, $thread);
elseif ($method === 'GET')                           getInbox($user);
elseif ($method === 'POST')                          sendMessage($user);
elseif ($method === 'PUT'  && $id)                   markRead($user, $id);
elseif ($method === 'DELETE') {
    $partnerId = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;
    if ($partnerId) {
        $db = getDB();
        // Mark all messages as deleted for this user (soft delete)
        $db->prepare("UPDATE messages SET deleted_for = CONCAT(IFNULL(deleted_for,''),',',?) 
                      WHERE (from_user_id = ? AND to_user_id = ?) OR (from_user_id = ? AND to_user_id = ?)")
           ->execute([$user['id'], $user['id'], $partnerId, $partnerId, $user['id']]);
        jsonOk(['message' => 'Conversation deleted']);
    }
    jsonError('conversation_id required', 400);
}
else jsonError('Method not allowed', 405);

function getInbox(array $user): void {
    $db  = getDB();
    $uid = (int)$user['id'];
    $stmt = $db->prepare(
        'SELECT m.*, 
                CASE WHEN m.from_user_id = ? THEN m.to_user_id ELSE m.from_user_id END AS other_user_id,
                u1.name AS from_name, u1.avatar AS from_avatar,
                u2.name AS to_name, u2.avatar AS to_avatar,
                l.title AS listing_title, l.images AS listing_images, l.price AS listing_price
         FROM messages m
         JOIN users u1 ON u1.id = m.from_user_id
         JOIN users u2 ON u2.id = m.to_user_id
         LEFT JOIN listings l ON l.id = m.listing_id
         WHERE m.id IN (
             SELECT MAX(id) FROM messages
             WHERE (from_user_id = ? OR to_user_id = ?)
               AND (deleted_for IS NULL OR FIND_IN_SET(?, deleted_for) = 0)
             GROUP BY LEAST(from_user_id, to_user_id), GREATEST(from_user_id, to_user_id)
         )
         ORDER BY m.created_at DESC
         LIMIT 50'
    );
    $stmt->execute([$uid, $uid, $uid, (string)$uid]);
    $rows = $stmt->fetchAll();
    
    $threads = [];
    foreach ($rows as $r) {
        $otherId = (int)$r['other_user_id'];
        $unreadStmt = $db->prepare(
            'SELECT COUNT(*) FROM messages WHERE to_user_id = ? AND from_user_id = ? AND is_read = 0 AND (deleted_for IS NULL OR FIND_IN_SET(?, deleted_for) = 0)'
        );
        $unreadStmt->execute([$uid, $otherId, (string)$uid]);
        $unreadCount = (int)$unreadStmt->fetchColumn();

        $listingImages = [];
        if (!empty($r['listing_images'])) {
            $listingImages = normalizeImagesArray($r['listing_images']);
        }
        
        $threads[] = [
            'other_user_id'   => $otherId,
            'partner_id'      => $otherId,
            'other_user_name' => $r['from_user_id'] == $uid ? ($r['to_name'] ?? 'User') : ($r['from_name'] ?? 'User'),
            'partner_name'    => $r['from_user_id'] == $uid ? ($r['to_name'] ?? 'User') : ($r['from_name'] ?? 'User'),
            'other_avatar'    => toAbsoluteUrl($r['from_user_id'] == $uid ? ($r['to_avatar'] ?? null) : ($r['from_avatar'] ?? null)),
            'partner_avatar'  => toAbsoluteUrl($r['from_user_id'] == $uid ? ($r['to_avatar'] ?? null) : ($r['from_avatar'] ?? null)),
            'listing_id'      => $r['listing_id'] ? (int)$r['listing_id'] : null,
            'listing_title'   => $r['listing_title'],
            'listing_image'   => $listingImages[0] ?? null,
            'listing_price'   => $r['listing_price'],
            'last_message'    => $r['body'],
            'last_time'       => $r['created_at'],
            'updated_at'      => $r['created_at'],
            'unread_count'    => $unreadCount,
        ];
    }
    jsonOk(array_values($threads));
}

function getThread(array $user, int $otherId): void {
    $db   = getDB();
    $uid  = (int)$user['id'];
    
    // Always fetch ALL messages between these two users (complete conversation history)
    $sql  = 'SELECT m.*, u.name AS from_name, u.avatar AS from_avatar,
                    l.title AS listing_title, l.price AS listing_price, l.images AS listing_images
             FROM messages m
             JOIN users u ON u.id = m.from_user_id
             LEFT JOIN listings l ON l.id = m.listing_id
             WHERE ((m.from_user_id = ? AND m.to_user_id = ?) OR (m.from_user_id = ? AND m.to_user_id = ?))
               AND (m.deleted_for IS NULL OR FIND_IN_SET(?, m.deleted_for) = 0)
             ORDER BY m.created_at ASC LIMIT 300';
    $params = [$uid, $otherId, $otherId, $uid, (string)$uid];
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $msgs = $stmt->fetchAll();
    
    foreach ($msgs as &$msg) {
        $msg['from_avatar'] = toAbsoluteUrl($msg['from_avatar'] ?? null);
        $msg['media_url']   = toAbsoluteUrl($msg['media_url'] ?? null);
        $msg['is_read']     = (bool)$msg['is_read'];
        $msg['sender_id']   = (int)$msg['from_user_id'];
        $msg['receiver_id'] = (int)$msg['to_user_id'];
        $msg['text']        = $msg['body'];
    }
    
    // Mark all received messages as read
    $db->prepare('UPDATE messages SET is_read = 1 WHERE to_user_id = ? AND from_user_id = ?')
       ->execute([$uid, $otherId]);
       
    jsonOk($msgs);
}

function sendMessage(array $user): void {
    $b      = getBody();
    $toId   = (int)($b['to_user_id'] ?? 0);
    $body   = trim($b['body'] ?? '');
    $mediaUrl = trim($b['media_url'] ?? $b['image_url'] ?? '');
    
    if (!$toId) jsonError('to_user_id is required.');
    if (!$body && !$mediaUrl) jsonError('Message body or media is required.');
    if ($toId === (int)$user['id']) jsonError('Cannot message yourself.');

    $db = getDB();
    $chk = $db->prepare('SELECT id, name, fcm_token FROM users WHERE id = ? AND is_active = 1');
    $chk->execute([$toId]);
    $recipient = $chk->fetch();
    if (!$recipient) jsonError('Recipient not found.', 404);

    $listingId = !empty($b['listing_id']) && (int)$b['listing_id'] > 0 ? (int)$b['listing_id'] : null;

    $db->prepare(
        'INSERT INTO messages (from_user_id, to_user_id, listing_id, subject, body, media_url)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        (int)$user['id'],
        $toId,
        $listingId,
        trim($b['subject'] ?? ''),
        trim($body),
        $mediaUrl ?: null
    ]);
    
    $insertedId = (int)$db->lastInsertId();
    
    // Send Push Notification if FCM token exists
    $senderName = $user['name'] ?: 'Someone';
    $notifBody  = $body ?: 'Sent an attachment';

    if (!empty($recipient['fcm_token'])) {
        sendFcmPush($recipient['fcm_token'], "New message from $senderName", $notifBody, [
            'type'                    => 'new_message',
            'conversation_partner_id' => (string)$user['id'],
            'partner_id'              => (string)$user['id'],
            'sender_name'             => (string)$senderName,
            'listing_id'              => (string)($listingId ?? ''),
            'body'                    => (string)$notifBody
        ]);
    }

    // Also persist in-app notification
    try {
        $db->prepare("INSERT INTO user_notifications (user_id, type, title, message, link) VALUES (?, 'new_message', ?, ?, ?)")
           ->execute([$toId, "New message from $senderName", $notifBody, "chat/" . $user['id'] . "/$senderName"]);
    } catch (\Throwable $e) {}

    $sql  = 'SELECT m.*, u.name AS from_name, u.avatar AS from_avatar
             FROM messages m JOIN users u ON u.id = m.from_user_id
             WHERE m.id = ?';
    $stmt = $db->prepare($sql);
    $stmt->execute([$insertedId]);
    $msgData = $stmt->fetch();
    
    if ($msgData) {
        $msgData['from_avatar'] = toAbsoluteUrl($msgData['from_avatar'] ?? null);
        $msgData['media_url']   = toAbsoluteUrl($msgData['media_url'] ?? null);
        $msgData['is_read']     = (bool)$msgData['is_read'];
        $msgData['sender_id']   = (int)$msgData['from_user_id'];
        $msgData['receiver_id'] = (int)$msgData['to_user_id'];
        $msgData['text']        = $msgData['body'];
    }
    
    jsonOk($msgData, 201);
}

function markRead(array $user, int $id): void {
    getDB()->prepare('UPDATE messages SET is_read = 1 WHERE id = ? AND to_user_id = ?')
           ->execute([$id, (int)$user['id']]);
    jsonOk(['message' => 'Marked as read.']);
}

function getUnreadCount(array $user): void {
    $uid = (int)$user['id'];
    $stmt = getDB()->prepare('SELECT COUNT(*) FROM messages WHERE to_user_id = ? AND is_read = 0 AND (deleted_for IS NULL OR FIND_IN_SET(?, deleted_for) = 0)');
    $stmt->execute([$uid, (string)$uid]);
    jsonOk(['count' => (int)$stmt->fetchColumn()]);
}
