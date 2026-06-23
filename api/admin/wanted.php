<?php
/**
 * ZipZapZoi — Admin Wanted Requests API
 * GET    /api/admin/wanted.php              → all wanted ads (admin view, all statuses)
 * PUT    /api/admin/wanted.php              → update status (active/flagged/deleted)
 * DELETE /api/admin/wanted.php?id=X        → hard delete
 */
require_once __DIR__ . '/../config.php';
$admin  = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET')    listWantedAds();
elseif ($method === 'PUT') updateWantedAd($admin);
elseif ($method === 'DELETE') deleteWantedAd($admin);
else jsonError('Method not allowed', 405);

function listWantedAds(): void {
    $db     = getDB();
    $where  = ['1=1'];
    $params = [];

    $search = $_GET['search'] ?? '';
    if ($search !== '') {
        $where[] = '(w.title LIKE :q OR u.name LIKE :q2 OR u.email LIKE :q3)';
        $params[':q']  = "%$search%";
        $params[':q2'] = "%$search%";
        $params[':q3'] = "%$search%";
    }

    $status = $_GET['status'] ?? '';
    if ($status !== '') {
        $where[] = 'w.status = :status';
        $params[':status'] = $status;
    }

    $whereSql = implode(' AND ', $where);

    $sql = "SELECT w.*, u.name as buyer_name, u.email as buyer_email
            FROM wanted_ads w
            JOIN users u ON w.user_id = u.id
            WHERE $whereSql
            ORDER BY w.created_at DESC";

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse([
            'wanted_ads' => $ads,
            'count'      => count($ads)
        ]);
    } catch (PDOException $e) {
        logError('Admin Wanted list failed', ['error' => $e->getMessage()]);
        jsonError('Failed to fetch wanted ads', 500);
    }
}

function updateWantedAd(array $admin): void {
    $data = json_decode(file_get_contents('php://input'), true);
    $id     = $data['id'] ?? null;
    $status = $data['status'] ?? null;

    if (!$id || !$status) jsonError('ID and status required', 400);

    $db = getDB();
    try {
        $stmt = $db->prepare("UPDATE wanted_ads SET status = :status WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $id]);
        if ($stmt->rowCount() > 0) {
            logAdminAction($admin['id'], 'wanted_status_update', ['wanted_id' => $id, 'new_status' => $status]);
            jsonResponse(['message' => "Wanted request status updated to $status"]);
        } else {
            jsonError('Wanted request not found or status already set', 404);
        }
    } catch (PDOException $e) {
        logError('Admin Wanted status update failed', ['error' => $e->getMessage(), 'id' => $id]);
        jsonError('Database error', 500);
    }
}

function deleteWantedAd(array $admin): void {
    $id = $_GET['id'] ?? null;
    if (!$id) jsonError('ID required', 400);

    $db = getDB();
    try {
        $stmt = $db->prepare("DELETE FROM wanted_ads WHERE id = :id");
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() > 0) {
            logAdminAction($admin['id'], 'wanted_hard_delete', ['wanted_id' => $id]);
            jsonResponse(['message' => 'Wanted request deleted permanently']);
        } else {
            jsonError('Wanted request not found', 404);
        }
    } catch (PDOException $e) {
        logError('Admin Wanted delete failed', ['error' => $e->getMessage(), 'id' => $id]);
        jsonError('Database error', 500);
    }
}
