<?php

declare(strict_types=1);

namespace App;

use App\Exceptions\OutreachException;

/**
 * SequenceManager — Phase 4 campaign-sequence engine.
 *
 * Owns everything a launched campaign needs after "Launch":
 *   - launch(): enroll eligible leads, queue step 1 honoring templates.step_order
 *   - queueStep(): enqueue one step as a `SequenceSend` task_queue row
 *   - sendSucceeded()/sendSimulated()/sendFailed(): send outcome bookkeeping
 *     plus cadence progression (step N sent -> step N+1 queued per the
 *     sent template's delay_days)
 *   - recordOpen(): open-tracking pixel attribution (tokenized, non-enumerable)
 *   - recordReply(): attach a Phase 3 classification verdict to the
 *     lead/campaign timeline and stop the sequence per reply intent
 *   - stopForLead()/stopForEmail()/stopCampaign(): stop sequences on reply,
 *     unsubscribe, bounce, complaint, or manual halt (cancels queued sends
 *     and their pending task_queue rows)
 *   - event(): append to the sequence_events campaign timeline
 *   - campaignStats(): per-campaign aggregates for api/stats.php
 *
 * All methods are static and take an optional \App\PDO (defaulting to
 * Database::getConnection()), so the static webhook/unsubscribe call sites
 * and the API endpoints share the same code. Every method degrades cleanly
 * when the Phase-4 tables are not installed (tableReady() probe, cached per
 * request) — pre-migration installs behave exactly as before.
 */
final class SequenceManager
{
    /** Enrollment statuses that stop all further sending for the lead. */
    public const STOPPED_STATUSES = [
        'stopped_reply',
        'stopped_unsubscribe',
        'stopped_bounce',
        'stopped_complaint',
        'stopped_hostile',
        'stopped_manual',
    ];

    /** Lead statuses eligible for sequence enrollment at launch. */
    private const ELIGIBLE_LEAD_STATUSES = ['New', 'Enriched', 'Drafted', 'Qualified'];

    /** Lead statuses that must never be mailed (checked again at send time). */
    private const TERMINAL_LEAD_STATUSES = ['Converted', 'Unqualified'];

    /** Reply intents that stop the sequence (null = keep the sequence going). */
    private const INTENT_STOP_MAP = [
        'unsubscribe'   => 'stopped_unsubscribe',
        'bounce'        => 'stopped_bounce',
        'hostile'       => 'stopped_hostile',
        'positive'      => 'stopped_reply',
        'objection'     => 'stopped_reply',
        'referral'      => 'stopped_reply',
        'not_now'       => 'stopped_reply',
        'other'         => 'stopped_reply',
        // 'out_of_office' is deliberately absent: an autoresponder is not a
        // human reply, so the sequence continues.
    ];

    /** Per-request cache of tableReady() probes. */
    private static array $readyCache = [];

    private static function pdo(?\App\PDO $pdo): \App\PDO
    {
        return $pdo ?? Database::getConnection();
    }

    /**
     * True when a Phase-4 table exists. Cached per request; never throws.
     */
    public static function tableReady(?\App\PDO $pdo, string $table): bool
    {
        if (isset(self::$readyCache[$table])) {
            return self::$readyCache[$table];
        }
        try {
            $db = self::pdo($pdo);
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS c FROM information_schema.TABLES " .
                "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $stmt->execute([$table]);
            $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
            return self::$readyCache[$table] = ($row && (int)$row['c'] > 0);
        } catch (\Throwable $e) {
            return self::$readyCache[$table] = false;
        }
    }

    /** Reset the tableReady() cache (tests). */
    public static function resetReadyCache(): void
    {
        self::$readyCache = [];
    }

    // ── Launch ───────────────────────────────────────────────────────────

