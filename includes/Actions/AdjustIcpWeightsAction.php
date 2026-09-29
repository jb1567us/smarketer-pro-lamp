<?php

declare(strict_types=1);

namespace App\Actions;

use App\Icp\IcpProfile;

/**
 * AdjustIcpWeightsAction — the PER-BUYER engagement feedback loop for the
 * weighted ICP scoring model.
 *
 * Scope is strictly this installation's own data: engagement signals come
 * from this buyer's sequence_events, dimension scores from this buyer's
 * leads, and weight changes apply only to this buyer's active ICP profile.
 * There is deliberately NO cross-buyer pooling, no shared learning, and no
 * multi-tenant anything.
 *
 * How it works (one run):
 *   1. Load the active ICP profile (subject 1: App\Icp\IcpProfile) and its
 *      dimension weights + buyer_locked flags.
 *   2. Build a per-lead engagement signal from this buyer's own outreach
 *      history (sequence_events, last 90 days):
 *
 *          reply intent            signal
 *          ----------------        ------
 *          positive                +2.0   (strong positive)
 *          objection / referral    +1.0   (engaged but not bought)
 *          opened, no reply        +0.25  (neutral-positive)
 *          unsubscribe / hostile   -1.0   (negative)
 *          bounce / out_of_office /
 *          not_now / other /
 *          reply w/o stored verdict  0.0  (neutral)
 *          never mailed / no events  —    (excluded from the sample)
 *
 *      Reply intent comes from the 'classified' timeline events written by
 *      SequenceManager::recordReply() ("Phase-3 verdict: {json}"); a lead
 *      with only 'opened' events and no reply gets +0.25.
 *   3. Load per-lead, per-dimension fit scores. Primary source is subject 2's
 *      ScoreLeadFitAction storage: per-dimension 1-10 scores persisted in
 *      leads.notes by QualifyLeadAction::notesMarker() as
 *      "Dimensions: company_size=8/10, industry_fit=7/10, ...". The latest
 *      marker wins (notes are append-only); leads with no Dimensions line
 *      (legacy or vetoed qualifications) are not scored and do not
 *      participate. Scores are normalized to 0..1 (Pearson is
 *      scale-invariant, so the exact normalization is immaterial). When no
 *      lead carries a Dimensions marker at all, the action falls back to
 *      re-derived evidence flags from lead fields + the profile's own
 *      target_config (1.0 match / 0.0 mismatch / 0.5 unknown-or-no-evidence).
 *      The fallback is deliberately coarse: most dimensions collapse to 0.5
 *      for every lead, which yields zero variance and therefore no
 *      adjustment.
 *   4. For each UNLOCKED dimension, compute the Pearson correlation between
 *      that dimension's scores and the engagement signal across the sample.
 *      Nudge = clamp(5 * r, -5, +5) weight points (|r| < 0.05 is noise → 0).
 *   5. Renormalize the unlocked weights so the full vector (locked kept
 *      exactly) sums to exactly 100 as integers (largest-remainder).
 *   6. Persist via IcpProfile::updateWeights(..., created_by='auto_tuner'),
 *      which records one icp_weight_history row per changed dimension with
 *      reason + sample_size — the audit trail the buyer uses to review and
 *      reverse any change.
 *
 * Guards (fail-safe, conservative):
 *   - No active ICP profile (or profile class unavailable) → skip.
 *   - Fewer than MIN_ENGAGED_LEADS (10) engaged leads in the sample → skip.
 *   - All dimensions buyer_locked → skip; buyer_locked=1 is never touched.
 *   - No correlation above the noise floor → skip (no churn).
 *   - Every skip writes a "skipped: <reason>" trace to agent_traces and an
 *     error_log line; unexpected failures are logged loudly (investigate on
 *     first occurrence) and reported as status=error, never thrown into the
 *     cron worker.
 *
 * Scheduling: this is a SCHEDULED maintenance job, not real-time. The
 * canonical trigger is the daily-gated block in cron/process_queue.php (see
 * AdjustIcpWeightsAction::SETTING_LAST_RUN / RUN_INTERVAL_SECONDS), placed
 * inside the process lock next to the SendMonitor maintenance item. On
 * demand from any authenticated CLI/API context:
 *
 *     (new \App\Actions\AdjustIcpWeightsAction($pdo))->run();
 *     (new \App\Actions\AdjustIcpWeightsAction($pdo))->resetToDefaults();
 *
 * resetToDefaults() restores the seeded default weight vector on all
 * UNLOCKED dimensions (buyer_locked dimensions keep their weight — the buyer
 * override always wins) and records the reset in icp_weight_history with
 * created_by='user'.
 *
 * Interface assumptions about subject 1 (verified against
 * includes/Icp/IcpProfile.php on this branch):
 *   IcpProfile::active(): ?array{id:int,...}
 *   IcpProfile::dimensions(int $profileId): array dim => ['weight'=>int,
 *       'buyer_locked'=>bool, 'target_config'=>array]
 *   IcpProfile::updateWeights(int $profileId, array $weights, string $reason,
 *       ?int $sampleSize, bool $buyerSet, string $createdBy): void
 *     — requires every dimension present, integer weights summing to 100;
 *     writes icp_weight_history rows (reason, sample_size, created_by).
 * The three protected seam methods (resolveProfileId / loadDimensions /
 * writeWeights) wrap those calls so tests can substitute fakes without
 * touching the static gateway.
 */
