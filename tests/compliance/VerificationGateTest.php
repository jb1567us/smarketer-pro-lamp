<?php
/**
 * Verification-gate tests (compliance gap item 3).
 *
 * Usage: php tests/compliance/VerificationGateTest.php
 *
 * Fakes and stubs ONLY — no network calls, no database. The compliance gate
 * exposes two test seams (setVerificationSettingsReader /
 * setVerificationProviderFactory) plus the DB-free core method
 * Compliance::applyVerificationGate(), all inside the "item 3" block in
 * includes/Compliance.php.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/autoload.php';

use App\Compliance;
use App\Exceptions\OutreachException;
use App\Verification\EmailVerificationProvider;
use App\Verification\EmailVerificationResult;
use App\Verification\MillionVerifierProvider;
use App\Verification\NullVerificationProvider;

$failures = 0;
$passed = 0;

function ok(bool $cond, string $name): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "  PASS: {$name}\n"; }
    else { $failures++; echo "  FAIL: {$name}\n"; }
}

function expectOutreachThrow(callable $fn, string $needle, string $name): void
{
    try {
        $fn();
        ok(false, $name . ' (no exception thrown)');
    } catch (OutreachException $e) {
        ok(stripos($e->getMessage(), $needle) !== false, $name . ' [got: ' . substr($e->getMessage(), 0, 100) . ']');
    } catch (\Throwable $e) {
        ok(false, $name . ' (wrong exception: ' . get_class($e) . ')');
    }
}

/** Fake provider: scripted status (or throw), records every verify() call. */
class FakeVerificationProvider implements EmailVerificationProvider
{
    public int $calls = 0;
    public array $seenEmails = [];

    public function __construct(
        private readonly string $status,
        private readonly bool $throw = false
    ) {}

    public function name(): string { return 'fake'; }

    public function verify(string $email): EmailVerificationResult
    {
        $this->calls++;
        $this->seenEmails[] = $email;
        if ($this->throw) {
            throw new \RuntimeException('simulated provider outage');
        }
        return new EmailVerificationResult($this->status, $this->name(), new \DateTimeImmutable(), ['fake' => true]);
    }
}

/** Capture recorder + warnings. */
function harness(): array
{
    $recorded = [];
    $warnings = [];
    return [
        'record' => function (string $status) use (&$recorded): void { $recorded[] = $status; },
        'warn' => function (string $msg) use (&$warnings): void { $warnings[] = $msg; },
        'recorded' => &$recorded,
        'warnings' => &$warnings,
    ];
}

/** Point the wrapper at fake settings; pass null key to reset. */
function useSettings(array $settings): void
{
    Compliance::setVerificationSettingsReader(
        static fn(string $key, string $default): string => (string)($settings[$key] ?? $default)
    );
}
function useProvider(EmailVerificationProvider $p): void
{
    Compliance::setVerificationProviderFactory(static fn(string $key): EmailVerificationProvider => $p);
}
function usePersister(array &$recorded): void
{
    Compliance::setVerificationStatusPersister(static function (string $status) use (&$recorded): void {
        $recorded[] = $status;
    });
}
function resetSeams(): void
{
    Compliance::setVerificationSettingsReader(null);
    Compliance::setVerificationProviderFactory(null);
    Compliance::setVerificationStatusPersister(null);
}

echo "result value object:\n";
$r = new EmailVerificationResult('valid', 'fake', new \DateTimeImmutable('2026-01-01'), ['a' => 1]);
ok($r->isValid() && !$r->isInvalid() && !$r->isRisky() && !$r->isUnknown(), 'status helpers agree');
ok($r->provider === 'fake' && $r->raw === ['a' => 1], 'provider/raw carried through');
try { new EmailVerificationResult('bogus', 'x', new \DateTimeImmutable()); ok(false, 'bad status rejected'); }
catch (\InvalidArgumentException $e) { ok(true, 'bad status rejected'); }

echo "NullVerificationProvider:\n";
$n = new NullVerificationProvider('unknown');
ok($n->verify('a@b.com')->isUnknown() && $n->name() === 'null', 'null provider yields unknown, no network');

