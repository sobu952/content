<?php
// Skrypt przetwarzający kolejkę zadań
// Może być uruchamiany przez cron/daemon (CLI) lub ręcznie (WWW)

require_once 'config.php';

// Zmienna globalna do określania trybu (CLI vs WWW)
$is_cli_mode = php_sapi_name() === 'cli';

/**
 * Loguje wiadomość do konsoli/przeglądarki i do pliku logu.
 */
function logMessage($message, $type = 'info') {
    global $is_cli_mode;

    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message\n";
    
    // Zapisz do konsoli/terminala (jeśli skrypt jest uruchamiany w CLI)
    if ($is_cli_mode) {
        echo $log_entry;
    } else {
        // Dla WWW, wyślij do przeglądarki w HTML
        $class = '';
        if ($type === 'error') $class = 'error';
        if ($type === 'success') $class = 'success';
        echo "<div class='log-entry {$class}'>[{$timestamp}] {$message}</div>\n";
        // Wypłucz bufor, aby wiadomości pojawiały się od razu
        ob_flush();
        flush();
    }

    // Zapisz do pliku log (zawsze)
    $log_dir = __DIR__ . '/logs';
    if (!file_exists($log_dir)) {
        if (!mkdir($log_dir, 0755, true)) {
            error_log("Failed to create log directory: $log_dir");
            return;
        }
    }
    $log_file = $log_dir . '/queue_' . date('Y-m-d') . '.log';
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Czyści tekst z fragmentów markdown i niepożądanych elementów
 */
function cleanGeneratedText($text) {
    // Usuń fragmenty ```html i ```
    $text = preg_replace('/```html\s*/i', '', $text);
    $text = preg_replace('/```\s*$/', '', $text);
    $text = preg_replace('/```/', '', $text);
    
    // Usuń inne popularne fragmenty markdown
    $text = preg_replace('/^```[a-zA-Z]*\s*/m', '', $text);
    
    // Usuń nadmiarowe białe znaki
    $text = trim($text);
    
    return $text;
}

/**
 * Pobiera konfigurację AI dla zadania
 */
function getAIConfiguration($pdo, $task_id) {
    $stmt = $pdo->prepare("
        SELECT t.ai_model_id, am.model_name, am.model_key, ap.name as provider_name, 
               ap.api_base_url, ap.api_type, ak.api_key
        FROM tasks t
        JOIN ai_models am ON t.ai_model_id = am.id
        JOIN ai_providers ap ON am.provider_id = ap.id
        JOIN ai_api_keys ak ON ap.id = ak.provider_id
        WHERE t.id = ? AND ak.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$task_id]);
    return $stmt->fetch();
}

/**
 * Pobierz opóźnienie w minutach przed pierwszą próbą przetwarzania zadania.
 */
function getProcessingDelayMinutes() {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'processing_delay_minutes'");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result ? (int)$result['setting_value'] : 1; 
}

/**
 * Pobiera treść strony z podanego URL
 */
function fetchPageContent($url) {
    logMessage("Fetching content from URL: $url");
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language: pl,en-US;q=0.7,en;q=0.3',
        'Accept-Encoding: gzip, deflate',
        'DNT: 1',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        logMessage("cURL error fetching $url: $curl_error", 'error');
        return '';
    }
    
    if ($http_code !== 200) {
        logMessage("HTTP error $http_code fetching $url", 'error');
        return '';
    }
    
    if (empty($response)) {
        logMessage("Empty response from $url", 'error');
        return '';
    }
    
    // Wyczyść HTML i wyciągnij tekst
    $content = extractTextFromHtml($response);
    logMessage("Extracted " . strlen($content) . " characters from $url");
    
    return $content;
}

/**
 * Wyciąga tekst z HTML, usuwając tagi i niepotrzebne elementy
 */
function extractTextFromHtml($html) {
    // Usuń skrypty, style i inne niepotrzebne elementy
    $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $html);
    $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mi', '', $html);
    $html = preg_replace('/<nav\b[^<]*(?:(?!<\/nav>)<[^<]*)*<\/nav>/mi', '', $html);
    $html = preg_replace('/<footer\b[^<]*(?:(?!<\/footer>)<[^<]*)*<\/footer>/mi', '', $html);
    $html = preg_replace('/<header\b[^<]*(?:(?!<\/header>)<[^<]*)*<\/header>/mi', '', $html);
    
    // Konwertuj HTML entities
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Usuń wszystkie tagi HTML
    $text = strip_tags($html);
    
    // Wyczyść białe znaki
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    
    return $text;
}

