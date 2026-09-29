#!/usr/bin/env php
<?php
/**
 * Engagement-feedback loop math (subject 3/4, goal_67693fcbba4c).
 *
 * Complements tests/icp/ (which covers signalForIntent, parseClassifiedIntent,
 * pearson units, proposeWeights basics, and the MariaDB integration run):
 *   A. Nudge boundaries: exact noise-floor cut (|r|=0.05), engineered
 *      largest-remainder apportionment cases with deterministic exact
 *      vectors, negative clamp, buyer_locked dims kept EXACTLY while the
 *      unlocked slice renormalizes to (100 - locked).
 *   B. AdjustIcpWeightsAction::run() through the protected test seams
 *      (subclass overrides resolveProfileId/loadDimensions/writeWeights) +
 *      a scripted fake PDO — DB-free: happy-path adjustment, sample-floor
 *      skip, no-profile skip, and the never-throws error path.
 *   C. resetToDefaults() semantics via the same seams: locked dims keep
 *      their weight (buyer override wins), unlocked renormalize to the
 *      default vector scaled to (100 - locked), created_by='user'.
 *   D. cron/process_queue.php: the daily-gated hook reads the last-run
 *      timestamp, gates on RUN_INTERVAL_SECONDS, runs the action, persists
 *      the timestamp, and is non-fatal to the queue.
 *   E. Constant pins: INTENT_SIGNALS map, OPENED_NO_REPLY_SIGNAL,
 *      MIN_ENGAGED_LEADS, MAX_STEP_PER_RUN, CORR_NOISE_FLOOR.
 *
 * Usage: php tests/icp_scoring/test_feedback_math.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require_once ics_repo_root() . '/includes/autoload.php';

use App\Actions\AdjustIcpWeightsAction as A;
use App\Icp\IcpProfile;
use App\Jev\DecisionTier;

ics_assert_default_mode_off('(start)');

$DIMS = IcpProfile::DIMENSIONS;
$W = ['company_size' => 17, 'industry_fit' => 17, 'tech_stack' => 17,
      'target_title' => 17, 'geography' => 16, 'trigger_signals' => 16];

// --- A. nudge boundaries --------------------------------------------------------
echo "A. noise floor, clamp, largest-remainder:\n";

check('|r| = 0.049 (below floor) -> vector identical',
    A::proposeWeights($W, [], ['company_size' => 0.049]) === $W);
check('|-r| = 0.049 (below floor) -> vector identical',
    A::proposeWeights($W, [], ['company_size' => -0.049]) === $W);
$nf = A::proposeWeights($W, [], ['company_size' => 0.05]);
check('|r| = 0.05 (at floor): vector sums to 100 as ints',
    array_sum($nf) === 100
    && array_reduce($nf, fn($c, $w) => $c && is_int($w), true));

// Engineered: weights [35,33,32,0,0,0], r=+1.0 -> raw [40,33,32] total 105,
// scaled [38.095, 31.429, 30.476] -> floors [38,31,30]=99, the +1 goes to
// tech_stack (largest fractional remainder .476).
$we = ['company_size' => 35, 'industry_fit' => 33, 'tech_stack' => 32,
       'target_title' => 0, 'geography' => 0, 'trigger_signals' => 0];
$new = A::proposeWeights($we, [], ['company_size' => 1.0]);
check('largest-remainder: +5 nudge apportions to [38,31,31,0,0,0]',
    $new === ['company_size' => 38, 'industry_fit' => 31, 'tech_stack' => 31,
              'target_title' => 0, 'geography' => 0, 'trigger_signals' => 0],
    json_encode($new));

// Engineered negative clamp: r=-1.0 -> raw [30,33,32] total 95,
// scaled [31.579, 34.737, 33.684] -> floors [31,34,33]=98, +1 to the two
// largest remainders (industry_fit .737, tech_stack .684).
$new = A::proposeWeights($we, [], ['company_size' => -1.0]);
check('negative clamp: exact vector [31,35,34,0,0,0], sum 100',
    $new === ['company_size' => 31, 'industry_fit' => 35, 'tech_stack' => 34,
              'target_title' => 0, 'geography' => 0, 'trigger_signals' => 0]
    && array_sum($new) === 100,
    json_encode($new));

// Locked dims kept EXACTLY; unlocked renormalize to (100 - locked).
// Locked tech_stack=17 -> target 83. corr cs=+1.0: raw [40,33] over
// unlocked {cs,it} -> scaled [45.479, 37.521] -> floors 82, +1 to
// industry_fit (.521 > .479).
$wl = ['company_size' => 35, 'industry_fit' => 33, 'tech_stack' => 17,
       'target_title' => 0, 'geography' => 0, 'trigger_signals' => 0];
$new = A::proposeWeights($wl, ['tech_stack'], ['company_size' => 1.0]);
check('locked dim kept EXACTLY, unlocked renormalize to 100-locked',
    $new === ['company_size' => 45, 'industry_fit' => 38, 'tech_stack' => 17,
              'target_title' => 0, 'geography' => 0, 'trigger_signals' => 0]
    && array_sum($new) === 100,
    json_encode($new));

// --- E. constant pins --------------------------------------------------------------
echo "E. constant pins:\n";
check('MIN_ENGAGED_LEADS = 10', A::MIN_ENGAGED_LEADS === 10);
check('MAX_STEP_PER_RUN = 5.0', A::MAX_STEP_PER_RUN === 5.0);
check('CORR_NOISE_FLOOR = 0.05', A::CORR_NOISE_FLOOR === 0.05);
check('OPENED_NO_REPLY_SIGNAL = 0.25', A::OPENED_NO_REPLY_SIGNAL === 0.25);
check('RUN_INTERVAL_SECONDS = 86400', A::RUN_INTERVAL_SECONDS === 86400);
check("SETTING_LAST_RUN = 'icp_weight_adjust_last_run'",
    A::SETTING_LAST_RUN === 'icp_weight_adjust_last_run');
$signals = A::INTENT_SIGNALS;
check('INTENT_SIGNALS: positive 2.0 / objection+referral 1.0 / unsubscribe+hostile -1.0',
    ($signals['positive'] ?? null) === 2.0
    && ($signals['objection'] ?? null) === 1.0
    && ($signals['referral'] ?? null) === 1.0
    && ($signals['unsubscribe'] ?? null) === -1.0
    && ($signals['hostile'] ?? null) === -1.0);
check('DEFAULT_WEIGHTS sums to 100',
    array_sum(A::DEFAULT_WEIGHTS) === 100
    && count(A::DEFAULT_WEIGHTS) === count($DIMS));

// --- B/C. run() + resetToDefaults() via subclassed seams (DB-free) --------------------
echo "B/C. run() and resetToDefaults() via seams:\n";

/** Subclass exposing the protected seams; captures writeWeights instead of hitting subject 1. */
class IcsAdjust extends A
{
    public ?int $pid = 1;
    /** @var array dim => ['weight'=>int,'buyer_locked'=>bool,'target_config'=>array] */
    public array $dims = [];
    public ?array $written = null;
    public ?string $writtenReason = null;
    public ?int $writtenSample = -1;
    public ?string $writtenBy = null;
    public bool $throwOnDims = false;

