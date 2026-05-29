<?php
/**
 * ZipZapZoi Classifieds — Auth API
 * Actions: register | verify_otp | login | logout | me
 *          forgot_password | reset_password | request_sensitive_otp | verify_sensitive_otp
 */
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body   = getBody();

switch ($action) {
    case 'register':             handleRegister($body);          break;
    case 'verify_otp':           handleVerifyOtp($body);         break;
    case 'login':                handleLogin($body);             break;
    case 'logout':               handleLogout();                 break;
    case 'me':                   handleMe();                     break;
    case 'forgot_password':      handleForgotPassword($body);    break;
    case 'reset_password':       handleResetPassword($body);     break;
    case 'request_sensitive_otp':handleSensitiveOtp($body);      break;
    case 'verify_sensitive_otp': handleVerifySensitiveOtp($body);break;
    default:                     jsonError('Unknown action', 400);
}

// ─────────────────────────────────────────────────────────────────────
// REGISTER — Step 1: validate + send OTP (don't create user yet)
// ─────────────────────────────────────────────────────────────────────
function handleRegister(array $b): void {
    $name     = clean($b['name']     ?? '');
    $email    = strtolower(trim($b['email']    ?? ''));
    $phone    = preg_replace('/\D/', '', $b['phone'] ?? '');
    $password = $b['password'] ?? '';

    if (!$name)                         jsonError('Full name is required.');
    if (!validateEmail($email))         jsonError('Invalid email address.');
    if (strlen($phone) !== 10)          jsonError('Phone must be 10 digits.');
    if (strlen($password) < 6)          jsonError('Password must be at least 6 characters.');

    $db = getDB();
    // Check duplicate email
    $exists = $db->prepare('SELECT id FROM users WHERE email = ?');
    $exists->execute([$email]);
    if ($exists->fetch()) jsonError('An account with this email already exists.');

    // Store pending registration data in otp_tokens meta
    $otp     = generateOtp();
    $expiry  = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $meta    = json_encode(['name' => $name, 'phone' => $phone, 'password' => password_hash($password, PASSWORD_DEFAULT)]);

    // Clear old OTPs for this email
    $db->prepare("DELETE FROM otp_tokens WHERE email = ? AND action = 'register'")->execute([$email]);
    $db->prepare(
        'INSERT INTO otp_tokens (email, otp_code, action, expires_at, meta) VALUES (?, ?, ?, ?, ?)'
    )->execute([$email, $otp, 'register', $expiry, $meta]);

    // Send OTP via EmailJS (server-side via cURL if available, else instruct frontend)
    // NOTE: EmailJS is client-side only. OTP is sent by the browser via EmailJS.
    // We return the OTP expiry so the frontend can show a countdown.
    // The actual email is sent by the frontend after this endpoint returns success.
    jsonOk([
        'message'    => 'OTP generated. Frontend should send it via EmailJS.',
        'otp'        => $otp,       // Frontend sends this in the email
        'expires_in' => 900,        // 15 minutes in seconds
    ]);
}

