<?php
/**
 * ZipZapZoi Classifieds — Price Offers API
 * POST   /api/offers.php              → buyer makes an offer {listing_id, amount, message?}
 * GET    /api/offers.php?listing_id=X → seller sees all offers on their listing
 * GET    /api/offers.php?my_offers=1  → buyer sees all their sent offers
 * PUT    /api/offers.php?id=X         → seller accepts/declines/counters {action: 'accept'|'decline'|'counter', counter_amount?}
 */
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db = getDB();

// Create table if not exists
$db->exec("
CREATE TABLE IF NOT EXISTS offers (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    listing_id    INT NOT NULL,
    buyer_id      INT NOT NULL,
    seller_id     INT NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,
    message       TEXT,
    status        ENUM('pending','accepted','declined','countered') DEFAULT 'pending',
    counter_amount DECIMAL(12,2) NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_listing (listing_id),
    INDEX idx_buyer   (buyer_id),
    INDEX idx_seller  (seller_id)
)
");

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $body = getBody();
    $listing_id = isset($body['listing_id']) ? (int)$body['listing_id'] : 0;
    $amount = isset($body['amount']) ? (float)$body['amount'] : 0.0;
    $message = isset($body['message']) ? clean($body['message']) : '';

    if ($listing_id <= 0 || $amount <= 0) {
        jsonError("Invalid listing ID or offer amount.", 400);
    }

    // Get listing details
    $stmt = $db->prepare("SELECT user_id, status FROM listings WHERE id = ?");
    $stmt->execute([$listing_id]);
    $listing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$listing) {
        jsonError("Listing not found.", 404);
    }

    if ($listing['status'] !== 'active') {
        jsonError("Listing is not active.", 400);
    }

    $seller_id = (int)$listing['user_id'];
    if ($seller_id === (int)$user['id']) {
        jsonError("You cannot make an offer on your own listing.", 403);
    }

    // One active offer per buyer per listing (upsert if exists)
    $stmt = $db->prepare("SELECT id FROM offers WHERE listing_id = ? AND buyer_id = ?");
    $stmt->execute([$listing_id, $user['id']]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Update existing offer
        $stmt = $db->prepare("UPDATE offers SET amount = ?, message = ?, status = 'pending', counter_amount = NULL WHERE id = ?");
        $stmt->execute([$amount, $message, $existing['id']]);
    } else {
        // Insert new offer
        $stmt = $db->prepare("INSERT INTO offers (listing_id, buyer_id, seller_id, amount, message) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$listing_id, $user['id'], $seller_id, $amount, $message]);
    }

    // Insert notification to seller
    try {
        // Create table if not exists (just in case)
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
            )
        ");
        $notif_text = "You have a new offer of ₹" . number_format($amount) . " on your listing.";
        $link = "Listing Detail.html?id={$listing_id}";
        $stmt = $db->prepare("INSERT INTO user_notifications (user_id, type, title, message, link) VALUES (?, 'new_offer', 'New Offer!', ?, ?)");
        $stmt->execute([$seller_id, $notif_text, $link]);
    } catch (Exception $e) {
        // Silently ignore if table format is different
    }

    jsonOk(['message' => 'Offer submitted successfully.']);
} 
elseif ($method === 'GET') {
    $listing_id = isset($_GET['listing_id']) ? (int)$_GET['listing_id'] : 0;
    $my_offers = isset($_GET['my_offers']) ? (int)$_GET['my_offers'] : 0;

    if ($my_offers === 1) {
        // Buyer sees all their sent offers
        $stmt = $db->prepare("
            SELECT o.*, l.title AS listing_title, l.images AS listing_images 
            FROM offers o
            JOIN listings l ON o.listing_id = l.id
            WHERE o.buyer_id = ?
            ORDER BY o.updated_at DESC
        ");
        $stmt->execute([$user['id']]);
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode images
        foreach ($offers as &$offer) {
            $imgs = json_decode($offer['listing_images'] ?? '[]', true);
            $offer['listing_image'] = is_array($imgs) && count($imgs) > 0 ? $imgs[0] : null;
            unset($offer['listing_images']);
        }
        
        jsonOk(['offers' => $offers]);
    } 
    elseif ($listing_id > 0) {
        // Seller sees all offers on their listing
        $stmt = $db->prepare("SELECT user_id FROM listings WHERE id = ?");
        $stmt->execute([$listing_id]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing || (int)$listing['user_id'] !== (int)$user['id']) {
            jsonError("Unauthorized or listing not found.", 403);
        }

        $stmt = $db->prepare("
            SELECT o.*, u.name AS buyer_name, u.avatar AS buyer_avatar 
            FROM offers o
            JOIN users u ON o.buyer_id = u.id
            WHERE o.listing_id = ?
            ORDER BY o.updated_at DESC
        ");
        $stmt->execute([$listing_id]);
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonOk(['offers' => $offers]);
    } 
    else {
        jsonError("Missing required parameters.", 400);
    }
} 
elseif ($method === 'PUT') {
    $offer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $body = getBody();
    $action = isset($body['action']) ? clean($body['action']) : '';

    if ($offer_id <= 0 || !in_array($action, ['accept', 'decline', 'counter'])) {
        jsonError("Invalid offer ID or action.", 400);
    }

    $stmt = $db->prepare("SELECT * FROM offers WHERE id = ?");
    $stmt->execute([$offer_id]);
    $offer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$offer) {
        jsonError("Offer not found.", 404);
    }

    if ((int)$offer['seller_id'] !== (int)$user['id']) {
        jsonError("Unauthorized. Only the seller can update this offer.", 403);
    }

    $buyer_id = (int)$offer['buyer_id'];
    $listing_id = (int)$offer['listing_id'];
    $amount = (float)$offer['amount'];

    if ($action === 'accept') {
        $stmt = $db->prepare("UPDATE offers SET status = 'accepted' WHERE id = ?");
        $stmt->execute([$offer_id]);

        // Auto-chat message
        $chat_msg = "Your offer of ₹" . number_format($amount) . " has been accepted! Let's finalize the deal.";
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    from_user_id INT NOT NULL,
                    to_user_id INT NOT NULL,
                    listing_id INT NOT NULL,
                    body TEXT NOT NULL,
                    is_read TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_from (from_user_id),
                    INDEX idx_to (to_user_id),
                    INDEX idx_listing (listing_id)
                )
            ");
            $stmt = $db->prepare("INSERT INTO messages (from_user_id, to_user_id, listing_id, body) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user['id'], $buyer_id, $listing_id, $chat_msg]);
        } catch (Exception $e) {}

        jsonOk(['message' => 'Offer accepted.']);
    } 
    elseif ($action === 'decline') {
        $stmt = $db->prepare("UPDATE offers SET status = 'declined' WHERE id = ?");
        $stmt->execute([$offer_id]);
        jsonOk(['message' => 'Offer declined.']);
    } 
    elseif ($action === 'counter') {
        $counter_amount = isset($body['counter_amount']) ? (float)$body['counter_amount'] : 0.0;
        if ($counter_amount <= 0) {
            jsonError("Invalid counter amount.", 400);
        }

        $stmt = $db->prepare("UPDATE offers SET status = 'countered', counter_amount = ? WHERE id = ?");
        $stmt->execute([$counter_amount, $offer_id]);

        // Notification to buyer
        try {
            $notif_text = "The seller has made a counter offer of ₹" . number_format($counter_amount) . ".";
            $link = "Listing Detail.html?id={$listing_id}";
            $stmt = $db->prepare("INSERT INTO user_notifications (user_id, type, title, message, link) VALUES (?, 'counter_offer', 'Counter Offer Received', ?, ?)");
            $stmt->execute([$buyer_id, $notif_text, $link]);
        } catch (Exception $e) {}

        jsonOk(['message' => 'Counter offer submitted.']);
    }
} 
else {
    jsonError("Method not allowed", 405);
}
