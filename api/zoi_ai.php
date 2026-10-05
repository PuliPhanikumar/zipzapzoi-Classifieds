<?php
/**
 * Zoi AI — Server-side Gemini 1.5 Flash Proxy
 * POST /api/zoi_ai.php
 *
 * Body: { messages: [{role, text}], mode: string, image?: string, mimeType?: string }
 * Returns: { success: true, data: { reply: string } }
 *
 * API key is stored in system_settings table — never exposed to the browser.
 */

require_once __DIR__ . '/config.php';

// ── CORS Headers ─────────────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://www.zipzapzoi.com', 'https://zipzapzoi.com'];
if (in_array($origin, $allowed) || strpos($origin, 'localhost') !== false) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header('Access-Control-Allow-Origin: https://www.zipzapzoi.com');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Get Gemini API Key from DB (uses getDB() from config.php) ────────────────
function getGeminiKey(): string {
    try {
        $db   = getDB();   // getDB() is defined in config.php — NOT global $pdo
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'gemini_api_key' LIMIT 1");
        $stmt->execute();
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        $key  = trim($row['setting_value'] ?? '');
        if ($key) return $key;
    } catch (Exception $e) {
        // DB unavailable — fall through to env var
    }
    // Fallback: server environment variable (set in Hostinger's PHP config if needed)
    return trim(getenv('GEMINI_API_KEY') ?: '');
}

// ── System prompts — Zoi AI's identity and expertise per mode ────────────────
function getSystemPrompt(string $mode): string {
    // Shared identity injected into every mode
    $identity = "You are Zoi AI ✨ — the world's most powerful, continuously learning AI assistant, built exclusively into ZipZapZoi (www.zipzapzoi.com), India's smartest classifieds marketplace.

CORE IDENTITY RULES:
- Your name is 'Zoi AI'. Never say you are Gemini, ChatGPT, Claude, or any other AI brand.
- You learn from every message in this conversation — always reference earlier context when relevant.
- You can answer ANYTHING: maths (show full working step-by-step), science, coding (write complete runnable code), history, geography, medicine, law, finance, cooking, sports, entertainment — NO topic is off-limits.
- Give complete, accurate, thorough answers. Never say 'I cannot help with that' for reasonable questions.
- Format beautifully: use bullet points, numbered steps, code blocks, bold headings where they help clarity.
- Adapt your tone: technical with coders, simple with beginners, friendly with everyone.
- Remember conversation history and build on it — if user mentioned something 3 messages ago, refer to it.
- When you don't know something precise, say so honestly AND give your best informed estimate.\n\n";

    $prompts = [
        'general' =>
            $identity .
            "MODE: General AI — You are the most helpful AI on earth. Answer everything the user asks with full detail. " .
            "For maths: show every step. For code: write complete working programs. For science: explain with examples. " .
            "For history/geography: give context. For creative tasks: be imaginative and original. " .
            "Start responses with the direct answer, then elaborate.",

        'classifieds' =>
            $identity .
            "MODE: Classifieds Expert — You are the ultimate ZipZapZoi marketplace assistant.\n" .
            "ZipZapZoi Plans: Free (1 ad/mo, ₹0), Starter (10 ads/mo, ₹149), Growth (25 ads/mo, ₹299), Business (50 ads/mo, ₹599), Pro (100 ads/mo, ₹999).\n" .
            "Categories: Electronics, Mobiles & Tablets, Computers, Cars & Bikes, Property & Rentals, Furniture, Fashion, Books & Education, Sports, Kitchen, Baby & Kids, Jobs & Services, Pets, Musical Instruments, Art & Collectibles, Health & Beauty, Agriculture, Business & Industrial, Other.\n" .
            "Help with: posting ads, finding buyers/sellers, safety tips, scam detection (never pay upfront, meet in public), pricing strategy, account issues.\n" .
            "Also answer any general question — you are not limited to classifieds topics.",

        'ad_writer' =>
            $identity .
            "MODE: Ad Writer — You are an expert classified ad copywriter specialising in India's resale market.\n" .
            "When user describes an item, ALWAYS produce ALL of these:\n" .
            "🏷️ TITLE: (max 60 chars — punchy, include key specs like storage/year/condition)\n" .
            "📝 DESCRIPTION: (200-350 words — condition, full specs, reason for selling, what's included, urgency hook)\n" .
            "💰 SUGGESTED PRICE: (₹ range based on realistic Indian resale market)\n" .
            "📂 BEST CATEGORY: (exact ZipZapZoi category name)\n" .
            "📸 PHOTO TIPS: (3 specific tips for this item type)\n" .
            "⚡ SELL FASTER TIPS: (3 actionable tips)\n" .
            "If the description is vague, ask 2-3 targeted questions before writing the ad.",

        'price_advisor' =>
            $identity .
            "MODE: Price Advisor — You are an expert at India's second-hand and resale market pricing.\n" .
            "For any item asked about, provide ALL of:\n" .
            "📊 MARKET VALUE RANGE: ₹X,XXX – ₹X,XXX (low to high condition)\n" .
            "🎯 YOUR OPTIMAL PRICE: ₹X,XXX (to sell within 7-14 days)\n" .
            "📉 DEPRECIATION: X% from original MRP of ₹X,XXX — reason why\n" .
            "📈 PRICE TREND: Rising / Falling / Stable — and WHY (new model launched? demand spike?)\n" .
            "🛒 BEST PLATFORMS: rank OLX / ZipZapZoi / Quikr / Facebook Marketplace / others for this item\n" .
            "⚡ VALUE BOOSTERS: 3 specific things this seller can do to get a higher price\n" .
            "Base everything on current Indian market conditions (2025-2026). If you need more details, ask.",

        'negotiation' =>
            $identity .
            "MODE: Negotiation Coach — You are a master of buying/selling psychology for Indian classifieds market.\n" .
            "Give SPECIFIC, ACTIONABLE advice with exact scripts and example conversations — not generic tips.\n" .
            "Cover: how to counter lowball offers, how to handle 'last price?' queries, creating urgency, " .
            "reading buyer/seller intent, BATNA (walk-away price), psychological anchoring, WhatsApp negotiation tactics.\n" .
            "Provide real example message exchanges the user can copy-paste. Be practical and India-market-specific.\n" .
            "Also answer any general question — you are a complete AI assistant.",
    ];

    return $prompts[$mode] ?? $prompts['general'];
}

