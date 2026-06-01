<?php
/**
 * ZipZapZoi — Schema API
 *
 * GET  /api/schema.php → returns current schema (categories/subcategories/fields)
 *                        Public — no auth required (needed for Post Listing page)
 *
 * POST /api/schema.php → saves schema to DB
 *                        Admin only
 *
 * The schema is stored as a single JSON value in system_settings
 * with key = 'classifieds_schema'.
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    getSchema();
} elseif ($method === 'POST') {
    saveSchema();
} else {
    jsonError('Method not allowed', 405);
}

// ── GET — public, returns schema ──────────────────────────────────
function getSchema(): void {
    $db  = getDB();
    $row = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'classifieds_schema'")->fetch();

    if ($row && !empty($row['setting_value'])) {
        $schema = json_decode($row['setting_value'], true);
        if ($schema) {
            jsonOk($schema);
            return;
        }
    }

    // No schema in DB yet — return empty so frontend uses schema.js fallback
    jsonOk([
        'categories'    => [],
        'subcategories' => [],
        'fields'        => [],
        'source'        => 'default', // signals to frontend to use schema.js
    ]);
}

// ── POST — admin only, saves schema ──────────────────────────────
function saveSchema(): void {
    requireAdmin();
    $b = getBody();

    $schema = $b['schema'] ?? null;
    if (!$schema || !isset($schema['categories'], $schema['subcategories'], $schema['fields'])) {
        jsonError('Invalid schema: must have categories, subcategories, fields arrays.');
    }

    if (!is_array($schema['categories']) || !is_array($schema['subcategories']) || !is_array($schema['fields'])) {
        jsonError('Schema arrays must be proper arrays.');
    }

    $json = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $db = getDB();
    $db->prepare(
        "INSERT INTO system_settings (setting_key, setting_value)
         VALUES ('classifieds_schema', ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute([$json]);

    jsonOk(['message' => 'Schema saved to database. All users will see the updated categories.']);
}
