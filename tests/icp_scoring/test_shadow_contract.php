#!/usr/bin/env php
<?php
/**
 * Per-dimension shadow extraction + agreement rule (subject 2/4,
 * goal_67693fcbba4c).
 *
 * ScoreLeadFitAction extends the existing shadow JSONL logging
 * (logs/jev_shadow.jsonl) via the shadowExtract()/shadowAgree() factories.
 * Zero network, zero DB:
 *   1. shadowExtract() units: Jev answers -> {verdict, fit_score,
 *      dimensions (five pcts), source 'jev'}; legacy shapes -> verdict
 *      derived from qualified, fit_score from score, source from the
 *      legacy 'source' field; malformed Jev answers (missing a dimension)
 *      fall back to the legacy branch.
 *   2. shadowAgree() units: agreement = same binary act-decision
 *      (qualified vs not, at the qualify threshold) AND |fit - legacy|
 *      <= 15. Boundary: exactly 15 -> agree, 15.1 -> disagree. Verdict
 *      mismatch -> disagree even with identical scores. Custom thresholds
 *      move the binary boundary.
 *   3. One DecisionTier-level shadow round-trip: the JSONL record carries
 *      decision/jev_value/llm_value/agree/jev_answers; the legacy result
 *      is returned unchanged (zero behavior change); disagreement is
 *      logged when verdicts diverge.
 *
 * Usage: php tests/icp_scoring/test_shadow_contract.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require_once ics_repo_root() . '/includes/autoload.php';

use App\Actions\ScoreLeadFitAction;
use App\Icp\IcpProfile;
use App\Jev\DecisionTier;

ics_assert_default_mode_off('(start)');

$DIMS = IcpProfile::DIMENSIONS;
$weights = ics_profile()['weights'];
$TH = ['qualify' => 75, 'review' => 50];
$all9 = array_fill_keys($DIMS, 9.0);
// The fixture models the production default: tech_stack disabled, so the
// shadow record carries the five enabled dimensions' pcts.
$enabledFive = ScoreLeadFitAction::enabledKeys(ics_profile()['dimensions']);

// --- 1. shadowExtract() units --------------------------------------------------
echo "1. shadowExtract():\n";
$extract = ScoreLeadFitAction::shadowExtract($weights, $TH, $enabledFive);

$jv = $extract(ics_dim_answers($all9));
check('jev answers -> verdict qualified, fit 100, five pcts, source jev',
    $jv['verdict'] === 'qualified' && $jv['fit_score'] === 100
    && count($jv['dimensions']) === 5
    && $jv['dimensions']['company_size'] === 100.0
    && $jv['source'] === 'jev');

$jv = $extract(ics_dim_answers(array_fill_keys($DIMS, 4.5)));
check('jev answers in review band -> verdict needs_review',
    $jv['verdict'] === 'needs_review' && $jv['fit_score'] === 50);

$lv = $extract(['qualified' => true, 'score' => 82, 'reason' => 'legacy', 'source' => 'llm']);
check("legacy shape -> verdict qualified, fit 82.0, source 'llm'",
    $lv['verdict'] === 'qualified' && $lv['fit_score'] === 82.0
    && $lv['dimensions'] === [] && $lv['source'] === 'llm');

$lv = $extract(['qualified' => false, 'score' => 30, 'reason' => 'legacy', 'source' => 'llm']);
check("legacy unqualified -> verdict 'unqualified'",
    $lv['verdict'] === 'unqualified' && $lv['fit_score'] === 30.0);

// Malformed Jev answers (one dimension missing) take the legacy branch.
$broken = ics_dim_answers($all9);
unset($broken['dim_trigger_signals']);
$broken['qualified'] = true;
$broken['score'] = 70;
$broken['source'] = 'llm';
$b = $extract($broken);
check('malformed jev answers (missing a dimension) fall back to the legacy branch',
    $b['verdict'] === 'qualified' && $b['fit_score'] === 70.0
    && $b['dimensions'] === [] && $b['source'] === 'llm');

// --- 2. shadowAgree() units -----------------------------------------------------
echo "2. shadowAgree():\n";
$agree = ScoreLeadFitAction::shadowAgree($TH);

$jvQ = ['verdict' => 'qualified', 'fit_score' => 100.0];
$lvQ = ['verdict' => 'qualified', 'fit_score' => 90.0];
check('same binary verdict, diff 10 -> agree', $agree($jvQ, $lvQ) === true);

$lv15 = ['verdict' => 'qualified', 'fit_score' => 85.0];
check('boundary: diff exactly 15 -> agree', $agree($jvQ, $lv15) === true);

$lv16 = ['verdict' => 'qualified', 'fit_score' => 84.9];
check('diff 15.1 -> disagree', $agree($jvQ, $lv16) === false);

$jvU = ['verdict' => 'unqualified', 'fit_score' => 30.0];
check('both unqualified, diff 0 -> agree',
    $agree($jvU, ['verdict' => 'unqualified', 'fit_score' => 30.0]) === true);

$jvNR = ['verdict' => 'needs_review', 'fit_score' => 74.0];
check('verdict mismatch (needs_review vs qualified) with identical scores -> disagree',
    $agree($jvNR, ['verdict' => 'qualified', 'fit_score' => 74.0]) === false);

$agreeCustom = ScoreLeadFitAction::shadowAgree(['qualify' => 80, 'review' => 60]);
check('custom threshold: fit 79 acts unqualified -> disagrees with qualified legacy',
    $agreeCustom(['verdict' => 'needs_review', 'fit_score' => 79.0],
                 ['verdict' => 'qualified', 'fit_score' => 79.0]) === false);
check('custom threshold: fit 80 acts qualified -> agrees',
    $agreeCustom(['verdict' => 'qualified', 'fit_score' => 80.0],
                 ['verdict' => 'qualified', 'fit_score' => 80.0]) === true);

// --- 3. DecisionTier-level shadow round-trip -------------------------------------
echo "3. shadow JSONL round-trip:\n";
$shadowLog = rtrim(sys_get_temp_dir(), '/\\') . '/ics_shadow_' . getmypid() . '.jsonl';
@unlink($shadowLog);
ics_inject_tier(['enabled' => true, 'mode' => 'shadow', 'shadow_log' => $shadowLog], new IcsFakeJev());

IcsFakeJev::reset();
$legacy = fn() => ['qualified' => false, 'score' => 30, 'reason' => 'legacy', 'source' => 'llm'];
IcsFakeJev::$script = [ics_dim_answers($all9)];
$out = DecisionTier::decide(
    'lead_fit.score_fit',
    ['lead_context' => 'x'],
    ScoreLeadFitAction::buildFitQuestions(ics_profile()['dimensions']),
    $legacy,
    ScoreLeadFitAction::shadowExtract($weights, $TH, $enabledFive),
    ScoreLeadFitAction::shadowAgree($TH)
);
check('shadow: legacy result returned unchanged (zero behavior change)', $out === $legacy());

$rec = null;
foreach ((array)@file($shadowLog) as $line) {
    $row = json_decode($line, true);
    if (is_array($row) && ($row['decision'] ?? '') === 'lead_fit.score_fit') {
        $rec = $row;
    }
}
check('shadow: exactly one JSONL record for lead_fit.score_fit', $rec !== null);
check('shadow: jev_value carries fit_score, verdict, five pcts, source jev',
    $rec !== null
    && ($rec['jev_value']['fit_score'] ?? null) === 100
    && ($rec['jev_value']['verdict'] ?? '') === 'qualified'
    && count($rec['jev_value']['dimensions'] ?? []) === 5
    && ($rec['jev_value']['dimensions']['trigger_signals'] ?? null) == 100
    && ($rec['jev_value']['source'] ?? '') === 'jev');
check('shadow: llm_value carries legacy verdict + score',
    $rec !== null
    && ($rec['llm_value']['verdict'] ?? '') === 'unqualified'
    && ($rec['llm_value']['fit_score'] ?? null) == 30);
check('shadow: disagree logged (jev qualified/100 vs legacy unqualified/30)',
    $rec !== null && ($rec['agree'] ?? null) === false);
check('shadow: raw jev_answers present with per-dimension scores',
    $rec !== null && isset($rec['jev_answers']['dim_company_size']['score']));
@unlink($shadowLog);

// Agreement case: legacy qualified/95 vs jev 100 -> agree true.
$shadowLog2 = rtrim(sys_get_temp_dir(), '/\\') . '/ics_shadow2_' . getmypid() . '.jsonl';
@unlink($shadowLog2);
ics_inject_tier(['enabled' => true, 'mode' => 'shadow', 'shadow_log' => $shadowLog2], new IcsFakeJev());
IcsFakeJev::reset();
$legacy95 = fn() => ['qualified' => true, 'score' => 95, 'reason' => 'legacy', 'source' => 'llm'];
IcsFakeJev::$script = [ics_dim_answers($all9)];
DecisionTier::decide(
    'lead_fit.score_fit',
    ['lead_context' => 'x'],
    ScoreLeadFitAction::buildFitQuestions(ics_profile()['dimensions']),
    $legacy95,
    ScoreLeadFitAction::shadowExtract($weights, $TH, $enabledFive),
    ScoreLeadFitAction::shadowAgree($TH)
);
$rec2 = null;
foreach ((array)@file($shadowLog2) as $line) {
    $row = json_decode($line, true);
    if (is_array($row) && ($row['decision'] ?? '') === 'lead_fit.score_fit') {
        $rec2 = $row;
    }
}
check('shadow: agree=true logged when verdicts match and scores within 15',
    $rec2 !== null && ($rec2['agree'] ?? null) === true);
@unlink($shadowLog2);

ics_assert_default_mode_off('(end)');
exit(ics_summary('test_shadow_contract.php'));