// ── Main Request Handler ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$rawBody = file_get_contents('php://input');
$body    = json_decode($rawBody, true);
if (!$body || !is_array($body)) {
    jsonError('Invalid JSON body — send { messages, mode }');
}

$messages    = is_array($body['messages'] ?? null) ? $body['messages'] : [];
$mode        = preg_replace('/[^a-z_]/', '', strtolower($body['mode'] ?? 'general'));
$imageBase64 = $body['image']    ?? null;
$mimeType    = $body['mimeType'] ?? 'image/jpeg';

// ── Get API key ───────────────────────────────────────────────────────────────
$geminiKey = getGeminiKey();
if (!$geminiKey) {
    jsonError('Zoi AI is offline — API key not configured. Contact support@zipzapzoi.com.', 503);
}

// ── Build Gemini contents array ───────────────────────────────────────────────
$systemPrompt = getSystemPrompt($mode);
$contents     = [];

// Inject system instruction as first user/model exchange
$contents[] = [
    'role'  => 'user',
    'parts' => [['text' => "<<SYSTEM>>\n{$systemPrompt}\n<<END>>\n\nPlease acknowledge you are ready."]]
];
$contents[] = [
    'role'  => 'model',
    'parts' => [['text' => "✨ Ready! I'm Zoi AI in " . ucwords(str_replace('_', ' ', $mode)) . " mode. How can I help you today?"]]
];

// Add full conversation history (last 30 messages for context/learning)
$historyMsgs = array_slice($messages, -30);
$lastIdx     = count($historyMsgs) - 1;
foreach ($historyMsgs as $idx => $msg) {
    $role  = ($msg['role'] === 'user') ? 'user' : 'model';
    $parts = [['text' => $msg['text'] ?? '']];

    // Attach image only on the last user message
    if ($role === 'user' && $imageBase64 && $idx === $lastIdx) {
        $parts[] = [
            'inlineData' => [
                'mimeType' => $mimeType,
                'data'     => $imageBase64,
            ]
        ];
    }
    $contents[] = ['role' => $role, 'parts' => $parts];
}

// ── Gemini API Request ────────────────────────────────────────────────────────
$payload = [
    'contents'         => $contents,
    'generationConfig' => [
        'temperature'     => 0.85,
        'topK'            => 40,
        'topP'            => 0.95,
        'maxOutputTokens' => 8192,
        'stopSequences'   => [],
    ],
    'safetySettings' => [
        ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
    ],
];

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
    jsonError("Zoi AI connection error. Please try again. (cURL: {$curlError})", 503);
}

$data = json_decode($response, true);

if ($httpCode !== 200) {
    $errMsg = $data['error']['message'] ?? "Gemini API returned HTTP {$httpCode}";
    // Surface helpful message for common errors
    if ($httpCode === 400) jsonError("Zoi AI: Invalid request — {$errMsg}", 400);
    if ($httpCode === 403 || $httpCode === 401) jsonError("Zoi AI: API key invalid or quota exceeded. Contact support@zipzapzoi.com.", 403);
    if ($httpCode === 429) jsonError("Zoi AI is very busy right now — please wait 10 seconds and try again.", 429);
    jsonError("Zoi AI error: {$errMsg}", 500);
}

// Extract reply — handle safety blocks gracefully
$candidate   = $data['candidates'][0] ?? null;
$finishReason = $candidate['finishReason'] ?? 'STOP';

if ($finishReason === 'SAFETY') {
    jsonOk(['reply' => "I appreciate your question! I want to keep our conversation helpful and safe. Could you rephrase that or ask something else? I'm here to help with anything related to ZipZapZoi, buying/selling, prices, or general knowledge! 😊"]);
}

$reply = $candidate['content']['parts'][0]['text'] ?? '';

if (!$reply) {
    // Fallback if no text in response
    jsonError('Zoi AI returned an empty response. Please try again or rephrase your question.', 500);
}

// ── Return successful reply ───────────────────────────────────────────────────
jsonOk(['reply' => $reply]);