    public static function dimsFor(array $weights, array $locked = []): array
    {
        $out = [];
        foreach ($weights as $dim => $w) {
            $out[$dim] = ['weight' => $w, 'buyer_locked' => in_array($dim, $locked, true), 'target_config' => []];
        }
        return $out;
    }

    protected function resolveProfileId(?int $override): ?int
    {
        return $this->pid;
    }
    protected function loadDimensions(int $profileId): array
    {
        if ($this->throwOnDims) {
            throw new \RuntimeException('dims boom');
        }
        return $this->dims;
    }
    protected function writeWeights(int $profileId, array $weights, string $reason, ?int $sampleSize, string $createdBy): void
    {
        $this->written = $weights;
        $this->writtenReason = $reason;
        $this->writtenSample = $sampleSize;
        $this->writtenBy = $createdBy;
    }
}

function ics_notes_row(int $id, array $scores10): array
{
    $parts = [];
    foreach ($scores10 as $dim => $s) {
        $parts[] = "{$dim}={$s}/10";
    }
    return ['id' => $id,
            'notes' => "\n\n[Qualification 2026-09-28]: Qualified (fit 70/100) — seed\nDimensions: " . implode(', ', $parts) . '.'];
}

function ics_events_script(): array
{
    $rows = [];
    $mk = fn(string $intent): string => 'Phase-3 verdict: {"intent":"' . $intent . '","confidence":0.9}';
    foreach ([1, 2, 3, 4, 5, 6] as $lid) {
        $rows[] = ['lead_id' => $lid, 'event_type' => 'replied', 'detail' => 'Inbound reply', 'created_at' => '2026-09-28 10:00:00'];
        $rows[] = ['lead_id' => $lid, 'event_type' => 'classified', 'detail' => $mk('positive'), 'created_at' => '2026-09-28 10:01:00'];
    }
    foreach ([7, 8, 9, 10] as $lid) {
        $rows[] = ['lead_id' => $lid, 'event_type' => 'replied', 'detail' => 'Inbound reply', 'created_at' => '2026-09-28 10:00:00'];
        $rows[] = ['lead_id' => $lid, 'event_type' => 'classified', 'detail' => $mk('unsubscribe'), 'created_at' => '2026-09-28 10:01:00'];
    }
    $rows[] = ['lead_id' => 11, 'event_type' => 'replied', 'detail' => 'Inbound reply', 'created_at' => '2026-09-28 10:00:00'];
    $rows[] = ['lead_id' => 11, 'event_type' => 'classified', 'detail' => $mk('objection'), 'created_at' => '2026-09-28 10:01:00'];
    $rows[] = ['lead_id' => 12, 'event_type' => 'opened', 'detail' => 'Open tracked', 'created_at' => '2026-09-28 10:00:00'];
    return $rows;
}

