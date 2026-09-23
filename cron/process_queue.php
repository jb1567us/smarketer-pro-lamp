<?php
/**
 * Cron Job: Background Task Processor
 * Usage: php cron/process_queue.php
 *
 * CLI only - never expose this to the web.
 *
 * Concurrency model (cron fires every 5 min on typical shared hosting):
 *  1. A DB-backed process lock allows exactly one active run. A second
 *     run exits immediately instead of re-processing the same tasks.
 *  2. Task claiming is atomic (UPDATE ... WHERE status='Pending'), so even
 *     if two processes ever overlap, a task can only be claimed once.
 *  3. Crash recovery: tasks left 'In Progress' by a dead run are re-queued
 *     (up to 5 attempts), then marked Failed instead of rotting forever.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only.');
}
require_once __DIR__ . '/../includes/autoload.php';
$pdo = \App\Database::getConnection();

echo "[LOG] Starting queue processing: " . date('Y-m-d H:i:s') . "\n";

// --- Process lock table (idempotent; also in schema.sql for fresh installs) ---
$pdo->prepare(
    "CREATE TABLE IF NOT EXISTS cron_locks (" .
    "lock_name VARCHAR(100) PRIMARY KEY, " .
    "locked_at TIMESTAMP NULL, " .
    "pid INT DEFAULT 0" .
    ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
)->execute();
$pdo->prepare(
    "INSERT IGNORE INTO cron_locks (lock_name, locked_at, pid) VALUES ('process_queue', '2000-01-01 00:00:00', 0)"
)->execute();

// --- Atomic lock acquire: exactly one run at a time.
// A lock older than 30 minutes is stale (previous run crashed). With a
// 5-minute cron cadence, 6 consecutive missed releases means the holder
// is dead, so the next run takes over instead of stalling forever.
$lockStmt = $pdo->prepare(
    "UPDATE cron_locks SET locked_at = NOW(), pid = ? " .
    "WHERE lock_name = 'process_queue' AND locked_at < NOW() - INTERVAL 30 MINUTE"
);
$lockStmt->execute([getmypid()]);
if ($lockStmt->rowCount() === 0) {
    echo "[LOG] Another queue run is active (lock held). Exiting.\n";
    exit(0);
}

try {
    // --- Crash recovery: re-queue tasks orphaned by a dead run ---
    $requeued = $pdo->prepare(
        "UPDATE task_queue SET status = 'Pending' " .
        "WHERE status = 'In Progress' AND processed_at < NOW() - INTERVAL 30 MINUTE AND retry_count < 5"
    );
    $requeued->execute();
    if ($requeued->rowCount() > 0) {
        echo "[LOG] Re-queued " . $requeued->rowCount() . " orphaned task(s) from a crashed run.\n";
    }

    // Tasks that crashed their worker 5+ times are dead; fail them loudly
    // instead of retrying silently forever.
    $abandoned = $pdo->prepare(
        "UPDATE task_queue SET status = 'Failed', error_message = 'Abandoned: task crashed its worker 5 times' " .
        "WHERE status = 'In Progress' AND processed_at < NOW() - INTERVAL 30 MINUTE AND retry_count >= 5"
    );
    $abandoned->execute();
    if ($abandoned->rowCount() > 0) {
        echo "[LOG] Marked " . $abandoned->rowCount() . " repeatedly-crashing task(s) as Failed.\n";
    }

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
} finally {
    // Always release the lock so the next 5-minute tick can run.
    $pdo->prepare(
        "UPDATE cron_locks SET locked_at = '2000-01-01 00:00:00', pid = 0 WHERE lock_name = 'process_queue'"
    )->execute();
}
?>
