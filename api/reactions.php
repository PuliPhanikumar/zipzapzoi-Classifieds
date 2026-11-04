<?php
/**
 * ZipZapZoi - Emoji Reactions API
 * GET  /api/reactions.php?listing_id=X   → get reaction counts for a listing
 * POST /api/reactions.php                 → toggle a reaction {listing_id, reaction}
 */
require_once __DIR__ . '/config.php';

// Auto-create table
try {
    getDB()->exec("
        CREATE TABLE IF NOT EXISTS listing_reactions (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            listing_id  INT NOT NULL,
            user_id     INT NULL,
            session_id  VARCHAR(128) NOT NULL,
            reaction    ENUM('fire','heart','wow','eyes','hundred') NOT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_react (listing_id, session_id, reaction),
            INDEX idx_listing (listing_id)
        )
    ");
} catch (Exception $e) {}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $listing_id = (int)($_GET['listing_id'] ?? 0);
    if (!$listing_id) jsonError('Missing listing_id', 400);

    $db = getDB();
    $stmt = $db->prepare("
        SELECT reaction, COUNT(*) as count
        FROM listing_reactions
        WHERE listing_id = ?
        GROUP BY reaction
    ");
    $stmt->execute([$listing_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $counts = ['fire' => 0, 'heart' => 0, 'wow' => 0, 'eyes' => 0, 'hundred' => 0];
    foreach ($rows as $r) {
        $counts[$r['reaction']] = (int)$r['count'];
    }

    // Check user's own reaction if session_id passed or authenticated
    $myReactions = [];
    $sessionId = clean($_GET['session_id'] ?? '');
    $u = getCurrentUser();
    if (!$sessionId && $u) {
        $sessionId = 'user_' . $u['id'];
    }
    if ($sessionId) {
        $s2 = $db->prepare("SELECT reaction FROM listing_reactions WHERE listing_id = ? AND session_id = ?");
        $s2->execute([$listing_id, $sessionId]);
        $myReactions = array_column($s2->fetchAll(PDO::FETCH_ASSOC), 'reaction');
    }

    $isApp = !empty($_SERVER['HTTP_X_APP_CLIENT']) || stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'okhttp') !== false || !isset($_GET['session_id']);
    if ($isApp) {
        jsonOk($counts);
    } else {
        jsonOk(['counts' => $counts, 'my_reactions' => $myReactions, 'total' => array_sum($counts)]);
    }

} elseif ($method === 'POST') {
    $body = getBody();
    $listing_id = (int)($body['listing_id'] ?? 0);
    $reaction   = clean($body['reaction'] ?? '');
    $session_id = clean($body['session_id'] ?? '');

    $allowed = ['fire', 'heart', 'wow', 'eyes', 'hundred'];
    if (!$listing_id) jsonError('Missing listing_id', 400);
    if (!in_array($reaction, $allowed)) jsonError('Invalid reaction', 400);

    $user_id = null;
    $u = getCurrentUser();
    if ($u) {
        $user_id = (int)$u['id'];
    }
    if (!$session_id) {
        if ($user_id) {
            $session_id = 'user_' . $user_id;
        } else {
            $token = $_COOKIE[SESSION_COOKIE] ?? '';
            $session_id = $token ?: 'anon_' . md5($_SERVER['REMOTE_ADDR'] . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        }
    }

    $db = getDB();

    // Toggle: if exists, delete; if not, insert
    $check = $db->prepare("SELECT id FROM listing_reactions WHERE listing_id = ? AND session_id = ? AND reaction = ?");
    $check->execute([$listing_id, $session_id, $reaction]);
    $exists = $check->fetch();

    if ($exists) {
        $db->prepare("DELETE FROM listing_reactions WHERE listing_id = ? AND session_id = ? AND reaction = ?")
           ->execute([$listing_id, $session_id, $reaction]);
        $action = 'removed';
    } else {
        $db->prepare("INSERT IGNORE INTO listing_reactions (listing_id, user_id, session_id, reaction) VALUES (?,?,?,?)")
           ->execute([$listing_id, $user_id, $session_id, $reaction]);
        $action = 'added';
    }

    // Return fresh counts
    $stmt = $db->prepare("SELECT reaction, COUNT(*) as count FROM listing_reactions WHERE listing_id = ? GROUP BY reaction");
    $stmt->execute([$listing_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $counts = ['fire' => 0, 'heart' => 0, 'wow' => 0, 'eyes' => 0, 'hundred' => 0];
    foreach ($rows as $r) { $counts[$r['reaction']] = (int)$r['count']; }

    $isApp = !empty($_SERVER['HTTP_X_APP_CLIENT']) || stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'okhttp') !== false || empty($body['session_id']);
    if ($isApp) {
        jsonOk($counts);
    } else {
        jsonOk(['action' => $action, 'counts' => $counts, 'total' => array_sum($counts)]);
    }

} else {
    jsonError('Method not allowed', 405);
}
