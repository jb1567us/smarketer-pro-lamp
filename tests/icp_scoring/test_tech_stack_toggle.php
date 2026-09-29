#!/usr/bin/env php
<?php
/**
 * tech_stack toggle coverage (subject 2/4 toggle rework, goal_67693fcbba4c).
 *
 * tech_stack is a TOGGLEABLE dimension, OFF by default. The default-off
 * state is mathematically identical to the five-dimension model: no prompt
 * question (zero tokens), no aggregation weight, no output. When the buyer
 * enables it, it is scored on DISCOVERABILITY (how much of the target tech
 * surface was actually found) — never on stack "goodness" — and never from
 * invented evidence.
 *
 * Zero network, zero DB: fixture profiles use ScoreLeadFitAction's
 * $profileOverride-style dimension arrays; setDimensionEnabled()'s
 * pre-database validation is checked here, the write paths in
 * tests/icp/test_adjust_weights_integration.php (F7).
 *
 *   1. enabledKeys(): disabled dims excluded, missing 'enabled' flag treated
 *      as enabled (backward-compatible fixtures), canonical dimension order.
 *   2. Disabled tech_stack: absent from buildFitQuestions() (five questions,
 *      zero tokens), absent from normalizeJevAnswers() aggregation and from
 *      jevReason() output — even when its weight key is nonzero (the flag,
 *      not weight 0, is what excludes it).
 *   3. Enabled tech_stack: sixth question appears with the exact
 *      discoverability rubric (9-10 / 7-8 / 4-6 / 2-3 / 1 bands, never-invent
 *      evidence); weights renormalize to 100 across the enabled set.
 *   4. isJevFitAnswers(): answers are validated against the ENABLED dims —
 *      extra keys tolerated, missing enabled dims rejected.
 *   5. setDimensionEnabled(): a non-toggleable dimension throws before any
 *      database write.
 *
 * Usage: php tests/icp_scoring/test_tech_stack_toggle.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require_once ics_repo_root() . '/includes/autoload.php';

use App\Actions\ScoreLeadFitAction;
use App\Icp\IcpProfile;

ics_assert_default_mode_off('(start)');

$DIMS = IcpProfile::DIMENSIONS; // six, tech_stack last
$TH = ['qualify' => 75, 'review' => 50];
$coreFive = ['company_size', 'industry_fit', 'target_title', 'geography', 'trigger_signals'];

// --- 1. enabledKeys ------------------------------------------------------------
echo "1. enabledKeys():\n";
$offDims = ics_profile()['dimensions']; // tech_stack enabled=false
check('default fixture: five enabled, canonical order',
    ScoreLeadFitAction::enabledKeys($offDims) === $coreFive);
$onDims = $offDims;
$onDims['tech_stack']['enabled'] = true;
check('enabled tech_stack: six keys, tech_stack last',
    ScoreLeadFitAction::enabledKeys($onDims) === array_merge($coreFive, ['tech_stack']));
// Backward compatibility: fixtures without the flag count as enabled.
$legacyDims = [];
foreach ($offDims as $d => $meta) {
    unset($meta['enabled']);
    $legacyDims[$d] = $meta;
}
check('missing enabled flag = enabled (old fixtures keep working)',
    ScoreLeadFitAction::enabledKeys($legacyDims) === $DIMS);
// Canonical order even when the input map is shuffled.
$shuffled = [];
foreach (array_reverse($DIMS) as $d) {
    $shuffled[$d] = $onDims[$d];
}
check('canonical order regardless of input order',
    ScoreLeadFitAction::enabledKeys($shuffled) === array_merge($coreFive, ['tech_stack']));

// --- 2. Disabled: absent from prompt, aggregation, reason ---------------------
echo "2. disabled tech_stack:\n";
$qs = ScoreLeadFitAction::buildFitQuestions($offDims);
check('no dim_tech_stack question (zero tokens), five questions total',
    !isset($qs['dim_tech_stack']) && count($qs) === 5,
    implode(',', array_keys($qs)));
$qsOn = ScoreLeadFitAction::buildFitQuestions($onDims);
check('enabled: six questions', count($qsOn) === 6 && isset($qsOn['dim_tech_stack']));

// Aggregation: a terrible tech score must not move the fit while disabled.
$wOff = ['company_size' => 20, 'industry_fit' => 20, 'target_title' => 20,
         'geography' => 20, 'trigger_signals' => 20, 'tech_stack' => 0];
$ans = ics_dim_answers(array_fill_keys($DIMS, 10.0));
$ans['dim_tech_stack']['score'] = 1.0;
$n = ScoreLeadFitAction::normalizeJevAnswers($ans, $wOff, $TH, $coreFive);
check('disabled: tech=1/10 does not dilute the fit (100)',
    $n['fit_score'] === 100, json_encode($n['fit_score']));
check('disabled: no tech_stack output key',
    !isset($n['dimensions']['tech_stack']) && !isset($n['dimension_pcts']['tech_stack'])
    && count($n['dimensions']) === 5);
// The FLAG excludes the dimension, not weight 0: even a nonzero weight key
// for a disabled dimension must not enter the denominator.
$wStale = $wOff;
$wStale['tech_stack'] = 17;
$n2 = ScoreLeadFitAction::normalizeJevAnswers($ans, $wStale, $TH, $coreFive);
check('disabled: stray tech weight 17 ignored (denominator 100, fit 100)',
    $n2['fit_score'] === 100, json_encode($n2['fit_score']));
$jevReason = new ReflectionMethod(ScoreLeadFitAction::class, 'jevReason');
$profile = ['key' => 'Test ICP'];
$reason = $jevReason->invoke(null, $n, $profile, $coreFive);
check('disabled: reason lists five dimensions, no tech_stack part',
    !str_contains($reason, 'tech_stack') && str_contains($reason, 'trigger_signals=10/10'),
    $reason);

// --- 3. Enabled: discoverability rubric + renormalized weights ----------------
echo "3. enabled tech_stack:\n";
$instr = (string)($qsOn['dim_tech_stack']['instructions'] ?? '');
check('instructions score discoverability, not goodness',
    stripos($instr, 'DISCOVERABILITY') !== false
    && stripos($instr, 'NOT whether') !== false
    && stripos($instr, 'evidence availability') !== false);
check('all five bands present',
    str_contains($instr, '9-10') && str_contains($instr, '7-8')
    && str_contains($instr, '4-6') && str_contains($instr, '2-3')
    && str_contains($instr, '1 = nothing discoverable'));
check('never-invent-evidence is prominent',
    stripos($instr, 'never invent evidence') !== false);
$wOn = ['company_size' => 15, 'industry_fit' => 15, 'target_title' => 15,
        'geography' => 15, 'trigger_signals' => 15, 'tech_stack' => 25];
$all10 = ics_dim_answers(array_fill_keys($DIMS, 10.0));
$nOn = ScoreLeadFitAction::normalizeJevAnswers($all10, $wOn, $TH, array_merge($coreFive, ['tech_stack']));
check('enabled: weights renormalize to 100 (six dims, all 10 -> fit 100)',
    $nOn['fit_score'] === 100 && count($nOn['dimensions']) === 6,
    json_encode($nOn['fit_score']));
// Weighted math with tech enabled: five at display 10 (15 each) + tech at
// display 1 (position 0, weight 25) -> (75 * 100 + 25 * 0) / 100 = 75.
$mixed = $all10;
$mixed['dim_tech_stack']['score'] = 0.0;
$nMix = ScoreLeadFitAction::normalizeJevAnswers($mixed, $wOn, $TH, array_merge($coreFive, ['tech_stack']));
check('enabled: nothing-found tech pulls the fit (75)',
    $nMix['fit_score'] === 75 && $nMix['verdict'] === 'qualified',
    json_encode($nMix['fit_score']));
$reasonOn = $jevReason->invoke(null, $nMix, $profile, array_merge($coreFive, ['tech_stack']));
check('enabled: reason carries the tech_stack part',
    str_contains($reasonOn, 'tech_stack=1/10'), $reasonOn);

// --- 4. isJevFitAnswers: validated against the enabled set -------------------
echo "4. isJevFitAnswers():\n";
$isFit = new ReflectionMethod(ScoreLeadFitAction::class, 'isJevFitAnswers');
$allSix = array_merge($coreFive, ['tech_stack']);
check('six-key answers valid for six enabled',
    $isFit->invoke(null, $all10, $allSix) === true);
check('six-key answers valid for five enabled (extras tolerated)',
    $isFit->invoke(null, $all10, $coreFive) === true);
$noTech = $all10;
unset($noTech['dim_tech_stack']);
check('missing tech valid when disabled',
    $isFit->invoke(null, $noTech, $coreFive) === true);
check('missing tech INVALID when enabled',
    $isFit->invoke(null, $noTech, $allSix) === false);
$noSize = $all10;
unset($noSize['dim_company_size']);
check('missing company_size invalid',
    $isFit->invoke(null, $noSize, $coreFive) === false);

// --- 5. setDimensionEnabled: pre-write validation ------------------------------
echo "5. setDimensionEnabled():\n";
// newInstanceWithoutConstructor: the dimension-key validation throws before
// loadDimensions() (and therefore before any database write), so no PDO is
// needed at all.
$action = (new ReflectionClass(\App\Actions\AdjustIcpWeightsAction::class))
    ->newInstanceWithoutConstructor();
$threw = false;
try {
    $action->setDimensionEnabled(1, 'geography', true, [], 'must throw');
} catch (\InvalidArgumentException $e) {
    $threw = str_contains($e->getMessage(), 'not toggleable');
}
check('non-toggleable dimension throws before any write', $threw);

ics_assert_default_mode_off('(end)');
exit(ics_summary('test_tech_stack_toggle.php'));
