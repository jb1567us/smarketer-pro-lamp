<?php

declare(strict_types=1);

namespace App;

use App\Verification\EmailVerificationResult;

/**
 * ITEM2 — Bulk email verification, queue-backed.
 *
 * Problem: a 10k-row lead list cannot be verified inside one HTTP request
 * (PHP max_execution_time, MillionVerifier rate limits, shared-hosting
 * process killers). So "Verify leads" only ENQUEUES a job; the existing
 * cron queue worker (cron/process_queue.php → TaskProcessor) processes it
 * in small batches, one batch per tick, with the cursor persisted in the
 * task payload between ticks.
 *
 * Job model: one row in task_queue, task_type='BulkVerify', lead_id=NULL,
 * payload JSON:
 *   {
 *     "job": "bulk_verify",
 *     "mode": "selected" | "unchecked",
 *     "lead_ids": [..],            // 'selected' mode only
 *     "cursor": 0,                 // 'selected': index into lead_ids
 *     "last_id": 0,               // 'unchecked': keyset cursor (leads.id)
 *     "total": 1234,
 *     "counts": {"valid":0,"invalid":0,"risky":0,"unknown":0},
 *     "started_at": "...", "finished_at": null,
 *     "cancel_requested": false,
 *     "note": ""
 *   }
 *
 * Resume semantics: every lead's verdict is written to leads.verification_status
 * (+ verified_at) IMMEDIATELY, and the cursor is only advanced after the write.
 * If a run dies mid-batch, the task stays 'In Progress'; the queue's crash
 * recovery re-queues it and the next tick resumes from the persisted cursor —
 * already-verified leads are never re-checked and no credits are double-spent.
 * A completed batch parks the task back to 'Pending' (retry_count reset to 0,
 * because progress lives in the cursor, not in the retry counter — a long job
 * legitimately takes many batches and must not trip the queue's 5-crash
 * abandonment guard) with scheduled_at a few seconds out so the next tick
 * picks it up.
 *
 * Throttling: a configurable sleep between API calls
 * (verification_bulk_delay_ms, default 250) plus a consecutive-unknown
 * circuit breaker (verification_bulk_max_unknown_streak, default 15):
 * MillionVerifier returns non-2xx/429 as 'unknown' in this codebase's
 * adapter, so a streak of unknowns almost always means rate-limiting or a
 * provider outage — the batch parks gracefully instead of burning credits.
 *
 * Security: the API key is read from settings at run time, passed only to
 * the provider constructor, and NEVER written to the task payload, the
 * status endpoint output, or the logs (exception messages are scrubbed).
 */
final class BulkVerifyJob
{
    public const TASK_TYPE = 'BulkVerify';

    public const MODE_SELECTED = 'selected';
    public const MODE_UNCHECKED = 'unchecked';

    /** Sanity cap: 'selected' mode comes from UI checkboxes; reject abuse. */
    public const MAX_SELECTED_IDS = 20000;

    public const DEFAULT_BATCH_SIZE = 150;
    public const DEFAULT_DELAY_MS = 250;
    public const DEFAULT_MAX_UNKNOWN_STREAK = 15;

    /** Stop a batch early after this many seconds so one tick never hogs cron. */
    public const BATCH_TIME_BUDGET_S = 240;
    /** Re-check the cancel flag every N leads inside a batch. */
    public const CANCEL_CHECK_EVERY = 25;
    /** Park the task this many seconds out so the next tick picks it up. */
    public const RESCHEDULE_DELAY_S = 20;

    /** @var callable|null Test seam: fn(string $apiKey): EmailVerificationProvider */
    private static $providerFactory = null;

    /** @internal test-only */
    public static function setProviderFactory(?callable $factory): void
    {
        self::$providerFactory = $factory;
    }

    /** @var callable|null Test seam: fn(string $key, string $default): string */
    private static $settingsReader = null;

    /** @internal test-only */
    public static function setSettingsReader(?callable $reader): void
    {
        self::$settingsReader = $reader;
    }

    private static function setting(string $key, string $default): string
    {
        if (self::$settingsReader !== null) {
            return (string)call_user_func(self::$settingsReader, $key, $default);
        }
        return (string)(Database::getSetting($key, $default) ?? $default);
    }

    // ── Configuration gate ─────────────────────────────────────────────

