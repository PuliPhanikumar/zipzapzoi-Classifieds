<?php
/**
 * Zoi AI — Smart Hybrid AI Engine
 * ─────────────────────────────────────────────────────────────
 * MODE 1 (preferred): Gemini 1.5 Flash via API  → needs valid AIzaSy... key
 * MODE 2 (fallback):  Standalone AI engine       → works WITHOUT any API key
 *
 * The standalone engine:
 *   • Learns from every Q&A pair (stored in zoi_knowledge table)
 *   • Searches knowledge base using keyword matching + TF-IDF scoring
 *   • Has built-in domain knowledge about ZipZapZoi, classifieds, pricing
 *   • Handles maths, general knowledge, and contextual responses
 *   • Improves over time as more questions are asked
 *
 * POST /api/zoi_ai.php
 * Body: { messages: [{role, text}], mode: string }
 * Returns: { success: true, data: { reply: string, engine: "gemini"|"standalone" } }
 */

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
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

// ── Get Gemini Key ────────────────────────────────────────────────────────────
function getGeminiKey(): string {
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'gemini_api_key' LIMIT 1");
        $stmt->execute();
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        $key  = trim($row['setting_value'] ?? '');
        if ($key) return $key;
    } catch (Throwable $e) {
        error_log('ZoiAI: DB key fetch failed: ' . $e->getMessage());
    }
    return trim(getenv('GEMINI_API_KEY') ?: '');
}

// ── System prompts ────────────────────────────────────────────────────────────
function getSystemPrompt(string $mode): string {
    $identity = "You are Zoi AI ✨ — the world's most powerful AI assistant built exclusively into ZipZapZoi (www.zipzapzoi.com), India's smartest classifieds marketplace.\n\nCORE RULES:\n- Your name is 'Zoi AI'. Never call yourself Gemini, ChatGPT, or any other AI.\n- Learn from every message in this conversation.\n- Answer ANYTHING: maths, science, coding, history, geography, medicine, law, finance, cooking, sports, entertainment.\n- Give complete, thorough answers. Use bullet points, numbered steps, bold headings.\n- Always reference earlier context to show continuity.\n\n";

    $modes = [
        'general'      => $identity . "MODE: General AI — Answer everything thoroughly. Maths: show every step. Code: write complete working programs.",
        'classifieds'  => $identity . "MODE: ZipZapZoi Classifieds Expert.\nPlans: Free (1 ad/mo ₹0) | Starter (10 ads ₹149) | Growth (25 ads ₹299) | Business (50 ads ₹599) | Pro (100 ads ₹999).\nCategories: Electronics, Mobiles, Computers, Cars & Bikes, Property, Furniture, Fashion, Books, Sports, Kitchen, Baby & Kids, Jobs, Pets, Musical Instruments, Art, Health, Agriculture, Business, Other.\nHelp with: posting ads, finding buyers, safety tips, scam detection, pricing, account issues.",
        'ad_writer'    => $identity . "MODE: Expert Ad Copywriter for India's classifieds market.\nFor any item, always produce:\n🏷️ TITLE: (max 60 chars — punchy, include key specs)\n📝 DESCRIPTION: (200-300 words — condition, specs, reason for selling)\n💰 SUGGESTED PRICE: (₹ based on realistic Indian resale market)\n📂 BEST CATEGORY: (exact ZipZapZoi category)\n⚡ SELL FASTER TIPS: (3 actionable tips)",
        'price_advisor'=> $identity . "MODE: Indian Resale Market Price Expert.\nFor any item, provide:\n📊 MARKET VALUE RANGE: ₹X – ₹Y\n🎯 OPTIMAL SELLING PRICE: ₹X\n📉 DEPRECIATION: X% from MRP — reason why\n📈 PRICE TREND: Rising/Falling/Stable\n🛒 BEST PLATFORMS: ranked for this item type",
        'negotiation'  => $identity . "MODE: Negotiation Coach for Indian classifieds market.\nGive SPECIFIC scripts and real example message exchanges.\nCover: countering lowballs, handling 'last price?' queries, creating urgency, WhatsApp negotiation tactics.",
    ];
    return $modes[$mode] ?? $modes['general'];
}

