<?php
require_once 'auth_check.php';

$pdo = getDbConnection();

$success = '';
$error = '';

// Obsługa usuwania projektu
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ? AND user_id = ?");
        $stmt->execute([$_GET['delete'], $_SESSION['user_id']]);
        $success = 'Projekt został usunięty.';
    } catch(Exception $e) {
        $error = 'Błąd usuwania projektu: ' . $e->getMessage();
    }
}

// Obsługa dodawania/edycji projektu
if ($_POST) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $project_id = isset($_POST['project_id']) ? $_POST['project_id'] : null;
    
    if (empty($name)) {
        $error = 'Nazwa projektu jest wymagana.';
    } else {
        try {
            if ($project_id) {
                // Edycja
                $stmt = $pdo->prepare("UPDATE projects SET name = ?, description = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$name, $description, $project_id, $_SESSION['user_id']]);
                $success = 'Projekt został zaktualizowany.';
            } else {
                // Dodawanie
                $stmt = $pdo->prepare("INSERT INTO projects (user_id, name, description) VALUES (?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $name, $description]);
                $success = 'Projekt został utworzony.';
            }
        } catch(Exception $e) {
            $error = 'Błąd zapisywania projektu: ' . $e->getMessage();
        }
    }
}

// Pobierz projekt do edycji
$edit_project = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
    $stmt->execute([$_GET['edit'], $_SESSION['user_id']]);
    $edit_project = $stmt->fetch();
}

// Pobierz wszystkie projekty użytkownika
$stmt = $pdo->prepare("
    SELECT p.*, 
           COUNT(DISTINCT t.id) as task_count,
           COUNT(DISTINCT gc.id) as content_count
    FROM projects p
    LEFT JOIN tasks t ON p.id = t.project_id
    LEFT JOIN task_items ti ON t.id = ti.task_id
    LEFT JOIN generated_content gc ON ti.id = gc.task_item_id
    WHERE p.user_id = ?
    GROUP BY p.id
    ORDER BY p.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$projects = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projekty - Generator treści SEO</title>
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
                    <h1 class="h2">Projekty</h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#projectModal">
                        <i class="fas fa-plus"></i> Nowy projekt
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
                
                <?php if (empty($projects)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-folder fa-3x text-muted mb-3"></i>
                        <h4>Brak projektów</h4>
                        <p class="text-muted">Stwórz swój pierwszy projekt, aby zacząć generować treści.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#projectModal">
                            Stwórz projekt
                        </button>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($projects as $project): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <h5 class="card-title"><?= htmlspecialchars($project['name']) ?></h5>
                                        <p class="card-text text-muted"><?= htmlspecialchars($project['description']) ?></p>
                                        
                                        <div class="row text-center mb-3">
                                            <div class="col">
                                                <small class="text-muted">Zadania</small>
                                                <div class="fw-bold"><?= $project['task_count'] ?></div>
                                            </div>
                                            <div class="col">
                                                <small class="text-muted">Treści</small>
                                                <div class="fw-bold"><?= $project['content_count'] ?></div>
                                            </div>
                                        </div>
                                        
                                        <small class="text-muted">
                                            Utworzony: <?= date('d.m.Y', strtotime($project['created_at'])) ?>
                                        </small>
                                    </div>
                                    <div class="card-footer bg-transparent">
                                        <div class="btn-group w-100" role="group">
                                            <a href="tasks.php?project_id=<?= $project['id'] ?>" class="btn btn-outline-primary">
                                                <i class="fas fa-tasks"></i> Zadania
                                            </a>
                                            <button class="btn btn-outline-secondary" onclick="editProject(<?= htmlspecialchars(json_encode($project)) ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="?delete=<?= $project['id'] ?>" class="btn btn-outline-danger" 
                                               onclick="return confirm('Czy na pewno chcesz usunąć ten projekt?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <!-- Modal dodawania/edycji projektu -->
    <div class="modal fade" id="projectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="projectModalTitle">Nowy projekt</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="project_id" id="project_id" value="">
                        <div class="mb-3">
                            <label for="name" class="form-label">Nazwa projektu *</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Opis</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
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
        function editProject(project) {
            document.getElementById('projectModalTitle').textContent = 'Edytuj projekt';
            document.getElementById('project_id').value = project.id;
            document.getElementById('name').value = project.name;
            document.getElementById('description').value = project.description || '';
            
            var modal = new bootstrap.Modal(document.getElementById('projectModal'));
            modal.show();
        }
        
        // Reset modal when closed
        document.getElementById('projectModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('projectModalTitle').textContent = 'Nowy projekt';
            document.getElementById('project_id').value = '';
            document.getElementById('name').value = '';
            document.getElementById('description').value = '';
        });
    </script>
</body>
</html>