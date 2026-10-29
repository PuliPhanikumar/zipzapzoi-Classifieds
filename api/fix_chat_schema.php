<?php
require_once __DIR__ . '/config.php';
$db = getDB();

$results = [];

// 1. Add deleted_for column if not exists
try {
    $db->exec("ALTER TABLE messages ADD COLUMN deleted_for TEXT DEFAULT NULL");
    $results['alter_deleted_for'] = 'Added deleted_for column';
} catch (Throwable $e) {
    $results['alter_deleted_for'] = $e->getMessage();
}

// 2. Add media_url if not exists
try {
    $db->exec("ALTER TABLE messages ADD COLUMN media_url TEXT DEFAULT NULL");
    $results['alter_media_url'] = 'Added media_url column';
} catch (Throwable $e) {
    $results['alter_media_url'] = $e->getMessage();
}

// 3. Now test getThread query for user 7 with user 14
try {
    $uid = 7;
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
    $results['get_thread_test'] = [
        'success' => true,
        'count' => count($msgs),
        'last_3' => array_slice($msgs, -3)
    ];
} catch (Throwable $e) {
    $results['get_thread_test'] = [
        'success' => false,
        'error' => $e->getMessage()
    ];
}

// 4. Test getInbox query for user 7
try {
    $uid = 7;
    $stmt = $db->prepare(
        'SELECT m.*, 
                CASE WHEN m.from_user_id = ? THEN m.to_user_id ELSE m.from_user_id END AS other_user_id,
                u1.name AS from_name, u1.avatar AS from_avatar,
                u2.name AS to_name, u2.avatar AS to_avatar,
                l.title AS listing_title
         FROM messages m
         JOIN users u1 ON u1.id = m.from_user_id
         JOIN users u2 ON u2.id = m.to_user_id
         LEFT JOIN listings l ON l.id = m.listing_id
         WHERE m.id IN (
             SELECT MAX(id) FROM messages
             WHERE (from_user_id = ? OR to_user_id = ?)
               AND (deleted_for IS NULL OR FIND_IN_SET(?, deleted_for) = 0)
             GROUP BY LEAST(from_user_id, to_user_id), GREATEST(from_user_id, to_user_id), COALESCE(listing_id, 0)
         )
         ORDER BY m.created_at DESC
         LIMIT 50'
    );
    $stmt->execute([$uid, $uid, $uid, (string)$uid]);
    $rows = $stmt->fetchAll();
    $results['get_inbox_test'] = [
        'success' => true,
        'count' => count($rows),
        'inbox' => $rows
    ];
} catch (Throwable $e) {
    $results['get_inbox_test'] = [
        'success' => false,
        'error' => $e->getMessage()
    ];
}

jsonOk($results);
