<?php
/**
 * ZipZapZoi Classifieds — Listings API
 * GET    /api/listings.php              → all listings (with filters)
 * GET    /api/listings.php?id=X         → single listing
 * POST   /api/listings.php              → create listing
 * PUT    /api/listings.php?id=X         → update listing
 * DELETE /api/listings.php?id=X         → delete listing
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

switch ($method) {
    case 'GET':    $id ? getOne($id) : getAll();    break;
    case 'POST':   createListing();                  break;
    case 'PUT':    $id ? updateListing($id) : jsonError('id required'); break;
    case 'DELETE': $id ? deleteListing($id) : jsonError('id required'); break;
    default:       jsonError('Method not allowed', 405);
}

// ─────────────────────────────────────────────────────────────────────
// GET ALL — with filters: category, subcategory, city, state, search,
//           min_price, max_price, sort, status, user_id, page, limit
// ─────────────────────────────────────────────────────────────────────
function getAll(): void {
    $db     = getDB();
    $where  = ['l.status = :status'];
    $params = [':status' => $_GET['status'] ?? 'active'];

    if (!empty($_GET['category'])) {
        $where[] = 'l.category = :cat';
        $params[':cat'] = $_GET['category'];
    }
    if (!empty($_GET['subcategory'])) {
        $where[] = 'l.subcategory = :subcat';
        $params[':subcat'] = $_GET['subcategory'];
    }
    if (!empty($_GET['city'])) {
        $where[] = 'l.location_city LIKE :city';
        $params[':city'] = '%' . $_GET['city'] . '%';
    }
    if (!empty($_GET['state'])) {
        $where[] = 'l.location_state = :state';
        $params[':state'] = $_GET['state'];
    }
    if (!empty($_GET['search'])) {
        $where[] = '(l.title LIKE :q OR l.description LIKE :q2)';
        $params[':q']  = '%' . $_GET['search'] . '%';
        $params[':q2'] = '%' . $_GET['search'] . '%';
    }
    if (!empty($_GET['min_price'])) {
        $where[] = 'l.price >= :minp';
        $params[':minp'] = (float)$_GET['min_price'];
    }
    if (!empty($_GET['max_price'])) {
        $where[] = 'l.price <= :maxp';
        $params[':maxp'] = (float)$_GET['max_price'];
    }
    if (!empty($_GET['user_id'])) {
        $where[] = 'l.user_id = :uid';
        $params[':uid'] = (int)$_GET['user_id'];
    }

    // Sorting
    $sortMap = [
        'newest'    => 'l.created_at DESC',
        'oldest'    => 'l.created_at ASC',
        'price_asc' => 'l.price ASC',
        'price_desc'=> 'l.price DESC',
        'popular'   => 'l.views DESC',
    ];
    $sort = $sortMap[$_GET['sort'] ?? 'newest'] ?? 'l.created_at DESC';

    // Pagination
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $whereSQL = implode(' AND ', $where);
    $sql = "SELECT l.*, u.name AS seller_name, u.avatar AS seller_avatar,
                   u.city AS seller_city, u.phone AS seller_phone,
                   (SELECT COUNT(*) FROM favorites f WHERE f.listing_id = l.id) AS favorite_count
            FROM listings l
            JOIN users u ON u.id = l.user_id
            WHERE {$whereSQL}
            ORDER BY {$sort}
            LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Count total for pagination
    $countSQL = "SELECT COUNT(*) FROM listings l JOIN users u ON u.id = l.user_id WHERE {$whereSQL}";
    $cstmt = $db->prepare($countSQL);
    foreach ($params as $k => $v) $cstmt->bindValue($k, $v);
    $cstmt->execute();
    $total = (int)$cstmt->fetchColumn();

    // Decode JSON fields
    foreach ($rows as &$row) {
        $row['images'] = json_decode($row['images'] ?? '[]', true) ?: [];
        $row['fields'] = json_decode($row['fields'] ?? '{}', true) ?: [];
        $row['price']  = (float)$row['price'];
        $row['views']  = (int)$row['views'];
        $row['id']     = (int)$row['id'];
        $row['user_id']= (int)$row['user_id'];
    }

    jsonOk([
        'listings'   => $rows,
        'total'      => $total,
        'page'       => $page,
        'limit'      => $limit,
        'total_pages'=> (int)ceil($total / $limit),
    ]);
}

// ─────────────────────────────────────────────────────────────────────
// GET ONE
// ─────────────────────────────────────────────────────────────────────
function getOne(int $id): void {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT l.*, u.name AS seller_name, u.email AS seller_email,
                u.phone AS seller_phone, u.avatar AS seller_avatar,
                u.city AS seller_city, u.state AS seller_state,
                u.created_at AS seller_since, u.is_verified AS seller_is_verified
         FROM listings l
         JOIN users u ON u.id = l.user_id
         WHERE l.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Listing not found.', 404);

    // Increment view count
    $db->prepare('UPDATE listings SET views = views + 1 WHERE id = ?')->execute([$id]);
    $row['views']++;

    $row['images'] = json_decode($row['images'] ?? '[]', true) ?: [];
    $row['fields'] = json_decode($row['fields'] ?? '{}', true) ?: [];
    $row['price']  = (float)$row['price'];
    $row['id']     = (int)$row['id'];

    // Similar listings
    $sim = $db->prepare(
        'SELECT id, title, price, images, location_city FROM listings
         WHERE category = ? AND id != ? AND status = ? LIMIT 6'
    );
    $sim->execute([$row['category'], $id, 'active']);
    $similar = $sim->fetchAll();
    foreach ($similar as &$s) {
        $imgs = json_decode($s['images'] ?? '[]', true) ?: [];
        $s['thumbnail'] = $imgs[0] ?? null;
        unset($s['images']);
    }
    $row['similar'] = $similar;

    jsonOk($row);
}

// ─────────────────────────────────────────────────────────────────────
// CREATE LISTING
// ─────────────────────────────────────────────────────────────────────
function createListing(): void {
    $user = requireAuth();
    $b    = getBody();

    $title    = clean($b['title']    ?? '');
    $category = clean($b['category'] ?? '');
    if (!$title)    jsonError('Title is required.');
    if (!$category) jsonError('Category is required.');

    $db = getDB();

    // Check quota
    $qStmt = $db->prepare('SELECT ads_remaining, expires_at FROM user_quotas WHERE user_id = ?');
    $qStmt->execute([(int)$user['id']]);
    $quota = $qStmt->fetch();
    if (!$quota || $quota['ads_remaining'] <= 0) {
        jsonError('You have no ad quota remaining. Please purchase a plan to post more ads.');
    }
    // Check quota expiry
    if ($quota['expires_at'] && strtotime($quota['expires_at']) < time()) {
        jsonError('Your ad quota has expired. Please renew your plan.');
    }

    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    $stmt = $db->prepare(
        'INSERT INTO listings
         (user_id, title, description, category, subcategory, price, price_type,
          location_city, location_state, location_area, images, fields, status, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$user['id'],
        $title,
        clean($b['description'] ?? ''),
        $category,
        clean($b['subcategory'] ?? ''),
        max(0, (float)($b['price'] ?? 0)),
        in_array($b['price_type'] ?? '', ['fixed','negotiable','free']) ? $b['price_type'] : 'fixed',
        clean($b['location_city']  ?? ''),
        clean($b['location_state'] ?? ''),
        clean($b['location_area']  ?? ''),
        json_encode($b['images'] ?? []),
        json_encode($b['fields'] ?? []),
        'active',  // auto-approve — change to 'pending_review' for moderation
        $expires,
    ]);
    $listingId = (int)$db->lastInsertId();

    // Deduct quota
    $db->prepare('UPDATE user_quotas SET ads_remaining = ads_remaining - 1 WHERE user_id = ?')
       ->execute([(int)$user['id']]);

    jsonOk(['id' => $listingId, 'message' => 'Listing posted successfully!'], 201);
}

// ─────────────────────────────────────────────────────────────────────
// UPDATE LISTING
// ─────────────────────────────────────────────────────────────────────
function updateListing(int $id): void {
    $user = requireAuth();
    $b    = getBody();
    $db   = getDB();

    // Check ownership (or admin)
    $stmt = $db->prepare('SELECT user_id FROM listings WHERE id = ?');
    $stmt->execute([$id]);
    $listing = $stmt->fetch();
    if (!$listing) jsonError('Listing not found.', 404);
    if ((int)$listing['user_id'] !== (int)$user['id'] && !in_array($user['role'], ['admin','super_admin'])) {
        jsonError('You can only edit your own listings.', 403);
    }

    // Build dynamic SET
    $allowed = ['title','description','category','subcategory','price','price_type',
                'location_city','location_state','location_area','images','fields','status'];
    $sets = []; $params = [];
    foreach ($allowed as $field) {
        if (!array_key_exists($field, $b)) continue;
        if (in_array($field, ['images','fields'])) {
            $sets[] = "{$field} = ?";
            $params[] = json_encode($b[$field]);
        } else {
            $sets[] = "{$field} = ?";
            $params[] = is_string($b[$field]) ? clean($b[$field]) : $b[$field];
        }
    }
    if (empty($sets)) jsonError('Nothing to update.');
    $params[] = $id;
    $db->prepare('UPDATE listings SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    jsonOk(['message' => 'Listing updated.']);
}

// ─────────────────────────────────────────────────────────────────────
// DELETE LISTING
// ─────────────────────────────────────────────────────────────────────
function deleteListing(int $id): void {
    $user = requireAuth();
    $db   = getDB();
    $stmt = $db->prepare('SELECT user_id, images FROM listings WHERE id = ?');
    $stmt->execute([$id]);
    $listing = $stmt->fetch();
    if (!$listing) jsonError('Listing not found.', 404);
    if ((int)$listing['user_id'] !== (int)$user['id'] && !in_array($user['role'], ['admin','super_admin'])) {
        jsonError('You can only delete your own listings.', 403);
    }
    // Delete images from disk
    $images = json_decode($listing['images'] ?? '[]', true) ?: [];
    foreach ($images as $imgPath) {
        $full = __DIR__ . '/../' . ltrim($imgPath, '/');
        if (file_exists($full)) @unlink($full);
    }
    $db->prepare('DELETE FROM listings WHERE id = ?')->execute([$id]);
    jsonOk(['message' => 'Listing deleted.']);
}
