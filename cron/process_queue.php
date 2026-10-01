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

// Record this run for the Diagnostics page (cron health check). Cheap
// settings upsert, inside the process lock; best-effort, never fatal.
\App\Database::setSetting('queue_last_run_at', date('Y-m-d H:i:s'));

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

    // --- Item 4: throttles + complaint/bounce monitor ---------------------
    // Runs once per tick, inside the process lock, before any task is
    // processed. Never fatal to the queue: any failure is logged and the
    // queue continues. With all throttle caps at their defaults (unlimited /
    // disabled) checkSend() short-circuits without counting, and the monitor
    // only pauses on sustained abusive patterns (>=100 delivered in 7 days
    // AND a threshold breach), so existing users see no behavior change.
    $throttles = new \App\Throttles(new \App\DbThrottleStore($pdo));
    try {
        $pauses = (new \App\SendMonitor())->run(new \App\DbMonitorStore($pdo));
        foreach ($pauses as $summary) {
            echo "[LOG] Compliance monitor: {$summary}\n";
        }
    } catch (\Throwable $e) {
        echo "[WARN] Compliance monitor failed (queue continues): " . $e->getMessage() . "\n";
    }
    // --- License soft-lock: daily re-validation (never fatal) -------------
    // Runs at most once per 24h (sooner for revoked keys, so un-revoking
    // restores sending promptly). Any failure is swallowed: the queue must
    // never die because of licensing.
    try {
        \App\Licensing::dailyCheck();
    } catch (\Throwable $e) {
        echo "[WARN] License check failed (queue continues): " . $e->getMessage() . "\n";
    }

    $activeProvider = (string)($throttles->getStore()->getSetting('active_email_provider', 'smtp') ?: 'smtp');

    // --- Item 5: ICP engagement-feedback weight auto-tuner ----------------
    // Per-buyer loop (this install's own engagement only; no cross-buyer
    // pooling), at most once per 24h, inside the process lock. Nudges
    // UNLOCKED ICP dimension weights toward the dimensions whose fit scores
    // correlate with real engagement: small steps (max +/-5 per dimension
    // per run), 10-engaged-lead sample floor, buyer_locked dimensions never
    // touched, every change recorded in icp_weight_history with reason +
    // sample_size. Scheduled maintenance, not real-time. Never fatal to the
    // queue: any failure is logged and the queue continues.
    try {
        $icpLastStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $icpLastStmt->execute([\App\Actions\AdjustIcpWeightsAction::SETTING_LAST_RUN]);
        $icpLastRow = $icpLastStmt->fetch(\App\PDO::FETCH_ASSOC);
        $icpLast = $icpLastRow !== false ? (int)$icpLastRow['setting_value'] : 0;
        if (time() - $icpLast >= \App\Actions\AdjustIcpWeightsAction::RUN_INTERVAL_SECONDS) {
            $icpResult = (new \App\Actions\AdjustIcpWeightsAction($pdo))->run();
            $icpNow = (string)time();
            $pdo->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) " .
                "ON DUPLICATE KEY UPDATE setting_value = ?"
            )->execute([\App\Actions\AdjustIcpWeightsAction::SETTING_LAST_RUN, $icpNow, $icpNow]);
            echo "[LOG] ICP weight auto-tuner: " . ($icpResult['status'] ?? 'unknown') .
                " -- " . ($icpResult['detail'] ?? '') . "\n";
        }
    } catch (\Throwable $e) {
        echo "[WARN] ICP weight auto-tuner failed (queue continues): " . $e->getMessage() . "\n";
    }

    // Fetch pending tasks (task_type + payload are needed for the throttle gate)
    $stmt = $pdo->query("SELECT id, task_type, payload, lead_id FROM task_queue WHERE status = 'Pending' AND scheduled_at <= NOW() ORDER BY scheduled_at ASC LIMIT 10");
    $tasks = $stmt->fetchAll();

    // FIX 4 -- paused-campaign gate. One batched lookup per tick: send tasks
    // whose owning campaign is paused (is_active=0, auto or manual) are HELD
    // -- left Pending, never claimed, no attempt burned, no blocked-send
    // count -- so they resume automatically on unpause. Other campaigns'
    // tasks in the same tick are unaffected.
    $heldTaskIds = \App\CampaignPauseGate::holdTaskIds($pdo, $tasks);

    if (empty($tasks)) {
        echo "[LOG] No pending tasks found.\n";
    }

    foreach ($tasks as $row) {
        $taskId = (int)$row['id'];
        $taskType = (string)($row['task_type'] ?? '');

        // FIX 4: paused campaign -- hold the task. It is NOT claimed,
        // NOT failed, burns no attempt, and is NOT counted as a blocked
        // send. It stays Pending and resumes on the next tick after the
        // campaign is reactivated.
        if (isset($heldTaskIds[$taskId])) {
            echo "[LOG] Task {$taskId} held: campaign is paused (queued sends resume on unpause).\n";
            continue;
        }

        // Throttle gate: before a send task is processed, check the three
        // caps (campaign daily / provider daily / global per-minute). When a
        // cap is hit the task is DEFERRED -- kept Pending with scheduled_at
        // pushed out -- never dropped. The next tick retries it automatically.
        if (in_array($taskType, ['EmailOutreach', 'SocialOutreach', 'SequenceSend'], true)) {
            $payload = json_decode((string)($row['payload'] ?? ''), true);
            $payloadCampaign = isset($payload['campaign_id']) ? (int)$payload['campaign_id'] : 0;
            $decision = $throttles->checkSend(
                $payloadCampaign > 0 ? $payloadCampaign : null,
                $activeProvider === 'smart_rotation' ? null : $activeProvider
            );
            if (!$decision->allowed) {
                $defer = $pdo->prepare(
                    "UPDATE task_queue SET status = 'Pending', scheduled_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), " .
                    "error_message = ? WHERE id = ?"
                );
                $defer->execute([$decision->deferMinutes, 'Throttled (' . $decision->code . '): ' . $decision->detail, $taskId]);
                // ITEM A: the send was refused by a throttle cap for now -- the
                // task is deferred (retried later), and the refusal is counted
                // so the campaign view can explain the holdup.
                \App\BlockedCount::record(
                    $payloadCampaign > 0 ? $payloadCampaign : null,
                    \App\BlockedCount::REASON_THROTTLE
                );
                echo "[LOG] Task {$taskId} deferred {$decision->deferMinutes}m: {$decision->detail}\n";
                continue;
            }
        }

        echo "[LOG] Processing Task ID: {$taskId}...\n";
        $processor->processTask($taskId);
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
