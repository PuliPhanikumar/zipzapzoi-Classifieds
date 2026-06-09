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
    case 'GET':
        if (isset($_GET['action']) && $_GET['action'] === 'stats') getStats();
        elseif ($id) getOne($id);
        else getAll();
        break;
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
    $requestedStatus = $_GET['status'] ?? 'active';

    // status=all: show all statuses for the authenticated owner viewing their own listings
    // This is used by Seller Dashboard to show pending, active, rejected etc.
    $ownerId = null;
    if ($requestedStatus === 'all') {
        $currentUser = getCurrentUser();
        $ownerId = $currentUser ? (int)$currentUser['id'] : null;
    }

    if ($requestedStatus === 'all' && $ownerId) {
        // No status filter — owner sees all their listings regardless of status
        $where  = ['l.user_id = :owner_uid'];
        $params = [':owner_uid' => $ownerId];
    } else {
        $where  = ['l.status = :status'];
        $params = [':status' => $requestedStatus];
        // Auto-filter: don't show expired listings in public active search
        if ($requestedStatus === 'active') {
            $where[] = '(l.expires_at IS NULL OR l.expires_at > NOW())';
        }
    }

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
    $uid = (int)$user['id'];

    // ── Duplicate Detection ──────────────────────────────────────────
    if (!empty($user['phone'])) {
        $dupStmt = $db->prepare("
            SELECT l.id 
            FROM listings l
            JOIN users u ON l.user_id = u.id
            WHERE l.title = ? 
              AND u.phone = ?
              AND l.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $dupStmt->execute([$title, $user['phone']]);
    } else {
        $dupStmt = $db->prepare("SELECT id FROM listings WHERE user_id = ? AND title = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $dupStmt->execute([$uid, $title]);
    }
    
    if ($dupStmt->fetch()) {
        jsonError('Duplicate ad detected. An ad with this title was already posted from your phone number recently.');
    }

    // ── Ensure uploads directory exists ──────────────────────────────
    $uploadDir = UPLOAD_DIR;
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    // ── Auto-create quota row for new users (3 free ads to start) ───
    $qStmt = $db->prepare(
        'SELECT ads_remaining, expires_at, monthly_free_granted FROM user_quotas WHERE user_id = ?'
    );
    $qStmt->execute([$uid]);
    $quota = $qStmt->fetch();

    if (!$quota) {
        // Brand-new user: give them 3 free starter ads (no expiry)
        $db->prepare(
            'INSERT INTO user_quotas (user_id, ads_remaining, total_granted, plan_id, expires_at, monthly_free_granted)
             VALUES (?, 3, 3, "free", NULL, NULL)
             ON DUPLICATE KEY UPDATE user_id = user_id'
        )->execute([$uid]);
        $qStmt->execute([$uid]);
        $quota = $qStmt->fetch();
    }

    // Monthly free ad grant: 1 free ad per calendar month per account
    $thisMonth = date('Y-m');
    if ($quota && ($quota['monthly_free_granted'] ?? '') !== $thisMonth) {
        $db->prepare(
            'UPDATE user_quotas
             SET ads_remaining = ads_remaining + 1,
                 monthly_free_granted = ?
             WHERE user_id = ?'
        )->execute([$thisMonth, $uid]);
        $quota['ads_remaining'] = ($quota['ads_remaining'] ?? 0) + 1;
    }

    if (!$quota) {
        jsonError('Could not create quota. Please contact support.');
    }

    // Check quota expiry (plan must be active to use remaining ads)
    // NULL expires_at means unlimited (admin / no expiry)
    if (!empty($quota['expires_at']) && strtotime($quota['expires_at']) < time()) {
        jsonError('Your ad plan has expired. Please purchase a new plan to continue posting.');
    }

    if ((int)$quota['ads_remaining'] <= 0) {
        jsonError('You have 0 ads remaining in your plan. Purchase a plan to post more ads.');
    }

    // ── Handle images: base64 data URLs → save to disk ───────────────
    $imageUrls = [];
    $rawImages = $b['images'] ?? [];
    if (is_array($rawImages)) {
        $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
        $maxBytes = MAX_UPLOAD_MB * 1024 * 1024;
        foreach (array_slice($rawImages, 0, 10) as $img) {
            if (!is_string($img)) continue;
            if (str_starts_with($img, 'data:image/')) {
                // Base64 data URL → decode and save
                if (preg_match('/^data:(image\/[a-z]+);base64,(.+)$/s', $img, $m)) {
                    $mime     = $m[1];
                    $decoded  = base64_decode($m[2], true);
                    if (!$decoded || strlen($decoded) > $maxBytes) continue;
                    if (!in_array($mime, $allowed)) continue;
                    $ext  = explode('/', $mime)[1];
                    $ext  = ($ext === 'jpeg') ? 'jpg' : $ext;
                    $name = 'lst_' . $uid . '_' . uniqid() . '.' . $ext;
                    $path = UPLOAD_DIR . $name;
                    if (file_put_contents($path, $decoded) !== false) {
                        $imageUrls[] = UPLOAD_URL . $name;
                    }
                }
            } elseif (str_starts_with($img, '/') || str_starts_with($img, 'http')) {
                // Already a URL
                $imageUrls[] = $img;
            }
        }
    }

    // ── Insert listing ────────────────────────────────────────────
    $isCharity = ($b['price_type'] ?? '') === 'free' || ($category === 'Charity & Donations');
    $priceType = in_array($b['price_type'] ?? '', ['fixed','negotiable','free'])
               ? $b['price_type'] : 'fixed';
    $condition = in_array($b['condition'] ?? '', ['new','used','refurbished'])
               ? $b['condition'] : null;
    // Charity posts go to pending_review; others too (admin approval required)
    $status    = 'pending_review';

    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    $db->prepare(
        'INSERT INTO listings
         (user_id, title, description, category, subcategory, price, price_type,
          location_city, location_state, location_area, images, fields, status, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $uid,
        $title,
        clean($b['description'] ?? ''),
        $category,
        clean($b['subcategory'] ?? ''),
        max(0, (float)($b['price'] ?? 0)),
        $priceType,
        clean($b['location_city']  ?? ''),
        clean($b['location_state'] ?? ''),
        clean($b['location_area']  ?? ''),
        json_encode($imageUrls),
        json_encode($b['fields'] ?? []),
        $status,
        $expires,
    ]);
    $listingId = (int)$db->lastInsertId();

    // ── Deduct quota (charity posts don't use quota) ──────────────
    if (!$isCharity) {
        $db->prepare(
            'UPDATE user_quotas SET ads_remaining = ads_remaining - 1 WHERE user_id = ? AND ads_remaining > 0'
        )->execute([$uid]);
    }

    jsonOk([
        'id'      => $listingId,
        'status'  => $status,
        'message' => $isCharity
            ? 'Charity post submitted! Our team will verify and approve it within 24 hours.'
            : 'Ad submitted for review! It will be live once approved by admin.',
    ], 201);
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

    $uid = (int)$user['id'];

    // ── Process images: convert any base64 data URLs → disk files ──────
    $processedImages = null;
    if (array_key_exists('images', $b) && is_array($b['images'])) {
        $allowed  = ['image/jpeg','image/png','image/webp','image/gif'];
        $maxBytes = MAX_UPLOAD_MB * 1024 * 1024;
        $processedImages = [];
        foreach (array_slice($b['images'], 0, 10) as $img) {
            if (!is_string($img)) continue;
            if (str_starts_with($img, 'data:image/')) {
                // Base64 data URL → decode and save to disk
                if (preg_match('/^data:(image\/[a-z]+);base64,(.+)$/s', $img, $m)) {
                    $mime    = $m[1];
                    $decoded = base64_decode($m[2], true);
                    if (!$decoded || strlen($decoded) > $maxBytes) continue;
                    if (!in_array($mime, $allowed)) continue;
                    $ext  = explode('/', $mime)[1];
                    $ext  = ($ext === 'jpeg') ? 'jpg' : $ext;
                    $name = 'lst_' . $uid . '_' . uniqid() . '.' . $ext;
                    $path = UPLOAD_DIR . $name;
                    if (file_put_contents($path, $decoded) !== false) {
                        $processedImages[] = UPLOAD_URL . $name;
                    }
                }
            } elseif (str_starts_with($img, '/') || str_starts_with($img, 'http')) {
                // Already a stored URL — keep it as-is
                $processedImages[] = $img;
            }
        }
    }

    // Build dynamic SET
    $allowed = ['title','description','category','subcategory','price','price_type',
                'location_city','location_state','location_area','images','fields','status'];
    $sets = []; $params = [];
    foreach ($allowed as $field) {
        if (!array_key_exists($field, $b)) continue;
        if ($field === 'images') {
            // Use the processed images array (base64 already converted to URLs)
            if ($processedImages !== null) {
                $sets[]   = "images = ?";
                $params[] = json_encode($processedImages);
            }
        } elseif ($field === 'fields') {
            $sets[]   = "fields = ?";
            $params[] = json_encode($b[$field]);
        } else {
            $sets[]   = "{$field} = ?";
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

// ─────────────────────────────────────────────────────────────────────
// GET STATS — public endpoint for Landing Page hero counters
// GET /api/listings.php?action=stats
// Returns: total_active, total_listings, total_sellers, total_users
// ─────────────────────────────────────────────────────────────────────
function getStats(): void {
    $db = getDB();

    // Total active listings (not expired)
    $activeStmt = $db->query(
        "SELECT COUNT(*) FROM listings
         WHERE status = 'active' AND (expires_at IS NULL OR expires_at > NOW())"
    );
    $totalActive = (int)$activeStmt->fetchColumn();

    // Total listings ever posted
    $totalListings = (int)$db->query('SELECT COUNT(*) FROM listings')->fetchColumn();

    // Total unique sellers (users who posted at least 1 listing)
    $totalSellers = (int)$db->query('SELECT COUNT(DISTINCT user_id) FROM listings')->fetchColumn();

    // Total registered active users
    $totalUsers = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();

    jsonOk([
        'total_active'   => $totalActive,
        'total_listings' => $totalListings,
        'total_sellers'  => $totalSellers,
        'total_users'    => $totalUsers,
    ]);
}
