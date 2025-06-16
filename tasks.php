<?php
require_once 'auth_check.php';

$pdo = getDbConnection();

$success = '';
$error = '';
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : null;

// Sprawdź czy projekt należy do użytkownika
if ($project_id) {
    $stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ? AND user_id = ?");
    $stmt->execute([$project_id, $_SESSION['user_id']]);
    $project = $stmt->fetch();
    if (!$project) {
        header('Location: projects.php');
        exit;
    }
}

// Pobierz typy treści
$stmt = $pdo->query("SELECT id, name FROM content_types ORDER BY name");
$content_types = $stmt->fetchAll();

// Pobierz projekty użytkownika
$stmt = $pdo->prepare("SELECT id, name FROM projects WHERE user_id = ? ORDER BY name");
$stmt->execute([$_SESSION['user_id']]);
$user_projects = $stmt->fetchAll();

// Pobierz dostępne modele AI
$stmt = $pdo->query("
    SELECT am.id, am.name, ap.name as provider_name 
    FROM ai_models am
    JOIN ai_providers ap ON am.provider_id = ap.id
    WHERE am.is_active = 1 AND ap.is_active = 1
    AND EXISTS (SELECT 1 FROM ai_api_keys ak WHERE ak.provider_id = ap.id AND ak.is_active = 1)
    ORDER BY ap.name, am.name
");
$ai_models = $stmt->fetchAll();

// Obsługa dodawania zadania
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'create_task') {
    $task_project_id = intval($_POST['project_id']);
    $content_type_id = intval($_POST['content_type_id']);
    $ai_model_id = intval($_POST['ai_model_id']);
    $task_name = trim($_POST['task_name']);
    $strictness_level = floatval($_POST['strictness_level']);
    
    // Sprawdź czy projekt należy do użytkownika
    $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND user_id = ?");
    $stmt->execute([$task_project_id, $_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        $error = 'Nieprawidłowy projekt.';
    } elseif (empty($task_name)) {
        $error = 'Nazwa zadania jest wymagana.';
    } elseif (empty($ai_model_id)) {
        $error = 'Model AI jest wymagany.';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Pobierz pola dla typu treści
            $stmt = $pdo->prepare("SELECT fields FROM content_types WHERE id = ?");
            $stmt->execute([$content_type_id]);
            $content_type = $stmt->fetch();
            $fields = json_decode($content_type['fields'], true);
            
            // Utwórz zadanie
            $stmt = $pdo->prepare("INSERT INTO tasks (project_id, content_type_id, ai_model_id, name, strictness_level) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$task_project_id, $content_type_id, $ai_model_id, $task_name, $strictness_level]);
            $task_id = $pdo->lastInsertId();
            
            // Sprawdź ile URL-i zostało dodanych
            $url_count = 0;
            if (isset($_POST['urls']) && is_array($_POST['urls'])) {
                foreach ($_POST['urls'] as $index => $url) {
                    if (empty(trim($url))) continue;
                    
                    // Zbierz dane dla każdego pola dla tego konkretnego URL
                    $input_data = ['url' => trim($url)];
                    foreach ($fields as $field_key => $field_config) {
                        if ($field_key === 'url') continue;
                        
                        $field_value = '';
                        if ($field_config['type'] === 'checkbox') {
                            $field_value = isset($_POST[$field_key][$index]) ? 'TAK' : 'NIE';
                        } else {
                            $field_value = isset($_POST[$field_key][$index]) ? $_POST[$field_key][$index] : '';
                        }
                        $input_data[$field_key] = $field_value;
                    }
                    
                    $stmt = $pdo->prepare("INSERT INTO task_items (task_id, url, input_data) VALUES (?, ?, ?)");
                    $stmt->execute([$task_id, trim($url), json_encode($input_data)]);
                    $task_item_id = $pdo->lastInsertId();
                    
                    // Dodaj do kolejki
                    $stmt = $pdo->prepare("INSERT INTO task_queue (task_item_id) VALUES (?)");
                    $stmt->execute([$task_item_id]);
                    
                    $url_count++;
                }
            }
            
            if ($url_count === 0) {
                throw new Exception('Musisz dodać co najmniej jeden URL.');
            }
            
            $pdo->commit();
            $success = "Zadanie zostało utworzone z $url_count elementami i dodane do kolejki.";
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $error = 'Błąd tworzenia zadania: ' . $e->getMessage();
        }
    }
}

// Pobierz zadania
$where_conditions = ['p.user_id = ?'];
$params = [$_SESSION['user_id']];

if ($project_id) {
    $where_conditions[] = 't.project_id = ?';
    $params[] = $project_id;
}

$stmt = $pdo->prepare("
    SELECT t.*, p.name as project_name, ct.name as content_type_name,
           am.name as ai_model_name, ap.name as ai_provider_name,
           COUNT(ti.id) as item_count,
           COUNT(CASE WHEN ti.status = 'completed' THEN 1 END) as completed_count
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    JOIN content_types ct ON t.content_type_id = ct.id
    LEFT JOIN ai_models am ON t.ai_model_id = am.id
    LEFT JOIN ai_providers ap ON am.provider_id = ap.id
    LEFT JOIN task_items ti ON t.id = ti.task_id
    WHERE " . implode(' AND ', $where_conditions) . "
    GROUP BY t.id
    ORDER BY t.created_at DESC
");
$stmt->execute($params);
$tasks = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zadania - Generator treści SEO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .url-item {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
            background-color: #f8f9fa;
        }
        .url-item-header {
            display: flex;
            justify-content-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .remove-url-btn {
            color: #dc3545;
            cursor: pointer;
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
                    <h1 class="h2">
                        Zadania
                        <?php if ($project_id && isset($project)): ?>
                            - <?= htmlspecialchars($project['name']) ?>
                        <?php endif; ?>
                    </h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#taskModal">
                        <i class="fas fa-plus"></i> Nowe zadanie
                    </button>
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
                
                <?php if (empty($ai_models)): ?>
                    <div class="alert alert-warning">
                        <h5><i class="fas fa-exclamation-triangle"></i> Brak dostępnych modeli AI</h5>
                        <p>Nie można tworzyć zadań, ponieważ nie skonfigurowano żadnych modeli AI lub kluczy API.</p>
                        <?php if (isAdmin()): ?>
                            <p><a href="admin_ai_providers.php" class="btn btn-primary">Skonfiguruj modele AI</a></p>
                        <?php else: ?>
                            <p>Skontaktuj się z administratorem w celu skonfigurowania modeli AI.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!$project_id): ?>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <select class="form-select" onchange="filterByProject(this.value)">
                                <option value="">Wszystkie projekty</option>
                                <?php foreach ($user_projects as $proj): ?>
                                    <option value="<?= $proj['id'] ?>"><?= htmlspecialchars($proj['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($tasks)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                        <h4>Brak zadań</h4>
                        <p class="text-muted">Stwórz swoje pierwsze zadanie, aby zacząć generować treści.</p>
                        <?php if (!empty($ai_models)): ?>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#taskModal">
                                Stwórz zadanie
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Nazwa zadania</th>
                                    <th>Projekt</th>
                                    <th>Typ treści</th>
                                    <th>Model AI</th>
                                    <th>Status</th>
                                    <th>Postęp</th>
                                    <th>Poziom naturalności</th>
                                    <th>Data utworzenia</th>
                                    <th>Akcje</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tasks as $task): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($task['name']) ?></td>
                                        <td><?= htmlspecialchars($task['project_name']) ?></td>
                                        <td><?= htmlspecialchars($task['content_type_name']) ?></td>
                                        <td>
                                            <?php if ($task['ai_model_name']): ?>
                                                <small class="text-muted"><?= htmlspecialchars($task['ai_provider_name']) ?></small><br>
                                                <strong><?= htmlspecialchars($task['ai_model_name']) ?></strong>
                                            <?php else: ?>
                                                <span class="text-warning">Brak modelu</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status_classes = [
                                                'pending' => 'bg-warning',
                                                'processing' => 'bg-info',
                                                'completed' => 'bg-success',
                                                'failed' => 'bg-danger'
                                            ];
                                            $status_labels = [
                                                'pending' => 'Oczekuje',
                                                'processing' => 'Przetwarzanie',
                                                'completed' => 'Ukończone',
                                                'failed' => 'Błąd'
                                            ];
                                            ?>
                                            <span class="badge <?= $status_classes[$task['status']] ?>">
                                                <?= $status_labels[$task['status']] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= $task['completed_count'] ?> / <?= $task['item_count'] ?>
                                            <?php if ($task['item_count'] > 0): ?>
                                                <div class="progress mt-1" style="height: 5px;">
                                                    <div class="progress-bar" style="width: <?= ($task['completed_count'] / $task['item_count']) * 100 ?>%"></div>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $task['strictness_level'] ?></td>
                                        <td><?= date('d.m.Y H:i', strtotime($task['created_at'])) ?></td>
                                        <td>
                                            <div class="btn-group">
                                                <a href="task_details.php?id=<?= $task['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if ($task['completed_count'] > 0): ?>
                                                    <a href="export_docx.php?task_id=<?= $task['id'] ?>" class="btn btn-sm btn-outline-success">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <!-- Modal dodawania zadania -->
    <div class="modal fade" id="taskModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" id="taskForm">
                    <input type="hidden" name="action" value="create_task">
                    <div class="modal-header">
                        <h5 class="modal-title">Nowe zadanie</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="project_id" class="form-label">Projekt *</label>
                                    <select class="form-select" name="project_id" required>
                                        <option value="">Wybierz projekt</option>
                                        <?php foreach ($user_projects as $proj): ?>
                                            <option value="<?= $proj['id'] ?>" <?= ($project_id == $proj['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($proj['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="content_type_id" class="form-label">Typ treści *</label>
                                    <select class="form-select" name="content_type_id" id="content_type_id" required onchange="loadContentTypeFields()">
                                        <option value="">Wybierz typ treści</option>
                                        <?php foreach ($content_types as $type): ?>
                                            <option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="ai_model_id" class="form-label">Model AI *</label>
                                    <select class="form-select" name="ai_model_id" required>
                                        <option value="">Wybierz model AI</option>
                                        <?php foreach ($ai_models as $model): ?>
                                            <option value="<?= $model['id'] ?>">
                                                <?= htmlspecialchars($model['provider_name']) ?> - <?= htmlspecialchars($model['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="task_name" class="form-label">Nazwa zadania *</label>
                            <input type="text" class="form-control" name="task_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="strictness_level" class="form-label">Poziom naturalności: <span id="strictness_value">0.0</span></label>
                            <input type="range" class="form-range" name="strictness_level" id="strictness_level" 
                                   min="0" max="1" step="0.1" value="0" oninput="updateStrictnessValue(this.value)">
                            <div class="d-flex justify-content-between">
                                <small class="text-muted">Naturalny (0)</small>
                                <small class="text-muted">Dokładny (1)</small>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">Adresy URL i ich parametry</h6>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addUrlItem()">
                                <i class="fas fa-plus"></i> Dodaj kolejny URL
                            </button>
                        </div>
                        
                        <div id="url_items_container">
                            <!-- URL items będą dodawane przez JavaScript -->
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                        <button type="submit" class="btn btn-primary">Utwórz zadanie</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const contentTypes = <?= json_encode($content_types) ?>;
        let currentFields = {};
        let urlItemIndex = 0;
        
        function updateStrictnessValue(value) {
            document.getElementById('strictness_value').textContent = value;
        }
        
        function filterByProject(projectId) {
            if (projectId) {
                window.location.href = 'tasks.php?project_id=' + projectId;
            } else {
                window.location.href = 'tasks.php';
            }
        }
        
        async function loadContentTypeFields() {
            const contentTypeId = document.getElementById('content_type_id').value;
            
            if (!contentTypeId) {
                currentFields = {};
                document.getElementById('url_items_container').innerHTML = '';
                return;
            }
            
            try {
                const response = await fetch('ajax_content_type_fields.php?id=' + contentTypeId);
                const data = await response.json();
                currentFields = data.fields;
                
                // Wyczyść istniejące URL items i dodaj pierwszy
                document.getElementById('url_items_container').innerHTML = '';
                urlItemIndex = 0;
                addUrlItem();
                
            } catch (error) {
                console.error('Error loading content type fields:', error);
            }
        }
        
        function addUrlItem() {
            const container = document.getElementById('url_items_container');
            const index = urlItemIndex++;
            
            let html = `
                <div class="url-item" data-index="${index}">
                    <div class="url-item-header">
                        <h6 class="mb-0">URL #${index + 1}</h6>
                        ${index > 0 ? `<i class="fas fa-times remove-url-btn" onclick="removeUrlItem(${index})"></i>` : ''}
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Adres URL *</label>
                        <input type="url" class="form-control" name="urls[${index}]" required 
                               placeholder="https://example.com/category">
                    </div>
            `;
            
            // Dodaj pola dla typu treści (pomijając URL które już mamy)
            for (const [fieldKey, fieldConfig] of Object.entries(currentFields)) {
                if (fieldKey === 'url') continue;
                
                html += '<div class="mb-3">';
                html += `<label class="form-label">${fieldConfig.label}`;
                if (fieldConfig.required) html += ' *';
                html += '</label>';
                
                if (fieldConfig.type === 'textarea') {
                    html += `<textarea class="form-control" name="${fieldKey}[${index}]"`;
                    if (fieldConfig.required) html += ' required';
                    html += '></textarea>';
                } else if (fieldConfig.type === 'checkbox') {
                    html += '<div class="form-check">';
                    html += `<input class="form-check-input" type="checkbox" name="${fieldKey}[${index}]">`;
                    html += `<label class="form-check-label">${fieldConfig.label}</label>`;
                    html += '</div>';
                } else {
                    html += `<input type="${fieldConfig.type}" class="form-control" name="${fieldKey}[${index}]"`;
                    if (fieldConfig.required) html += ' required';
                    html += '>';
                }
                
                html += '</div>';
            }
            
            html += '</div>';
            
            container.insertAdjacentHTML('beforeend', html);
        }
        
        function removeUrlItem(index) {
            const item = document.querySelector(`[data-index="${index}"]`);
            if (item) {
                item.remove();
                updateUrlItemNumbers();
            }
        }
        
        function updateUrlItemNumbers() {
            const items = document.querySelectorAll('.url-item');
            items.forEach((item, index) => {
                const header = item.querySelector('h6');
                if (header) {
                    header.textContent = `URL #${index + 1}`;
                }
            });
        }
        
        // Reset modal when closed
        document.getElementById('taskModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('content_type_id').value = '';
            document.getElementById('url_items_container').innerHTML = '';
            currentFields = {};
            urlItemIndex = 0;
        });
    </script>
</body>
</html>