    /**
     * Launch a campaign: enroll every eligible lead and queue step 1.
     *
     * Eligible = assigned to the campaign, status in (New, Enriched, Drafted,
     * Qualified), not on the suppression list, not already enrolled.
     * Idempotent: re-launching only enrolls leads that are not enrolled yet.
     *
     * @return array{enrolled:int, queued:int, skipped_enrolled:int, skipped_suppressed:int, skipped_status:int, templates:int}
     * @throws OutreachException when the campaign is missing/inactive/paused
     *                           or has no templates, or tables aren't installed.
     */
    public static function launch(?\App\PDO $pdo, int $campaignId): array
    {
        $pdo = self::pdo($pdo);
        if (!self::tableReady($pdo, 'sequence_enrollments')) {
            throw new OutreachException(
                'Phase-4 sequence tables are not installed. Apply migrations/2026-09-28-phase4-sequences.sql first.'
            );
        }
        if ($campaignId <= 0) {
            throw new OutreachException('Missing campaign id.');
        }

        $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ?");
        $stmt->execute([$campaignId]);
        $campaign = $stmt->fetch(\App\PDO::FETCH_ASSOC);
        if (!$campaign) {
            throw new OutreachException("Campaign {$campaignId} not found.");
        }
        if (!(bool)($campaign['is_active'] ?? false)) {
            throw new OutreachException("Campaign '{$campaign['name']}' is not active. Activate it before launching.");
        }
        // The item-4 pause columns are optional; when present, a paused
        // campaign must be resumed (api/campaigns.php?action=resume) first.
        if (array_key_exists('status', $campaign) && ($campaign['status'] ?? 'active') !== 'active') {
            throw new OutreachException(
                "Campaign '{$campaign['name']}' is {$campaign['status']}" .
                (isset($campaign['paused_reason']) && $campaign['paused_reason'] !== ''
                    ? " ({$campaign['paused_reason']})" : '') .
                '. Resume it before launching.'
            );
        }

        $tstmt = $pdo->prepare(
            "SELECT id FROM templates WHERE campaign_id = ? ORDER BY step_order ASC, id ASC"
        );
        $tstmt->execute([$campaignId]);
        $templates = $tstmt->fetchAll(\App\PDO::FETCH_ASSOC);
        if (count($templates) === 0) {
            throw new OutreachException(
                "Campaign '{$campaign['name']}' has no templates. Add at least one template (step 1) before launching."
            );
        }

        $lstmt = $pdo->prepare(
            "SELECT id, email FROM leads WHERE campaign_id = ? ORDER BY id ASC"
        );
        $lstmt->execute([$campaignId]);
        $leads = $lstmt->fetchAll(\App\PDO::FETCH_ASSOC);

        $result = [
            'enrolled' => 0, 'queued' => 0, 'skipped_enrolled' => 0,
            'skipped_suppressed' => 0, 'skipped_status' => 0,
            'templates' => count($templates),
        ];

        foreach ($leads as $lead) {
            $leadId = (int)$lead['id'];
            $email = strtolower(trim((string)($lead['email'] ?? '')));

            // Status eligibility is checked at launch AND at send time.
            $sstmt = $pdo->prepare("SELECT status FROM leads WHERE id = ?");
            $sstmt->execute([$leadId]);
            $status = (string)($sstmt->fetch(\App\PDO::FETCH_ASSOC)['status'] ?? '');
            if (!in_array($status, self::ELIGIBLE_LEAD_STATUSES, true)) {
                $result['skipped_status']++;
                continue;
            }
            if ($email === '' || Compliance::isSuppressed($email)) {
                $result['skipped_suppressed']++;
                continue;
            }

            $istmt = $pdo->prepare(
                "INSERT IGNORE INTO sequence_enrollments (campaign_id, lead_id, status, current_step) " .
                "VALUES (?, ?, 'active', 1)"
            );
            $istmt->execute([$campaignId, $leadId]);
            if ($istmt->rowCount() === 0) {
                $result['skipped_enrolled']++;
                continue;
            }
            $enrollmentId = (int)$pdo->lastInsertId();
            $result['enrolled']++;
            self::event($pdo, $campaignId, $leadId, null, 'enrolled',
                "Lead enrolled in campaign '{$campaign['name']}' (" . count($templates) . " steps)");

            $sendId = self::queueStep($pdo, $enrollmentId, 1);
            if ($sendId !== null) {
                $result['queued']++;
            }
        }

        self::event($pdo, $campaignId, null, null, 'launch',
            "Campaign launched: {$result['enrolled']} enrolled, {$result['queued']} step-1 sends queued, " .
            "{$result['skipped_enrolled']} already enrolled, {$result['skipped_suppressed']} suppressed, " .
            "{$result['skipped_status']} ineligible status");

        return $result;
    }

