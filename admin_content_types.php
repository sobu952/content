<?php
require_once 'auth_check.php';
requireAdmin();

$pdo = getDbConnection();

$success = '';
$error = '';

// Obsługa usuwania typu treści
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM content_types WHERE id = ?");
        $stmt->execute([$_GET['delete']]);
        $success = 'Typ treści został usunięty.';
    } catch(Exception $e) {
        $error = 'Błąd usuwania typu treści: ' . $e->getMessage();
    }
}

// Obsługa dodawania/edycji typu treści
if ($_POST) {
    $name = trim($_POST['name']);
    $content_type_id = isset($_POST['content_type_id']) ? $_POST['content_type_id'] : null;
    
    // Budowanie pól
    $fields = [];
    if (isset($_POST['field_keys'])) {
        foreach ($_POST['field_keys'] as $index => $key) {
            if (empty($key)) continue;
            
            $fields[$key] = [
                'type' => $_POST['field_types'][$index],
                'label' => $_POST['field_labels'][$index],
                'required' => isset($_POST['field_required'][$index])
            ];
        }
    }
    
    if (empty($name)) {
        $error = 'Nazwa typu treści jest wymagana.';
    } elseif (empty($fields)) {
        $error = 'Musisz dodać co najmniej jedno pole.';
    } else {
        try {
            if ($content_type_id) {
                // Edycja
                $stmt = $pdo->prepare("UPDATE content_types SET name = ?, fields = ? WHERE id = ?");
                $stmt->execute([$name, json_encode($fields), $content_type_id]);
                $success = 'Typ treści został zaktualizowany.';
            } else {
                // Dodawanie
                $stmt = $pdo->prepare("INSERT INTO content_types (name, fields) VALUES (?, ?)");
                $stmt->execute([$name, json_encode($fields)]);
                $success = 'Typ treści został utworzony.';
            }
        } catch(Exception $e) {
            $error = 'Błąd zapisywania typu treści: ' . $e->getMessage();
        }
    }
}

// Pobierz typ treści do edycji
$edit_content_type = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM content_types WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_content_type = $stmt->fetch();
}

// Pobierz wszystkie typy treści
$stmt = $pdo->query("
    SELECT ct.*, 
           COUNT(DISTINCT t.id) as task_count
    FROM content_types ct
    LEFT JOIN tasks t ON ct.id = t.content_type_id
    GROUP BY ct.id
    ORDER BY ct.created_at DESC
");
$content_types = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Typy treści - Generator treści SEO</title>
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
                    <h1 class="h2">Typy treści</h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#contentTypeModal">
                        <i class="fas fa-plus"></i> Nowy typ treści
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
                
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Nazwa</th>
                                        <th>Pola</th>
                                        <th>Zadania</th>
                                        <th>Data utworzenia</th>
                                        <th>Akcje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($content_types as $type): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($type['name']) ?></td>
                                            <td>
                                                <?php
                                                $fields = json_decode($type['fields'], true);
                                                $field_names = array_keys($fields);
                                                echo count($field_names) . ' pól: ' . implode(', ', array_slice($field_names, 0, 3));
                                                if (count($field_names) > 3) echo '...';
                                                ?>
                                            </td>
                                            <td><?= $type['task_count'] ?></td>
                                            <td><?= date('d.m.Y H:i', strtotime($type['created_at'])) ?></td>
                                            <td>
                                                <div class="btn-group">
                                                    <button class="btn btn-sm btn-outline-secondary" 
                                                            onclick="editContentType(<?= htmlspecialchars(json_encode($type)) ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="?delete=<?= $type['id'] ?>" class="btn btn-sm btn-outline-danger" 
                                                       onclick="return confirm('Czy na pewno chcesz usunąć ten typ treści?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Modal dodawania/edycji typu treści -->
    <div class="modal fade" id="contentTypeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="contentTypeModalTitle">Nowy typ treści</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="content_type_id" id="content_type_id" value="">
                        <div class="mb-3">
                            <label for="name" class="form-label">Nazwa typu treści *</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        
                        <h6>Pola formularza</h6>
                        <div id="fields-container">
                            <!-- Pola będą dodawane dynamicznie -->
                        </div>
                        
                        <button type="button" class="btn btn-outline-primary" onclick="addField()">
                            <i class="fas fa-plus"></i> Dodaj pole
                        </button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                        <button type="submit" class="btn btn-primary">Zapisz</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let fieldIndex = 0;
        
        function addField(key = '', type = 'text', label = '', required = false) {
            const container = document.getElementById('fields-container');
            const fieldHtml = `
                <div class="row mb-3 field-row" data-index="${fieldIndex}">
                    <div class="col-md-3">
                        <label class="form-label">Klucz pola</label>
                        <input type="text" class="form-control" name="field_keys[]" value="${key}" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Typ pola</label>
                        <select class="form-select" name="field_types[]" required>
                            <option value="text" ${type === 'text' ? 'selected' : ''}>Tekst</option>
                            <option value="textarea" ${type === 'textarea' ? 'selected' : ''}>Textarea</option>
                            <option value="number" ${type === 'number' ? 'selected' : ''}>Liczba</option>
                            <option value="url" ${type === 'url' ? 'selected' : ''}>URL</option>
                            <option value="checkbox" ${type === 'checkbox' ? 'selected' : ''}>Checkbox</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Etykieta</label>
                        <input type="text" class="form-control" name="field_labels[]" value="${label}" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Wymagane</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="field_required[${fieldIndex}]" ${required ? 'checked' : ''}>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger mt-1" onclick="removeField(${fieldIndex})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', fieldHtml);
            fieldIndex++;
        }
        
        function removeField(index) {
            const fieldRow = document.querySelector(`[data-index="${index}"]`);
            if (fieldRow) {
                fieldRow.remove();
            }
        }
        
        function editContentType(contentType) {
            document.getElementById('contentTypeModalTitle').textContent = 'Edytuj typ treści';
            document.getElementById('content_type_id').value = contentType.id;
            document.getElementById('name').value = contentType.name;
            
            // Wyczyść pola
            document.getElementById('fields-container').innerHTML = '';
            fieldIndex = 0;
            
            // Dodaj istniejące pola
            const fields = JSON.parse(contentType.fields);
            for (const [key, config] of Object.entries(fields)) {
                addField(key, config.type, config.label, config.required);
            }
            
            var modal = new bootstrap.Modal(document.getElementById('contentTypeModal'));
            modal.show();
        }
        
        // Reset modal when closed
        document.getElementById('contentTypeModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('contentTypeModalTitle').textContent = 'Nowy typ treści';
            document.getElementById('content_type_id').value = '';
            document.getElementById('name').value = '';
            document.getElementById('fields-container').innerHTML = '';
            fieldIndex = 0;
            
            // Dodaj domyślne pole URL
            addField('url', 'url', 'Adres URL', true);
        });
        
        // Dodaj domyślne pole URL przy pierwszym otwarciu
        document.addEventListener('DOMContentLoaded', function() {
            addField('url', 'url', 'Adres URL', true);
        });
    </script>
</body>
</html>