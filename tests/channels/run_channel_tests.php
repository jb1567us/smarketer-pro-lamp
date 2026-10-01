#!/usr/bin/env php
<?php
/**
 * Channel test orchestrator (P5: channel_reach target list).
 *
 * php -l the new includes/Channels files, then runs each test file in
 * tests/channels/ in its own PHP process. Zero DB, zero network.
 *
 * Usage: php tests/channels/run_channel_tests.php
 */
declare(strict_types=1);

$dir  = __DIR__;
$repo = dirname(__DIR__, 2);

$lintFiles = [
    $repo . '/includes/Channels/ChannelTargetList.php',
    $repo . '/includes/Channels/ChannelTargetListExtension.php',
];

$testFiles = [
    'test_channel_target_list_unit.php' => 'determinism, ordering, scope filtering, strength floor, fail-closed misses, targetBlock seam, mentionCheck tripwire, flag-not-drop',
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

echo "\n=== channel suite: " . ($totalFail === 0 ? 'ALL GREEN' : "{$totalFail} FAILURE(S)") . " ===\n";
exit($totalFail === 0 ? 0 : 1);
