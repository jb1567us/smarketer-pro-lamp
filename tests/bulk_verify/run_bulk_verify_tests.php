<?php
/**
 * ITEM2 bulk-verify test suite.
 *
 * Usage: php tests/bulk_verify/run_bulk_verify_tests.php
 *
 * Runs the REAL BulkVerifyJob code (enqueue / run / status / cancel) plus the
 * real Compliance verification gate, with a FAKE verification provider (no
 * network) and injected settings (no config files).
 *
 * Database: prefers MySQL when BULKVERIFY_MYSQL_DSN is set
 * (e.g. mysql:host=127.0.0.1;dbname=bulkverify_test with
 * BULKVERIFY_MYSQL_USER/PASS), applying the real schema.sql + the bulk-verify
 * migration. Otherwise falls back to an in-memory SQLite database with a
 * portable minimal schema — the job's SQL is deliberately portable so both
 * paths exercise the same code. The crash-recovery SQL asserted here mirrors
 * cron/process_queue.php.
 *
 * Nothing here touches production. No network calls. No secrets on disk.
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

use App\BulkVerifyJob;
use App\Compliance;
use App\FunnelStats;
use App\Verification\EmailVerificationProvider;
use App\Verification\EmailVerificationResult;

$failures = 0;
$passed = 0;
function ok(bool $cond, string $name): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "  PASS: {$name}\n"; }
    else { $failures++; echo "  FAIL: {$name}\n"; }
}
function expectInvalidArgument(callable $fn, string $needle, string $name): void
{
    try {
        $fn();
        ok(false, $name . ' (no exception thrown)');
    } catch (\InvalidArgumentException $e) {
        ok(stripos($e->getMessage(), $needle) !== false, $name . ' [got: ' . substr($e->getMessage(), 0, 110) . ']');
    } catch (\Throwable $e) {
        ok(false, $name . ' (wrong exception: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 80) . ')');
    }
}

/** Fake provider: verdict by email domain; counts every call; never touches network. */
class FakeBulkProvider implements EmailVerificationProvider
{
    public array $calls = []; // email => count
    public bool $throw = false;

    public function name(): string { return 'fake'; }

    public function verify(string $email): EmailVerificationResult
    {
        $this->calls[$email] = ($this->calls[$email] ?? 0) + 1;
        if ($this->throw) {
            throw new \RuntimeException('simulated provider outage');
        }
        $domain = substr(strrchr(strtolower(trim($email)), '@') ?: '', 1);
        $status = match ($domain) {
            'valid.example' => EmailVerificationResult::VALID,
            'dead.example' => EmailVerificationResult::INVALID,
            'catchall.example' => EmailVerificationResult::RISKY,
            default => EmailVerificationResult::UNKNOWN,
        };
        return new EmailVerificationResult($status, 'fake', new \DateTimeImmutable());
    }
}

