<?php
require_once __DIR__ . '/includes/autoload.php';
$pdo = \App\Database::getConnection();


try {
    // 1. Seed Lead
    $uniqueEmail = 'gm' . time() . '@example.com';
    $stmt = $pdo->prepare("INSERT INTO leads (company_name, email, status) VALUES ('Golden Master Corp', ?, 'New')");
    $stmt->execute([$uniqueEmail]);
    $leadId = $pdo->lastInsertId();

    // 2. Seed Task
    $stmt = $pdo->prepare("INSERT INTO task_queue (lead_id, task_type, status) VALUES (?, 'Qualify', 'Pending')");
    $stmt->execute([$leadId]);

    echo "Seeded lead $leadId and task.\n";

    // 3. Run Runner
    $processor = new \App\Domain\TaskProcessor($pdo, new \App\Routers\SmartLLMRouter($pdo));
    $processor->processTask($pdo->lastInsertId());
    echo "Processed task.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
