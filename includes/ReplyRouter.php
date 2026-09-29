<?php

declare(strict_types=1);

namespace App;

/**
 * ReplyRouter — Phase 3 reply-verdict routing layer.
 *
 * Consumes reply-classification verdicts shaped like:
 *   ['intent','needs_human','urgency','confidence','source','latency_ms']
 * (produced by the sibling ClassifyReplyAction; designed against that
 * documented shape only — this file never instantiates the classifier).
 *
 * Routes each verdict to a DB-backed outcome using ONLY existing schema:
 *   - agent_traces      : audit trail of every routing decision (lead_id may be NULL)
 *   - leads.notes       : appended "[REPLY ROUTER ...]" markers, never overwritten
 *   - leads.status      : ENUM('New','Enriched','Contacted','Qualified',
 *                         'Unqualified','Converted','Drafted','Needs Review')
 *                         — see §"status gaps"
 *   - Compliance::suppress(): unsubscribe/hostile suppression writes
 *
 * Guardrails:
 *   - Never auto-sends anything. This layer only writes DB rows and calls
 *     Compliance::suppress(); no email leaves the system from here.
 *   - Missing leadId/email routes conservatively to human review (fail-closed),
 *     never silently skipped.
 *   - A classifier-set needs_human flag or confidence below
 *     LOW_CONFIDENCE_THRESHOLD forces human review regardless of intent —
 *     except COMPLIANCE_FAST_LANE_INTENTS (unsubscribe/hostile) with a
 *     heuristic verdict, which stay auto-suppressed (fail-safe direction).
 *   - All DB logging is best-effort (wrapped in try/catch) so a logging
 *     failure can never break the routing decision itself.
 *
 * STATUS GAPS (no new tables created — Phase 4 candidates):
 *   1. No 'nurture' lead status exists. not_now uses 'Contacted' (closest fit:
 *      a lead we have engaged) and records the +90d follow-up date in the
 *      lead's notes. Recommended: ALTER leads ADD 'Nurture' to the status
 *      ENUM + add a nullable next_action_at TIMESTAMP column so the +90d
 *      re-queue becomes an actual scheduled item instead of a note.
 *   2. No dedicated human-review queue table exists. Human-needed outcomes are
 *      recorded as agent_traces rows (persona='ReplyRouter', goal='reply_review')
 *      plus a "[REPLY ROUTER] HUMAN REVIEW" marker appended to leads.notes.
 *      The drafts table's 'needs_human' queue covers drafts only, not replies.
 *      Recommended: a small reply_review_queue table or a shared
 *      human_review_queue table keyed by entity type/id.
 *   3. No 'bounced'/'archived' lead status exists. bounce/out_of_office are
 *      auto-handled silently with notes + traces only; hostile uses
 *      'Unqualified' (closest existing terminal status) plus a suppression
 *      entry so the address is never mailed again. Recommended: add
 *      'Archived'/'Bounced' to the leads status ENUM.
 *   4. task_queue is the cron background-task queue with a closed
 *      task_type ENUM ('Enrichment','EmailOutreach','SocialOutreach',
 *      'Qualify','Enrich','Draft') — it is NOT a human-follow-up queue and
 *      must not be abused as one. Human follow-ups stay as trace+note above.
 */
class ReplyRouter
{
    /** Below this classifier confidence, the intent is not trusted. */
    public const LOW_CONFIDENCE_THRESHOLD = 0.55;

    /**
     * Intents that bypass Guardrail 2's confidence gate when the verdict
     * comes from the heuristic fallback (JEV off). Explicit opt-out / abuse
     * phrases ('unsubscribe', 'remove me', 'screw you', ...) are
     * high-precision keyword matches, and suppression is the fail-safe
     * direction: a false positive loses one contact (recoverable), while a
     * missed unsubscribe risks mailing an opted-out address. They still get
     * a human-review trace entry so the owner sees them.
     */
    public const COMPLIANCE_FAST_LANE_INTENTS = ['unsubscribe', 'hostile'];

    /** Trace persona used for the human-review queue entries and audit rows. */
    public const TRACE_PERSONA = 'ReplyRouter';

    public function __construct(
        protected \App\PDO $pdo
    ) {}

