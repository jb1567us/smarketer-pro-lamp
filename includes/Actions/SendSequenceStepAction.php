<?php

declare(strict_types=1);

namespace App\Actions;

use App\Compliance;
use App\Database;
use App\EmailSender;
use App\Exceptions\OutreachException;
use App\SequenceManager;

/**
 * SendSequenceStepAction — executes one `SequenceSend` task_queue row
 * (Phase 4). This is the per-step send worker behind launched campaigns.
 *
 * Per execution:
 *   1. Loads its own claimed task row and the sequence_sends payload
 *      (idempotent: an already-sent/simulated send returns true).
 *   2. Re-validates the enrollment is still active and the lead is still
 *      mailable (a reply/unsubscribe/bounce that landed while the task was
 *      queued cancels the send instead of failing it).
 *   3. Runs the compliance gates FIRST on every path — suppression list,
 *      sender identity, CASL country gate, email verification gate
 *      (Compliance::requireCompliantSend throws OutreachException =
 *      permanent failure).
 *   4. Renders the step's template (personalization tokens + open-tracking
 *      pixel for HTML bodies), then:
 *        - Safety/Simulation/Simulated operational_mode: NOTHING leaves the
 *          server. The send is logged to email_logs as provider_id
 *          'safety_simulated' and the sequence still progresses, so tests
 *          and dry runs exercise the full pipeline with zero real sends.
 *        - Production: EmailSender::send() (15s HTTP timeouts; the send
 *          choke point appends the CAN-SPAM footer + List-Unsubscribe
 *          headers on every message, all providers).
 *   5. On success the sequence progresses (step N+1 queued per the sent
 *      template's delay_days; enrollment completes after the last step).
 *   6. Transient provider failures (timeouts, 5xx, 429/rate limits, DNS,
 *      connection resets) requeue the task as Pending with 15/60/240-min
 *      backoff (up to 3 attempts); permanent failures mark the send failed.
 */
class SendSequenceStepAction extends AbstractAction implements TaskAware
{
    /**
     * operational_mode values that forbid any real send (case-insensitive).
     * Mirrors the WS2 EmailOutreachAction safety contract.
     */
    private const SAFETY_MODES = ['safety', 'simulation', 'simulated'];

    /** Classifies a provider error as transient (retryable). */
    private const TRANSIENT_PATTERN = '/timeout|timed out|5\d{2}\b|429|rate.?limit|temporar|connection (reset|refused|timed)|socket|dns|econn/i';

    /** Backoff minutes per attempt for transient failures. */
    private const RETRY_BACKOFF_MIN = [15, 60, 240];

    private const MAX_RETRIES = 3;

    /** Exact task_queue row being executed (injected by TaskProcessor). */
    private ?int $taskId = null;

    public function setTaskId(int $taskId): void
    {
        $this->taskId = $taskId;
    }

    public function execute(int $leadId): bool
    {
        // Load our own claimed task row. TaskProcessor injects the exact row
        // ID (TaskAware); without it we fall back to the lead-based lookup,
        // which is only unambiguous when a lead has one In Progress send.
        $task = null;
        if ($this->taskId !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT id, payload, retry_count FROM task_queue WHERE id = ? AND status = 'In Progress'"
            );
            $stmt->execute([$this->taskId]);
            $task = $stmt->fetch(\App\PDO::FETCH_ASSOC);
        }
        if (!$task) {
            $stmt = $this->pdo->prepare(
                "SELECT id, payload, retry_count FROM task_queue " .
                "WHERE lead_id = ? AND task_type = 'SequenceSend' AND status = 'In Progress' " .
                "ORDER BY scheduled_at ASC LIMIT 1"
            );
            $stmt->execute([$leadId]);
            $task = $stmt->fetch(\App\PDO::FETCH_ASSOC);
        }
        if (!$task) {
            throw new OutreachException("No claimed SequenceSend task found for lead {$leadId}.");
        }
        $taskId = (int)$task['id'];
        $retryCount = (int)($task['retry_count'] ?? 0);

