<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
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
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS zoi_knowledge (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            question    VARCHAR(500) NOT NULL,
            answer      TEXT NOT NULL,
            keywords    TEXT,
            category    VARCHAR(50) DEFAULT 'general',
            weight      INT DEFAULT 1,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_question (question(250)),
            FULLTEXT KEY ft_qa (question, answer)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Clean out any polluted canned default answers that were saved previously
        $db->exec("DELETE FROM zoi_knowledge WHERE answer LIKE '%I\'m still learning!%' OR answer LIKE '%I\'m Zoi AI and I can help you with:%';");
    } catch (Throwable $e) {
        error_log('ZoiAI: Could not create/clean zoi_knowledge table: ' . $e->getMessage());
    }
}

/**
 * Search the knowledge base for relevant answers.
 * Uses FULLTEXT search + keyword overlap scoring.
 */
function searchKnowledge(string $query): ?string {
    try {
        $db = getDB();
        $clean = trim(preg_replace('/[^\w\s]/u', ' ', strtolower($query)));
        if (strlen($clean) < 3) return null;

        // Try exact/like question match first
        $stmt0 = $db->prepare("SELECT answer FROM zoi_knowledge WHERE question LIKE :q ORDER BY weight DESC LIMIT 1");
        $stmt0->execute([':q' => '%' . substr($clean, 0, 100) . '%']);
        $row0 = $stmt0->fetch(PDO::FETCH_ASSOC);
        if ($row0 && !empty($row0['answer'])) {
            return $row0['answer'];
        }

        // FULLTEXT match
        $stmt = $db->prepare(
            "SELECT answer, weight,
                    MATCH(question, answer) AGAINST (:q IN NATURAL LANGUAGE MODE) AS score
             FROM zoi_knowledge
             WHERE MATCH(question, answer) AGAINST (:q IN NATURAL LANGUAGE MODE)
             ORDER BY score DESC, weight DESC
             LIMIT 1"
        );
        $stmt->execute([':q' => $clean]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && ($row['score'] ?? 0) > 0.5) {
            $db->prepare("UPDATE zoi_knowledge SET weight = weight + 1 WHERE answer = :a LIMIT 1")
               ->execute([':a' => $row['answer']]);
            return $row['answer'];
        }

        // Keyword fallback - match meaningful words (length > 3, exclude common stopwords)
        $stopwords = ['what', 'when', 'where', 'which', 'who', 'whom', 'this', 'that', 'with', 'from', 'have', 'your', 'about', 'some', 'could', 'would', 'should', 'tell', 'want'];
        $words = array_filter(explode(' ', $clean), fn($w) => strlen($w) > 3 && !in_array($w, $stopwords));
        if (empty($words)) return null;

        foreach ($words as $word) {
            $stmt2 = $db->prepare("SELECT answer FROM zoi_knowledge WHERE keywords LIKE :kw ORDER BY weight DESC LIMIT 1");
            $stmt2->execute([':kw' => '%' . $word . '%']);
            $r = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($r && !empty($r['answer'])) return $r['answer'];
        }
    } catch (Throwable $e) {
        error_log('ZoiAI knowledge search error: ' . $e->getMessage());
    }
    return null;
}

/**
 * Save a new Q&A pair to the knowledge base.
 */
