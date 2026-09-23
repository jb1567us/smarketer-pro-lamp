#!/usr/bin/env php
<?php
// Orchestrator: spins up the stub TypeSafe server, runs each JEV test file
// in its own PHP process (simulating a fresh request each time), reports.
$dir = __DIR__;
$port = 18923;
$stub = $dir . '/stub_server.php';

$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", $stub],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);
if (!is_resource($server)) { fwrite(STDERR, "Could not start stub server\n"); exit(2); }
usleep(600000); // let php -S bind

putenv('JEV_STUB_URL=http://127.0.0.1:' . $port . '/systemone');
@unlink(sys_get_temp_dir() . '/jev_stub/ratelimit_count');

$tests = ['test_autoload.php', 'test_provider.php', 'test_decision_tier.php'];
$totalFail = 0;
foreach ($tests as $t) {
    echo "=== $t ===\n";
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . '/' . $t);
    passthru($cmd, $code);
    if ($code !== 0) { echo "!! $t exited with code $code\n"; $totalFail++; }
    echo "\n";
}

proc_terminate($server);
proc_close($server);

echo $totalFail === 0 ? "ALL JEV TESTS PASSED\n" : "FAILURES: $totalFail test file(s)\n";
exit($totalFail === 0 ? 0 : 1);