class AdjustIcpWeightsAction implements ActionInterface
{
    /** Engaged-lead sample floor: below this the run is a no-op. */
    public const MIN_ENGAGED_LEADS = 10;

    /** Max absolute weight-point nudge per dimension per run. */
    public const MAX_STEP_PER_RUN = 5.0;

    /** |r| below this is treated as noise — no nudge. */
    public const CORR_NOISE_FLOOR = 0.05;

    /** Engagement lookback window (days). */
    public const LOOKBACK_DAYS = 90;

    /** Cron trigger cadence: at most one adjustment run per 24h. */
    public const RUN_INTERVAL_SECONDS = 86400;

    /** settings key holding the last run's unix timestamp. */
    public const SETTING_LAST_RUN = 'icp_weight_adjust_last_run';

    /**
     * Reply-intent → engagement signal. Intents not listed here
     * (bounce, out_of_office, not_now, other) are neutral (0.0).
     */
    public const INTENT_SIGNALS = [
        'positive'    => 2.0,
        'objection'   => 1.0,
        'referral'    => 1.0,
        'unsubscribe' => -1.0,
        'hostile'     => -1.0,
    ];

    /** Signal for "opened at least once but never replied". */
    public const OPENED_NO_REPLY_SIGNAL = 0.25;

    /**
     * Default weight vector used by resetToDefaults(), mirroring the seeded
     * default profile in migrations/2026-09-28-icp-scoring.sql.
     */
    public const DEFAULT_WEIGHTS = [
        'company_size'    => 17,
        'industry_fit'    => 17,
        'tech_stack'      => 17,
        'target_title'    => 17,
        'geography'       => 16,
        'trigger_signals' => 16,
    ];

    public function __construct(
        protected \App\PDO $pdo
    ) {}

    /**
     * ActionInterface contract. This is a per-buyer loop, not a per-lead
     * action, so execute() is a deliberate no-op (same precedent as
     * ClassifyReplyAction::execute()). Use run() / resetToDefaults().
     */
    public function execute(int $leadId): bool
    {
        return false;
    }

