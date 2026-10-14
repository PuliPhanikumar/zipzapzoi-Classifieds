<?php
/**
 * Zoi AI — Server-side Gemini 1.5 Flash Proxy
 * POST /api/zoi_ai.php
 *
 * Body: { messages: [{role, text}], mode: string, image?: string, mimeType?: string }
 * Returns: { success: true, data: { reply: string } }
 *          { success: false, error: "..." }
 *
 * CORS + DB connection handled by config.php (do NOT duplicate here).
 */

require_once __DIR__ . '/config.php';
// config.php already: sets CORS headers, defines getDB(), jsonOk(), jsonError(), clean()

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

// ── Get Gemini API Key from system_settings table ─────────────────────────────
function getGeminiKey(): string {
    try {
        $db   = getDB();   // getDB() defined in config.php via static PDO singleton
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'gemini_api_key' LIMIT 1");
        $stmt->execute();
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        $key  = trim($row['setting_value'] ?? '');
        if ($key) return $key;
    } catch (Throwable $e) {
        error_log('ZoiAI: DB key fetch failed: ' . $e->getMessage());
    }
    // Fallback: server environment variable
    return trim(getenv('GEMINI_API_KEY') ?: '');
}

// ── System prompts per mode ───────────────────────────────────────────────────
function getSystemPrompt(string $mode): string {
    $identity = "You are Zoi AI ✨ — the world's most powerful AI assistant, built exclusively into ZipZapZoi (www.zipzapzoi.com), India's smartest classifieds marketplace.

CORE RULES:
- Your name is 'Zoi AI'. Never identify as Gemini, ChatGPT, Claude, or any other AI brand.
- You learn from every message in this conversation — always use earlier context when relevant.
- You answer ANYTHING: maths (show full step-by-step working), science, coding (write complete runnable code), history, geography, medicine, law, finance, cooking, sports, entertainment — NO topic is off-limits for reasonable questions.
- Give complete, accurate, thorough answers. Format with bullet points, numbered steps, code blocks, bold headings.
- Adapt tone: technical with coders, simple with beginners, warm with everyone.
- Reference conversation history to show continuity and learning.\n\n";

    $modes = [
        'general' =>
            $identity .
            "MODE: General AI — Answer EVERYTHING thoroughly. " .
            "Maths: show every step. Code: write complete working programs. Science: explain with real examples. " .
            "Always give the direct answer first, then elaborate.",

        'classifieds' =>
            $identity .
            "MODE: ZipZapZoi Classifieds Expert.\n" .
            "Plans: Free (1 ad/mo ₹0) | Starter (10 ads ₹149) | Growth (25 ads ₹299) | Business (50 ads ₹599) | Pro (100 ads ₹999).\n" .
            "Categories: Electronics, Mobiles, Computers, Cars & Bikes, Property, Furniture, Fashion, Books, Sports, Kitchen, Baby & Kids, Jobs, Pets, Musical Instruments, Art, Health, Agriculture, Business, Other.\n" .
            "Help with: posting ads, finding buyers, safety tips, scam detection, pricing, account issues. Also answer any general question.",

        'ad_writer' =>
            $identity .
            "MODE: Expert Ad Copywriter for India's classifieds market.\n" .
            "For any item described, ALWAYS produce:\n" .
            "🏷️ TITLE: (max 60 chars — punchy, include key specs)\n" .
            "📝 DESCRIPTION: (200-300 words — condition, specs, reason for selling, what's included)\n" .
            "💰 SUGGESTED PRICE: (₹ based on realistic Indian resale market)\n" .
            "📂 BEST CATEGORY: (exact ZipZapZoi category)\n" .
            "⚡ SELL FASTER TIPS: (3 actionable tips)\n" .
            "If description is too vague, ask 2-3 clarifying questions first.",

        'price_advisor' =>
            $identity .
            "MODE: Indian Resale Market Price Expert.\n" .
            "For any item, provide:\n" .
            "📊 MARKET VALUE RANGE: ₹X – ₹Y\n" .
            "🎯 OPTIMAL SELLING PRICE: ₹X (to sell in 7-14 days)\n" .
            "📉 DEPRECIATION: X% from original MRP — reason why\n" .
            "📈 PRICE TREND: Rising/Falling/Stable — and WHY\n" .
            "🛒 BEST PLATFORMS: ranked for this item type\n" .
            "⚡ VALUE BOOSTERS: 3 things seller can do to get higher price",

        'negotiation' =>
            $identity .
            "MODE: Negotiation Coach for Indian classifieds market.\n" .
            "Give SPECIFIC scripts and real example message exchanges — not generic tips.\n" .
            "Cover: countering lowballs, handling 'last price?' queries, creating urgency, psychology of pricing, WhatsApp negotiation tactics, when to walk away.\n" .
            "Also answer any general question.",
    ];

    return $modes[$mode] ?? $modes['general'];
}