    /**
     * Enqueue one sequence step as a `SequenceSend` task_queue row.
     * Dedupes: never double-queues a step that is queued/sending/sent/simulated.
     * Returns the sequence_sends id, or null when there is nothing to queue.
     */
    public static function queueStep(
        ?\App\PDO $pdo,
        int $enrollmentId,
        int $stepOrder,
        ?string $scheduledAt = null
    ): ?int {
        $pdo = self::pdo($pdo);
        if (!self::tableReady($pdo, 'sequence_sends')) {
            return null;
        }

        $estmt = $pdo->prepare("SELECT * FROM sequence_enrollments WHERE id = ?");
        $estmt->execute([$enrollmentId]);
        $enrollment = $estmt->fetch(\App\PDO::FETCH_ASSOC);
        if (!$enrollment || ($enrollment['status'] ?? '') !== 'active') {
            return null;
        }
        $campaignId = (int)$enrollment['campaign_id'];
        $leadId = (int)$enrollment['lead_id'];

        $tstmt = $pdo->prepare(
            "SELECT id FROM templates WHERE campaign_id = ? AND step_order = ? LIMIT 1"
        );
        $tstmt->execute([$campaignId, $stepOrder]);
        $template = $tstmt->fetch(\App\PDO::FETCH_ASSOC);
        if (!$template) {
            return null;
        }

        // Dedupe: a step already queued/sending/sent/simulated is never re-queued.
        $dstmt = $pdo->prepare(
            "SELECT id FROM sequence_sends WHERE enrollment_id = ? AND step_order = ? " .
            "AND status IN ('queued', 'sending', 'sent', 'simulated') LIMIT 1"
        );
        $dstmt->execute([$enrollmentId, $stepOrder]);
        $existing = $dstmt->fetch(\App\PDO::FETCH_ASSOC);
        if ($existing) {
            return (int)$existing['id'];
        }

        $token = bin2hex(random_bytes(32));
        $sched = $scheduledAt ?? date('Y-m-d H:i:s');
        $istmt = $pdo->prepare(
            "INSERT INTO sequence_sends (enrollment_id, campaign_id, lead_id, template_id, step_order, " .
            "status, scheduled_at, track_token) VALUES (?, ?, ?, ?, ?, 'queued', ?, ?)"
        );
        $istmt->execute([$enrollmentId, $campaignId, $leadId, $template['id'], $stepOrder, $sched, $token]);
        $sendId = (int)$pdo->lastInsertId();

        $payload = json_encode([
            'sequence_send_id' => $sendId,
            'campaign_id'      => $campaignId,
            'lead_id'          => $leadId,
            'step_order'       => $stepOrder,
        ]);
        $qstmt = $pdo->prepare(
            "INSERT INTO task_queue (lead_id, task_type, payload, status, scheduled_at) " .
            "VALUES (?, 'SequenceSend', ?, 'Pending', ?)"
        );
        $qstmt->execute([$leadId, $payload, $sched]);
        $taskId = (int)$pdo->lastInsertId();

        $ustmt = $pdo->prepare("UPDATE sequence_sends SET task_id = ? WHERE id = ?");
        $ustmt->execute([$taskId, $sendId]);

        $ustmt2 = $pdo->prepare(
            "UPDATE sequence_enrollments SET current_step = GREATEST(current_step, ?) WHERE id = ?"
        );
        $ustmt2->execute([$stepOrder, $enrollmentId]);

        self::event($pdo, $campaignId, $leadId, $sendId, 'queued',
            "Step {$stepOrder} queued (task {$taskId}, scheduled {$sched})");

        return $sendId;
    }

    // ── Send outcomes ────────────────────────────────────────────────────

    /**
     * Step sent (live): mark sent, mark the lead Contacted, then progress the
     * sequence — next template step is queued per the sent template's
     * delay_days cadence; with no next step the enrollment completes.
     * Scheduling never fails a successful send (logged, not thrown).
     */
    public static function sendSucceeded(?\App\PDO $pdo, int $sendId): void
    {
        self::finishSend($pdo, $sendId, 'sent');
    }