echo "gate disabled → no-op:\n";
useSettings(['verification_required' => '0']);
$fake = new FakeVerificationProvider('invalid');
useProvider($fake);
$h = harness();
try {
    Compliance::runEmailVerificationGate('lead@example.com', null);
    ok(true, 'no exception when gate disabled');
} catch (\Throwable $e) {
    ok(false, 'no exception when gate disabled (got ' . get_class($e) . ')');
}
ok($fake->calls === 0, 'provider never consulted when disabled');

echo "gate enabled but no key → no-op (never throws):\n";
useSettings(['verification_required' => '1', 'verification_api_key' => '']);
$fake2 = new FakeVerificationProvider('invalid');
useProvider($fake2);
try {
    Compliance::runEmailVerificationGate('lead@example.com', null);
    ok(true, 'no exception without key');
} catch (\Throwable $e) {
    ok(false, 'no exception without key (got ' . get_class($e) . ')');
}
ok($fake2->calls === 0, 'provider never consulted without key');

echo "core gate decisions (applyVerificationGate, no DB):\n";
// invalid → throws + recorded
$fake = new FakeVerificationProvider('invalid');
$h = harness();
expectOutreachThrow(
    fn() => Compliance::applyVerificationGate('x@example.com', $fake, null, 'block', false, 30, $h['record'], $h['warn']),
    'invalid', 'invalid verdict blocks'
);
ok($h['recorded'] === ['invalid'], 'invalid status persisted');
// risky + block → throws
$fake = new FakeVerificationProvider('risky');
$h = harness();
expectOutreachThrow(
    fn() => Compliance::applyVerificationGate('x@example.com', $fake, null, 'block', false, 30, $h['record'], $h['warn']),
    'risky', 'risky+block throws'
);
ok($h['recorded'] === ['risky'], 'risky status persisted on block');
// risky + flag → allows
$fake = new FakeVerificationProvider('risky');
$h = harness();
try {
    Compliance::applyVerificationGate('x@example.com', $fake, null, 'flag', false, 30, $h['record'], $h['warn']);
    ok(true, 'risky+flag allows send');
} catch (\Throwable $e) { ok(false, 'risky+flag allows send (got ' . get_class($e) . ')'); }
ok($h['recorded'] === ['risky'], 'risky status persisted on flag');
// valid → allows
$fake = new FakeVerificationProvider('valid');
$h = harness();
try {
    Compliance::applyVerificationGate('x@example.com', $fake, null, 'block', false, 30, $h['record'], $h['warn']);
    ok(true, 'valid verdict allows send');
} catch (\Throwable $e) { ok(false, 'valid verdict allows send'); }
ok($h['recorded'] === ['valid'], 'valid status persisted');
// outage (throws inside provider), non-strict → allows + warning
$fake = new FakeVerificationProvider('unknown', true);
$h = harness();
try {
    Compliance::applyVerificationGate('x@example.com', $fake, null, 'block', false, 30, $h['record'], $h['warn']);
    ok(true, 'provider outage allows send when not strict');
} catch (\Throwable $e) { ok(false, 'provider outage allows send when not strict'); }
ok($h['recorded'] === ['unknown'], 'unknown status persisted on outage');
ok(count($h['warnings']) >= 1, 'warning logged on outage');
// outage, strict → throws
$fake = new FakeVerificationProvider('unknown', true);
$h = harness();
expectOutreachThrow(
    fn() => Compliance::applyVerificationGate('x@example.com', $fake, null, 'block', true, 30, $h['record'], $h['warn']),
    'strict', 'provider outage throws when strict'
);
// 'unknown' status (no throw) non-strict → allows
$fake = new FakeVerificationProvider('unknown');
$h = harness();
try {
    Compliance::applyVerificationGate('x@example.com', $fake, null, 'block', false, 30, $h['record'], $h['warn']);
    ok(true, "'unknown' verdict allows send when not strict");
} catch (\Throwable $e) { ok(false, "'unknown' verdict allows send when not strict"); }

