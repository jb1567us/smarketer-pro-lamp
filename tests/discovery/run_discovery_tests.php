#!/usr/bin/env php
<?php
/**
 * Discovery test orchestrator (D-P2: deterministic candidate construction).
 *
 * php -l the new includes/Discovery files, then runs each test file in
 * tests/discovery/ in its own PHP process. Zero DB, zero network, zero LLM.
 *
 * Usage: php tests/discovery/run_discovery_tests.php
 */
declare(strict_types=1);

$dir  = __DIR__;
$repo = dirname(__DIR__, 2);

$lintFiles = [
    $repo . '/includes/Discovery/BuyerArchetypes.php',
    $repo . '/includes/Discovery/CandidateBuilder.php',
];

$testFiles = [
    'test_candidate_builder_unit.php' => 'table integrity, determinism, no-model inspection, template rules, eligibility, honest absence',
    'test_relay_fixture.php' => 'Relay day-zero buyer: non-empty sensible deterministic candidate set',
];

$php = PHP_BINARY;
$totalFail = 0;

// --- (a) syntax/lint ----------------------------------------------------------
echo "=== (a) php -l syntax check ===\n";
foreach (array_merge($lintFiles, glob($dir . '/*.php')) as $f) {
    $cmd = escapeshellarg($php) . ' -l ' . escapeshellarg($f) . ' 2>&1';
    exec($cmd, $out, $code);
    $ok = $code === 0;
    echo ($ok ? '  PASS' : '  FAIL') . ': php -l ' . basename($f) . "\n";
    if (!$ok) {
        $totalFail++;
        echo '    ' . implode("\n    ", $out) . "\n";
    }
    $out = [];
}

// --- (b) test files, each in its own process ----------------------------------
echo "\n=== (b) test files ===\n";
foreach ($testFiles as $file => $desc) {
    echo "--- {$file} ({$desc}) ---\n";
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($dir . '/' . $file) . ' 2>&1';
    exec($cmd, $out, $code);
    echo implode("\n", $out) . "\n";
    if ($code !== 0) {
        $totalFail++;
    }
    $out = [];
}

if ($totalFail !== 0) {
    echo "DISCOVERY TESTS FAILED ({$totalFail})\n";
    exit(1);
}
echo "ALL DISCOVERY TESTS PASSED\n";
exit(0);
