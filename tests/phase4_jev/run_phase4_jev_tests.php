#!/usr/bin/env php
<?php
// Orchestrator: php -l the Phase 4 JEV decision-port files, then runs each
// test file in its own PHP process (simulating a fresh request), and reports
// per category.
//
// NOTE: tests/phase4_jev/ is the JEV decision-port Phase 4 suite
// (goal_67693fcbba4c). The tests/phase4/ directory belongs to the n8n
// ingestion-hardening workstream and is left untouched.
declare(strict_types=1);

$dir  = __DIR__;
$repo = dirname(__DIR__, 2);

$newFiles = [
    $repo . '/includes/Actions/SendGateAction.php',
    $repo . '/includes/Actions/EnrichSufficiencyAction.php',
    $repo . '/includes/Actions/FollowUpTimingAction.php',
    $repo . '/api/send_email.php',
];

$testFiles = [
    'test_send_gate_rules.php'   => 'send gate rule gates (suppression/CASL/verification/quota), fail-closed contract',
    'test_enrich_sufficiency.php'=> 'enrichment sufficiency completeness criteria, loop guard, verdict shape',
    'test_followup_timing.php'   => 'follow-up cadence fallback, JEV normalization + sanity clamp',
    'test_decision_modes.php'    => 'off/shadow/live contract at the DecisionTier level + send_email.php wiring',
];

$php = PHP_BINARY;
$totalFail = 0;

// --- (a) syntax/lint ---------------------------------------------------------
echo "=== (a) php -l syntax check ===\n";
foreach (array_merge($newFiles, glob($dir . '/*.php')) as $f) {
    $cmd = escapeshellarg($php) . ' -l ' . escapeshellarg($f) . ' 2>&1';
    exec($cmd, $out, $code);
    $short = substr($f, strlen($repo) + 1);
    if ($code === 0) {
        echo "  PASS: php -l {$short}\n";
    } else {
        echo "  FAIL: php -l {$short}\n" . implode("\n", $out) . "\n";
        $totalFail++;
    }
    $out = [];
}
echo "\n";

// --- test files ----------------------------------------------------------------
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

echo $totalFail === 0 ? "ALL PHASE 4 JEV TESTS PASSED\n" : "FAILURES: {$totalFail}\n";
exit($totalFail === 0 ? 0 : 1);
