<?php
/**
 * Cron Worker — Pseudo-Asynchronous Job Processor
 * 
 * Designed to be triggered by cPanel cron every 1-5 minutes:
 *   php /home/user/public_html/b2b_outreach_lamp/cron_worker.php
 * 
 * Picks up pending jobs, processes them within PHP's max_execution_time,
 * and stores results back into the database.
 */

declare(strict_types=1);

// Prevent web access
if (php_sapi_name() !== 'cli' && !defined('CRON_OVERRIDE')) {
    http_response_code(403);
    die('CLI only.');
}

require_once __DIR__ . '/includes/autoload.php';
require_once __DIR__ . '/includes/SimpleHarvester.php';

use App\Database;

// Configuration
$BATCH_SIZE = 5;            // Jobs to process per cron run
$LOCK_FILE = __DIR__ . '/tmp/cron.lock';

// Prevent overlapping runs
if (!is_dir(__DIR__ . '/tmp')) {
    mkdir(__DIR__ . '/tmp', 0755, true);
}

if (file_exists($LOCK_FILE)) {
    $lockAge = time() - filemtime($LOCK_FILE);
    if ($lockAge < 300) { // Lock is less than 5 minutes old
        logLine("Another worker is running (lock age: {$lockAge}s). Exiting.");
        exit(0);
    }
    // Stale lock — remove it
    unlink($LOCK_FILE);
}

file_put_contents($LOCK_FILE, getmypid());

register_shutdown_function(function() use ($LOCK_FILE) {
    if (file_exists($LOCK_FILE)) {
        unlink($LOCK_FILE);
    }
});

try {
    $pdo = Database::getConnection();
} catch (\Exception $e) {
    logLine("FATAL: Cannot connect to database: " . $e->getMessage());
    exit(1);
}

// Claim a batch of pending jobs atomically
$stmt = $pdo->prepare("
    UPDATE jobs 
    SET status = 'processing', started_at = NOW(), attempts = attempts + 1 
    WHERE status = 'pending' AND attempts < max_attempts
    ORDER BY priority DESC, created_at ASC 
    LIMIT ?
");
$stmt->execute([$BATCH_SIZE]);

// Fetch the jobs we just claimed
$stmt = $pdo->prepare("
    SELECT * FROM jobs 
    WHERE status = 'processing' AND started_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ORDER BY priority DESC, created_at ASC
");
$stmt->execute();
$jobs = $stmt->fetchAll();

if (empty($jobs)) {
    logLine("No pending jobs. Exiting.");
    exit(0);
}

logLine("Processing " . count($jobs) . " jobs...");

foreach ($jobs as $job) {
    $jobId = (int) $job['id'];
    $type = $job['type'];
    $payload = json_decode($job['payload'], true);

    logLine("Job #{$jobId} ({$type}): Starting...");
    addJobLog($pdo, $jobId, 'info', "Processing started.");

    try {
        $result = null;

        switch ($type) {
            case 'harvest':
                $result = processHarvestJob($payload);
                break;

            case 'extract':
                $result = processExtractJob($payload);
                break;

            default:
                throw new \Exception("Unknown job type: {$type}");
        }

        // Mark as completed
        $stmt = $pdo->prepare("UPDATE jobs SET status = 'completed', result = ?, completed_at = NOW() WHERE id = ?");
        $stmt->execute([json_encode($result), $jobId]);
        addJobLog($pdo, $jobId, 'success', "Completed. Results: " . count($result) . " items.");
        logLine("Job #{$jobId}: Completed with " . count($result) . " results.");

    } catch (\Exception $e) {
        $errorMsg = $e->getMessage();

        // Check if we should retry or fail permanently
        if ((int)$job['attempts'] >= (int)$job['max_attempts']) {
            $stmt = $pdo->prepare("UPDATE jobs SET status = 'failed', error_message = ?, completed_at = NOW() WHERE id = ?");
            $stmt->execute([$errorMsg, $jobId]);
            addJobLog($pdo, $jobId, 'error', "Permanently failed after {$job['max_attempts']} attempts: {$errorMsg}");
        } else {
            // Return to pending for retry
            $stmt = $pdo->prepare("UPDATE jobs SET status = 'pending', error_message = ? WHERE id = ?");
            $stmt->execute([$errorMsg, $jobId]);
            addJobLog($pdo, $jobId, 'warn', "Attempt {$job['attempts']} failed, will retry: {$errorMsg}");
        }
        logLine("Job #{$jobId}: ERROR — {$errorMsg}");
    }
}

logLine("Batch complete.");
exit(0);

// =============================================================================
// Job Handlers
// =============================================================================

function processHarvestJob(array $payload): array
{
    $query = $payload['query'] ?? '';
    $provider = $payload['provider'] ?? null;
    $limit = (int)($payload['limit'] ?? 50);
    $campaignId = !empty($payload['campaign_id']) ? (int)$payload['campaign_id'] : null;
    $leadPersona = !empty($payload['lead_persona']) ? trim($payload['lead_persona']) : '';

    if (empty($query)) {
        throw new \Exception("Empty query in harvest job.");
    }

    $pdo = Database::getConnection();
    // Override active provider if specified in payload
    if ($provider) {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_search_provider', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$provider, $provider]);
    }

    $harvester = new \SimpleHarvester();
    $results = $harvester->harvest($query, $limit);

    // Item 4: score with the real TrustScorer before staging + returning to
    // the card renderer (sync/async parity — see api/mass_tools.php).
    $results = \SimpleHarvester::scoreHarvestResults(
        $results,
        null,
        \SimpleHarvester::isHarvestDnsEnabled($pdo)
    );

    // Stage results
    \SimpleHarvester::stageResults($pdo, $results, $query, $campaignId, $leadPersona);

    return $results;
}

function processExtractJob(array $payload): array
{
    $url = $payload['url'] ?? '';
    if (empty($url)) {
        throw new \Exception("Empty URL in extract job.");
    }

    $res = \App\ExtractionEngine::fullExtract($url);
    if (isset($res['error'])) {
        throw new \Exception($res['error']);
    }
    if (isset($res['blocked'])) {
        throw new \Exception($res['reason']);
    }

    return $res;
}

// =============================================================================
// Helpers
// =============================================================================

function addJobLog($pdo, int $jobId, string $level, string $message): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO job_logs (job_id, level, message) VALUES (?, ?, ?)");
        $stmt->execute([$jobId, $level, $message]);
    } catch (\Exception $e) {
        // Don't let logging failures crash the worker
        error_log("[CronWorker] Failed to log for job #{$jobId}: " . $e->getMessage());
    }
}

function logLine(string $msg): void
{
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] [CronWorker] {$msg}";
    echo $line . PHP_EOL;
    error_log($line);
}
