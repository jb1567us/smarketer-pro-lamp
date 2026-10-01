#!/usr/bin/env php
<?php
// Orchestrator: php -l the weighted-ICP subject files, runs each test file
// in tests/icp_scoring/ in its own PHP process, and enforces the
// shadow-first meta-guard (no test may persist a 'live' default or write
// jev settings rows; every test file ends with DecisionTier::resetForTests()
// and a default-mode-off check).
//
// Usage: php tests/icp_scoring/run_icp_scoring_tests.php
declare(strict_types=1);

$dir  = __DIR__;
$repo = dirname(__DIR__, 2);

$lintFiles = [
    $repo . '/includes/Icp/IcpProfile.php',
    $repo . '/includes/Icp/IcpProfileSnapshot.php',
    $repo . '/includes/Actions/ScoreLeadFitAction.php',
    $repo . '/includes/Actions/QualifyLeadAction.php',
    $repo . '/includes/Actions/AdjustIcpWeightsAction.php',
    $repo . '/api/icp.php',
    $repo . '/cron/process_queue.php',
];

$testFiles = [
    'test_weighted_math.php'     => 'weighted scoring math (weights x pcts, normalization, fallback prose, rounding)',
    'test_threshold_routing.php' => 'threshold bands + IcpProfile thresholds/updateWeights/exclusions data-model (scratch DB)',
    'test_exclusion_veto.php'    => 'hard exclusion veto in off/shadow/live, before any Jev call',
    'test_shadow_contract.php'   => 'per-dimension shadow extraction + JSONL shape + agreement rule',
    'test_feedback_math.php'     => 'feedback-loop math (nudge clamp, renormalization, floor, locks, reset, cron gate)',
    'test_api_contract.php'      => 'api/icp.php auth/CSRF/action allowlist (static)',
    'test_profile_snapshots.php' => 'P4 immutable profile snapshots + run pins + repoint-rollback (scratch DB)',
];

$php = PHP_BINARY;
$totalFail = 0;

// --- (a) syntax/lint ----------------------------------------------------------
echo "=== (a) php -l syntax check ===\n";
foreach (array_merge($lintFiles, glob($dir . '/*.php')) as $f) {
    $cmd = escapeshellarg($php) . ' -l ' . escapeshellarg($f) . ' 2>&1';
    exec($cmd, $out, $code);
    $short = str_replace($repo . '/', '', $f);
    if ($code === 0) {
        echo "  PASS: php -l {$short}\n";
    } else {
        echo "  FAIL: php -l {$short}\n" . implode("\n", $out) . "\n";
        $totalFail++;
    }
    $out = [];
}
echo "\n";

// --- (b) shadow-first meta-guard ------------------------------------------------
// No test file in this suite may persist settings rows or otherwise change
// the effective default: the shipped default (enabled + live since the
// 2026-10-01 ship call) lives in the DecisionTier defaults, not in stored
// rows. Tier modes are only ever injected per-process via reflection.
echo "=== (b) shipped-default meta-guard ===\n";
$guardFail = 0;
foreach (glob($dir . '/test_*.php') as $f) {
    $src = file_get_contents($f);
    $short = basename($f);
    $problems = [];
    $usesScratchDb = stripos($src, 'CREATE DATABASE') !== false;
    if (!$usesScratchDb && preg_match('/\b(UPDATE|INSERT)\b[^;]*\bsettings\b/i', $src)) {
        $problems[] = 'writes to the settings table (no scratch DB)';
    }
    if (preg_match("/['\"]jev_mode['\"]/", $src) && strpos($src, 'ics_inject_tier') === false) {
        $problems[] = "mentions jev_mode outside the reflection-injection helper";
    }
    if (strpos($src, 'ics_assert_default_mode_live') === false) {
        $problems[] = 'missing shipped-default guard';
    }
    if ($problems === []) {
        echo "  PASS: {$short} (no persisted settings rows; shipped-default guard present)\n";
    } else {
        echo "  FAIL: {$short} — " . implode('; ', $problems) . "\n";
        $guardFail++;
    }
}
if ($guardFail > 0) {
    $totalFail++;
}
echo "\n";

// --- (c) test files --------------------------------------------------------------
foreach ($testFiles as $t => $desc) {
    echo "=== {$t} — {$desc} ===\n";
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($dir . '/' . $t);
    passthru($cmd, $code);
    if ($code !== 0) {
        echo "!! {$t} exited with code {$code}\n";
        $totalFail++;
    }
    echo "\n";
}

echo $totalFail === 0 ? "ALL ICP SCORING TESTS PASSED\n" : "FAILURES: {$totalFail}\n";
exit($totalFail === 0 ? 0 : 1);
