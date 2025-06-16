<?php
require_once 'auth_check.php';
requireAdmin();

$pdo = getDbConnection();

$success = '';
$error = '';

// Obsługa działań
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_provider':
            try {
                $stmt = $pdo->prepare("INSERT INTO ai_providers (name, api_type, api_base_url, description) VALUES (?, ?, ?, ?)");
                $stmt->execute([$_POST['name'], $_POST['api_type'], $_POST['api_base_url'], $_POST['description']]);
                $success = 'Provider został dodany.';
            } catch(Exception $e) {
                $error = 'Błąd dodawania providera: ' . $e->getMessage();
            }
            break;
            
        case 'add_model':
            try {
                $stmt = $pdo->prepare("INSERT INTO ai_models (provider_id, name, model_key, description, max_tokens) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$_POST['provider_id'], $_POST['name'], $_POST['model_key'], $_POST['description'], $_POST['max_tokens']]);
                $success = 'Model został dodany.';
            } catch(Exception $e) {
                $error = 'Błąd dodawania modelu: ' . $e->getMessage();
            }
            break;
            
        case 'add_api_key':
            try {
                $stmt = $pdo->prepare("INSERT INTO ai_api_keys (provider_id, api_key, description) VALUES (?, ?, ?)");
                $stmt->execute([$_POST['provider_id'], $_POST['api_key'], $_POST['description']]);
                $success = 'Klucz API został dodany.';
            } catch(Exception $e) {
                $error = 'Błąd dodawania klucza API: ' . $e->getMessage();
            }
            break;
            
        case 'toggle_provider':
            $provider_id = intval($_POST['provider_id']);
            try {
                $stmt = $pdo->prepare("UPDATE ai_providers SET is_active = NOT is_active WHERE id = ?");
                $stmt->execute([$provider_id]);
                $success = 'Status providera został zmieniony.';
            } catch(Exception $e) {
                $error = 'Błąd zmiany statusu: ' . $e->getMessage();
            }
            break;
            
        case 'toggle_model':
            $model_id = intval($_POST['model_id']);
            try {
                $stmt = $pdo->prepare("UPDATE ai_models SET is_active = NOT is_active WHERE id = ?");
                $stmt->execute([$model_id]);
                $success = 'Status modelu został zmieniony.';
            } catch(Exception $e) {
                $error = 'Błąd zmiany statusu: ' . $e->getMessage();
            }
            break;
            
        case 'toggle_api_key':
            $key_id = intval($_POST['api_key_id']);
            try {
                $stmt = $pdo->prepare("UPDATE ai_api_keys SET is_active = NOT is_active WHERE id = ?");
                $stmt->execute([$key_id]);
                $success = 'Status klucza API został zmieniony.';
            } catch(Exception $e) {
                $error = 'Błąd zmiany statusu: ' . $e->getMessage();
            }
            break;
    }
}

// Pobierz providerów
$stmt = $pdo->query("SELECT * FROM ai_providers ORDER BY name");
$providers = $stmt->fetchAll();