    /**
     * Route one reply-classification verdict to a DB-backed outcome.
     *
     * @param array       $verdict Verdict from ClassifyReplyAction:
     *                             ['intent','needs_human','urgency','confidence',
     *                              'source','latency_ms']
     * @param int|null    $leadId  leads.id, if known.
     * @param string|null $email   Replying address, if known.
     *
     * @return array Pure-data result for the UI layer:
     *               ['action'=>string, 'intent'=>string, 'detail'=>string,
     *                'lead_id'=>?int, 'routed_to_human'=>bool]
     */
    public function route(array $verdict, ?int $leadId, ?string $email): array
    {
        $intent     = strtolower(trim((string)($verdict['intent'] ?? 'other')));
        $needsHuman = (bool)($verdict['needs_human'] ?? false);
        $confidence = (float)($verdict['confidence'] ?? 0.0);
        $email      = $email !== null ? strtolower(trim($email)) : null;
        if ($email === '') {
            $email = null;
        }

        // Resolve a lead id from the email when only the email is known
        // (leads.email is UNIQUE, so this is unambiguous).
        if ($leadId === null && $email !== null) {
            $leadId = $this->findLeadIdByEmail($email);
        }

        $guardrailNote = null;

        // Guardrail 1: missing identity — route conservatively to human review.
        if ($leadId === null || $email === null) {
            $guardrailNote = 'missing ' . ($leadId === null ? 'lead id' : '') .
                ($leadId === null && $email === null ? ' and ' : '') .
                ($email === null ? 'email' : '') .
                '; fail-closed to human review';
            $this->recordHumanReview($leadId, $email, $verdict, $guardrailNote);
            $detail = "Verdict intent '{$intent}' could not be auto-routed ({$guardrailNote}). " .
                'Logged to the human-review queue; no automated action taken.';
            $this->audit($leadId, $intent, 'human_review', $detail, $verdict);
            return $this->result('human_review', $intent, $detail, $leadId, true);
        }

        // Compliance fast-lane (see COMPLIANCE_FAST_LANE_INTENTS): explicit
        // opt-out / abuse keyword matches stay auto-suppressed even when JEV
        // is off, with a human-review trace entry for owner visibility.
        $complianceFastLane = in_array($intent, self::COMPLIANCE_FAST_LANE_INTENTS, true)
            && ($verdict['source'] ?? '') === 'heuristic';
        if ($complianceFastLane) {
            $this->recordHumanReview($leadId, $email, $verdict, "heuristic '{$intent}': compliance fast-lane, address suppressed");
        }

        // Guardrail 2: classifier asked for a human, or its confidence is too low.
        if (($needsHuman || $confidence < self::LOW_CONFIDENCE_THRESHOLD) && !$complianceFastLane) {
            $reason = $needsHuman
                ? 'classifier flagged needs_human'
                : sprintf('low confidence (%.2f < %.2f)', $confidence, self::LOW_CONFIDENCE_THRESHOLD);
            $this->recordHumanReview($leadId, $email, $verdict, $reason);
            $detail = "Intent '{$intent}' routed to human review ({$reason}). No automated action taken.";
            $this->audit($leadId, $intent, 'human_review', $detail, $verdict);
            return $this->result('human_review', $intent, $detail, $leadId, true);
        }

        switch ($intent) {
            case 'positive':
                // Positive reply = strong fit signal. Closest existing status is
                // 'Qualified'; the owner must follow up personally — we only
                // queue that work for a human, we never draft or send.
                $this->setLeadStatus($leadId, 'Qualified');
                $this->appendNote($leadId, '[REPLY ROUTER] Positive reply from ' . $email .
                    ' — owner follow-up needed. No automated reply sent.');
                $this->recordHumanReview($leadId, $email, $verdict, 'positive reply: owner follow-up task');
                $detail = "Positive reply from {$email}. Lead marked Qualified and queued " .
                    'for owner follow-up (human review queue). Nothing sent automatically.';
                $this->audit($leadId, $intent, 'human_follow_up', $detail, $verdict);
                return $this->result('human_follow_up', $intent, $detail, $leadId, true);

            case 'unsubscribe':
                // Hard compliance path: suppression first, then mark the lead
                // row so list views reflect it (mirrors unsubscribe.php).
                Compliance::suppress($email, 'unsubscribe', 'reply_classifier');
                $this->setLeadStatus($leadId, 'Unqualified');
                $this->setConsentUnknown($leadId);
                $this->appendNote($leadId, '[REPLY ROUTER] Unsubscribe via reply — address suppressed (source: reply_classifier).');
                $detail = "Unsubscribe from {$email}: added to suppression_list (reason 'unsubscribe', source 'reply_classifier'), " .
                    'lead marked Unqualified.';
                $this->audit($leadId, $intent, 'suppressed', $detail, $verdict);
                return $this->result('suppressed', $intent, $detail, $leadId, false);

            case 'not_now':
                // No 'nurture' status in the leads ENUM (schema gap #1), so use
                // 'Contacted' (engaged but not converted) and stamp the +90d
                // follow-up target in the notes — no next_action_at column
                // exists to schedule against.
                $followUp = date('Y-m-d', strtotime('+90 days'));
                $this->setLeadStatus($leadId, 'Contacted');
                $this->appendNote($leadId, '[REPLY ROUTER] "Not now" reply from ' . $email .
                    " — re-contact target {$followUp} (+90d). NOTE: no nurture status or " .
                    'next_action_at column exists; follow-up is recorded in notes only.');
                $detail = "Not-now reply from {$email}: lead set to Contacted with re-contact target {$followUp} " .
                    '(recorded in lead notes; no nurture scheduling column exists yet).';
                $this->audit($leadId, $intent, 'nurtured', $detail, $verdict);
                return $this->result('nurtured', $intent, $detail, $leadId, false);

            case 'hostile':
                // Closest existing terminal status is 'Unqualified'; additionally
                // suppress the address so no future send can inflame things.
                Compliance::suppress($email, 'hostile', 'reply_classifier');
                $this->setLeadStatus($leadId, 'Unqualified');
                $this->appendNote($leadId, '[REPLY ROUTER] Hostile reply from ' . $email .
                    ' — lead archived as Unqualified and address suppressed (reason: hostile).');
                $detail = "Hostile reply from {$email}: lead archived (Unqualified) and address suppressed " .
                    'to block all future sends.';
                $this->audit($leadId, $intent, 'archived', $detail, $verdict);
                return $this->result('archived', $intent, $detail, $leadId, false);

            case 'bounce':
            case 'out_of_office':
                // Auto-handled silently: no 'bounced'/'ooo' lead status exists
                // (schema gap #3), so record in notes + trace only. Status is
                // left untouched — a bounce is not a qualification signal.
                $label = $intent === 'bounce' ? 'Bounce' : 'Out-of-office auto-reply';
                $this->appendNote($leadId, "[REPLY ROUTER] {$label} from " . $email .
                    ' — auto-handled silently, no status change.');
                $detail = "{$label} from {$email}: logged and auto-handled silently (lead status unchanged).";
                $this->audit($leadId, $intent, 'auto_handled', $detail, $verdict);
                return $this->result('auto_handled', $intent, $detail, $leadId, false);

            case 'objection':
            case 'referral':
            case 'other':
            default:
                // Nuance a machine must not answer alone — human review queue.
                $this->recordHumanReview($leadId, $email, $verdict, "intent '{$intent}' requires human judgment");
                $detail = "Intent '{$intent}' from {$email}: queued for human review. No automated action taken.";
                $this->audit($leadId, $intent, 'human_review', $detail, $verdict);
                return $this->result('human_review', $intent, $detail, $leadId, true);
        }
    }

