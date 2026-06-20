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
header('X-LiteSpeed-Cache-Control: no-cache');
header('Access-Control-Allow-Origin: https://www.zipzapzoi.com');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Set default timezone for PHP mathematically exact dates ───────
date_default_timezone_set('Asia/Kolkata');

// ── Database Credentials ──────────────────────────────────────────────
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') continue;
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'u572945141_Classifieds_db');
define('DB_USER', $_ENV['DB_USER'] ?? 'u572945141_Classifieds_db');
define('DB_PASS', $_ENV['DB_PASS'] ?? 'Dhiyanshi#28');
define('DB_CHARSET', 'utf8mb4');

// ── Session / Cookie Settings ─────────────────────────────────────────
define('SESSION_COOKIE', 'zzz_session');
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
        $pdo->exec("SET time_zone = '+05:30';");
    }
    return $pdo;
}

// ── IP Allowlist & Blacklist Middleware ───────────────────────────
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
try {
    $middlewareDb = getDB();
    $listStmt = $middlewareDb->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('ip_allowlist', 'ai_blacklist')");
    $lists = [];
    while ($row = $listStmt->fetch()) {
        $lists[$row['setting_key']] = $row['setting_value'];
    }
    
    $allowlist = trim($lists['ip_allowlist'] ?? '');
    if ($allowlist) {
        $allowedIps = array_map('trim', explode(',', $allowlist));
        if (!in_array($clientIp, $allowedIps)) {
            http_response_code(403);
            die(json_encode(['success' => false, 'error' => 'Access denied: IP not in allowlist.']));
        }
    }
    
    $blacklist = trim($lists['ai_blacklist'] ?? '');
    if ($blacklist) {
        $blockedIps = array_map('trim', explode(',', $blacklist));
        if (in_array($clientIp, $blockedIps)) {
            http_response_code(403);
            die(json_encode(['success' => false, 'error' => 'Access denied: IP is blacklisted.']));
        }
    }
} catch (Throwable $e) {}

// ── JSON Response Helpers ─────────────────────────────────────────────
function jsonOk($data = null, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonError(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Auth: Get Current User From Session Cookie OR Bearer Token ────────
function getCurrentUser(): ?array {
    // Check Authorization: Bearer <token> header first (mobile app)
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? '';
    }
    if (!empty($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
        $token = trim(substr($authHeader, 7));
    } else {
        // Fallback to cookie (desktop web)
        $token = $_COOKIE[SESSION_COOKIE] ?? '';
    }

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
        $timeoutMins = 43200; // default 30 days
        $timeoutStmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_mins'");
        $val = $timeoutStmt->fetchColumn();
        if (is_numeric($val) && (int)$val > 0) $timeoutMins = (int)$val;
        
        $newExpiry = date('Y-m-d H:i:s', strtotime("+{$timeoutMins} minutes"));
        $db->prepare('UPDATE sessions SET last_activity = NOW(), expires_at = ? WHERE token = ?')
           ->execute([$newExpiry, $token]);
        // Refresh cookie (only relevant for web, safe to call for mobile too)
        setcookie(SESSION_COOKIE, $token, [
            'expires'  => time() + ($timeoutMins * 60),
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
    $db = getDB();
    $timeoutMins = 43200; // default 30 days
    $timeoutStmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_mins'");
    $val = $timeoutStmt->fetchColumn();
    if (is_numeric($val) && (int)$val > 0) $timeoutMins = (int)$val;

    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$timeoutMins} minutes"));
    $ip        = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua        = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    $db->prepare(
        'INSERT INTO sessions (user_id, token, ip_address, user_agent, expires_at)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$userId, $token, $ip, $ua, $expiresAt]);

    setcookie(SESSION_COOKIE, $token, [
        'expires'  => time() + ($timeoutMins * 60),
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
    setcookie(SESSION_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
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
