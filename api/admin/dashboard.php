<?php
/**
 * ZipZapZoi — Admin Dashboard API
 * GET /api/admin/dashboard.php → stats, chart data, audit logs
 */
require_once __DIR__ . '/../config.php';
requireAdmin();

$db = getDB();

// ── Stats ──────────────────────────────────────────────────────────
$totalUsers    = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_active=1')->fetchColumn();
$activeListings= (int)$db->query("SELECT COUNT(*) FROM listings WHERE status='active'")->fetchColumn();
$pendingListings=(int)$db->query("SELECT COUNT(*) FROM listings WHERE status='pending_review'")->fetchColumn();
$totalRevenue  = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='success'")->fetchColumn();
$openTickets   = 0; // tickets table not implemented yet — returns 0

// ── 7-Day Activity (new listings per day) ─────────────────────────
$actStmt = $db->prepare(
    "SELECT DATE(created_at) AS day, COUNT(*) AS cnt
     FROM listings
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(created_at)"
);
$actStmt->execute();
$actRows = $actStmt->fetchAll();
$actMap  = [];
foreach ($actRows as $r) $actMap[$r['day']] = (int)$r['cnt'];

$actLabels = []; $actData = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $actLabels[] = date('D', strtotime($d));
    $actData[]   = $actMap[$d] ?? 0;
}

// ── Listing Status Breakdown ───────────────────────────────────────
$statusStmt = $db->query(
    "SELECT status, COUNT(*) AS cnt FROM listings GROUP BY status"
);
$statusRows = $statusStmt->fetchAll();
$statusMap  = [];
foreach ($statusRows as $r) $statusMap[$r['status']] = (int)$r['cnt'];

// ── Category Distribution ──────────────────────────────────────────
$catStmt = $db->query(
    "SELECT category, COUNT(*) AS cnt FROM listings
     WHERE status='active' AND category IS NOT NULL
     GROUP BY category ORDER BY cnt DESC LIMIT 6"
);
$catRows = $catStmt->fetchAll();

// ── City Distribution ──────────────────────────────────────────────
$cityStmt = $db->query(
    "SELECT location_city, COUNT(*) AS cnt FROM listings
     WHERE location_city IS NOT NULL
     GROUP BY location_city ORDER BY cnt DESC LIMIT 6"
);
$cityRows = $cityStmt->fetchAll();

// ── Audit Logs (last 50) ──────────────────────────────────────────
$logStmt = $db->prepare(
    "SELECT al.action, al.detail, al.admin_name, al.created_at
     FROM admin_logs al
     ORDER BY al.created_at DESC LIMIT 50"
);
$logStmt->execute();
$logs = $logStmt->fetchAll();

jsonOk([
    'stats' => [
        'total_users'      => $totalUsers,
        'active_listings'  => $activeListings,
        'pending_listings' => $pendingListings,
        'total_revenue'    => $totalRevenue,
        'open_tickets'     => $openTickets,
    ],
    'activity' => [
        'labels' => $actLabels,
        'data'   => $actData,
    ],
    'status_breakdown' => $statusMap,
    'category_breakdown' => array_map(fn($r) => ['label' => $r['category'], 'count' => (int)$r['cnt']], $catRows),
    'city_breakdown'     => array_map(fn($r) => ['label' => $r['location_city'], 'count' => (int)$r['cnt']], $cityRows),
    'logs' => $logs,
]);
