<?php
/**
 * ZipZapZoi — Zoi AI Setup & Diagnostic Tool
 * VISIT: https://www.zipzapzoi.com/api/zoi_setup.php
 * DELETE this file after setup is complete!
 */

require_once __DIR__ . '/config.php';

$action  = $_GET['action'] ?? 'check';
$key     = trim($_GET['key'] ?? '');

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
<title>Zoi AI Setup</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 700px; margin: 40px auto; padding: 20px; background: #0f0f1a; color: #e2e8f0; }
  h1 { color: #7c3aed; } h2 { color: #a78bfa; margin-top: 30px; }
  .ok   { background: #052e16; border-left: 4px solid #22c55e; padding: 12px 16px; border-radius: 6px; margin: 8px 0; }
  .err  { background: #450a0a; border-left: 4px solid #ef4444; padding: 12px 16px; border-radius: 6px; margin: 8px 0; }
  .info { background: #1e1b4b; border-left: 4px solid #7c3aed; padding: 12px 16px; border-radius: 6px; margin: 8px 0; }
  code  { background: #1e1e2e; padding: 2px 8px; border-radius: 4px; font-size: 13px; color: #cdd6f4; }
  input[type=text] { width: 100%; padding: 10px; background: #1e1e2e; border: 1px solid #7c3aed; color: #e2e8f0; border-radius: 6px; font-size: 14px; margin: 8px 0; box-sizing: border-box; }
  button { background: #7c3aed; color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-size: 15px; margin-top: 8px; }
  button:hover { background: #6d28d9; }
  .warn { background: #431407; border-left: 4px solid #f97316; padding: 12px 16px; border-radius: 6px; margin: 8px 0; }
</style>
</head>
<body>
<h1>✨ Zoi AI Setup Tool</h1>
<div class="warn">⚠️ <strong>Security:</strong> Delete this file from your server after you're done!</div>

<?php

// ── Step 1: Test DB Connection ────────────────────────────────────────────────
echo "<h2>Step 1 — Database Connection</h2>";
try {
    $db = getDB();
    echo "<div class='ok'>✅ Database connected successfully</div>";
} catch (Throwable $e) {
    echo "<div class='err'>❌ Database connection FAILED: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<div class='err'>Fix your DB credentials in config.php or .env file</div>";
    die("</body></html>");
}

// ── Step 2: Check system_settings table ──────────────────────────────────────
echo "<h2>Step 2 — system_settings Table</h2>";
try {
    $db->query("SELECT 1 FROM system_settings LIMIT 1");
    echo "<div class='ok'>✅ system_settings table exists</div>";

    // Show all current keys (masked)
    $rows = $db->query("SELECT setting_key, LEFT(setting_value, 8) AS preview, LENGTH(setting_value) AS len FROM system_settings")->fetchAll(PDO::FETCH_ASSOC);
    echo "<div class='info'><strong>Current keys in table:</strong><br>";
    if (empty($rows)) {
        echo "⚠️ Table is EMPTY — no keys saved yet!";
    } else {
        foreach ($rows as $r) {
            echo "<code>{$r['setting_key']}</code> → <code>{$r['preview']}...</code> ({$r['len']} chars)<br>";
        }
    }
    echo "</div>";
} catch (Throwable $e) {
    echo "<div class='err'>❌ system_settings table does NOT exist!</div>";
    echo "<div class='info'>Run this SQL in phpMyAdmin to create it:<br><br>";
    echo "<code style='display:block;white-space:pre;padding:12px;'>CREATE TABLE IF NOT EXISTS system_settings (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  setting_key   VARCHAR(100) NOT NULL UNIQUE,
  setting_value TEXT NOT NULL,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</code></div>";
}

// ── Step 3: Check Gemini key specifically ────────────────────────────────────
echo "<h2>Step 3 — Gemini API Key</h2>";
try {
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'gemini_api_key' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentKey = $row['setting_value'] ?? '';

    if (empty($currentKey)) {
        echo "<div class='err'>❌ gemini_api_key is NOT set in system_settings</div>";
    } elseif (strpos($currentKey, 'AIza') === 0) {
        echo "<div class='ok'>✅ Gemini key found and looks valid: <code>" . substr($currentKey, 0, 8) . "..." . substr($currentKey, -4) . "</code></div>";
    } else {
        echo "<div class='err'>⚠️ Gemini key found but INVALID format: <code>" . substr($currentKey, 0, 8) . "...</code><br>Valid Gemini keys start with <code>AIza</code></div>";
    }
} catch (Throwable $e) {
    echo "<div class='err'>❌ Could not check key: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// ── Step 4: Save/Update key form ─────────────────────────────────────────────
echo "<h2>Step 4 — Save / Update Gemini API Key</h2>";

// Handle save action
if ($action === 'save' && !empty($key)) {
    if (strpos($key, 'AIza') !== 0) {
        echo "<div class='err'>❌ Invalid key format! Gemini keys start with <strong>AIza</strong>. Get yours at <a href='https://aistudio.google.com/app/apikey' target='_blank' style='color:#a78bfa'>aistudio.google.com</a></div>";
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('gemini_api_key', :key) ON DUPLICATE KEY UPDATE setting_value = :key, updated_at = NOW()");
            $stmt->execute([':key' => $key]);
            echo "<div class='ok'>✅ <strong>Gemini API key saved successfully!</strong><br>Key: <code>" . substr($key, 0, 8) . "..." . substr($key, -4) . "</code><br><br>🎉 Zoi AI is now active! Test it at <a href='https://www.zipzapzoi.com' target='_blank' style='color:#a78bfa'>www.zipzapzoi.com</a></div>";
        } catch (Throwable $e) {
            echo "<div class='err'>❌ Save failed: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

echo "
<div class='info'>
  Paste your Gemini API key below (get it from 
  <a href='https://aistudio.google.com/app/apikey' target='_blank' style='color:#a78bfa'>aistudio.google.com/app/apikey</a>):
</div>
<form method='GET'>
  <input type='hidden' name='action' value='save'>
  <input type='text' name='key' placeholder='AIzaSy...' value='" . htmlspecialchars($key) . "'>
  <button type='submit'>💾 Save Gemini API Key</button>
</form>";

// ── Step 5: Test Zoi AI live ──────────────────────────────────────────────────
echo "<h2>Step 5 — Test Zoi AI</h2>";
echo "<div class='info'>After saving your key, test it live:</div>
<form method='GET' action='/api/zoi_test.php' target='_blank'>
  <button type='button' onclick=\"
    fetch('/api/zoi_ai.php', {method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({messages:[{role:'user',text:'Hello Zoi! Say: Zoi AI is working!'}], mode:'general'})})
    .then(r=>r.json()).then(d=>{
      document.getElementById('test_result').innerText = d.success ? '✅ ' + d.data.reply : '❌ ' + (d.error||'Failed');
      document.getElementById('test_result').style.display='block';
    }).catch(e=>{document.getElementById('test_result').innerText='❌ Network error: '+e; document.getElementById('test_result').style.display='block';});
  \">🧪 Test Zoi AI Now</button>
</form>
<div id='test_result' style='display:none; margin-top:12px; padding:12px; background:#1e1b4b; border-radius:6px; white-space:pre-wrap;'></div>";

echo "<h2>⚠️ After Setup</h2>
<div class='warn'><strong>DELETE this file immediately</strong> after you're done:<br>
Delete <code>api/zoi_setup.php</code> from your Hostinger File Manager</div>";

?>
</body>
</html>
