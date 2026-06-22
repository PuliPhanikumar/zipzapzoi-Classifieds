<?php
/**
 * ZipZapZoi Classifieds — Neighborhood Alerts API
 * GET /api/alerts.php        -> List my alerts
 * POST /api/alerts.php       -> Create an alert
 * DELETE /api/alerts.php?id= -> Delete an alert
 */
require_once __DIR__ . '/config.php';

// Auto-migrate schema for search_alerts
try {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS search_alerts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        keyword VARCHAR(255) DEFAULT '',
        category VARCHAR(100) DEFAULT 'All',
        lat DECIMAL(10,8) NULL,
        lng DECIMAL(11,8) NULL,
        radius INT DEFAULT 15,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) { /* Ignore */ }

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = requireAuth();
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM search_alerts WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user['id']]);
    jsonOk($stmt->fetchAll());
} elseif ($method === 'POST') {
    $user = requireAuth();
    $body = getBody();
    
    $keyword  = trim($body['keyword'] ?? '');
    $category = trim($body['category'] ?? 'All');
    $lat      = isset($body['lat']) ? (float)$body['lat'] : null;
    $lng      = isset($body['lng']) ? (float)$body['lng'] : null;
    $radius   = (int)($body['radius'] ?? 15);
    
    // Validate that at least keyword or category is set if no location
    if ($category === 'All' && $keyword === '' && !$lat) {
        jsonError('Please specify what you want to be alerted for (Category, Keyword, or Location).');
    }
    
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO search_alerts (user_id, keyword, category, lat, lng, radius) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user['id'], $keyword, $category, $lat, $lng, $radius]);
    
    jsonOk(['id' => $db->lastInsertId(), 'message' => 'Alert created successfully!']);
} elseif ($method === 'DELETE') {
    $user = requireAuth();
    $id = $_GET['id'] ?? null;
    if (!$id) jsonError('Alert ID required');
    
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM search_alerts WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user['id']]);
    jsonOk(['message' => 'Alert deleted']);
} else {
    jsonError('Method not allowed', 405);
}
