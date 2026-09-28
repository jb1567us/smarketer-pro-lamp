<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jev\DecisionTier;
use App\Jev\JevProvider;
use App\Exceptions\OutreachException;

/**
 * FollowUpTimingAction — Phase 4 decision point: decide when the next touch
 * should go (delay window / skip), wired to lead + campaign state.
 *
 * There is no legacy LLM timing path to preserve — this action is JEV-native
 * with a deterministic cadence fallback, mirroring ClassifyReplyAction's
 * design. The fallback codifies the standing cadence:
 *
 *   skip when: lead is Converted/Unqualified, campaign is missing/inactive/
 *              paused, or the lead has already received MAX_TOUCHES sends;
 *   otherwise: touch 1 -> 7 days, touch 2 -> 14 days, touches 3-4 -> 30 days.
 *
 * State wired in (all from the database, no LLM needed to gather it):
 *   lead:   status, lead_score, contact/company for context
 *   campaign: is_active, status, daily_send_cap, paused_reason
 *   history: touch count + last touch timestamp from email_logs,
 *            days since last touch
 *
 * Modes (same contract as every other JEV decision point):
 *   off    — cadence fallback returned (source 'rules', confidence 0.0).
 *   shadow — fallback returned; JEV's choice is logged to the shadow log by
 *            DecisionTier::decide. Zero behavior change.
 *   live   — the JEV choice is returned. JEV error/timeout/low confidence
 *            fails soft to the cadence fallback (never throws).
 *
 * Live-mode scheduling is DELIBERATELY not wired here: there is no
 * Send/EmailOutreach action in TaskProcessor's factory yet, so an enqueued
 * follow-up task would fail with "Unknown task type". The decision is
 * returned to the caller (a future send scheduler or the queue worker);
 * decide() is pure and side-effect free. See the Phase 4 notes.
 */
class FollowUpTimingAction extends AbstractAction
{
    public const DECISION = 'follow_up_timing.decide';

    /** Hard timeout for the batched timing call (plan: <=8s). */
    public const TIMEOUT_S = 8;

    /** Delay options offered to the JEV choice question. */
    public const OPTIONS = ['skip', 'in_3_days', 'in_7_days', 'in_14_days', 'in_30_days'];

    /** Never touch a lead more than this many times in a sequence. */
    public const MAX_TOUCHES = 5;

    /** Lead statuses that end the sequence outright. */
    public const TERMINAL_STATUSES = ['Converted', 'Unqualified'];

    /**
     * ActionInterface contract. Timing is stateless and driven via decide();
     * there is no per-lead side effect to perform, so this is a deliberate
     * no-op returning false (same as ClassifyReplyAction).
     */
    public function execute(int $leadId): bool
    {
        return false;
    }

    /**
     * Decide the next touch for a lead.
     *
     * @return array{next_touch:string,delay_days:?int,skip_reason:?string,
     *               touches_so_far:int,days_since_last_touch:?int,
     *               next_touch_at:?string,confidence:float,source:string,
     *               latency_ms:int,note?:string}
     *   - next_touch: one of self::OPTIONS
     *   - delay_days: null when next_touch = 'skip'
     *   - next_touch_at: ISO date of the suggested touch (null on skip)
     *   - source: 'rules' | 'jev'; rules results carry confidence 0.0
     */
    public function decide(int $leadId): array
    {
        $state = $this->loadState($leadId);

        // Fallback (off mode / JEV failure): the deterministic cadence.
        $fallback = fn(): array => $this->cadenceFallback($state);

        $questions = [
            'next_touch' => JevProvider::choiceQuestion(
                'When should the next outreach touch go to this lead?',
                self::OPTIONS,
                self::choiceCriteria($state)
            ),
        ];

        $t0 = microtime(true);
        try {
            $raw = DecisionTier::decide(
                self::DECISION,
                $state,
                $questions,
                $fallback,
                fn($mixed) => $this->extractChoice($mixed),
                fn($jv, $lv) => $jv === $lv,
                self::TIMEOUT_S
            );
        } catch (\Throwable $e) {
            error_log('[FollowUpTimingAction] DecisionTier::decide threw: ' . $e->getMessage());
            $raw = $fallback();
        }
        $latencyMs = (int)((microtime(true) - $t0) * 1000);

        $result = $this->normalize($raw, $state);
        $result['latency_ms'] = $latencyMs;
        return $result;
    }

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------

