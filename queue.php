<?php
require_once 'auth_check.php';

$pdo = getDbConnection();

$success = '';
$error = '';

// Obsługa wymuszenia generowania
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'force_generate') {
    $queue_id = intval($_POST['queue_id']);
    
    // Sprawdź czy element kolejki należy do użytkownika
    $stmt = $pdo->prepare("
        SELECT tq.id 
        FROM task_queue tq
        JOIN task_items ti ON tq.task_item_id = ti.id
        JOIN tasks t ON ti.task_id = t.id
        JOIN projects p ON t.project_id = p.id
        WHERE tq.id = ? AND p.user_id = ?
    ");
    $stmt->execute([$queue_id, $_SESSION['user_id']]);
    
    if ($stmt->fetch()) {
        try {
            // Ustaw wysoki priorytet i zresetuj status
            $stmt = $pdo->prepare("UPDATE task_queue SET priority = 100, status = 'pending', attempts = 0 WHERE id = ?");
            $stmt->execute([$queue_id]);
            
            $success = 'Zadanie zostało dodane do pierwszeństwa w kolejce.';
            
        } catch(Exception $e) {
            $error = 'Błąd aktualizacji kolejki: ' . $e->getMessage();
        }
    } else {
        $error = 'Nieprawidłowe zadanie.';
    }
}

// Pobierz elementy kolejki dla użytkownika
$stmt = $pdo->prepare("
    SELECT tq.*, ti.url, t.name as task_name, p.name as project_name
    FROM task_queue tq
    JOIN task_items ti ON tq.task_item_id = ti.id
    JOIN tasks t ON ti.task_id = t.id
    JOIN projects p ON t.project_id = p.id
    WHERE p.user_id = ?
    ORDER BY tq.priority DESC, tq.created_at ASC
");
$stmt->execute([$_SESSION['user_id']]);
$queue_items = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kolejka zadań - Generator treści SEO</title>
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
                    <h1 class="h2">Kolejka zadań</h1>
                    <div>
                        <small class="text-muted">Automatyczne odświeżanie co 30 sekund</small>
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
                
                <?php if (empty($queue_items)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-clock fa-3x text-muted mb-3"></i>
                        <h4>Kolejka jest pusta</h4>
                        <p class="text-muted">Wszystkie zadania zostały przetworzone lub nie ma żadnych zadań w kolejce.</p>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Projekt</th>
                                            <th>Zadanie</th>
                                            <th>URL</th>
                                            <th>Status</th>
                                            <th>Priorytet</th>
                                            <th>Próby</th>
                                            <th>Dodano do kolejki</th>
                                            <th>Akcje</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($queue_items as $item): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($item['project_name']) ?></td>
                                                <td><?= htmlspecialchars($item['task_name']) ?></td>
                                                <td>
                                                    <div style="max-width: 200px; word-break: break-all;">
                                                        <a href="<?= htmlspecialchars($item['url']) ?>" target="_blank">
                                                            <?= htmlspecialchars($item['url']) ?>
                                                        </a>
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
                                                    <?php if ($item['attempts'] >= $item['max_attempts'] && $item['status'] === 'failed'): ?>
                                                        <i class="fas fa-exclamation-triangle text-danger" title="Maksymalna liczba prób osiągnięta"></i>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= date('d.m.Y H:i:s', strtotime($item['created_at'])) ?></td>
                                                <td>
                                                    <?php if ($item['status'] === 'pending' || $item['status'] === 'failed'): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="force_generate">
                                                            <input type="hidden" name="queue_id" value="<?= $item['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-primary" 
                                                                    title="Wymusz generowanie natychmiast">
                                                                <i class="fas fa-bolt"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($item['error_message']): ?>
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="showError('<?= htmlspecialchars($item['error_message']) ?>')"
                                                                title="Pokaż błąd">
                                                            <i class="fas fa-exclamation-circle"></i>
                                                        </button>
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showError(message) {
            alert('Błąd: ' + message);
        }
    </script>
</body>
</html>