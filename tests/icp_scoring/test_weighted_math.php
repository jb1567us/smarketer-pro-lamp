#!/usr/bin/env php
<?php
/**
 * Weighted ICP scoring math (subject 2/4, goal_67693fcbba4c).
 *
 * Zero network, zero DB — everything under test is a public static on
 * ScoreLeadFitAction / JevProvider:
 *   1. scoreToPercent: Jev position (0..9) -> 0-100 mapping.
 *   2. Weighted fit = sum(dimension_pct * weight / weight_sum): exact
 *      arithmetic with non-uniform weights.
 *   3. Normalization uses the ACTUAL weight sum as the denominator (not a
 *      hard-coded 100): weights summing to 60 or 30 still rescale correctly.
 *   4. Degenerate inputs: all-zero weights (no division error, fit 0),
 *      missing weight for a dimension (contributes 0, still scored 1-10).
 *   5. 1-10 display conversion, position clamping, confidence = min.
 *   6. Empty-target fallback prose: every dimension's question carries a
 *      documented default target when the buyer has not configured one.
 *   7. Fit-score rounding at the qualify boundary (74.4 -> 74, 74.5 -> 75).
 *
 * Usage: php tests/icp_scoring/test_weighted_math.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require_once ics_repo_root() . '/includes/autoload.php';

use App\Actions\ScoreLeadFitAction;
use App\Icp\IcpProfile;
use App\Jev\JevProvider;

ics_assert_default_mode_off('(start)');

$DIMS = IcpProfile::DIMENSIONS;
$TH = ['qualify' => 75, 'review' => 50];

// --- 1. scoreToPercent -------------------------------------------------------
echo "1. scoreToPercent:\n";
check('position 0 -> 0.0', JevProvider::scoreToPercent(0.0, 10) === 0.0);
check('position 9 -> 100.0', JevProvider::scoreToPercent(9.0, 10) === 100.0);
check('position 4.5 -> 50.0', JevProvider::scoreToPercent(4.5, 10) === 50.0);
check('position 1 -> 11.1', JevProvider::scoreToPercent(1.0, 10) === 11.1);
check('fewer than 2 levels -> 0.0', JevProvider::scoreToPercent(3.0, 1) === 0.0);
check('position 6.705 -> 74.5', JevProvider::scoreToPercent(6.705, 10) === 74.5);

// --- 2. exact weighted arithmetic --------------------------------------------
echo "2. weighted fit arithmetic:\n";
$w = [
    'company_size' => 40, 'industry_fit' => 20, 'tech_stack' => 10,
    'target_title' => 10, 'geography' => 10, 'trigger_signals' => 10,
]; // sums to 100
$n = ScoreLeadFitAction::normalizeJevAnswers(
    ics_dim_answers(['company_size' => 9.0]), $w, $TH);
// company_size pct 100 * 40/100 = 40; everything else 0.
check('non-uniform weights: fit = 40', $n['fit_score'] === 40, json_encode($n['fit_score']));

$n = ScoreLeadFitAction::normalizeJevAnswers(
    ics_dim_answers(['company_size' => 9.0, 'industry_fit' => 4.5]), $w, $TH);
// 100*0.40 + 50*0.20 = 40 + 10 = 50.
check('non-uniform weights: fit = 50', $n['fit_score'] === 50, json_encode($n['fit_score']));

// --- 3. denominator is the ACTUAL weight sum ----------------------------------
echo "3. normalization by actual weight sum:\n";
$w60 = array_fill_keys($DIMS, 10); // sums to 60
$n = ScoreLeadFitAction::normalizeJevAnswers(ics_dim_answers(array_fill_keys($DIMS, 9.0)), $w60, $TH);
check('sum-60 weights: all-9 fit = 100 (rescaled, not 60)',
    $n['fit_score'] === 100, json_encode($n['fit_score']));

$w30 = ['company_size' => 30, 'industry_fit' => 0, 'tech_stack' => 0,
        'target_title' => 0, 'geography' => 0, 'trigger_signals' => 0]; // sums to 30
$n = ScoreLeadFitAction::normalizeJevAnswers(ics_dim_answers(['company_size' => 4.5]), $w30, $TH);
// pct 50 * 30/30 = 50. A hard-coded /100 denominator would have given 15.
check('sum-30 weights: single-dim 50 pct -> fit 50 (denominator is the sum)',
    $n['fit_score'] === 50, json_encode($n['fit_score']));

// --- 4. degenerate inputs ------------------------------------------------------
echo "4. degenerate weight vectors:\n";
$w0 = array_fill_keys($DIMS, 0);
$n = ScoreLeadFitAction::normalizeJevAnswers(ics_dim_answers(array_fill_keys($DIMS, 9.0)), $w0, $TH);
check('all-zero weights: no division error, fit 0',
    $n['fit_score'] === 0 && $n['verdict'] === 'unqualified');

$wMissing = [
    'company_size' => 17, 'industry_fit' => 17, 'tech_stack' => 0,
    'target_title' => 17, 'geography' => 17, 'trigger_signals' => 17,
]; // 'tech_stack' weight absent below
unset($wMissing['tech_stack']);
$n = ScoreLeadFitAction::normalizeJevAnswers(ics_dim_answers(array_fill_keys($DIMS, 9.0)), $wMissing, $TH);
check('missing weight key: fit still 100 (denominator 85)',
    $n['fit_score'] === 100, json_encode($n['fit_score']));
check('missing weight key: dimension still scored 10/10 with 100.0 pct',
    $n['dimensions']['tech_stack'] === 10 && $n['dimension_pcts']['tech_stack'] === 100.0);

// --- 5. display conversion / clamping / confidence ----------------------------
echo "5. display scores, clamping, confidence:\n";
$n = ScoreLeadFitAction::normalizeJevAnswers(
    ics_dim_answers(['company_size' => 0.0, 'industry_fit' => 7.5, 'tech_stack' => 8.9], 0.9),
    ics_profile()['weights'], $TH);
check('position 0 -> display 1', $n['dimensions']['company_size'] === 1);
check('position 7.5 -> display 9 (round half up)', $n['dimensions']['industry_fit'] === 9);
check('position 8.9 -> display 10', $n['dimensions']['tech_stack'] === 10);

$n = ScoreLeadFitAction::normalizeJevAnswers(
    ics_dim_answers(['company_size' => 42.0, 'industry_fit' => -3.0]),
    ics_profile()['weights'], $TH);
check('positions clamp to the 0-9 spectrum',
    $n['dimension_pcts']['company_size'] === 100.0
    && $n['dimension_pcts']['industry_fit'] === 0.0);

$n = ScoreLeadFitAction::normalizeJevAnswers(
    ics_dim_answers(array_fill_keys($DIMS, 9.0), 0.4),
    ics_profile()['weights'], $TH);
check('confidence = min of answer confidences', abs($n['confidence'] - 0.4) < 1e-9);

$noConf = [];
foreach ($DIMS as $d) {
    $noConf['dim_' . $d] = ['score' => 9.0]; // no 'confidence' key
}
$n = ScoreLeadFitAction::normalizeJevAnswers($noConf, ics_profile()['weights'], $TH);
check('missing confidences default to 1.0', $n['confidence'] === 1.0);

// --- 6. empty-target fallback prose ---------------------------------------------
echo "6. empty-target fallback prose:\n";
$qs = ScoreLeadFitAction::buildFitQuestions(ics_profile()['dimensions']);
$fallbacks = [
    'company_size'    => '10-500 employees',
    'industry_fit'    => 'proactive outreach',
    'tech_stack'      => 'CRM/marketing/sales',
    'target_title'    => 'decision-maker or budget influencer',
    'geography'       => 'US, UK, EU, Canada, Australia',
    'trigger_signals' => 'recent hiring',
];
foreach ($fallbacks as $dim => $phrase) {
    $instr = (string)($qs['dim_' . $dim]['instructions'] ?? '');
    check("empty target_config for {$dim} renders documented default ('{$phrase}')",
        stripos($instr, $phrase) !== false);
}

// Configured targets REPLACE the default (not appended silently).
$prof = ics_profile();
$prof['dimensions']['company_size']['target_config'] = ['min_employees' => 200, 'max_employees' => 1000];
$prof['dimensions']['target_title']['target_config'] = ['titles' => ['VP Sales', 'CMO']];
$prof['dimensions']['trigger_signals']['target_config'] = ['signals' => ['funding']];
$qs2 = ScoreLeadFitAction::buildFitQuestions($prof['dimensions']);
check('configured company_size range renders, default range gone',
    stripos((string)$qs2['dim_company_size']['instructions'], '200+ to 1000 employees') !== false
    && stripos((string)$qs2['dim_company_size']['instructions'], '10-500') === false);
check('configured titles render',
    stripos((string)$qs2['dim_target_title']['instructions'], 'VP Sales') !== false
    && stripos((string)$qs2['dim_target_title']['instructions'], 'CMO') !== false);
check('configured trigger signals render',
    stripos((string)$qs2['dim_trigger_signals']['instructions'], 'funding') !== false);

// --- 7. fit rounding at the qualify boundary --------------------------------------
echo "7. fit rounding at boundaries:\n";
$wSingle = ['company_size' => 100, 'industry_fit' => 0, 'tech_stack' => 0,
            'target_title' => 0, 'geography' => 0, 'trigger_signals' => 0];
$n = ScoreLeadFitAction::normalizeJevAnswers(ics_dim_answers(['company_size' => 6.705]), $wSingle, $TH);
check('pct 74.5 rounds fit to 75 -> qualified',
    $n['fit_score'] === 75 && $n['verdict'] === 'qualified' && $n['qualified'] === true);
$n = ScoreLeadFitAction::normalizeJevAnswers(ics_dim_answers(['company_size' => 6.696]), $wSingle, $TH);
check('pct 74.4 rounds fit to 74 -> needs_review',
    $n['fit_score'] === 74 && $n['verdict'] === 'needs_review' && $n['qualified'] === false);

ics_assert_default_mode_off('(end)');
exit(ics_summary('test_weighted_math.php'));
