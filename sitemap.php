<?php
/**
 * ZipZapZoi Classifieds — Dynamic Sitemap Generator
 * GET /sitemap.php (accessible via /sitemap.xml)
 */
require_once __DIR__ . '/api/config.php';

header('Content-Type: text/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

$domain = 'https://www.zipzapzoi.com';
$today = date('Y-m-d');

// 1. Static Pages
$staticPages = [
    '/' => 1.0,
    '/classifieds.html' => 0.95,
    '/SearchResult.html' => 0.90,
    '/Post%20Listing.html' => 0.85,
    '/Classifieds%20Plans.html' => 0.80,
    '/wanted.html' => 0.75,
    '/about.html' => 0.65,
    '/contact.html' => 0.65,
    '/help.html' => 0.60,
    '/legal.html' => 0.50,
    '/Feedback.html' => 0.50
];

foreach ($staticPages as $path => $priority) {
    echo "  <url>\n";
    echo "    <loc>{$domain}{$path}</loc>\n";
    echo "    <lastmod>{$today}</lastmod>\n";
    echo "    <changefreq>daily</changefreq>\n";
    echo "    <priority>{$priority}</priority>\n";
    echo "  </url>\n";
}

// 2. Dynamic Listings
try {
    $db = getDB();
    // Only active listings that are not expired
    $stmt = $db->query("SELECT id, updated_at FROM listings WHERE status = 'active' AND expires_at > NOW() ORDER BY created_at DESC");
    while ($row = $stmt->fetch()) {
        $id = $row['id'];
        $lastmod = date('Y-m-d', strtotime($row['updated_at']));
        echo "  <url>\n";
        // Link directly to the SPA route. .htaccess routes bots to listing.php
        echo "    <loc>{$domain}/Listing%20Detail.html?id={$id}</loc>\n";
        echo "    <lastmod>{$lastmod}</lastmod>\n";
        echo "    <changefreq>weekly</changefreq>\n";
        echo "    <priority>0.80</priority>\n";
        echo "  </url>\n";
    }
} catch (Exception $e) {
    // Ignore DB errors in sitemap to prevent breaking XML syntax, just log it
    error_log("Sitemap generation error: " . $e->getMessage());
}

echo "</urlset>\n";