function ics_scores_script(): array
{
    // company_size correlates with engagement (9/10 -> positive, 1/10 ->
    // unsubscribe); every other dimension is constant 5/10 (zero variance).
    $rows = [];
    $sizeScore = [1 => 9, 2 => 9, 3 => 9, 4 => 9, 5 => 9, 6 => 9,
                  7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 5, 12 => 9];
    foreach ($sizeScore as $lid => $s) {
        $rows[] = ics_notes_row($lid, [
            'company_size' => $s, 'industry_fit' => 5, 'tech_stack' => 5,
            'target_title' => 5, 'geography' => 5, 'trigger_signals' => 5,
        ]);
    }
    return $rows;
}

// B1. happy path: 12 engaged leads, company_size predictive.
$pdo = ScriptedPdo::make([
    ['match' => 'sequence_events', 'rows' => ics_events_script()],
    ['match' => 'FROM leads', 'rows' => ics_scores_script()],
]);
$action = new IcsAdjust($pdo);
$action->dims = IcsAdjust::dimsFor($W);
$res = $action->run();
check('run(): 12 engaged -> status adjusted', $res['status'] === 'adjusted', $res['detail'] ?? '');
check('run(): sample_size=12, engaged_leads=12',
    $res['sample_size'] === 12 && $res['engaged_leads'] === 12);
check('run(): predictive dim nudged up, vector sums to 100 as ints',
    $action->written !== null
    && $action->written['company_size'] > 17
    && array_sum($action->written) === 100
    && count($action->written) === 6);
check("run(): write captured as auto_tuner with sample size",
    $action->writtenBy === 'auto_tuner' && $action->writtenSample === 12
    && str_contains((string)$action->writtenReason, 'engagement feedback'));
check('run(): correlation(company_size) strongly positive, zero-variance dim null',
    ($res['correlations']['company_size'] ?? 0) > 0.5
    && array_key_exists('tech_stack', $res['correlations'])
    && $res['correlations']['tech_stack'] === null,
    json_encode($res['correlations']));

// B2. sample floor: 9 engaged leads -> skipped, nothing written.
$few = array_values(array_filter(
    ics_events_script(),
    fn($r) => (int)$r['lead_id'] <= 9
));
$pdo2 = ScriptedPdo::make([
    ['match' => 'sequence_events', 'rows' => $few],
    ['match' => 'FROM leads', 'rows' => array_slice(ics_scores_script(), 0, 9)],
]);
$action2 = new IcsAdjust($pdo2);
$action2->dims = IcsAdjust::dimsFor($W);
$res2 = $action2->run();
check('run(): 9 engaged < 10 -> skipped below floor',
    $res2['status'] === 'skipped' && str_contains((string)$res2['detail'], 'below floor'));
check('run(): nothing written below the floor', $action2->written === null);