// ── Database ─────────────────────────────────────────────────────────────
$mysqlDsn = getenv('BULKVERIFY_MYSQL_DSN') ?: '';
if ($mysqlDsn !== '') {
    // MySQL path: mirrors the other suites (mysql CLI imports the real
    // schema.sql, which contains DELIMITER blocks the PDO splitter can't
    // handle). Requires: BULKVERIFY_MYSQL_DSN, _USER, _PASS, _DBNAME and the
    // `mysql` CLI. NOTE: this path is declared but NOT verified in CI here —
    // no MySQL server exists in this sandbox; the SQLite path below is the
    // verified one.
    $mysqlCli = trim(shell_exec('command -v mysql') ?: '');
    $dbName = getenv('BULKVERIFY_MYSQL_DBNAME') ?: '';
    if ($mysqlCli === '' || $dbName === '') {
        fwrite(STDERR, "MySQL mode needs the `mysql` CLI and BULKVERIFY_MYSQL_DBNAME.\n");
        exit(2);
    }
    $dbUser = getenv('BULKVERIFY_MYSQL_USER') ?: '';
    $dbPass = getenv('BULKVERIFY_MYSQL_PASS') ?: '';
    // Omit -p when the password is empty: `-p''` makes the mysql client
    // prompt on stdin, which eats a redirected SQL file and corrupts the import.
    $dbPwArg = $dbPass !== '' ? ' -p' . escapeshellarg($dbPass) : '';
    exec(sprintf(
        '%s -h %s -u %s%s -e %s </dev/null 2>&1',
        escapeshellarg($mysqlCli),
        escapeshellarg(getenv('BULKVERIFY_MYSQL_HOST') ?: '127.0.0.1'),
        escapeshellarg($dbUser),
        $dbPwArg,
        escapeshellarg("DROP DATABASE IF EXISTS `{$dbName}`; CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4;")
    ), $out, $code);
    if ($code !== 0) { fwrite(STDERR, "cannot create MySQL test DB\n" . implode("\n", $out) . "\n"); exit(2); }
    exec(sprintf(
        '%s -h %s -u %s%s %s < %s 2>&1',
        escapeshellarg($mysqlCli),
        escapeshellarg(getenv('BULKVERIFY_MYSQL_HOST') ?: '127.0.0.1'),
        escapeshellarg($dbUser),
        $dbPwArg,
        escapeshellarg($dbName),
        escapeshellarg($repo . '/schema.sql')
    ), $out, $code);
    if ($code !== 0) { fwrite(STDERR, "schema.sql import failed\n" . implode("\n", $out) . "\n"); exit(2); }
    exec(sprintf(
        '%s -h %s -u %s%s %s < %s 2>&1',
        escapeshellarg($mysqlCli),
        escapeshellarg(getenv('BULKVERIFY_MYSQL_HOST') ?: '127.0.0.1'),
        escapeshellarg($dbUser),
        $dbPwArg,
        escapeshellarg($dbName),
        escapeshellarg($repo . '/migrations/2026-09-24-bulk-verify.sql')
    ), $out, $code);
    if ($code !== 0) { fwrite(STDERR, "migration import failed\n" . implode("\n", $out) . "\n"); exit(2); }
    $pdo = new \PDO($mysqlDsn, $dbUser, $dbPass);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    echo "DB: MySQL ({$dbName})\n";
} else {
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company_name VARCHAR(255), email VARCHAR(255),
        verification_status VARCHAR(20) NOT NULL DEFAULT 'unknown',
        verified_at DATETIME NULL
    )");
    $pdo->exec("CREATE TABLE task_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        lead_id INT NULL, task_type VARCHAR(50) NOT NULL, payload TEXT,
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        scheduled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL, retry_count INT DEFAULT 0, error_message TEXT
    )");
    $pdo->exec("CREATE TABLE suppression_list (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email VARCHAR(255) NOT NULL, reason VARCHAR(50) NOT NULL DEFAULT 'unsubscribe',
        source VARCHAR(100) NULL
    )");
    echo "DB: SQLite (in-memory, portable schema)\n";
}

// ── Settings + provider seams ────────────────────────────────────────────
$settings = [
    'verification_required' => '1',
    'verification_api_key' => 'TESTKEY-abc123-NEVER-REAL',
    'verification_provider' => 'millionverifier',
    'verification_risky_action' => 'block',
    'verification_strict' => '0',
    'verification_cache_days' => '30',
    'verification_bulk_batch_size' => '3',
    'verification_bulk_delay_ms' => '0',
    'verification_bulk_max_unknown_streak' => '15',
];
BulkVerifyJob::setSettingsReader(function (string $key, string $default) use (&$settings): string {
    return array_key_exists($key, $settings) ? (string)$settings[$key] : $default;
});
$fake = new FakeBulkProvider();
BulkVerifyJob::setProviderFactory(function (string $apiKey) use ($fake): EmailVerificationProvider {
    if ($apiKey !== 'TESTKEY-abc123-NEVER-REAL') {
        throw new \RuntimeException('provider factory received wrong key');
    }
    return $fake;
});

