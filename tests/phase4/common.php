<?php
/**
 * Phase 4 n8n ingestion seam hardening tests — shared harness.
 *
 * Each test file requires this file, then requires includes/autoload.php
 * via the repo path, then runs its checks. Run the whole suite with
 * tests/phase4/run_phase4_tests.php.
 */
declare(strict_types=1);

$PHASE4_PASS = 0;
$PHASE4_FAIL = 0;
$PHASE4_SKIP = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $PHASE4_PASS, $PHASE4_FAIL;
    if ($cond) {
        $PHASE4_PASS++;
        echo "  PASS: {$name}\n";
    } else {
        $PHASE4_FAIL++;
        echo "  FAIL: {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function phase4_skip(string $name, string $reason): void
{
    global $PHASE4_SKIP;
    $PHASE4_SKIP++;
    echo "  SKIP: {$name} ({$reason})\n";
}

/** Print the summary and return the process exit code for this file. */
function phase4_summary(string $file): int
{
    global $PHASE4_PASS, $PHASE4_FAIL, $PHASE4_SKIP;
    echo "  -- {$file}: {$PHASE4_PASS} pass, {$PHASE4_FAIL} fail, {$PHASE4_SKIP} skip\n";
    return $PHASE4_FAIL === 0 ? 0 : 1;
}

/** Repo root derived from this file's location. */
function phase4_repo_root(): string
{
    return dirname(__DIR__, 2);
}

/** Make a fresh temp dir for file-based tests; caller must clean up. */
function phase4_tmpdir(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '_' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    return $dir;
}

/** Recursively remove a temp dir. */
function phase4_rmdir(string $dir): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}