// ──────────────────────────────────────────────────────────────────────────────
//  GEMINI ENGINE
// ──────────────────────────────────────────────────────────────────────────────
function callGemini(array $messages, string $mode, ?string $imageBase64, string $mimeType, string $apiKey): ?string {
    $systemPrompt = getSystemPrompt($mode);
    $contents = [];
    $contents[] = ['role' => 'user',  'parts' => [['text' => "<<SYSTEM>>\n{$systemPrompt}\n<<END>>\nAcknowledge briefly."]]];
    $contents[] = ['role' => 'model', 'parts' => [['text' => "✨ Ready! I'm Zoi AI. How can I help?"]]];

    $historySlice = array_slice($messages, -30);
    $lastIdx      = count($historySlice) - 1;
    foreach ($historySlice as $idx => $msg) {
        $role  = ($msg['role'] === 'user') ? 'user' : 'model';
        $text  = trim($msg['text'] ?? '');
        $parts = $text ? [['text' => $text]] : [['text' => '...']];
        if ($role === 'user' && $imageBase64 && $idx === $lastIdx) {
            $parts[] = ['inlineData' => ['mimeType' => $mimeType, 'data' => $imageBase64]];
        }
        $contents[] = ['role' => $role, 'parts' => $parts];
    }

    $payload = [
        'contents'         => $contents,
        'generationConfig' => ['temperature' => 0.85, 'topK' => 40, 'topP' => 0.95, 'maxOutputTokens' => 8192],
        'safetySettings'   => [
            ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
        ],
    ];

    $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";
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

    if ($curlError || $httpCode !== 200) {
        error_log("ZoiAI Gemini failed: HTTP $httpCode / $curlError");
        return null; // fall through to standalone
    }

    $data      = json_decode($response, true);
    $candidate = $data['candidates'][0] ?? null;
    if (!$candidate || ($candidate['finishReason'] ?? '') === 'SAFETY') return null;

    $reply = $candidate['content']['parts'][0]['text'] ?? '';
    return $reply ?: null;
}

// ──────────────────────────────────────────────────────────────────────────────
//  STANDALONE AI ENGINE  (no external API)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Ensure the zoi_knowledge table exists for persistent learning.
 */
function ensureKnowledgeTable(): void {
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS zoi_knowledge (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            question    TEXT NOT NULL,
            answer      TEXT NOT NULL,
            keywords    TEXT,
            category    VARCHAR(50) DEFAULT 'general',
            weight      INT DEFAULT 1,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FULLTEXT KEY ft_qa (question, answer)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $e) {
        error_log("ZoiAI: Could not create zoi_knowledge table: " . $e->getMessage());
    }
}

/**
 * Search the knowledge base for relevant answers.
 * Uses FULLTEXT search + keyword overlap scoring.
 */
function searchKnowledge(string $query): ?string {
    try {
        $db = getDB();
        $clean = preg_replace('/[^\w\s]/u', ' ', $query);
        $clean = trim($clean);
        if (strlen($clean) < 2) return null;

        // FULLTEXT match
        $stmt = $db->prepare(
            "SELECT answer, weight,
                    MATCH(question, answer) AGAINST (:q IN NATURAL LANGUAGE MODE) AS score
             FROM zoi_knowledge
             WHERE MATCH(question, answer) AGAINST (:q IN NATURAL LANGUAGE MODE)
             ORDER BY score DESC, weight DESC
             LIMIT 3"
        );
        $stmt->execute([':q' => $clean]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows) && $rows[0]['score'] > 0) {
            // Bump weight (usage count)
            $db->prepare("UPDATE zoi_knowledge SET weight = weight + 1 WHERE answer = :a LIMIT 1")
               ->execute([':a' => $rows[0]['answer']]);
            return $rows[0]['answer'];
        }

        // Keyword fallback — LIKE search on keywords column
        $words = array_filter(explode(' ', strtolower($clean)), fn($w) => strlen($w) > 3);
        if (empty($words)) return null;

        foreach ($words as $word) {
            $stmt2 = $db->prepare("SELECT answer FROM zoi_knowledge WHERE keywords LIKE :kw ORDER BY weight DESC LIMIT 1");
            $stmt2->execute([':kw' => '%' . $word . '%']);
            $row = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row['answer'];
        }
    } catch (Throwable $e) {
        error_log("ZoiAI knowledge search error: " . $e->getMessage());
    }
    return null;
}