    /** Step "sent" in Safety/Simulated mode: same bookkeeping, distinct status. */
    public static function sendSimulated(?\App\PDO $pdo, int $sendId): void
    {
        self::finishSend($pdo, $sendId, 'simulated');
    }

    private static function finishSend(?\App\PDO $pdo, int $sendId, string $status): void
    {
        $pdo = self::pdo($pdo);
        if (!self::tableReady($pdo, 'sequence_sends')) {
            return;
        }
        $sstmt = $pdo->prepare("SELECT * FROM sequence_sends WHERE id = ?");
        $sstmt->execute([$sendId]);
        $send = $sstmt->fetch(\App\PDO::FETCH_ASSOC);
        if (!$send) {
            return;
        }
        // Idempotent: a retried worker must not double-progress the sequence.
        if (in_array($send['status'] ?? '', ['sent', 'simulated'], true)) {
            return;
        }

        $ustmt = $pdo->prepare(
            "UPDATE sequence_sends SET status = ?, sent_at = NOW() " .
            "WHERE id = ? AND status IN ('queued', 'sending')"
        );
        $ustmt->execute([$status, $sendId]);

        $leadId = (int)$send['lead_id'];
        $campaignId = (int)$send['campaign_id'];
        $enrollmentId = (int)$send['enrollment_id'];
        $stepOrder = (int)$send['step_order'];

        try {
            $lstmt = $pdo->prepare("UPDATE leads SET status = 'Contacted' WHERE id = ? AND status NOT IN ('Converted', 'Unqualified')");
            $lstmt->execute([$leadId]);
        } catch (\Throwable $e) {
            error_log('[SequenceManager] lead Contacted mark failed: ' . $e->getMessage());
        }

        self::event($pdo, $campaignId, $leadId, $sendId, $status,
            "Step {$stepOrder} {$status}");

        // Cadence progression: schedule step N+1 per the SENT template's delay.
        try {
            $tstmt = $pdo->prepare(
                "SELECT delay_days FROM templates WHERE id = ? LIMIT 1"
            );
            $tstmt->execute([(int)$send['template_id']]);
            $tpl = $tstmt->fetch(\App\PDO::FETCH_ASSOC);
            $delayDays = max(0, (int)($tpl['delay_days'] ?? 3));

            $nstmt = $pdo->prepare(
                "SELECT id FROM templates WHERE campaign_id = ? AND step_order = ? LIMIT 1"
            );
            $nstmt->execute([$campaignId, $stepOrder + 1]);
            $next = $nstmt->fetch(\App\PDO::FETCH_ASSOC);

            if (!$next) {
                // Sequence complete — but only if the enrollment is still active
                // (a reply may have stopped it while this step was sending).
                $cstmt = $pdo->prepare(
                    "UPDATE sequence_enrollments SET status = 'completed', stopped_at = NOW(), " .
                    "stop_reason = 'all steps sent' WHERE id = ? AND status = 'active'"
                );
                $cstmt->execute([$enrollmentId]);
                if ($cstmt->rowCount() > 0) {
                    self::event($pdo, $campaignId, $leadId, null, 'sequence_completed',
                        "All {$stepOrder} steps sent; enrollment completed");
                }
                return;
            }

            // Re-check the enrollment is still active before queuing the next step.
            $estmt = $pdo->prepare("SELECT status FROM sequence_enrollments WHERE id = ?");
            $estmt->execute([$enrollmentId]);
            $enr = $estmt->fetch(\App\PDO::FETCH_ASSOC);
            if (!$enr || ($enr['status'] ?? '') !== 'active') {
                return;
            }

            $scheduledAt = date('Y-m-d H:i:s', strtotime("+{$delayDays} days"));
            self::queueStep($pdo, $enrollmentId, $stepOrder + 1, $scheduledAt);
        } catch (\Throwable $e) {
            // Never fail an already-successful send because scheduling hiccuped.
            error_log('[SequenceManager] sequence progression failed: ' . $e->getMessage());
        }
    }

