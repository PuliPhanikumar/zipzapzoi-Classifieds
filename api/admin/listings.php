<?php
/**
 * ZipZapZoi — Admin Listings API
 * GET    /api/admin/listings.php              → all listings (admin view, all statuses)
 * GET    /api/admin/listings.php?type=charity → charity/free pending listings
 * PUT    /api/admin/listings.php              → update status (approve/reject/flag)
 * DELETE /api/admin/listings.php?id=X        → hard delete
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../fcm_helper.php';
$admin  = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET')    listListings();
elseif ($method === 'PUT') updateListing($admin);
elseif ($method === 'DELETE') deleteListing($admin);
else jsonError('Method not allowed', 405);

// ── LIST LISTINGS (admin — all statuses) ─────────────────────────
function listListings(): void {
    $db     = getDB();
    $where  = ['1=1'];
    $params = [];

    $type = $_GET['type'] ?? '';
    if ($type === 'charity') {
        $where[] = "l.price_type = 'free'";
    }

    $search = $_GET['search'] ?? '';
    if ($search !== '') {
        $where[] = '(l.title LIKE :q OR u.name LIKE :q2 OR u.email LIKE :q3)';
        $params[':q']  = "%$search%";
        $params[':q2'] = "%$search%";
        $params[':q3'] = "%$search%";
    }

    $status = $_GET['status'] ?? '';
    if ($status !== '') {
        $where[] = 'l.status = :status';
        $params[':status'] = $status;
    }

    $whereSQL = implode(' AND ', $where);
    $stmt = $db->prepare(
        "SELECT l.id, l.title, l.category, l.subcategory, l.price, l.price_type,
                l.status, l.views, l.created_at, l.expires_at,
                u.id AS user_id, u.name AS seller_name, u.email AS seller_email
         FROM listings l
         JOIN users u ON u.id = l.user_id
         WHERE $whereSQL
         ORDER BY l.created_at DESC
         LIMIT 500"
    );
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id']      = (int)$r['id'];
        $r['user_id'] = (int)$r['user_id'];
        $r['price']   = (float)$r['price'];
        $r['views']   = (int)$r['views'];
    }
    jsonOk($rows);
}

// ── UPDATE LISTING STATUS ─────────────────────────────────────────
function updateListing(array $admin): void {
    $db      = getDB();
    $b       = getBody();
    $id      = (int)($b['id'] ?? 0);
    $status  = $b['status'] ?? '';
    $allowed = ['active','pending_review','rejected','sold','expired','draft'];

    if (!$id) jsonError('Listing id required.');
    if (!in_array($status, $allowed)) jsonError('Invalid status: ' . $status);

    if ($status === 'active') {
        // Fairness logic: If setting to active (approving), reset the creation clock to right now
        // so it bumps to the top of the feed and they get their full 30 days from this exact moment.
        $expiryStmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'listing_expiry_days'");
        $expiryVal = $expiryStmt->fetchColumn();
        $expiresDays = (is_numeric($expiryVal) && (int)$expiryVal > 0) ? (int)$expiryVal : 30;
        
        $db->prepare('UPDATE listings SET status=?, created_at=NOW(), updated_at=NOW(), expires_at=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?')
           ->execute([$status, $expiresDays, $id]);

        // Send instant notification and push to the seller
        try {
            $stmtListing = $db->prepare("SELECT l.user_id, l.title, u.fcm_token FROM listings l JOIN users u ON u.id = l.user_id WHERE l.id = ?");
            $stmtListing->execute([$id]);
            $lData = $stmtListing->fetch();
            if ($lData) {
                $uid    = (int)$lData['user_id'];
                $ltitle = $lData['title'];
                $msg    = "Your listing \"$ltitle\" is now live and visible to buyers across India!";
                $db->prepare("INSERT INTO user_notifications (user_id, type, title, message, link) VALUES (?, 'listing_approved', 'Listing Approved 🎉', ?, ?)")
                   ->execute([$uid, $msg, "listing/$id"]);
                if (!empty($lData['fcm_token'])) {
                    sendFcmPush($lData['fcm_token'], 'Listing Approved 🎉', $msg, ['listing_id' => (string)$id, 'type' => 'listing_approved']);
                }
            }
        } catch (\Throwable $ne) {}
    } else {
        $db->prepare('UPDATE listings SET status=?, updated_at=NOW() WHERE id=?')->execute([$status, $id]);

        if ($status === 'rejected') {
            try {
                $stmtListing = $db->prepare("SELECT l.user_id, l.title, u.fcm_token FROM listings l JOIN users u ON u.id = l.user_id WHERE l.id = ?");
                $stmtListing->execute([$id]);
                $lData = $stmtListing->fetch();
                if ($lData) {
                    $uid    = (int)$lData['user_id'];
                    $ltitle = $lData['title'];
                    $msg    = "Your listing \"$ltitle\" was reviewed and rejected. Please review our posting guidelines.";
                    $db->prepare("INSERT INTO user_notifications (user_id, type, title, message, link) VALUES (?, 'listing_rejected', 'Listing Review Update', ?, ?)")
                       ->execute([$uid, $msg, "my_listings"]);
                    if (!empty($lData['fcm_token'])) {
                        sendFcmPush($lData['fcm_token'], 'Listing Review Update', $msg, ['listing_id' => (string)$id, 'type' => 'listing_rejected']);
                    }
                }
            } catch (\Throwable $ne) {}
        }
    }

    $actionMap = [
        'active'   => 'APPROVE_LISTING',
        'rejected' => 'REJECT_LISTING',
        'expired'  => 'EXPIRE_LISTING',
        'sold'     => 'MARK_SOLD_LISTING',
    ];
    adminLog($admin, $actionMap[$status] ?? 'UPDATE_LISTING', "Listing ID: $id → $status");
    jsonOk(['message' => "Listing $id set to $status."]);
}

// ── DELETE LISTING ────────────────────────────────────────────────
function deleteListing(array $admin): void {
    $db = getDB();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('id required.');

    $db->prepare('DELETE FROM listings WHERE id=?')->execute([$id]);
    adminLog($admin, 'DELETE_LISTING', "Listing ID: $id");
    jsonOk(['message' => "Listing $id deleted."]);
}

if (!function_exists('adminLog')) {
    function adminLog(array $admin, string $action, string $detail = ''): void {
        try {
            getDB()->prepare(
                'INSERT INTO admin_logs (admin_id, admin_name, action, detail) VALUES (?,?,?,?)'
            )->execute([(int)$admin['id'], $admin['name'], $action, $detail]);
        } catch (\Throwable $e) {}
    }
}