/**
 * Save a new Q&A pair to the knowledge base.
 */
function saveKnowledge(string $question, string $answer, string $category = 'general'): void {
    try {
        $db = getDB();
        $words    = preg_split('/\s+/', strtolower($question));
        $keywords = implode(',', array_filter($words, fn($w) => strlen($w) > 3));

        $stmt = $db->prepare(
            "INSERT INTO zoi_knowledge (question, answer, keywords, category)
             VALUES (:q, :a, :kw, :cat)
             ON DUPLICATE KEY UPDATE answer = :a, weight = weight + 1, updated_at = NOW()"
        );
        $stmt->execute([':q' => substr($question, 0, 1000), ':a' => substr($answer, 0, 5000), ':kw' => $keywords, ':cat' => $category]);
    } catch (Throwable $e) {
        error_log("ZoiAI: Could not save knowledge: " . $e->getMessage());
    }
}

/**
 * Main standalone AI engine — rule-based + knowledge base.
 */
function standaloneAI(array $messages, string $mode): string {
    $userMsg = '';
    foreach (array_reverse($messages) as $m) {
        if (($m['role'] ?? '') === 'user') { $userMsg = trim($m['text'] ?? ''); break; }
    }

    $q       = strtolower($userMsg);
    $context = buildContext($messages);

    // 1. Greetings
    if (preg_match('/^(hi|hello|hey|namaste|vanakkam|hii|helo|sup|howdy|yo)\b/i', $q)) {
        return randomPick([
            "✨ **Hello! I'm Zoi AI** — your smart assistant for ZipZapZoi!\n\nI can help you with:\n• 🏪 Buying & selling on ZipZapZoi\n• ✍️ Writing perfect classified ads\n• 💰 Getting the best price for your items\n• 🤝 Negotiation tips\n• 🧠 General questions — maths, coding, science, anything!\n\nWhat's on your mind?",
            "✨ **Namaste! Zoi AI here!** 🙏\n\nI'm your AI assistant for ZipZapZoi — India's smartest classifieds marketplace. Ask me anything about buying, selling, pricing, or any general topic. How can I help you today?",
            "Hey! 👋 **Zoi AI at your service!** I'm here to make your ZipZapZoi experience smoother. Want to post an ad, check prices, or just ask a random question? Go ahead — I'm all ears! 😊",
        ]);
    }

    // 2. Who are you / what are you
    if (preg_match('/(who are you|what are you|introduce yourself|your name|zoi ai|tell me about yourself)/i', $q)) {
        return "✨ **I'm Zoi AI** — ZipZapZoi's built-in AI assistant!\n\n**What I can do:**\n• 🏪 **Classifieds Expert** — help you buy, sell, post ads\n• ✍️ **Ad Writer** — write compelling ad copy for any item\n• 💰 **Price Advisor** — estimate fair market prices in India\n• 🤝 **Negotiation Coach** — scripts to handle lowballers\n• 🧠 **General AI** — maths, science, coding, history, anything!\n\nI learn from every conversation and get smarter over time. Try me! 🚀";
    }

    // 3. ZipZapZoi plans
    if (preg_match('/(plan|subscription|price|cost|fee|paid|free|starter|growth|business|pro)/i', $q) &&
        preg_match('/(plan|subscribe|membership|upgrade|listing limit)/i', $q)) {
        return "📋 **ZipZapZoi Subscription Plans:**\n\n| Plan | Price | Listings/Month |\n|------|-------|---------------|\n| **Free** | ₹0 | 1 ad |\n| **Starter** | ₹149 | 10 ads |\n| **Growth** | ₹299 | 25 ads |\n| **Business** | ₹599 | 50 ads |\n| **Pro** | ₹999 | 100 ads |\n\n💡 **My recommendation:** Start with **Starter (₹149)** if you're a casual seller, or **Growth (₹299)** if you sell regularly.\n\nAll plans include: Featured listings support, photo uploads, direct buyer messaging, and price alert tools!";
    }

    // 4. How to post ad
    if (preg_match('/(post|list|sell|create|add).{0,20}(ad|listing|item|product)/i', $q) ||
        preg_match('/(how.{0,10}sell|want to sell)/i', $q)) {
        return "🏪 **How to Post Your Ad on ZipZapZoi:**\n\n**Step 1:** Click **\"Post Ad\"** button in the top navigation\n**Step 2:** Choose the right **category** for your item (Electronics, Mobiles, Cars, etc.)\n**Step 3:** Fill in:\n   • 🏷️ Catchy title (include brand + model + condition)\n   • 📝 Detailed description (mention what's included, any defects)\n   • 💰 Fair asking price (I can help with pricing!)\n   • 📍 Your location\n   • 📷 Clear photos (more photos = faster sale!)\n**Step 4:** Choose your **contact preference** — phone, WhatsApp, or in-app message\n**Step 5:** Hit **Publish!** 🎉\n\n💡 **Pro tip:** Ads with 5+ photos sell 3x faster. Use the **Price Advisor** mode to set the right price!";
    }

    // 5. Categories
    if (preg_match('/(categor|what can.{0,10}sell|what.{0,10}sell)/i', $q)) {
        return "📂 **ZipZapZoi Categories — You Can Sell Anything!**\n\n📱 **Electronics** — TVs, Cameras, Headphones, Speakers\n📲 **Mobiles** — Phones, Tablets, Accessories, SIM cards\n💻 **Computers** — Laptops, Desktops, Printers, Parts\n🚗 **Cars & Bikes** — Cars, Motorcycles, Scooters, Spare parts\n🏠 **Property** — Rent/Sale Flats, Houses, Plots, PG\n🛋️ **Furniture** — Sofas, Beds, Wardrobes, Office furniture\n👗 **Fashion** — Clothing, Shoes, Bags, Jewellery\n📚 **Books** — Textbooks, Novels, Competitive exam books\n⚽ **Sports** — Gym equipment, Cycles, Cricket/Football gear\n🍳 **Kitchen** — Appliances, Cookware, Dining sets\n👶 **Baby & Kids** — Toys, Clothing, Strollers, School items\n💼 **Jobs** — Part-time, Full-time, Work-from-home\n🐾 **Pets** — Dogs, Cats, Aquarium fish, Accessories\n🎸 **Musical Instruments** — Guitars, Keyboards, Drums\n🎨 **Art & Antiques** — Paintings, Collectibles, Handicrafts\n🌾 **Agriculture** — Seeds, Tools, Farm equipment\n🏢 **Business** — Machinery, Office equipment, Franchises";
    }

    // 6. Safety / scam detection
    if (preg_match('/(scam|fraud|safe|safety|trust|fake|cheat|avoid|suspicious)/i', $q)) {
        return "🛡️ **Staying Safe on ZipZapZoi — Anti-Scam Guide:**\n\n**🚨 RED FLAGS to watch for:**\n• Buyer offers more than your asking price\n• Asks you to pay first (shipping, processing, customs)\n• Wants to move conversation off the platform immediately\n• Can't meet in person / always \"out of town\"\n• Sends a cheque — wait for it to clear!\n• Asks for OTP, passwords, or bank details\n\n**✅ Safe Selling Tips:**\n• Meet buyers in **public places** (malls, police stations, etc.)\n• **Never share OTP** with anyone — ZipZapZoi will never ask\n• Accept **cash on delivery** for expensive items\n• Verify the buyer's profile — check reviews and join date\n• Take payment **before handing over the item**\n• Trust your gut — if it feels wrong, it probably is!\n\n**📞 Report fraud:** Use the **\"Report Listing\"** button on any suspicious ad.";
    }

    // 7. Maths — solve equations
    if (preg_match('/(\d+\s*%|\d+[\+\-\*\/\^]\d+|\d+\s+percent|\bsolve\b|\bcalculate\b|\bmath\b|\bequation\b|\bpercentage\b|\bdiscount\b)/i', $q)) {
        return solveMath($userMsg);
    }

    // 8. Coding questions
    if (preg_match('/(code|program|function|algorithm|python|javascript|php|java|kotlin|html|css|sql|api|debug|error|variable|loop|array)/i', $q)) {
        return handleCoding($userMsg);
    }

    // 9. Price estimation
    if (preg_match('/(price|value|worth|cost|rate|how much|market price|resale)/i', $q)) {
        return estimatePrice($userMsg);
    }

    // 10. Negotiation help
    if (preg_match('/(negotiat|counter offer|lowball|haggle|bargain|price negotiation|deal)/i', $q)) {
        return "🤝 **Negotiation Scripts for Indian Classifieds:**\n\n**When a buyer lowballs you:**\n> Buyer: \"Can you do ₹3,000?\" (your price: ₹7,000)\n> **You:** \"Hey! Thanks for your interest. This is priced fair at ₹7,000 based on current market rates. I can go to ₹6,500 since you're serious, but that's my final. Item is in great condition and includes [accessories]. Let me know!\"\n\n**When buyer asks 'last price?':**\n> Don't reveal your bottom immediately. Say:\n> **\"The price is already competitive. What makes you ask — is there something specific you're concerned about?\"\n\n**Creating urgency:**\n> \"I have 2 other interested buyers coming this weekend. If you can confirm today, I'll give you first priority at ₹X.\"\n\n**Handling 'I'll think about it':**\n> \"Of course! Just so you know, I'll keep the listing up. If someone else takes it, I can't hold it — these things go fast. Want me to message you if anyone else shows serious interest?\"\n\n**When to walk away:** If offer is below 70% of your fair price — politely decline. Better to wait for the right buyer! ⏰";
    }

    // 11. How ZipZapZoi works
    if (preg_match('/(how does zipzapzoi|what is zipzapzoi|about zipzapzoi|zipzapzoi works)/i', $q)) {
        return "🌟 **What is ZipZapZoi?**\n\nZipZapZoi (www.zipzapzoi.com) is **India's smartest classifieds marketplace** — think of it as a smarter, AI-powered version of OLX or Quikr!\n\n**🎯 Key Features:**\n• 🤖 **Zoi AI** — that's me! Built-in AI assistant for buyers and sellers\n• 📍 **Map View** — see listings near you on an interactive map\n• 🔔 **Price Drop Alerts** — get notified when prices drop\n• 💬 **In-app Messaging** — chat with buyers/sellers safely\n• 👀 **Live Watcher Count** — see how many people are viewing a listing (FOMO!)\n• 😍 **Emoji Reactions** — react to listings\n• 🏆 **Verified Sellers** — trust badges for genuine sellers\n• 📊 **Seller Analytics** — see your ad performance\n\n**📱 Available on:** Website + Android App (on Google Play Soon!)\n\nStart selling or buying today — it's **free**! 🚀";
    }

    // 12. Contact/Help
    if (preg_match('/(contact|support|help|problem|issue|complaint|not working)/i', $q)) {
        return "📞 **ZipZapZoi Support:**\n\n🌐 **Help Center:** www.zipzapzoi.com/help.html\n📧 **Contact Form:** www.zipzapzoi.com/contact.html\n💬 **In-app:** Use the \"Report\" button on any listing for urgent issues\n\n**Common Issues & Quick Fixes:**\n• **Can't post ad?** — Check if you've reached your plan's listing limit\n• **Login issues?** — Try \"Forgot Password\" on the login page\n• **Payment not processed?** — Wait 10 minutes, then check your dashboard\n• **Ad not showing?** — It may be under review (usually takes <1 hour)\n• **Suspicious buyer?** — Use the Report button immediately\n\nI'm here 24/7 as Zoi AI — ask me anything specific and I'll do my best to help! 🤗";
    }

    // 13. General knowledge — give helpful answer
    $knowledgeAnswer = searchKnowledge($userMsg);
    if ($knowledgeAnswer) {
        return $knowledgeAnswer;
    }

    // 14. Contextual response using conversation history
    if (count($messages) > 2) {
        return buildContextualResponse($userMsg, $messages, $mode);
    }

    // 15. Default — helpful fallback
    return buildDefaultResponse($userMsg, $mode);
}