    /**
     * Run one engagement-feedback adjustment pass.
     *
     * @param int|null $profileId Override the active profile (tests / tools).
     * @return array{status:string,detail:string,profile_id:?int,sample_size:int,
     *               engaged_leads:int,correlations:array<string,?float>,
     *               old_weights:array<string,int>,new_weights:array<string,int>}
     *   status: 'adjusted' | 'skipped' | 'error'. Never throws.
     */
    public function run(?int $profileId = null): array
    {
        try {
            return $this->runInner($profileId);
        } catch (\Throwable $e) {
            // Investigate on first occurrence: loud, structured, never silent.
            error_log('[AdjustIcpWeights] FAILED: ' . get_class($e) . ': ' . $e->getMessage());
            $this->skipTrace('error: ' . substr($e->getMessage(), 0, 200), []);
            return [
                'status'        => 'error',
                'detail'        => 'adjustment failed: ' . $e->getMessage(),
                'profile_id'    => null,
                'sample_size'   => 0,
                'engaged_leads' => 0,
                'correlations'  => [],
                'old_weights'   => [],
                'new_weights'   => [],
            ];
        }
    }

    /**
     * Buyer-facing reset: restore the default weight vector on every UNLOCKED
     * dimension. buyer_locked dimensions keep their weight — the buyer
     * override always wins, even on reset.
     *
     * @return array same shape as run(); status 'adjusted' | 'skipped' | 'error'.
     */
    public function resetToDefaults(?int $profileId = null, ?string $reason = null): array
    {
        try {
            $resolved = $this->resolveProfileId($profileId);
            if ($resolved === null) {
                return $this->skip('no active ICP profile (or profile class unavailable)');
            }
            $dims = $this->loadDimensions($resolved);
            if ($dims === []) {
                return $this->skip("profile {$resolved} has no dimensions");
            }

            $weights = [];
            $locked  = [];
            foreach ($dims as $key => $dim) {
                $weights[$key] = (int)($dim['weight'] ?? 0);
                if (!empty($dim['buyer_locked'])) {
                    $locked[] = $key;
                }
            }
            $unlocked = array_values(array_diff(array_keys($weights), $locked));
            if ($unlocked === []) {
                return $this->skip('reset refused: all dimensions buyer-locked (buyer override wins)');
            }

            $lockedTotal = 0;
            foreach ($locked as $dim) {
                $lockedTotal += $weights[$dim];
            }
            $target = 100 - $lockedTotal;
            if ($target <= 0) {
                return $this->skip('reset refused: locked weights already sum to >= 100');
            }

            $base = [];
            $even = $target / count($unlocked);
            foreach ($unlocked as $dim) {
                $base[$dim] = (float)(self::DEFAULT_WEIGHTS[$dim] ?? $even);
            }
            $scaled = [];
            $baseTotal = array_sum($base);
            foreach ($base as $dim => $v) {
                $scaled[$dim] = $baseTotal > 0 ? $v * $target / $baseTotal : $even;
            }
            $ints = self::intVectorForTarget($scaled, $target);

            $new = [];
            foreach ($weights as $dim => $w) {
                $new[$dim] = in_array($dim, $locked, true) ? $w : (int)$ints[$dim];
            }

            if ($new === $weights) {
                return $this->skip('reset: unlocked dimensions already at defaults');
            }

            $reasonText = $reason ?? 'buyer reset to defaults';
            $this->writeWeights($resolved, $new, $reasonText, null, 'user');
            error_log('[AdjustIcpWeights] reset to defaults on profile ' . $resolved .
                ': ' . json_encode($new));
            return [
                'status'        => 'adjusted',
                'detail'        => 'reset to defaults: ' . json_encode($new),
                'profile_id'    => $resolved,
                'sample_size'   => 0,
                'engaged_leads' => 0,
                'correlations'  => [],
                'old_weights'   => $weights,
                'new_weights'   => $new,
            ];
        } catch (\Throwable $e) {
            error_log('[AdjustIcpWeights] reset FAILED: ' . get_class($e) . ': ' . $e->getMessage());
            return [
                'status'        => 'error',
                'detail'        => 'reset failed: ' . $e->getMessage(),
                'profile_id'    => null,
                'sample_size'   => 0,
                'engaged_leads' => 0,
                'correlations'  => [],
                'old_weights'   => [],
                'new_weights'   => [],
            ];
        }
    }

