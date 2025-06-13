<?php
require_once 'auth_check.php';
requireAdmin();

$pdo = getDbConnection();

$success = '';
$error = '';

// Obsługa usuwania użytkownika
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $user_id = intval($_GET['delete']);
    
    // Nie pozwól usunąć samego siebie
    if ($user_id == $_SESSION['user_id']) {
        $error = 'Nie możesz usunąć swojego własnego konta.';
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $success = 'Użytkownik został usunięty.';
        } catch(Exception $e) {
            $error = 'Błąd usuwania użytkownika: ' . $e->getMessage();
        }
    }
}

// Obsługa dodawania/edycji użytkownika
if ($_POST) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    $user_id = isset($_POST['user_id']) ? $_POST['user_id'] : null;
    
    if (empty($email)) {
        $error = 'Email jest wymagany.';
    } elseif ($user_id && empty($password)) {
        // Edycja bez zmiany hasła
        try {
            $stmt = $pdo->prepare("UPDATE users SET email = ?, role = ? WHERE id = ?");
            $stmt->execute([$email, $role, $user_id]);
            $success = 'Użytkownik został zaktualizowany.';
        } catch(Exception $e) {
            $error = 'Błąd aktualizacji użytkownika: ' . $e->getMessage();
        }
    } else {
        // Dodawanie lub edycja z hasłem
        if (strlen($password) < 6) {
            $error = 'Hasło musi mieć co najmniej 6 znaków.';
        } else {
            try {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                if ($user_id) {
                    // Edycja z hasłem
                    $stmt = $pdo->prepare("UPDATE users SET email = ?, password = ?, role = ? WHERE id = ?");
                    $stmt->execute([$email, $hashed_password, $role, $user_id]);
                    $success = 'Użytkownik został zaktualizowany.';
                } else {
                    // Dodawanie
                    $stmt = $pdo->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, ?)");
                    $stmt->execute([$email, $hashed_password, $role]);
                    $success = 'Użytkownik został utworzony.';
                }
            } catch(Exception $e) {
                $error = 'Błąd zapisywania użytkownika: ' . $e->getMessage();
            }
        }
    }
}

// Pobierz użytkownika do edycji
$edit_user = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_user = $stmt->fetch();
}

// Pobierz wszystkich użytkowników
$stmt = $pdo->query("
    SELECT u.*, 
           COUNT(DISTINCT p.id) as project_count,
           COUNT(DISTINCT t.id) as task_count
    FROM users u
    LEFT JOIN projects p ON u.id = p.user_id
    LEFT JOIN tasks t ON p.id = t.project_id
    GROUP BY u.id
    ORDER BY u.created_at DESC
");
$users = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zarządzanie użytkownikami - Generator treści SEO</title>
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
                    <h1 class="h2">Zarządzanie użytkownikami</h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal">
                        <i class="fas fa-plus"></i> Nowy użytkownik
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
                                        <th>Email</th>
                                        <th>Rola</th>
                                        <th>Projekty</th>
                                        <th>Zadania</th>
                                        <th>Data rejestracji</th>
                                        <th>Akcje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($user['email']) ?></td>
                                            <td>
                                                <span class="badge <?= $user['role'] === 'admin' ? 'bg-danger' : 'bg-primary' ?>">
                                                    <?= $user['role'] === 'admin' ? 'Administrator' : 'Użytkownik' ?>
                                                </span>
                                            </td>
                                            <td><?= $user['project_count'] ?></td>
                                            <td><?= $user['task_count'] ?></td>
                                            <td><?= date('d.m.Y H:i', strtotime($user['created_at'])) ?></td>
                                            <td>
                                                <div class="btn-group">
                                                    <button class="btn btn-sm btn-outline-secondary" 
                                                            onclick="editUser(<?= htmlspecialchars(json_encode($user)) ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                        <a href="?delete=<?= $user['id'] ?>" class="btn btn-sm btn-outline-danger" 
                                                           onclick="return confirm('Czy na pewno chcesz usunąć tego użytkownika?')">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
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
    
    <!-- Modal dodawania/edycji użytkownika -->
    <div class="modal fade" id="userModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="userModalTitle">Nowy użytkownik</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="user_id" id="user_id" value="">
                        <div class="mb-3">
                            <label for="email" class="form-label">Email *</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Hasło *</label>
                            <input type="password" class="form-control" id="password" name="password">
                            <div class="form-text" id="passwordHelp">Pozostaw puste, aby nie zmieniać hasła (tylko przy edycji)</div>
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">Rola *</label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="user">Użytkownik</option>
                                <option value="admin">Administrator</option>
                            </select>
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
        function editUser(user) {
            document.getElementById('userModalTitle').textContent = 'Edytuj użytkownika';
            document.getElementById('user_id').value = user.id;
            document.getElementById('email').value = user.email;
            document.getElementById('password').value = '';
            document.getElementById('role').value = user.role;
            document.getElementById('passwordHelp').style.display = 'block';
            document.getElementById('password').removeAttribute('required');
            
            var modal = new bootstrap.Modal(document.getElementById('userModal'));
            modal.show();
        }
        
        // Reset modal when closed
        document.getElementById('userModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('userModalTitle').textContent = 'Nowy użytkownik';
            document.getElementById('user_id').value = '';
            document.getElementById('email').value = '';
            document.getElementById('password').value = '';
            document.getElementById('role').value = 'user';
            document.getElementById('passwordHelp').style.display = 'none';
            document.getElementById('password').setAttribute('required', 'required');
        });
    </script>
</body>
</html>