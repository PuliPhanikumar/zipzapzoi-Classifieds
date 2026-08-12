<?php
/**
 * ZipZapZoi Classifieds — OpenGraph Wrapper
 * GET /listing.php?id=123
 * Serves dynamic meta tags for social media scrapers, then redirects real users
 * to the actual SPA view (Listing Detail.html).
 */
require_once __DIR__ . '/api/config.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header("Location: /classifieds.html");
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT title, description, price, images FROM listings WHERE id = ?");
$stmt->execute([$id]);
$listing = $stmt->fetch();

if (!$listing) {
    header("Location: /404.html");
    exit;
}

$title = htmlspecialchars($listing['title']);
// Truncate description for SEO
$desc = htmlspecialchars(mb_substr(strip_tags($listing['description']), 0, 150)) . '...';
$priceStr = '$' . number_format($listing['price'], 2);
$fullTitle = "$title - $priceStr | ZipZapZoi Classifieds";

$imageUrl = 'https://www.zipzapzoi.com/images/default-listing.jpg';
$images = json_decode($listing['images'], true);
if (is_array($images) && count($images) > 0) {
    // If it's a relative path, make it absolute. If it's already absolute (e.g. from api/uploads.php), use it directly.
    $firstImg = $images[0];
    if (strpos($firstImg, 'http') === 0) {
        $imageUrl = $firstImg;
    } else {
        $imageUrl = 'https://www.zipzapzoi.com' . (strpos($firstImg, '/') === 0 ? '' : '/') . $firstImg;
    }
}
$currentUrl = "https://www.zipzapzoi.com/listing.php?id={$id}";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $fullTitle ?></title>
    <meta name="description" content="<?= $desc ?>">
    
    <!-- Open Graph / Facebook / WhatsApp -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= $currentUrl ?>">
    <meta property="og:title" content="<?= $fullTitle ?>">
    <meta property="og:description" content="<?= $desc ?>">
    <meta property="og:image" content="<?= $imageUrl ?>">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?= $currentUrl ?>">
    <meta property="twitter:title" content="<?= $fullTitle ?>">
    <meta property="twitter:description" content="<?= $desc ?>">
    <meta property="twitter:image" content="<?= $imageUrl ?>">
    
    <script>
        // Redirect real users to the actual SPA application route
        window.location.replace("/Listing Detail.html?id=<?= $id ?>");
    </script>
</head>
<body style="background:#f8f9fa; display:flex; justify-content:center; align-items:center; height:100vh; font-family:sans-serif;">
    <p>Redirecting to listing...</p>
</body>
</html>