    // ------------------------------------------------------------------
    // Core pass
    // ------------------------------------------------------------------

    private function runInner(?int $profileId): array
    {
        $resolved = $this->resolveProfileId($profileId);
        if ($resolved === null) {
            return $this->skip('no active ICP profile (or profile class unavailable)');
        }
        $dims = $this->loadDimensions($resolved);
        if ($dims === []) {
            return $this->skip("profile {$resolved} has no dimensions");
        }

        $weights = [];
        $locked  = [];
        foreach ($dims as $key => $dim) {
            $weights[$key] = (int)($dim['weight'] ?? 0);
            if (!empty($dim['buyer_locked'])) {
                $locked[] = $key;
            }
        }
        $unlocked = array_values(array_diff(array_keys($weights), $locked));
        if ($unlocked === []) {
            return $this->skip('all dimensions buyer-locked; nothing adjustable');
        }

        $signals = $this->collectEngagementSignals();
        $scores  = $this->collectDimensionScores($dims);

        // Sample = scored leads with engagement data (both maps must cover
        // the lead; only scored leads participate, per the spec).
        $sample = [];
        foreach (array_intersect(array_keys($signals), array_keys($scores)) as $leadId) {
            $sample[$leadId] = ['signal' => $signals[$leadId], 'scores' => $scores[$leadId]];
        }
        $engaged = 0;
        foreach ($sample as $row) {
            if ($row['signal'] != 0.0) {
                $engaged++;
            }
        }
        if ($engaged < self::MIN_ENGAGED_LEADS) {
            return $this->skip(
                "engaged-lead sample below floor (n={$engaged} < " . self::MIN_ENGAGED_LEADS . ')'
            );
        }

        $correlations = [];
        foreach ($unlocked as $dim) {
            $xs = [];
            $ys = [];
            foreach ($sample as $row) {
                if (!isset($row['scores'][$dim])) {
                    continue;
                }
                $xs[] = (float)$row['scores'][$dim];
                $ys[] = (float)$row['signal'];
            }
            $correlations[$dim] = self::pearson($xs, $ys);
        }

        $newWeights = self::proposeWeights($weights, $locked, $correlations);
        if ($newWeights === $weights) {
            return $this->skip(
                'no predictive signal above noise floor (n=' . count($sample) .
                ", engaged={$engaged})"
            );
        }

        $reason = 'engagement feedback auto-tune: n=' . count($sample) .
            " leads ({$engaged} engaged), corr=" . self::corrSummary($correlations);
        $this->writeWeights($resolved, $newWeights, $reason, count($sample), 'auto_tuner');

        $detail = 'adjusted profile ' . $resolved . ': ' .
            json_encode(array_diff_assoc($newWeights, $weights)) .
            " (n=" . count($sample) . ", engaged={$engaged})";
        error_log('[AdjustIcpWeights] ' . $detail);
        return [
            'status'        => 'adjusted',
            'detail'        => $detail,
            'profile_id'    => $resolved,
            'sample_size'   => count($sample),
            'engaged_leads' => $engaged,
            'correlations'  => $correlations,
            'old_weights'   => $weights,
            'new_weights'   => $newWeights,
        ];
    }

    // ------------------------------------------------------------------
    // Subject-1 seam (overridable in tests)
    // ------------------------------------------------------------------

    /**
     * Resolve the profile id: explicit override, else the active profile.
     * Null when no profile is active or the profile class is unavailable.
     */
    protected function resolveProfileId(?int $override): ?int
    {
        if ($override !== null && $override > 0) {
            return $override;
        }
        if (!class_exists(IcpProfile::class)) {
            return null;
        }
        try {
            $active = IcpProfile::active();
        } catch (\Throwable $e) {
            error_log('[AdjustIcpWeights] IcpProfile::active() failed: ' . $e->getMessage());
            return null;
        }
        return is_array($active) && isset($active['id']) ? (int)$active['id'] : null;
    }