// ── Parse Request ─────────────────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');
$body    = json_decode($rawBody, true);
if (!$body || !is_array($body)) {
    jsonError('Invalid JSON — send: { messages: [], mode: "general" }');
}

$messages    = is_array($body['messages'] ?? null) ? $body['messages'] : [];
$mode        = preg_replace('/[^a-z_]/', '', strtolower($body['mode'] ?? 'general'));
$imageBase64 = $body['image']    ?? null;
$mimeType    = $body['mimeType'] ?? 'image/jpeg';

// ── Validate API key ──────────────────────────────────────────────────────────
$geminiKey = getGeminiKey();
if (empty($geminiKey)) {
    jsonError('Zoi AI offline — Gemini API key not found in system_settings. Please add gemini_api_key to the database.', 503);
}

// ── Build Gemini request ──────────────────────────────────────────────────────
$systemPrompt = getSystemPrompt($mode);
$contents     = [];

// System instruction as opening user/model pair
$contents[] = ['role' => 'user',  'parts' => [['text' => "<<SYSTEM>>\n{$systemPrompt}\n<<END>>\nAcknowledge briefly."]]];
$contents[] = ['role' => 'model', 'parts' => [['text' => "✨ Ready! I'm Zoi AI — " . ucwords(str_replace('_', ' ', $mode)) . " mode. How can I help?"]]];

// Add conversation history (last 30 messages for learning/context)
$historySlice = array_slice($messages, -30);
$lastIdx      = count($historySlice) - 1;
foreach ($historySlice as $idx => $msg) {
    $role  = ($msg['role'] === 'user') ? 'user' : 'model';
    $text  = trim($msg['text'] ?? '');
    $parts = $text ? [['text' => $text]] : [['text' => '...']];

    // Attach image only on last user message
    if ($role === 'user' && $imageBase64 && $idx === $lastIdx) {
        $parts[] = ['inlineData' => ['mimeType' => $mimeType, 'data' => $imageBase64]];
    }
    $contents[] = ['role' => $role, 'parts' => $parts];
}

$payload = [
    'contents'         => $contents,
    'generationConfig' => [
        'temperature'     => 0.85,
        'topK'            => 40,
        'topP'            => 0.95,
        'maxOutputTokens' => 8192,
    ],
    'safetySettings'   => [
        ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
    ],
];

// ── Call Gemini API ───────────────────────────────────────────────────────────
$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$geminiKey}";

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 45,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// ── Handle Errors ─────────────────────────────────────────────────────────────
if ($curlError) {
    error_log("ZoiAI cURL error: $curlError");
    jsonError("Zoi AI connection failed. Check server internet access. ($curlError)", 503);
}

$data = json_decode($response, true);

if ($httpCode !== 200) {
    $errMsg = $data['error']['message'] ?? "Gemini API returned HTTP {$httpCode}";
    error_log("ZoiAI Gemini error HTTP $httpCode: $errMsg");
    if ($httpCode === 400) jsonError("Bad request to Gemini: $errMsg", 400);
    if ($httpCode === 401 || $httpCode === 403) jsonError("Gemini API key invalid or quota exceeded. Update gemini_api_key in system_settings.", 403);
    if ($httpCode === 429) jsonError("Zoi AI is very busy — please wait 10 seconds and try again.", 429);
    jsonError("Zoi AI error: $errMsg", 500);
}

// ── Extract reply ─────────────────────────────────────────────────────────────
$candidate    = $data['candidates'][0] ?? null;
$finishReason = $candidate['finishReason'] ?? 'STOP';

if ($finishReason === 'SAFETY') {
    jsonOk(['reply' => "I'm here to keep things helpful and safe! Could you rephrase that? I can help with ZipZapZoi, buying/selling, prices, or any general topic. 😊"]);
}

$reply = $candidate['content']['parts'][0]['text'] ?? '';

if (empty($reply)) {
    error_log("ZoiAI: Empty reply from Gemini. FinishReason: $finishReason. Raw: " . substr($response, 0, 500));
    jsonError('Zoi AI returned an empty response. Please try again or rephrase your question.', 500);
}

jsonOk(['reply' => $reply]);
