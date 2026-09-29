#!/usr/bin/env php
<?php
/**
 * ICP weight auto-tuner test orchestrator.
 *
 * Runs every test file in tests/icp/ in a fresh PHP process and reports the
 * aggregate result. Fails (exit 1) if any file fails.
 *
 * Usage: php tests/icp/run_icp_tests.php
 */
declare(strict_types=1);

$dir = __DIR__;
$files = [
    'test_adjust_weights_unit.php',
    'test_adjust_weights_integration.php',
];

$failed = [];
foreach ($files as $file) {
    echo "=== {$file} ===\n";
    exec('php ' . escapeshellarg($dir . '/' . $file) . ' 2>&1', $out, $code);
    echo implode("\n", $out) . "\n";
    if ($code !== 0) {
        $failed[] = $file;
    }
    $out = [];
}

if ($failed !== []) {
    echo "ICp TESTS FAILED: " . implode(', ', $failed) . "\n";
    exit(1);
}
echo "ALL ICP TESTS PASSED\n";
exit(0);