// ─────────────────────────────────────────────────────────────────────
// VERIFY OTP — Step 2 of register: check OTP and create account
// ─────────────────────────────────────────────────────────────────────
function handleVerifyOtp(array $b): void {
    $email = strtolower(trim($b['email'] ?? ''));
    $otp   = trim($b['otp']   ?? '');
    $action = $b['action'] ?? 'register'; // 'register' or 'login'

    if (!$email || !$otp) jsonError('Email and OTP are required.');

    $db  = getDB();
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare(
        'SELECT * FROM otp_tokens WHERE email = ? AND otp_code = ? AND action = ? AND expires_at > ? AND used = 0'
    );
    $stmt->execute([$email, $otp, $action, $now]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Invalid or expired OTP. Please try again.');

    // Mark OTP as used
    $db->prepare('UPDATE otp_tokens SET used = 1 WHERE id = ?')->execute([$row['id']]);

    if ($action === 'register') {
        $meta = json_decode($row['meta'], true);
        // Create user account
        $db->prepare(
            'INSERT INTO users (name, email, phone, password_hash, role, is_verified)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$meta['name'], $email, $meta['phone'], $meta['password'], 'user']);
        $userId = (int) $db->lastInsertId();

        // Grant new-user free quota (6 ads)
        $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
        $db->prepare(
            'INSERT INTO user_quotas (user_id, ads_remaining, total_granted, plan_id, plan_name, expires_at, new_user_free_granted)
             VALUES (?, 6, 6, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
               ads_remaining = ads_remaining + 6, total_granted = total_granted + 6,
               plan_id = VALUES(plan_id), plan_name = VALUES(plan_name), expires_at = VALUES(expires_at)'
        )->execute([$userId, 'new_user_free', 'New User Free (6 Ads)', $expiry]);

        $token = createSession($userId);
        $user  = getUserById($userId);
        jsonOk(['user' => $user, 'token' => $token, 'message' => 'Account created successfully! Welcome to ZipZapZoi.'], 201);

    } elseif ($action === 'login') {
        $stmt2 = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
        $stmt2->execute([$email]);
        $user = $stmt2->fetch();
        if (!$user) jsonError('Account not found or deactivated.', 404);
        $token = createSession((int)$user['id']);
        jsonOk(['user' => sanitizeUser($user), 'token' => $token]);
    }
}

// ─────────────────────────────────────────────────────────────────────
// LOGIN — Step 1: check credentials, send OTP
// ─────────────────────────────────────────────────────────────────────
function handleLogin(array $b): void {
    $email    = strtolower(trim($b['email']    ?? ''));
    $password = $b['password'] ?? '';

    if (!validateEmail($email)) jsonError('Invalid email address.');
    if (!$password)             jsonError('Password is required.');

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonError('Invalid email or password.', 401);
    }

    // Generate & store OTP for login
    $otp    = generateOtp();
    $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $db->prepare("DELETE FROM otp_tokens WHERE email = ? AND action = 'login'")->execute([$email]);
    $db->prepare('INSERT INTO otp_tokens (email, otp_code, action, expires_at) VALUES (?, ?, ?, ?)')
       ->execute([$email, $otp, 'login', $expiry]);

    jsonOk([
        'message'    => 'OTP generated.',
        'otp'        => $otp,
        'expires_in' => 600,
        'to_name'    => $user['name'],
        'to_email'   => $email,
    ]);
}

// ─────────────────────────────────────────────────────────────────────
// LOGOUT
// ─────────────────────────────────────────────────────────────────────
function handleLogout(): void {
    destroySession();
    jsonOk(['message' => 'Logged out successfully.']);
}

// ─────────────────────────────────────────────────────────────────────
// ME — return current logged-in user
// ─────────────────────────────────────────────────────────────────────
function handleMe(): void {
    $user = getCurrentUser();
    if (!$user) jsonError('Not authenticated.', 401);
    // Fetch full user data
    $full = getUserById((int)$user['id']);
    jsonOk($full);
}

// ─────────────────────────────────────────────────────────────────────
// FORGOT PASSWORD — generate reset token
// ─────────────────────────────────────────────────────────────────────
function handleForgotPassword(array $b): void {
    $email = strtolower(trim($b['email'] ?? ''));
    if (!validateEmail($email)) jsonError('Invalid email address.');

    $db   = getDB();
    $stmt = $db->prepare('SELECT id, name FROM users WHERE email = ? AND is_active = 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Always return success (don't reveal if email exists)
    if (!$user) {
        jsonOk(['message' => 'If that email exists, a reset link has been sent.']);
    }

    $token  = bin2hex(random_bytes(32));
    $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $db->prepare('DELETE FROM reset_tokens WHERE user_id = ?')->execute([$user['id']]);
    $db->prepare('INSERT INTO reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)')
       ->execute([$user['id'], $token, $expiry]);

    $resetLink = 'https://www.zipzapzoi.com/reset-password.html?token=' . $token;
    jsonOk([
        'message'    => 'Reset link generated.',
        'reset_link' => $resetLink,     // Frontend sends this via EmailJS
        'to_name'    => $user['name'],
        'to_email'   => $email,
    ]);
}

// ─────────────────────────────────────────────────────────────────────
// RESET PASSWORD
// ─────────────────────────────────────────────────────────────────────
function handleResetPassword(array $b): void {
    $token    = trim($b['token']    ?? '');
    $password = $b['new_password'] ?? '';

    if (!$token)               jsonError('Reset token is missing.');
    if (strlen($password) < 6) jsonError('Password must be at least 6 characters.');

    $db  = getDB();
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare('SELECT * FROM reset_tokens WHERE token = ? AND expires_at > ? AND used = 0');
    $stmt->execute([$token, $now]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Invalid or expired reset link. Please request a new one.');

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $row['user_id']]);
    $db->prepare('UPDATE reset_tokens SET used = 1 WHERE id = ?')->execute([$row['id']]);
    // Invalidate all sessions for security
    $db->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$row['user_id']]);
    jsonOk(['message' => 'Password reset successfully. Please log in with your new password.']);
}

// ─────────────────────────────────────────────────────────────────────
// SENSITIVE ACTION OTP — for password/email change etc.
// ─────────────────────────────────────────────────────────────────────
function handleSensitiveOtp(array $b): void {
    $user = requireAuth();
    $otp    = generateOtp();
    $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $db = getDB();
    $db->prepare("DELETE FROM otp_tokens WHERE email = ? AND action = 'sensitive_action'")->execute([$user['email']]);
    $db->prepare('INSERT INTO otp_tokens (email, otp_code, action, expires_at) VALUES (?, ?, ?, ?)')
       ->execute([$user['email'], $otp, 'sensitive_action', $expiry]);
    jsonOk(['otp' => $otp, 'to_email' => $user['email'], 'to_name' => $user['name'], 'expires_in' => 600]);
}

function handleVerifySensitiveOtp(array $b): void {
    $user = requireAuth();
    $otp  = trim($b['otp'] ?? '');
    $db   = getDB();
    $now  = date('Y-m-d H:i:s');
    $stmt = $db->prepare("SELECT id FROM otp_tokens WHERE email = ? AND otp_code = ? AND action = 'sensitive_action' AND expires_at > ? AND used = 0");
    $stmt->execute([$user['email'], $otp, $now]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Invalid or expired OTP.');
    $db->prepare('UPDATE otp_tokens SET used = 1 WHERE id = ?')->execute([$row['id']]);
    jsonOk(['verified' => true]);
}

// ─────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────
function generateOtp(): string {
    return str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function getUserById(int $id): ?array {
    $stmt = getDB()->prepare(
        'SELECT id, name, email, phone, role, avatar, city, state, is_verified, created_at FROM users WHERE id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function sanitizeUser(array $u): array {
    return [
        'id'          => (int)$u['id'],
        'name'        => $u['name'],
        'email'       => $u['email'],
        'phone'       => $u['phone'],
        'role'        => $u['role'],
        'avatar'      => $u['avatar'],
        'city'        => $u['city'],
        'state'       => $u['state'],
        'is_verified' => (bool)$u['is_verified'],
        'created_at'  => $u['created_at'],
    ];
}
