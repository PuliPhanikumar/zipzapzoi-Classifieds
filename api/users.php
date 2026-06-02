<?php
/**
 * ZipZapZoi Classifieds — Users API
 * GET  /api/users.php?id=me   → my profile + stats
 * GET  /api/users.php?id=X    → public profile of user X
 * PUT  /api/users.php         → update my profile
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = $_GET['id'] ?? 'me';
$action = $_GET['action'] ?? '';

if ($method === 'GET')        getProfile($id);
elseif ($method === 'PUT')    updateProfile();
elseif ($method === 'POST') {
    if ($action === 'notify') handleNotify();
    elseif ($action === 'report') handleReport();
    elseif ($action === 'review') handleReview();
    else jsonError('Unknown action', 400);
}
else jsonError('Method not allowed', 405);


function getProfile(string $id): void {
    $user = getCurrentUser();
    $db   = getDB();

    $userId = ($id === 'me') ? (int)($user['id'] ?? 0) : (int)$id;
    if (!$userId) jsonError('Not authenticated or invalid user ID.', 401);

    $stmt = $db->prepare(
        'SELECT id, name, email, phone, role, avatar, city, state, is_verified, created_at FROM users WHERE id = ? AND is_active = 1'
    );
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
    if (!$profile) jsonError('User not found.', 404);

    // Active listings count
    $lStmt = $db->prepare("SELECT COUNT(*) FROM listings WHERE user_id = ? AND status = 'active'");
    $lStmt->execute([$userId]);
    $profile['active_listings'] = (int)$lStmt->fetchColumn();

    // Quota info (only for self)
    if ($id === 'me' && $user) {
        $qStmt = $db->prepare('SELECT * FROM user_quotas WHERE user_id = ?');
        $qStmt->execute([$userId]);
        $quota = $qStmt->fetch();
        $profile['quota'] = $quota ?: ['ads_remaining' => 0, 'plan_name' => 'No Plan'];
    }

    $profile['id']          = (int)$profile['id'];
    $profile['is_verified'] = (bool)$profile['is_verified'];
    jsonOk($profile);
}

function updateProfile(): void {
    $user = requireAuth();
    $b    = getBody();
    $db   = getDB();

    $allowed = ['name','phone','city','state','avatar'];
    $sets = []; $params = [];
    foreach ($allowed as $f) {
        if (!array_key_exists($f, $b)) continue;
        $sets[]   = "{$f} = ?";
        $params[] = clean((string)$b[$f]);
    }
    if (empty($sets)) jsonError('Nothing to update.');
    $params[] = (int)$user['id'];
    $db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

    // Return updated user
    $stmt = $db->prepare('SELECT id, name, email, phone, role, avatar, city, state, is_verified FROM users WHERE id = ?');
    $stmt->execute([(int)$user['id']]);
    jsonOk($stmt->fetch());
}

// ─────────────────────────────────────────────────────────────────────
// POST ?action=notify — Save "Notify Me" email for a coming-soon module
// ─────────────────────────────────────────────────────────────────────
function handleNotify(): void {
    $b     = getBody();
    $email = filter_var(trim($b['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    if (!$email) jsonError('Valid email required.');

    $module = clean($b['module'] ?? 'unknown');
    $db     = getDB();

    try {
        // Create table if not exists (self-healing)
        $db->exec(
            'CREATE TABLE IF NOT EXISTS notify_signups (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                email      VARCHAR(255) NOT NULL,
                module     VARCHAR(100) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_email_module (email, module)
            )'
        );
        $db->prepare(
            'INSERT IGNORE INTO notify_signups (email, module) VALUES (?, ?)'
        )->execute([$email, $module]);
    } catch (\Exception $e) {
        // Table may already exist or DB issue — still return success to frontend
        error_log('notify_signups error: ' . $e->getMessage());
    }

    jsonOk(['message' => 'You are on the notify list!']);
}

// ─────────────────────────────────────────────────────────────────────
// POST ?action=report — Report a user (requires login)
// ─────────────────────────────────────────────────────────────────────
function handleReport(): void {
    $user = requireAuth();
    $b    = getBody();

    $reportedId = (int)($b['reportedBy'] ?? $b['reported_user_id'] ?? 0);
    $reason     = clean($b['reason']  ?? 'Other');
    $details    = clean($b['details'] ?? '');
    $db         = getDB();

    try {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS user_reports (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                reported_by     INT NOT NULL,
                reported_user   INT,
                reason          VARCHAR(200),
                details         TEXT,
                status          VARCHAR(50) DEFAULT \'pending\',
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $db->prepare(
            'INSERT INTO user_reports (reported_by, reported_user, reason, details) VALUES (?, ?, ?, ?)'
        )->execute([(int)$user['id'], $reportedId ?: null, $reason, $details]);
    } catch (\Exception $e) {
        error_log('user_reports error: ' . $e->getMessage());
    }

    jsonOk(['message' => 'Report submitted. Thank you.']);
}

// ─────────────────────────────────────────────────────────────────────
// POST ?action=review — Submit a seller review (requires login)
// ─────────────────────────────────────────────────────────────────────
function handleReview(): void {
    $user = requireAuth();
    $b    = getBody();

    $sellerId = (int)($b['seller_id'] ?? 0);
    $rating   = max(1, min(5, (int)($b['rating'] ?? 0)));
    $text     = clean($b['text'] ?? '');

    if (!$sellerId) jsonError('seller_id required.');
    if (!$rating)   jsonError('Rating (1-5) required.');
    if (!$text)     jsonError('Review text required.');
    if ($sellerId === (int)$user['id']) jsonError('You cannot review yourself.');

    $db = getDB();

    try {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS user_reviews (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                seller_id   INT NOT NULL,
                reviewer_id INT NOT NULL,
                rating      TINYINT NOT NULL,
                review_text TEXT,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY one_review_per_seller (seller_id, reviewer_id)
            )'
        );
        $db->prepare(
            'INSERT INTO user_reviews (seller_id, reviewer_id, rating, review_text)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), review_text = VALUES(review_text)'
        )->execute([$sellerId, (int)$user['id'], $rating, $text]);
    } catch (\Exception $e) {
        error_log('user_reviews error: ' . $e->getMessage());
        jsonError('Failed to save review. Please try again.');
    }

    jsonOk(['message' => 'Review posted successfully!']);
}