    /**
     * Lead + campaign + touch history, in one place. All reads are
     * defensive: a missing table/column yields nulls, never an exception.
     *
     * @return array{lead_id:int,lead_status:string,lead_score:int,
     *               contact_name:string,company_name:string,
     *               campaign_id:?int,campaign_active:bool,campaign_paused:bool,
     *               campaign_cap:?int,touches_so_far:int,
     *               last_touch_at:?int,days_since_last_touch:?int}
     */
    public function loadState(int $leadId): array
    {
        $lead = $this->loadLead($leadId);
        if ($lead === null) {
            throw new OutreachException("Lead ID {$leadId} not found.");
        }

        $campaign = null;
        $campaignId = isset($lead['campaign_id']) ? (int)$lead['campaign_id'] : 0;
        if ($campaignId > 0) {
            $campaign = $this->loadCampaign($campaignId);
        }

        [$touches, $lastTouch] = $this->touchHistory((string)($lead['email'] ?? ''));
        $daysSince = $lastTouch !== null
            ? (int)floor((time() - $lastTouch) / 86400)
            : null;

        return [
            'lead_id'              => $leadId,
            'lead_status'          => (string)($lead['status'] ?? ''),
            'lead_score'           => (int)($lead['lead_score'] ?? 0),
            'contact_name'         => (string)($lead['contact_name'] ?? ''),
            'company_name'         => (string)($lead['company_name'] ?? ''),
            'campaign_id'          => $campaignId > 0 ? $campaignId : null,
            'campaign_active'      => $campaign !== null
                && (bool)($campaign['is_active'] ?? false)
                && strtolower((string)($campaign['status'] ?? 'active')) === 'active',
            'campaign_paused'      => $campaign !== null
                && ((bool)($campaign['is_active'] ?? false) === false
                    || strtolower((string)($campaign['status'] ?? '')) === 'paused'
                    || trim((string)($campaign['paused_reason'] ?? '')) !== ''),
            'campaign_cap'         => $campaign !== null && isset($campaign['daily_send_cap'])
                ? (int)$campaign['daily_send_cap'] : null,
            'touches_so_far'       => $touches,
            'last_touch_at'        => $lastTouch,
            'days_since_last_touch' => $daysSince,
        ];
    }

    // ------------------------------------------------------------------
    // Rule-based cadence fallback (the JEV-off path)
    // ------------------------------------------------------------------

    /**
     * Deterministic cadence: terminal states and dead campaigns skip;
     * otherwise the delay grows with the number of touches already sent.
     */
    public function cadenceFallback(array $state): array
    {
        $skipReason = $this->skipReason($state);
        if ($skipReason !== null) {
            return $this->buildResult('skip', null, $skipReason, $state, 0.0, 'rules');
        }

        $touches = $state['touches_so_far'];
        $delay = match (true) {
            $touches <= 0 => 3,
            $touches === 1 => 7,
            $touches === 2 => 14,
            default => 30,
        };
        return $this->buildResult(
            'in_' . $delay . '_days',
            $delay,
            null,
            $state,
            0.0,
            'rules',
            'Cadence fallback: ' . ($touches === 0 ? 'first follow-up' : "touch #{$touches} sent") . " -> +{$delay}d."
        );
    }

    /** Skip reason string, or null when the sequence may continue. */
    private function skipReason(array $state): ?string
    {
        if (in_array($state['lead_status'], self::TERMINAL_STATUSES, true)) {
            return 'lead status is ' . $state['lead_status'] . ' (terminal)';
        }
        if ($state['campaign_id'] === null) {
            return 'lead has no campaign';
        }
        if ($state['campaign_paused']) {
            return 'campaign is paused/inactive';
        }
        if (!$state['campaign_active']) {
            return 'campaign is not active';
        }
        if ($state['touches_so_far'] >= self::MAX_TOUCHES) {
            return 'max touches reached (' . self::MAX_TOUCHES . ')';
        }
        return null;
    }

    private function buildResult(
        string $nextTouch,
        ?int $delayDays,
        ?string $skipReason,
        array $state,
        float $confidence,
        string $source,
        ?string $note = null
    ): array {
        $anchor = $state['last_touch_at'] ?? time();
        return [
            'next_touch'            => $nextTouch,
            'delay_days'            => $delayDays,
            'skip_reason'           => $skipReason,
            'touches_so_far'        => $state['touches_so_far'],
            'days_since_last_touch' => $state['days_since_last_touch'],
            'next_touch_at'         => $delayDays === null
                ? null : gmdate('Y-m-d', $anchor + $delayDays * 86400),
            'confidence'            => $confidence,
            'source'                => $source,
            'latency_ms'            => 0,
            'note'                  => $note ?? ($skipReason !== null ? 'Skipped: ' . $skipReason : null),
        ];
    }

