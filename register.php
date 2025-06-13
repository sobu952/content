<?php
session_start();

// Sprawdź czy aplikacja jest zainstalowana
if (!file_exists('config.php')) {
    header('Location: install.php');
    exit;
}

require_once 'config.php';

// Jeśli użytkownik jest już zalogowany, przekieruj do dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_POST) {
    $email = $_POST['email'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if ($password !== $confirm_password) {
        $error = 'Hasła nie są identyczne.';
    } elseif (strlen($password) < 6) {
        $error = 'Hasło musi mieć co najmniej 6 znaków.';
    } else {
        try {
            $pdo = getDbConnection();
            
            // Sprawdź czy email już istnieje
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->fetch()) {
                $error = 'Użytkownik z tym adresem email już istnieje.';
            } else {
                // Utwórz nowego użytkownika
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, 'user')");
                $stmt->execute([$email, $hashed_password]);
                
                $success = 'Konto zostało utworzone pomyślnie. Możesz się teraz zalogować.';
            }
        } catch(Exception $e) {
            $error = 'Błąd rejestracji: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejestracja - Generator treści SEO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card mt-5">
                    <div class="card-header">
                        <h3 class="mb-0">Rejestracja</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                            <a href="login.php" class="btn btn-primary">Przejdź do logowania</a>
                        <?php else: ?>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Hasło</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                                <div class="form-text">Hasło musi mieć co najmniej 6 znaków.</div>
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Potwierdź hasło</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Zarejestruj się</button>
                        </form>
                        
                        <?php endif; ?>
                        
                        <hr>
                        <div class="text-center">
                            <a href="login.php">Masz już konto? Zaloguj się</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>