// B3. no active profile -> skipped.
$action3 = new IcsAdjust($pdo);
$action3->pid = null;
$action3->dims = IcsAdjust::dimsFor($W);
$res3 = $action3->run();
check('run(): no active profile -> skipped',
    $res3['status'] === 'skipped' && str_contains((string)$res3['detail'], 'no active ICP profile'));
check('run(): nothing written without a profile', $action3->written === null);

// B4. unexpected failure -> status 'error', never throws into the caller.
$action4 = new IcsAdjust($pdo);
$action4->throwOnDims = true;
$threw = false;
try {
    $res4 = $action4->run();
} catch (\Throwable $e) {
    $threw = true;
}
check('run(): internal failure -> status error, never throws',
    !$threw && ($res4['status'] ?? '') === 'error');

// C1. resetToDefaults: locked dim keeps its weight, unlocked renormalize.
// locked geography=5 -> target 95; defaults [17,17,17,17,16] over 5 unlocked
// scale to 95 -> floors [19,19,19,19,18]=94, +1 to one 17-base dim.
$action5 = new IcsAdjust($pdo);
$action5->dims = IcsAdjust::dimsFor(
    ['company_size' => 30, 'industry_fit' => 30, 'tech_stack' => 30,
     'target_title' => 3, 'geography' => 5, 'trigger_signals' => 2],
    ['geography']
);
$res5 = $action5->resetToDefaults();
check('resetToDefaults(): status adjusted', $res5['status'] === 'adjusted', $res5['detail'] ?? '');
check('resetToDefaults(): locked dim keeps its weight (buyer override wins)',
    $action5->written !== null && $action5->written['geography'] === 5);
$unlockedVals = [];
foreach (($action5->written ?? []) as $dim => $w) {
    if ($dim !== 'geography') {
        $unlockedVals[] = $w;
    }
}
sort($unlockedVals);
check('resetToDefaults(): unlocked renormalize to default shape scaled to 95',
    $unlockedVals === [18, 19, 19, 19, 20] && array_sum($action5->written ?? []) === 100,
    json_encode($action5->written));
check("resetToDefaults(): recorded as 'user' with reset reason",
    $action5->writtenBy === 'user' && str_contains((string)$action5->writtenReason, 'reset'));

// C2. reset when already at defaults -> skipped, nothing written.
$action6 = new IcsAdjust($pdo);
$action6->dims = IcsAdjust::dimsFor(A::DEFAULT_WEIGHTS);
$res6 = $action6->resetToDefaults();
check('resetToDefaults(): already at defaults -> skipped',
    $res6['status'] === 'skipped' && $action6->written === null);

// C3. reset refused when every dimension is buyer-locked.
$action7 = new IcsAdjust($pdo);
$action7->dims = IcsAdjust::dimsFor($W, array_keys($W));
$res7 = $action7->resetToDefaults();
check('resetToDefaults(): all locked -> refused, buyer override wins',
    $res7['status'] === 'skipped' && str_contains((string)$res7['detail'], 'refused')
    && $action7->written === null);

// --- D. cron daily gate (static) ------------------------------------------------------
echo "D. cron daily gate:\n";
$cron = file_get_contents(ics_repo_root() . '/cron/process_queue.php');
check('cron: reads the last-run setting before running',
    strpos($cron, 'AdjustIcpWeightsAction::SETTING_LAST_RUN') !== false);
check('cron: gates on RUN_INTERVAL_SECONDS',
    strpos($cron, 'AdjustIcpWeightsAction::RUN_INTERVAL_SECONDS') !== false
    && strpos($cron, 'time() - $icpLast >=') !== false);
check('cron: gate is checked BEFORE the action runs',
    strpos($cron, 'RUN_INTERVAL_SECONDS') < strpos($cron, 'new \\App\\Actions\\AdjustIcpWeightsAction'));
check('cron: persists the last-run timestamp after the run',
    strpos($cron, 'ON DUPLICATE KEY UPDATE setting_value') !== false);
check('cron: tuner failure is non-fatal to the queue',
    strpos($cron, 'ICP weight auto-tuner failed (queue continues)') !== false);

DecisionTier::resetForTests();
ics_assert_default_mode_off('(end)');
exit(ics_summary('test_feedback_math.php'));
