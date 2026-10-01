#!/usr/bin/env php
<?php
/**
 * Phase 4 JEV tests: per-dimension abstention for lead_fit.score_fit.
 *
 * Implements the per-dimension-abstention spec
 * (goals/.../files/per-dimension-abstention-spec.md, approved 2026-10-01)
 * for the lead_fit.score_fit decision point ONLY. The other ten decision
 * points keep the all-or-nothing confidence veto (default false).
 *
 * Zero network, zero DB:
 *   1. DecisionTier::markAbstentions: mixed confidences -> correct flags;
 *      missing confidence field counts as 1.0 -> never abstained; non-array
 *      answers left untouched; threshold boundary (>= 0.65 not abstained).
 *   2. decide() live + perDimensionAbstention=true: low min-confidence
 *      answers are returned flagged (not escalated to legacy).
 *   3. decide() live default (false): same answers escalate to legacy
 *      (existing behavior preserved).
 *   4. decide() shadow + abstention=true: legacy returned; marking is
 *      live-mode only (unchanged).
 *   5. decide() live + abstention=true, provider throws: legacy (fail-closed).
 *   6. ScoreLeadFitAction::abstainedDims: flagged keys in canonical order.
 *   7. ScoreLeadFitAction::abstentionCoverageOk: 0/5 and 2/5 abstain -> true
 *      (60% boundary inclusive); 3/5 and 5/5 -> false; zero total weight ->
 *      false (fail-closed); tech_stack is just a dimension.
 *   8. normalizeJevAnswers() with abstentions (run-7 Reply.io numbers):
 *      triggers excluded from pcts/dimensions, remaining weights
 *      renormalize -> fit 85 qualified, confidence over scored dims only,
 *      'abstained' reported.
 *   9. normalizeJevAnswers() backward compatible: null abstentions -> the
 *      pre-abstention result, 'abstained' === [].
 *  10. jevReason() names abstained dims and omits them from the scored
 *      breakdown (skipped, not judged 0/10).
 *
 * Usage: php tests/phase4_jev/test_per_dimension_abstention.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require p4j_repo_root() . '/includes/autoload.php';

use App\Actions\ScoreLeadFitAction;
use App\Icp\IcpProfile;
use App\Jev\DecisionTier;

/** Scripted provider: answers queues or Throwables; records calls. */
class AbstainFakeJev extends \App\Jev\JevProvider
{
    public static array $script = [];
    public static int $calls = 0;
    public function __construct() {} // skip key requirement
    public function systemOne($state, array $questions): array
    {
        self::$calls++;
        $next = array_shift(self::$script);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }
    public static function reset(): void
    {
        self::$script = [];
        self::$calls = 0;
    }
}

/** Dimension-keyed fake answers with per-dimension positions/confidences. */
function abAnswers(array $positions, array $confs, array $dims = null): array
{
    $a = [];
    foreach ($dims ?? IcpProfile::DIMENSIONS as $d) {
        $ans = ['score' => $positions[$d] ?? 0.0, 'probabilities' => []];
        if (array_key_exists($d, $confs)) {
            $ans['confidence'] = $confs[$d];
        }
        $a['dim_' . $d] = $ans;
    }
    return $a;
}

$coreFive = ['company_size', 'industry_fit', 'target_title', 'geography', 'trigger_signals'];
$weights = array_fill_keys($coreFive, 20) + ['tech_stack' => 0];
$thresholds = ['qualify' => 75, 'review' => 50];
// Run-7 Reply.io numbers (positions; triggers honestly uncertain).
$run7Pos = [
    'company_size' => 7.91, 'industry_fit' => 7.74, 'target_title' => 7.33,
    'geography' => 7.65, 'trigger_signals' => 6.08,
];
$run7Conf = [
    'company_size' => 0.94, 'industry_fit' => 0.72, 'target_title' => 0.70,
    'geography' => 0.83, 'trigger_signals' => 0.19,
];
$legacyFn = fn() => ['qualified' => true, 'score' => 80, 'reason' => 'legacy verdict', 'source' => 'llm'];

// --- 1. markAbstentions -------------------------------------------------------
$mixed = [
    'dim_a' => ['score' => 5.0, 'confidence' => 0.9],
    'dim_b' => ['score' => 5.0, 'confidence' => 0.19],
    'dim_c' => ['score' => 5.0],                    // no confidence -> 1.0
    'dim_d' => ['score' => 5.0, 'confidence' => 0.65], // boundary: not abstained
    'plain' => 'not-an-array',
];
$marked = DecisionTier::markAbstentions($mixed, 0.65);
check('markAbstentions flags only below-threshold answers',
    $marked['dim_a']['abstained'] === false
    && $marked['dim_b']['abstained'] === true
    && $marked['dim_c']['abstained'] === false
    && $marked['dim_d']['abstained'] === false);
