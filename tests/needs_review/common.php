<?php
/**
 * needs_review test suite (goal_67693fcbba4c, subject agent 4: tests + fixtures).
 *
 * Covers the 'Needs Review' qualification state across the coordinated fix:
 *   - fixtures for leads in each qualification state (qualified / unqualified /
 *     needs_review) — see fixtures.php
 *   - migration idempotency for the new ENUM migration (apply twice to a
 *     scratch DB -> clean no-op) — see test_migration_idempotency.php
 *   - verdict -> status routing for the 50-75 review band (sibling 2)
 *   - state-transition tests: needs_review -> approved -> Qualified /
 *     needs_review -> disqualified -> Unqualified; approval makes a lead
 *     sequence-eligible, disqualification doesn't; invalid transitions
 *     rejected by the review API (sibling 3)
 *   - no-leak guarantees: a needs_review lead cannot be enrolled or sent to,
 *     across campaign launch / sequence enrollment / queue processing
 *   - API auth tests for the new review endpoints: 401 unauthenticated,
 *     CSRF enforcement (sibling 3)
 *
 * Conventions (same as tests/phase4, tests/icp_scoring): each test file
 * requires this file, then the repo autoloader, then runs its checks in its
 * own PHP process (see run_needs_review_tests.php). Scratch MariaDB is used
 * via DB_* process-environment credentials (tests/support/db_env.php) that
 * are always cleared afterwards — the repo tree is never touched.
 * Zero network, zero real sends
 * (operational_mode='simulated'), zero real LLM calls.
 *
 * Sibling-gating: three sibling subjects land in parallel —
 *   (1) schema: adds 'Needs Review' to the lead qualification-status ENUM
 *   (2) decision point: routes the 50-75 fit-score band to needs_review and
 *       audits all enrollment/sequence paths for no-leak
 *   (3) review workflow: approve/disqualify API + UI + audit trail
 * Tests that need a sibling's artifact detect its absence and SKIP with an
 * explicit "PENDING sibling N" reason instead of failing — a missing sibling
 * is not a test failure, and skipping is honest where faking coverage would
 * not be. Once the siblings land, the same files exercise the full contract.
 */
declare(strict_types=1);

require_once __DIR__ . '/../support/db_env.php';

$NR_PASS = 0;
$NR_FAIL = 0;
$NR_SKIP = 0;