echo "cache behavior:\n";
// fresh cached verdict → provider never called
$fake = new FakeVerificationProvider('invalid');
$h = harness();
$lead = ['verification_status' => 'valid', 'verified_at' => date('Y-m-d H:i:s', time() - 3600)];
try {
    Compliance::applyVerificationGate('x@example.com', $fake, $lead, 'block', false, 30, $h['record'], $h['warn']);
    ok(true, 'cache hit allows without provider');
} catch (\Throwable $e) { ok(false, 'cache hit allows without provider'); }
ok($fake->calls === 0, 'provider skipped on fresh cache hit');
ok($h['recorded'] === [], 'nothing persisted on cache hit');
// stale cache → provider consulted
$fake = new FakeVerificationProvider('valid');
$h = harness();
$lead = ['verification_status' => 'valid', 'verified_at' => date('Y-m-d H:i:s', time() - 31 * 86400)];
try {
    Compliance::applyVerificationGate('x@example.com', $fake, $lead, 'block', false, 30, $h['record'], $h['warn']);
    ok(true, 'stale cache re-verifies and allows');
} catch (\Throwable $e) { ok(false, 'stale cache re-verifies'); }
ok($fake->calls === 1, 'provider called when cache is stale');
// cached 'unknown' is never treated as a verdict → re-verify
$fake = new FakeVerificationProvider('valid');
$h = harness();
$lead = ['verification_status' => 'unknown', 'verified_at' => date('Y-m-d H:i:s', time() - 3600)];
Compliance::applyVerificationGate('x@example.com', $fake, $lead, 'block', false, 30, $h['record'], $h['warn']);
ok($fake->calls === 1, "cached 'unknown' does not short-circuit");
ok($h['recorded'] === ['valid'], 'fresh verdict persisted over cached unknown');
// cache_days=0 disables caching
$fake = new FakeVerificationProvider('valid');
$h = harness();
$lead = ['verification_status' => 'valid', 'verified_at' => date('Y-m-d H:i:s', time() - 60)];
Compliance::applyVerificationGate('x@example.com', $fake, $lead, 'block', false, 0, $h['record'], $h['warn']);
ok($fake->calls === 1, 'cache disabled with cache_days=0');

echo "wrapper-level (fake settings + fake provider):\n";
useSettings([
    'verification_required' => '1',
    'verification_api_key' => 'test-key',
    'verification_provider' => 'millionverifier',
    'verification_risky_action' => 'flag',
    'verification_strict' => '0',
    'verification_cache_days' => '30',
]);
$fake = new FakeVerificationProvider('invalid');
useProvider($fake);
// Lead passed explicitly (fresh cached 'valid' verdict) → wrapper short-circuits
// without any database access, which the DB-less test env has none of.
$lead = ['verification_status' => 'valid', 'verified_at' => date('Y-m-d H:i:s')];
$wrapperPersisted = [];
usePersister($wrapperPersisted);
try {
    Compliance::runEmailVerificationGate('x@example.com', $lead);
    ok(true, 'wrapper honors cache without DB');
} catch (\Throwable $e) { ok(false, 'wrapper honors cache without DB'); }
ok($fake->calls === 0, 'wrapper did not call provider on cache hit');
// no cached verdict (lead row passed explicitly so no DB lookup) → wrapper
// consults provider and blocks on invalid
$fake2 = new FakeVerificationProvider('invalid');
useProvider($fake2);
$wrapperPersisted2 = [];
usePersister($wrapperPersisted2);
expectOutreachThrow(
    fn() => Compliance::runEmailVerificationGate('x@example.com', []),
    'invalid', 'wrapper blocks on invalid (provider factory injected)'
);
ok($fake2->calls === 1, 'wrapper consulted provider exactly once');
ok($wrapperPersisted2 === ['invalid'], 'wrapper persisted invalid verdict');

echo "MillionVerifierProvider offline behavior (no network):\n";
try { new MillionVerifierProvider(''); ok(false, 'empty key rejected'); }
catch (\InvalidArgumentException $e) { ok(true, 'empty key rejected'); }
$mv = new MillionVerifierProvider('dummy-key');
$res = $mv->verify('not-an-email');
ok($res->isInvalid() && $res->provider === 'millionverifier', 'syntax-invalid email → invalid without network');
ok($mv->name() === 'millionverifier', 'adapter name');

resetSeams();

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