        $payload = json_decode((string)($task['payload'] ?? ''), true);
        $sendId = (int)($payload['sequence_send_id'] ?? 0);
        if ($sendId <= 0) {
            throw new OutreachException(
                "SequenceSend task {$taskId} payload is missing sequence_send_id."
            );
        }

        try {
            $this->doSend($taskId, $sendId, $leadId);
            return true;
        } catch (OutreachException $e) {
            // Permanent: compliance refusal, bad data, unsupported provider.
            SequenceManager::sendFailed($this->pdo, $sendId, $e->getMessage(), true);
            throw $e;
        } catch (\Throwable $e) {
            if ($this->isTransient($e) && $retryCount < self::MAX_RETRIES) {
                $this->requeueTransient($taskId, $sendId, $retryCount, $e->getMessage());
                return true;
            }
            SequenceManager::sendFailed($this->pdo, $sendId, $e->getMessage(), true);
            throw new OutreachException(
                "SequenceSend task {$taskId} failed: " . $e->getMessage(), 0, $e
            );
        }
    }

    private function doSend(int $taskId, int $sendId, int $leadId): void
    {
        $sstmt = $this->pdo->prepare("SELECT * FROM sequence_sends WHERE id = ?");
        $sstmt->execute([$sendId]);
        $send = $sstmt->fetch(\App\PDO::FETCH_ASSOC);
        if (!$send) {
            throw new OutreachException("SequenceSend task {$taskId} references missing send #{$sendId}.");
        }
        // Idempotent: an already-completed send is a no-op, never a failure.
        if (in_array($send['status'] ?? '', ['sent', 'simulated'], true)) {
            return;
        }
        if (in_array($send['status'] ?? '', ['cancelled', 'skipped'], true)) {
            return;
        }

        $ustmt = $this->pdo->prepare(
            "UPDATE sequence_sends SET status = 'sending' WHERE id = ? AND status IN ('queued', 'sending')"
        );
        $ustmt->execute([$sendId]);

        // The enrollment may have been stopped (reply/unsubscribe/bounce)
        // while this task sat in the queue — cancel, don't fail.
        $estmt = $this->pdo->prepare("SELECT status FROM sequence_enrollments WHERE id = ?");
        $estmt->execute([(int)$send['enrollment_id']]);
        $enrollment = $estmt->fetch(\App\PDO::FETCH_ASSOC);
        if (!$enrollment || ($enrollment['status'] ?? '') !== 'active') {
            $this->markSkipped($sendId, 'enrollment is ' . ($enrollment['status'] ?? 'missing'));
            return;
        }

        $lstmt = $this->pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $lstmt->execute([$leadId]);
        $lead = $lstmt->fetch(\App\PDO::FETCH_ASSOC);
        if (!$lead) {
            throw new OutreachException("Lead {$leadId} no longer exists.");
        }
        $to = strtolower(trim((string)($lead['email'] ?? '')));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new OutreachException("Lead {$leadId} has no valid email address.");
        }
        // Fail-closed send-time gate: the lead must be in an explicitly
        // sendable status (SequenceManager::SENDABLE_LEAD_STATUSES). A lead
        // that flipped to 'Needs Review' — or Converted / Unqualified, or any
        // unknown status — after enrollment is skipped, never mailed.
        if (!in_array($lead['status'] ?? '', SequenceManager::SENDABLE_LEAD_STATUSES, true)) {
            $this->markSkipped($sendId, "lead status is {$lead['status']}");
            return;
        }

        $tstmt = $this->pdo->prepare(
            "SELECT * FROM templates WHERE campaign_id = ? AND step_order = ? LIMIT 1"
        );
        $tstmt->execute([(int)$send['campaign_id'], (int)$send['step_order']]);
        $template = $tstmt->fetch(\App\PDO::FETCH_ASSOC);
        if (!$template) {
            throw new OutreachException(
                "Template for campaign {$send['campaign_id']} step {$send['step_order']} no longer exists."
            );
        }

        // ── Compliance gates FIRST, on every path (throws = permanent) ──
        Compliance::requireCompliantSend($to, $lead);

        [$subject, $body] = SequenceManager::renderTemplate(
            (string)($template['subject'] ?? ''),
            (string)($template['body'] ?? ''),
            $lead
        );

        // Open-tracking pixel on HTML bodies only (never inside plain text).
        if (strpos($body, '<') !== false) {
            $pixel = SequenceManager::trackingPixel($this->pdo, $sendId);
            if ($pixel !== '') {
                $body .= "\n" . $pixel;
            }
        }

        // Persist the rendered content (what the recipient actually got).
        $pstmt = $this->pdo->prepare("UPDATE sequence_sends SET subject = ?, body = ? WHERE id = ?");
        $pstmt->execute([mb_substr($subject, 0, 500), $body, $sendId]);

        // ── Safety mode: log everything, send nothing ──
        $mode = strtolower(trim((string)Database::getSetting('operational_mode', 'Production')));
        if (in_array($mode, self::SAFETY_MODES, true)) {
            $this->logEmailLog($to, $send, 'safety_simulated', 'queued', $mode);
            SequenceManager::event($this->pdo, (int)$send['campaign_id'], $leadId, $sendId,
                'simulated', "Step {$send['step_order']} simulated (operational_mode={$mode}); no email left the server");
            SequenceManager::sendSimulated($this->pdo, $sendId);
            return;
        }

        // ── Phase 4 send gate (sibling subject): final JEV go/no-go. Guarded
        // by class_exists so this action works standalone. In off/shadow
        // modes the gate only evaluates/logs; in live mode a denial is a
        // permanent failure. Never runs in Safety/Simulated mode (above).
        $this->runSendGate($leadId, $to, $subject, $body, (int)$send['campaign_id']);

        // Last-moment enrollment recheck: a reply/unsubscribe/bounce that
        // landed after the first check must not race into one final live
        // follow-up. Compliance is re-checked too (suppression could have
        // arrived in the same window); EmailSender::send re-checks the rest.
        $rstmt = $this->pdo->prepare("SELECT status FROM sequence_enrollments WHERE id = ?");
        $rstmt->execute([(int)$send['enrollment_id']]);
        $late = $rstmt->fetch(\App\PDO::FETCH_ASSOC);
        if (!$late || ($late['status'] ?? '') !== 'active') {
            $this->markSkipped($sendId, 'enrollment stopped before live send');
            return;
        }
        Compliance::requireCompliantSend($to, $lead);

        // ── Live path ──
        $provider = (string)(Database::getSetting('active_email_provider', 'smtp') ?: 'smtp');
        if ($provider === 'smart_rotation') {
            throw new OutreachException(
                "Sequence sends require a single email provider; 'smart_rotation' is not supported here. " .
                'Pick one provider in System Settings before launching.'
            );
        }
        $senderEmail = (string)(Database::getSetting('email_sender', '') ?: '');
        $apiKey = Database::getSetting($provider . '_api_key');
        if ($apiKey === null || $apiKey === '') {
            $smtpUser = (string)(Database::getSetting('smtp_user', '') ?: '');
            $smtpPass = (string)(Database::getSetting('smtp_pass', '') ?: '');
            $apiKey = in_array($provider, ['smtp', 'custom_smtp', 'sendpulse', 'amazon_ses', 'zoho_smtp', 'netcore_smtp'], true)
                ? $smtpPass : $smtpUser;
        }

        // EmailSender::send is the compliance choke point: it re-checks
        // suppression/CASL/verification, appends the CAN-SPAM footer and
        // List-Unsubscribe headers, and enforces HTTP timeouts (15s) on the
        // REST providers (SMTP connect 15s + read timeout).
        $sent = EmailSender::send($to, $subject, $body, $provider, (string)$apiKey, $senderEmail);
        if (!$sent) {
            throw new \RuntimeException("Email provider '{$provider}' returned failure for {$to}.");
        }

        $this->logEmailLog($to, $send, $provider, 'sent', $mode);
        SequenceManager::sendSucceeded($this->pdo, $sendId);
    }

    private function markSkipped(int $sendId, string $reason): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sequence_sends SET status = 'skipped', error_message = ? WHERE id = ?"
        );
        $stmt->execute(['Skipped: ' . mb_substr($reason, 0, 500), $sendId]);

        $sstmt = $this->pdo->prepare("SELECT campaign_id, lead_id, step_order FROM sequence_sends WHERE id = ?");
        $sstmt->execute([$sendId]);
        $send = $sstmt->fetch(\App\PDO::FETCH_ASSOC);
        if ($send) {
            SequenceManager::event($this->pdo, (int)$send['campaign_id'], (int)$send['lead_id'], $sendId,
                'skipped', "Step {$send['step_order']} skipped: {$reason}");
        }
    }

    private function isTransient(\Throwable $e): bool
    {
        return (bool)preg_match(self::TRANSIENT_PATTERN, $e->getMessage());
    }

    /**
     * Phase 4 send gate (sibling subject's SendGateAction): final go/no-go
     * before a live send. Off/shadow modes evaluate and log only; live-mode
     * denial throws OutreachException (permanent). Guarded by class_exists so
     * this action runs standalone; gate failures outside live mode never
     * change behavior (logged, send continues).
     */
    private function runSendGate(int $leadId, string $to, string $subject, string $body, int $campaignId): void
    {
        if (!class_exists(\App\Actions\SendGateAction::class)) {
            return;
        }
        try {
            $gateAction = new \App\Actions\SendGateAction($this->pdo, $this->llmRouter);
            $gate = $gateAction->gate($leadId, [
                'subject'     => $subject,
                'body'        => $body,
                'campaign_id' => $campaignId,
            ]);
            $live = class_exists(\App\Jev\DecisionTier::class)
                && \App\Jev\DecisionTier::mode() === 'live';
            if ($live && !($gate['allowed'] ?? false)) {
                throw new OutreachException(
                    'Send gate denied: ' . implode('; ', $gate['blockers'] ?? ['denied'])
                );
            }
        } catch (OutreachException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[SendSequenceStepAction] send gate evaluation failed: ' . $e->getMessage());
        }
    }

    /**
     * Transient provider failure: keep the send queued and push the task out
     * with backoff instead of failing it. The worker picks it up on the next
     * tick and retries the send.
     */
    private function requeueTransient(int $taskId, int $sendId, int $retryCount, string $message): void
    {
        $backoff = self::RETRY_BACKOFF_MIN[min($retryCount, count(self::RETRY_BACKOFF_MIN) - 1)];
        $note = 'Transient provider failure (attempt ' . ($retryCount + 1) . '/' . self::MAX_RETRIES .
            ', retry in ' . $backoff . 'm): ' . mb_substr($message, 0, 500);

        $stmt = $this->pdo->prepare(
            "UPDATE task_queue SET status = 'Pending', scheduled_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), " .
            "error_message = ? WHERE id = ?"
        );
        $stmt->execute([$backoff, $note, $taskId]);

        SequenceManager::sendFailed($this->pdo, $sendId, $note, false);
    }

    private function logEmailLog(string $to, array $send, string $providerId, string $status, string $mode): void
    {
        try {
            $meta = json_encode([
                'subject'    => mb_substr((string)($send['subject'] ?? ''), 0, 200),
                'send_id'    => (int)$send['id'],
                'step_order' => (int)$send['step_order'],
                'sequence'   => true,
                'mode'       => $mode,
            ]);
            $stmt = $this->pdo->prepare(
                "INSERT INTO email_logs (lead_email, provider_id, campaign_id, status, metadata_json, timestamp) " .
                "VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$to, $providerId, (int)$send['campaign_id'], $status, $meta, time()]);
        } catch (\Throwable $e) {
            // Logging must never fail a send.
            error_log('[SendSequenceStepAction] email_logs write failed: ' . $e->getMessage());
        }
    }
}
