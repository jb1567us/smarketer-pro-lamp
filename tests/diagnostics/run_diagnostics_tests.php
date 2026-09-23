<?php
/**
 * Diagnostics suite runner.
 *
 * Usage: php tests/diagnostics/run_diagnostics_tests.php
 *
 * Runs the offline unit tests (tests/diagnostics/DiagnosticsTest.php).
 * No database, no network.
 */
declare(strict_types=1);

$test = __DIR__ . '/DiagnosticsTest.php';
echo "== DiagnosticsTest ==\n";
passthru(PHP_BINARY . ' ' . escapeshellarg($test), $code);
exit($code);