/**
 * Simple math solver for common expressions.
 */
function solveMath(string $q): string {
    // Percentage of
    if (preg_match('/(\d+(?:\.\d+)?)\s*%\s*of\s*(\d+(?:\.\d+)?)/i', $q, $m)) {
        $pct  = (float)$m[1];
        $base = (float)$m[2];
        $res  = $pct * $base / 100;
        return "🧮 **{$pct}% of {$base}:**\n\n= ({$pct} ÷ 100) × {$base}\n= {$res}\n\n**Answer: {$res}** ✅";
    }

    // Discount
    if (preg_match('/discount.*?(\d+(?:\.\d+)?)\s*%.*?(\d+(?:[,\d]*\d+)?)/i', $q, $m) ||
        preg_match('/(\d+(?:[,\d]*\d+)?).{0,20}(\d+(?:\.\d+)?)\s*%\s*(?:off|discount)/i', $q, $m2)) {
        $mm = !empty($m[2]) ? $m : $m2;
        $price = (float)str_replace(',', '', $mm[2] ?? $mm[1]);
        $pct   = (float)($mm[1] ?? $mm[2]);
        if ($price > 1000 && $pct < 100) {
            $save = round($price * $pct / 100, 2);
            $final = $price - $save;
            return "🏷️ **{$pct}% Discount on ₹{$price}:**\n\nDiscount amount = ₹{$save}\n**Final price = ₹{$final}** ✅\n\nYou save ₹{$save}! 🎉";
        }
    }

    // Simple arithmetic — let PHP evaluate safely
    if (preg_match('/^[\d\s\+\-\*\/\(\)\.\^%]+$/', preg_replace('/[^0-9\+\-\*\/\(\)\.\^%\s]/', '', $q))) {
        $expr = preg_replace('/[^0-9\+\-\*\/\(\)\.\^%\s]/', '', $q);
        $expr = str_replace('^', '**', $expr);
        try {
            $result = eval("return ($expr);");
            if (is_numeric($result)) {
                return "🧮 **Math Result:**\n\n`{$expr}` = **{$result}** ✅";
            }
        } catch (Throwable $e) {}
    }

    return "🧮 **Math Help:**\n\nI can solve:\n• Basic arithmetic: `45 × 123`, `1024 ÷ 32`\n• Percentages: `15% of 4500`\n• Discounts: `20% off ₹2999`\n• Powers: `2^10`\n\nCould you write your problem more clearly? For example:\n• \"What is 35% of 8000?\"\n• \"Calculate 256 × 47 + 130\"\n• \"20% discount on ₹1299\"";
}

