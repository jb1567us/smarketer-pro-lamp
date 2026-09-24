<?php
/**
 * Manual Task Trigger API (Phase 0 rewrite).
 *
 * Queues agent-pipeline tasks (Enrich → Qualify → Draft) for one lead or many.
 *
 * POST JSON:
 *   { "lead_id": 12, "task_type": "Enrich" }            single, processed NOW
 *   { "lead_id": 12, "task_type": "Qualify", "defer": true }  queued for cron
 *   { "lead_ids": [12, 13], "task_type": "Pipeline" }   bulk Enrich→Qualify→Draft,
 *                                                       always deferred, staggered
 *
 * task_type accepts 'Enrich' | 'Qualify' | 'Draft' | 'Pipeline' (bulk only).
 * Legacy aliases 'Enrichment' → 'Enrich', 'Qualification' → 'Qualify',
 * 'Drafting' → 'Draft' are mapped (the old default 'Enrichment' previously
 * inserted a task the processor could not handle → instant Failed).
 *
 * All routes require auth (\App\Auth::requireApiAuth). Errors are logged,
 * never echoed with display_errors in production.
 */
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
\App\Auth::requireApiAuth();
$pdo = \App\Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

// Queueable single-step types the TaskProcessor actually handles.
const TRIGGER_TASK_TYPES = ['Enrich', 'Qualify', 'Draft'];

// Legacy aliases that used to be inserted verbatim and then failed in the
// processor ("Unknown or unsupported task type").
const TRIGGER_TASK_ALIASES = [
    'Enrichment' => 'Enrich',
    'Qualification' => 'Qualify',
    'Drafting' => 'Draft',
];

function trigger_task_error(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

/** Normalize a requested type to a processor-handled type, or null. */
function normalize_task_type(string $type): ?string {
    $type = trim($type);
    if (in_array($type, TRIGGER_TASK_TYPES, true)) return $type;
    return TRIGGER_TASK_ALIASES[$type] ?? null;
}

/** Queue one task row; returns the new task id. */
function queue_task(\App\PDO $pdo, int $leadId, string $type, int $delayMinutes = 0): int {
    if ($delayMinutes > 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO task_queue (lead_id, task_type, status, scheduled_at) " .
            "VALUES (?, ?, 'Pending', DATE_ADD(NOW(), INTERVAL ? MINUTE))"
        );
        $stmt->execute([$leadId, $type, $delayMinutes]);
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO task_queue (lead_id, task_type, status) VALUES (?, ?, 'Pending')"
        );
        $stmt->execute([$leadId, $type]);
    }
    return (int)$pdo->lastInsertId();
}

/** True when every requested lead id exists. */
function leads_exist(\App\PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id FROM leads WHERE id IN ({$placeholders})");
    $stmt->execute($ids);
    return array_map('intval', $stmt->fetchAll(\App\PDO::FETCH_COLUMN));
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) trigger_task_error(400, 'Invalid JSON body');

    $rawType = (string)($input['task_type'] ?? '');
    $isPipeline = (strcasecmp(trim($rawType), 'Pipeline') === 0);

    // ── Bulk path: lead_ids[] + (Enrich|Qualify|Draft|Pipeline), always deferred ──
    if (isset($input['lead_ids'])) {
        if (!is_array($input['lead_ids'])) trigger_task_error(400, 'lead_ids must be an array');
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $input['lead_ids']),
            fn($v) => $v > 0
        )));
        if (!$ids) trigger_task_error(400, 'No valid lead ids');
        if (count($ids) > 100) trigger_task_error(400, 'Bulk limit is 100 leads per request');

        $steps = $isPipeline
            ? TRIGGER_TASK_TYPES
            : ((($t = normalize_task_type($rawType)) !== null) ? [$t] : null);
        if ($steps === null) trigger_task_error(400, 'Unknown task_type: ' . substr($rawType, 0, 32));

        $existing = leads_exist($pdo, $ids);
        $missing = array_diff($ids, $existing);
        if ($missing) trigger_task_error(404, 'Lead(s) not found: ' . implode(',', array_slice($missing, 0, 10)));

        $queued = 0;
        $taskIds = [];
        foreach ($ids as $leadId) {
            $delay = 0;
            foreach ($steps as $step) {
                // Stagger steps so Enrich → Qualify → Draft order holds even
                // across cron ticks (worker consumes ORDER BY scheduled_at ASC).
                $taskIds[] = queue_task($pdo, $leadId, $step, $delay);
                $queued++;
                $delay += 3;
            }
        }
        echo json_encode([
            'success' => true,
            'queued' => $queued,
            'task_ids' => $taskIds,
            'steps' => $steps,
            'message' => "Queued {$queued} task(s) (" . implode(' → ', $steps) . ") for " . count($ids) . " lead(s). The cron worker will process them in order.",
        ]);
        exit;
    }

    // ── Single-lead path ──
    $leadId = (int)($input['lead_id'] ?? 0);
    if ($leadId <= 0) trigger_task_error(400, 'Missing lead_id');

    $type = normalize_task_type($rawType === '' ? 'Enrich' : $rawType);
    if ($type === null) trigger_task_error(400, 'Unknown task_type: ' . substr($rawType, 0, 32));
    if ($isPipeline) trigger_task_error(400, 'Pipeline requires lead_ids (bulk path)');

    if (!leads_exist($pdo, [$leadId])) trigger_task_error(404, "Lead ID {$leadId} not found");

    $taskId = queue_task($pdo, $leadId, $type);
    $defer = !empty($input['defer']);

    if ($defer) {
        echo json_encode([
            'success' => true,
            'task_id' => $taskId,
            'deferred' => true,
            'message' => "Task {$type} queued for lead {$leadId}; the cron worker will pick it up.",
        ]);
        exit;
    }

    // Immediate (manual-test) processing, as before.
    $processor = new \App\Domain\TaskProcessor($pdo, new \App\Routers\SmartLLMRouter($pdo));
    $processor->processTask($taskId);

    echo json_encode(['success' => true, 'task_id' => $taskId, 'message' => "Task {$type} processed successfully"]);
} catch (\App\Exceptions\OutreachException $e) {
    error_log('[trigger_task] ' . $e->getMessage());
    trigger_task_error(500, $e->getMessage());
} catch (Exception $e) {
    error_log('[trigger_task] ' . $e->getMessage());
    trigger_task_error(500, 'Task failed (see server error log)');
}