    /**
     * @return array dim => ['weight'=>int,'buyer_locked'=>bool,'target_config'=>array]
     */
    protected function loadDimensions(int $profileId): array
    {
        try {
            return IcpProfile::dimensions($profileId);
        } catch (\Throwable $e) {
            error_log('[AdjustIcpWeights] IcpProfile::dimensions() failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Persist the new weight vector. Delegates to subject 1's updateWeights(),
     * which validates (all dims present, ints, sum 100) and records one
     * icp_weight_history row per changed dimension (reason + sample_size +
     * created_by). Fail-closed: throws on validation/DB failure.
     */
    protected function writeWeights(
        int $profileId,
        array $weights,
        string $reason,
        ?int $sampleSize,
        string $createdBy
    ): void {
        IcpProfile::updateWeights($profileId, $weights, $reason, $sampleSize, false, $createdBy);
    }

    // ------------------------------------------------------------------
    // Engagement signals (this buyer's own sequence_events)
    // ------------------------------------------------------------------

    /**
     * Map a reply intent to its engagement signal. Unlisted intents
     * (bounce, out_of_office, not_now, other, unknown) are neutral (0.0).
     */
    public static function signalForIntent(string $intent): float
    {
        $intent = strtolower(trim($intent));
        return self::INTENT_SIGNALS[$intent] ?? 0.0;
    }

    /**
     * Extract the reply intent from a 'classified' sequence_events detail.
     * Written by SequenceManager::recordReply() as
     * "Phase-3 verdict: {json}". Returns null when unparseable.
     */
    public static function parseClassifiedIntent(?string $detail): ?string
    {
        if ($detail === null || $detail === '') {
            return null;
        }
        $json = $detail;
        $prefix = 'Phase-3 verdict: ';
        if (str_starts_with($detail, $prefix)) {
            $json = substr($detail, strlen($prefix));
        }
        $decoded = json_decode($json, true);
        if (is_array($decoded) && isset($decoded['intent']) && is_string($decoded['intent'])) {
            $intent = strtolower(trim($decoded['intent']));
            return $intent === '' ? null : $intent;
        }
        if (preg_match('/"intent"\s*:\s*"([a-z_]+)"/i', $detail, $m)) {
            return strtolower($m[1]);
        }
        return null;
    }

    /**
     * Per-lead engagement signals from sequence_events (90-day lookback).
     *
     * @return array<int,float> lead_id => signal
     */
    private function collectEngagementSignals(): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::LOOKBACK_DAYS * 86400);
        try {
            $stmt = $this->pdo->prepare(
                "SELECT lead_id, event_type, detail, created_at FROM sequence_events " .
                "WHERE event_type IN ('opened','replied','classified') AND created_at >= ? " .
                "ORDER BY lead_id ASC, created_at ASC"
            );
            $stmt->execute([$cutoff]);
            $rows = $stmt->fetchAll(\App\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Fail-safe: a missing/unreadable timeline means no sample, and
            // the sample-floor guard below will skip the run.
            error_log('[AdjustIcpWeights] engagement read failed: ' . $e->getMessage());
            return [];
        }

        $perLead = [];
        foreach ($rows as $row) {
            $leadId = (int)($row['lead_id'] ?? 0);
            if ($leadId <= 0) {
                continue;
            }
            if (!isset($perLead[$leadId])) {
                $perLead[$leadId] = ['intent' => null, 'replied' => false, 'opened' => false];
            }
            switch ((string)($row['event_type'] ?? '')) {
                case 'classified':
                    // Rows arrive oldest-first; the latest verdict wins.
                    $intent = self::parseClassifiedIntent((string)($row['detail'] ?? ''));
                    if ($intent !== null) {
                        $perLead[$leadId]['intent'] = $intent;
                    }
                    break;
                case 'replied':
                    $perLead[$leadId]['replied'] = true;
                    break;
                case 'opened':
                    $perLead[$leadId]['opened'] = true;
                    break;
            }
        }

        $signals = [];
        foreach ($perLead as $leadId => $ev) {
            if ($ev['intent'] !== null) {
                $signals[$leadId] = self::signalForIntent($ev['intent']);
            } elseif ($ev['replied']) {
                // Reply exists but no stored verdict — neutral, don't guess.
                $signals[$leadId] = 0.0;
            } elseif ($ev['opened']) {
                $signals[$leadId] = self::OPENED_NO_REPLY_SIGNAL;
            }
        }
        return $signals;
    }

    // ------------------------------------------------------------------
    // Per-dimension scores (subject 2, with field-derivation fallback)
    // ------------------------------------------------------------------

    /**
     * Per-lead, per-dimension scores in 0..1.
     *
     * Primary source: subject 2's ScoreLeadFitAction storage — per-dimension
     * 1-10 scores written into leads.notes by QualifyLeadAction::notesMarker()
     * as "Dimensions: company_size=8/10, industry_fit=7/10, ...". The latest
     * marker wins (notes are append-only); leads with no Dimensions line
     * (legacy or vetoed qualifications) are not scored and do not
     * participate. Scores are normalized to 0..1.
     *
     * Fallback (no Dimensions markers anywhere): re-derived evidence flags
     * from lead fields + the profile's own target_config: 1.0 on a match,
     * 0.0 on a mismatch, 0.5 when unknown or when no lead column carries that
     * dimension's evidence. Deliberately coarse — zero-variance dimensions
     * produce no adjustment.
     *
     * @return array<int,array<string,float>> lead_id => [dim => 0..1]
     */
    private function collectDimensionScores(array $dimensions): array
    {
        $parsed = $this->parseNotesDimensionScores(array_keys($dimensions));
        if ($parsed !== []) {
            return $parsed;
        }
        return $this->fallbackDimensionScores($dimensions);
    }

    /**
     * Parse subject 2's "Dimensions: dim=N/10, ..." markers out of
     * leads.notes. Latest marker per lead wins.
     *
     * @return array<int,array<string,float>> lead_id => [dim => 0..1]
     */
    private function parseNotesDimensionScores(array $dimKeys): array
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT id, notes FROM leads WHERE notes LIKE '%Dimensions:%'"
            );
            $rows = $stmt->fetchAll(\App\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[AdjustIcpWeights] notes score read failed: ' . $e->getMessage());
            return [];
        }
        $wanted = array_flip($dimKeys);
        $out = [];
        foreach ($rows as $row) {
            $leadId = (int)($row['id'] ?? 0);
            if ($leadId <= 0) {
                continue;
            }
            $notes = (string)($row['notes'] ?? '');
            if (!preg_match_all('/^Dimensions:\s*(.+)$/m', $notes, $m) || $m[1] === []) {
                continue;
            }
            $line = end($m[1]);
            $scores = [];
            foreach (explode(',', (string)$line) as $part) {
                if (preg_match('/^\s*([a-z_]+)\s*=\s*(\d+(?:\.\d+)?)\s*\/\s*10/', $part, $pm)
                    && isset($wanted[$pm[1]])) {
                    $scores[$pm[1]] = max(0.0, min(10.0, (float)$pm[2])) / 10.0;
                }
            }
            if ($scores !== []) {
                $out[$leadId] = $scores;
            }
        }
        return $out;
    }

