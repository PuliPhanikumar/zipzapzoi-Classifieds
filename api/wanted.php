<?php
/**
 * ZipZapZoi Classifieds — Wanted Board API
 * GET /api/wanted.php        -> List wanted ads
 * POST /api/wanted.php       -> Create a wanted ad
 * DELETE /api/wanted.php?id= -> Delete a wanted ad
 */
require_once __DIR__ . '/config.php';

// Auto-migrate schema for wanted_ads
try {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS wanted_ads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        category VARCHAR(100) DEFAULT 'General',
        description TEXT,
        budget_min DECIMAL(10,2) DEFAULT 0,
        budget_max DECIMAL(10,2) DEFAULT 0,
        location_city VARCHAR(100),
        status ENUM('active', 'fulfilled', 'deleted') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) { /* Ignore */ }

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $db = getDB();
    $where = ["w.status = 'active'"];
    $params = [];
    
    if (!empty($_GET['city'])) {
        $where[] = "w.location_city LIKE ?";
        $params[] = '%' . trim($_GET['city']) . '%';
    }
    if (!empty($_GET['category']) && $_GET['category'] !== 'All') {
        $where[] = "w.category = ?";
        $params[] = trim($_GET['category']);
    }
    
    $whereSQL = implode(' AND ', $where);
    $sql = "SELECT w.*, u.name as buyer_name, u.avatar as buyer_avatar, u.phone as buyer_phone, u.trusted_seller 
            FROM wanted_ads w 
            JOIN users u ON u.id = w.user_id 
            WHERE {$whereSQL} 
            ORDER BY w.created_at DESC LIMIT 50";
            
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    
    // Formatting
    foreach ($rows as &$row) {
        $row['budget_min'] = (float)$row['budget_min'];
        $row['budget_max'] = (float)$row['budget_max'];
    }
    
    jsonOk(['wanted_ads' => $rows]);
} elseif ($method === 'POST') {
    $user = requireAuth();
    $body = getBody();
    
    $title    = trim($body['title'] ?? '');
    $category = trim($body['category'] ?? 'General');
    $desc     = trim($body['description'] ?? '');
    $min      = (float)($body['budget_min'] ?? 0);
    $max      = (float)($body['budget_max'] ?? 0);
    $city     = trim($body['location_city'] ?? '');
    
    if (!$title) jsonError('Title is required.');
    if ($max < $min) jsonError('Max budget cannot be less than Min budget.');
    
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO wanted_ads (user_id, title, category, description, budget_min, budget_max, location_city) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user['id'], $title, $category, $desc, $min, $max, $city]);
    
    jsonOk(['id' => $db->lastInsertId(), 'message' => 'Wanted ad posted!']);
} elseif ($method === 'DELETE') {
    $user = requireAuth();
    $id = $_GET['id'] ?? null;
    if (!$id) jsonError('Ad ID required');
    
    $db = getDB();
    $stmt = $db->prepare("UPDATE wanted_ads SET status = 'deleted' WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user['id']]);
    jsonOk(['message' => 'Wanted ad deleted']);
} else {
    jsonError('Method not allowed', 405);
}