check('markAbstentions leaves non-array answers untouched',
    $marked['plain'] === 'not-an-array');
check('markAbstentions preserves score payload',
    $marked['dim_b']['score'] === 5.0);
check('markAbstentions on empty answers returns empty', DecisionTier::markAbstentions([], 0.65) === []);

// --- 2. decide() live + abstention --------------------------------------------
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], new AbstainFakeJev());
AbstainFakeJev::reset();
AbstainFakeJev::$script = [abAnswers($run7Pos, $run7Conf, $coreFive)];
$questions = ScoreLeadFitAction::buildFitQuestions(
    array_fill_keys($coreFive, ['weight' => 20, 'enabled' => true, 'target_config' => []])
);
$out = DecisionTier::decide('lead_fit.score_fit', ['x' => 1], $questions, $legacyFn,
    null, null, null, true);
check('live+abstention: answers returned, not escalated to legacy',
    is_array($out) && isset($out['dim_company_size']['score']));
check('live+abstention: triggers flagged abstained',
    ($out['dim_trigger_signals']['abstained'] ?? null) === true);
$othersOk = true;
foreach (['company_size', 'industry_fit', 'target_title', 'geography'] as $d) {
    if (($out['dim_' . $d]['abstained'] ?? null) !== false) {
        $othersOk = false;
    }
}
check('live+abstention: confident dims flagged false (not abstained)', $othersOk);

// --- 3. decide() live default (opt-in false) ----------------------------------
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], new AbstainFakeJev());
AbstainFakeJev::reset();
AbstainFakeJev::$script = [abAnswers($run7Pos, $run7Conf, $coreFive)];
$out = DecisionTier::decide('lead_fit.score_fit', ['x' => 1], $questions, $legacyFn);
check('live default: low min-confidence still escalates to legacy', $out === $legacyFn());

// --- 4. shadow mode unchanged ---------------------------------------------------
p4j_inject_tier(['enabled' => true, 'mode' => 'shadow'], new AbstainFakeJev());
AbstainFakeJev::reset();
AbstainFakeJev::$script = [abAnswers($run7Pos, $run7Conf, $coreFive)];
$out = DecisionTier::decide('lead_fit.score_fit', ['x' => 1], $questions, $legacyFn,
    null, null, null, true);
check('shadow+abstention=true: legacy returned (marking is live-only)', $out === $legacyFn());

// --- 5. provider throw still fail-closed ---------------------------------------
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], new AbstainFakeJev());
AbstainFakeJev::reset();
AbstainFakeJev::$script = [new \App\Jev\JevException('boom')];
$out = DecisionTier::decide('lead_fit.score_fit', ['x' => 1], $questions, $legacyFn,
    null, null, null, true);
check('live+abstention: provider throw fails over to legacy', $out === $legacyFn());

// --- 6. abstainedDims helper ----------------------------------------------------
$markedRun7 = DecisionTier::markAbstentions(abAnswers($run7Pos, $run7Conf, $coreFive), 0.65);
check('abstainedDims extracts flagged keys in canonical order',
    ScoreLeadFitAction::abstainedDims($markedRun7, $coreFive) === ['trigger_signals']);
check('abstainedDims on unflagged answers is empty',
    ScoreLeadFitAction::abstainedDims(abAnswers($run7Pos, $run7Conf, $coreFive), $coreFive) === []);

// --- 7. coverage floor ----------------------------------------------------------
check('coverage: 0/5 abstain -> ok', ScoreLeadFitAction::abstentionCoverageOk($weights, $coreFive, []));
check('coverage: 2/5 abstain (60% boundary inclusive) -> ok',
    ScoreLeadFitAction::abstentionCoverageOk($weights, $coreFive, ['trigger_signals', 'geography']));
check('coverage: 3/5 abstain (40% scored) -> fail-closed',
    !ScoreLeadFitAction::abstentionCoverageOk($weights, $coreFive, ['trigger_signals', 'geography', 'target_title']));
check('coverage: 5/5 abstain -> fail-closed',
    !ScoreLeadFitAction::abstentionCoverageOk($weights, $coreFive, $coreFive));