    private function fallbackDimensionScores(array $dimensions): array
    {
        $configs = [];
        foreach ($dimensions as $key => $dim) {
            $configs[$key] = is_array($dim['target_config'] ?? null) ? $dim['target_config'] : [];
        }
        try {
            $stmt = $this->pdo->query("SELECT id, country_code, target_persona FROM leads");
            $rows = $stmt->fetchAll(\App\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[AdjustIcpWeights] fallback score read failed: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $lead) {
            $leadId = (int)($lead['id'] ?? 0);
            if ($leadId <= 0) {
                continue;
            }
            $flags = [];
            foreach (array_keys($dimensions) as $dim) {
                $flags[$dim] = $this->fallbackFlag($dim, $configs[$dim] ?? [], $lead);
            }
            $out[$leadId] = $flags;
        }
        return $out;
    }

    /**
     * One coarse evidence flag for a dimension from lead fields.
     * geography: lead country_code vs the profile's targeted countries.
     * target_title: target_config titles vs the lead's target_persona.
     * Everything else: no lead column carries this evidence in the current
     * schema → 0.5 (neutral; zero variance → no nudge).
     */
    private function fallbackFlag(string $dim, array $config, array $lead): float
    {
        switch ($dim) {
            case 'geography':
                $countries = [];
                foreach ((array)($config['countries'] ?? []) as $c) {
                    $c = strtolower(trim((string)$c));
                    if ($c !== '') {
                        $countries[] = $c;
                    }
                }
                if ($countries === []) {
                    return 0.5;
                }
                $cc = strtolower(trim((string)($lead['country_code'] ?? '')));
                if ($cc === '') {
                    return 0.5;
                }
                return in_array($cc, $countries, true) ? 1.0 : 0.0;

            case 'target_title':
                $titles = [];
                foreach ((array)($config['titles'] ?? []) as $t) {
                    $t = strtolower(trim((string)$t));
                    if ($t !== '') {
                        $titles[] = $t;
                    }
                }
                if ($titles === []) {
                    return 0.5;
                }
                $persona = strtolower((string)($lead['target_persona'] ?? ''));
                if ($persona === '') {
                    return 0.5;
                }
                foreach ($titles as $t) {
                    if (str_contains($persona, $t)) {
                        return 1.0;
                    }
                }
                return 0.0;

            default:
                // company_size, industry_fit, tech_stack, trigger_signals:
                // no lead column carries this evidence in the current schema.
                return 0.5;
        }
    }

    // ------------------------------------------------------------------
    // Math: correlation, nudge, renormalize
    // ------------------------------------------------------------------

    /**
     * Pearson correlation coefficient, or null when undefined (fewer than 2
     * pairs, length mismatch, or zero variance in either series).
     */
    public static function pearson(array $xs, array $ys): ?float
    {
        $n = count($xs);
        if ($n !== count($ys) || $n < 2) {
            return null;
        }
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $sxy = 0.0;
        $sxx = 0.0;
        $syy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = (float)$xs[$i] - $mx;
            $dy = (float)$ys[$i] - $my;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }
        if ($sxx <= 0.0 || $syy <= 0.0) {
            return null;
        }
        return max(-1.0, min(1.0, $sxy / sqrt($sxx * $syy)));
    }

