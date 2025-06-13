<?php
require_once 'auth_check.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid ID']);
    exit;
}

$pdo = getDbConnection();

try {
    $stmt = $pdo->prepare("SELECT fields FROM content_types WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $content_type = $stmt->fetch();
    
    if (!$content_type) {
        http_response_code(404);
        echo json_encode(['error' => 'Content type not found']);
        exit;
    }
    
    echo json_encode([
        'fields' => json_decode($content_type['fields'], true)
    ]);
    
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>