/**
 * Bezpiecznie zamienia placeholdery w szablonie promptu.
 */
function replacePromptPlaceholders($template, $replacements) {
    $callback = function($matches) use ($replacements) {
        $key = $matches[1];
        if (!isset($replacements[$key])) {
            throw new Exception("Missing replacement for placeholder: '{$key}' in prompt template. Available keys: " . implode(', ', array_keys($replacements)));
        }
        return $replacements[$key];
    };
    
    $processed_prompt = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', $callback, $template);
    
    if ($processed_prompt === null) {
        throw new Exception("Error during prompt placeholder replacement using regex.");
    }

    return $processed_prompt;
}

/**
 * Wywołuje API Gemini.
 */
function callGeminiAPI($prompt, $api_key) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent";
    
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 8192,
        ],
        'safetySettings' => [
            [
                'category' => 'HARM_CATEGORY_HARASSMENT',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ],
            [
                'category' => 'HARM_CATEGORY_HATE_SPEECH',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ],
            [
                'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ],
            [
                'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ]
        ]
    ];
    
    logMessage("Calling Gemini API with prompt length: " . strlen($prompt));
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Goog-Api-Key: ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SEO Content Generator/1.0');
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    curl_close($ch);
    
    logMessage("API Response HTTP Code: $http_code");
    
    if ($curl_error) {
        throw new Exception("cURL Error ({$curl_errno}): {$curl_error}");
    }
    
    if ($http_code !== 200) {
        $error_details = json_decode($response, true);
        $error_message = isset($error_details['error']['message']) ? $error_details['error']['message'] : $response;
        logMessage("API Error Details: " . $error_message);
        throw new Exception("API Error: HTTP $http_code - " . $error_message);
    }
    
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response from API: " . json_last_error_msg());
    }
    
    logMessage("API Response structure (top keys): " . print_r(array_keys($result), true));
    
    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        $generated_text = $result['candidates'][0]['content']['parts'][0]['text'];
        logMessage("Generated text length: " . strlen($generated_text));
        return $generated_text;
    } elseif (isset($result['candidates'][0]['output'])) {
        logMessage("Generated text found in 'output' field, length: " . strlen($result['candidates'][0]['output']));
        return $result['candidates'][0]['output'];
    } elseif (isset($result['error'])) {
        throw new Exception("API Error from response: " . $result['error']['message'] . " (Code: " . ($result['error']['code'] ?? 'N/A') . ")");
    } else {
        logMessage("Unexpected API response format: " . json_encode($result));
        throw new Exception("Invalid API response format - no text content found");
    }
}

/**
 * Wywołuje API OpenAI.
 */
function callOpenAIAPI($prompt, $api_key, $model = 'gpt-3.5-turbo') {
    $url = "https://api.openai.com/v1/chat/completions";
    
    $data = [
        'model' => $model,
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.7,
        'max_tokens' => 4000
    ];
    
    logMessage("Calling OpenAI API ($model) with prompt length: " . strlen($prompt));
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SEO Content Generator/1.0');
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    logMessage("OpenAI API Response HTTP Code: $http_code");
    
    if ($curl_error) {
        throw new Exception("cURL Error: {$curl_error}");
    }
    
    if ($http_code !== 200) {
        $error_details = json_decode($response, true);
        $error_message = isset($error_details['error']['message']) ? $error_details['error']['message'] : $response;
        throw new Exception("OpenAI API Error: HTTP $http_code - " . $error_message);
    }
    
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response from OpenAI API: " . json_last_error_msg());
    }
    
    if (isset($result['choices'][0]['message']['content'])) {
        $generated_text = $result['choices'][0]['message']['content'];
        logMessage("Generated text length: " . strlen($generated_text));
        return $generated_text;
    } elseif (isset($result['error'])) {
        throw new Exception("OpenAI API Error: " . $result['error']['message']);
    } else {
        throw new Exception("Unexpected OpenAI response format");
    }
}

/**
 * Wywołuje API Anthropic (Claude).
 */
function callAnthropicAPI($prompt, $api_key, $model = 'claude-3-haiku-20240307') {
    $url = "https://api.anthropic.com/v1/messages";
    
    $data = [
        'model' => $model,
        'max_tokens' => 4000,
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ]
    ];
    
    logMessage("Calling Anthropic API ($model) with prompt length: " . strlen($prompt));
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SEO Content Generator/1.0');
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    logMessage("Anthropic API Response HTTP Code: $http_code");
    
    if ($curl_error) {
        throw new Exception("cURL Error: {$curl_error}");
    }
    
    if ($http_code !== 200) {
        $error_details = json_decode($response, true);
        $error_message = isset($error_details['error']['message']) ? $error_details['error']['message'] : $response;
        throw new Exception("Anthropic API Error: HTTP $http_code - " . $error_message);
    }
    
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response from Anthropic API: " . json_last_error_msg());
    }
    
    if (isset($result['content'][0]['text'])) {
        $generated_text = $result['content'][0]['text'];
        logMessage("Generated text length: " . strlen($generated_text));
        return $generated_text;
    } elseif (isset($result['error'])) {
        throw new Exception("Anthropic API Error: " . $result['error']['message']);
    } else {
        throw new Exception("Unexpected Anthropic response format");
    }
}

