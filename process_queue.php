<?php
// Skrypt przetwarzający kolejkę zadań
// Może być uruchamiany przez cron lub w pętli

require_once 'config.php';

function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $message\n";
    
    // Zapisz również do pliku log
    $log_file = 'logs/queue_' . date('Y-m-d') . '.log';
    if (!file_exists('logs')) {
        mkdir('logs', 0755, true);
    }
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
}

function getGeminiApiKey() {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'gemini_api_key'");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result ? $result['setting_value'] : null;
}

function callGeminiAPI($prompt, $api_key) {
    // Nowy endpoint API Gemini
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $api_key;
    
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
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Zwiększony timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SEO Content Generator/1.0');
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    logMessage("API Response HTTP Code: $http_code");
    
    if ($curl_error) {
        throw new Exception("cURL Error: $curl_error");
    }
    
    if ($http_code !== 200) {
        logMessage("API Error Response: $response");
        throw new Exception("API Error: HTTP $http_code - $response");
    }
    
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response: " . json_last_error_msg());
    }
    
    logMessage("API Response structure: " . print_r(array_keys($result), true));
    
    // Sprawdź różne możliwe struktury odpowiedzi
    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        $generated_text = $result['candidates'][0]['content']['parts'][0]['text'];
        logMessage("Generated text length: " . strlen($generated_text));
        return $generated_text;
    } elseif (isset($result['candidates'][0]['output'])) {
        return $result['candidates'][0]['output'];
    } elseif (isset($result['error'])) {
        throw new Exception("API Error: " . $result['error']['message']);
    } else {
        logMessage("Unexpected API response format: " . json_encode($result));
        throw new Exception("Invalid API response format - no text content found");
    }
}

