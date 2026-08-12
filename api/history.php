<?php
/**
 * ZipZapZoi Classifieds — Listing View History API
 *
 * Records and retrieves a user's recently viewed listings.
 * Called by classifieds.html to show "Recently Viewed" section from DB.
 * Called by Listing Detail.html (POST) to record a view.
 *
 * Endpoints:
 *   GET    /api/history.php?limit=10    → return recent listings for logged-in user
 *   POST   /api/history.php             → record a listing view { listing_id }
 *   DELETE /api/history.php             → clear all history for logged-in user
 */
require_once __DIR__ . '/config.php';

// Auto-create table if it doesn't exist
try {
    getDB()->exec("CREATE TABLE IF NOT EXISTS listing_history (
        id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id    INT UNSIGNED    NOT NULL,
        listing_id INT UNSIGNED    NOT NULL,
        viewed_at  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_listing (user_id, listing_id),
        INDEX idx_user_viewed (user_id, viewed_at DESC),
        FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
        FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) { /* table already exists */ }

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// ── GET — fetch recently viewed listings ───────────────────────────────────
if ($method === 'GET') {
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));

    $stmt = $db->prepare(
        'SELECT l.id, l.title, l.price, l.price_type, l.location_city, l.location_state,
                l.images, l.category, h.viewed_at
         FROM listing_history h
         JOIN listings l ON l.id = h.listing_id AND l.status = "active"
         WHERE h.user_id = ?
         ORDER BY h.viewed_at DESC
         LIMIT ?'
    );
    $stmt->execute([$user['id'], $limit]);
    $rows = $stmt->fetchAll();

    // Decode images JSON
    foreach ($rows as &$row) {
        if (!empty($row['images'])) {
            $decoded = json_decode($row['images'], true);
            $row['images'] = normalizeImagesArray(is_array($decoded) ? $decoded : [$row['images']]);
        } else {
            $row['images'] = [];
        }
        $row['thumbnail'] = $row['images'][0] ?? null;
    }
    unset($row);

    jsonOk($rows);
}

// ── POST — record a view ───────────────────────────────────────────────────
elseif ($method === 'POST') {
    $b          = getBody();
    $listingId  = (int)($b['listing_id'] ?? 0);
    if (!$listingId) jsonError('listing_id is required.');

    // UPSERT: update timestamp if already viewed, insert if new
    $db->prepare(
        'INSERT INTO listing_history (user_id, listing_id)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE viewed_at = CURRENT_TIMESTAMP'
    )->execute([$user['id'], $listingId]);

    // Keep history capped at 50 most recent per user
    $db->prepare(
        'DELETE FROM listing_history
         WHERE user_id = ?
           AND id NOT IN (
             SELECT id FROM (
               SELECT id FROM listing_history WHERE user_id = ? ORDER BY viewed_at DESC LIMIT 50
             ) sub
           )'
    )->execute([$user['id'], $user['id']]);

    jsonOk(['message' => 'View recorded.']);
}

// ── DELETE — clear history ─────────────────────────────────────────────────
elseif ($method === 'DELETE') {
    $db->prepare('DELETE FROM listing_history WHERE user_id = ?')->execute([$user['id']]);
    jsonOk(['message' => 'History cleared.']);
}

else {
    jsonError('Method not allowed.', 405);
}