    /**
     * Propose the next weight vector: nudge each unlocked dimension by
     * clamp(5 * r, -5, +5) (noise floor |r| < 0.05 → 0), keep buyer_locked
     * dimensions EXACTLY as-is, and renormalize so the full vector sums to
     * exactly 100 as integers (largest-remainder).
     *
     * @param array<string,int>   $weights     dim => current weight
     * @param string[]            $lockedDims  dims with buyer_locked=1
     * @param array<string,?float> $correlations dim => Pearson r (unlocked only)
     * @return array<string,int> new weight vector summing to exactly 100
     */
    public static function proposeWeights(
        array $weights,
        array $lockedDims,
        array $correlations
    ): array {
        $isLocked = [];
        foreach (array_keys($weights) as $dim) {
            if (in_array($dim, $lockedDims, true)) {
                $isLocked[$dim] = true;
            }
        }
        $unlocked = array_values(array_diff(array_keys($weights), array_keys($isLocked)));
        if ($unlocked === []) {
            return self::intVector($weights);
        }

        $raw = [];
        foreach ($unlocked as $dim) {
            $r = $correlations[$dim] ?? null;
            $delta = 0.0;
            if ($r !== null && abs($r) >= self::CORR_NOISE_FLOOR) {
                $delta = max(
                    -self::MAX_STEP_PER_RUN,
                    min(self::MAX_STEP_PER_RUN, self::MAX_STEP_PER_RUN * $r)
                );
            }
            // Never let a nudge drive a weight below zero.
            $raw[$dim] = max(0.0, (float)$weights[$dim] + $delta);
        }

        $lockedTotal = 0;
        foreach (array_keys($isLocked) as $dim) {
            $lockedTotal += (int)$weights[$dim];
        }
        $target = 100 - $lockedTotal;
        $rawTotal = array_sum($raw);
        if ($target <= 0 || $rawTotal <= 0.0) {
            // Degenerate (locked weights already >= 100, or every unlocked
            // weight is zero): fail-safe, return the vector unchanged.
            return self::intVector($weights);
        }

        $scaled = [];
        foreach ($raw as $dim => $v) {
            $scaled[$dim] = $v * $target / $rawTotal;
        }
        $ints = self::intVectorForTarget($scaled, $target);

        $out = [];
        foreach ($weights as $dim => $w) {
            $out[$dim] = isset($isLocked[$dim]) ? (int)$w : (int)$ints[$dim];
        }
        return $out;
    }

