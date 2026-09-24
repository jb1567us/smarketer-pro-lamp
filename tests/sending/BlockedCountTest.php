<?php
/**
 * ITEM A — blocked-send counter tests.
 *
 * Usage: php tests/sending/BlockedCountTest.php
 *
 * Pure PHP: no database server, no network. The counter's DB write is
 * intercepted with the BlockedCount::setRecorder() test seam, and the
 * compliance gate's own seams (verification settings reader / provider
 * factory / persister, Licensing verdict override) drive each refusal path.
 * A native in-memory SQLite PDO exercises the real SQL (probe + increment)
 * without MySQL.
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

use App\BlockedCount;
use App\Compliance;
use App\EmailSender;
use App\Exceptions\OutreachException;
use App\Licensing;
use App\Verification\EmailVerificationProvider;
use App\Verification\EmailVerificationResult;

$passed = 0;
$failed = 0;
function ok(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS: {$name}\n"; }
    else { $failed++; echo "  FAIL: {$name}\n"; }
}
function expectOutreachThrow(callable $fn, string $needle, string $name): void
{
    try {
        $fn();
        ok(false, $name . ' (no exception thrown)');
    } catch (OutreachException $e) {
        ok(stripos($e->getMessage(), $needle) !== false, $name . ' [got: ' . substr($e->getMessage(), 0, 100) . ']');
    } catch (\Throwable $e) {
        ok(false, $name . ' (wrong exception: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 80) . ')');
    }
}

/** Fake verification provider: scripted verdict, never touches network. */
class FakeItemAProvider implements EmailVerificationProvider
{
    public function __construct(private readonly string $status) {}
    public function name(): string { return 'fake-itema'; }
    public function verify(string $email): EmailVerificationResult
    {
        return new EmailVerificationResult($this->status, $this->name(), new \DateTimeImmutable());
    }
}

/** Recorder capturing (campaignId, reason) pairs instead of DB writes. */
$calls = [];
$resetSeams = function () use (&$calls): void {
    $calls = [];
    BlockedCount::setRecorder(function (int $cid, string $reason) use (&$calls): void {
        $calls[] = [$cid, $reason];
    });
    BlockedCount::resetColumnCache();
    Compliance::setVerificationSettingsReader(null);
    Compliance::setVerificationProviderFactory(null);
    Compliance::setVerificationStatusPersister(null);
    Licensing::setVerdictForTest(null);
};
$resetSeams();

/* ── 1. Reason registry ─────────────────────────────────────────────── */
$cols = BlockedCount::columns();
ok(count($cols) === 6, 'six block reasons registered');
ok(count(array_unique(array_values($cols))) === 6, 'counter columns are unique');
$allPrefixed = true;
foreach ($cols as $column) {
    if (!str_starts_with($column, 'blocked_')) { $allPrefixed = false; }
}
ok($allPrefixed, 'every counter column is blocked_-prefixed');
foreach (['invalid_verification', 'suppression', 'compliance_pause', 'throttle', 'license_revoked', 'placeholder'] as $r) {
    ok(isset($cols[$r]), "reason '{$r}' registered");
}

/* ── 2. record() semantics (recorder seam) ───────────────────────────── */
BlockedCount::record(7, 'suppression');
ok($calls === [[7, 'suppression']], 'record() forwards (campaignId, reason) to the recorder');

$calls = [];
BlockedCount::record(null, 'suppression');
BlockedCount::record(0, 'suppression');
BlockedCount::record(-3, 'suppression');
ok($calls === [], 'record() ignores missing/non-positive campaign ids');

BlockedCount::record(7, 'bogus_reason');
ok($calls === [], 'record() ignores unknown reasons');

$calls = [];
foreach (array_keys($cols) as $reason) {
    BlockedCount::record(42, $reason);
}
$gotReasons = array_column($calls, 1);
ok($gotReasons === array_keys($cols), 'every registered reason increments through record()');
ok(array_column($calls, 0) === array_fill(0, 6, 42), 'campaign id passes through for every reason');