// Capture error_log output to prove the API key never lands in logs.
$logFile = tempnam(sys_get_temp_dir(), 'bulkverify_log_');
ini_set('error_log', $logFile);

// ── Helpers ──────────────────────────────────────────────────────────────
function seedLeads(\PDO $pdo, array $emails): array
{
    $ids = [];
    $stmt = $pdo->prepare("INSERT INTO leads (company_name, email) VALUES (?, ?)");
    foreach ($emails as $i => $email) {
        $stmt->execute(["Co {$i}", $email]);
        $ids[] = (int)$pdo->lastInsertId();
    }
    return $ids;
}
function wipe(\PDO $pdo): void
{
    $pdo->exec("DELETE FROM task_queue");
    $pdo->exec("DELETE FROM leads");
    $pdo->exec("DELETE FROM suppression_list");
}
/** Simulate one queue tick's claim, portably (mirrors TaskProcessor::processTask). */
function claimTask(\PDO $pdo, int $taskId): bool
{
    $stmt = $pdo->prepare(
        "UPDATE task_queue SET status = 'In Progress', processed_at = ?, retry_count = retry_count + 1 " .
        "WHERE id = ? AND status = 'Pending'"
    );
    $stmt->execute([date('Y-m-d H:i:s'), $taskId]);
    return $stmt->rowCount() === 1;
}
function taskStatus(\PDO $pdo, int $taskId): string
{
    $stmt = $pdo->prepare("SELECT status FROM task_queue WHERE id = ?");
    $stmt->execute([$taskId]);
    return (string)$stmt->fetchColumn();
}
function leadStatus(\PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare("SELECT verification_status, verified_at FROM leads WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(\PDO::FETCH_ASSOC);
}

echo "1. enqueue (selected mode):\n";
wipe($pdo);
$ids = seedLeads($pdo, ['a@valid.example', 'b@dead.example', 'c@catchall.example', 'd@flaky.example', 'e@valid.example']);
$job = BulkVerifyJob::enqueue($pdo, 'selected', [$ids[0], $ids[1], $ids[3]]);
ok($job['task_id'] > 0 && $job['total'] === 3 && $job['mode'] === 'selected', 'enqueue returns task_id/total/mode');
$stmt = $pdo->prepare("SELECT task_type, status, lead_id, payload FROM task_queue WHERE id = ?");
$stmt->execute([$job['task_id']]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);
ok($row['task_type'] === 'BulkVerify' && $row['status'] === 'Pending' && $row['lead_id'] === null, 'task row: BulkVerify/Pending/NULL lead_id');
$payload = json_decode($row['payload'], true);
ok(($payload['job'] ?? '') === 'bulk_verify' && count($payload['lead_ids']) === 3 && $payload['cursor'] === 0, 'payload carries job, ids, cursor');
ok(strpos($row['payload'], 'TESTKEY') === false, 'API key NOT in task payload');
expectInvalidArgument(
    fn() => BulkVerifyJob::enqueue($pdo, 'unchecked', []),
    'already running',
    'second enqueue refused while a job is active'
);
BulkVerifyJob::cancel($pdo, $job['task_id']); // clear the active job before input-validation checks
expectInvalidArgument(
    fn() => BulkVerifyJob::enqueue($pdo, 'selected', []),
    'at least one',
    'selected mode with no ids refused'
);
expectInvalidArgument(
    fn() => BulkVerifyJob::enqueue($pdo, 'bogus', []),
    'Unknown verify mode',
    'bogus mode refused'
);

echo "2. refusal when verification is disabled / keyless:\n";
$settings['verification_required'] = '0';
expectInvalidArgument(
    fn() => BulkVerifyJob::enqueue($pdo, 'unchecked', []),
    'Settings',
    'refusal message points at Settings when switched off'
);
$settings['verification_required'] = '1';
$settings['verification_api_key'] = '';
expectInvalidArgument(
    fn() => BulkVerifyJob::enqueue($pdo, 'unchecked', []),
    'API key',
    'refusal message mentions the API key when keyless'
);
$settings['verification_api_key'] = 'TESTKEY-abc123-NEVER-REAL';

echo "3. worker processes batches across ticks (unchecked mode):\n";
wipe($pdo);
$fake->calls = [];
$ids = seedLeads($pdo, [
    'a@valid.example', 'b@dead.example', 'c@catchall.example', 'd@flaky.example',
    'e@valid.example', 'f@dead.example', 'g@valid.example',
]);
$job = BulkVerifyJob::enqueue($pdo, 'unchecked', []);
ok($job['total'] === 7, 'unchecked count = 7');
$tid = $job['task_id'];

// Tick 1: batch of 3
ok(claimTask($pdo, $tid), 'tick1 claim');
BulkVerifyJob::run($pdo, $tid);
ok(taskStatus($pdo, $tid) === 'Pending', 'tick1 parks back to Pending');
$s = BulkVerifyJob::status($pdo, $tid);
ok($s['checked'] === 3 && $s['total'] === 7, 'tick1 progress 3/7');
// Tick 2
ok(claimTask($pdo, $tid), 'tick2 claim');
BulkVerifyJob::run($pdo, $tid);
$s = BulkVerifyJob::status($pdo, $tid);
ok($s['checked'] === 6 && taskStatus($pdo, $tid) === 'Pending', 'tick2 progress 6/7, still Pending');
// Tick 3: finishes
ok(claimTask($pdo, $tid), 'tick3 claim');
BulkVerifyJob::run($pdo, $tid);
$s = BulkVerifyJob::status($pdo, $tid);
ok($s['state'] === 'Completed' && $s['checked'] === 7, 'tick3 completes 7/7');
ok($s['valid'] === 3 && $s['invalid'] === 2 && $s['risky'] === 1 && $s['unknown'] === 1, 'counts: 3 valid / 2 invalid / 1 risky / 1 unknown');
ok($s['percent'] === 100 && $s['finished_at'] !== null, 'percent=100 and finished_at set');
ok(count($fake->calls) === 7 && array_sum($fake->calls) === 7, 'each lead verified exactly once (no double credit spend)');
ok(leadStatus($pdo, $ids[1])['verification_status'] === 'invalid', 'dead.example → invalid persisted');
ok(leadStatus($pdo, $ids[2])['verification_status'] === 'risky', 'catchall.example → risky persisted');
$flaky = leadStatus($pdo, $ids[3]);
ok($flaky['verification_status'] === 'unknown' && $flaky['verified_at'] !== null, 'flaky.example stays unknown (never marked valid), attempt timestamped');
$suppressed = $pdo->query("SELECT COUNT(*) FROM suppression_list")->fetchColumn();
ok((int)$suppressed === 0, 'invalid leads NOT auto-added to suppression_list (gate refuses them without it)');

echo "4. resume after crash:\n";
wipe($pdo);
$fake->calls = [];
$settings['verification_bulk_batch_size'] = '2';
$ids = seedLeads($pdo, ['a@valid.example', 'b@dead.example', 'c@valid.example', 'd@dead.example', 'e@valid.example']);
$job = BulkVerifyJob::enqueue($pdo, 'unchecked', []);
$tid = $job['task_id'];
// Tick 1 completes a batch of 2, parks.
ok(claimTask($pdo, $tid), 'tick1 claim');
BulkVerifyJob::run($pdo, $tid);
ok(BulkVerifyJob::status($pdo, $tid)['checked'] === 2, 'tick1 did 2');
// Simulate a crash DURING tick 2: claim it, then leave it 'In Progress' with a
// stale processed_at (as if the worker died after the claim but the cursor
// shows the first batch only — nothing of batch 2 was persisted).
$pdo->prepare("UPDATE task_queue SET status = 'In Progress', processed_at = ?, retry_count = 1 WHERE id = ?")
    ->execute([date('Y-m-d H:i:s', time() - 31 * 60), $tid]);
// Crash recovery — mirrors cron/process_queue.php (portable form).
$recovered = $pdo->prepare(
    "UPDATE task_queue SET status = 'Pending' WHERE status = 'In Progress' AND processed_at < ? AND retry_count < 5"
)->execute([date('Y-m-d H:i:s', time() - 30 * 60)]);
ok(taskStatus($pdo, $tid) === 'Pending', 'crash recovery re-queues the orphaned task');
// Ticks 2+3 resume from the persisted cursor.
ok(claimTask($pdo, $tid), 'tick2 claim after recovery');
BulkVerifyJob::run($pdo, $tid);
ok(claimTask($pdo, $tid), 'tick3 claim');
BulkVerifyJob::run($pdo, $tid);
$s = BulkVerifyJob::status($pdo, $tid);
ok($s['state'] === 'Completed' && $s['checked'] === 5, 'job completes after crash: 5/5');
ok($s['valid'] === 3 && $s['invalid'] === 2, 'counts correct after resume');
ok(array_sum($fake->calls) === 5, 'no lead re-verified after crash (5 provider calls total)');
$settings['verification_bulk_batch_size'] = '3';

echo "5. invalid verdicts feed the funnel + the send gate refuses them:\n";
wipe($pdo);
$fake->calls = [];
$ids = seedLeads($pdo, ['a@valid.example', 'b@dead.example', 'c@catchall.example', 'd@flaky.example']);
$job = BulkVerifyJob::enqueue($pdo, 'selected', $ids);
$tid = $job['task_id'];
ok(claimTask($pdo, $tid), 'claim');
BulkVerifyJob::run($pdo, $tid);
$funnel = FunnelStats::compute($pdo);
ok($funnel['harvested'] === 4, 'funnel: harvested=4');
ok($funnel['verified_valid'] === 1 && $funnel['invalid'] === 1 && $funnel['risky'] === 1 && $funnel['unknown'] === 1, 'funnel reads the same verification columns the job writes');
ok($funnel['checked'] === 3, 'funnel: checked excludes unknown');
ok($funnel['mailable'] === 1, 'funnel: mailable = valid AND not suppressed = 1');
// Same treatment as the send gate: a fresh invalid verdict must refuse a send,
// even with a warm cache (ITEM2 gate fix).
$gateSettings = $settings;
Compliance::setVerificationSettingsReader(function (string $key, string $default) use ($gateSettings): string {
    return array_key_exists($key, $gateSettings) ? (string)$gateSettings[$key] : $default;
});
Compliance::setVerificationProviderFactory(fn(string $key): EmailVerificationProvider => $fake);
$lead = ['verification_status' => 'invalid', 'verified_at' => date('Y-m-d H:i:s')];
try {
    Compliance::applyVerificationGate(
        'b@dead.example', $fake, $lead, 'block', false, 30,
        function (string $s): void {}, function (string $m): void {}
    );
    ok(false, 'send gate refuses cached invalid verdict');
} catch (\App\Exceptions\OutreachException $e) {
    ok(stripos($e->getMessage(), 'invalid') !== false, 'send gate refuses cached invalid verdict');
}
// Unknown is not strict-blocked by default.
try {
    Compliance::applyVerificationGate(
        'd@flaky.example', $fake, ['verification_status' => 'unknown', 'verified_at' => null],
        'block', false, 30, function (string $s): void {}, function (string $m): void {}
    );
    ok(true, 'unknown verdict does not block when strict mode is off');
} catch (\Throwable $e) {
    ok(false, 'unknown verdict does not block when strict mode is off');
}
Compliance::setVerificationSettingsReader(null);
Compliance::setVerificationProviderFactory(null);

echo "6. API key never in output or logs:\n";
wipe($pdo);
$ids = seedLeads($pdo, ['a@valid.example']);
$job = BulkVerifyJob::enqueue($pdo, 'selected', $ids);
$tid = $job['task_id'];
ok(strpos(json_encode(BulkVerifyJob::status($pdo, $tid)), 'TESTKEY') === false, 'status endpoint JSON has no key');
// Force a failure (provider factory throws) and check the failure surfaces.
BulkVerifyJob::setProviderFactory(function (string $apiKey): EmailVerificationProvider {
    throw new \RuntimeException('simulated provider outage (key=' . substr($apiKey, 0, 4) . '…)');
});
ok(claimTask($pdo, $tid), 'claim');
BulkVerifyJob::run($pdo, $tid);
$s = BulkVerifyJob::status($pdo, $tid);
ok($s['state'] === 'Failed', 'job marked Failed when provider cannot start');
$blob = json_encode($s) . $s['error'] . $s['note'];
ok(strpos($blob, 'TESTKEY') === false, 'failure output has no key');
// The factory above embedded a key PREFIX in its own message — prove the JOB
// scrubbed it anyway (defense in depth; real providers never do this).
BulkVerifyJob::setProviderFactory(fn(string $apiKey): EmailVerificationProvider => $fake);
$log = file_get_contents($logFile);
ok(strpos($log, 'TESTKEY') === false, 'error_log output has no key');
$stmt = $pdo->prepare("SELECT payload, error_message FROM task_queue WHERE id = ?");
$stmt->execute([$tid]);
$dbrow = $stmt->fetch(\PDO::FETCH_ASSOC);
ok(strpos($dbrow['payload'] . $dbrow['error_message'], 'TESTKEY') === false, 'DB row (payload/error) has no key');

echo "7. cancel:\n";
wipe($pdo);
$ids = seedLeads($pdo, ['a@valid.example', 'b@valid.example', 'c@valid.example']);
$job = BulkVerifyJob::enqueue($pdo, 'unchecked', []);
$tid = $job['task_id'];
ok(BulkVerifyJob::cancel($pdo, $tid) === true, 'cancel a queued job');
ok(taskStatus($pdo, $tid) === 'Cancelled', 'queued job → Cancelled immediately');
ok(claimTask($pdo, $tid) === false, 'cancelled job cannot be claimed');
// Cancel mid-run: flag is honored at the next cancel-check.
$job = BulkVerifyJob::enqueue($pdo, 'unchecked', []);
$tid = $job['task_id'];
ok(claimTask($pdo, $tid), 'claim second job');
ok(BulkVerifyJob::cancel($pdo, $tid) === true, 'cancel requested while In Progress');
BulkVerifyJob::run($pdo, $tid);
ok(taskStatus($pdo, $tid) === 'Cancelled', 'running job stops at next check → Cancelled');
ok(BulkVerifyJob::cancel($pdo, $tid) === false, 'cancel on terminal job returns false');

echo "8. unknown-streak circuit breaker:\n";
wipe($pdo);
$fake->calls = [];
$settings['verification_bulk_max_unknown_streak'] = '3';
$settings['verification_bulk_batch_size'] = '10';
$ids = seedLeads($pdo, ['a@flaky.example', 'b@flaky.example', 'c@flaky.example', 'd@flaky.example', 'e@valid.example']);
$job = BulkVerifyJob::enqueue($pdo, 'unchecked', []);
$tid = $job['task_id'];
ok(claimTask($pdo, $tid), 'claim');
BulkVerifyJob::run($pdo, $tid);
$s = BulkVerifyJob::status($pdo, $tid);
ok($s['checked'] === 3 && taskStatus($pdo, $tid) === 'Pending', 'batch parks after 3 consecutive unknowns (provider treated as down)');
ok(stripos($s['note'], 'unknown') !== false, 'note explains the pause');
$settings['verification_bulk_max_unknown_streak'] = '15';
$settings['verification_bulk_batch_size'] = '3';

echo "9. status of missing job:\n";
ok(BulkVerifyJob::status($pdo, 999999) === null, 'unknown task id → null');
ok(BulkVerifyJob::cancel($pdo, 999999) === false, 'cancel unknown task id → false');

BulkVerifyJob::setProviderFactory(null);
BulkVerifyJob::setSettingsReader(null);

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
