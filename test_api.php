<?php
require_once 'auth_check.php';
requireAdmin();

$pdo = getDbConnection();

// Pobierz dostępne modele AI z kluczami API
$stmt = $pdo->query("
    SELECT am.*, ap.name as provider_name, ap.api_type, ak.api_key
    FROM ai_models am
    JOIN ai_providers ap ON am.provider_id = ap.id
    JOIN ai_api_keys ak ON ap.id = ak.provider_id
    WHERE am.is_active = 1 AND ap.is_active = 1 AND ak.is_active = 1
    ORDER BY ap.name, am.name
");
$ai_models = $stmt->fetchAll();

$test_result = '';
$error = '';

if ($_POST && isset($_POST['test_api'])) {
    $model_id = intval($_POST['model_id']);
    
    // Znajdź wybrany model
    $selected_model = null;
    foreach ($ai_models as $model) {
        if ($model['id'] == $model_id) {
            $selected_model = $model;
            break;
        }
    }
    
    if (!$selected_model) {
        $error = 'Wybrany model nie został znaleziony.';
    } else {
        try {
            $test_prompt = "Napisz krótki test w języku polskim - jedną linijkę tekstu.";
            
            // Określ funkcję API na podstawie typu providera
            switch ($selected_model['api_type']) {
                case 'gemini':
                    $test_result = callGeminiAPI($test_prompt, $selected_model['api_key']);
                    break;
                case 'openai':
                    $test_result = callOpenAIAPI($test_prompt, $selected_model['api_key'], $selected_model['model_key']);
                    break;
                case 'anthropic':
                    $test_result = callAnthropicAPI($test_prompt, $selected_model['api_key'], $selected_model['model_key']);
                    break;
                default:
                    throw new Exception("Nieobsługiwany typ providera: " . $selected_model['api_type']);
            }
            
        } catch (Exception $e) {
            $error = 'Błąd testu API: ' . $e->getMessage();
        }
    }
}

// Funkcje API (kopiowane z process_queue.php dla testu)
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
            'maxOutputTokens' => 100,
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Goog-Api-Key: ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        throw new Exception("cURL Error: $curl_error");
    }
    
    if ($http_code !== 200) {
        throw new Exception("HTTP Error $http_code: $response");
    }
    
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response: " . json_last_error_msg());
    }
    
    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        return $result['candidates'][0]['content']['parts'][0]['text'];
    } elseif (isset($result['error'])) {
        throw new Exception("API Error: " . $result['error']['message']);
    } else {
        throw new Exception("Unexpected response format");
    }
}

function callOpenAIAPI($prompt, $api_key, $model) {
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
        'max_tokens' => 100
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        throw new Exception("cURL Error: $curl_error");
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
        return $result['choices'][0]['message']['content'];
    } elseif (isset($result['error'])) {
        throw new Exception("OpenAI API Error: " . $result['error']['message']);
    } else {
        throw new Exception("Unexpected OpenAI response format");
    }
}

function callAnthropicAPI($prompt, $api_key, $model) {
    $url = "https://api.anthropic.com/v1/messages";
    
    $data = [
        'model' => $model,
        'max_tokens' => 100,
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ]
    ];
    
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        throw new Exception("cURL Error: $curl_error");
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
        return $result['content'][0]['text'];
    } elseif (isset($result['error'])) {
        throw new Exception("Anthropic API Error: " . $result['error']['message']);
    } else {
        throw new Exception("Unexpected Anthropic response format");
    }
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test API - Generator treści SEO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Test API</h1>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <h5>Błąd:</h5>
                        <pre><?= htmlspecialchars($error) ?></pre>
                    </div>
                <?php endif; ?>
                
                <?php if ($test_result): ?>
                    <div class="alert alert-success">
                        <h5>Test zakończony pomyślnie!</h5>
                        <p><strong>Odpowiedź API:</strong></p>
                        <div class="border p-3 bg-light">
                            <?= htmlspecialchars($test_result) ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Test połączenia z API</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($ai_models)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                Brak skonfigurowanych modeli AI lub kluczy API. 
                                <a href="admin_ai_providers.php">Przejdź do zarządzania AI</a> aby je dodać.
                            </div>
                        <?php else: ?>
                            <p>Wybierz model AI aby przetestować połączenie:</p>
                            
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="model_id" class="form-label">Model AI</label>
                                    <select class="form-select" name="model_id" required>
                                        <option value="">Wybierz model do testu</option>
                                        <?php foreach ($ai_models as $model): ?>
                                            <option value="<?= $model['id'] ?>">
                                                <?= htmlspecialchars($model['provider_name']) ?> - <?= htmlspecialchars($model['name']) ?>
                                                (<?= htmlspecialchars($model['model_key']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <button type="submit" name="test_api" class="btn btn-primary">
                                    <i class="fas fa-play"></i> Testuj API
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">Test procesora kolejki</h5>
                    </div>
                    <div class="card-body">
                        <p>Możesz przetestować procesor kolejki bezpośrednio w przeglądarce:</p>
                        <a href="process_queue.php" target="_blank" class="btn btn-info">
                            <i class="fas fa-external-link-alt"></i> Otwórz test procesora
                        </a>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">Informacje debugowania</h5>
                    </div>
                    <div class="card-body">
                        <h6>Konfiguracja PHP:</h6>
                        <ul>
                            <li>cURL: <?= extension_loaded('curl') ? '<span class="text-success">Włączone</span>' : '<span class="text-danger">Wyłączone</span>' ?></li>
                            <li>JSON: <?= extension_loaded('json') ? '<span class="text-success">Włączone</span>' : '<span class="text-danger">Wyłączone</span>' ?></li>
                            <li>OpenSSL: <?= extension_loaded('openssl') ? '<span class="text-success">Włączone</span>' : '<span class="text-danger">Wyłączone</span>' ?></li>
                            <li>Max execution time: <?= ini_get('max_execution_time') ?>s</li>
                            <li>Memory limit: <?= ini_get('memory_limit') ?></li>
                        </ul>
                        
                        <h6>Dostępne modele AI:</h6>
                        <ul>
                            <?php foreach ($ai_models as $model): ?>
                                <li>
                                    <?= htmlspecialchars($model['provider_name']) ?> - <?= htmlspecialchars($model['name']) ?>
                                    <small class="text-muted">(<?= htmlspecialchars($model['model_key']) ?>)</small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        
                        <h6>Status kolejki:</h6>
                        <?php
                        $stmt = $pdo->query("
                            SELECT 
                                status,
                                COUNT(*) as count
                            FROM task_queue 
                            GROUP BY status
                        ");
                        $queue_stats = $stmt->fetchAll();
                        ?>
                        <ul>
                            <?php if (empty($queue_stats)): ?>
                                <li>Kolejka jest pusta</li>
                            <?php else: ?>
                                <?php foreach ($queue_stats as $stat): ?>
                                    <li><?= ucfirst($stat['status']) ?>: <?= $stat['count'] ?></li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>