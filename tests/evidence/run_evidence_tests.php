#!/usr/bin/env php
<?php
/**
 * Evidence test orchestrator (D2-P1: source-authority taxonomy + mention-validity).
 *
 * php -l the new includes/Evidence files, then runs each test file in
 * tests/evidence/ in its own PHP process. Zero DB, zero network.
 *
 * Usage: php tests/evidence/run_evidence_tests.php
 */
declare(strict_types=1);

$dir  = __DIR__;
$repo = dirname(__DIR__, 2);

$lintFiles = [
    $repo . '/includes/Evidence/SourceAuthority.php',
    $repo . '/includes/Evidence/MentionValidity.php',
];

$testFiles = [
    'test_source_authority_unit.php' => 'taxonomy grading: 4 tiers, top-down order, fail-closed, servability',
    'test_mention_validity_unit.php' => 'predicate gates, recency boundary, scope ladder, flag-not-drop routing',
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
        echo "  !! {$file} exited with code {$code}\n";
    }
    $out = [];
}

echo "\n=== evidence suite: " . ($totalFail === 0 ? 'ALL GREEN' : "{$totalFail} FAILURE(S)") . " ===\n";
exit($totalFail === 0 ? 0 : 1);
