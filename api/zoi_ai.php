<?php
/**
 * Zoi AI — Server-side Gemini 1.5 Flash Proxy
 * POST /api/zoi_ai.php
 *
 * Body: { messages: [{role, text}], mode: string, imageBase64?: string }
 * Returns: { reply: string }
 *
 * API key lives here on the server — never exposed to the browser.
 */

require_once __DIR__ . '/config.php';

// Allow CORS for same-origin website
header('Access-Control-Allow-Origin: https://www.zipzapzoi.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Gemini API Key (set this in system_settings table or hard-code temporarily) ──
function getGeminiKey(): string {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'gemini_api_key' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['setting_value'] ?? '';
    } catch (Exception $e) {
        return '';
    }
}

// ── System prompts by mode ─────────────────────────────────────────────────
function getSystemPrompt(string $mode): string {
    $prompts = [
        'general' => "You are Zoi AI — the world's most helpful, intelligent, and friendly AI assistant built into ZipZapZoi.
Your name is 'Zoi AI'. You are NOT powered by Google Gemini. You ARE Zoi AI.
You excel at: Mathematics (show step-by-step), Science, Coding (write working code), History, Geography, Language, Business, Creative writing, General knowledge.
Be accurate, detailed, and conversational. Adapt to the user's level. Format responses clearly with markdown when helpful.",

        'classifieds' => "You are Zoi AI — the expert assistant for ZipZapZoi, India's leading classifieds marketplace (www.zipzapzoi.com).
Help users with: posting ads, searching listings, categories, pricing, safety tips, scam avoidance, plans and pricing.
Plans: Free (1 ad), Starter ₹149 (10 ads), Growth ₹299 (25 ads), Business ₹599 (50 ads), Pro ₹999 (100 ads).
Categories: Electronics, Mobiles, Computers, Cars & Bikes, Property, Furniture, Fashion, Books, Sports, Kitchen, Baby & Kids, Jobs, Pets, Musical Instruments, Art, Health & Beauty, Agriculture, Business, Other.",

        'ad_writer' => "You are Zoi AI — an expert classified ad copywriter. Write compelling, honest, SEO-optimized ads.
When user describes an item, write: 1) Catchy Title (max 60 chars), 2) Professional Description (150-300 words), 3) Suggested Price (Indian market), 4) Recommended Category, 5) 2-3 tips to sell faster.",

        'price_advisor' => "You are Zoi AI — an expert Indian second-hand market price analyst.
For any item: give Current Market Value Range (₹), explain price factors, suggest best selling price, estimate depreciation, note price trend, and suggest best platform to sell.",

        'negotiation' => "You are Zoi AI — a master negotiation coach for India's classifieds market. Help both buyers and sellers with scripts, tactics, timing, and psychology of negotiating deals. Provide specific example scripts."
    ];
    return $prompts[$mode] ?? $prompts['general'];
}

// ── Main handler ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) jsonError('Invalid JSON body');

$messages     = $body['messages']    ?? [];
$mode         = clean($body['mode']  ?? 'general');
$imageBase64  = $body['image']       ?? null;
$mimeType     = $body['mimeType']    ?? 'image/jpeg';

$geminiKey = getGeminiKey();
if (!$geminiKey) {
    // Fallback: check environment variable
    $geminiKey = getenv('GEMINI_API_KEY') ?: '';
}
if (!$geminiKey) {
    jsonError('Zoi AI is not configured on this server. Please add gemini_api_key to system_settings.', 503);
}

$systemPrompt = getSystemPrompt($mode);

// ── Build Gemini contents array ───────────────────────────────────────────
$contents = [];

// System instruction as first user/model pair
$contents[] = [
    'role' => 'user',
    'parts' => [['text' => "<<SYSTEM>>\n$systemPrompt\n<<END>>\nAcknowledge briefly."]]
];
$contents[] = [
    'role' => 'model',
    'parts' => [['text' => "✨ Ready! I'm Zoi AI in " . ucfirst($mode) . " mode. How can I help you?"]]
];

// Add conversation history
foreach ($messages as $msg) {
    $role = ($msg['role'] === 'user') ? 'user' : 'model';
    $parts = [['text' => $msg['text']]];

    // If this is the latest user message and has an image attached
    if ($role === 'user' && $imageBase64 && $msg === end($messages)) {
        $parts[] = [
            'inlineData' => [
                'mimeType' => $mimeType,
                'data'     => $imageBase64
            ]
        ];
    }
    $contents[] = ['role' => $role, 'parts' => $parts];
}

// ── Call Gemini 1.5 Flash ─────────────────────────────────────────────────
$payload = [
    'contents' => $contents,
    'generationConfig' => [
        'temperature'     => 0.85,
        'topK'            => 40,
        'topP'            => 0.95,
        'maxOutputTokens' => 8192,
    ],
    'safetySettings' => [
        ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
    ]
];

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=$geminiKey";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) jsonError("Connection error: $curlError", 503);

$data = json_decode($response, true);

if ($httpCode !== 200) {
    $errMsg = $data['error']['message'] ?? 'Gemini API error';
    jsonError("Zoi AI error: $errMsg", 500);
}

$reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
if (!$reply) jsonError('Empty response from Zoi AI', 500);

jsonOk(['reply' => $reply]);