function nr_check(string $name, bool $cond, string $detail = ''): void
{
    global $NR_PASS, $NR_FAIL;
    if ($cond) {
        $NR_PASS++;
        echo "  PASS: {$name}\n";
    } else {
        $NR_FAIL++;
        echo "  FAIL: {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** Skip with an explicit reason (used for PENDING-sibling gating). */
function nr_skip(string $name, string $reason): void
{
    global $NR_SKIP;
    $NR_SKIP++;
    echo "  SKIP: {$name} ({$reason})\n";
}

/** Print the summary and return the process exit code for this file. */
function nr_summary(string $file): int
{
    global $NR_PASS, $NR_FAIL, $NR_SKIP;
    echo "  -- {$file}: {$NR_PASS} pass, {$NR_FAIL} fail, {$NR_SKIP} skip\n";
    return $NR_FAIL === 0 ? 0 : 1;
}

/** Repo root derived from this file's location. */
function nr_repo_root(): string
{
    return dirname(__DIR__, 2);
}

// ── Sibling detection ─────────────────────────────────────────────────────

/**
 * Sibling 1 (schema): the ENUM migration file, or null when it has not
 * landed yet. Named e.g. migrations/2026-09-28-needs-review-enum.sql.
 */
function nr_schema_migration(): ?string
{
    $hits = glob(nr_repo_root() . '/migrations/*needs*review*.sql')
        ?: glob(nr_repo_root() . '/migrations/*review*enum*.sql');
    if ($hits === false || $hits === []) {
        return null;
    }
    sort($hits);
    return $hits[0];
}

/** Sibling 2 (decision point): the 50-75 band routes to the 'Needs Review' status. */
function nr_sibling_decision_landed(): bool
{
    return \App\Actions\QualifyLeadAction::statusForVerdict('needs_review') === 'Needs Review';
}

/**
 * Sibling 3 (review workflow): the approve/disqualify review API endpoint,
 * or null when it has not landed yet.
 */
function nr_review_api(): ?string
{
    $hits = glob(nr_repo_root() . '/api/*review*.php');
    if ($hits === false || $hits === []) {
        return null;
    }
    foreach ($hits as $f) {
        $src = (string)file_get_contents($f);
        if (stripos($src, 'approve') !== false && stripos($src, 'disqualify') !== false) {
            return $f;
        }
    }
    return null;
}

// ── Scratch DB helpers (no mysql CLI: native PDO as root via socket) ──────

/**
 * Create a scratch database + user via a root connection and point the
 * DB_* process-environment credentials at it (tests/support/db_env.php —
 * no files written), then return the app PDO. Prior env values (if any) are
 * captured and can be restored via nr_restore_db_config().
 *
 * Uses only localhost TCP for the app connection (config-driven) and a
 * best-effort root connection (unix socket first, then 127.0.0.1) for admin.
 */
function nr_scratch_db(string $dbName, string $dbUser, string $dbPass): \App\PDO
{
    $root = null;
    $rootErr = '';
    foreach (['mysql:unix_socket=/run/mysqld/mysqld.sock', 'mysql:host=127.0.0.1'] as $dsn) {
        try {
            $root = new \PDO($dsn, 'root', '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            break;
        } catch (\Throwable $e) {
            $rootErr = $e->getMessage();
        }
    }
    if ($root === null) {
        throw new \RuntimeException('no root DB connection available: ' . $rootErr);
    }
    $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4");
    $root->exec("CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'");
    $root->exec("CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'");
    // CREATE USER IF NOT EXISTS leaves a stale password when a previous
    // suite run created the same user with a different password — reset it
    // unconditionally so test files stay order-independent. Both hosts are
    // covered: on machines with an anonymous ''@'localhost' account, the
    // '%'-host row never matches and password auth would fail without the
    // explicit 'localhost' row.
    $root->exec("ALTER USER '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'");
    $root->exec("ALTER USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'");
    $root->exec("GRANT ALL ON `{$dbName}`.* TO '{$dbUser}'@'%'");
    $root->exec("GRANT ALL ON `{$dbName}`.* TO '{$dbUser}'@'localhost'");
    $root->exec('FLUSH PRIVILEGES');

    // Credentials travel via the process environment now (config/db.php is
    // environment-first): nothing is written to disk, so there is no file to
    // race over and nothing to restore afterwards. Each test file runs in its
    // own PHP process; a second nr_scratch_db() call in the SAME process must
    // not reuse the first connection — test_db_use_env() resets the memoized
    // singleton so getConnection() reconnects to the new database.
    test_db_use_env('127.0.0.1', $dbName, $dbUser, $dbPass);
    // SequenceManager caches table probes per request — reset it explicitly.
    \App\SequenceManager::resetReadyCache();

    return \App\Database::getConnection();
}

/** Restore the pre-test DB environment. Always call in a finally block. */
function nr_restore_db_config(): void
{
    // Nothing was written to disk: just drop the scratch credentials from the
    // process environment.
    test_db_restore_env();
    \App\SequenceManager::resetReadyCache();
}

/**
 * Apply a .sql migration file's statements to $pdo (comment-stripped,
 * split on ';' — same approach as tests/phase4/test_sequences.php).
 */
function nr_apply_migration(\App\PDO $pdo, string $file): void
{
    $mig = file_get_contents($file);
    if ($mig === false) {
        throw new \RuntimeException("migration missing: {$file}");
    }
    $lines = array_filter(explode("\n", $mig), fn($l) => !str_starts_with(trim($l), '--'));
    $stripped = implode("\n", $lines);
    foreach (array_filter(array_map('trim', explode(';', $stripped))) as $sql) {
        $pdo->exec($sql);
    }
}

/** Return the leads.status ENUM definition string, e.g. "enum('New',...)". */
function nr_status_enum(\App\PDO $pdo): string
{
    $col = $pdo->query("SHOW COLUMNS FROM leads LIKE 'status'")->fetch(\App\PDO::FETCH_ASSOC);
    if ($col === false) {
        throw new \RuntimeException('leads.status column missing');
    }
    return strtolower((string)($col['Type'] ?? ''));
}