check('coverage: zero total weight -> fail-closed',
    !ScoreLeadFitAction::abstentionCoverageOk(array_fill_keys($coreFive, 0), $coreFive, []));
// tech_stack enabled and abstaining is just a dimension.
$wSix = ['company_size' => 20, 'industry_fit' => 20, 'target_title' => 20,
    'geography' => 20, 'trigger_signals' => 10, 'tech_stack' => 10];
$six = array_merge($coreFive, ['tech_stack']);
check('coverage: tech_stack abstain counts by its own weight (80/100 -> ok)',
    ScoreLeadFitAction::abstentionCoverageOk($wSix, $six, ['tech_stack', 'trigger_signals']));
check('coverage: heavy abstentions with tech_stack (40/100 -> fail-closed)',
    !ScoreLeadFitAction::abstentionCoverageOk($wSix, $six, ['tech_stack', 'trigger_signals', 'geography', 'company_size']));
// Zero-weight abstention has no coverage impact.
check('coverage: zero-weight dim abstaining changes nothing',
    ScoreLeadFitAction::abstentionCoverageOk($weights, $coreFive, ['tech_stack']) === true);

// --- 8. normalizeJevAnswers with abstentions (run-7 numbers) --------------------
$norm = ScoreLeadFitAction::normalizeJevAnswers(
    $markedRun7, $weights, $thresholds, $coreFive, ['trigger_signals']);
check('abstained normalize: triggers excluded from dimension_pcts',
    !isset($norm['dimension_pcts']['trigger_signals']) && count($norm['dimension_pcts']) === 4);
check('abstained normalize: triggers excluded from dimensions',
    !isset($norm['dimensions']['trigger_signals']) && count($norm['dimensions']) === 4);
check('abstained normalize: fit 85 (renormalized over 4 dims)',
    $norm['fit_score'] === 85, 'got ' . var_export($norm['fit_score'], true));
check('abstained normalize: qualified at >= 75', $norm['qualified'] === true && $norm['verdict'] === 'qualified');
check("abstained normalize: 'abstained' reported",
    $norm['abstained'] === ['trigger_signals']);
check('abstained normalize: confidence over scored dims only (min of 4, not 0.19)',
    $norm['confidence'] === 0.70, 'got ' . var_export($norm['confidence'], true));
// Fit is the renormalized mean of the four scored pcts (87.9/86.0/81.4/85.0 -> 85.075 -> 85).
$expectedPcts = [
    'company_size' => 87.9, 'industry_fit' => 86.0,
    'target_title' => 81.4, 'geography' => 85.0,
];
$pctsOk = true;
foreach ($expectedPcts as $d => $p) {
    if (($norm['dimension_pcts'][$d] ?? null) !== $p) {
        $pctsOk = false;
    }
}
check('abstained normalize: per-dimension pcts as expected', $pctsOk);

// --- 9. backward compatibility (null abstentions) -------------------------------
$legacy5 = ScoreLeadFitAction::normalizeJevAnswers($markedRun7, $weights, $thresholds, $coreFive);
check('null abstentions: same fit as the pre-abstention five-dim model (82)',
    $legacy5['fit_score'] === 82, 'got ' . var_export($legacy5['fit_score'], true));
check("null abstentions: 'abstained' === []", $legacy5['abstained'] === []);
check('null abstentions: confidence is the all-dim min (0.19)',
    $legacy5['confidence'] === 0.19);
check('null abstentions: all five dims present', count($legacy5['dimensions']) === 5);

// --- 10. jevReason names abstained dims ------------------------------------------
$ref = new ReflectionMethod(ScoreLeadFitAction::class, 'jevReason');
$ref->setAccessible(true);
$reason = $ref->invoke(null, $norm, ['key' => 'Test ICP'], $coreFive, ['trigger_signals']);
check('jevReason names the abstained dimension',
    str_contains($reason, 'Abstained (insufficient evidence): trigger_signals.'));
check('jevReason omits abstained dim from the scored breakdown (skipped, not 0/10)',
    !str_contains($reason, 'trigger_signals='));
check('jevReason keeps scored dims in the breakdown',
    str_contains($reason, 'company_size=9/10') && str_contains($reason, 'geography=9/10'));
$reasonNoAbst = $ref->invoke(null, $legacy5, ['key' => 'Test ICP'], $coreFive);
check('jevReason without abstentions appends nothing',
    !str_contains($reasonNoAbst, 'Abstained'));

exit(p4j_summary(basename(__FILE__)));
