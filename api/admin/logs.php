<?php
/**
 * ZipZapZoi — Admin Logs API
 * GET  /api/admin/logs.php   → last 200 audit logs
 * POST /api/admin/logs.php   → write log {action, detail}
 */
require_once __DIR__ . '/../config.php';
$admin  = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET')  getLogs();
elseif ($method === 'POST') writeLog($admin);
else jsonError('Method not allowed', 405);

function getLogs(): void {
    $limit = min((int)($_GET['limit'] ?? 200), 500);
    $stmt  = getDB()->prepare(
        'SELECT id, admin_name, action, detail, created_at
         FROM admin_logs ORDER BY created_at DESC LIMIT ?'
    );
    $stmt->execute([$limit]);
    jsonOk($stmt->fetchAll());
}

function writeLog(array $admin): void {
    $b      = getBody();
    $action = clean($b['action'] ?? '');
    $detail = $b['detail'] ?? '';
    if (!$action) jsonError('action required.');
    getDB()->prepare(
        'INSERT INTO admin_logs (admin_id, admin_name, action, detail) VALUES (?,?,?,?)'
    )->execute([(int)$admin['id'], $admin['name'], $action, $detail]);
    jsonOk(['message' => 'Log written.'], 201);
}
