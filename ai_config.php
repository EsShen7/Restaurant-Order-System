<?php
/**
 * AI API Configuration
 *
 * ── 国内推荐：DeepSeek（免费，无需翻墙）──
 * 1. 打开 https://platform.deepseek.com/api_keys
 * 2. 注册账号 → 创建 API Key
 * 3. 注册送 500 万 tokens，完全够用
 *
 * ── 备选：SiliconFlow（硅基流动，免费）──
 * 官网 https://siliconflow.cn 注册送额度
 *
 * ── 国外：Google Gemini / OpenAI ──
 * Gemini: https://aistudio.google.com/apikey (需翻墙)
 */

// 可选值: 'deepseek' | 'siliconflow' | 'openai' | 'gemini'
define('AI_PROVIDER', 'deepseek');

// ── API 密钥（根据上面的 provider 填写对应的 key）──
// 优先从 .env 文件读取（推荐，.env 已 gitignore，密钥不会泄漏）
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), 'DEEPSEEK_API_KEY=')) {
            define('DEEPSEEK_API_KEY', trim(substr($line, strpos($line, '=') + 1)));
        }
    }
}
if (!defined('DEEPSEEK_API_KEY')) {
    define('DEEPSEEK_API_KEY', '');
}
define('SILICONFLOW_API_KEY', '');
define('GEMINI_API_KEY', '');
define('OPENAI_API_KEY', '');

// ── 模型设置 ──
define('AI_TEMPERATURE', 0.1);
define('AI_MAX_TOKENS', 1024);
define('AI_TIMEOUT', 15); // API 超时时间（秒），超时后自动降级为关键词搜索

/**
 * Call the configured AI API with a prompt
 */
function callAI(string $prompt): string {
    switch (AI_PROVIDER) {
        case 'deepseek':
            return callOpenAICompat($prompt,
                'https://api.deepseek.com/v1/chat/completions',
                DEEPSEEK_API_KEY,
                'deepseek-chat'
            );
        case 'siliconflow':
            return callOpenAICompat($prompt,
                'https://api.siliconflow.cn/v1/chat/completions',
                SILICONFLOW_API_KEY,
                'deepseek-ai/DeepSeek-V3'
            );
        case 'openai':
            return callOpenAICompat($prompt,
                'https://api.openai.com/v1/chat/completions',
                OPENAI_API_KEY,
                'gpt-4o-mini'
            );
        case 'gemini':
            return callGemini($prompt);
        default:
            throw new RuntimeException('Unknown AI provider: ' . AI_PROVIDER);
    }
}

/**
 * OpenAI 兼容格式的 API 调用（DeepSeek / SiliconFlow / OpenAI 通用）
 */
function callOpenAICompat(string $prompt, string $url, string $apiKey, string $model): string {
    if (!$apiKey) {
        throw new RuntimeException(
            'API key not configured for provider "' . AI_PROVIDER . '". ' .
            'Set the corresponding key in ai_config.php'
        );
    }

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => AI_TEMPERATURE,
        'max_tokens' => AI_MAX_TOKENS,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => AI_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Retry with alternative method if curl fails
    if ($error) {
        $altResponse = callOpenAICompatStream($prompt, $url, $apiKey, $model);
        if ($altResponse !== null) return $altResponse;
        throw new RuntimeException('AI API request failed: ' . $error);
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        $errMsg = $data['error']['message'] ?? json_encode($data);
        // Try fallback
        $altResponse = callOpenAICompatStream($prompt, $url, $apiKey, $model);
        if ($altResponse !== null) return $altResponse;
        throw new RuntimeException('AI API error (' . $httpCode . '): ' . $errMsg);
    }

    return trim($data['choices'][0]['message']['content'] ?? '');
}

/**
 * Google Gemini API 调用（需翻墙）
 */
