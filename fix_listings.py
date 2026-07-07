import re

with open('api/listings.php', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Update SELECT to include images
content = content.replace(
    "$stmt = $db->prepare('SELECT user_id, status, expires_at FROM listings WHERE id = ?');",
    "$stmt = $db->prepare('SELECT user_id, status, expires_at, images FROM listings WHERE id = ?');"
)

# 2. Fix Path Traversal and Storage Bloat in updateListing
old_img_processing = """            } elseif (str_starts_with($img, '/') || str_starts_with($img, 'http')) {
                // Already a stored URL — keep it as-is
                $processedImages[] = $img;
            }
        }
    }"""

new_img_processing = """            } elseif (str_starts_with($img, '/') || str_starts_with($img, 'http')) {
                // Ensure it's a valid URL, not a path traversal payload
                if (filter_var($img, FILTER_VALIDATE_URL) || preg_match('/^\/uploads\/lst_[0-9a-zA-Z_]+\.(jpg|jpeg|png|webp|gif)$/', $img)) {
                    $processedImages[] = $img;
                }
            }
        }
        
        // Storage Bloat: Active delete old images when replacing them
        $oldImages = json_decode($listing['images'], true) ?: [];
        $removedImages = array_diff($oldImages, $processedImages);
        foreach ($removedImages as $oldImg) {
            $filename = basename($oldImg);
            $filepath = UPLOAD_DIR . $filename;
            // Additional check to prevent any accidental path traversal deletion
            if (file_exists($filepath) && strpos(realpath($filepath), realpath(UPLOAD_DIR)) === 0) {
                @unlink($filepath);
            }
        }
    }"""
content = content.replace(old_img_processing, new_img_processing)

# Fix Path traversal in createListing too
old_create_img_processing = """            } elseif (str_starts_with($img, '/') || str_starts_with($img, 'http')) {
                // Already a URL
                $imageUrls[] = $img;
            }"""

new_create_img_processing = """            } elseif (str_starts_with($img, '/') || str_starts_with($img, 'http')) {
                if (filter_var($img, FILTER_VALIDATE_URL) || preg_match('/^\/uploads\/lst_[0-9a-zA-Z_]+\.(jpg|jpeg|png|webp|gif)$/', $img)) {
                    $imageUrls[] = $img;
                }
            }"""
content = content.replace(old_create_img_processing, new_create_img_processing)

# 3. Patch Fraud Bypass in updateListing
fraud_bypass_hook = """    // --- PRICE DROP ALERT LOGIC ---"""
fraud_bypass_patch = """    // --- FRAUD DETECTION HEURISTIC ---
    $price = max(0, (float)($b['price'] ?? 0));
    $lcCategory = strtolower($b['category'] ?? $listing['category'] ?? '');
    if (($lcCategory === 'cars' || str_contains($lcCategory, 'electronic')) && $price < 10.00 && ($b['price_type'] ?? '') !== 'free') {
        $db->prepare("UPDATE listings SET status = 'pending_review' WHERE id = ?")->execute([$id]);
    }

    // --- PRICE DROP ALERT LOGIC ---"""
content = content.replace(fraud_bypass_hook, fraud_bypass_patch)


# 4. Enforce Schema Validation in createListing
create_hook = """    $db = getDB();
    $uid = (int)$user['id'];"""
schema_validation_patch = """    $db = getDB();
    $uid = (int)$user['id'];

    // --- SCHEMA VALIDATION ---
    $schemaStmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'classifieds_schema'");
    $schemaJson = $schemaStmt->fetchColumn();
    $schema = json_decode($schemaJson, true);
    if ($schema && isset($schema['categories'])) {
        $validCats = array_column($schema['categories'], 'label');
        if (!in_array($category, $validCats)) {
             jsonError("Invalid category selected: " . htmlspecialchars($category));
        }
    }
"""
content = content.replace(create_hook, schema_validation_patch)


with open('api/listings.php', 'w', encoding='utf-8') as f:
    f.write(content)
