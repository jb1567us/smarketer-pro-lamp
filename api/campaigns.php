<?php
/**
 * Campaigns & Templates API
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
$pdo = \App\Database::getConnection();

$type = $_GET['type'] ?? 'campaigns';
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($type === 'campaigns') {
        if ($method === 'GET') {
            $stmt = $pdo->query("SELECT * FROM campaigns ORDER BY created_at DESC");
            $data = $stmt->fetchAll();
            echo json_encode([
                'success' => true, 
                'data' => $data,
                'meta' => ['count' => count($data)]
            ]);
        } elseif ($method === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("INSERT INTO campaigns (name, description) VALUES (?, ?)");
            $stmt->execute([$data['name'], $data['description']]);
            echo json_encode([
                'success' => true, 
                'data' => ['id' => $pdo->lastInsertId()],
                'meta' => ['timestamp' => date('c')]
            ]);
        }
    } elseif ($type === 'templates') {
        if ($method === 'GET') {
            $campaign_id = $_GET['campaign_id'] ?? 0;
            $stmt = $pdo->prepare("SELECT * FROM templates WHERE campaign_id = ? ORDER BY step_order ASC");
            $stmt->execute([$campaign_id]);
            $data = $stmt->fetchAll();
            echo json_encode([
                'success' => true, 
                'data' => $data,
                'meta' => ['count' => count($data)]
            ]);
        } elseif ($method === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("INSERT INTO templates (campaign_id, subject, body, step_order) VALUES (?, ?, ?, ?)");
            $stmt->execute([$data['campaign_id'], $data['subject'], $data['body'], $data['step_order']]);
            echo json_encode([
                'success' => true, 
                'data' => ['id' => $pdo->lastInsertId()],
                'meta' => ['timestamp' => date('c')]
            ]);
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
