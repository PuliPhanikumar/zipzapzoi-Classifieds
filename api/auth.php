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
    case 'validate_reset_token': handleValidateResetToken();     break;
    case 'update_fcm':           handleUpdateFcm($body);         break;
    default:                     jsonError('Unknown action', 400);
}

// ─────────────────────────────────────────────────────────────────────
// REGISTER — Step 1: validate + send OTP (don't create user yet)
// ─────────────────────────────────────────────────────────────────────
function handleRegister(array $b): void {
    checkRateLimit('register', 5, 15);

    // Simple honeypot check for spam protection
    if (!empty($b['hp_website'])) {
        // Fake success to trick bot
        jsonOk(['message' => 'Registration successful.', 'expires_in' => 900, 'to_name' => 'Bot', 'to_email' => 'bot@bot.com']);
    }

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

    // ── Block duplicate phone — one free trial per mobile number ──
    $phoneExists = $db->prepare('SELECT id FROM users WHERE phone = ?');
    $phoneExists->execute([$phone]);
    if ($phoneExists->fetch()) jsonError('An account with this mobile number already exists. Each mobile number can only have one account.');

    // Store pending registration data in otp_tokens meta
    $otp     = generateOtp();
    $expiry  = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $refCode = clean($b['referral_code'] ?? '');
    $meta    = json_encode(['name' => $name, 'phone' => $phone, 'password' => password_hash($password, PASSWORD_DEFAULT), 'referral_code' => $refCode]);

    // Clear old OTPs for this email
    $db->prepare("DELETE FROM otp_tokens WHERE email = ? AND action = 'register'")->execute([$email]);
    $db->prepare(
        'INSERT INTO otp_tokens (email, otp_code, action, expires_at, meta) VALUES (?, ?, ?, ?, ?)'
    )->execute([$email, $otp, 'register', $expiry, $meta]);

    // Send OTP email via PHP mail (server-side)
    sendOtpMail($email, $name, $otp, 15);

    jsonOk([
        'message'    => 'OTP sent to your email.',
        'expires_in' => 900,
        'to_name'    => $name,
        'to_email'   => $email,
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
        
        $referredById = null;
        if (!empty($meta['referral_code'])) {
            $refStmt = $db->prepare('SELECT id FROM users WHERE referral_code = ?');
            $refStmt->execute([$meta['referral_code']]);
            $refRow = $refStmt->fetch();
            if ($refRow) $referredById = (int)$refRow['id'];
        }

        // Generate a unique referral code for the new user
        $newReferralCode = 'ZZZ' . strtoupper(substr(bin2hex(random_bytes(16)), 0, 6));

        // Create user account
        $db->prepare(
            'INSERT INTO users (name, email, phone, password_hash, role, is_verified, referral_code, referred_by)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
        )->execute([$meta['name'], $email, $meta['phone'], $meta['password'], 'user', $newReferralCode, $referredById]);
        $userId = (int) $db->lastInsertId();

        // Grant new-user free quota (3 ads)
        $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
        
        $baseAds = 6;
        $rewardAds = $referredById ? 2 : 0;
        $totalGranted = $baseAds + $rewardAds;
        
        $db->prepare(
            'INSERT INTO user_quotas (user_id, ads_remaining, total_granted, plan_id, plan_name, expires_at, new_user_free_granted)
             VALUES (?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
               ads_remaining = ads_remaining + ?, total_granted = total_granted + ?,
               plan_id = VALUES(plan_id), plan_name = VALUES(plan_name), expires_at = VALUES(expires_at)'
        )->execute([$userId, $totalGranted, $totalGranted, 'new_user_free', 'New User Free', $expiry, $totalGranted, $totalGranted]);

        if ($referredById) {
            // Give +2 ads to referrer
            $db->prepare(
                'UPDATE user_quotas SET ads_remaining = ads_remaining + 2, total_granted = total_granted + 2 WHERE user_id = ?'
            )->execute([$referredById]);
        }

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
// LOGIN — verify credentials, issue session directly.
// OTP is only required ONCE at registration to verify the email address.
// Asking for OTP on every login would be 2FA — not required here.
// ─────────────────────────────────────────────────────────────────────
function handleLogin(array $b): void {
    checkRateLimit('login', 5, 15);

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

    clearRateLimit('login');

    // Issue session directly — email was already verified at registration
    $token = createSession((int)$user['id']);
    jsonOk(['user' => sanitizeUser($user), 'token' => $token, 'message' => 'Login successful.']);
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

    $full = getUserById((int)$user['id']);
    if (!$full) jsonError('User not found.', 404);
    $db = getDB();

    // ── Quota ──────────────────────────────────────────────────────
    $qStmt = $db->prepare(
        'SELECT ads_remaining, total_granted, plan_id, plan_name, expires_at
         FROM user_quotas WHERE user_id = ?'
    );
    $qStmt->execute([(int)$user['id']]);
    $quota = $qStmt->fetch();
    $full['quota'] = $quota ? [
        'ads_remaining' => (int)$quota['ads_remaining'],
        'total_granted' => (int)$quota['total_granted'],
        'plan_id'       => $quota['plan_id'],
        'plan_name'     => $quota['plan_name'],
        'expires_at'    => $quota['expires_at'],
    ] : null;

    // ── Listing Stats (aggregated) ─────────────────────────────────
    $sStmt = $db->prepare(
        'SELECT status, COUNT(*) AS cnt, COALESCE(SUM(views), 0) AS total_views
         FROM listings WHERE user_id = ? GROUP BY status'
    );
    $sStmt->execute([(int)$user['id']]);
    $rows        = $sStmt->fetchAll();
    $statViews   = 0; $statActive = 0; $statSold = 0;
    foreach ($rows as $r) {
        $statViews += (int)$r['total_views'];
        if ($r['status'] === 'active')  $statActive = (int)$r['cnt'];
        if ($r['status'] === 'sold')    $statSold   = (int)$r['cnt'];
    }
    $full['listing_stats'] = [
        'total_views' => $statViews,
        'active'      => $statActive,
        'sold'        => $statSold,
    ];

    // ── Unread message count ────────────────────────────────────────
    $mStmt = $db->prepare(
        'SELECT COUNT(*) FROM messages WHERE to_user_id = ? AND is_read = 0'
    );
    $mStmt->execute([(int)$user['id']]);
    $full['unread_messages'] = (int)$mStmt->fetchColumn();

    // ── Listing status notifications (last 7 days) ──────────────────
    $nStmt = $db->prepare(
        "SELECT id, title, status, updated_at
         FROM listings
         WHERE user_id = ?
           AND status IN ('active','rejected')
           AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         ORDER BY updated_at DESC
         LIMIT 10"
    );
    $nStmt->execute([(int)$user['id']]);
    $notifs = $nStmt->fetchAll();
    foreach ($notifs as &$n) {
        $n['id'] = (int)$n['id'];
        $n['message'] = $n['status'] === 'active'
            ? '✅ Your ad "' . $n['title'] . '" was approved and is now live!'
            : '❌ Your ad "' . $n['title'] . '" was rejected by admin.';
    }
    $full['listing_notifications'] = $notifs;
    $full['unread_notifications']  = count($notifs);

    jsonOk(['user' => $full]);
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
    
    // Send email server-side
    sendPasswordResetMail($email, $user['name'], $resetLink);
    
    jsonOk([
        'message'    => 'If that email exists, a reset link has been sent.',
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

function handleValidateResetToken(): void {
    $token = trim($_GET['token'] ?? '');
    if (!$token) jsonError('Token missing');
    $db  = getDB();
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare('SELECT id, user_id FROM reset_tokens WHERE token = ? AND expires_at > ? AND used = 0');
    $stmt->execute([$token, $now]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonError('Invalid or expired reset link. Please request a new one.');
    }
    
    // Also fetch the user's email to display it securely
    $uStmt = $db->prepare('SELECT email FROM users WHERE id = ?');
    $uStmt->execute([$row['user_id']]);
    $userRow = $uStmt->fetch();
    
    jsonOk(['valid' => true, 'email' => $userRow['email'] ?? '']);
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
       
    // Send email server-side
    sendOtpMail($user['email'], $user['name'], $otp, 10);
    
    jsonOk(['to_email' => $user['email'], 'to_name' => $user['name'], 'expires_in' => 600]);
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

function handleUpdateFcm(array $b): void {
    $user = requireAuth();
    $token = trim($b['token'] ?? '');
    if (!$token) jsonError('Token required');
    $db = getDB();
    try {
        $db->prepare('UPDATE users SET fcm_token = ? WHERE id = ?')->execute([$token, $user['id']]);
    } catch (Exception $e) {
        // If column doesn't exist, ignore for now (migration script must be run by admin on hostinger DB)
        if (strpos($e->getMessage(), 'Unknown column') !== false) {
            // Ignore silently
        } else {
            throw $e;
        }
    }
    jsonOk();
}

// ─────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────
function generateOtp(): string {
    return str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function sendOtpMail(string $toEmail, string $toName, string $otp, int $expiryMins = 10): void {
    $subject = "Your ZipZapZoi OTP Code: {$otp}";
    $body    = "
<!DOCTYPE html>
<html><body style='margin:0;padding:0;background:#f0f4f8;font-family:Arial,sans-serif;'>
<div style='max-width:480px;margin:40px auto;background:#fff;border-radius:16px;padding:40px;box-shadow:0 4px 24px rgba(0,0,0,0.08);'>
  <div style='text-align:center;margin-bottom:32px;'>
    <h1 style='color:#019863;margin:0;font-size:28px;'>ZipZapZoi</h1>
    <p style='color:#888;margin:4px 0 0;font-size:13px;'>Post Free Ads</p>
  </div>
  <p style='color:#374151;font-size:16px;margin:0 0 8px;'>Hello <strong>{$toName}</strong>,</p>
  <p style='color:#374151;font-size:15px;margin:0 0 28px;'>Your verification code is:</p>
  <div style='background:#f0fdf4;border:2px solid #019863;border-radius:12px;padding:24px;text-align:center;margin-bottom:28px;'>
    <span style='font-size:42px;font-weight:800;letter-spacing:12px;color:#019863;'>{$otp}</span>
  </div>
  <p style='color:#6b7280;font-size:14px;text-align:center;margin:0 0 8px;'>⏱ Valid for <strong>{$expiryMins} minutes</strong></p>
  <p style='color:#9ca3af;font-size:12px;text-align:center;margin:0;'>If you didn't request this code, please ignore this email.</p>
  <hr style='border:none;border-top:1px solid #f3f4f6;margin:28px 0;'>
  <p style='color:#d1d5db;font-size:11px;text-align:center;margin:0;'>© 2026 ZipZapZoi. All Rights Reserved.</p>
</div>
</body></html>";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: ZipZapZoi <noreply@zipzapzoi.com>\r\n";
    $headers .= "Reply-To: support@zipzapzoi.com\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    @mail($toEmail, $subject, $body, $headers);
}

function sendPasswordResetMail(string $toEmail, string $toName, string $resetLink): void {
    $subject = "ZipZapZoi - Password Reset Request";
    $body = "
<!DOCTYPE html>
<html><body style='margin:0;padding:0;background:#f0f4f8;font-family:Arial,sans-serif;'>
<div style='max-width:480px;margin:40px auto;background:#fff;border-radius:16px;padding:40px;box-shadow:0 4px 24px rgba(0,0,0,0.08);'>
  <div style='text-align:center;margin-bottom:32px;'>
    <h1 style='color:#019863;margin:0;font-size:28px;'>ZipZapZoi</h1>
  </div>
  <p style='color:#374151;font-size:16px;margin:0 0 8px;'>Hello <strong>{$toName}</strong>,</p>
  <p style='color:#374151;font-size:15px;margin:0 0 28px;'>Click the link below to reset your password:</p>
  <div style='text-align:center;margin-bottom:28px;'>
    <a href='{$resetLink}' style='background:#019863;color:#fff;padding:12px 24px;text-decoration:none;border-radius:8px;font-weight:bold;display:inline-block;'>Reset Password</a>
  </div>
  <p style='color:#9ca3af;font-size:12px;text-align:center;margin:0;'>If you didn't request this, please ignore this email.</p>
</div>
</body></html>";
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: ZipZapZoi <noreply@zipzapzoi.com>\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    @mail($toEmail, $subject, $body, $headers);
}

function getUserById(int $id): ?array {
    $stmt = getDB()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? sanitizeUser($row) : null;
}

function sanitizeUser(array $u): array {
    return [
        'id'            => (int)$u['id'],
        'name'          => $u['name'],
        'email'         => $u['email'],
        'phone'         => $u['phone'],
        'role'          => $u['role'],
        'avatar'        => $u['avatar'],
        'city'          => $u['city'],
        'state'         => $u['state'],
        'is_verified'   => (bool)$u['is_verified'],
        'created_at'    => $u['created_at'],
        'referral_code' => $u['referral_code'] ?? null,
    ];
}

function checkRateLimit(string $action, int $maxAttempts = 5, int $lockoutMinutes = 15): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $db = getDB();

        // Clean up old entries
        $db->prepare("DELETE FROM rate_limits WHERE last_attempt < DATE_SUB(NOW(), INTERVAL ? MINUTE)")
           ->execute([$lockoutMinutes]);

        $stmt = $db->prepare("SELECT attempts FROM rate_limits WHERE ip_address = ? AND action = ?");
        $stmt->execute([$ip, $action]);
        $row = $stmt->fetch();

        if ($row) {
            if ($row['attempts'] >= $maxAttempts) {
                jsonError("Too many attempts. Please try again in {$lockoutMinutes} minutes.", 429);
            }
            $db->prepare("UPDATE rate_limits SET attempts = attempts + 1, last_attempt = NOW() WHERE ip_address = ? AND action = ?")
               ->execute([$ip, $action]);
        } else {
            $db->prepare("INSERT INTO rate_limits (ip_address, action, attempts) VALUES (?, ?, 1)")
               ->execute([$ip, $action]);
        }
    } catch (\Throwable $e) {
    }
}

function clearRateLimit(string $action): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $db = getDB();
        $db->prepare("DELETE FROM rate_limits WHERE ip_address = ? AND action = ?")->execute([$ip, $action]);
    } catch (\Throwable $e) {
    }
}
