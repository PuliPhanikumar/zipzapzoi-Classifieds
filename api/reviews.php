<?php
/**
 * ZipZapZoi Classifieds — Reviews API
 * GET /api/reviews.php?seller_id=1
 * POST /api/reviews.php -> { seller_id, rating, comment }
 */
require_once __DIR__ . '/config.php';

try {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS reviews (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        seller_id INT UNSIGNED NOT NULL,
        buyer_id INT UNSIGNED NOT NULL,
        rating TINYINT UNSIGNED NOT NULL CHECK(rating BETWEEN 1 AND 5),
        comment TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) { /* Ignore */ }

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $sellerId = (int)($_GET['seller_id'] ?? 0);
    if (!$sellerId) jsonError('seller_id is required.');

    $db = getDB();

    // Get canonical reviews with user details
    $stmt = $db->prepare("
        SELECT r.id, r.seller_id, r.buyer_id, r.rating, r.comment, r.created_at,
               u.name as buyer_name, u.avatar as buyer_avatar
        FROM reviews r
        JOIN users u ON u.id = r.buyer_id
        WHERE r.seller_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$sellerId]);
    $reviews = $stmt->fetchAll();

    foreach ($reviews as &$r) {
        $r['id']           = (int)$r['id'];
        $r['seller_id']    = (int)$r['seller_id'];
        $r['buyer_id']     = (int)$r['buyer_id'];
        $r['rating']       = (int)$r['rating'];
        $r['buyer_avatar'] = toAbsoluteUrl($r['buyer_avatar'] ?? null);
    }
    unset($r);

    $totalReviews = count($reviews);
    $avgRating = $totalReviews > 0 ? round(array_sum(array_column($reviews, 'rating')) / $totalReviews, 1) : 0.0;

    $isApp = !empty($_SERVER['HTTP_X_APP_CLIENT']) || isset($_GET['flat']) || stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'okhttp') !== false;
    if ($isApp) {
        jsonOk($reviews);
    } else {
        jsonOk([
            'stats' => [
                'total'   => $totalReviews,
                'average' => $avgRating
            ],
            'reviews' => $reviews
        ]);
    }
}

if ($method === 'POST') {
    $user = requireAuth();
    $b = getBody();
    
    $sellerId = (int)($b['seller_id'] ?? 0);
    $rating = (int)($b['rating'] ?? 0);
    $comment = clean($b['comment'] ?? $b['text'] ?? '');

    if (!$sellerId) jsonError('seller_id is required.');
    if ($sellerId === (int)$user['id']) jsonError('You cannot review yourself.');
    if ($rating < 1 || $rating > 5) jsonError('Rating must be between 1 and 5.');

    $db = getDB();

    try {
        $stmt = $db->prepare("
            INSERT INTO reviews (seller_id, buyer_id, rating, comment)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)
        ");
        $stmt->execute([$sellerId, $user['id'], $rating, $comment]);

        // Cross-sync into user_reviews for complete consistency
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS user_reviews (
                id INT AUTO_INCREMENT PRIMARY KEY,
                seller_id INT NOT NULL,
                reviewer_id INT NOT NULL,
                rating TINYINT NOT NULL,
                review_text TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY one_review_per_seller (seller_id, reviewer_id)
            )');
            $db->prepare("
                INSERT INTO user_reviews (seller_id, reviewer_id, rating, review_text)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE rating = VALUES(rating), review_text = VALUES(review_text)
            ")->execute([$sellerId, $user['id'], $rating, $comment]);
        } catch (\Throwable $ue) {}

        jsonOk(['message' => 'Review submitted successfully.']);
    } catch (PDOException $e) {
        jsonError('Database error while saving review: ' . $e->getMessage(), 500);
    }
}

jsonError('Method Not Allowed', 405);
