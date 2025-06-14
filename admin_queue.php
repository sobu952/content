<?php
require_once 'auth_check.php';
requireAdmin();

$pdo = getDbConnection();

$success = '';
$error = '';

// Obsługa akcji administracyjnych
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'clear_completed':
            try {
                $stmt = $pdo->prepare("DELETE FROM task_queue WHERE status = 'completed'");
                $stmt->execute();
                $success = 'Ukończone zadania zostały usunięte z kolejki.';
            } catch(Exception $e) {
                $error = 'Błąd usuwania zadań: ' . $e->getMessage();
            }
            break;
            
        case 'clear_failed':
            try {
                $stmt = $pdo->prepare("DELETE FROM task_queue WHERE status = 'failed'");
                $stmt->execute();
                $success = 'Nieudane zadania zostały usunięte z kolejki.';
            } catch(Exception $e) {
                $error = 'Błąd usuwania zadań: ' . $e->getMessage();
            }
            break;
            
        case 'retry_failed':
            try {
                $stmt = $pdo->prepare("UPDATE task_queue SET status = 'pending', attempts = 0, error_message = NULL WHERE status = 'failed'");
                $stmt->execute();
                $success = 'Nieudane zadania zostały ponownie dodane do kolejki.';
            } catch(Exception $e) {
                $error = 'Błąd ponownego dodawania zadań: ' . $e->getMessage();
            }
            break;
            
        case 'clear_all':
            try {
                $stmt = $pdo->prepare("DELETE FROM task_queue");
                $stmt->execute();
                $success = 'Cała kolejka została wyczyszczona.';
            } catch(Exception $e) {
                $error = 'Błąd czyszczenia kolejki: ' . $e->getMessage();
            }
            break;
    }
}

// Pobierz statystyki kolejki
$stmt = $pdo->query("
    SELECT 
        status,
        COUNT(*) as count
    FROM task_queue 
    GROUP BY status
");
$queue_stats = $stmt->fetchAll();

$stats_by_status = [];
foreach ($queue_stats as $stat) {
    $stats_by_status[$stat['status']] = $stat['count'];
}

// Pobierz elementy kolejki z dodatkowymi informacjami
$stmt = $pdo->query("
    SELECT tq.*, ti.url, t.name as task_name, p.name as project_name, u.email as user_email
    FROM task_queue tq
    JOIN task_items ti ON tq.task_item_id = ti.id
    JOIN tasks t ON ti.task_id = t.id
    JOIN projects p ON t.project_id = p.id
    JOIN users u ON p.user_id = u.id
    ORDER BY tq.priority DESC, tq.created_at ASC
    LIMIT 100
");
$queue_items = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kolejka zadań (Admin) - Generator treści SEO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <meta http-equiv="refresh" content="30">
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Kolejka zadań (Admin)</h1>
                    <div>
                        <small class="text-muted">Automatyczne odświeżanie co 30 sekund</small>
                        <a href="process_queue.php" target="_blank" class="btn btn-sm btn-outline-info ms-2">
                            <i class="fas fa-flask"></i> Test procesora
                        </a>
                    </div>
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
                
                <!-- Statystyki kolejki -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-white bg-warning">
                            <div class="card-body text-center">
                                <h4><?= $stats_by_status['pending'] ?? 0 ?></h4>
                                <small>Oczekuje</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-info">
                            <div class="card-body text-center">
                                <h4><?= $stats_by_status['processing'] ?? 0 ?></h4>
                                <small>Przetwarzanie</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-success">
                            <div class="card-body text-center">
                                <h4><?= $stats_by_status['completed'] ?? 0 ?></h4>
                                <small>Ukończone</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-danger">
                            <div class="card-body text-center">
                                <h4><?= $stats_by_status['failed'] ?? 0 ?></h4>
                                <small>Błędy</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Akcje administracyjne -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Akcje administracyjne</h5>
                    </div>
                    <div class="card-body">
                        <div class="btn-group" role="group">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="clear_completed">
                                <button type="submit" class="btn btn-outline-success" 
                                        onclick="return confirm('Czy na pewno chcesz usunąć wszystkie ukończone zadania?')">
                                    <i class="fas fa-check"></i> Usuń ukończone
                                </button>
                            </form>
                            
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="retry_failed">
                                <button type="submit" class="btn btn-outline-warning" 
                                        onclick="return confirm('Czy na pewno chcesz ponowić wszystkie nieudane zadania?')">
                                    <i class="fas fa-redo"></i> Ponów nieudane
                                </button>
                            </form>
                            
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="clear_failed">
                                <button type="submit" class="btn btn-outline-danger" 
                                        onclick="return confirm('Czy na pewno chcesz usunąć wszystkie nieudane zadania?')">
                                    <i class="fas fa-times"></i> Usuń nieudane
                                </button>
                            </form>
                            
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="clear_all">
                                <button type="submit" class="btn btn-danger" 
                                        onclick="return confirm('UWAGA: To usunie WSZYSTKIE zadania z kolejki! Czy na pewno?')">
                                    <i class="fas fa-trash"></i> Wyczyść wszystko
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <?php if (empty($queue_items)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-clock fa-3x text-muted mb-3"></i>
                        <h4>Kolejka jest pusta</h4>
                        <p class="text-muted">Wszystkie zadania zostały przetworzone lub nie ma żadnych zadań w kolejce.</p>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Elementy kolejki (ostatnie 100)</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-sm">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Użytkownik</th>
                                            <th>Projekt</th>
                                            <th>Zadanie</th>
                                            <th>URL</th>
                                            <th>Status</th>
                                            <th>Priorytet</th>
                                            <th>Próby</th>
                                            <th>Dodano</th>
                                            <th>Przetworzono</th>
                                            <th>Błąd</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($queue_items as $item): ?>
                                            <tr>
                                                <td><?= $item['id'] ?></td>
                                                <td><?= htmlspecialchars($item['user_email']) ?></td>
                                                <td><?= htmlspecialchars($item['project_name']) ?></td>
                                                <td><?= htmlspecialchars($item['task_name']) ?></td>
                                                <td>
                                                    <div style="max-width: 150px; word-break: break-all; font-size: 0.8em;">
                                                        <?= htmlspecialchars($item['url']) ?>
                                                    </div>
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
                                                    <span class="badge <?= $status_classes[$item['status']] ?>">
                                                        <?= $status_labels[$item['status']] ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?= $item['priority'] ?></span>
                                                </td>
                                                <td>
                                                    <?= $item['attempts'] ?> / <?= $item['max_attempts'] ?>
                                                </td>
                                                <td>
                                                    <small><?= date('d.m H:i', strtotime($item['created_at'])) ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($item['processed_at']): ?>
                                                        <small><?= date('d.m H:i', strtotime($item['processed_at'])) ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($item['error_message']): ?>
                                                        <button class="btn btn-sm btn-outline-warning" 
                                                                onclick="showError('<?= htmlspecialchars(addslashes($item['error_message'])) ?>')"
                                                                title="Pokaż błąd">
                                                            <i class="fas fa-exclamation-circle"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <!-- Modal błędu -->
    <div class="modal fade" id="errorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Szczegóły błędu</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <pre id="errorContent" class="bg-light p-3"></pre>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zamknij</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showError(message) {
            document.getElementById('errorContent').textContent = message;
            var modal = new bootstrap.Modal(document.getElementById('errorModal'));
            modal.show();
        }
    </script>
</body>
</html>