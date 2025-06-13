<?php
require_once 'auth_check.php';

header('Content-Type: application/json');

if (!isset($_GET['task_item_id']) || !is_numeric($_GET['task_item_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid task item ID']);
    exit;
}

$task_item_id = intval($_GET['task_item_id']);
$pdo = getDbConnection();

try {
    // Sprawdź czy element zadania należy do użytkownika
    $stmt = $pdo->prepare("
        SELECT gc.generated_text, gc.verified_text
        FROM generated_content gc
        JOIN task_items ti ON gc.task_item_id = ti.id
        JOIN tasks t ON ti.task_id = t.id
        JOIN projects p ON t.project_id = p.id
        WHERE ti.id = ? AND p.user_id = ?
    ");
    $stmt->execute([$task_item_id, $_SESSION['user_id']]);
    $content = $stmt->fetch();
    
    if (!$content) {
        http_response_code(404);
        echo json_encode(['error' => 'Content not found']);
        exit;
    }
    
    echo json_encode([
        'generated_text' => $content['generated_text'],
        'verified_text' => $content['verified_text']
    ]);
    
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>