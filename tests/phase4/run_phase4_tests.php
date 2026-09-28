#!/usr/bin/env php
<?php
// Orchestrator: php -l all Phase 4 files, then runs each test file in its
// own PHP process (simulating a fresh request), and reports per category.
declare(strict_types=1);

$dir  = __DIR__;
$repo = dirname(__DIR__, 2);

$newFiles = [
    $repo . '/includes/ApiAuth.php',
    $repo . '/includes/Auth.php',
    $repo . '/api/ingest_reply.php',
];

$testFiles = [
    'test_ingest_auth.php'        => 'API-key extraction/validation, key generation, Auth wiring',
    'test_ingest_idempotency.php' => 'dedupe keys, store cap, duplicate short-circuit',
    'test_ingest_limits.php'      => 'payload caps (413), rate limiting (429), guarantees',
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

echo $totalFail === 0 ? "PHASE4 SUITE: ALL GREEN\n" : "PHASE4 SUITE: {$totalFail} FAILURE(S)\n";
exit($totalFail === 0 ? 0 : 1);
