<?php
/**
 * ZipZapZoi Classifieds — Reviews API
 * GET /api/reviews.php?seller_id=1
 * POST /api/reviews.php -> { seller_id, rating, comment }
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $sellerId = (int)($_GET['seller_id'] ?? 0);
    if (!$sellerId) jsonError('seller_id is required.');

    $db = getDB();

    // Get average rating and count
    $stmt = $db->prepare("SELECT COUNT(*) as total_reviews, AVG(rating) as avg_rating FROM reviews WHERE seller_id = ?");
    $stmt->execute([$sellerId]);
    $stats = $stmt->fetch();

    // Get reviews with buyer details
    $stmt = $db->prepare("
        SELECT r.*, u.name as buyer_name, u.avatar as buyer_avatar
        FROM reviews r
        JOIN users u ON u.id = r.buyer_id
        WHERE r.seller_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$sellerId]);
    $reviews = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total' => (int)$stats['total_reviews'],
            'average' => round((float)$stats['avg_rating'], 1)
        ],
        'reviews' => $reviews
    ]);
}

if ($method === 'POST') {
    $user = requireAuth();
    $b = getBody();
    
    $sellerId = (int)($b['seller_id'] ?? 0);
    $rating = (int)($b['rating'] ?? 0);
    $comment = clean($b['comment'] ?? '');

    if (!$sellerId) jsonError('seller_id is required.');
    if ($sellerId === (int)$user['id']) jsonError('You cannot review yourself.');
    if ($rating < 1 || $rating > 5) jsonError('Rating must be between 1 and 5.');

    $db = getDB();

    // Check if review already exists
    $stmt = $db->prepare("SELECT id FROM reviews WHERE seller_id = ? AND buyer_id = ?");
    $stmt->execute([$sellerId, $user['id']]);
    if ($stmt->fetch()) {
        jsonError('You have already reviewed this seller.');
    }

    $stmt = $db->prepare("INSERT INTO reviews (seller_id, buyer_id, rating, comment) VALUES (?, ?, ?, ?)");
    try {
        $stmt->execute([$sellerId, $user['id'], $rating, $comment]);
        jsonOk(['message' => 'Review submitted successfully.']);
    } catch (PDOException $e) {
        jsonError('Database error while saving review.');
    }
}

jsonError('Method Not Allowed', 405);
