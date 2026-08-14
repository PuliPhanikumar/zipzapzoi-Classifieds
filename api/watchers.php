<?php
/**
 * ZipZapZoi - Live Watcher Counter (Hybrid: real sessions + smart random)
 * GET /api/watchers.php?listing_id=X&session_id=Y → returns current watcher count
 * POST /api/watchers.php {listing_id, session_id} → heartbeat (keep watching)
 */
require_once __DIR__ . '/config.php';

// Auto-create table
try {
    getDB()->exec("
        CREATE TABLE IF NOT EXISTS listing_watchers (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            listing_id  INT NOT NULL,
            session_id  VARCHAR(128) NOT NULL,
            last_seen   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_watcher (listing_id, session_id),
            INDEX idx_listing (listing_id),
            INDEX idx_last_seen (last_seen)
        )
    ");
} catch (Exception $e) {}

$method = $_SERVER['REQUEST_METHOD'];
$listing_id = (int)($_GET['listing_id'] ?? (getBody()['listing_id'] ?? 0));
$session_id = clean($_GET['session_id'] ?? (getBody()['session_id'] ?? ''));

if (!$listing_id) jsonError('Missing listing_id', 400);

$db = getDB();

if ($method === 'POST' && $session_id) {
    // Heartbeat: upsert session
    try {
        $db->prepare("INSERT INTO listing_watchers (listing_id, session_id) VALUES (?,?) ON DUPLICATE KEY UPDATE last_seen = NOW()")
           ->execute([$listing_id, $session_id]);
        // Clean old sessions (>5 min inactive)
        $db->prepare("DELETE FROM listing_watchers WHERE last_seen < DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->execute();
    } catch (Exception $e) {}
}

// Count real active watchers (last 5 min)
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM listing_watchers WHERE listing_id = ? AND last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    $stmt->execute([$listing_id]);
    $realCount = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $realCount = 0;
}

// Hybrid: if real count < 3, add realistic pseudo-random based on listing_id + views
// This ensures the counter always shows meaningful social proof
try {
    $viewStmt = $db->prepare("SELECT views FROM listings WHERE id = ?");
    $viewStmt->execute([$listing_id]);
    $views = (int)($viewStmt->fetchColumn() ?? 0);
} catch (Exception $e) {
    $views = 0;
}

// Generate a stable pseudo-random number: changes slowly over time (every 3 min)
$timeSeed = floor(time() / 180); // changes every 3 minutes
$pseudoBase = (int)(abs(sin($listing_id * 9301 + $timeSeed * 49297)) * 100);

// Scale by views: more views = more watchers shown (max 25)
$viewFactor = min(20, max(1, (int)($views / 10)));
$pseudoWatchers = max(1, $pseudoBase % $viewFactor + 1);

// Hybrid result: real + pseudo (with a cap to keep realistic)
$displayCount = max($realCount, $pseudoWatchers);
$displayCount = min(50, $displayCount); // never show more than 50

jsonOk([
    'listing_id' => $listing_id,
    'count' => $displayCount,
    'real' => $realCount,
]);
