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

if ($method === 'GET')  getProfile($id);
elseif ($method === 'PUT')  updateProfile();
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
