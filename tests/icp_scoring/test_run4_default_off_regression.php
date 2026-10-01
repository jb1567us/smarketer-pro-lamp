#!/usr/bin/env php
<?php
/**
 * Frozen run-4 deterministic regression: default-off tech_stack toggle.
 *
 * Product invariant: with tech_stack disabled (the default), scoring is
 * mathematically identical to the five-dimension model. This pins that
 * invariant against the frozen TypeSafe run-4 real-data benchmark
 * (tests/icp_scoring/fixtures/run4-frozen-dimensions.json — 24 real
 * companies, labels frozen pre-scoring, generated once from the 2026-09-29
 * frozen run-4 file; never re-scored).
 *
 * Method: each lead's frozen 1-10 display scores are converted to the
 * production 0-9 positions (display - 1) and run through the REAL
 * ScoreLeadFitAction::normalizeJevAnswers() with the default-off profile
 * shape (five core dims x 20, tech_stack disabled at 0, enabled = the five
 * cores). CIENCE is the hard-veto zero case (exclusion fires -> fit 0, no
 * model call), exactly as production handles it. Zero network, zero DB.
 *
 * Expected (from tech-stack-removal-recalc-2026-09-29.md, reproduced here
 * through the production code path):
 *   - 3-way agreement: 22/24 = 91.7%
 *   - clear-binary agreement (qualified/unqualified humans only): 16/16
 *   - zero severe flips (qualified<->unqualified)
 *   - the two misses are the documented ones: Qualified (human
 *     needs_review -> model qualified at 76, pre-existing) and Accurx
 *     (human needs_review -> model qualified at 78, the canary)
 *   - per-lead fit scores equal the recalc table exactly
 *
 * Usage: php tests/icp_scoring/test_run4_default_off_regression.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require_once ics_repo_root() . '/includes/autoload.php';

use App\Actions\ScoreLeadFitAction;
use App\Icp\IcpProfile;

ics_assert_default_mode_live('(start)');

$fx = json_decode(
    file_get_contents(__DIR__ . '/fixtures/run4-frozen-dimensions.json'),
    true
);
check('fixture loads: 24 leads', is_array($fx['leads'] ?? null) && count($fx['leads']) === 24);

$TH = $fx['thresholds'];
$enabled = ['company_size', 'industry_fit', 'target_title', 'geography', 'trigger_signals'];
$weights = array_fill_keys($enabled, 20);
$weights['tech_stack'] = 0;

// The default-off profile shape is what makes this the five-dimension model:
// tech_stack present but disabled.
$defaultOff = ics_profile()['dimensions'];
check('default fixture: tech_stack disabled',
    ScoreLeadFitAction::enabledKeys($defaultOff) === $enabled);

// Expected per-lead fit scores (recalc table, reproduced through production).
$expectedFit = [
    'Chili Piper' => 84, 'ChurnZero' => 82, 'Cognism' => 80, 'Beamery' => 78,
    'Vidyard' => 82, 'Loopio' => 80, 'Klue' => 80, 'Humi' => 78,
    'CIENCE Technologies' => 0, 'Toyota Motor Corporation' => 0,
    'Marriott International' => 20, 'Nike, Inc.' => 24, 'Greggs plc' => 18,
    'Mayo Clinic' => 18, "Gold'n Fresh Bakery" => 18, 'Plumber Westminster' => 18,
    'CharlieHR' => 53, 'Vendasta' => 60, 'Usercentrics' => 67, 'Deputy' => 67,
    'lemlist' => 53, 'Reprise' => 64, 'Qualified' => 76, 'Accurx' => 78,
];

$agree3 = 0;
$agreeBinary = 0;
$clearN = 0;
$severeFlips = [];
$misses = [];
$fitDiffs = [];

foreach ($fx['leads'] as $lead) {
    $company = $lead['company'];
    $human = $lead['human'];
    if (!empty($lead['veto'])) {
        // Hard-veto zero case: exclusion fires, fit forced to 0, no model call.
        $fit = 0;
        $verdict = 'unqualified';
    } else {
        $positions = array_map(fn($d) => $d - 1, $lead['dimensions']);
        $n = ScoreLeadFitAction::normalizeJevAnswers(
            ics_dim_answers($positions), $weights, $TH, $enabled
        );
        $fit = $n['fit_score'];
        $verdict = $n['verdict'];
    }
    if (array_key_exists($company, $expectedFit) && $expectedFit[$company] !== $fit) {
        $fitDiffs[] = "{$company}: expected {$expectedFit[$company]}, got {$fit}";
    }
    if ($verdict === $human) {
        $agree3++;
    } else {
        $misses[] = "{$company}: human={$human} model={$verdict} fit={$fit}";
    }
    if (in_array($human, ['qualified', 'unqualified'], true)) {
        $clearN++;
        if ($verdict === $human) {
            $agreeBinary++;
        }
    }
    if (($human === 'qualified' && $verdict === 'unqualified')
        || ($human === 'unqualified' && $verdict === 'qualified')) {
        $severeFlips[] = $company;
    }
}

check('per-lead fit scores match the recalc table exactly',
    $fitDiffs === [], implode('; ', $fitDiffs));
check('3-way agreement: 22/24 = 91.7%', $agree3 === 22, "{$agree3}/24");
check('clear-binary agreement: 16/16', $clearN === 16 && $agreeBinary === 16,
    "{$agreeBinary}/{$clearN}");
check('zero severe flips (qualified<->unqualified)', $severeFlips === [],
    implode(',', $severeFlips));
// The two misses are the documented ones — the test names them so a new
// miss can never hide inside the 22/24.
sort($misses);
check('the only misses are Qualified (pre-existing) and Accurx (canary)',
    $misses === [
        'Accurx: human=needs_review model=qualified fit=78',
        'Qualified: human=needs_review model=qualified fit=76',
    ],
    implode(' | ', $misses));

ics_assert_default_mode_live('(end)');
exit(ics_summary('test_run4_default_off_regression.php'));