/**
 * Handle coding questions.
 */
function handleCoding(string $q): string {
    $ql = strtolower($q);

    if (str_contains($ql, 'fibonacci') || str_contains($ql, 'fib')) {
        return "💻 **Fibonacci Sequence in Python:**\n\n```python\ndef fibonacci(n):\n    a, b = 0, 1\n    result = []\n    for _ in range(n):\n        result.append(a)\n        a, b = b, a + b\n    return result\n\n# Example usage\nprint(fibonacci(10))\n# Output: [0, 1, 1, 2, 3, 5, 8, 13, 21, 34]\n```\n\n**How it works:**\n1. Start with a=0, b=1\n2. Each iteration: add `a` to result, then shift: a=b, b=a+b\n3. Continue for n iterations\n\nTime complexity: **O(n)** | Space: **O(n)**";
    }

    if (str_contains($ql, 'hello world') || str_contains($ql, 'first program')) {
        return "💻 **Hello World in popular languages:**\n\n**Python:**\n```python\nprint(\"Hello, World!\")\n```\n\n**JavaScript:**\n```javascript\nconsole.log(\"Hello, World!\");\n```\n\n**PHP:**\n```php\n<?php echo \"Hello, World!\"; ?>\n```\n\n**Kotlin (Android):**\n```kotlin\nfun main() {\n    println(\"Hello, World!\")\n}\n```\n\n**Java:**\n```java\npublic class Main {\n    public static void main(String[] args) {\n        System.out.println(\"Hello, World!\");\n    }\n}\n```";
    }

    if (str_contains($ql, 'sort') || str_contains($ql, 'bubble sort') || str_contains($ql, 'algorithm')) {
        return "💻 **Sorting Algorithms:**\n\n**Bubble Sort (Python):**\n```python\ndef bubble_sort(arr):\n    n = len(arr)\n    for i in range(n):\n        for j in range(0, n - i - 1):\n            if arr[j] > arr[j + 1]:\n                arr[j], arr[j + 1] = arr[j + 1], arr[j]\n    return arr\n\nprint(bubble_sort([64, 34, 25, 12, 22, 11, 90]))\n# Output: [11, 12, 22, 25, 34, 64, 90]\n```\n\n**Complexity:** O(n²) time, O(1) space\n\n💡 For real projects, use Python's built-in `sorted()` — it uses TimSort (O(n log n))!";
    }

    // Generic coding help
    return "💻 **Coding Help — I'm here!**\n\nI can help you with:\n• Python, JavaScript, PHP, Kotlin, Java, SQL\n• Algorithms & data structures\n• Debugging code\n• Writing functions & APIs\n• Understanding error messages\n\nShare your specific code problem or question and I'll write the solution for you! What language are you working in?";
}