    /**
     * @return array{0:bool,1:string} [configured, plain-language refusal message]
     */
    public static function checkConfigured(): array
    {
        $enabled = self::setting('verification_required', '0');
        if ($enabled !== '1') {
            return [false, self::refusalMessage('switched off')];
        }
        $key = trim(self::setting('verification_api_key', ''));
        if ($key === '') {
            return [false, self::refusalMessage('no API key')];
        }
        return [true, ''];
    }

    private static function refusalMessage(string $problem): string
    {
        $detail = $problem === 'no API key'
            ? 'It is switched on, but no MillionVerifier API key is saved.'
            : 'Email verification is switched off.';
        return $detail . ' Bulk verify needs it: go to Settings → Email Verification (MillionVerifier), '
            . 'paste your MillionVerifier API key, turn verification ON, and save. '
            . 'Each lookup uses one of your MillionVerifier credits, so nothing is spent until you start a job.';
    }

    // ── Enqueue (called from the API; never touches the network) ───────

    public static function countUnchecked($pdo): int
    {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM leads WHERE verification_status = 'unknown' " .
            "AND email IS NOT NULL AND TRIM(email) <> ''"
        );
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array{task_id:int,total:int,mode:string}
     * @throws \InvalidArgumentException on bad input / nothing to do / misconfiguration
     */
    public static function enqueue($pdo, string $mode, array $leadIds = []): array
    {
        [$ok, $refusal] = self::checkConfigured();
        if (!$ok) {
            throw new \InvalidArgumentException($refusal);
        }

        if (!in_array($mode, [self::MODE_SELECTED, self::MODE_UNCHECKED], true)) {
            throw new \InvalidArgumentException("Unknown verify mode '{$mode}'.");
        }

        // Never stack two bulk jobs: double runs = double credit spend.
        $active = $pdo->prepare(
            "SELECT id FROM task_queue WHERE task_type = ? AND status IN ('Pending','In Progress') LIMIT 1"
        );
        $active->execute([self::TASK_TYPE]);
        if ($row = $active->fetch(\App\PDO::FETCH_ASSOC)) {
            throw new \InvalidArgumentException(
                "A bulk-verify job is already running (task #{$row['id']}). " .
                "Let it finish or cancel it before starting another."
            );
        }

        if ($mode === self::MODE_SELECTED) {
            $ids = [];
            foreach ($leadIds as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
            $ids = array_keys($ids);
            if (empty($ids)) {
                throw new \InvalidArgumentException('Select at least one lead to verify.');
            }
            if (count($ids) > self::MAX_SELECTED_IDS) {
                throw new \InvalidArgumentException(
                    'Too many leads selected (max ' . self::MAX_SELECTED_IDS . '). ' .
                    'Use "verify all unchecked" for large lists.'
                );
            }
            // Only enqueue ids that actually exist.
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id FROM leads WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $ids = array_map('intval', $stmt->fetchAll(\App\PDO::FETCH_COLUMN));
            sort($ids);
            if (empty($ids)) {
                throw new \InvalidArgumentException('None of the selected leads still exist.');
            }
            $total = count($ids);
        } else {
            $ids = [];
            $total = self::countUnchecked($pdo);
            if ($total === 0) {
                throw new \InvalidArgumentException(
                    'Nothing to verify: every lead already has a verification verdict ' .
                    "(status is not 'unknown')."
                );
            }
        }

        $payload = [
            'job' => 'bulk_verify',
            'mode' => $mode,
            'lead_ids' => $ids,
            'cursor' => 0,
            'last_id' => 0,
            'total' => $total,
            'counts' => ['valid' => 0, 'invalid' => 0, 'risky' => 0, 'unknown' => 0],
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => null,
            'cancel_requested' => false,
            'note' => 'Queued. The next queue tick (usually within 5 minutes) starts verifying.',
        ];

        $stmt = $pdo->prepare(
            "INSERT INTO task_queue (lead_id, task_type, payload, status, scheduled_at) " .
            "VALUES (NULL, ?, ?, 'Pending', ?)"
        );
        $stmt->execute([self::TASK_TYPE, json_encode($payload), date('Y-m-d H:i:s')]);
        $taskId = (int)$pdo->lastInsertId();

        error_log("[BulkVerify] enqueued task #{$taskId} mode={$mode} total={$total}");
        return ['task_id' => $taskId, 'total' => $total, 'mode' => $mode];
    }

    // ── Worker (called by TaskProcessor after it claims the task) ──────

    /**
     * Process one batch. The claim (status 'In Progress', retry_count++)
     * is already done by TaskProcessor::processTask.
     */
    public static function run($pdo, int $taskId): void
    {
        $task = self::loadTask($pdo, $taskId);
        if ($task === null) {
            return;
        }
        $payload = self::payload($task);
        if (($payload['job'] ?? '') !== 'bulk_verify') {
            self::fail($pdo, $taskId, $payload, 'Task payload is not a bulk-verify job.');
            return;
        }

        [$ok, $refusal] = self::checkConfigured();
        if (!$ok) {
            // Key removed or gate switched off between enqueue and run.
            self::fail($pdo, $taskId, $payload, $refusal);
            return;
        }
        $apiKey = trim(self::setting('verification_api_key', ''));

        try {
            $provider = self::buildProvider($apiKey);
        } catch (\Throwable $e) {
            self::fail($pdo, $taskId, $payload, 'Could not start the verification provider: ' . $e->getMessage());
            return;
        }

        $batchSize = self::clampInt(
            self::setting('verification_bulk_batch_size', (string)self::DEFAULT_BATCH_SIZE),
            1, 2000, self::DEFAULT_BATCH_SIZE
        );
        $delayMs = self::clampInt(
            self::setting('verification_bulk_delay_ms', (string)self::DEFAULT_DELAY_MS),
            0, 10000, self::DEFAULT_DELAY_MS
        );
        $maxStreak = self::clampInt(
            self::setting('verification_bulk_max_unknown_streak', (string)self::DEFAULT_MAX_UNKNOWN_STREAK),
            1, 1000, self::DEFAULT_MAX_UNKNOWN_STREAK
        );

        $mode = $payload['mode'];
        $targets = self::nextTargets($pdo, $payload, $batchSize);
        if (empty($targets)) {
            self::complete($pdo, $taskId, $payload, 'Finished: no more leads to verify.');
            return;
        }

        $deadline = microtime(true) + self::BATCH_TIME_BUDGET_S;
        $unknownStreak = 0;
        $processed = 0;
        $firstCall = true;
        $aborted = null; // 'cancel' | 'streak' | 'budget'

        foreach ($targets as $target) {
            // Cooperative cancel: re-read the flag from the DB row periodically.
            if ($processed % self::CANCEL_CHECK_EVERY === 0) {
                $freshTask = self::loadTask($pdo, $taskId);
                if ($freshTask === null) {
                    return; // Task row deleted mid-run — stop quietly.
                }
                $fresh = self::payload($freshTask);
                if (!empty($fresh['cancel_requested'])) {
                    $aborted = 'cancel';
                    break;
                }
            }

            if (!$firstCall && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
            $firstCall = false;

            $status = self::verifyOne($provider, (string)$target['email']);
            self::persistVerdict($pdo, (int)$target['id'], $status);

            $payload['counts'][$status]++;
            $processed++;
            if ($mode === self::MODE_SELECTED) {
                $payload['cursor']++;
            } else {
                $payload['last_id'] = max((int)$payload['last_id'], (int)$target['id']);
            }

            if ($status === EmailVerificationResult::UNKNOWN) {
                $unknownStreak++;
                if ($unknownStreak >= $maxStreak) {
                    $aborted = 'streak';
                    break;
                }
            } else {
                $unknownStreak = 0;
            }

            if (microtime(true) >= $deadline) {
                $aborted = 'budget';
                break;
            }
        }

        $checked = self::checkedSoFar($payload);
        $payload['note'] = "Verified {$checked} of {$payload['total']} ({$processed} this tick).";

        if ($aborted === 'cancel') {
            self::setStatus($pdo, $taskId, $payload, 'Cancelled', 'Cancelled by the buyer.');
            error_log("[BulkVerify] task #{$taskId} cancelled after {$checked} leads.");
            return;
        }
        if ($aborted === 'streak') {
            $payload['note'] =
                "Paused after {$maxStreak} consecutive 'unknown' results — the provider is " .
                "probably rate-limiting or down. Will retry on the next tick ({$checked} of {$payload['total']} done).";
            error_log("[BulkVerify] task #{$taskId}: unknown-streak circuit breaker tripped at {$checked} leads.");
        }

        if ($checked >= (int)$payload['total']) {
            self::complete($pdo, $taskId, $payload);
            return;
        }

        // Park for the next tick. Progress lives in the cursor, so reset the
        // retry counter: a long job legitimately takes many batches and must
        // not trip the queue's crash-abandonment guard.
        self::setStatus(
            $pdo,
            $taskId,
            $payload,
            'Pending',
            '',
            date('Y-m-d H:i:s', time() + self::RESCHEDULE_DELAY_S),
            0
        );
    }

    // ── Status / cancel (API) ──────────────────────────────────────────

    /**
     * Progress snapshot for the pollable status endpoint. Never includes the
     * API key or any secret — only counts, cursor position, and notes.
     */
    public static function status($pdo, int $taskId): ?array
    {
        $task = self::loadTask($pdo, $taskId);
        if ($task === null) {
            return null;
        }
        $payload = self::payload($task);
        if (($payload['job'] ?? '') !== 'bulk_verify') {
            return null;
        }
        $counts = $payload['counts'];
        $checked = self::checkedSoFar($payload);
        $total = max(1, (int)$payload['total']);
        return [
            'task_id' => $taskId,
            'state' => (string)$task['status'],
            'mode' => $payload['mode'],
            'total' => (int)$payload['total'],
            'checked' => $checked,
            'valid' => (int)$counts['valid'],
            'invalid' => (int)$counts['invalid'],
            'risky' => (int)$counts['risky'],
            'unknown' => (int)$counts['unknown'],
            'percent' => (int)round(100 * $checked / $total),
            'note' => (string)($payload['note'] ?? ''),
            'error' => (string)($task['error_message'] ?? ''),
            'started_at' => (string)($payload['started_at'] ?? ''),
            'finished_at' => $payload['finished_at'] ?? null,
            'updated_at' => (string)($task['processed_at'] ?? ''),
        ];
    }

    public static function cancel($pdo, int $taskId): bool
    {
        $task = self::loadTask($pdo, $taskId);
        if ($task === null) {
            return false;
        }
        $status = (string)$task['status'];
        if (!in_array($status, ['Pending', 'In Progress'], true)) {
            return false; // Already terminal — nothing to cancel.
        }
        $payload = self::payload($task);
        $payload['cancel_requested'] = true;
        // If the worker is mid-batch it will see the flag at its next check;
        // if the job is merely queued, flip it straight to Cancelled.
        $newStatus = $status === 'Pending' ? 'Cancelled' : $status;
        self::setStatus($pdo, $taskId, $payload, $newStatus, 'Cancellation requested.');
        error_log("[BulkVerify] task #{$taskId} cancellation requested (was {$status}).");
        return true;
    }

    // ── Internals ──────────────────────────────────────────────────────

    private static function buildProvider(string $apiKey)
    {
        if (self::$providerFactory !== null) {
            return call_user_func(self::$providerFactory, $apiKey);
        }
        return \App\Compliance::makeVerificationProvider($apiKey);
    }

    private static function loadTask($pdo, int $taskId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM task_queue WHERE id = ?");
        $stmt->execute([$taskId]);
        $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private static function payload(array $task): array
    {
        $p = json_decode((string)($task['payload'] ?? ''), true);
        if (!is_array($p)) {
            return [];
        }
        $p['counts'] = array_merge(
            ['valid' => 0, 'invalid' => 0, 'risky' => 0, 'unknown' => 0],
            is_array($p['counts'] ?? null) ? $p['counts'] : []
        );
        return $p;
    }

    private static function checkedSoFar(array $payload): int
    {
        $c = $payload['counts'];
        return (int)$c['valid'] + (int)$c['invalid'] + (int)$c['risky'] + (int)$c['unknown'];
    }

    /**
     * Next batch of [id, email] targets. Portable SQL (no MySQL-isms) so the
     * job runs on the test SQLite harness too.
     */
    private static function nextTargets($pdo, array $payload, int $batchSize): array
    {
        if ($payload['mode'] === self::MODE_SELECTED) {
            $ids = array_slice($payload['lead_ids'] ?? [], (int)($payload['cursor'] ?? 0), $batchSize);
            if (empty($ids)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, email FROM leads WHERE id IN ({$placeholders})");
            $stmt->execute(array_values($ids));
            $rows = $stmt->fetchAll(\App\PDO::FETCH_ASSOC);
            // Preserve the payload order (deleted rows drop out naturally).
            $byId = [];
            foreach ($rows as $r) {
                $byId[(int)$r['id']] = $r;
            }
            $ordered = [];
            foreach ($ids as $id) {
                if (isset($byId[(int)$id])) {
                    $ordered[] = $byId[(int)$id];
                }
            }
            return $ordered;
        }

        $stmt = $pdo->prepare(
            "SELECT id, email FROM leads " .
            "WHERE verification_status = 'unknown' AND email IS NOT NULL AND TRIM(email) <> '' " .
            "AND id > ? ORDER BY id ASC LIMIT {$batchSize}"
        );
        $stmt->execute([(int)($payload['last_id'] ?? 0)]);
        return $stmt->fetchAll(\App\PDO::FETCH_ASSOC);
    }

    private static function verifyOne($provider, string $email): string
    {
        try {
            $result = $provider->verify($email);
            $status = $result->status ?? EmailVerificationResult::UNKNOWN;
        } catch (\Throwable $e) {
            // A throwing provider is an infrastructure failure, never a
            // verdict on the address — same as the send gate treats it.
            $status = EmailVerificationResult::UNKNOWN;
        }
        if (!in_array($status, [
            EmailVerificationResult::VALID,
            EmailVerificationResult::INVALID,
            EmailVerificationResult::RISKY,
            EmailVerificationResult::UNKNOWN,
        ], true)) {
            $status = EmailVerificationResult::UNKNOWN;
        }
        return $status;
    }

    /**
     * Persist the verdict exactly the way the per-send gate does:
     * leads.verification_status + verified_at. An 'invalid' verdict makes the
     * lead unmailable (the gate refuses sends to invalid leads, and the
     * funnel's "mailable" count requires status='valid'). 'unknown' and
     * 'risky' are NEVER upgraded to valid — unknown stays unknown.
     */
    private static function persistVerdict($pdo, int $leadId, string $status): void
    {
        $stmt = $pdo->prepare(
            "UPDATE leads SET verification_status = ?, verified_at = ? WHERE id = ?"
        );
        $stmt->execute([$status, date('Y-m-d H:i:s'), $leadId]);
    }

    private static function complete($pdo, int $taskId, array $payload, string $note = ''): void
    {
        $c = $payload['counts'];
        $payload['finished_at'] = date('Y-m-d H:i:s');
        $payload['note'] = $note !== '' ? $note : sprintf(
            'Done. %d verified: %d valid, %d invalid, %d risky, %d unknown.',
            self::checkedSoFar($payload),
            $c['valid'], $c['invalid'], $c['risky'], $c['unknown']
        );
        self::setStatus($pdo, $taskId, $payload, 'Completed', '');
        error_log("[BulkVerify] task #{$taskId} completed: " . $payload['note']);
    }

    private static function fail($pdo, int $taskId, array $payload, string $message): void
    {
        $payload['finished_at'] = date('Y-m-d H:i:s');
        $payload['note'] = 'Failed.';
        self::setStatus($pdo, $taskId, $payload, 'Failed', $message);
        error_log("[BulkVerify] task #{$taskId} failed: {$message}");
    }

    private static function setStatus(
        $pdo,
        int $taskId,
        array $payload,
        string $status,
        string $error = '',
        ?string $scheduledAt = null,
        ?int $retryCount = null
    ): void {
        $sql = "UPDATE task_queue SET status = ?, payload = ?, error_message = ?, processed_at = ?";
        $params = [$status, json_encode($payload), $error, date('Y-m-d H:i:s')];
        if ($scheduledAt !== null) {
            $sql .= ", scheduled_at = ?";
            $params[] = $scheduledAt;
        }
        if ($retryCount !== null) {
            $sql .= ", retry_count = ?";
            $params[] = $retryCount;
        }
        $sql .= " WHERE id = ?";
        $params[] = $taskId;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    private static function clampInt(string $raw, int $min, int $max, int $default): int
    {
        if (!is_numeric(trim($raw))) {
            return $default;
        }
        return max($min, min($max, (int)$raw));
    }
}
