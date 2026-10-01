#!/usr/bin/env php
<?php
// Orchestrator: php -l all five Phase 3 files, then runs each test file in
// its own PHP process (simulating a fresh request), and reports per category.
declare(strict_types=1);

$dir  = __DIR__;
$repo = dirname(__DIR__, 2);

$newFiles = [
    $repo . '/includes/Actions/ClassifyReplyAction.php',
    $repo . '/includes/ReplyRouter.php',
    $repo . '/includes/ReplyIntake.php',
    $repo . '/reply_lab.php',
    $repo . '/api/ingest_reply.php',
];

$testFiles = [
    'test_classify_heuristic.php' => 'categories (b) heuristic fixtures, (c) off-mode marking, (f) verdict shape',
    'test_router.php'             => 'category (d) routing outcomes per intent + guardrails',
    'test_endpoints_static.php'   => 'categories (e) auth on endpoints, (f) endpoint contract',
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

echo $totalFail === 0 ? "ALL PHASE 3 TESTS PASSED\n" : "FAILURES: {$totalFail}\n";
exit($totalFail === 0 ? 0 : 1);
