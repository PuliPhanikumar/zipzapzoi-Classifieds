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
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        category VARCHAR(100) DEFAULT 'General',
        subcategory VARCHAR(100) DEFAULT NULL,
        description TEXT,
        budget_min DECIMAL(10,2) DEFAULT 0,
        budget_max DECIMAL(10,2) DEFAULT 0,
        location_state VARCHAR(100) DEFAULT NULL,
        location_city VARCHAR(100) DEFAULT NULL,
        dynamic_data JSON DEFAULT NULL,
        status ENUM('active', 'fulfilled', 'deleted') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Auto-migrate existing table
    $db->exec("ALTER TABLE wanted_ads ADD COLUMN subcategory VARCHAR(100) DEFAULT NULL");
    $db->exec("ALTER TABLE wanted_ads ADD COLUMN location_state VARCHAR(100) DEFAULT NULL");
    $db->exec("ALTER TABLE wanted_ads ADD COLUMN dynamic_data JSON DEFAULT NULL");
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
    if (!empty($_GET['user_id'])) {
        $where[] = "w.user_id = ?";
        $params[] = (int)$_GET['user_id'];
    }
    if (!empty($_GET['id'])) {
        $where[] = "w.id = ?";
        $params[] = (int)$_GET['id'];
    }
    
    $whereSQL = implode(' AND ', $where);
    
    // Pagination
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $sql = "SELECT w.*, u.name as buyer_name, u.avatar as buyer_avatar, u.phone as buyer_phone, u.trusted_seller 
            FROM wanted_ads w 
            JOIN users u ON u.id = w.user_id 
            WHERE {$whereSQL} 
            ORDER BY w.created_at DESC 
            LIMIT ? OFFSET ?";
            
    $stmt = $db->prepare($sql);
    $idx = 1;
    foreach ($params as $v) { $stmt->bindValue($idx++, $v); }
    $stmt->bindValue($idx++,  $limit,  PDO::PARAM_INT);
    $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
    $stmt->execute();
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
    
    $title       = trim($body['title'] ?? '');
    $category    = trim($body['category'] ?? 'General');
    $subcategory = trim($body['subcategory'] ?? '');
    $desc        = trim($body['description'] ?? '');
    $min         = (float)($body['budget_min'] ?? 0);
    $max         = (float)($body['budget_max'] ?? 0);
    $state       = trim($body['location_state'] ?? '');
    $city        = trim($body['location_city'] ?? '');
    
    $dynamic_data = null;
    if (isset($body['dynamic_data']) && is_array($body['dynamic_data'])) {
        $dynamic_data = json_encode($body['dynamic_data'], JSON_UNESCAPED_UNICODE);
    }
    
    if (!$title) jsonError('Title is required.');
    if ($max < $min) jsonError('Max budget cannot be less than Min budget.');
    
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO wanted_ads (user_id, title, category, subcategory, description, budget_min, budget_max, location_state, location_city, dynamic_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user['id'], $title, $category, $subcategory, $desc, $min, $max, $state, $city, $dynamic_data]);
    
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
