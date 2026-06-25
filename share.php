<?php
/**
 * ZipZapZoi - Rich Social Sharing Wrapper
 * 
 * Fetches listing data and generates OpenGraph tags for WhatsApp/Facebook
 * before redirecting the user to the actual listing page.
 */
require_once __DIR__ . '/api/config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$listingUrl = "https://" . $_SERVER['HTTP_HOST'] . "/Listing%20Detail.html?id=" . $id;

$title = "ZipZapZoi Classifieds";
$description = "Check out this amazing deal on ZipZapZoi!";
$image = "https://via.placeholder.com/1200x630?text=ZipZapZoi";

if ($id > 0) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT title, description, price, price_type, images FROM listings WHERE id = ? AND status = 'active'");
        $stmt->execute([$id]);
        $listing = $stmt->fetch();

        if ($listing) {
            $priceStr = '';
            if ($listing['price_type'] === 'free') {
                $priceStr = 'FREE';
            } elseif (!empty($listing['price']) && $listing['price'] > 0) {
                $priceStr = 'Rs. ' . number_format((float)$listing['price']);
            }
            
            $title = htmlspecialchars($listing['title']) . ($priceStr ? " - " . $priceStr : "") . " | ZipZapZoi";
            
            $desc = strip_tags($listing['description']);
            if (mb_strlen($desc) > 150) {
                $desc = mb_substr($desc, 0, 147) . '...';
            }
            $description = htmlspecialchars($desc);
            
            $images = json_decode($listing['images'], true);
            if (is_array($images) && count($images) > 0 && !empty($images[0])) {
                $imgUrl = $images[0];
                if (!preg_match('/^http/', $imgUrl)) {
                    $imgUrl = "https://" . $_SERVER['HTTP_HOST'] . "/" . ltrim($imgUrl, '/');
                }
                $image = htmlspecialchars($imgUrl);
            }
        }
    } catch (Exception $e) {
        // Fallback to default tags on error
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?></title>
    <!-- OpenGraph Meta Tags -->
    <meta property="og:type" content="website" />
    <meta property="og:title" content="<?= $title ?>" />
    <meta property="og:description" content="<?= $description ?>" />
    <meta property="og:image" content="<?= $image ?>" />
    <meta property="og:url" content="<?= $listingUrl ?>" />
    <meta property="og:site_name" content="ZipZapZoi" />
    
    <!-- Twitter Card Meta Tags -->
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="<?= $title ?>" />
    <meta name="twitter:description" content="<?= $description ?>" />
    <meta name="twitter:image" content="<?= $image ?>" />

    <!-- Redirect immediately to the listing -->
    <meta http-equiv="refresh" content="0;url=<?= $listingUrl ?>" />
    <script>window.location.href = "<?= $listingUrl ?>";</script>
</head>
<body>
    <p>Redirecting you to the listing... <a href="<?= $listingUrl ?>">Click here</a> if not redirected automatically.</p>
</body>
</html>
