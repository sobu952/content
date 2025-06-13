<?php
require_once 'auth_check.php';
requireAdmin();

$pdo = getDbConnection();

$success = '';
$error = '';

// Obsługa zapisywania ustawień
if ($_POST) {
    $gemini_api_key = trim($_POST['gemini_api_key']);
    
    try {
        // Zapisz klucz API Gemini
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('gemini_api_key', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$gemini_api_key]);
        
        $success = 'Ustawienia zostały zapisane.';
    } catch(Exception $e) {
        $error = 'Błąd zapisywania ustawień: ' . $e->getMessage();
    }
}

// Pobierz aktualne ustawienia
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings_raw = $stmt->fetchAll();

$settings = [];
foreach ($settings_raw as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}

// Pobierz statystyki systemu
$stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
$user_count = $stmt->fetch()['count'];

$stmt = $pdo->query("SELECT COUNT(*) as count FROM projects");
$project_count = $stmt->fetch()['count'];

$stmt = $pdo->query("SELECT COUNT(*) as count FROM tasks");
$task_count = $stmt->fetch()['count'];

$stmt = $pdo->query("SELECT COUNT(*) as count FROM generated_content");
$content_count = $stmt->fetch()['count'];

$stmt = $pdo->query("SELECT COUNT(*) as count FROM task_queue WHERE status = 'pending'");
$queue_count = $stmt->fetch()['count'];
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ustawienia systemu - Generator treści SEO</title>
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
                    <h1 class="h2">Ustawienia systemu</h1>
                </div>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Konfiguracja API</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="mb-3">
                                        <label for="gemini_api_key" class="form-label">Klucz API Google Gemini *</label>
                                        <input type="password" class="form-control" id="gemini_api_key" name="gemini_api_key" 
                                               value="<?= htmlspecialchars($settings['gemini_api_key'] ?? '') ?>" required>
                                        <div class="form-text">
                                            Klucz API potrzebny do generowania treści. Możesz go uzyskać w 
                                            <a href="https://makersuite.google.com/app/apikey" target="_blank">Google AI Studio</a>.
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Zapisz ustawienia</button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="mb-0">Informacje o systemie</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Wersja PHP</h6>
                                        <p><?= phpversion() ?></p>
                                        
                                        <h6>Rozszerzenia PHP</h6>
                                        <ul class="list-unstyled">
                                            <li>
                                                <i class="fas fa-<?= extension_loaded('pdo') ? 'check text-success' : 'times text-danger' ?>"></i>
                                                PDO
                                            </li>
                                            <li>
                                                <i class="fas fa-<?= extension_loaded('curl') ? 'check text-success' : 'times text-danger' ?>"></i>
                                                cURL
                                            </li>
                                            <li>
                                                <i class="fas fa-<?= extension_loaded('zip') ? 'check text-success' : 'times text-danger' ?>"></i>
                                                ZIP
                                            </li>
                                            <li>
                                                <i class="fas fa-<?= extension_loaded('json') ? 'check text-success' : 'times text-danger' ?>"></i>
                                                JSON
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Limity PHP</h6>
                                        <ul class="list-unstyled">
                                            <li>Max execution time: <?= ini_get('max_execution_time') ?>s</li>
                                            <li>Memory limit: <?= ini_get('memory_limit') ?></li>
                                            <li>Upload max filesize: <?= ini_get('upload_max_filesize') ?></li>
                                            <li>Post max size: <?= ini_get('post_max_size') ?></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Statystyki systemu</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-3">
                                            <h4 class="text-primary"><?= $user_count ?></h4>
                                            <small>Użytkownicy</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-3">
                                            <h4 class="text-success"><?= $project_count ?></h4>
                                            <small>Projekty</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-3">
                                            <h4 class="text-info"><?= $task_count ?></h4>
                                            <small>Zadania</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-3">
                                            <h4 class="text-warning"><?= $content_count ?></h4>
                                            <small>Treści</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <div class="text-center">
                                    <h5 class="text-danger"><?= $queue_count ?></h5>
                                    <small>Zadania w kolejce</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="mb-0">Zarządzanie kolejką</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted">
                                    Aby uruchomić przetwarzanie kolejki, wykonaj poniższą komendę na serwerze:
                                </p>
                                <code>php process_queue.php</code>
                                
                                <p class="text-muted mt-3">
                                    Lub dodaj do cron-a dla automatycznego przetwarzania:
                                </p>
                                <code>* * * * * php /path/to/process_queue.php</code>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>