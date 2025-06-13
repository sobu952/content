<?php
require_once 'auth_check.php';
requireAdmin();

$pdo = getDbConnection();

$success = '';
$error = '';

// Obsługa zapisywania promptu
if ($_POST) {
    $content_type_id = intval($_POST['content_type_id']);
    $type = $_POST['type'];
    $content = trim($_POST['content']);
    
    if (empty($content)) {
        $error = 'Treść promptu jest wymagana.';
    } else {
        try {
            // Sprawdź czy prompt już istnieje
            $stmt = $pdo->prepare("SELECT id FROM prompts WHERE content_type_id = ? AND type = ?");
            $stmt->execute([$content_type_id, $type]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Aktualizuj istniejący
                $stmt = $pdo->prepare("UPDATE prompts SET content = ? WHERE content_type_id = ? AND type = ?");
                $stmt->execute([$content, $content_type_id, $type]);
            } else {
                // Dodaj nowy
                $stmt = $pdo->prepare("INSERT INTO prompts (content_type_id, type, content) VALUES (?, ?, ?)");
                $stmt->execute([$content_type_id, $type, $content]);
            }
            
            $success = 'Prompt został zapisany.';
        } catch(Exception $e) {
            $error = 'Błąd zapisywania promptu: ' . $e->getMessage();
        }
    }
}

// Pobierz typy treści
$stmt = $pdo->query("SELECT id, name FROM content_types ORDER BY name");
$content_types = $stmt->fetchAll();

// Pobierz prompty
$stmt = $pdo->query("
    SELECT p.*, ct.name as content_type_name
    FROM prompts p
    JOIN content_types ct ON p.content_type_id = ct.id
    ORDER BY ct.name, p.type
");
$prompts = $stmt->fetchAll();

// Grupuj prompty według typu treści
$grouped_prompts = [];
foreach ($prompts as $prompt) {
    $grouped_prompts[$prompt['content_type_id']][$prompt['type']] = $prompt;
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zarządzanie promptami - Generator treści SEO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .prompt-content {
            max-height: 200px;
            overflow-y: auto;
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            font-family: monospace;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Zarządzanie promptami</h1>
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
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Edytuj prompt</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="mb-3">
                                        <label for="content_type_id" class="form-label">Typ treści *</label>
                                        <select class="form-select" name="content_type_id" id="content_type_id" required onchange="loadPrompt()">
                                            <option value="">Wybierz typ treści</option>
                                            <?php foreach ($content_types as $type): ?>
                                                <option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="type" class="form-label">Typ promptu *</label>
                                        <select class="form-select" name="type" id="type" required onchange="loadPrompt()">
                                            <option value="">Wybierz typ promptu</option>
                                            <option value="generate">Generowanie</option>
                                            <option value="verify">Weryfikacja</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="content" class="form-label">Treść promptu *</label>
                                        <textarea class="form-control" name="content" id="content" rows="15" required></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Zapisz prompt</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Istniejące prompty</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($content_types as $type): ?>
                                    <h6><?= htmlspecialchars($type['name']) ?></h6>
                                    
                                    <div class="row mb-4">
                                        <div class="col-md-6">
                                            <div class="card">
                                                <div class="card-header d-flex justify-content-between">
                                                    <span>Prompt generowania</span>
                                                    <?php if (isset($grouped_prompts[$type['id']]['generate'])): ?>
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="editPrompt(<?= $type['id'] ?>, 'generate')">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">Brak</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="card-body">
                                                    <?php if (isset($grouped_prompts[$type['id']]['generate'])): ?>
                                                        <div class="prompt-content">
                                                            <?= htmlspecialchars(substr($grouped_prompts[$type['id']]['generate']['content'], 0, 300)) ?>
                                                            <?php if (strlen($grouped_prompts[$type['id']]['generate']['content']) > 300): ?>
                                                                ...
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <p class="text-muted">Prompt nie został jeszcze utworzony.</p>
                                                        <button class="btn btn-sm btn-primary" 
                                                                onclick="editPrompt(<?= $type['id'] ?>, 'generate')">
                                                            Utwórz prompt
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="card">
                                                <div class="card-header d-flex justify-content-between">
                                                    <span>Prompt weryfikacji</span>
                                                    <?php if (isset($grouped_prompts[$type['id']]['verify'])): ?>
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="editPrompt(<?= $type['id'] ?>, 'verify')">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">Brak</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="card-body">
                                                    <?php if (isset($grouped_prompts[$type['id']]['verify'])): ?>
                                                        <div class="prompt-content">
                                                            <?= htmlspecialchars(substr($grouped_prompts[$type['id']]['verify']['content'], 0, 300)) ?>
                                                            <?php if (strlen($grouped_prompts[$type['id']]['verify']['content']) > 300): ?>
                                                                ...
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <p class="text-muted">Prompt nie został jeszcze utworzony.</p>
                                                        <button class="btn btn-sm btn-primary" 
                                                                onclick="editPrompt(<?= $type['id'] ?>, 'verify')">
                                                            Utwórz prompt
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const prompts = <?= json_encode($grouped_prompts) ?>;
        
        function editPrompt(contentTypeId, type) {
            document.getElementById('content_type_id').value = contentTypeId;
            document.getElementById('type').value = type;
            loadPrompt();
        }
        
        function loadPrompt() {
            const contentTypeId = document.getElementById('content_type_id').value;
            const type = document.getElementById('type').value;
            
            if (contentTypeId && type && prompts[contentTypeId] && prompts[contentTypeId][type]) {
                document.getElementById('content').value = prompts[contentTypeId][type].content;
            } else {
                document.getElementById('content').value = '';
            }
        }
    </script>
</body>
</html>