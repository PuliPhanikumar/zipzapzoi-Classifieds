<?php
/**
 * ZipZapZoi — Admin Users API
 * GET  /api/admin/users.php                     → list users (search)
 * GET  /api/admin/users.php?action=verifications → pending verification requests
 * PUT  /api/admin/users.php                     → ban/unban/verify/unverify/role
 * POST /api/admin/users.php                     → create user
 */
require_once __DIR__ . '/../config.php';
$admin  = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'verifications') getVerifications();
elseif ($method === 'GET')  listUsers();
elseif ($method === 'PUT')  updateUser($admin);
elseif ($method === 'POST') createUser($admin);
else jsonError('Method not allowed', 405);

// ── LIST USERS ────────────────────────────────────────────────────
function listUsers(): void {
    $db     = getDB();
    $search = '%' . ($_GET['search'] ?? '') . '%';
    $stmt   = $db->prepare(
        "SELECT u.id, u.name, u.email, u.phone, u.role, u.is_verified, u.is_active,
                u.city, u.state, u.created_at,
                (SELECT COUNT(*) FROM listings l WHERE l.user_id = u.id AND l.status='active') AS active_listings
         FROM users u
         WHERE (u.name LIKE :q OR u.email LIKE :q2)
         ORDER BY u.created_at DESC
         LIMIT 200"
    );
    $stmt->bindValue(':q',  $search);
    $stmt->bindValue(':q2', $search);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id']           = (int)$r['id'];
        $r['is_verified']  = (bool)$r['is_verified'];
        $r['is_active']    = (bool)$r['is_active'];
        $r['active_listings'] = (int)$r['active_listings'];
    }
    jsonOk($rows);
}

// ── PENDING VERIFICATIONS ─────────────────────────────────────────
function getVerifications(): void {
    $db   = getDB();
    // Users who requested verification = is_verified = 0 and is_active = 1
    // We'll treat unverified active users as "pending"
    $stmt = $db->query(
        "SELECT id, name, email, created_at FROM users
         WHERE is_verified = 0 AND is_active = 1
         ORDER BY created_at DESC LIMIT 50"
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r['id'] = (int)$r['id'];
    jsonOk($rows);
}

// ── UPDATE USER ───────────────────────────────────────────────────
function updateUser(array $admin): void {
    $db  = getDB();
    $b   = getBody();
    $id  = (int)($b['id'] ?? 0);
    $act = $b['action'] ?? '';

    if (!$id) jsonError('User id required.');

    switch ($act) {
        case 'ban':
            $db->prepare('UPDATE users SET is_active=0 WHERE id=?')->execute([$id]);
            adminLog($admin, 'BAN_USER', "User ID: $id");
            jsonOk(['message' => 'User banned.']);

        case 'unban':
            $db->prepare('UPDATE users SET is_active=1 WHERE id=?')->execute([$id]);
            adminLog($admin, 'UNBAN_USER', "User ID: $id");
            jsonOk(['message' => 'User unbanned.']);

        case 'verify':
            $db->prepare('UPDATE users SET is_verified=1 WHERE id=?')->execute([$id]);
            adminLog($admin, 'VERIFY_USER', "User ID: $id");
            jsonOk(['message' => 'User verified.']);

        case 'unverify':
            $db->prepare('UPDATE users SET is_verified=0 WHERE id=?')->execute([$id]);
            adminLog($admin, 'UNVERIFY_USER', "User ID: $id");
            jsonOk(['message' => 'Verification revoked.']);

        case 'role':
            $role = $b['role'] ?? 'user';
            if (!in_array($role, ['user','admin','super_admin'])) jsonError('Invalid role.');
            $db->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role, $id]);
            adminLog($admin, 'CHANGE_ROLE', "User ID: $id → $role");
            jsonOk(['message' => "Role changed to $role."]);

        default:
            // General edit: name, email, phone
            $name  = clean($b['name']  ?? '');
            $email = clean($b['email'] ?? '');
            $phone = clean($b['phone'] ?? '');
            $role  = in_array($b['role']??'', ['user','admin','super_admin']) ? $b['role'] : null;

            if (!$name || !$email) jsonError('Name and email required.');
            $sql = 'UPDATE users SET name=?, email=?, phone=?';
            $params = [$name, $email, $phone];
            if ($role) { $sql .= ', role=?'; $params[] = $role; }
            $sql .= ' WHERE id=?'; $params[] = $id;
            $db->prepare($sql)->execute($params);
            adminLog($admin, 'EDIT_USER', "User ID: $id ($email)");
            jsonOk(['message' => 'User updated.']);
    }
}

// ── CREATE USER ───────────────────────────────────────────────────
function createUser(array $admin): void {
    $db    = getDB();
    $b     = getBody();
    $name  = clean($b['name']  ?? '');
    $email = clean($b['email'] ?? '');
    $role  = in_array($b['role']??'user', ['user','admin','super_admin']) ? $b['role'] : 'user';

    if (!$name || !$email || !validateEmail($email)) jsonError('Valid name and email required.');

    // Generate a random temp password
    $pass   = password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT);
    try {
        $db->prepare(
            'INSERT INTO users (name, email, password_hash, role, is_verified)
             VALUES (?, ?, ?, ?, 1)'
        )->execute([$name, $email, $pass, $role]);
        $newId = (int)$db->lastInsertId();
        adminLog($admin, 'CREATE_USER', "New user: $email (ID: $newId)");
        jsonOk(['id' => $newId, 'message' => 'User created.'], 201);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') jsonError('Email already exists.');
        jsonError('Database error.');
    }
}

// ── HELPER ────────────────────────────────────────────────────────
function adminLog(array $admin, string $action, string $detail = ''): void {
    try {
        getDB()->prepare(
            'INSERT INTO admin_logs (admin_id, admin_name, action, detail) VALUES (?,?,?,?)'
        )->execute([(int)$admin['id'], $admin['name'], $action, $detail]);
    } catch (\Throwable $e) {}
}
