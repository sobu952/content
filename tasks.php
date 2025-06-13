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

// Obsługa dodawania zadania
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'create_task') {
    $task_project_id = intval($_POST['project_id']);
    $content_type_id = intval($_POST['content_type_id']);
    $task_name = trim($_POST['task_name']);
    $strictness_level = floatval($_POST['strictness_level']);
    $urls = array_filter(array_map('trim', explode("\n", $_POST['urls'])));
    
    // Sprawdź czy projekt należy do użytkownika
    $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND user_id = ?");
    $stmt->execute([$task_project_id, $_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        $error = 'Nieprawidłowy projekt.';
    } elseif (empty($task_name)) {
        $error = 'Nazwa zadania jest wymagana.';
    } elseif (empty($urls)) {
        $error = 'Musisz podać co najmniej jeden URL.';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Pobierz pola dla typu treści
            $stmt = $pdo->prepare("SELECT fields FROM content_types WHERE id = ?");
            $stmt->execute([$content_type_id]);
            $content_type = $stmt->fetch();
            $fields = json_decode($content_type['fields'], true);
            
            // Utwórz zadanie
            $stmt = $pdo->prepare("INSERT INTO tasks (project_id, content_type_id, name, strictness_level) VALUES (?, ?, ?, ?)");
            $stmt->execute([$task_project_id, $content_type_id, $task_name, $strictness_level]);
            $task_id = $pdo->lastInsertId();
            
            // Dodaj elementy zadania dla każdego URL
            foreach ($urls as $url) {
                if (empty($url)) continue;
                
                // Zbierz dane dla każdego pola
                $input_data = ['url' => $url];
                foreach ($fields as $field_key => $field_config) {
                    if ($field_key === 'url') continue;
                    
                    $field_value = isset($_POST[$field_key]) ? $_POST[$field_key] : '';
                    if ($field_config['type'] === 'checkbox') {
                        $field_value = isset($_POST[$field_key]) ? 'TAK' : 'NIE';
                    }
                    $input_data[$field_key] = $field_value;
                }
                
                $stmt = $pdo->prepare("INSERT INTO task_items (task_id, url, input_data) VALUES (?, ?, ?)");
                $stmt->execute([$task_id, $url, json_encode($input_data)]);
                $task_item_id = $pdo->lastInsertId();
                
                // Dodaj do kolejki
                $stmt = $pdo->prepare("INSERT INTO task_queue (task_item_id) VALUES (?)");
                $stmt->execute([$task_item_id]);
            }
            
            $pdo->commit();
            $success = 'Zadanie zostało utworzone i dodane do kolejki.';
            
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
           COUNT(ti.id) as item_count,
           COUNT(CASE WHEN ti.status = 'completed' THEN 1 END) as completed_count
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    JOIN content_types ct ON t.content_type_id = ct.id
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
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#taskModal">
                            Stwórz zadanie
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Nazwa zadania</th>
                                    <th>Projekt</th>
                                    <th>Typ treści</th>
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
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="taskForm">
                    <input type="hidden" name="action" value="create_task">
                    <div class="modal-header">
                        <h5 class="modal-title">Nowe zadanie</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
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
                            <div class="col-md-6">
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
                        
                        <div class="mb-3">
                            <label for="urls" class="form-label">Adresy URL (jeden w każdej linii) *</label>
                            <textarea class="form-control" name="urls" rows="5" required 
                                      placeholder="https://example.com/category1&#10;https://example.com/category2"></textarea>
                        </div>
                        
                        <div id="content_type_fields">
                            <!-- Pola będą wczytane przez JavaScript -->
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
            const fieldsContainer = document.getElementById('content_type_fields');
            
            if (!contentTypeId) {
                fieldsContainer.innerHTML = '';
                return;
            }
            
            try {
                const response = await fetch('ajax_content_type_fields.php?id=' + contentTypeId);
                const data = await response.json();
                
                let html = '<h6>Pola dla typu treści:</h6>';
                
                for (const [fieldKey, fieldConfig] of Object.entries(data.fields)) {
                    if (fieldKey === 'url') continue; // URL jest już obsługiwany oddzielnie
                    
                    html += '<div class="mb-3">';
                    html += '<label for="' + fieldKey + '" class="form-label">' + fieldConfig.label;
                    if (fieldConfig.required) html += ' *';
                    html += '</label>';
                    
                    if (fieldConfig.type === 'textarea') {
                        html += '<textarea class="form-control" name="' + fieldKey + '" id="' + fieldKey + '"';
                        if (fieldConfig.required) html += ' required';
                        html += '></textarea>';
                    } else if (fieldConfig.type === 'checkbox') {
                        html += '<div class="form-check">';
                        html += '<input class="form-check-input" type="checkbox" name="' + fieldKey + '" id="' + fieldKey + '">';
                        html += '<label class="form-check-label" for="' + fieldKey + '">' + fieldConfig.label + '</label>';
                        html += '</div>';
                    } else {
                        html += '<input type="' + fieldConfig.type + '" class="form-control" name="' + fieldKey + '" id="' + fieldKey + '"';
                        if (fieldConfig.required) html += ' required';
                        html += '>';
                    }
                    
                    html += '</div>';
                }
                
                fieldsContainer.innerHTML = html;
            } catch (error) {
                console.error('Error loading content type fields:', error);
            }
        }
    </script>
</body>
</html>