#!/usr/bin/env php
<?php
// Orchestrator: php -l all needs_review files, then runs each test file in
// its own PHP process (simulating a fresh request), and reports per file.
// Sibling-gated tests SKIP (not fail) when the sibling's artifact has not
// landed yet — see tests/needs_review/common.php.
declare(strict_types=1);

$dir  = __DIR__;
$repo = dirname(__DIR__, 2);

$testFiles = [
    'test_fixtures.php'              => 'fixtures: one lead per qualification state (independent)',
    'test_migration_idempotency.php' => 'ENUM migration applies twice, clean no-op (sibling 1: schema)',
    'test_qualification_routing.php'  => 'verdict -> status routing for the 50-75 band (sibling 2: decision point)',
    'test_state_transitions.php'     => 'needs_review transitions + review-API enforcement (sibling 3: review workflow)',
    'test_no_leak.php'               => 'no-leak: launch / enrollment / queue send-time gate (sibling 2 audit)',
    'test_review_api_auth.php'       => 'review endpoint auth: 401 unauthenticated, CSRF (sibling 3: review workflow)',
];

$php = PHP_BINARY;
$totalFail = 0;

// --- (a) syntax/lint ---------------------------------------------------------
echo "=== (a) php -l syntax check ===\n";
foreach (glob($dir . '/*.php') as $f) {
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

// --- (b) sibling status ------------------------------------------------------
echo "=== (b) sibling status ===\n";
require $dir . '/common.php';
require $repo . '/includes/autoload.php';
$mig = nr_schema_migration();
echo '  sibling 1 (schema ENUM migration): ' . ($mig === null ? "NOT LANDED\n" : "landed: " . substr($mig, strlen($repo) + 1) . "\n");
echo '  sibling 2 (decision-point routing): ' . (nr_sibling_decision_landed() ? "landed\n" : "NOT LANDED\n");
$api = nr_review_api();
echo '  sibling 3 (review workflow API): ' . ($api === null ? "NOT LANDED\n" : "landed: " . substr($api, strlen($repo) + 1) . "\n");
echo "\n";

// --- (c) test files ----------------------------------------------------------
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

echo $totalFail === 0 ? "NEEDS_REVIEW SUITE: ALL GREEN\n" : "NEEDS_REVIEW SUITE: {$totalFail} FAILURE(S)\n";
exit($totalFail === 0 ? 0 : 1);