function saveKnowledge(string $question, string $answer, string $category = 'general'): void {
    try {
        // Never save canned fallback or trivial messages
        if (str_contains($answer, "I'm still learning") || str_contains($answer, "What would you like help with?")) {
            return;
        }
        $db = getDB();
        $words = preg_split('/\s+/', strtolower($question));
        $stopwords = ['what', 'when', 'where', 'which', 'who', 'whom', 'this', 'that', 'with', 'from', 'have', 'your', 'about', 'some', 'could', 'would', 'should', 'tell', 'want'];
        $keywords = implode(',', array_filter($words, fn($w) => strlen($w) > 3 && !in_array($w, $stopwords)));

        $stmt = $db->prepare(
            "INSERT INTO zoi_knowledge (question, answer, keywords, category)
             VALUES (:q, :a, :kw, :cat)
             ON DUPLICATE KEY UPDATE answer = :a, weight = weight + 1, updated_at = NOW()"
        );
        $stmt->execute([
            ':q'   => substr(trim($question), 0, 500),
            ':a'   => substr(trim($answer), 0, 5000),
            ':kw'  => $keywords,
            ':cat' => $category
        ]);
    } catch (Throwable $e) {
        error_log('ZoiAI: Could not save knowledge: ' . $e->getMessage());
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

    if (empty($userMsg)) {
        return "👋 **Hello! I'm Zoi AI** — your assistant for ZipZapZoi. How can I help you today?";
    }

    $q       = strtolower($userMsg);
    $context = buildContext($messages);

    // 0. Dedicated Classifieds Ad Copywriter Mode / Ad Description Generation
    if ($mode === 'ad_writer' || $mode === 'ad_creator' ||
        (str_contains($q, 'description') && (str_contains($q, 'ad') || str_contains($q, 'item') || str_contains($q, 'classified'))) ||
        (str_contains($q, 'write') && (str_contains($q, 'ad') || str_contains($q, 'sell')))) {

        $title = '';
        if (preg_match("/(?:titled|title|item|sell|product|for)[:\s'"]+([^'"\n,\.]+)/i", $userMsg, $tm)) {
            $title = trim($tm[1]);
        }
        if (!$title) {
            $cleanTitle = trim(preg_replace('/(?i)\b(write|catchy|professional|classified|ad|description|for|an|item|titled|category|condition|is|make|it|ready|to|post|output|only|text|without|conversational|filler|or|greetings)\b/', '', $userMsg));
            $cleanTitle = trim(preg_replace('/[^\w\s]/', '', $cleanTitle));
            if (!empty($cleanTitle)) {
                $words = explode(' ', $cleanTitle);
                $title = implode(' ', array_slice(array_filter($words), 0, 4));
            }
        }
        $title = ucwords($title ?: 'Item');

        $condition = 'Good';
        if (preg_match('/condition\s*(?:is)?\s*[:\x27\x22]?\s*([a-zA-Z\s\-]+)/i', $userMsg, $cm)) {
            $condition = trim(explode('.', explode(',', $cm[1])[0])[0]);
        }

        return "Up for sale: **{$title}** in **{$condition}** condition! 🚀\n\n" .
               "100% genuine, very well-maintained, and functions flawlessly. Clean aesthetic with minimal signs of use. " .
               "A great value deal for anyone looking for reliable quality. " .
               "Ready for instant inspection or pick-up. Serious buyers, please message or call for a quick and smooth deal!";
    }

    // 1. Well-being & Greetings
    if (preg_match('/\b(how are you|how r u|how are u|how do you do|what\'s up|whats up|how\'s it going|hows it going|how have you been|how are things|are you doing well|are you okay)\b/i', $q)) {
        return "😊 **I'm doing great, thank you for asking!**\n\nI'm Zoi AI — your smart companion built into ZipZapZoi! I'm always energized and ready to help you with:\n  🏷️ **Buying & Selling** on India's smartest classifieds\n  🙋 **Buyer Demands** — find or post what you need\n  💰 **Price Checks** — fair market valuations for resale\n  🔐 **Account & Login Guidance**\n  💡 **Any Questions** — math, science, coding, history, and daily advice\n\nWhat can I help you with today?";
    }

    if (preg_match('/^(hi|hello|hey|namaste|vanakkam|hii|helo|sup|howdy|yo|good morning|good afternoon|good evening)\b/i', $q)) {
        return randomPick([
            "👋 **Hello! I'm Zoi AI** — your smart assistant for ZipZapZoi!\n\nI can help you with:\n  🏷️ Buying & selling on ZipZapZoi\n  🙋 Posting & viewing Buyer Demands\n  ✍️ Writing catchy classified ads\n  💰 Getting the best price for your items\n  🔐 Account login & safety tips\n  🧠 General questions — maths, coding, science, anything!\n\nWhat's on your mind today?",
            "🙏 **Namaste! Zoi AI here!** ✨\n\nI'm your AI assistant for ZipZapZoi — India's smartest classifieds marketplace. Ask me anything about buying, selling, pricing, or any general topic. How can I help you right now?",
            "Hey! 🚀 **Zoi AI at your service!** I'm here to make your ZipZapZoi experience smoother. Want to post an ad, check prices, or just ask any question? Go ahead — I'm all ears! 💡",
        ]);
    }

    // 2. Developer, Origin & Creation Details
    if (preg_match('/(developer|who developed|who created|who made you|who built you|who designed you|who is your creator|who owns you|creator details|developer details|who is your developer|about your developer|who programmed you|who are your developers|team behind)/i', $q)) {
        return "👨‍💻 **Zoi AI Developer & Origin Details:**\n\nI was designed, engineered, and developed exclusively by the **ZipZapZoi Engineering Team** for **ZipZapZoi** (www.zipzapzoi.com) — India's smartest modern classifieds marketplace.\n\n✨ **Key Highlights:**\n• **Platform:** ZipZapZoi Technologies\n• **Headquarters:** India\n• **Purpose:** Empower Indian buyers and sellers with an intelligent, 100% scam-free, zero-commission marketplace.\n• **Technology:** Built with native Android (Jetpack Compose & Kotlin), high-performance PHP REST APIs, real-time shared learning knowledge base (`zoi_knowledge`), and multimodal intelligence.\n• **Cross-Platform Sync:** I learn and assist seamlessly across both the **ZipZapZoi Android App** and the **www.zipzapzoi.com Website**!\n\nWhether you need help with listings, pricing, or technical questions, my team and I are here for you 24/7! 🚀";
    }

    // 3. How to Log In & Account Guidance
    if (preg_match('/(how.{0,10}(log in|login|sign in|signin)|guide me.{0,15}log in|guide me.{0,15}login|how to create account|how to register|cant log in|cannot log in|login problem|login guide|login issue|forgot password|how do i login|how to sign in)/i', $q)) {
        return "🔐 **How to Log In to ZipZapZoi (Quick Step-by-Step Guide):**\n\n**📱 On the Android App:**\n1. Tap the **Profile** icon in the bottom navigation bar (bottom-right).\n2. Tap the **'Sign In'** button (or 'Create Account' if you're new).\n3. Enter your registered **Email address** or **10-digit Mobile Number**.\n4. Enter your **Password** and tap **Sign In**.\n5. You can also sign in or verify using **Email OTP** for fast, passwordless access!\n\n**🌐 On the Website (www.zipzapzoi.com):**\n1. Click the **'Sign In'** button at the top-right corner of any page.\n2. Choose **Password Login** or **Email OTP Login**.\n3. Enter your credentials and click **Submit**.\n\n💡 **Forgot your password?**\nSimply tap **'Forgot Password?'** on the login screen, enter your email or mobile number, and you will receive an instant reset code to set a new password securely!";
    }

    // 4. What is ZipZapZoi / About ZipZapZoi
    if (preg_match('/(about zipzapzoi|what is zipzapzoi|tell me about zipzapzoi|zipzapzoi classifieds|how does zipzapzoi work|features of zipzapzoi|why zipzapzoi|about the app|about website)/i', $q)) {
        return "🌟 **About ZipZapZoi Classifieds:**\n\n**ZipZapZoi** (www.zipzapzoi.com) is **India's smartest, AI-powered classifieds marketplace** designed to make buying and selling used & new items super fast, transparent, and completely free of broker commissions!\n\n**🚀 Standout Features:**\n• 🤖 **Zoi AI Assistant:** That's me! I automatically generate catchy ad copy, value fair resale prices, and assist you in real time.\n• 🙋 **Buyer Demand Marketplace:** Can't find what you need? Post what you want to buy, and local sellers will bring offers directly to you!\n• 📍 **Interactive Maps & Radius Search:** Discover genuine deals in your exact neighborhood or city.\n• 💬 **Direct WhatsApp & In-App Chat:** Connect with genuine local buyers and sellers with zero middleman commissions.\n• 🛡️ **Verified Seller Badges:** Extra peace of mind with ID-verified profiles and scam prevention.\n• ⚡ **Live Watcher Counts & Price Drops:** Track real-time buyer demand and get alerted when prices drop.\n• 📱 **Seamless Sync:** Fully synced between the Android App and Website anytime!";
    }

    // 5. Buyer Demand Section
    if (preg_match('/(buyer demand|buyers demand|buyer\'s demand|demand section|post demand|how to post demand|what is buyer demand|want to buy|demand feature)/i', $q)) {
        return "🙋 **ZipZapZoi Buyer Demand Section:**\n\nThe Buyer Demand section is designed for when you want to buy something specific without having to search every day!\n\n**Why Use Buyer Demands?**\n• Post what item you're looking for (e.g. *'Need iPhone 13 128GB under ₹25,000'* or *'Looking for Study Table in Bangalore'*).\n• Local sellers who have that item will see your demand and contact you directly with their best deals!\n\n**How to Post a Buyer Demand in the App:**\n1. Tap the **Post (+)** button in the bottom navigation bar.\n2. At the top of the screen, tap the **[ 🙋 Buyer Demand ]** tab.\n3. Enter the item name, your **Budget Range (Min ₹ & Max ₹)**, Category, and your City.\n4. Tap **'Publish Buyer Demand'** — it goes live immediately for all sellers to see!";
    }

    // 6. ZipZapZoi subscription plans
    if (preg_match('/(plan|subscription|price|cost|fee|paid|free|starter|growth|business|pro|package)/i', $q) &&
        preg_match('/(plan|subscribe|membership|upgrade|listing limit|ad limit)/i', $q)) {
        return "💎 **ZipZapZoi Subscription Plans:**\n\n| Plan | Price | Listings/Month | Features |\n|------|-------|---------------|----------|\n| **Free** | ₹0 | 1 ad | Basic listing, 30 days |\n| **Starter** | ₹149 | 10 ads | Verified badge, 5 photos/ad |\n| **Growth** | ₹299 | 25 ads | Featured boost, priority search |\n| **Business** | ₹599 | 50 ads | Analytics, top-tier placement |\n| **Pro** | ₹999 | 100 ads | Maximum visibility, unlimited edits |\n\n💡 **Recommendation:** Casual sellers love the **Starter (₹149)** plan, while active retailers get the best ROI with **Growth (₹299)**!";
    }

    // 7. How to post ad / sell item
    if (preg_match('/(post|list|sell|create|add).{0,20}(ad|listing|item|product)/i', $q) ||
        preg_match('/(how.{0,10}sell|want to sell)/i', $q)) {
        return "📝 **How to Post Your Ad on ZipZapZoi:**\n\n**Step 1:** Tap or click the **\"Post Ad (+)\"** button\n**Step 2:** Select **[ 🏷️ Sell an Item ]** at the top\n**Step 3:** Fill in your listing details:\n     • **Title:** Brand + Model + Key Spec (e.g. *Samsung Galaxy S23 256GB Phantom Black*)\n     • **Category:** Select from Electronics, Mobiles, Cars, etc.\n     • **Price:** Fair asking price in ₹ (I can help you price it!)\n     • **Description:** Use our **Auto-write with AI** button for instant high-converting text!\n     • **Photos:** Add 3-5 clear, well-lit photos (ads with photos sell 3x faster!)\n**Step 4:** Choose your contact preference (Chat, Phone, or WhatsApp)\n**Step 5:** Tap **Publish Listing!** 🚀";
    }

    // 8. Categories
    if (preg_match('/(categor|what can.{0,10}sell|what.{0,10}sell|types of items)/i', $q)) {
        return "📦 **ZipZapZoi Categories — You Can Sell Anything!**\n\n📱 **Mobiles** — Phones, Tablets, Accessories, Smartwatches\n💻 **Electronics & Computers** — Laptops, Desktops, TVs, Audio, Cameras\n🚗 **Cars & Bikes** — Cars, Motorcycles, Scooters, Spare parts\n🏠 **Property** — Rent/Sale Flats, Houses, Plots, Commercial\n🛋️ **Furniture** — Sofas, Beds, Wardrobes, Office furniture\n👗 **Fashion** — Clothing, Shoes, Watches, Jewellery\n📚 **Books & Hobbies** — Textbooks, Novels, Musical Instruments\n⚽ **Sports & Fitness** — Gym equipment, Bicycles, Sports gear\n🍳 **Kitchen & Home** — Appliances, Cookware, Dining\n💼 **Jobs & Services** — Part-time, Full-time, Freelance, Local services\n🐾 **Pets** — Pet supplies, Accessories, Food";
    }

    // 9. Safety / Scam detection
    if (preg_match('/(scam|fraud|safe|safety|trust|fake|cheat|avoid|suspicious)/i', $q)) {
        return "🛡️ **Staying Safe on ZipZapZoi — Anti-Scam Guide:**\n\n**⚠️ RED FLAGS to watch out for:**\n• Buyer offers more than your asking price without seeing the item\n• Asks you to pay advance \"courier fees\", \"verification charges\", or \"customs clearance\"\n• Insists on sending QR codes to \"receive money\" — **QR codes are only for paying, never for receiving!**\n• Demands OTP, bank PIN, or credit card details\n• Refuses to meet in person or use in-app messaging\n\n**✅ Safe Trading Rules:**\n1. Meet in a **busy public place** (metro station, mall, coffee shop).\n2. Inspect and test the item in person before handing over money.\n3. Use direct UPI or cash only after inspecting the product.\n4. **Never share OTPs** under any circumstances.";
    }

    // 10. Maths - solve equations
    if (preg_match('/(\d+\s*%|\d+[\+\-\*/\^]\d+|\d+\s+percent|\bsolve\b|\bcalculate\b|\bmath\b|\bequation\b|\bpercentage\b|\bdiscount\b)/i', $q)) {
        return solveMath($userMsg);
    }

    // 11. Coding questions
    if (preg_match('/(code|program|function|algorithm|python|javascript|php|java|kotlin|html|css|sql|api|debug|error|variable|loop|array)/i', $q)) {
        return handleCoding($userMsg);
    }

    // 12. Price estimation
    if (preg_match('/(price|value|worth|cost|rate|how much|market price|resale)/i', $q)) {
        return estimatePrice($userMsg);
    }

    // 13. Negotiation help
    if (preg_match('/(negotiat|counter offer|lowball|haggle|bargain|price negotiation|deal)/i', $q)) {
        return "🤝 **Negotiation Tactics for Indian Classifieds:**\n\n**When a buyer lowballs you:**\n> Buyer: *\"Bhai, ₹5,000 me de do?\"* (Your price: ₹10,000)\n> **Your Reply:** *\"Thanks for the offer! The item is already priced competitively given its excellent condition and accessories. The absolute best I can do for a fast pick-up today is ₹9,200. Let me know if that works for you!\"*\n\n**When a buyer asks 'What is your last price?':**\n> **Your Reply:** *\"My listed price is already very close to fair market value. What budget do you have in mind? If you can pick it up today, I can do a small courtesy discount.\"*\n\n**Creating urgency:**\n> *\"I have two other inquiries scheduled for this weekend. I can reserve it for you if you can confirm by this evening.\"*\n\n💡 **Golden Rule:** Never sell below your walk-away price — genuine buyers are always willing to pay for well-cared-for items!";
    }

    // 14. Contact & Support
    if (preg_match('/(contact|support|help|problem|issue|complaint|not working|report)/i', $q)) {
        return "📞 **ZipZapZoi Support & Help Center:**\n\n• **Website:** www.zipzapzoi.com/help.html\n• **Contact Us:** www.zipzapzoi.com/contact.html\n• **Email Support:** support@zipzapzoi.com\n\n**Quick Troubleshooting:**\n• **Ad Under Review?** Listings are automatically verified and go live within 15–30 minutes.\n• **Can't Post Ad?** Check if you have reached your plan's monthly ad limit on your Profile dashboard.\n• **Report Suspicious User:** Click the 'Report Listing' button directly on any suspicious ad.\n\nI'm Zoi AI, here 24/7 — feel free to ask me anything specific!";
    }

    // 15. Search shared knowledge base (zoi_knowledge)
    $knowledgeAnswer = searchKnowledge($userMsg);
    if ($knowledgeAnswer) {
        return $knowledgeAnswer;
    }

    // 16. Smart, intelligent fallback that addresses the user's specific query
    return buildSmartResponse($userMsg, $mode);
}

/**
 * Intelligent response when no direct pattern matches.
 */
function buildSmartResponse(string $q, string $mode): string {
    $clean = trim(preg_replace('/[^\w\s]/u', ' ', $q));
    $words = array_filter(explode(' ', strtolower($clean)), fn($w) => strlen($w) > 2);
    $topic = !empty($words) ? implode(' ', array_slice($words, 0, 4)) : 'your question';

    return "💡 **Regarding \"" . htmlspecialchars($topic) . "\":**\n\n" .
           "I'm Zoi AI, continuously learning and improving on ZipZapZoi! While I'm looking into the exact details for this, here is how I can assist you right now:\n\n" .
           "• 🏷️ **Post or Buy:** Ask me *'How do I post a watch ad?'* or *'How does Buyer Demand work?'*\n" .
           "• 💰 **Price Estimates:** Ask *'What is iPhone 13 resale price?'* or *'Activa 6G market value'*\n" .
           "• 🔐 **Account & Login:** Ask *'How to log in to my account'*\n" .
           "• 🧮 **Maths & Code:** Ask me to solve formulas, write Python/Kotlin code, or calculate discounts\n\n" .
           "Could you add a bit more detail to your question? I'm ready to help you!";
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


/**
 * Default response when nothing else matches.
 */


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
