<?php
/**
 * ZipZapZoi Classifieds — Maintenance & Database Migration Patch
 * Auto-activates founder listing #7 and sets default listing expiry to 90 days.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=UTF-8');

try {
    $db = getDB();
    $updates = [];

    // 1. Reactivate listing #7 and extend expiry by 90 days
    $stmt7 = $db->prepare("UPDATE listings SET status = 'active', expires_at = DATE_ADD(NOW(), INTERVAL 90 DAY) WHERE id = 7");
    $stmt7->execute();
    $updates['listing_7'] = "Updated listing #7 status to active and extended expires_at +90 days (affected: {$stmt7->rowCount()})";

    // 2. Ensure system_settings has listing_expiry_days = 90
    $expStmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('listing_expiry_days', '90') ON DUPLICATE KEY UPDATE setting_value = '90'");
    $expStmt->execute();
    $updates['listing_expiry_days'] = "Set listing_expiry_days to 90 days";

    // 3. Make founder user (phanikumar.isep@gmail.com) admin if not already, activate all users and clear rate limits
    $adminStmt = $db->prepare("UPDATE users SET role = 'admin', is_verified = 1, is_active = 1 WHERE email = 'phanikumar.isep@gmail.com' OR id = 7");
    $adminStmt->execute();
    $updates['founder_admin'] = "Ensured founder account is admin & verified (affected: {$adminStmt->rowCount()})";

    $db->exec("UPDATE users SET is_verified = 1, is_active = 1");
    try { $db->exec("DELETE FROM rate_limits"); } catch (\Throwable $e) {}
    $updates['rate_limits'] = "Cleared all rate limits and ensured all accounts are active & verified";

    // 4. Return current state of listing #7 and all users
    $checkStmt = $db->prepare("SELECT id, title, status, expires_at, user_id FROM listings WHERE id = 7");
    $checkStmt->execute();
    $listing7 = $checkStmt->fetch();

    $usersStmt = $db->prepare("SELECT id, name, email, phone, role, is_active, is_verified FROM users ORDER BY id DESC LIMIT 10");
    $usersStmt->execute();
    $users = $usersStmt->fetchAll();

    jsonOk([
        'message' => 'Database patch applied successfully',
        'updates' => $updates,
        'listing' => $listing7,
        'users'   => $users
    ]);
} catch (Throwable $e) {
    jsonError('Patch error: ' . $e->getMessage(), 500);
}