// Pobierz modele
$stmt = $pdo->query("
    SELECT am.*, ap.name as provider_name 
    FROM ai_models am 
    JOIN ai_providers ap ON am.provider_id = ap.id 
    ORDER BY ap.name, am.name
");
$models = $stmt->fetchAll();

// Pobierz klucze API
$stmt = $pdo->query("
    SELECT ak.*, ap.name as provider_name 
    FROM ai_api_keys ak 
    JOIN ai_providers ap ON ak.provider_id = ap.id 
    ORDER BY ap.name, ak.created_at DESC
");
$api_keys = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zarządzanie AI - Generator treści SEO</title>
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
                    <h1 class="h2">Zarządzanie AI</h1>
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
                
                <!-- Karty nawigacyjne -->
                <ul class="nav nav-tabs" id="aiTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="providers-tab" data-bs-toggle="tab" data-bs-target="#providers" type="button">
                            <i class="fas fa-server"></i> Providerzy AI
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="models-tab" data-bs-toggle="tab" data-bs-target="#models" type="button">
                            <i class="fas fa-brain"></i> Modele
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="api-keys-tab" data-bs-toggle="tab" data-bs-target="#api-keys" type="button">
                            <i class="fas fa-key"></i> Klucze API
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" id="aiTabsContent">
                    <!-- Providerzy AI -->
                    <div class="tab-pane fade show active" id="providers" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center my-3">
                            <h3>Providerzy AI</h3>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProviderModal">
                                <i class="fas fa-plus"></i> Dodaj providera
                            </button>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Nazwa</th>
                                        <th>Typ API</th>
                                        <th>URL API</th>
                                        <th>Status</th>
                                        <th>Akcje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($providers as $provider): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($provider['name']) ?></td>
                                            <td>
                                                <span class="badge bg-info"><?= ucfirst($provider['api_type']) ?></span>
                                            </td>
                                            <td><?= htmlspecialchars($provider['api_base_url']) ?></td>
                                            <td>
                                                <span class="badge <?= $provider['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                                    <?= $provider['is_active'] ? 'Aktywny' : 'Nieaktywny' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="toggle_provider">
                                                    <input type="hidden" name="provider_id" value="<?= $provider['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                        <i class="fas fa-toggle-<?= $provider['is_active'] ? 'on' : 'off' ?>"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Modele -->
                    <div class="tab-pane fade" id="models" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center my-3">
                            <h3>Modele AI</h3>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModelModal">
                                <i class="fas fa-plus"></i> Dodaj model
                            </button>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Provider</th>
                                        <th>Nazwa</th>
                                        <th>Klucz modelu</th>
                                        <th>Max tokenów</th>
                                        <th>Status</th>
                                        <th>Akcje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($models as $model): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($model['provider_name']) ?></td>
                                            <td><?= htmlspecialchars($model['name']) ?></td>
                                            <td><code><?= htmlspecialchars($model['model_key']) ?></code></td>
                                            <td><?= number_format($model['max_tokens']) ?></td>
                                            <td>
                                                <span class="badge <?= $model['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                                    <?= $model['is_active'] ? 'Aktywny' : 'Nieaktywny' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="toggle_model">
                                                    <input type="hidden" name="model_id" value="<?= $model['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                        <i class="fas fa-toggle-<?= $model['is_active'] ? 'on' : 'off' ?>"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Klucze API -->
                    <div class="tab-pane fade" id="api-keys" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center my-3">
                            <h3>Klucze API</h3>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addApiKeyModal">
                                <i class="fas fa-plus"></i> Dodaj klucz API
                            </button>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Provider</th>
                                        <th>Klucz API</th>
                                        <th>Opis</th>
                                        <th>Status</th>
                                        <th>Dodano</th>
                                        <th>Akcje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($api_keys as $key): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($key['provider_name']) ?></td>
                                            <td>
                                                <code><?= htmlspecialchars(substr($key['api_key'], 0, 10)) ?>...</code>
                                            </td>
                                            <td><?= htmlspecialchars($key['description']) ?></td>
                                            <td>
                                                <span class="badge <?= $key['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                                    <?= $key['is_active'] ? 'Aktywny' : 'Nieaktywny' ?>
                                                </span>
                                            </td>
                                            <td><?= date('d.m.Y', strtotime($key['created_at'])) ?></td>
                                            <td>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="toggle_api_key">
                                                    <input type="hidden" name="api_key_id" value="<?= $key['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                        <i class="fas fa-toggle-<?= $key['is_active'] ? 'on' : 'off' ?>"></i>
                                                    </button>
                                                </form>
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
    
    <!-- Modal dodawania providera -->
    <div class="modal fade" id="addProviderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_provider">
                    <div class="modal-header">
                        <h5 class="modal-title">Dodaj providera AI</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nazwa *</label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Typ API *</label>
                            <select class="form-select" name="api_type" required>
                                <option value="gemini">Gemini</option>
                                <option value="openai">OpenAI</option>
                                <option value="anthropic">Anthropic</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">URL API</label>
                            <input type="url" class="form-control" name="api_base_url">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Opis</label>
                            <textarea class="form-control" name="description" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                        <button type="submit" class="btn btn-primary">Dodaj</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal dodawania modelu -->
    <div class="modal fade" id="addModelModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_model">
                    <div class="modal-header">
                        <h5 class="modal-title">Dodaj model AI</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Provider *</label>
                            <select class="form-select" name="provider_id" required>
                                <option value="">Wybierz providera</option>
                                <?php foreach ($providers as $provider): ?>
                                    <option value="<?= $provider['id'] ?>"><?= htmlspecialchars($provider['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nazwa *</label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Klucz modelu *</label>
                            <input type="text" class="form-control" name="model_key" required>
                            <div class="form-text">Np. gpt-4, claude-3-opus-20240229, gemini-1.5-pro</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Max tokenów</label>
                            <input type="number" class="form-control" name="max_tokens" value="4000">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Opis</label>
                            <textarea class="form-control" name="description" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                        <button type="submit" class="btn btn-primary">Dodaj</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal dodawania klucza API -->
    <div class="modal fade" id="addApiKeyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_api_key">
                    <div class="modal-header">
                        <h5 class="modal-title">Dodaj klucz API</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Provider *</label>
                            <select class="form-select" name="provider_id" required>
                                <option value="">Wybierz providera</option>
                                <?php foreach ($providers as $provider): ?>
                                    <option value="<?= $provider['id'] ?>"><?= htmlspecialchars($provider['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Klucz API *</label>
                            <input type="password" class="form-control" name="api_key" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Opis</label>
                            <input type="text" class="form-control" name="description" 
                                   placeholder="Np. Klucz główny, Klucz testowy">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                        <button type="submit" class="btn btn-primary">Dodaj</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>