<?php
/**
 * Email-verification settings UI tests (ITEM 1).
 *
 * Usage: php tests/compliance/VerificationSettingsUITest.php
 *
 * DB-free and network-free. Covers:
 *   A. Settings-API contract (static): the two UI keys are allowlisted in
 *      api/settings.php, and `verification_api_key` falls under the secret
 *      rule (redacted on read, never echoed back).
 *   B. Gate wiring: the UI toggle (`verification_required`) actually drives
 *      Compliance::runEmailVerificationGate — OFF ('0') and "on but no key"
 *      both no-op; ON ('1') with a key routes to the provider.
 *   C. api/verification_test.php's pure core (testMillionVerifierKey): key
 *      acceptance / rejection / transport failure with stubbed HTTP, and —
 *      critically — the key never appears in any returned message.
 *
 * Fakes and stubs ONLY — no network calls, no database.
 */
declare(strict_types=1);

define('VERIFICATION_TEST_UNIT', true);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';
require $repo . '/api/verification_test.php';

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

/** Recursively collect scalar string values from a nested array. */
function flattenStrings($v): array
{
    $out = [];
    $walk = function ($x) use (&$walk, &$out) {
        if (is_string($x)) { $out[] = $x; }
        elseif (is_array($x)) { foreach ($x as $i) { $walk($i); } }
    };
    $walk($v);
    return $out;
}

// ── A. Settings-API contract ─────────────────────────────────────────────
$settingsSrc = file_get_contents($repo . '/api/settings.php');

// Extract SETTINGS_ALLOWLIST entries.
preg_match("/const SETTINGS_ALLOWLIST = \[(.*?)\];/s", $settingsSrc, $m);
$allowlistSrc = $m[1] ?? '';
preg_match_all("/'([a-z0-9_]+)'/", $allowlistSrc, $mm);
$allowlist = $mm[1];

ok(in_array('verification_required', $allowlist, true), 'A1: verification_required is in the settings allowlist');
ok(in_array('verification_api_key', $allowlist, true), 'A2: verification_api_key is in the settings allowlist');

// Replicate the file's own secret rule with its extracted constants.
preg_match("/const SETTINGS_SECRET_SUFFIXES = \[(.*?)\];/s", $settingsSrc, $m);
preg_match_all("/'([^']+)'/", $m[1] ?? '', $mm);
$suffixes = $mm[1];
preg_match("/const SETTINGS_SECRET_EXPLICIT = \[(.*?)\];/s", $settingsSrc, $m);
preg_match_all("/'([^']+)'/", $m[1] ?? '', $mm);
$explicit = $mm[1];
$isSecret = function (string $key) use ($suffixes, $explicit): bool {
    if (in_array($key, $explicit, true)) return true;
    foreach ($suffixes as $s) {
        if (substr($key, -strlen($s)) === $s) return true;
    }
    return false;
};
ok($isSecret('verification_api_key'), 'A3: verification_api_key matches the secret rule (redacted on read)');
ok(!$isSecret('verification_required'), 'A4: verification_required is NOT secret (toggle stays visible)');
ok($isSecret('gemini_api_key') === $isSecret('verification_api_key'), 'A5: verification_api_key masked exactly like other provider keys');

// ── B. Gate wiring via the UI keys ───────────────────────────────────────
class Item1FakeProvider implements EmailVerificationProvider
{
    public int $calls = 0;
    public function __construct(private readonly string $status) {}
    public function name(): string { return 'item1-fake'; }
    public function verify(string $email): EmailVerificationResult
    {
        $this->calls++;
        return new EmailVerificationResult($this->status, $this->name(), new \DateTimeImmutable(), []);
    }
}

function item1Reader(array $map): callable
{
    return function (string $key, string $default) use ($map): string {
        return $map[$key] ?? $default;
    };
}
$noop = function (string $status): void {};

