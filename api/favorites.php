<?php
/**
 * ZipZapZoi Classifieds — Favorites API
 * GET    /api/favorites.php                   → get my favorite listings
 * POST   /api/favorites.php                   → add { listing_id }
 * DELETE /api/favorites.php?listing_id=X      → remove
 * POST   /api/favorites.php?action=toggle     → toggle, returns { is_favorited }
 */
require_once __DIR__ . '/config.php';

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET')                          getFavorites($user);
elseif ($method === 'POST' && $action==='toggle')toggleFavorite($user);
elseif ($method === 'POST')                     addFavorite($user);
elseif ($method === 'DELETE')                   removeFavorite($user);
else jsonError('Method not allowed', 405);

function getFavorites(array $user): void {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT l.id, l.title, l.price, l.price_type, l.images, l.location_city,
                l.location_state, l.category, l.status, l.created_at,
                u.name AS seller_name
         FROM favorites f
         JOIN listings l ON l.id = f.listing_id
         JOIN users u ON u.id = l.user_id
         WHERE f.user_id = ?
         ORDER BY f.created_at DESC'
    );
    $stmt->execute([(int)$user['id']]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['images'] = json_decode($r['images'] ?? '[]', true) ?: [];
        $r['thumbnail'] = $r['images'][0] ?? null;
        $r['price'] = (float)$r['price'];
        $r['id'] = (int)$r['id'];
    }
    jsonOk($rows);
}

function addFavorite(array $user): void {
    $b  = getBody();
    $lid = (int)($b['listing_id'] ?? 0);
    if (!$lid) jsonError('listing_id required.');
    try {
        getDB()->prepare('INSERT IGNORE INTO favorites (user_id, listing_id) VALUES (?, ?)')->execute([(int)$user['id'], $lid]);
        jsonOk(['is_favorited' => true]);
    } catch (PDOException $e) { jsonError('Could not save favorite.'); }
}

function removeFavorite(array $user): void {
    $lid = (int)($_GET['listing_id'] ?? 0);
    if (!$lid) { $b = getBody(); $lid = (int)($b['listing_id'] ?? 0); }
    if (!$lid) jsonError('listing_id required.');
    getDB()->prepare('DELETE FROM favorites WHERE user_id = ? AND listing_id = ?')->execute([(int)$user['id'], $lid]);
    jsonOk(['is_favorited' => false]);
}

function toggleFavorite(array $user): void {
    $b  = getBody();
    $lid = (int)($b['listing_id'] ?? 0);
    if (!$lid) jsonError('listing_id required.');
    $db  = getDB();
    $chk = $db->prepare('SELECT id FROM favorites WHERE user_id = ? AND listing_id = ?');
    $chk->execute([(int)$user['id'], $lid]);
    if ($chk->fetch()) {
        $db->prepare('DELETE FROM favorites WHERE user_id = ? AND listing_id = ?')->execute([(int)$user['id'], $lid]);
        jsonOk(['is_favorited' => false]);
    } else {
        $db->prepare('INSERT IGNORE INTO favorites (user_id, listing_id) VALUES (?, ?)')->execute([(int)$user['id'], $lid]);
        jsonOk(['is_favorited' => true]);
    }
}