/**
 * Price estimation for common items.
 */
function estimatePrice(string $q): string {
    $ql = strtolower($q);

    // iPhone
    if (preg_match('/iphone\s*(\d+)/i', $ql, $m)) {
        $model = (int)$m[1];
        $prices = [11 => '8,000–12,000', 12 => '12,000–18,000', 13 => '18,000–28,000', 14 => '28,000–40,000', 15 => '40,000–60,000', 16 => '60,000–80,000'];
        $range = $prices[$model] ?? '10,000–50,000';
        return "💰 **iPhone {$model} Resale Price (India, 2026):**\n\n📊 Market Range: **₹{$range}**\n📉 Avg depreciation: 15-25% per year from MRP\n\n**Factors that affect price:**\n• Storage (128GB > 64GB by ₹2-4k)\n• Condition (mint > good > average)\n• Warranty remaining\n• Original box & accessories\n• Battery health (>80% = better price)\n\n🛒 **Sell on ZipZapZoi** — list it now for free! Post high-quality photos for faster sale. 📸";
    }

    // Samsung
    if (str_contains($ql, 'samsung')) {
        return "💰 **Samsung Phone Resale Prices (India, 2026):**\n\n• Galaxy A14: ₹7,000–10,000\n• Galaxy A34: ₹12,000–18,000\n• Galaxy A54: ₹18,000–25,000\n• Galaxy S23: ₹35,000–50,000\n• Galaxy S24: ₹55,000–75,000\n• Galaxy Note series: ₹15,000–35,000 (older)\n\n💡 **Tip:** Samsung phones depreciate ~20-30% in year 1, then slower. Good condition with original box adds ₹1,000–3,000 to value.\n\n📱 Post on ZipZapZoi for free and reach thousands of buyers!";
    }

    // Laptop
    if (str_contains($ql, 'laptop') || str_contains($ql, 'macbook')) {
        return "💰 **Laptop Resale Prices (India, 2026):**\n\n**Budget (<₹30k new):** Resale ₹8,000–18,000\n**Mid-range (₹30–60k new):** Resale ₹15,000–35,000\n**Premium (₹60k+ new):** Resale ₹30,000–65,000\n\n**MacBook (specific):**\n• MacBook Air M1: ₹55,000–70,000\n• MacBook Pro M2: ₹80,000–1,20,000\n• Older Intel MacBooks: ₹25,000–55,000\n\n**Key price factors:** Age, RAM, Storage, Screen condition, Battery cycles, Original charger\n\n💡 Clean the laptop, take photos showing all ports, and mention exact specs in the ad!";
    }

    // Bike / motorcycle
    if (str_contains($ql, 'activa') || str_contains($ql, 'bike') || str_contains($ql, 'motorcycle') || str_contains($ql, 'scooter')) {
        return "💰 **Two-Wheeler Resale Prices (India, 2026):**\n\n🛵 **Scooters:**\n• Honda Activa 6G (2022-24): ₹55,000–70,000\n• TVS Jupiter 125: ₹50,000–62,000\n• Suzuki Access 125: ₹52,000–65,000\n\n🏍️ **Motorcycles:**\n• Hero Splendor (2020+): ₹35,000–50,000\n• Bajaj Pulsar 150 (2021+): ₹55,000–70,000\n• Royal Enfield Bullet 350: ₹1,20,000–1,60,000\n\n**Key factors:** Km driven (<15,000km adds ₹5-10k), Service history, Insurance validity, Original RC\n\n📍 Post with location on ZipZapZoi — local buyers pay more!";
    }

    // Generic price help
    return "💰 **Price Estimation Help:**\n\nI can estimate resale values for:\n• 📱 Smartphones (iPhone, Samsung, OnePlus, etc.)\n• 💻 Laptops & computers\n• 🛵 Bikes & scooters\n• 📺 TVs & electronics\n• 🚗 Cars\n• 🛋️ Furniture\n\n**To get a precise estimate, tell me:**\n1. Item name + model/year\n2. Current condition (mint/good/average/poor)\n3. Any defects?\n4. What's included (original box, accessories)?\n\nPost your item on ZipZapZoi and let the market decide — real buyers often pay better than estimates! 🎯";
}