/**
 * Uniwersalna funkcja do wywołania odpowiedniego API
 */
function callAIAPI($prompt, $ai_config) {
    switch ($ai_config['api_type']) {
        case 'gemini':
            return callGeminiAPI($prompt, $ai_config['api_key']);
        case 'openai':
            return callOpenAIAPI($prompt, $ai_config['api_key'], $ai_config['model_key']);
        case 'anthropic':
            return callAnthropicAPI($prompt, $ai_config['api_key'], $ai_config['model_key']);
        default:
            throw new Exception("Unsupported AI provider: " . $ai_config['api_type']);
    }
}

/**
 * Przetwarza pojedynczy element zadania z kolejki.
 */
function processTaskItem($pdo, $queue_item, $ai_config) {
    $task_item_id = $queue_item['task_item_id'];
    
    logMessage("Processing task item ID: $task_item_id");
    
    // Pobierz dane zadania
    $stmt = $pdo->prepare("
        SELECT ti.*, t.strictness_level, ct.id as content_type_id, t.id as task_id
        FROM task_items ti
        JOIN tasks t ON ti.task_id = t.id
        JOIN content_types ct ON t.content_type_id = ct.id
        WHERE ti.id = ?
    ");
    $stmt->execute([$task_item_id]);
    $task_item = $stmt->fetch();
    
    if (!$task_item) {
        throw new Exception("Task item not found for ID: $task_item_id");
    }
    
    logMessage("Task item data for ID {$task_item_id}: " . json_encode($task_item));
    
    // Pobierz prompt generowania
    $stmt = $pdo->prepare("SELECT content FROM prompts WHERE content_type_id = ? AND type = 'generate'");
    $stmt->execute([$task_item['content_type_id']]);
    $generate_prompt_template_data = $stmt->fetch();
    $generate_prompt_template = $generate_prompt_template_data ? $generate_prompt_template_data['content'] : null;

    // Pobierz prompt weryfikacji
    $stmt = $pdo->prepare("SELECT content FROM prompts WHERE content_type_id = ? AND type = 'verify'");
    $stmt->execute([$task_item['content_type_id']]);
    $verify_prompt_template_data = $stmt->fetch();
    $verify_prompt_template = $verify_prompt_template_data ? $verify_prompt_template_data['content'] : null;
    
    if (!$generate_prompt_template) {
        throw new Exception("Generate prompt not found for content type ID: " . $task_item['content_type_id']);
    }
    
    if (!$verify_prompt_template) {
        throw new Exception("Verify prompt not found for content type ID: " . $task_item['content_type_id']);
    }
    
    $input_data = json_decode($task_item['input_data'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON input_data for task item ID: {$task_item_id} - " . json_last_error_msg());
    }

    logMessage("Input data for ID {$task_item_id}: " . json_encode($input_data));
    
    // Pobierz treść strony z URL
    $page_content = '';
    if (isset($input_data['url']) && !empty($input_data['url'])) {
        $page_content = fetchPageContent($input_data['url']);
    }
    
    // Przygotuj dane do zamiany w promptach
    $replacements = $input_data;
    $replacements['strictness_level'] = $task_item['strictness_level'];
    $replacements['page_content'] = $page_content;
    
    // Zamień zmienne w promptcie generowania
    $generate_prompt = replacePromptPlaceholders($generate_prompt_template, $replacements);
    
    logMessage("Final generate prompt length: " . strlen($generate_prompt));
    
    // KROK 1: Wygeneruj treść
    try {
        $generated_text = callAIAPI($generate_prompt, $ai_config);
        $generated_text = cleanGeneratedText($generated_text); // Wyczyść tekst
        logMessage("Content generated successfully for ID {$task_item_id}, length: " . strlen($generated_text));
    } catch (Exception $e) {
        logMessage("Error generating content for ID {$task_item_id}: " . $e->getMessage(), 'error');
        throw $e;
    }
    
    // KROK 2: Zweryfikuj treść
    logMessage("Verifying content for ID {$task_item_id}...");
    
    // Przygotuj prompt weryfikacji z wygenerowaną treścią
    $verify_replacements = ['generated_text' => $generated_text];
    $verify_prompt = replacePromptPlaceholders($verify_prompt_template, $verify_replacements);
    
    try {
        $verified_text = callAIAPI($verify_prompt, $ai_config);
        $verified_text = cleanGeneratedText($verified_text); // Wyczyść tekst
        logMessage("Content verified successfully for ID {$task_item_id}, length: " . strlen($verified_text));
        
        // Sprawdź czy tekst rzeczywiście został zmieniony
        if (trim($verified_text) === trim($generated_text)) {
            logMessage("Verification did not change the text for ID {$task_item_id}");
        } else {
            logMessage("Text was modified during verification for ID {$task_item_id}");
        }
        
    } catch (Exception $e) {
        logMessage("Error verifying content for ID {$task_item_id}: " . $e->getMessage(), 'error');
        // W przypadku błędu weryfikacji, użyj oryginalnego tekstu
        $verified_text = $generated_text;
        logMessage("Using original generated text as verified text due to verification failure.");
    }
    
    // KROK 3: Zapisz oba teksty
    $stmt = $pdo->prepare("
        INSERT INTO generated_content (task_item_id, generated_text, verified_text, status) 
        VALUES (?, ?, ?, 'verified')
        ON DUPLICATE KEY UPDATE 
        generated_text = VALUES(generated_text),
        verified_text = VALUES(verified_text),
        status = VALUES(status)
    ");
    $stmt->execute([$task_item_id, $generated_text, $verified_text]);
    
    logMessage("Content generated and saved for ID {$task_item_id}");
}

/**
 * Aktualizuje ogólny status zadania na podstawie statusów jego elementów.
 */
function updateTaskStatus($pdo, $task_id) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_items,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_items,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_items,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_items,
            COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing_items
        FROM task_items 
        WHERE task_id = ?
    ");
    $stmt->execute([$task_id]);
    $stats = $stmt->fetch();
    
    $new_status = 'pending'; 
    
    if ($stats['total_items'] == 0) {
        $new_status = 'pending';
    } elseif ($stats['completed_items'] == $stats['total_items']) {
        $new_status = 'completed';
    } elseif ($stats['failed_items'] == $stats['total_items']) {
        $new_status = 'failed';
    } elseif ($stats['processing_items'] > 0 || $stats['pending_items'] > 0) {
        $new_status = 'processing';
    } elseif ($stats['completed_items'] > 0 && $stats['failed_items'] > 0) {
        $new_status = 'partial_failure';
    }

    $stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?");
    $stmt->execute([$new_status, $task_id]);
    
    logMessage("Task ID: {$task_id} status updated to: {$new_status}");
}

// --- Główna logika przetwarzania ---

// Start buforowania wyjścia dla trybu WWW
if (!$is_cli_mode) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Queue Processor (Manual Trigger)</title>
        <meta charset="UTF-8">
        <style>
            body { font-family: monospace; margin: 20px; background-color: #f8f8f8; color: #333; }
            h1 { color: #0056b3; }
            .log-entry { 
                background: #fff; 
                padding: 8px 12px; 
                margin-bottom: 5px; 
                border-radius: 4px; 
                box-shadow: 0 1px 3px rgba(0,0,0,0.1); 
                word-wrap: break-word;
            }
            .error { background-color: #ffe0e0; color: #d9534f; border-left: 4px solid #d9534f; }
            .success { background-color: #e0ffe0; color: #5cb85c; border-left: 4px solid #5cb85c; }
            .info { border-left: 4px solid #007bff; }
            .container { max-width: 900px; margin: 0 auto; }
        </style>
        <meta http-equiv="refresh" content="10">
    </head>
    <body>
        <div class="container">
            <h1>Queue Processor (Manual Trigger)</h1>
            <p>This page processes one queue item per refresh. Auto-refresh every 10 seconds.</p>
    <?php
}

logMessage("Starting queue processor. Mode: " . ($is_cli_mode ? "CLI" : "WWW (Manual Trigger)"));

$pdo = getDbConnection();
$processing_delay_minutes = getProcessingDelayMinutes();

logMessage("Processing delay set to: {$processing_delay_minutes} minutes.");
logMessage(($is_cli_mode ? "Starting continuous processing loop." : "Processing one item."));

// Ustawienie PDO na tryb rzucania wyjątków
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Główna pętla przetwarzania (tylko dla CLI) lub jednorazowe wykonanie (WWW)
do {
    $queue_item = null;
    try {
        $pdo->beginTransaction();
        
        // Pobierz następny element z kolejki - TYLKO JEDEN NA RAZ
        $stmt = $pdo->prepare("
            SELECT tq.*, ti.task_id
            FROM task_queue tq
            JOIN task_items ti ON tq.task_item_id = ti.id
            WHERE tq.status = 'pending' 
            AND tq.attempts < tq.max_attempts
            AND (tq.attempts > 0 OR tq.created_at <= NOW() - INTERVAL ? MINUTE)
            ORDER BY tq.priority DESC, tq.created_at ASC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$processing_delay_minutes]);
        $queue_item = $stmt->fetch();
        
        if (!$queue_item) {
            $pdo->commit();
            if ($is_cli_mode) {
                sleep(10); 
            }
            continue;
        }
        
        logMessage("Attempting to process queue item ID: {$queue_item['id']} (Task Item ID: {$queue_item['task_item_id']})");

        // Pobierz konfigurację AI dla tego zadania
        $ai_config = getAIConfiguration($pdo, $queue_item['task_id']);
        if (!$ai_config) {
            throw new Exception("No AI configuration found for task ID: {$queue_item['task_id']}");
        }

        logMessage("Using AI: {$ai_config['provider_name']} - {$ai_config['model_name']}");

        // Oznacz element kolejki jako przetwarzany
        $stmt = $pdo->prepare("UPDATE task_queue SET status = 'processing', processed_at = NOW() WHERE id = ?");
        $stmt->execute([$queue_item['id']]);
        
        // Oznacz element zadania jako przetwarzany
        $stmt = $pdo->prepare("UPDATE task_items SET status = 'processing' WHERE id = ?");
        $stmt->execute([$queue_item['task_item_id']]);
        
        $pdo->commit(); // Commit przed długotrwałym procesem
        
        try {
            // Rozpocznij nową transakcję dla przetwarzania
            $pdo->beginTransaction();
            
            processTaskItem($pdo, $queue_item, $ai_config);
            
            // Oznacz element kolejki jako ukończony
            $stmt = $pdo->prepare("UPDATE task_queue SET status = 'completed' WHERE id = ?");
            $stmt->execute([$queue_item['id']]);
            
            // Zakończ element zadania jako completed
            $stmt = $pdo->prepare("UPDATE task_items SET status = 'completed' WHERE id = ?");
            $stmt->execute([$queue_item['task_item_id']]);

            logMessage("Queue item {$queue_item['id']} successfully processed and marked as completed.", 'success');
            
        } catch (Exception $e) {
            logMessage("ERROR processing queue item {$queue_item['id']} (Task Item ID: {$queue_item['task_item_id']}): " . $e->getMessage(), 'error');
            
            $new_attempts = $queue_item['attempts'] + 1;
            $new_queue_status = ($new_attempts >= $queue_item['max_attempts']) ? 'failed' : 'pending';
            $new_task_item_status = $new_queue_status;

            $stmt = $pdo->prepare("
                UPDATE task_queue 
                SET attempts = ?, 
                    status = ?,
                    error_message = ?
                WHERE id = ?
            ");
            $stmt->execute([$new_attempts, $new_queue_status, $e->getMessage(), $queue_item['id']]);
            
            $stmt = $pdo->prepare("UPDATE task_items SET status = ? WHERE id = ?");
            $stmt->execute([$new_task_item_status, $queue_item['task_item_id']]);

            logMessage("Queue item {$queue_item['id']} marked as '{$new_queue_status}' with {$new_attempts} attempts.", ($new_queue_status == 'failed' ? 'error' : 'info'));
        }
        
        updateTaskStatus($pdo, $queue_item['task_id']);
        
        $pdo->commit();
        logMessage("Transaction for queue item {$queue_item['id']} committed.");

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
                logMessage("CRITICAL ERROR: Transaction rolled back for queue item " . ($queue_item['id'] ?? 'unknown') . " - " . $e->getMessage(), 'error');
            } catch (PDOException $rb_e) {
                logMessage("CRITICAL ERROR: Failed to rollback transaction: " . $rb_e->getMessage(), 'error');
            }
        } else {
            logMessage("CRITICAL ERROR (no active transaction): " . $e->getMessage(), 'error');
        }
        
        if ($is_cli_mode) {
            sleep(30); 
        }
    }
    
    if ($is_cli_mode) {
        sleep(5); // Krótsze opóźnienie między zadaniami
    }

} while ($is_cli_mode);

if (!$is_cli_mode) {
    echo "</div></body></html>";
    ob_end_flush();
}

?>