    // ------------------------------------------------------------------
    // JEV normalization
    // ------------------------------------------------------------------

    /** Choice criteria grounded in the loaded state (stable option keys). */
    private static function choiceCriteria(array $state): array
    {
        $t = (int)$state['touches_so_far'];
        $d = $state['days_since_last_touch'];
        $ago = $d === null ? 'never touched' : "{$d} day(s) since last touch";
        return [
            'skip'       => "Do not touch this lead again (terminal status, dead campaign, or {$t} touches already sent; {$ago}).",
            'in_3_days'  => 'Short delay: warm lead, first follow-up, or a time-sensitive signal.',
            'in_7_days'  => 'Standard follow-up delay after an initial touch.',
            'in_14_days' => 'Longer pause: lead has been touched before and needs space.',
            'in_30_days' => 'Re-engagement window: several touches sent; last resort before the cap.',
        ];
    }

    /** Extract the comparable choice from either a JEV answer map or a normalized result. */
    private function extractChoice($mixed): string
    {
        if (is_array($mixed) && isset($mixed['next_touch']) && is_array($mixed['next_touch'])) {
            $c = (string)($mixed['next_touch']['choice'] ?? '');
            return in_array($c, self::OPTIONS, true) ? $c : 'skip';
        }
        $c = (string)($mixed['next_touch'] ?? '');
        return in_array($c, self::OPTIONS, true) ? $c : 'skip';
    }

    /**
     * Normalize either raw JEV answers or an already-normalized result
     * (off/shadow modes, JEV errors) into one result shape. A JEV verdict is
     * sanity-clamped: JEV can never schedule a touch the cadence would skip
     * (terminal status / dead campaign / touch cap) — those return 'skip'
     * with a note instead of throwing.
     */
    private function normalize($raw, array $state): array
    {
        if (is_array($raw) && array_key_exists('next_touch', $raw)
            && !is_array($raw['next_touch'] ?? null)) {
            return $raw; // already normalized (rules fallback)
        }

        $ans = is_array($raw['next_touch'] ?? null) ? $raw['next_touch'] : [];
        $choice = (string)($ans['choice'] ?? '');
        if (!in_array($choice, self::OPTIONS, true)) {
            $choice = 'skip'; // unknown JEV choice -> safest option
        }
        $confidence = (float)($ans['confidence'] ?? 0);

        // Sanity clamp: never schedule into a state the cadence skips.
        $skipReason = $this->skipReason($state);
        if ($skipReason !== null && $choice !== 'skip') {
            return $this->buildResult(
                'skip', null, $skipReason, $state, $confidence, 'jev',
                sprintf('JEV chose %s but the cadence skips (%s); clamped to skip.', $choice, $skipReason)
            );
        }

        $delay = $choice === 'skip' ? null
            : (int)filter_var($choice, FILTER_SANITIZE_NUMBER_INT);
        return $this->buildResult(
            $choice,
            $delay === 0 ? null : $delay,
            $choice === 'skip' ? 'JEV chose skip' : null,
            $state, $confidence, 'jev',
            sprintf('JEV chose %s (confidence %.2f).', $choice, $confidence)
        );
    }

    // ------------------------------------------------------------------
    // Data access (defensive: missing tables/columns degrade to nulls)
    // ------------------------------------------------------------------

    private function loadLead(int $leadId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM leads WHERE id = ? LIMIT 1");
        $stmt->execute([$leadId]);
        $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
        return $row === false || $row === null ? null : $row;
    }

    private function loadCampaign(int $campaignId): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM campaigns WHERE id = ? LIMIT 1");
            $stmt->execute([$campaignId]);
            $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
            return $row === false || $row === null ? null : $row;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{0:int touches, 1:?int last touch unix timestamp}
     */
    private function touchHistory(string $email): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS n, MAX(timestamp) AS last_ts FROM email_logs " .
                "WHERE lead_email = ? AND status = 'sent'"
            );
            $stmt->execute([$email]);
            $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
            if (!$row) {
                return [0, null];
            }
            $n = (int)($row['n'] ?? 0);
            $last = $row['last_ts'] ?? null;
            return [$n, $last !== null && $last !== '' ? (int)$last : null];
        } catch (\Throwable $e) {
            return [0, null];
        }
    }
}
