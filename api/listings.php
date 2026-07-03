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
    if (isset($_GET['is_story'])) {
        $where[] = 'l.is_story = :is_story';
        $params[':is_story'] = (int)$_GET['is_story'];
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

    // Geo-Search (Near Me) logic
    $distanceSelect = '';
    $havingSQL = '';
    if (!empty($_GET['lat']) && !empty($_GET['lng'])) {
        $lat = (float)$_GET['lat'];
        $lng = (float)$_GET['lng'];
        $radius = !empty($_GET['radius']) ? (float)$_GET['radius'] : 10; // default 10km

        // Haversine formula
        $distanceSelect = ", (6371 * acos(cos(radians(:my_lat)) * cos(radians(l.lat)) * cos(radians(l.lng) - radians(:my_lng)) + sin(radians(:my_lat)) * sin(radians(l.lat)))) AS distance";
        $havingSQL = "HAVING distance <= :radius";
        
        $params[':my_lat'] = $lat;
        $params[':my_lng'] = $lng;
        $params[':radius'] = $radius;
        
        // Override sort to distance if 'sort' not explicitly passed
        if (empty($_GET['sort'])) {
            $sort = 'distance ASC, l.created_at DESC';
        }
    }

    // Pagination
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $whereSQL = implode(' AND ', $where);
    $sql = "SELECT l.*, u.name AS seller_name, u.avatar AS seller_avatar,
                   u.city AS seller_city, u.phone AS seller_phone, u.trusted_seller AS seller_trusted,
                   (SELECT COUNT(*) FROM favorites f WHERE f.listing_id = l.id) AS favorite_count,
                   (l.boosted = 1 AND l.boosted_until > NOW()) AS is_boosted_active
                   {$distanceSelect}
            FROM listings l
            JOIN users u ON u.id = l.user_id
            WHERE {$whereSQL}
            {$havingSQL}
            ORDER BY is_boosted_active DESC, {$sort}
            LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Count total for pagination
    if ($havingSQL !== '') {
        $countSQL = "SELECT COUNT(*) FROM (SELECT l.id {$distanceSelect} FROM listings l JOIN users u ON u.id = l.user_id WHERE {$whereSQL} {$havingSQL}) AS sub";
    } else {
        $countSQL = "SELECT COUNT(*) FROM listings l JOIN users u ON u.id = l.user_id WHERE {$whereSQL}";
    }
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
                u.created_at AS seller_since, u.is_verified AS seller_is_verified, u.trusted_seller AS seller_trusted
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
         WHERE category = ? AND id != ? AND status = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 6'
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
    // Charity posts go to pending_review; others too (unless auto approve is on)
    $status    = 'pending_review';
    if (!$isCharity) {
        $autoApproveStmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'auto_approve_listings'");
        $autoApprove = $autoApproveStmt->fetchColumn();
        if ($autoApprove === 'true' || $autoApprove === '1') {
            $status = 'active';
        }
    }

    // --- FRAUD DETECTION HEURISTIC ---
    // If a car or electronics is listed for suspiciously low (under $10), flag for review
    $price = max(0, (float)($b['price'] ?? 0));
    $lcCategory = strtolower($category);
    if (($lcCategory === 'cars' || str_contains($lcCategory, 'electronic')) && $price < 10.00 && $priceType !== 'free') {
        $status = 'pending_review'; // Force review
    }


    $expiresDays = $isCharity ? 12 : 30;  // Default
    if (!$isCharity) {
        $expiryStmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'listing_expiry_days'");
        $expiryVal = $expiryStmt->fetchColumn();
        if (is_numeric($expiryVal) && (int)$expiryVal > 0) {
            $expiresDays = (int)$expiryVal;
        }
    }
    $expires = date('Y-m-d H:i:s', strtotime("+{$expiresDays} days"));
    $lat = isset($b['lat']) && $b['lat'] !== '' ? (float)$b['lat'] : null;
    $lng = isset($b['lng']) && $b['lng'] !== '' ? (float)$b['lng'] : null;

    $isStory = !empty($b['is_story']) ? 1 : 0;
    $videoUrl = clean($b['video_url'] ?? '');

    $db->prepare(
        'INSERT INTO listings
         (user_id, title, description, category, subcategory, price, price_type,
          location_city, location_state, location_area, lat, lng, is_story, video_url, images, fields, status, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
        $lat,
        $lng,
        $isStory,
        $videoUrl,
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

    // Update user stats
    $db->exec("UPDATE users SET listing_count = listing_count + 1 WHERE id = $uid");

    // ─── TRIGGER NEIGHBORHOOD ALERTS ──────────────────────────────────────────
    if ($status === 'active') {
        try {
            // Find alerts matching category OR keyword
            $alertQuery = "SELECT a.*, u.fcm_token FROM search_alerts a JOIN users u ON u.id = a.user_id WHERE u.fcm_token IS NOT NULL AND u.fcm_token != '' AND a.user_id != ?";
            $alertParams = [$uid];
            $stmt = $db->prepare($alertQuery);
            $stmt->execute($alertParams);
            $alerts = $stmt->fetchAll();
            
            // Group tokens to avoid spamming the same user multiple times for the same listing
            $tokensToNotify = [];
            
            foreach ($alerts as $al) {
                $match = true;
                if ($al['category'] !== 'All' && strcasecmp($al['category'], $category) !== 0) $match = false;
                if ($match && !empty($al['keyword']) && stripos($title, $al['keyword']) === false && stripos($b['description'] ?? '', $al['keyword']) === false) $match = false;
                
                // Distance check if both alert and listing have geo coords
                if ($match && $lat && $lng && $al['lat'] && $al['lng']) {
                    $dist = 6371 * acos(cos(deg2rad($al['lat'])) * cos(deg2rad($lat)) * cos(deg2rad($lng) - deg2rad($al['lng'])) + sin(deg2rad($al['lat'])) * sin(deg2rad($lat)));
                    if ($dist > $al['radius']) $match = false;
                }
                
                if ($match) {
                    $tokensToNotify[$al['fcm_token']] = true;
                }
            }
            
            $tokens = array_keys($tokensToNotify);
            if (!empty($tokens)) {
                $serverKey = 'BBK_dVKcz9bNoAzAO8nwd552RmP1YKxLqOQ6gx6aXGjCoL5tSBDBvrg6qEKn87PmR0dhNVD26xKM_bFo2j-Rjko';
            }
        } catch(Exception $e) {}
    }

    jsonOk([
        'id'      => $listingId,
        'status'  => $status,
        'message' => ($status === 'pending_review') ? 'Listing submitted for review' : 'Listing published'
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
    $stmt = $db->prepare('SELECT user_id, status, expires_at FROM listings WHERE id = ?');
    $stmt->execute([$id]);
    $listing = $stmt->fetch();
    if (!$listing) jsonError('Listing not found.', 404);
    if ((int)$listing['user_id'] !== (int)$user['id'] && !in_array($user['role'], ['admin','super_admin'])) {
        jsonError('You can only edit your own listings.', 403);
    }

    // ── Security: block re-activating an expired listing via status field ──
    // Only admins can force-reactivate; regular users must renew via renewal.php
    if (!in_array($user['role'], ['admin','super_admin'])) {
        $requestedStatus = $b['status'] ?? null;
        if ($requestedStatus === 'active') {
            $expiresAt = $listing['expires_at'] ? strtotime($listing['expires_at']) : 0;
            if ($expiresAt > 0 && $expiresAt < time()) {
                jsonError('This listing has expired. Please renew it to make it active again.', 403);
            }
        }
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
                'location_city','location_state','location_area','lat','lng','images','fields','status','boosted',
                'is_story','video_url'];
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
    
    // --- PRICE DROP ALERT LOGIC ---
    if (isset($b['price'])) {
        $old_price = (float)($listing['price'] ?? 0);
        $new_price = (float)$b['price'];
        if ($old_price > 0 && $new_price < $old_price) {
            // Price dropped! Find users who favorited this
            $favStmt = $db->prepare("SELECT user_id FROM favorites WHERE listing_id = ?");
            $favStmt->execute([$id]);
            $favoriters = $favStmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($favoriters)) {
                // Ensure table exists
                try {
                    $db->exec("
                        CREATE TABLE IF NOT EXISTS user_notifications (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            user_id INT NOT NULL,
                            type VARCHAR(50) NOT NULL,
                            title VARCHAR(255) NOT NULL,
                            message TEXT NOT NULL,
                            link VARCHAR(255),
                            is_read TINYINT(1) DEFAULT 0,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        )
                    ");
                } catch (Exception $e) {}

                $title = $listing['title'] ?? 'A saved item';
                $msg = "Price Drop Alert: The price of {$title} dropped from Rs. " . number_format($old_price) . " to Rs. " . number_format($new_price) . "!";
                $notifStmt = $db->prepare("INSERT INTO user_notifications (user_id, type, title, message, link) VALUES (?, 'price_drop', 'Price Drop!', ?, ?)");
                foreach ($favoriters as $uid_fav) {
                    try {
                        $notifStmt->execute([$uid_fav, $msg, "Listing Detail.html?id={$id}"]);
                    } catch (Exception $e) {}
                }
            }
        }
    }
    
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

