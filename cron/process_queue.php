<?php
/**
 * Cron Job: Background Task Processor
 * Usage: php cron/process_queue.php
 */
require_once __DIR__ . '/../includes/autoload.php';
$pdo = \App\Database::getConnection();

echo "[LOG] Starting queue processing: " . date('Y-m-d H:i:s') . "\n";

try {
    $processor = new \App\Domain\TaskProcessor($pdo, new \App\Routers\SmartLLMRouter($pdo));
    
    // Fetch pending tasks
    $stmt = $pdo->query("SELECT id FROM task_queue WHERE status = 'Pending' AND scheduled_at <= NOW() ORDER BY scheduled_at ASC LIMIT 10");
    $tasks = $stmt->fetchAll();

    if (empty($tasks)) {
        echo "[LOG] No pending tasks found.\n";
    }

    foreach ($tasks as $row) {
        echo "[LOG] Processing Task ID: {$row['id']}...\n";
        $processor->processTask($row['id']);
    }

    echo "[LOG] Finished processing " . count($tasks) . " tasks.\n";
} catch (Exception $e) {
    echo "[ERROR] Cron failed: " . $e->getMessage() . "\n";
}
?>