/* ── 3. Gate wiring: license_revoked ─────────────────────────────────── */
$resetSeams();
Licensing::setVerdictForTest(Licensing::defaultVerdict('revoked', 'test-revocation'));
expectOutreachThrow(
    fn() => Compliance::requireCompliantSend('buyer@realmail.example', null, 11),
    'revoked',
    'revoked key refuses the send (message intact)'
);
ok($calls === [[11, 'license_revoked']], 'revoked key increments license_revoked');
Licensing::setVerdictForTest(null); // fail-open default from here on

/* ── 4. Gate wiring: suppression ─────────────────────────────────────── */
// No database is configured here, and isSuppressed() fails CLOSED without
// one — exactly the path under test: the refusal must still be counted.
$resetSeams();
expectOutreachThrow(
    fn() => Compliance::requireCompliantSend('optout@example.com', null, 12),
    'suppression list',
    'suppressed address refuses the send (message intact)'
);
ok($calls === [[12, 'suppression']], 'suppression refusal increments suppression');

/* ── 5. Gate wiring: invalid_verification ────────────────────────────── */
$resetSeams();
Compliance::setVerificationSettingsReader(function (string $key, string $default): string {
    return match ($key) {
        'verification_required' => '1',
        'verification_api_key' => 'test-key',
        'verification_risky_action' => 'block',
        'verification_strict' => '0',
        'verification_cache_days' => '0',
        default => $default,
    };
});
Compliance::setVerificationProviderFactory(fn(string $k) => new FakeItemAProvider(EmailVerificationResult::INVALID));
Compliance::setVerificationStatusPersister(fn(string $status): int => 0);
expectOutreachThrow(
    fn() => Compliance::runEmailVerificationGate('bad@dead.example', null, 13),
    'invalid',
    'invalid verdict refuses the send (message intact)'
);
ok($calls === [[13, 'invalid_verification']], 'invalid verdict increments invalid_verification');

$calls = [];
Compliance::setVerificationProviderFactory(fn(string $k) => new FakeItemAProvider(EmailVerificationResult::RISKY));
expectOutreachThrow(
    fn() => Compliance::runEmailVerificationGate('iffy@catchall.example', null, 13),
    'risky',
    'risky verdict refuses the send when risky_action=block'
);
ok($calls === [[13, 'invalid_verification']], 'risky(block) verdict increments invalid_verification');

$calls = [];
Compliance::setVerificationSettingsReader(function (string $key, string $default): string {
    return match ($key) {
        'verification_required' => '1',
        'verification_api_key' => 'test-key',
        'verification_risky_action' => 'flag',
        'verification_strict' => '1',
        'verification_cache_days' => '0',
        default => $default,
    };
});
Compliance::setVerificationProviderFactory(fn(string $k) => new FakeItemAProvider(EmailVerificationResult::UNKNOWN));
try {
    Compliance::runEmailVerificationGate('mystery@unknown.example', null, 13);
    ok(false, 'strict unknown refuses the send (no exception thrown)');
} catch (OutreachException $e) {
    ok(stripos($e->getMessage(), 'strict') !== false, 'strict unknown refuses the send (message intact)');
}
ok($calls === [[13, 'invalid_verification']], 'strict-unknown increments invalid_verification');

// Gate disabled => no-op, nothing counted.
$calls = [];
Compliance::setVerificationSettingsReader(fn(string $key, string $default): string => '0');
Compliance::runEmailVerificationGate('anyone@example.com', null, 13);
ok($calls === [], 'disabled gate counts nothing');
$resetSeams();