try {
    // B1: toggle OFF -> provider never touched, no throw.
    $fake = new Item1FakeProvider(EmailVerificationResult::INVALID);
    Compliance::setVerificationSettingsReader(item1Reader(['verification_required' => '0', 'verification_api_key' => 'key-x']));
    Compliance::setVerificationProviderFactory(fn(string $k): EmailVerificationProvider => $fake);
    Compliance::setVerificationStatusPersister($noop);
    Compliance::runEmailVerificationGate('bounce@example.com', []);
    ok($fake->calls === 0, 'B1: verification_required=0 -> gate no-ops, provider never called');

    // B2: toggle ON with a key -> provider consulted; invalid verdict blocks.
    $fake = new Item1FakeProvider(EmailVerificationResult::INVALID);
    Compliance::setVerificationSettingsReader(item1Reader(['verification_required' => '1', 'verification_api_key' => 'key-x']));
    Compliance::setVerificationProviderFactory(fn(string $k): EmailVerificationProvider => $fake);
    $thrown = false;
    try { Compliance::runEmailVerificationGate('bounce@example.com', []); }
    catch (OutreachException $e) { $thrown = stripos($e->getMessage(), 'verification') !== false; }
    ok($fake->calls === 1 && $thrown, 'B2: verification_required=1 + key -> provider called, invalid address blocked');

    // B3: toggle ON but NO key -> gate skips quietly (never throws, never calls).
    $fake = new Item1FakeProvider(EmailVerificationResult::INVALID);
    Compliance::setVerificationSettingsReader(item1Reader(['verification_required' => '1', 'verification_api_key' => '']));
    Compliance::setVerificationProviderFactory(fn(string $k): EmailVerificationProvider => $fake);
    $threw = false;
    try { Compliance::runEmailVerificationGate('bounce@example.com', []); }
    catch (\Throwable $e) { $threw = true; }
    ok($fake->calls === 0 && !$threw, 'B3: verification_required=1 + empty key -> skips, no throw (incomplete setup is safe)');

    // B4: valid verdict passes through without blocking.
    $fake = new Item1FakeProvider(EmailVerificationResult::VALID);
    Compliance::setVerificationSettingsReader(item1Reader(['verification_required' => '1', 'verification_api_key' => 'key-x']));
    Compliance::setVerificationProviderFactory(fn(string $k): EmailVerificationProvider => $fake);
    $threw = false;
    try { Compliance::runEmailVerificationGate('good@example.com', []); }
    catch (\Throwable $e) { $threw = true; }
    ok($fake->calls === 1 && !$threw, 'B4: valid verdict -> send proceeds');

    // B5: default provider id builds the MillionVerifier adapter; unknown id -> null provider.
    Compliance::setVerificationProviderFactory(null);
    Compliance::setVerificationSettingsReader(item1Reader(['verification_provider' => 'millionverifier']));
    $p = Compliance::makeVerificationProvider('key-x');
    ok($p instanceof MillionVerifierProvider, 'B5a: verification_provider=millionverifier -> MillionVerifierProvider');
    Compliance::setVerificationSettingsReader(item1Reader(['verification_provider' => 'nope']));
    $p = Compliance::makeVerificationProvider('key-x');
    ok($p instanceof NullVerificationProvider, 'B5b: unknown provider id -> NullVerificationProvider (never throws)');
} finally {
    Compliance::setVerificationSettingsReader(null);
    Compliance::setVerificationProviderFactory(null);
    Compliance::setVerificationStatusPersister(null);
}

// ── C. Test-connection core (stubbed HTTP) ───────────────────────────────
$testKey = 'mv_test_key_9z8y7x';
$seenUrl = null;
$stub = function (array $res) use (&$seenUrl): callable {
    return function (string $url) use ($res, &$seenUrl): array {
        $seenUrl = $url;
        return $res;
    };
};

// C1: accepted key with JSON credit balance.
$seenUrl = null;
$r = testMillionVerifierKey($testKey, $stub(['httpCode' => 200, 'body' => '{"credits": 1234}']));
ok($r['success'] === true && $r['credits'] === 1234, 'C1: accepted key -> success, credits parsed');
ok($seenUrl !== null && strpos($seenUrl, 'api=' . urlencode($testKey)) !== false, 'C2: stub request carried the key as query param');

// C3: bare-number balance shape.
$r = testMillionVerifierKey($testKey, $stub(['httpCode' => 200, 'body' => '987']));
ok($r['success'] === true && $r['credits'] === 987, 'C3: numeric body -> credits parsed');

// C4: accepted but unparseable balance -> still success, credits null.
$r = testMillionVerifierKey($testKey, $stub(['httpCode' => 200, 'body' => 'not-json']));
ok($r['success'] === true && $r['credits'] === null, 'C4: 200 with odd body -> success, credits null (not a failure)');

// C4b: REAL rejection shape — HTTP 200 + {"result":"error","error":"apikey_not_found"}.
$r = testMillionVerifierKey($testKey, $stub(['httpCode' => 200, 'body' => '{"result":"error","error":"apikey_not_found"}']));
ok($r['success'] === false && stripos($r['message'], 'rejected') !== false, 'C4b: 200 error-payload -> treated as key rejection, not acceptance');
$r = testMillionVerifierKey($testKey, $stub(['httpCode' => 200, 'body' => '{"error":"some_other_problem"}']));
ok($r['success'] === false, 'C4c: 200 with error field -> key rejection');

// C5/C6: rejected key / unreachable / bad status.
$r = testMillionVerifierKey($testKey, $stub(['httpCode' => 401, 'body' => '']));
ok($r['success'] === false, 'C5: 401 -> key rejected, success=false');
$r = testMillionVerifierKey($testKey, $stub(['httpCode' => 0, 'body' => '']));
ok($r['success'] === false, 'C6: transport failure -> success=false');
$r = testMillionVerifierKey($testKey, $stub(['httpCode' => 500, 'body' => 'oops']));
ok($r['success'] === false, 'C7: 500 -> success=false');

// C8: empty key never reaches the network.
$net = false;
$r = testMillionVerifierKey('   ', function (string $u) use (&$net): array { $net = true; return ['httpCode' => 200, 'body' => '1']; });
ok($r['success'] === false && $net === false, 'C8: empty key -> fails before any HTTP call');

// C9: the key must NEVER appear in any returned message (all payloads).
$payloads = [
    ['httpCode' => 200, 'body' => '{"credits": 5}'],
    ['httpCode' => 200, 'body' => '5'],
    ['httpCode' => 200, 'body' => '???'],
    ['httpCode' => 200, 'body' => '{"result":"error","error":"apikey_not_found"}'],
    ['httpCode' => 401, 'body' => ''],
    ['httpCode' => 403, 'body' => ''],
    ['httpCode' => 500, 'body' => 'err'],
    ['httpCode' => 0, 'body' => ''],
];
$leaked = false;
foreach ($payloads as $pl) {
    $rr = testMillionVerifierKey($testKey, $stub($pl));
    foreach (flattenStrings($rr) as $s) {
        if (strpos($s, $testKey) !== false) { $leaked = true; }
    }
}
ok(!$leaked, 'C9: key never echoed in any success/error message across all payloads');

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
