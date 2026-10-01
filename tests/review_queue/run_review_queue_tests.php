#!/usr/bin/env php
<?php
// Orchestrator: php -l the review-workflow subject files, then runs each
// test file in tests/review_queue/ in its own PHP process.
//
// Usage: php tests/review_queue/run_review_queue_tests.php
declare(strict_types=1);

$dir  = __DIR__;
$repo = dirname(__DIR__, 2);

$lintFiles = [
    $repo . '/includes/ReviewQueue.php',
    $repo . '/includes/Auth.php',
    $repo . '/api/review.php',
    $repo . '/review_queue.php',
];

$testFiles = [
    'test_review_queue_unit.php' => 'contract constants, targetStatus, parseDimensionScores (no DB)',
    'test_review_queue_db.php'    => 'migration idempotency, listQueue, transitions, guards, idempotency, eligibility (scratch MariaDB)',
    'test_review_api_http.php'    => 'live HTTP: auth-before-input, CSRF, allowlist, transition guards, idempotency (php -S + scratch MariaDB)',
];

$php = PHP_BINARY;
$totalFail = 0;

// --- (a) syntax/lint ----------------------------------------------------------
echo "=== (a) php -l syntax check ===\n";
foreach (array_merge($lintFiles, glob($dir . '/*.php')) as $f) {
    $cmd = escapeshellarg($php) . ' -l ' . escapeshellarg($f) . ' 2>&1';
    exec($cmd, $out, $code);
    // php -l exits 0 on success; its success message ("No syntax errors
    // detected") would false-positive a /syntax error/i grep, so the exit
    // code alone is the signal.
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

echo "\n=== review_queue suite: " . ($totalFail === 0 ? 'ALL GREEN' : "{$totalFail} FAILURE(S)") . " ===\n";
exit($totalFail === 0 ? 0 : 1);