/**
 * Build contextual response using conversation history.
 */
function buildContextualResponse(string $q, array $messages, string $mode): string {
    // Find previous AI context
    $prevTopics = [];
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'ai' && !empty($m['text'])) {
            $prevTopics[] = substr($m['text'], 0, 100);
        }
    }

    $modeLabels = [
        'general'      => 'General AI',
        'classifieds'  => 'Classifieds',
        'ad_writer'    => 'Ad Writer',
        'price_advisor'=> 'Price Advisor',
        'negotiation'  => 'Negotiation Coach',
    ];
    $modeLabel = $modeLabels[$mode] ?? 'General AI';

    return "🤔 I'm processing your question: **\"" . htmlspecialchars(substr($q, 0, 100)) . "\"**\n\nIn **{$modeLabel}** mode, I'll do my best to help!\n\n" . buildDefaultResponse($q, $mode);
}

/**
 * Default response when nothing else matches.
 */
function buildDefaultResponse(string $q, string $mode): string {
    $suggestions = [
        "💡 **I'm Zoi AI** and I can help you with:\n\n🏪 **ZipZapZoi questions** — \"How do I post an ad?\", \"Which plan is best?\", \"How to avoid scams?\"\n\n💰 **Pricing** — \"What's my iPhone 13 worth?\", \"Laptop resale value?\"\n\n✍️ **Ad writing** — Describe your item and I'll write a killer ad!\n\n🤝 **Negotiations** — Scripts to handle lowballer buyers\n\n🧮 **Maths** — \"25% of 4800\", \"15% discount on ₹3999\"\n\n💻 **Coding** — Python, PHP, JavaScript, Android/Kotlin\n\nWhat would you like help with?",
        "I'm still learning! 🌱 Could you be more specific? For example:\n\n• **\"Write an ad for my Samsung A54, 6 months old, ₹18,000\"**\n• **\"What's a fair price for a 2021 Pulsar 150?\"**\n• **\"How to negotiate with a lowball buyer?\"**\n• **\"How do I post my first ad?\"**\n\nI get smarter with every conversation. Ask away! ✨",
    ];

    return $suggestions[array_rand($suggestions)];
}

