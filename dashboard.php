<?php
require_once 'auth_check.php';

$pdo = getDbConnection();

// Pobierz statystyki dla użytkownika
$stmt = $pdo->prepare("SELECT COUNT(*) as project_count FROM projects WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$project_count = $stmt->fetch()['project_count'];

$stmt = $pdo->prepare("
    SELECT COUNT(*) as task_count 
    FROM tasks t 
    JOIN projects p ON t.project_id = p.id 
    WHERE p.user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$task_count = $stmt->fetch()['task_count'];

$stmt = $pdo->prepare("
    SELECT COUNT(*) as content_count 
    FROM generated_content gc
    JOIN task_items ti ON gc.task_item_id = ti.id
    JOIN tasks t ON ti.task_id = t.id
    JOIN projects p ON t.project_id = p.id
    WHERE p.user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$content_count = $stmt->fetch()['content_count'];

// Pobierz ostatnie zadania
$stmt = $pdo->prepare("
    SELECT t.*, p.name as project_name, ct.name as content_type_name
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    JOIN content_types ct ON t.content_type_id = ct.id
    WHERE p.user_id = ?
    ORDER BY t.created_at DESC
    LIMIT 5
");
$stmt->execute([$_SESSION['user_id']]);
$recent_tasks = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Generator treści SEO</title>
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
                    <h1 class="h2">Dashboard</h1>
                </div>
                
                <!-- Statystyki -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card text-white bg-primary">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?= $project_count ?></h4>
                                        <p class="mb-0">Projekty</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-folder fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?= $task_count ?></h4>
                                        <p class="mb-0">Zadania</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-tasks fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-white bg-info">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?= $content_count ?></h4>
                                        <p class="mb-0">Wygenerowane treści</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-file-text fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Ostatnie zadania -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Ostatnie zadania</h5>
                        <a href="tasks.php" class="btn btn-sm btn-outline-primary">Zobacz wszystkie</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_tasks)): ?>
                            <p class="text-muted">Brak zadań.</p>
                            <a href="projects.php" class="btn btn-primary">Stwórz pierwszy projekt</a>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Nazwa zadania</th>
                                            <th>Projekt</th>
                                            <th>Typ treści</th>
                                            <th>Status</th>
                                            <th>Data utworzenia</th>
                                            <th>Akcje</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_tasks as $task): ?>
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
                                                <td><?= date('d.m.Y H:i', strtotime($task['created_at'])) ?></td>
                                                <td>
                                                    <a href="task_details.php?id=<?= $task['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>