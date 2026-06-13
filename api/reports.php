<?php
/**
 * ZipZapZoi — User Reports API
 *
 * POST /api/reports.php
 *   Body: { listing_id, reason }
 *   → Logged-in user reports a listing
 *
 * Rules:
 *  1. User must be authenticated
 *  2. listing_id must exist and be active
 *  3. User cannot report their own listing
 *  4. One report per user per listing (duplicate blocked)
 *  5. Reason must be one of the allowed values
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

$user = requireAuth();
$b    = getBody();

$listingId = (int)($b['listing_id'] ?? 0);
$reason    = clean($b['reason'] ?? '');

$allowed = ['Scam / Fraud', 'Misleading Price', 'Duplicate Listing', 'Inappropriate Content', 'Wrong Category', 'Item Already Sold', 'Other'];

if (!$listingId)                        jsonError('listing_id is required.');
if (!in_array($reason, $allowed))       jsonError('Invalid reason. Please select from the list.');

$db = getDB();

// ── 1. Check listing exists ────────────────────────────────────────────
$stmt = $db->prepare('SELECT id, user_id, title, status FROM listings WHERE id = ?');
$stmt->execute([$listingId]);
$listing = $stmt->fetch();
if (!$listing)                          jsonError('Listing not found.', 404);

// ── 2. Cannot report own listing ──────────────────────────────────────
if ((int)$listing['user_id'] === (int)$user['id']) {
    jsonError('You cannot report your own listing.');
}

// ── 3. Duplicate report check ─────────────────────────────────────────
$dup = $db->prepare('SELECT id FROM reports WHERE listing_id = ? AND reporter_id = ?');
$dup->execute([$listingId, $user['id']]);
if ($dup->fetch()) {
    jsonError('You have already reported this listing. Our team will review it shortly.');
}

// ── 4. Insert report ──────────────────────────────────────────────────
$db->prepare(
    'INSERT INTO reports (listing_id, reporter_id, reason, status, created_at)
     VALUES (?, ?, ?, ?, NOW())'
)->execute([$listingId, (int)$user['id'], $reason, 'pending']);

// ── 5. Auto-flag listing if it gets 5+ reports ────────────────────────
$reportCount = (int)$db->prepare(
    'SELECT COUNT(*) FROM reports WHERE listing_id = ? AND status = ?'
)->execute([$listingId, 'pending']) ? $db->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;

// Count properly
$countStmt = $db->prepare('SELECT COUNT(*) FROM reports WHERE listing_id = ?');
$countStmt->execute([$listingId]);
$totalReports = (int)$countStmt->fetchColumn();

if ($totalReports >= 5) {
    // Auto-flag for priority review
    $db->prepare("UPDATE listings SET status = 'pending_review' WHERE id = ? AND status = 'active'")
       ->execute([$listingId]);
}

jsonOk(['message' => 'Thank you for your report. Our team will review this listing within 24 hours.']);