/**
 * Helper: pick random item from array.
 */
function randomPick(array $arr): string {
    return $arr[array_rand($arr)];
}

/**
 * Helper: build context string from messages.
 */
function buildContext(array $messages): string {
    $ctx = [];
    $slice = array_slice($messages, -6);
    foreach ($slice as $m) {
        $role = ($m['role'] === 'user') ? 'User' : 'Zoi';
        $ctx[] = "{$role}: " . substr($m['text'] ?? '', 0, 200);
    }
    return implode("\n", $ctx);
}

// ──────────────────────────────────────────────────────────────────────────────
//  MAIN DISPATCH
// ──────────────────────────────────────────────────────────────────────────────

// Try Gemini first (if valid key exists)
$geminiKey = getGeminiKey();
$engine    = 'standalone';
$reply     = null;

if (!empty($geminiKey) && strpos($geminiKey, 'AIza') === 0) {
    $reply  = callGemini($messages, $mode, $imageBase64, $mimeType, $geminiKey);
    $engine = $reply ? 'gemini' : 'standalone';
}

// Ensure knowledge table exists (for standalone learning)
ensureKnowledgeTable();

// Standalone engine if Gemini unavailable or failed
if (!$reply) {
    $reply = standaloneAI($messages, $mode);
}

// Learn from this interaction (store in knowledge base for future use)
// Save the user's question paired with our response for future retrieval
$userQuestion = '';
foreach (array_reverse($messages) as $m) {
    if (($m['role'] ?? '') === 'user') { $userQuestion = trim($m['text'] ?? ''); break; }
}
if ($userQuestion && $reply && $engine === 'standalone') {
    // Only save non-trivial responses to knowledge base
    if (strlen($reply) > 100 && strlen($userQuestion) > 5) {
        saveKnowledge($userQuestion, $reply, $mode);
    }
}

jsonOk(['reply' => $reply, 'engine' => $engine]);
