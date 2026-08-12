<?php
/**
 * ZipZapZoi Classifieds — Price Alerts / Saved Searches API
 *
 * Allows users to register a search alert so they get notified when a new
 * listing matches their criteria. Sends an FCM push to the user's device.
 *
 * Endpoints:
 *   GET    /api/alerts.php                → get my saved alerts
 *   POST   /api/alerts.php                → register a new alert
 *   DELETE /api/alerts.php?id=X           → delete an alert
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fcm_helper.php';

// Auto-create table if it doesn't exist
try {
    getDB()->exec("CREATE TABLE IF NOT EXISTS search_alerts (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT UNSIGNED NOT NULL,
        category    VARCHAR(100)  DEFAULT NULL,
        subcategory VARCHAR(100)  DEFAULT NULL,
        city        VARCHAR(100)  DEFAULT NULL,
        state       VARCHAR(100)  DEFAULT NULL,
        keyword     VARCHAR(255)  DEFAULT NULL,
        min_price   DECIMAL(10,2) DEFAULT NULL,
        max_price   DECIMAL(10,2) DEFAULT NULL,
        is_active   TINYINT(1)    DEFAULT 1,
        created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_active (is_active),
        INDEX idx_user   (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) { /* table already exists */ }

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET — list my alerts ───────────────────────────────────────────────────
if ($method === 'GET') {
    $stmt = getDB()->prepare(
        'SELECT * FROM search_alerts WHERE user_id = ? AND is_active = 1 ORDER BY created_at DESC'
    );
    $stmt->execute([$user['id']]);
    jsonOk($stmt->fetchAll());
}

// ── POST — create a new alert ──────────────────────────────────────────────
elseif ($method === 'POST') {
    $b = getBody();

    $category    = clean($b['category']    ?? '');
    $subcategory = clean($b['subcategory'] ?? '');
    $city        = clean($b['city']        ?? '');
    $state       = clean($b['state']       ?? '');
    $keyword     = clean($b['keyword']     ?? '');
    $minPrice    = isset($b['min_price']) && is_numeric($b['min_price']) ? (float)$b['min_price'] : null;
    $maxPrice    = isset($b['max_price']) && is_numeric($b['max_price']) ? (float)$b['max_price'] : null;

    // At least one filter must be set
    if (!$category && !$keyword && !$city) {
        jsonError('Please provide at least a category, keyword, or city for the alert.');
    }

    // Cap alerts per user at 10
    $countStmt = getDB()->prepare('SELECT COUNT(*) FROM search_alerts WHERE user_id = ? AND is_active = 1');
    $countStmt->execute([$user['id']]);
    if ((int)$countStmt->fetchColumn() >= 10) {
        jsonError('You can have a maximum of 10 active alerts. Please delete one first.');
    }

    $db = getDB();
    $db->prepare(
        'INSERT INTO search_alerts
            (user_id, category, subcategory, city, state, keyword, min_price, max_price)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $user['id'], $category ?: null, $subcategory ?: null,
        $city ?: null, $state ?: null, $keyword ?: null,
        $minPrice, $maxPrice,
    ]);

    jsonOk(['id' => $db->lastInsertId(), 'message' => 'Alert saved! You\'ll be notified when a matching listing is posted.'], 201);
}

// ── DELETE — remove an alert ───────────────────────────────────────────────
elseif ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) jsonError('Alert ID is required.');

    $stmt = getDB()->prepare(
        'UPDATE search_alerts SET is_active = 0 WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$id, $user['id']]);

    if ($stmt->rowCount() === 0) jsonError('Alert not found or you do not have permission to delete it.', 404);
    jsonOk(['message' => 'Alert deleted.']);
}

else {
    jsonError('Method not allowed.', 405);
}