    /**
     * Record a send failure. Permanent failures mark the send 'failed';
     * transient ones (provider hiccup, worker will requeue the task) keep it
     * 'queued' with the error noted for the next attempt.
     */
    public static function sendFailed(?\App\PDO $pdo, int $sendId, string $message, bool $permanent): void
    {
        $pdo = self::pdo($pdo);
        if (!self::tableReady($pdo, 'sequence_sends')) {
            return;
        }
        try {
            if ($permanent) {
                $stmt = $pdo->prepare(
                    "UPDATE sequence_sends SET status = 'failed', error_message = ? WHERE id = ?"
                );
                $stmt->execute([mb_substr($message, 0, 2000), $sendId]);
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE sequence_sends SET status = 'queued', error_message = ? " .
                    "WHERE id = ? AND status IN ('queued', 'sending')"
                );
                $stmt->execute([mb_substr('Transient, will retry: ' . $message, 0, 2000), $sendId]);
            }
            $sstmt = $pdo->prepare("SELECT campaign_id, lead_id, step_order FROM sequence_sends WHERE id = ?");
            $sstmt->execute([$sendId]);
            $send = $sstmt->fetch(\App\PDO::FETCH_ASSOC);
            if ($send) {
                self::event($pdo, (int)$send['campaign_id'], (int)$send['lead_id'], $sendId,
                    $permanent ? 'failed' : 'queued',
                    "Step {$send['step_order']} " . ($permanent ? 'failed' : 'will retry') . ": " .
                    mb_substr($message, 0, 500));
            }
        } catch (\Throwable $e) {
            error_log('[SequenceManager] sendFailed bookkeeping failed: ' . $e->getMessage());
        }
    }

    // ── Stops ────────────────────────────────────────────────────────────

    /**
     * Stop a lead's sequence(s): mark enrollment(s) stopped, cancel queued
     * sends, delete their still-Pending task_queue rows (a stopped sequence
     * must not surface as failed work).
     *
     * @return int number of enrollments stopped
     */
    public static function stopForLead(
        ?\App\PDO $pdo,
        int $leadId,
        ?int $campaignId,
        string $stopStatus,
        string $reason
    ): int {
        $pdo = self::pdo($pdo);
        if (!self::tableReady($pdo, 'sequence_enrollments') || $leadId <= 0) {
            return 0;
        }
        if (!in_array($stopStatus, self::STOPPED_STATUSES, true)) {
            $stopStatus = 'stopped_manual';
        }

        try {
            $sql = "SELECT id, campaign_id FROM sequence_enrollments " .
                   "WHERE lead_id = ? AND status = 'active'";
            $params = [$leadId];
            if ($campaignId !== null && $campaignId > 0) {
                $sql .= " AND campaign_id = ?";
                $params[] = $campaignId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $enrollments = $stmt->fetchAll(\App\PDO::FETCH_ASSOC);

            $stopped = 0;
            foreach ($enrollments as $enr) {
                $enrollmentId = (int)$enr['id'];
                $cid = (int)$enr['campaign_id'];

                $ustmt = $pdo->prepare(
                    "UPDATE sequence_enrollments SET status = ?, stopped_at = NOW(), stop_reason = ? " .
                    "WHERE id = ? AND status = 'active'"
                );
                $ustmt->execute([$stopStatus, mb_substr($reason, 0, 255), $enrollmentId]);
                if ($ustmt->rowCount() === 0) {
                    continue;
                }
                $stopped++;

                // Cancel queued sends and remove their pending queue tasks.
                $sstmt = $pdo->prepare(
                    "SELECT id, task_id FROM sequence_sends " .
                    "WHERE enrollment_id = ? AND status IN ('queued', 'sending')"
                );
                $sstmt->execute([$enrollmentId]);
                foreach ($sstmt->fetchAll(\App\PDO::FETCH_ASSOC) as $send) {
                    $pdo->prepare("UPDATE sequence_sends SET status = 'cancelled' WHERE id = ?")
                        ->execute([(int)$send['id']]);
                    if ((int)($send['task_id'] ?? 0) > 0) {
                        $pdo->prepare("DELETE FROM task_queue WHERE id = ? AND status = 'Pending'")
                            ->execute([(int)$send['task_id']]);
                    }
                }

                self::event($pdo, $cid, $leadId, null, 'sequence_stopped',
                    "Sequence stopped ({$stopStatus}): {$reason}");
            }
            return $stopped;
        } catch (\Throwable $e) {
            error_log('[SequenceManager] stopForLead failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** Stop every active sequence for the lead(s) owning an email address. */
    public static function stopForEmail(
        ?\App\PDO $pdo,
        string $email,
        string $stopStatus,
        string $reason
    ): int {
        $pdo = self::pdo($pdo);
        $email = strtolower(trim($email));
        if ($email === '' || !self::tableReady($pdo, 'sequence_enrollments')) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare("SELECT id FROM leads WHERE email = ?");
            $stmt->execute([$email]);
            $stopped = 0;
            foreach ($stmt->fetchAll(\App\PDO::FETCH_ASSOC) as $lead) {
                $stopped += self::stopForLead($pdo, (int)$lead['id'], null, $stopStatus, $reason);
            }
            return $stopped;
        } catch (\Throwable $e) {
            error_log('[SequenceManager] stopForEmail failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** Halt every active enrollment of a campaign (manual stop). */
    public static function stopCampaign(?\App\PDO $pdo, int $campaignId, string $reason = 'manual stop'): int
    {
        $pdo = self::pdo($pdo);
        if (!self::tableReady($pdo, 'sequence_enrollments') || $campaignId <= 0) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT DISTINCT lead_id FROM sequence_enrollments WHERE campaign_id = ? AND status = 'active'"
            );
            $stmt->execute([$campaignId]);
            $stopped = 0;
            foreach ($stmt->fetchAll(\App\PDO::FETCH_ASSOC) as $row) {
                $stopped += self::stopForLead($pdo, (int)$row['lead_id'], $campaignId, 'stopped_manual', $reason);
            }
            self::event($pdo, $campaignId, null, null, 'campaign_stopped',
                "Campaign sequences halted manually: {$stopped} enrollment(s) stopped ({$reason})");
            return $stopped;
        } catch (\Throwable $e) {
            error_log('[SequenceManager] stopCampaign failed: ' . $e->getMessage());
            return 0;
        }
    }

    // ── Open tracking ────────────────────────────────────────────────────

    /** Pixel URL for a send's tracking token, or null when no base URL is set. */
    public static function trackingPixelUrl(string $token): ?string
    {
        $base = trim((string)(Database::getSetting('app_base_url', '') ?? ''));
        if ($base === '' || $token === '') {
            return null;
        }
        return rtrim($base, '/') . '/api/track_open.php?t=' . urlencode($token);
    }

    /** 1x1 tracking pixel HTML for a send, or '' when no pixel can be built. */
    public static function trackingPixel(?\App\PDO $pdo, int $sendId): string
    {
        $pdo = self::pdo($pdo);
        try {
            $stmt = $pdo->prepare("SELECT track_token FROM sequence_sends WHERE id = ?");
            $stmt->execute([$sendId]);
            $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
            $token = (string)($row['track_token'] ?? '');
            $url = self::trackingPixelUrl($token);
            if ($url === null) {
                return '';
            }
            return '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" width="1" height="1" alt="" style="display:none" />';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Attribute an open to a send by its unguessable token.
     * Returns false (no throw) for bad tokens or missing tables — the pixel
     * endpoint answers identically either way, so tokens are non-enumerable.
     */
    public static function recordOpen(
        ?\App\PDO $pdo,
        string $token,
        ?string $ip = null,
        ?string $userAgent = null
    ): bool {
        $pdo = self::pdo($pdo);
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return false;
        }
        if (!self::tableReady($pdo, 'sequence_sends')) {
            return false;
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT id, campaign_id, lead_id, step_order FROM sequence_sends WHERE track_token = ? LIMIT 1"
            );
            $stmt->execute([$token]);
            $send = $stmt->fetch(\App\PDO::FETCH_ASSOC);
            if (!$send) {
                return false;
            }
            $ustmt = $pdo->prepare(
                "UPDATE sequence_sends SET open_count = open_count + 1, " .
                "first_opened_at = COALESCE(first_opened_at, NOW()), last_opened_at = NOW() " .
                "WHERE id = ?"
            );
            $ustmt->execute([(int)$send['id']]);

            $detail = 'Open tracked' .
                ($ip !== null && $ip !== '' ? " from {$ip}" : '') .
                ($userAgent !== null && $userAgent !== '' ? ' | UA: ' . mb_substr($userAgent, 0, 200) : '');
            self::event($pdo, (int)$send['campaign_id'], (int)$send['lead_id'], (int)$send['id'],
                'opened', $detail);
            return true;
        } catch (\Throwable $e) {
            error_log('[SequenceManager] recordOpen failed: ' . $e->getMessage());
            return false;
        }
    }

    // ── Reply tracking (Phase 3 intake link) ─────────────────────────────

    /**
     * Attach a Phase 3 classification verdict to the lead/campaign timeline
     * and stop the sequence per the reply intent (any human reply halts
     * automated follow-ups; out-of-office autoresponders do not).
     *
     * Does NOT apply ReplyRouter routing — the ingest caller still decides
     * that (api/ingest_reply.php keeps its routed:false contract).
     *
     * @return array{attached:bool, lead_id:?int, intent:?string, stopped:?string}
     */
    public static function recordReply(
        ?\App\PDO $pdo,
        ?int $leadId,
        ?int $campaignId,
        ?string $email,
        array $verdict,
        ?string $subject = null
    ): array {
        $pdo = self::pdo($pdo);
        $none = ['attached' => false, 'lead_id' => null, 'intent' => null, 'stopped' => null];
        if (!self::tableReady($pdo, 'sequence_events')) {
            return $none;
        }
        try {
            $email = $email !== null ? strtolower(trim($email)) : null;
            if (($leadId === null || $leadId <= 0) && $email !== null && $email !== '') {
                $stmt = $pdo->prepare("SELECT id, campaign_id FROM leads WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
                if ($row) {
                    $leadId = (int)$row['id'];
                    if ($campaignId === null || $campaignId <= 0) {
                        $campaignId = (int)($row['campaign_id'] ?? 0);
                    }
                }
            }
            if ($leadId === null || $leadId <= 0) {
                return $none;
            }
            if ($campaignId === null || $campaignId <= 0) {
                $stmt = $pdo->prepare("SELECT campaign_id FROM leads WHERE id = ?");
                $stmt->execute([$leadId]);
                $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
                $campaignId = (int)($row['campaign_id'] ?? 0);
            }
            if ($campaignId <= 0) {
                return $none;
            }

            $intent = strtolower(trim((string)($verdict['intent'] ?? 'other')));
            $snippet = $subject !== null && $subject !== ''
                ? 'Subject: ' . mb_substr($subject, 0, 200) : '';

            self::event($pdo, $campaignId, $leadId, null, 'replied',
                trim("Inbound reply from " . ($email ?? "lead {$leadId}") . ". {$snippet}"));
            self::event($pdo, $campaignId, $leadId, null, 'classified',
                'Phase-3 verdict: ' . json_encode($verdict));

            $stopped = null;
            if (array_key_exists($intent, self::INTENT_STOP_MAP)) {
                $stopStatus = self::INTENT_STOP_MAP[$intent];
                if ($intent === 'unsubscribe' && $email !== null && $email !== '') {
                    // Defense in depth: the reply itself is an opt-out, even
                    // if the caller never applies router routing.
                    Compliance::suppress($email, 'unsubscribe', 'ingest_reply');
                }
                $n = self::stopForLead($pdo, $leadId, $campaignId, $stopStatus, "reply intent: {$intent}");
                $stopped = $n > 0 ? $stopStatus : null;
            }

            return ['attached' => true, 'lead_id' => $leadId, 'intent' => $intent, 'stopped' => $stopped];
        } catch (\Throwable $e) {
            error_log('[SequenceManager] recordReply failed: ' . $e->getMessage());
            return $none;
        }
    }

    // ── Timeline ─────────────────────────────────────────────────────────

    /** Append a row to the sequence_events timeline. Never throws. */
    public static function event(
        ?\App\PDO $pdo,
        int $campaignId,
        ?int $leadId,
        ?int $sendId,
        string $eventType,
        ?string $detail = null
    ): void {
        $pdo = self::pdo($pdo);
        if ($campaignId <= 0 || !self::tableReady($pdo, 'sequence_events')) {
            return;
        }
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO sequence_events (campaign_id, lead_id, send_id, event_type, detail) " .
                "VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $campaignId,
                $leadId,
                $sendId,
                mb_substr($eventType, 0, 48),
                $detail !== null ? mb_substr($detail, 0, 65535) : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[SequenceManager] timeline event write failed: ' . $e->getMessage());
        }
    }

    // ── Template rendering ───────────────────────────────────────────────

    /**
     * Substitute personalization tokens in a template.
     * Tokens: {{contact_name}}, {{first_name}}, {{company_name}}, {{email}}.
     *
     * @return array{0:string,1:string} [subject, body]
     */
    public static function renderTemplate(string $subject, string $body, array $lead): array
    {
        $contact = trim((string)($lead['contact_name'] ?? ''));
        $first = $contact !== '' ? (string)strtok($contact, " \t") : '';
        $tokens = [
            '{{contact_name}}' => $contact,
            '{{first_name}}'   => $first,
            '{{company_name}}' => trim((string)($lead['company_name'] ?? '')),
            '{{email}}'        => trim((string)($lead['email'] ?? '')),
        ];
        return [strtr($subject, $tokens), strtr($body, $tokens)];
    }

    // ── Stats ────────────────────────────────────────────────────────────

    /**
     * Per-campaign sequence aggregates for api/stats.php.
     * Returns [] when the Phase-4 tables are not installed.
     *
     * @return list<array<string,mixed>>
     */
    public static function campaignStats(?\App\PDO $pdo): array
    {
        $pdo = self::pdo($pdo);
        if (!self::tableReady($pdo, 'sequence_sends')) {
            return [];
        }
        try {
            $stmt = $pdo->query(
                "SELECT c.id, c.name, " .
                "(SELECT COUNT(*) FROM sequence_enrollments e WHERE e.campaign_id = c.id) AS enrolled, " .
                "(SELECT COUNT(*) FROM sequence_enrollments e WHERE e.campaign_id = c.id AND e.status = 'active') AS active, " .
                "(SELECT COUNT(*) FROM sequence_enrollments e WHERE e.campaign_id = c.id AND e.status = 'completed') AS completed, " .
                "(SELECT COUNT(*) FROM sequence_enrollments e WHERE e.campaign_id = c.id AND e.status LIKE 'stopped\\_%') AS stopped, " .
                "(SELECT COUNT(*) FROM sequence_enrollments e WHERE e.campaign_id = c.id AND e.status = 'stopped_unsubscribe') AS stopped_unsubscribe, " .
                "(SELECT COUNT(*) FROM sequence_enrollments e WHERE e.campaign_id = c.id AND e.status = 'stopped_bounce') AS stopped_bounce, " .
                "(SELECT COUNT(*) FROM sequence_sends s WHERE s.campaign_id = c.id AND s.status = 'queued') AS queued, " .
                "(SELECT COUNT(*) FROM sequence_sends s WHERE s.campaign_id = c.id AND s.status IN ('sent', 'simulated')) AS sent, " .
                "(SELECT COUNT(*) FROM sequence_sends s WHERE s.campaign_id = c.id AND s.status = 'failed') AS failed, " .
                "(SELECT COALESCE(SUM(s.open_count), 0) FROM sequence_sends s WHERE s.campaign_id = c.id) AS opens, " .
                "(SELECT COUNT(*) FROM sequence_events v WHERE v.campaign_id = c.id AND v.event_type = 'replied') AS replies " .
                "FROM campaigns c ORDER BY c.id ASC"
            );
            $rows = $stmt->fetchAll(\App\PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                foreach (['enrolled','active','completed','stopped','stopped_unsubscribe','stopped_bounce','queued','sent','failed','opens','replies'] as $k) {
                    $row[$k] = (int)$row[$k];
                }
                $sent = $row['sent'];
                $row['open_rate'] = $sent > 0 ? round($row['opens'] / $sent, 4) : 0.0;
                $row['reply_rate'] = $sent > 0 ? round($row['replies'] / $sent, 4) : 0.0;
            }
            return $rows;
        } catch (\Throwable $e) {
            error_log('[SequenceManager] campaignStats failed: ' . $e->getMessage());
            return [];
        }
    }
}
