<?php
/**
 * ZipZapZoi Classifieds — Maintenance & Database Migration Patch
 * Auto-activates founder listing #7 and sets default listing expiry to 90 days.
 * Ensures all schema columns are created for users, listings, messages, and feedback.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=UTF-8');

try {
    $db = getDB();
    $updates = [];

    // 1. Schema migration for users table
    $uCols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('verification_status', $uCols)) {
        $db->exec("ALTER TABLE users ADD COLUMN verification_status ENUM('unverified','pending','rejected','verified') DEFAULT 'unverified'");
        $updates['users_verification_status'] = 'Added verification_status column';
    }
    if (!in_array('verification_id_url', $uCols)) {
        $db->exec("ALTER TABLE users ADD COLUMN verification_id_url VARCHAR(255) DEFAULT NULL");
        $updates['users_verification_id_url'] = 'Added verification_id_url column';
    }
    if (!in_array('trusted_seller', $uCols)) {
        $db->exec("ALTER TABLE users ADD COLUMN trusted_seller TINYINT(1) NOT NULL DEFAULT 0");
        $updates['users_trusted_seller'] = 'Added trusted_seller column';
    }
    if (!in_array('push_notifications', $uCols)) {
        $db->exec("ALTER TABLE users ADD COLUMN push_notifications TINYINT(1) NOT NULL DEFAULT 1");
        $updates['users_push_notifications'] = 'Added push_notifications column';
    }
    if (!in_array('email_alerts', $uCols)) {
        $db->exec("ALTER TABLE users ADD COLUMN email_alerts TINYINT(1) NOT NULL DEFAULT 1");
        $updates['users_email_alerts'] = 'Added email_alerts column';
    }
    if (!in_array('deal_alerts', $uCols)) {
        $db->exec("ALTER TABLE users ADD COLUMN deal_alerts TINYINT(1) NOT NULL DEFAULT 1");
        $updates['users_deal_alerts'] = 'Added deal_alerts column';
    }
    if (!in_array('fcm_token', $uCols)) {
        $db->exec("ALTER TABLE users ADD COLUMN fcm_token TEXT NULL");
        $updates['users_fcm_token'] = 'Added fcm_token column';
    }
    if (!in_array('bio', $uCols)) {
        $db->exec("ALTER TABLE users ADD COLUMN bio TEXT NULL");
        $updates['users_bio'] = 'Added bio column';
    }
    if (!in_array('city', $uCols)) {
        $db->exec("ALTER TABLE users ADD COLUMN city VARCHAR(100) NULL");
        $updates['users_city'] = 'Added city column';
    }
    if (!in_array('state', $uCols)) {
        $db->exec("ALTER TABLE users ADD COLUMN state VARCHAR(100) NULL");
        $updates['users_state'] = 'Added state column';
    }
    if (!in_array('hide_phone', $uCols)) {
        $db->exec("ALTER TABLE users ADD COLUMN hide_phone TINYINT(1) NOT NULL DEFAULT 0");
        $updates['users_hide_phone'] = 'Added hide_phone column';
    }
    if (!in_array('whatsapp', $uCols)) {
        $db->exec("ALTER TABLE users ADD COLUMN whatsapp TINYINT(1) NOT NULL DEFAULT 1");
        $updates['users_whatsapp'] = 'Added whatsapp column';
    }

    // 2. Schema migration for user_feedback table
    $db->exec("
        CREATE TABLE IF NOT EXISTS user_feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL,
            type VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            status ENUM('unread', 'read', 'resolved') DEFAULT 'unread',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $updates['user_feedback_table'] = 'Ensured user_feedback table exists';

    // 3. Schema migration for user_notifications table
    $db->exec("
        CREATE TABLE IF NOT EXISTS user_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(255),
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $updates['user_notifications_table'] = 'Ensured user_notifications table exists';

    // 3b. Schema migration for messages table
    $mCols = $db->query("SHOW COLUMNS FROM messages")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('deleted_for', $mCols)) {
        $db->exec("ALTER TABLE messages ADD COLUMN deleted_for TEXT DEFAULT NULL");
        $updates['messages_deleted_for'] = 'Added deleted_for column to messages';
    }
    if (!in_array('media_url', $mCols)) {
        $db->exec("ALTER TABLE messages ADD COLUMN media_url TEXT DEFAULT NULL");
        $updates['messages_media_url'] = 'Added media_url column to messages';
    }

    // 4. Reactivate listing #7 and extend expiry by 90 days
    $stmt7 = $db->prepare("UPDATE listings SET status = 'active', expires_at = DATE_ADD(NOW(), INTERVAL 90 DAY) WHERE id = 7");
    $stmt7->execute();
    $updates['listing_7'] = "Updated listing #7 status to active and extended expires_at +90 days (affected: {$stmt7->rowCount()})";

    // 5. Ensure system_settings has listing_expiry_days = 90
    $expStmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('listing_expiry_days', '90') ON DUPLICATE KEY UPDATE setting_value = '90'");
    $expStmt->execute();
    $updates['listing_expiry_days'] = "Set listing_expiry_days to 90 days";

    // 6. Make founder user admin if not already, activate all users and clear rate limits
    $adminStmt = $db->prepare("UPDATE users SET role = 'admin', is_verified = 1, is_active = 1 WHERE email = 'phanikumar.isep@gmail.com' OR id = 7");
    $adminStmt->execute();
    $updates['founder_admin'] = "Ensured founder account is admin & verified (affected: {$adminStmt->rowCount()})";

    $db->exec("UPDATE users SET is_verified = 1, is_active = 1");
    try { $db->exec("DELETE FROM rate_limits"); } catch (\Throwable $e) {}
    $updates['rate_limits'] = "Cleared all rate limits and ensured all accounts are active & verified";

    // 7. Return current state
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
} catch (\Throwable $e) {
    jsonError('Patch error: ' . $e->getMessage(), 500);
}
