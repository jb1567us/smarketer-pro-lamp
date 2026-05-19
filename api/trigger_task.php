<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Manual Task Trigger API
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
$pdo = \App\Database::getConnection();


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

$input = json_decode(file_get_contents('php://input'), true);
$lead_id = isset($input['lead_id']) ? (int)$input['lead_id'] : 0;
$type = isset($input['task_type']) ? $input['task_type'] : 'Enrichment';

try {
    // 1. Create a task in the queue
    $stmt = $pdo->prepare("INSERT INTO task_queue (lead_id, task_type, status) VALUES (?, ?, 'Pending')");
    $stmt->execute([$lead_id, $type]);
    $taskId = $pdo->lastInsertId();

    // 2. Immediately trigger the runner for this task (Manual Test)
    $processor = new \App\Domain\TaskProcessor($pdo, new \App\Routers\SmartLLMRouter($pdo));
    $processor->processTask($taskId);

    echo json_encode(['success' => true, 'task_id' => $taskId, 'message' => 'Task processed successfully']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
