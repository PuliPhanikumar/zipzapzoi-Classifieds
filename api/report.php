<?php
/**
 * ZipZapZoi Classifieds — Reporting API
 */
require_once __DIR__ . '/config.php';

$user = requireAuth();
$reporterId = $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = getBody();
    $reportedUser = !empty($b['reported_user']) ? (int)$b['reported_user'] : null;
    $listingId = !empty($b['listing_id']) ? (int)$b['listing_id'] : null;
    $reason = trim($b['reason'] ?? '');

    if (!$reportedUser && !$listingId) {
        jsonError('Must provide a reported_user or listing_id.');
    }
    if (!$reason) {
        jsonError('Must provide a reason for the report.');
    }

    $db = getDB();
    
    // Check for duplicate report
    $stmt = $db->prepare('SELECT id FROM reports WHERE reporter_id = ? AND (reported_user = ? OR listing_id = ?)');
    $stmt->execute([$reporterId, $reportedUser, $listingId]);
    if ($stmt->fetch()) {
        jsonError('You have already submitted a report for this item or user.');
    }

    // Insert the report
    $stmt = $db->prepare('INSERT INTO reports (reporter_id, reported_user, listing_id, reason) VALUES (?, ?, ?, ?)');
    $stmt->execute([$reporterId, $reportedUser, $listingId, $reason]);

    // Optional: Automated fraud heuristics (e.g., if a user gets 3+ reports, flag them automatically)
    if ($reportedUser) {
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM reports WHERE reported_user = ?');
        $stmt->execute([$reportedUser]);
        $reportCount = $stmt->fetch()['count'];

        if ($reportCount >= 3) {
            // Deduct trust score or auto-ban heuristics
            $stmt = $db->prepare('UPDATE users SET trust_score = GREATEST(0, trust_score - 20) WHERE id = ?');
            $stmt->execute([$reportedUser]);
        }
    }

    if ($listingId) {
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM reports WHERE listing_id = ?');
        $stmt->execute([$listingId]);
        $reportCount = $stmt->fetch()['count'];

        if ($reportCount >= 5) {
            // Flag listing as pending_review automatically
            $stmt = $db->prepare('UPDATE listings SET status = "pending_review" WHERE id = ?');
            $stmt->execute([$listingId]);
        }
    }

    jsonOk(['message' => 'Report submitted successfully. Thank you for keeping our community safe.']);
} else {
    jsonError('Invalid method');
}
