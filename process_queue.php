<?php
// Skrypt przetwarzający kolejkę zadań
// Może być uruchamiany przez cron lub w pętli

require_once 'config.php';

function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $message\n";
}

function getGeminiApiKey() {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'gemini_api_key'");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result ? $result['setting_value'] : null;
}

function callGeminiAPI($prompt, $api_key) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=" . $api_key;
    
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        throw new Exception("API Error: HTTP $http_code - $response");
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception("Invalid API response format");
    }
    
    return $result['candidates'][0]['content']['parts'][0]['text'];
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
    
    // Pobierz prompt generowania
    $stmt = $pdo->prepare("SELECT content FROM prompts WHERE content_type_id = ? AND type = 'generate'");
    $stmt->execute([$task_item['content_type_id']]);
    $generate_prompt_template = $stmt->fetch()['content'];
    
    // Pobierz prompt weryfikacji
    $stmt = $pdo->prepare("SELECT content FROM prompts WHERE content_type_id = ? AND type = 'verify'");
    $stmt->execute([$task_item['content_type_id']]);
    $verify_prompt_template = $stmt->fetch()['content'];
    
    if (!$generate_prompt_template || !$verify_prompt_template) {
        throw new Exception("Prompts not found for content type");
    }
    
    $input_data = json_decode($task_item['input_data'], true);
    
    // Przygotuj dane do zamiany w promptach
    $replacements = $input_data;
    $replacements['strictness_level'] = $task_item['strictness_level'];
    
    // Zamień zmienne w promptcie generowania
    $generate_prompt = $generate_prompt_template;
    foreach ($replacements as $key => $value) {
        $generate_prompt = str_replace('{' . $key . '}', $value, $generate_prompt);
    }
    
    logMessage("Generating content...");
    
    // Wygeneruj treść
    $generated_text = callGeminiAPI($generate_prompt, $api_key);
    
    // Przygotuj prompt weryfikacji
    $verify_prompt = str_replace('{generated_text}', $generated_text, $verify_prompt_template);
    
    logMessage("Verifying content...");
    
    // Zweryfikuj treść
    $verified_text = callGeminiAPI($verify_prompt, $api_key);
    
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
}

// Główna pętla przetwarzania
logMessage("Starting queue processor");

$pdo = getDbConnection();
$api_key = getGeminiApiKey();

if (!$api_key) {
    logMessage("ERROR: Gemini API key not configured");
    exit(1);
}

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