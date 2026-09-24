<?php

declare(strict_types=1);

namespace App;

/**
 * FIX 4 — paused-campaign queue gate.
 *
 * The compliance monitor (SendMonitor) auto-pauses campaigns with
 * `campaigns.is_active = 0`, and the buyer can pause manually the same way.
 * Before this fix, cron/process_queue.php kept draining already-queued send
 * tasks for a paused campaign — a pause that doesn't stop sending.
 *
 * This class decides which pending tasks the worker must HOLD for the
 * current tick. Held tasks are SKIPPED, not claimed: they stay 'Pending'
 * with their original scheduled_at, burn no attempts, touch no blocked-send
 * counters, and resume automatically the next tick after the campaign is
 * reactivated. Other campaigns' tasks in the same tick are unaffected.
 *
 * Cost model: at most two extra queries per worker tick (one batched
 * campaign-status lookup, one batched lead→campaign fallback), regardless of
 * how many tasks are pending. Never throws — on any lookup failure the gate
 * fails OPEN (processes the task) rather than stalling the queue.
 *
 * Mid-flight boundary (honest): a task already claimed and executing when
 * the pause lands cannot be un-sent. This gate only guarantees no NEW
 * sends for a paused campaign are claimed after the check.
 */
final class CampaignPauseGate
{
    /** Task types that represent outbound sends and must respect pauses. */
    public const SEND_TASK_TYPES = ['EmailOutreach', 'SocialOutreach'];

    public static function isSendTaskType(string $taskType): bool
    {
        return in_array($taskType, self::SEND_TASK_TYPES, true);
    }

    /**
     * Resolve the owning campaign for each send-type task row.
     *
     * Payload campaign_id wins (the throttle gate uses the same source);
     * falls back to the lead's campaign_id via ONE batched query for tasks
     * whose payload carries no campaign. Non-send tasks are excluded.
     *
     * @param object $pdo \App\PDO in production, native \PDO in tests.
     * @param array<int,array<string,mixed>> $taskRows rows with id, task_type, payload, lead_id.
     * @return array<int,int|null> taskId => campaignId (null = no campaign context; fail-open).
     */
    public static function resolveTaskCampaigns($pdo, array $taskRows): array
    {
        $resolved = [];
        $needsLeadLookup = [];
        foreach ($taskRows as $row) {
            $taskId = (int)($row['id'] ?? 0);
            if ($taskId <= 0 || !self::isSendTaskType((string)($row['task_type'] ?? ''))) {
                continue;
            }
            $campaignId = self::payloadCampaignId($row['payload'] ?? null);
            if ($campaignId !== null) {
                $resolved[$taskId] = $campaignId;
            } else {
                $leadId = (int)($row['lead_id'] ?? 0);
                if ($leadId > 0) {
                    $needsLeadLookup[$taskId] = $leadId;
                } else {
                    $resolved[$taskId] = null;
                }
            }
        }

        if (!empty($needsLeadLookup)) {
            try {
                $placeholders = implode(',', array_fill(0, count($needsLeadLookup), '?'));
                $stmt = $pdo->prepare(
                    "SELECT id, campaign_id FROM leads WHERE id IN ({$placeholders})"
                );
                if ($stmt !== false) {
                    $stmt->execute(array_values($needsLeadLookup));
                    $leadCampaign = [];
                    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $leadRow) {
                        $cid = (int)($leadRow['campaign_id'] ?? 0);
                        $leadCampaign[(int)$leadRow['id']] = $cid > 0 ? $cid : null;
                    }
                    foreach ($needsLeadLookup as $taskId => $leadId) {
                        $resolved[$taskId] = $leadCampaign[$leadId] ?? null;
                    }
                }
            } catch (\Throwable $e) {
                // Fail-open: tasks without resolved campaigns are processed.
                foreach ($needsLeadLookup as $taskId => $leadId) {
                    $resolved[$taskId] = null;
                }
            }
        }