    /**
     * Shape the pure-data result the UI agent displays.
     */
    private function result(string $action, string $intent, string $detail, ?int $leadId, bool $routedToHuman): array
    {
        return [
            'action'          => $action,
            'intent'          => $intent,
            'detail'          => $detail,
            'lead_id'         => $leadId,
            'routed_to_human' => $routedToHuman,
        ];
    }

    /**
     * Record a human-review queue entry: an agent_traces row (the only
     * workable queue surface that exists — schema gap #2) plus a marker in
     * the lead's notes when the lead is known. Never throws.
     */
    private function recordHumanReview(?int $leadId, ?string $email, array $verdict, string $reason): void
    {
        $summary = sprintf(
            "HUMAN REVIEW needed | reason: %s | email: %s | intent: %s | urgency: %s | confidence: %.2f | source: %s | latency_ms: %s",
            $reason,
            $email ?? '(unknown)',
            (string)($verdict['intent'] ?? 'other'),
            (string)($verdict['urgency'] ?? 'unknown'),
            (float)($verdict['confidence'] ?? 0.0),
            (string)($verdict['source'] ?? 'unknown'),
            (string)($verdict['latency_ms'] ?? 'unknown')
        );
        if ($leadId !== null) {
            $this->appendNote($leadId, '[REPLY ROUTER] ' . $summary);
        }
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO agent_traces (lead_id, persona, goal, context, reasoning_output, operational_mode) " .
                "VALUES (?, 'ReplyRouter', 'reply_review', ?, ?, 'Production')"
            );
            $stmt->execute([$leadId, $summary, json_encode($verdict)]);
        } catch (\Throwable $e) {
            error_log('[ReplyRouter] human-review trace write failed: ' . $e->getMessage());
        }
        error_log('[ReplyRouter] ' . $summary);
    }

    /**
     * Audit trail for EVERY routing decision (agent_traces, best-effort).
     * Never throws.
     */
    private function audit(?int $leadId, string $intent, string $action, string $detail, array $verdict): void
    {
        $line = sprintf(
            '[ReplyRouter] intent=%s action=%s lead_id=%s detail=%s',
            $intent,
            $action,
            $leadId === null ? 'null' : (string)$leadId,
            $detail
        );
        error_log($line);
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO agent_traces (lead_id, persona, goal, context, reasoning_output, operational_mode) " .
                "VALUES (?, 'ReplyRouter', 'reply_routing_audit', ?, ?, 'Production')"
            );
            $stmt->execute([$leadId, $line, json_encode($verdict)]);
        } catch (\Throwable $e) {
            error_log('[ReplyRouter] audit trace write failed: ' . $e->getMessage());
        }
    }

    /**
     * Append a timestamped marker to leads.notes (never overwrite — Phase 0
     * fixed the overwrite bug). Best-effort, never throws.
     */
    private function appendNote(int $leadId, string $note): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE leads SET notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ?"
            );
            $stmt->execute(["\n\n" . date('Y-m-d H:i') . " {$note}", $leadId]);
        } catch (\Throwable $e) {
            error_log('[ReplyRouter] notes append failed for lead ' . $leadId . ': ' . $e->getMessage());
        }
    }

    /**
     * Set leads.status. Statuses used are restricted to the existing ENUM:
     * New, Enriched, Contacted, Qualified, Unqualified, Converted, Drafted.
     * Best-effort, never throws.
     */
    private function setLeadStatus(int $leadId, string $status): void
    {
        try {
            $stmt = $this->pdo->prepare("UPDATE leads SET status = ? WHERE id = ?");
            $stmt->execute([$status, $leadId]);
        } catch (\Throwable $e) {
            error_log('[ReplyRouter] status update failed for lead ' . $leadId . ': ' . $e->getMessage());
        }
    }

    /**
     * Mirror unsubscribe.php's "belt and braces" lead-row marking for opt-outs.
     * Best-effort, never throws.
     */
    private function setConsentUnknown(int $leadId): void
    {
        try {
            $stmt = $this->pdo->prepare("UPDATE leads SET consent_status = 'unknown' WHERE id = ?");
            $stmt->execute([$leadId]);
        } catch (\Throwable $e) {
            error_log('[ReplyRouter] consent-status mark failed for lead ' . $leadId . ': ' . $e->getMessage());
        }
    }

    /**
     * Resolve a lead id from the replying email (leads.email is UNIQUE).
     * Returns null when unknown. Never throws.
     */
    private function findLeadIdByEmail(string $email): ?int
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM leads WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
            return $row ? (int)$row['id'] : null;
        } catch (\Throwable $e) {
            error_log('[ReplyRouter] lead lookup failed for ' . $email . ': ' . $e->getMessage());
            return null;
        }
    }
}