function callGemini(string $prompt): string {
    $apiKey = GEMINI_API_KEY;
    if (!$apiKey) {
        throw new RuntimeException('Gemini API key not configured.');
    }

    $model = 'gemini-2.0-flash';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $apiKey;

    $payload = [
        'contents' => [[
            'parts' => [['text' => $prompt]]
        ]],
        'generationConfig' => [
            'temperature' => AI_TEMPERATURE,
            'maxOutputTokens' => AI_MAX_TOKENS,
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => AI_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new RuntimeException('AI API request failed: ' . $error);
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        $errMsg = $data['error']['message'] ?? 'Unknown error';
        throw new RuntimeException('AI API error (' . $httpCode . '): ' . $errMsg);
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    return trim($text);
}

/**
 * Fallback: use file_get_contents with stream context instead of curl.
 * Returns null if this method also fails (caller should fall back to keyword search).
 */
function callOpenAICompatStream(string $prompt, string $url, string $apiKey, string $model): ?string {
    try {
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => AI_TEMPERATURE,
            'max_tokens' => AI_MAX_TOKENS,
        ];

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nAuthorization: Bearer $apiKey\r\n",
                'content' => json_encode($payload),
                'timeout' => AI_TIMEOUT,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $response = file_get_contents($url, false, $context);
        if ($response === false) return null;

        $data = json_decode($response, true);
        return trim($data['choices'][0]['message']['content'] ?? '');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Build database context string for AI prompts
 * Includes schema summary and current availability snapshot
 */
function buildAIContext(): string {
    $ctx = "Restaurant Database Context:\n";
    $ctx .= "- dining_tables: id, type(enum:small,medium,large,private), table_number, min_capacity, max_capacity\n";
    $ctx .= "- reservations: id, table_id, customer_name, phone, party_size, notes, status(enum:confirmed,cancelled), created_at, created_by\n";
    $ctx .= "- employees: id, username, full_name, is_admin, is_active\n";
    $ctx .= "- private_room_requests: id, customer_name, phone, party_size, status(enum:pending,confirmed,rejected), created_at\n";
    $ctx .= "- pre_orders: id, reservation_id, item_name, quantity\n";

    try {
        require_once __DIR__ . '/config.php';
        $db = getDB();

        // Current availability stats
        $stats = $db->query(
            "SELECT dt.type, COUNT(*) AS total,
                    COUNT(*) - COUNT(r.id) AS available
             FROM dining_tables dt
             LEFT JOIN reservations r ON r.table_id = dt.id AND r.status='confirmed'
             GROUP BY dt.type"
        )->fetchAll();

        $ctx .= "\nCurrent availability:\n";
        foreach ($stats as $s) {
            $ctx .= "- {$s['type']}: {$s['available']}/{$s['total']} available\n";
        }

        // Total confirmed reservations
        $total = $db->query("SELECT COUNT(*) FROM reservations WHERE status='confirmed'")->fetchColumn();
        $ctx .= "\nTotal confirmed reservations: {$total}\n";
    } catch (Throwable $e) {
        $ctx .= "\n(Database unavailable)\n";
    }

    return $ctx;
}

/**
 * Build a prompt for AI restaurant recommendations and parse the response.
 * Returns an array of restaurant IDs ordered by relevance, or null on failure.
 *
 * @param string $userContext  JSON string with user preferences / history
 * @param string $restaurants  JSON string with available restaurants data
 * @return array|null  Array of restaurant IDs, or null
 */
function callAIRecommendation(string $userContext, string $restaurants): ?array {
    $prompt = <<<PROMPT
You are a city restaurant recommendation engine. Based on the user context and available restaurants below, recommend the best matches.

USER CONTEXT:
{$userContext}

AVAILABLE RESTAURANTS:
{$restaurants}

Return ONLY a valid JSON array of restaurant IDs ordered by relevance (best first).
Rules:
- Consider cuisine preference, price range, rating, and variety
- Include at least one restaurant outside the user's usual choices to encourage discovery
- Return exactly 5 IDs (or fewer if fewer restaurants exist)
- Return ONLY the JSON array, no other text or explanation

Example: [5, 2, 8, 1, 3]
PROMPT;

    try {
        $response = callAI($prompt);
        $response = trim($response);

        // Try direct JSON parse
        $ids = json_decode($response, true);
        if (is_array($ids) && count($ids) > 0) {
            return array_map('intval', $ids);
        }

        // Try extracting from code block
        if (preg_match('/```(?:json)?\s*\n?\[(.*?)\]\n?```/s', $response, $m)) {
            $ids = json_decode('[' . $m[1] . ']', true);
            if (is_array($ids)) return array_map('intval', $ids);
        }

        // Try finding array in text
        if (preg_match('/\[(\d+(?:\s*,\s*\d+)*)\]/', $response, $m)) {
            $ids = array_map('intval', explode(',', str_replace(' ', '', $m[1])));
            if (count($ids) > 0) return $ids;
        }

        return null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Fallback recommendation: rule-based scoring when AI is unavailable.
 * Scores restaurants by rating, review count, and cuisine match.
 */
function fallbackRecommendations(array $restaurants, array $userPrefs = []): array {
    $maxReviews = 1;
    foreach ($restaurants as $r) {
        if ($r['review_count'] > $maxReviews) $maxReviews = $r['review_count'];
    }

    $scored = [];
    foreach ($restaurants as $r) {
        $score = 0;
        // Rating score (0-40 points)
        $score += ($r['avg_rating'] / 5.0) * 40;
        // Popularity score (0-30 points)
        $score += ($r['review_count'] / $maxReviews) * 30;
        // Default cuisine match bonus
        $score += 10;

        $scored[] = [
            'id' => (int)$r['id'],
            'score' => round($score, 1),
        ];
    }

    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice(array_column($scored, 'id'), 0, 5);
}