    /**
     * Largest-remainder apportionment: integer parts of $floats that sum to
     * exactly $target. Keys are preserved.
     *
     * @param array<string,float> $floats non-negative values
     */
    private static function intVectorForTarget(array $floats, int $target): array
    {
        $floors = [];
        $fracts = [];
        foreach ($floats as $dim => $v) {
            $f = floor(max(0.0, $v));
            $floors[$dim] = (int)$f;
            $fracts[$dim] = $v - $f;
        }
        $need = $target - array_sum($floors);
        $need = max(0, min($need, count($floors)));
        arsort($fracts);
        $i = 0;
        foreach ($fracts as $dim => $_) {
            if ($i++ >= $need) {
                break;
            }
            $floors[$dim]++;
        }
        return $floors;
    }

    /** Cast a weight vector to ints, preserving keys. */
    private static function intVector(array $weights): array
    {
        $out = [];
        foreach ($weights as $dim => $w) {
            $out[$dim] = (int)$w;
        }
        return $out;
    }

    /** Compact "dim=+0.82, dim2=n/a" summary for the audit reason. */
    private static function corrSummary(array $correlations): string
    {
        $parts = [];
        foreach ($correlations as $dim => $r) {
            $parts[] = $dim . '=' . ($r === null ? 'n/a' : sprintf('%+.2f', $r));
        }
        return substr(implode(',', $parts), 0, 120);
    }

    // ------------------------------------------------------------------
    // Skip tracing (fail-safe audit)
    // ------------------------------------------------------------------

    /** Build a skip result + write the "skipped: <reason>" trace. Never throws. */
    private function skip(string $reason): array
    {
        $this->skipTrace($reason, []);
        error_log('[AdjustIcpWeights] skipped: ' . $reason);
        return [
            'status'        => 'skipped',
            'detail'        => 'skipped: ' . $reason,
            'profile_id'    => null,
            'sample_size'   => 0,
            'engaged_leads' => 0,
            'correlations'  => [],
            'old_weights'   => [],
            'new_weights'   => [],
        ];
    }

    private function skipTrace(string $reason, array $context): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO agent_traces (lead_id, persona, goal, context, reasoning_output, operational_mode) " .
                "VALUES (NULL, 'AdjustIcpWeights', 'icp_weight_skip', ?, ?, 'Production')"
            );
            $stmt->execute([json_encode($context), 'skipped: ' . $reason]);
        } catch (\Throwable $e) {
            // Logging must never break the run.
            error_log('[AdjustIcpWeights] skip-trace write failed: ' . $e->getMessage());
        }
    }
}