/* ── 6. EmailSender placeholder path ─────────────────────────────────── */
// NOTE: the placeholder refusal is a plain \Exception (pre-existing
// behavior in EmailSender::send), not an OutreachException.
try {
    EmailSender::send('pending_abc123@placeholder.com', 's', 'b', 'smtp', 'k', 'me@example.com', null, 14);
    ok(false, 'placeholder address refuses the send (no exception thrown)');
} catch (\Throwable $e) {
    ok(stripos($e->getMessage(), 'placeholder') !== false,
        'placeholder address refuses the send (message intact) [got: ' . substr($e->getMessage(), 0, 60) . ']');
}
ok($calls === [[14, 'placeholder']], 'placeholder refusal increments placeholder');
$resetSeams();

/* ── 7. Real SQL against SQLite (no MySQL needed) ────────────────────── */
BlockedCount::setRecorder(null); // exercise the real DB write path
BlockedCount::resetColumnCache();
$sqlite = new \PDO('sqlite::memory:');
$sqlite->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$sqlite->exec('CREATE TABLE campaigns (id INTEGER PRIMARY KEY, name VARCHAR(255), ' .
    'blocked_invalid_verification INT NOT NULL DEFAULT 0, blocked_suppression INT NOT NULL DEFAULT 0, ' .
    'blocked_compliance_pause INT NOT NULL DEFAULT 0, blocked_throttle INT NOT NULL DEFAULT 0, ' .
    'blocked_license_revoked INT NOT NULL DEFAULT 0, blocked_placeholder INT NOT NULL DEFAULT 0)');
$sqlite->exec("INSERT INTO campaigns (id, name) VALUES (5, 'sqlite test')");

BlockedCount::record(5, 'throttle', $sqlite);
$n = (int)$sqlite->query('SELECT blocked_throttle FROM campaigns WHERE id = 5')->fetchColumn();
ok($n === 1, 'SQLite: throttle increment persists');
BlockedCount::record(5, 'throttle', $sqlite);
BlockedCount::record(5, 'suppression', $sqlite);
$n = (int)$sqlite->query('SELECT blocked_throttle FROM campaigns WHERE id = 5')->fetchColumn();
$m = (int)$sqlite->query('SELECT blocked_suppression FROM campaigns WHERE id = 5')->fetchColumn();
ok($n === 2 && $m === 1, 'SQLite: increments accumulate per reason, counts survive re-read');

// Pre-migration schema (no blocked_* columns): silent no-op, never throws.
BlockedCount::resetColumnCache();
$bare = new \PDO('sqlite::memory:');
$bare->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$bare->exec('CREATE TABLE campaigns (id INTEGER PRIMARY KEY, name VARCHAR(255))');
$bare->exec("INSERT INTO campaigns (id, name) VALUES (5, 'bare')");
$threw = false;
try { BlockedCount::record(5, 'throttle', $bare); } catch (\Throwable $e) { $threw = true; }
ok(!$threw, 'record() is a silent no-op when the migration is not applied');

// A broken PDO must never break the send path either.
BlockedCount::resetColumnCache();
$broken = new class {
    public function query(string $q) { throw new \RuntimeException('db is down'); }
    public function prepare(string $q) { throw new \RuntimeException('db is down'); }
};
$threw = false;
try { BlockedCount::record(5, 'throttle', $broken); } catch (\Throwable $e) { $threw = true; }
ok(!$threw, 'record() never throws when the database is broken');

/* ── 8. summarize() ──────────────────────────────────────────────────── */
$row = ['id' => 5, 'name' => 'x', 'blocked_throttle' => 3, 'blocked_suppression' => 1, 'blocked_placeholder' => 0];
$s = BlockedCount::summarize($row);
ok($s['total'] === 4, 'summarize() totals the counters');
ok($s['breakdown'] === [
    ['reason' => 'suppression', 'count' => 1],
    ['reason' => 'throttle', 'count' => 3],
], 'summarize() lists only non-zero reasons in registry order');
$s2 = BlockedCount::summarize(['id' => 1]);
ok($s2['total'] === 0 && $s2['breakdown'] === [], 'summarize() handles pre-migration rows (missing columns)');

$resetSeams();

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
