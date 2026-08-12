<?php
/**
 * ZipZapZoi — Dynamic Listings Sitemap
 * GET /api/sitemap_listings.php
 *
 * Generates an XML sitemap of all ACTIVE listings for Google indexing.
 * Each listing gets its own URL: /Listing Detail.html?id=X
 * This allows Google to discover and index individual listing pages.
 *
 * Performance:
 * - Only fetches id, title, updated_at (minimal columns)
 * - Limited to 50,000 URLs per sitemap (Google limit)
 * - Outputs XML directly with correct Content-Type
 */

// Override JSON content type set by config.php
ob_start();
require_once __DIR__ . '/config.php';
ob_end_clean(); // Discard the JSON header set by config.php

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour

$db = getDB();

try {
    $stmt = $db->prepare(
        "SELECT id, title, updated_at, category, location_city, location_state
         FROM listings
         WHERE status = 'active'
           AND (expires_at IS NULL OR expires_at > NOW())
         ORDER BY updated_at DESC
         LIMIT 50000"
    );
    $stmt->execute();
    $listings = $stmt->fetchAll();
} catch (Exception $e) {
    $listings = [];
}

$baseUrl = 'https://www.zipzapzoi.com';

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
echo '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

foreach ($listings as $l) {
    $url   = $baseUrl . '/Listing%20Detail.html?id=' . (int)$l['id'];
    $title = htmlspecialchars($l['title'] ?? '', ENT_XML1, 'UTF-8');
    $mod   = date('Y-m-d', strtotime($l['updated_at'] ?? 'now'));

    echo "  <url>\n";
    echo "    <loc>" . $url . "</loc>\n";
    echo "    <lastmod>" . $mod . "</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.70</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>';
