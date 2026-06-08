<?php
/**
 * ZipZapZoi Classifieds — Database Config & Shared Helpers
 * DB: u572945141_Classifieds_db | Host: Hostinger
 */

// ── CORS Headers & Cache Prevention ─────────────────────────────────────
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: https://www.zipzapzoi.com');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Database Credentials ──────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'u572945141_Classifieds_db');
define('DB_USER', 'u572945141_Classifieds_db');  // ← Replace with your DB username from hPanel
define('DB_PASS', 'Dhiyanshi#28');           // ← Replace with your DB password
define('DB_CHARSET', 'utf8mb4');

// ── Session / Cookie Settings ─────────────────────────────────────────
define('SESSION_COOKIE', 'zzz_session');
define('SESSION_DAYS', 30);
define('SESSION_SECONDS', SESSION_DAYS * 24 * 3600);
define('UPLOAD_DIR', __DIR__ . '/../uploads/listings/');
define('UPLOAD_URL', '/uploads/listings/');
define('MAX_UPLOAD_MB', 10);

// ── PDO Connection ────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ── JSON Response Helpers ─────────────────────────────────────────────
function jsonOk(mixed $data = null, int $code = 200): never {
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonError(string $message, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Auth: Get Current User From Session Cookie ────────────────────────
function getCurrentUser(): ?array {
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if (!$token || strlen($token) < 32) return null;
    try {
        $db  = getDB();
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare(
            'SELECT s.user_id, s.token, u.id, u.name, u.email, u.phone,
                    u.role, u.avatar, u.city, u.state, u.is_verified, u.is_active
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token = ? AND s.expires_at > ? AND u.is_active = 1'
        );
        $stmt->execute([$token, $now]);
        $user = $stmt->fetch();
        if (!$user) return null;

        // Auto-renew session (refresh expiry on activity)
        $newExpiry = date('Y-m-d H:i:s', strtotime('+' . SESSION_DAYS . ' days'));
        $db->prepare('UPDATE sessions SET last_activity = NOW(), expires_at = ? WHERE token = ?')
           ->execute([$newExpiry, $token]);
        // Refresh cookie
        setcookie(SESSION_COOKIE, $token, [
            'expires'  => time() + SESSION_SECONDS,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        return $user;
    } catch (PDOException $e) {
        error_log('ZZZ Auth Error: ' . $e->getMessage());
        return null;
    }
}

// ── Require Auth (die with 401 if not logged in) ──────────────────────
function requireAuth(): array {
    $user = getCurrentUser();
    if (!$user) jsonError('Authentication required. Please log in.', 401);
    return $user;
}

// ── Require Admin ─────────────────────────────────────────────────────
function requireAdmin(): array {
    $user = requireAuth();
    if (!in_array($user['role'], ['admin', 'super_admin'])) {
        jsonError('Admin access required.', 403);
    }
    return $user;
}

// ── Get JSON Request Body ─────────────────────────────────────────────
function getBody(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

// ── Input Helpers ─────────────────────────────────────────────────────
function clean(string $val): string {
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}
function validateEmail(string $email): bool {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

// ── Create Session Token & Cookie ─────────────────────────────────────
function createSession(int $userId): string {
    $token     = bin2hex(random_bytes(32)); // 64-char secure token
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . SESSION_DAYS . ' days'));
    $ip        = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua        = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    getDB()->prepare(
        'INSERT INTO sessions (user_id, token, ip_address, user_agent, expires_at)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$userId, $token, $ip, $ua, $expiresAt]);

    setcookie(SESSION_COOKIE, $token, [
        'expires'  => time() + SESSION_SECONDS,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    return $token;
}

// ── Delete Session (Logout) ───────────────────────────────────────────
function destroySession(): void {
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token) {
        getDB()->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]);
    }
    setcookie(SESSION_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
}

// ── Razorpay Keys (loaded from system_settings table) ────────────
function getRazorpayKeys(): array {
    static $keys = null;
    if ($keys !== null) return $keys;
    try {
        $rows = getDB()->query(
            "SELECT setting_key, setting_value FROM system_settings
             WHERE setting_key IN ('razorpay_key','razorpay_secret')"
        )->fetchAll();
        $keys = [];
        foreach ($rows as $r) $keys[$r['setting_key']] = $r['setting_value'] ?? '';
    } catch (\Throwable $e) {
        $keys = ['razorpay_key' => '', 'razorpay_secret' => ''];
    }
    return $keys;
}

// ── Verify Razorpay Signature ─────────────────────────────────────
// Standard format: HMAC-SHA256( orderId + "|" + paymentId, secret )
function verifyRazorpaySignature(string $orderId, string $paymentId, string $signature): bool {
    $keys   = getRazorpayKeys();
    $secret = $keys['razorpay_secret'] ?? '';
    if (!$secret) return false; // secret not configured → block
    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
    return hash_equals($expected, $signature);
}