function processTaskItem($pdo, $queue_item, $api_key) {
    $task_item_id = $queue_item['task_item_id'];
    
    logMessage("Processing task item ID: $task_item_id");
    
    // Pobierz dane zadania
    $stmt = $pdo->prepare("
        SELECT ti.*, t.strictness_level, ct.id as content_type_id
        FROM task_items ti
        JOIN tasks t ON ti.task_id = t.id
        JOIN content_types ct ON t.content_type_id = ct.id
        WHERE ti.id = ?
    ");
    $stmt->execute([$task_item_id]);
    $task_item = $stmt->fetch();
    
    if (!$task_item) {
        throw new Exception("Task item not found");
    }
    
    logMessage("Task item data: " . json_encode($task_item));
    
    // Pobierz prompt generowania
    $stmt = $pdo->prepare("SELECT content FROM prompts WHERE content_type_id = ? AND type = 'generate'");
    $stmt->execute([$task_item['content_type_id']]);
    $prompt_result = $stmt->fetch();
    
    if (!$prompt_result) {
        throw new Exception("Generate prompt not found for content type ID: " . $task_item['content_type_id']);
    }
    
    $generate_prompt_template = $prompt_result['content'];
    
    // Pobierz prompt weryfikacji
    $stmt = $pdo->prepare("SELECT content FROM prompts WHERE content_type_id = ? AND type = 'verify'");
    $stmt->execute([$task_item['content_type_id']]);
    $verify_result = $stmt->fetch();
    
    if (!$verify_result) {
        throw new Exception("Verify prompt not found for content type ID: " . $task_item['content_type_id']);
    }
    
    $verify_prompt_template = $verify_result['content'];
    
    $input_data = json_decode($task_item['input_data'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid input data JSON: " . json_last_error_msg());
    }
    
    logMessage("Input data: " . json_encode($input_data));
    
    // Przygotuj dane do zamiany w promptach
    $replacements = $input_data;
    $replacements['strictness_level'] = $task_item['strictness_level'];
    
    // Zamień zmienne w promptcie generowania
    $generate_prompt = $generate_prompt_template;
    foreach ($replacements as $key => $value) {
        $placeholder = '{' . $key . '}';
        $generate_prompt = str_replace($placeholder, $value, $generate_prompt);
        logMessage("Replaced $placeholder with: " . substr($value, 0, 100) . (strlen($value) > 100 ? '...' : ''));
    }
    
    logMessage("Final prompt length: " . strlen($generate_prompt));
    
    // Wygeneruj treść
    try {
        $generated_text = callGeminiAPI($generate_prompt, $api_key);
        logMessage("Content generated successfully, length: " . strlen($generated_text));
    } catch (Exception $e) {
        logMessage("Error generating content: " . $e->getMessage());
        throw $e;
    }
    
    // Przygotuj prompt weryfikacji
    $verify_prompt = str_replace('{generated_text}', $generated_text, $verify_prompt_template);
    
    logMessage("Verifying content...");
    
    // Zweryfikuj treść
    try {
        $verified_text = callGeminiAPI($verify_prompt, $api_key);
        logMessage("Content verified successfully, length: " . strlen($verified_text));
    } catch (Exception $e) {
        logMessage("Error verifying content: " . $e->getMessage());
        // Jeśli weryfikacja się nie powiedzie, użyj oryginalnego tekstu
        $verified_text = $generated_text;
        logMessage("Using original generated text as verified text");
    }
    
    // Zapisz wygenerowaną treść
    $stmt = $pdo->prepare("
        INSERT INTO generated_content (task_item_id, generated_text, verified_text, status) 
        VALUES (?, ?, ?, 'verified')
        ON DUPLICATE KEY UPDATE 
        generated_text = VALUES(generated_text),
        verified_text = VALUES(verified_text),
        status = VALUES(status)
    ");
    $stmt->execute([$task_item_id, $generated_text, $verified_text]);
    
    // Aktualizuj status elementu zadania
    $stmt = $pdo->prepare("UPDATE task_items SET status = 'completed' WHERE id = ?");
    $stmt->execute([$task_item_id]);
    
    logMessage("Content generated and verified successfully");
}

function updateTaskStatus($pdo, $task_id) {
    // Sprawdź status wszystkich elementów zadania
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_items,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_items,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_items
        FROM task_items 
        WHERE task_id = ?
    ");
    $stmt->execute([$task_id]);
    $stats = $stmt->fetch();
    
    $new_status = 'pending';
    if ($stats['completed_items'] == $stats['total_items']) {
        $new_status = 'completed';
    } elseif ($stats['failed_items'] > 0 && ($stats['completed_items'] + $stats['failed_items']) == $stats['total_items']) {
        $new_status = 'failed';
    } elseif ($stats['completed_items'] > 0) {
        $new_status = 'processing';
    }
    
    $stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?");
    $stmt->execute([$new_status, $task_id]);
    
    logMessage("Task $task_id status updated to: $new_status");
}

// Sprawdź czy skrypt jest uruchamiany z linii poleceń
$is_cli = php_sapi_name() === 'cli';

if (!$is_cli) {
    // Jeśli uruchamiany z przeglądarki, pokaż interfejs testowy
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Queue Processor Test</title>
        <meta charset="UTF-8">
        <style>
            body { font-family: monospace; margin: 20px; }
            .log { background: #f5f5f5; padding: 10px; border: 1px solid #ddd; height: 400px; overflow-y: scroll; }
            .error { color: red; }
            .success { color: green; }
        </style>
    </head>
    <body>
        <h1>Queue Processor Test</h1>
        <div id="log" class="log"></div>
        <script>
            function log(message, type = 'info') {
                const logDiv = document.getElementById('log');
                const timestamp = new Date().toLocaleTimeString();
                const className = type === 'error' ? 'error' : (type === 'success' ? 'success' : '');
                logDiv.innerHTML += `<div class="${className}">[${timestamp}] ${message}</div>`;
                logDiv.scrollTop = logDiv.scrollHeight;
            }
        </script>
    <?php
    
    // Uruchom jeden element z kolejki dla testu
    try {
        $pdo = getDbConnection();
        $api_key = getGeminiApiKey();
        
        if (!$api_key) {
            echo "<script>log('ERROR: Gemini API key not configured', 'error');</script>";
            exit;
        }
        
        echo "<script>log('API key found, checking queue...', 'info');</script>";
        
        // Pobierz następny element z kolejki
        $stmt = $pdo->prepare("
            SELECT tq.*, ti.task_id
            FROM task_queue tq
            JOIN task_items ti ON tq.task_item_id = ti.id
            WHERE tq.status = 'pending' AND tq.attempts < tq.max_attempts
            ORDER BY tq.priority DESC, tq.created_at ASC
            LIMIT 1
        ");
        $stmt->execute();
        $queue_item = $stmt->fetch();
        
        if (!$queue_item) {
            echo "<script>log('No items in queue', 'info');</script>";
        } else {
            echo "<script>log('Processing queue item ID: {$queue_item['id']}', 'info');</script>";
            
            // Oznacz jako przetwarzane
            $stmt = $pdo->prepare("UPDATE task_queue SET status = 'processing', processed_at = NOW() WHERE id = ?");
            $stmt->execute([$queue_item['id']]);
            
            $stmt = $pdo->prepare("UPDATE task_items SET status = 'processing' WHERE id = ?");
            $stmt->execute([$queue_item['task_item_id']]);
            
            try {
                ob_start();
                processTaskItem($pdo, $queue_item, $api_key);
                $output = ob_get_clean();
                
                // Oznacz jako ukończone
                $stmt = $pdo->prepare("UPDATE task_queue SET status = 'completed' WHERE id = ?");
                $stmt->execute([$queue_item['id']]);
                
                echo "<script>log('Queue item {$queue_item['id']} completed successfully', 'success');</script>";
                
                updateTaskStatus($pdo, $queue_item['task_id']);
                
            } catch (Exception $e) {
                $error_msg = $e->getMessage();
                echo "<script>log('ERROR: " . addslashes($error_msg) . "', 'error');</script>";
                
                // Zwiększ liczbę prób
                $stmt = $pdo->prepare("
                    UPDATE task_queue 
                    SET attempts = attempts + 1, 
                        status = CASE WHEN attempts + 1 >= max_attempts THEN 'failed' ELSE 'pending' END,
                        error_message = ?
                    WHERE id = ?
                ");
                $stmt->execute([$error_msg, $queue_item['id']]);
                
                // Aktualizuj status elementu zadania
                $stmt = $pdo->prepare("
                    UPDATE task_items 
                    SET status = CASE WHEN (SELECT attempts FROM task_queue WHERE id = ?) >= (SELECT max_attempts FROM task_queue WHERE id = ?) THEN 'failed' ELSE 'pending' END
                    WHERE id = ?
                ");
                $stmt->execute([$queue_item['id'], $queue_item['id'], $queue_item['task_item_id']]);
                
                updateTaskStatus($pdo, $queue_item['task_id']);
            }
        }
        
    } catch (Exception $e) {
        echo "<script>log('CRITICAL ERROR: " . addslashes($e->getMessage()) . "', 'error');</script>";
    }
    
    echo "</body></html>";
    exit;
}

// Główna pętla przetwarzania (tylko dla CLI)
logMessage("Starting queue processor");

$pdo = getDbConnection();
$api_key = getGeminiApiKey();

if (!$api_key) {
    logMessage("ERROR: Gemini API key not configured");
    exit(1);
}

logMessage("API key configured, starting processing loop");

while (true) {
    try {
        // Pobierz następny element z kolejki
        $stmt = $pdo->prepare("
            SELECT tq.*, ti.task_id
            FROM task_queue tq
            JOIN task_items ti ON tq.task_item_id = ti.id
            WHERE tq.status = 'pending' AND tq.attempts < tq.max_attempts
            ORDER BY tq.priority DESC, tq.created_at ASC
            LIMIT 1
        ");
        $stmt->execute();
        $queue_item = $stmt->fetch();
        
        if (!$queue_item) {
            logMessage("No items in queue, waiting...");
            sleep(10);
            continue;
        }
        
        // Oznacz jako przetwarzane
        $stmt = $pdo->prepare("UPDATE task_queue SET status = 'processing', processed_at = NOW() WHERE id = ?");
        $stmt->execute([$queue_item['id']]);
        
        // Oznacz element zadania jako przetwarzany
        $stmt = $pdo->prepare("UPDATE task_items SET status = 'processing' WHERE id = ?");
        $stmt->execute([$queue_item['task_item_id']]);
        
        try {
            processTaskItem($pdo, $queue_item, $api_key);
            
            // Oznacz jako ukończone
            $stmt = $pdo->prepare("UPDATE task_queue SET status = 'completed' WHERE id = ?");
            $stmt->execute([$queue_item['id']]);
            
            logMessage("Queue item {$queue_item['id']} completed successfully");
            
        } catch (Exception $e) {
            logMessage("ERROR processing queue item {$queue_item['id']}: " . $e->getMessage());
            
            // Zwiększ liczbę prób
            $stmt = $pdo->prepare("
                UPDATE task_queue 
                SET attempts = attempts + 1, 
                    status = CASE WHEN attempts + 1 >= max_attempts THEN 'failed' ELSE 'pending' END,
                    error_message = ?
                WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $queue_item['id']]);
            
            // Aktualizuj status elementu zadania
            $stmt = $pdo->prepare("
                UPDATE task_items 
                SET status = CASE WHEN (SELECT attempts FROM task_queue WHERE id = ?) >= (SELECT max_attempts FROM task_queue WHERE id = ?) THEN 'failed' ELSE 'pending' END
                WHERE id = ?
            ");
            $stmt->execute([$queue_item['id'], $queue_item['id'], $queue_item['task_item_id']]);
        }
        
        // Aktualizuj status zadania
        updateTaskStatus($pdo, $queue_item['task_id']);
        
    } catch (Exception $e) {
        logMessage("CRITICAL ERROR: " . $e->getMessage());
        sleep(30); // Czekaj dłużej po krytycznym błędzie
    }
    
    // Krótka przerwa między zadaniami
    sleep(2);
}
?>