<?php
/**
 * Phase 3 JEV integration tests — shared harness.
 *
 * Each test file requires this file, then requires includes/autoload.php
 * via the repo path, then runs its checks. Run the whole suite with
 * tests/phase3/run_phase3_tests.php.
 */
declare(strict_types=1);

$PHASE3_PASS = 0;
$PHASE3_FAIL = 0;
$PHASE3_SKIP = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $PHASE3_PASS, $PHASE3_FAIL;
    if ($cond) {
        $PHASE3_PASS++;
        echo "  PASS: {$name}\n";
    } else {
        $PHASE3_FAIL++;
        echo "  FAIL: {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function phase3_skip(string $name, string $reason): void
{
    global $PHASE3_SKIP;
    $PHASE3_SKIP++;
    echo "  SKIP: {$name} ({$reason})\n";
}

/** Print the summary and return the process exit code for this file. */
function phase3_summary(string $file): int
{
    global $PHASE3_PASS, $PHASE3_FAIL, $PHASE3_SKIP;
    echo "  -- {$file}: {$PHASE3_PASS} pass, {$PHASE3_FAIL} fail, {$PHASE3_SKIP} skip\n";
    return $PHASE3_FAIL === 0 ? 0 : 1;
}

/** Repo root derived from this file's location. */
function phase3_repo_root(): string
{
    return dirname(__DIR__, 2);
}
