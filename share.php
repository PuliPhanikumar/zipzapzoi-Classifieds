<?php
/**
 * ZipZapZoi Classifieds - Dynamic Share Endpoint
 * This endpoint fetches the listing and renders Open Graph (OG) meta tags
 * for beautiful previews on WhatsApp, Facebook, iMessage, etc.
 * It then automatically redirects standard users to the actual Listing Detail.html page.
 */
require_once __DIR__ . '/api/config.php';

$id = isset($_GET['id']) ? $_GET['id'] : '';
if (!$id) {
    header("Location: /classifieds.html");
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT id, title, description, price, price_type, images FROM listings WHERE id = ? AND status = 'active'");
$stmt->execute([$id]);
$listing = $stmt->fetch();

if (!$listing) {
    header("Location: /classifieds.html");
    exit;
}

$title = htmlspecialchars($listing['title'], ENT_QUOTES, 'UTF-8');
$description = mb_strimwidth(htmlspecialchars($listing['description'], ENT_QUOTES, 'UTF-8'), 0, 160, "...");
$priceText = $listing['price_type'] === 'free' ? 'FREE' : '₹' . number_format($listing['price']);
$fullTitle = "$title - $priceText | ZipZapZoi";

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domain = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Default image if no images exist
$imageUrl = $protocol . $domain . '/images/default-share.png';
$images = json_decode($listing['images'], true);
if (is_array($images) && count($images) > 0) {
    // If it's a relative URL from our uploads directory, make it absolute
    if (strpos($images[0], '/') === 0 || strpos($images[0], 'uploads/') === 0) {
        $imageUrl = $protocol . $domain . '/' . ltrim($images[0], '/');
    } else {
        $imageUrl = $images[0];
    }
}
$targetUrl = "/Listing%20Detail.html?id=" . urlencode($id);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $fullTitle ?></title>
    
    <!-- Open Graph Meta Tags -->
    <meta property="og:title" content="<?= $fullTitle ?>" />
    <meta property="og:description" content="<?= $description ?>" />
    <meta property="og:image" content="<?= $imageUrl ?>" />
    <meta property="og:url" content="<?= $protocol . $domain . htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8') ?>" />
    <meta property="og:type" content="website" />
    
    <!-- Twitter Card Meta Tags -->
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="<?= $fullTitle ?>" />
    <meta name="twitter:description" content="<?= $description ?>" />
    <meta name="twitter:image" content="<?= $imageUrl ?>" />
    
    <!-- Fallback / Standard Meta Tags -->
    <meta name="description" content="<?= $description ?>">

    <!-- Automatic Redirect for actual browsers (bots will just read the meta tags) -->
    <script>
        window.location.replace("<?= $targetUrl ?>");
    </script>
</head>
<body style="background:#f4f4f5; text-align:center; padding:50px; font-family:sans-serif; color:#555;">
    <p>Redirecting you to the listing...</p>
    <p><a href="<?= $targetUrl ?>" style="color:#0066cc;">Click here if you are not redirected</a></p>
</body>
</html>