        return $resolved;
    }

    /**
     * Which of these campaign IDs are currently paused?
     *
     * A campaign counts as paused when campaigns.is_active = 0. Both the
     * auto-pause (SendMonitor) and the manual toggle write is_active, so one
     * predicate covers both. Missing rows are treated as ACTIVE (fail-open:
     * never stall the queue on a missing campaign).
     *
     * @param object $pdo \App\PDO in production, native \PDO in tests.
     * @return array<int,true> paused campaign IDs as keys.
     */
    public static function pausedIds($pdo, array $campaignIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $campaignIds), fn($i) => $i > 0)));
        if (empty($ids)) {
            return [];
        }
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT id FROM campaigns WHERE id IN ({$placeholders}) AND is_active = 0"
            );
            if ($stmt === false) {
                return [];
            }
            $stmt->execute($ids);
            $paused = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $paused[(int)$id] = true;
            }
            return $paused;
        } catch (\Throwable $e) {
            // Pre-migration schema or any lookup failure: fail open.
            return [];
        }
    }

    /**
     * Task IDs the worker must skip this tick: send-type tasks whose owning
     * campaign is paused. Held tasks stay 'Pending' — the caller must NOT
     * claim, defer, fail, or count them.
     *
     * @param object $pdo \App\PDO in production, native \PDO in tests.
     * @param array<int,array<string,mixed>> $taskRows pending task rows.
     * @return array<int,true> held task IDs as keys.
     */
    public static function holdTaskIds($pdo, array $taskRows): array
    {
        try {
            $resolved = self::resolveTaskCampaigns($pdo, $taskRows);
            $paused = self::pausedIds($pdo, array_values(array_filter($resolved)));
            $held = [];
            foreach ($resolved as $taskId => $campaignId) {
                if ($campaignId !== null && isset($paused[$campaignId])) {
                    $held[$taskId] = true;
                }
            }
            return $held;
        } catch (\Throwable $e) {
            return []; // fail open
        }
    }

    /**
     * Manual-send enforcement: refuse a one-off send when its campaign is
     * paused (api/send_email.php calls this). Throws a buyer-facing
     * RuntimeException naming the pause; the auto-pause reason is appended
     * when the compliance monitor set one. Never throws on lookup failure
     * (fail-open, same convention as the queue gate) and never throws when
     * there is no campaign context.
     *
     * Deliberately NOT recorded in BlockedCount: this refusal is synchronous
     * and the buyer sees the reason immediately in the UI. BlockedCount
     * exists for queue-side refusals where the buyer would otherwise wonder
     * why sends silently didn't go out.
     *
     * @param object $pdo \App\PDO in production, native \PDO in tests.
     * @throws \RuntimeException when the campaign is paused.
     */
    public static function throwIfPaused($pdo, ?int $campaignId): void
    {
        if ($campaignId === null || $campaignId <= 0) {
            return;
        }
        $paused = self::pausedIds($pdo, [$campaignId]); // never throws
        if (!isset($paused[$campaignId])) {
            return;
        }
        $reason = self::pauseReason($pdo, $campaignId); // never throws
        $msg = 'This campaign is paused — manual sends are held until you reactivate it.';
        if ($reason !== null && $reason !== '') {
            $msg .= ' Pause reason: ' . $reason;
        }
        throw new \RuntimeException($msg);
    }

    /**
     * The compliance monitor's pause reason (campaigns.paused_reason) when
     * the pause was automatic; null for manual pauses or when the columns
     * don't exist yet (pre-migration). Never throws.
     */
    private static function pauseReason($pdo, int $campaignId): ?string
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT status, paused_reason FROM campaigns WHERE id = ? LIMIT 1"
            );
            if ($stmt === false) {
                return null;
            }
            $stmt->execute([$campaignId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || ($row['status'] ?? '') !== 'paused') {
                return null;
            }
            $reason = trim((string)($row['paused_reason'] ?? ''));
            return $reason !== '' ? $reason : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extract campaign_id from a task payload (JSON string or array).
     * Returns null when absent/invalid — never throws.
     */
    private static function payloadCampaignId($payload): ?int
    {
        try {
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }
            if (is_array($payload) && isset($payload['campaign_id'])) {
                $cid = (int)$payload['campaign_id'];
                return $cid > 0 ? $cid : null;
            }
        } catch (\Throwable $e) {
            // fall through to null
        }
        return null;
    }
}
