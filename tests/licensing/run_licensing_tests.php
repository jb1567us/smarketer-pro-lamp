<?php
/**
 * Licensing tests.
 *
 * Usage: php tests/licensing/run_licensing_tests.php
 *
 * Two sections:
 *   A. Pure unit tests — NO database, NO network. Covers domain/key
 *      normalization, verdict sign/verify (+ tamper), the decideSending
 *      behavior matrix, and grace-period math.
 *   B. DB-backed integration — spins up a scratch MariaDB database
 *      (licensing_test), applies a minimal settings table, points the
 *      DB_* process environment at it (tests/support/db_env.php — no files
 *      written), and exercises validateNow /
 *      registerNow / releaseDomainNow / dailyCheck / sendingAllowed with a
 *      STUBBED HTTP layer (no live network, ever). Restores the repo tree
 *      afterwards.
 *
 * Also asserts client/server domain-normalization parity by loading
 * license-server/lib/common.php and comparing ls_normalize_domain() with
 * \App\Licensing::normalizeDomain() over a corpus of inputs.
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';
require $repo . '/license-server/lib/common.php';

$passed = 0;
$failed = 0;
function lok(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS: {$name}\n"; }
    else { $failed++; echo "  FAIL: {$name}\n"; }
}

use App\Licensing;

// ══ A. Pure unit tests (no DB, no network) ═══════════════════════════════
echo "normalization:\n";
$normCases = [
    'Example.COM' => 'example.com',
    'https://www.example.com/shop/?x=1' => 'example.com',
    'www.example.com:443' => 'example.com',
    'http://user@example.com/path' => 'example.com',
    'example.com.' => 'example.com',
    '  EXAMPLE.com  ' => 'example.com',
    'sub.example.com' => 'sub.example.com',
    'www.sub.example.com' => 'sub.example.com', // only ONE www. stripped
    'https://example.com:8443/a?b#c' => 'example.com',
];
foreach ($normCases as $in => $want) {
    lok(Licensing::normalizeDomain($in) === $want, "normalizeDomain('{$in}') === '{$want}'");
    lok(ls_normalize_domain($in) === $want, "server parity: ls_normalize_domain('{$in}')");
    lok(Licensing::normalizeDomain($in) === ls_normalize_domain($in), "client/server parity on '{$in}'");
}
lok(Licensing::normalizeKey(' smp-abcd-1234 ') === 'SMP-ABCD-1234', 'normalizeKey trims + uppercases');

echo "verdict sign/verify:\n";
$secret = 'test-secret-' . str_repeat('x', 32);
$v = Licensing::defaultVerdict('valid', 'ok');
$v['checked_at'] = 1234567890;
$blob = Licensing::signVerdict($v, $secret);
$back = Licensing::verifyVerdict($blob, $secret);
lok(is_array($back) && $back['status'] === 'valid' && $back['checked_at'] === 1234567890, 'verdict round-trips');
lok(Licensing::verifyVerdict($blob . 'x', $secret) === null, 'tampered signature rejected');
lok(Licensing::verifyVerdict('garbage', $secret) === null, 'garbage blob rejected');
$parts = explode('.', $blob, 2);
$forged = $parts[0] . '.' . str_repeat('0', 64);
lok(Licensing::verifyVerdict($forged, $secret) === null, 'forged signature rejected');
lok(Licensing::verifyVerdict($blob, 'wrong-secret') === null, 'wrong secret rejected');

echo "behavior matrix (decideSending):\n";
foreach (['valid', 'invalid', 'unreachable', 'unlicensed', 'unknown'] as $st) {
    lok(Licensing::decideSending(['status' => $st]) === true, "sending allowed when '{$st}' (soft)");
}
lok(Licensing::decideSending(['status' => 'revoked']) === false, "sending paused ONLY when 'revoked'");
lok(Licensing::decideSending([]) === true, 'sending allowed on malformed verdict (fail open)');

echo "grace math:\n";
$now = 1_700_000_000;
$unreach = ['status' => 'unreachable', 'grace_until' => $now + 100];
lok(Licensing::graceExpired($unreach, $now) === false, 'grace not expired inside window');
lok(Licensing::graceExpired($unreach, $now + 101) === true, 'grace expired past window');
lok(Licensing::graceExpired(['status' => 'valid', 'grace_until' => $now - 1], $now) === false, 'grace only applies to unreachable');
lok(Licensing::GRACE_SECONDS === 14 * 86400, 'grace is 14 days');

// ══ B. DB-backed integration (stubbed HTTP) ═════════════════════════════
echo "http-stubbed flows:\n";

$configDir = $repo . '/config';
require_once __DIR__ . '/../support/db_env.php';

try {
    $dbUser = 'licensing_test';
    $dbPass = 't_' . bin2hex(random_bytes(8));
    $sh = function (string $cmd): void {
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) { throw new RuntimeException("shell failed: {$cmd}\n" . implode("\n", $out)); }
    };
    $sh("mysql -u root -e \"DROP DATABASE IF EXISTS licensing_test; CREATE DATABASE licensing_test CHARACTER SET utf8mb4;\"");
    $sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON licensing_test.* TO '{$dbUser}'@'%'; FLUSH PRIVILEGES;\"");
    if (!is_dir($configDir)) { mkdir($configDir, 0755, true); }
    test_db_use_env('127.0.0.1', 'licensing_test', $dbUser, $dbPass);

    $pdo = \App\Database::getConnection();
    $pdo->exec("CREATE TABLE settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
    // Minimal app_secret so verdict signing doesn't touch the network/DB twice.
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('app_secret', '" . str_repeat('s', 64) . "')");

    $set = function (string $k, string $val) use ($pdo): void {
        $s = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $s->execute([$k, $val]);
    };
    $set('license_server_url', 'https://license.test/api');

    // Scripted stub HTTP layer: [$url, $payload] → response array | null (= unreachable).
    $script = [];
    $calls = [];
    $installStub = function () use (&$script, &$calls): void {
        Licensing::setHttpHandler(function (string $url, array $payload) use (&$script, &$calls) {
            $calls[] = [$url, $payload];
            foreach ($script as $entry) {
                if (str_contains($url, $entry[0])) {
                    return $entry[1];
                }
            }
            return null;
        });
    };
    $installStub();
    // Direct DB writes bypass the per-request verdict cache; clear it.
    $clearCache = function (): void {
        $ref = new ReflectionClass(Licensing::class);
        $prop = $ref->getProperty('verdictCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    };

    // 1. validateNow → valid
    $script = [['validate.php', ['valid' => true, 'reason' => 'ok', 'domains' => ['example.com'], 'max_domains' => 1]]];
    $set('license_key', 'SMP-TEST-KEY-0001');
    $v = Licensing::validateNow();
    lok($v['status'] === 'valid' && $v['last_ok_at'] > 0, 'validateNow stores valid verdict');
    lok(Licensing::sendingAllowed() === true, 'sending allowed when valid');
    // Verdict persisted + signed: re-read raw and verify signature.
    $raw = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='license_verdict'")->fetchColumn();
    lok(\App\Licensing::verifyVerdict($raw) !== null, 'stored verdict is HMAC-signed');
    // Tamper the stored blob → treated as unknown → sending still allowed.
    $pdo->exec("UPDATE settings SET setting_value = 'tampered.blob' WHERE setting_key='license_verdict'");
    $clearCache();
    lok(Licensing::sendingAllowed() === true, 'tampered verdict cache → fail open, sending allowed');

    // 2. revoked → sending paused, everything else fine
    $script = [['validate.php', ['valid' => false, 'reason' => 'revoked']]];
    $clearCache();
    $v = Licensing::validateNow();
    lok($v['status'] === 'revoked', 'revoked verdict stored');
    lok(Licensing::sendingAllowed() === false, 'sending PAUSED when revoked');
    $ui = Licensing::statusForUi();
    lok($ui['status'] === 'revoked' && $ui['sending_allowed'] === false, 'UI reflects revoked');
    // revoked → send gate throws the license message (not a compliance message)
    try {
        \App\Compliance::requireCompliantSend('anyone@example.com');
        lok(false, 'send gate throws when revoked');
    } catch (\App\Exceptions\OutreachException $e) {
        lok(stripos($e->getMessage(), 'revoked') !== false, 'send gate refusal mentions revocation');
    }

    // 3. un-revoke → next check restores sending
    $script = [['validate.php', ['valid' => true, 'reason' => 'ok', 'domains' => ['example.com'], 'max_domains' => 1]]];
    $clearCache();
    // Force the verdict old enough that even the revoked fast-path rechecks.
    $old = Licensing::readVerdict();
    $old['checked_at'] = time() - 99999;
    Licensing::storeVerdict($old);
    Licensing::dailyCheck();
    lok(Licensing::sendingAllowed() === true, 'un-revoke restores sending on next check');

    // 4. unknown key → soft (notice only)
    $script = [['validate.php', ['valid' => false, 'reason' => 'unknown_key']]];
    $clearCache();
    $v = Licensing::validateNow('SMP-WRONG-KEY');
    lok($v['status'] === 'invalid' && Licensing::sendingAllowed() === true, 'wrong key is soft: notice, keeps working');

    // 5. unreachable → soft + grace math
    $script = []; // stub returns null → unreachable
    $clearCache();
    $calls = [];
    $v = Licensing::validateNow();
    lok($v['status'] === 'unreachable' && Licensing::sendingAllowed() === true, 'unreachable keeps working');
    lok(is_int($v['grace_until']) && $v['grace_until'] > time(), 'grace_until set on unreachable');
    lok(Licensing::graceExpired($v) === false, 'grace not yet expired');

    // 6. registerNow success → key stored + domain registered
    $script = [['register.php', ['valid' => true, 'reason' => 'registered', 'domains' => ['example.com'], 'max_domains' => 1]]];
    $clearCache();
    $pdo->exec("DELETE FROM settings WHERE setting_key='license_key'");
    $v = Licensing::registerNow('smp-new-key-0002', 'https://www.example.com/');
    lok($v['status'] === 'valid' && $v['domain'] === 'example.com', 'registerNow normalizes domain + valid');
    lok($pdo->query("SELECT setting_value FROM settings WHERE setting_key='license_key'")->fetchColumn() === 'SMP-NEW-KEY-0002', 'registerNow stores normalized key');

    // 7. registerNow 409 slots exhausted → soft, key NOT stored
    $script = [['register.php', ['valid' => false, 'reason' => 'domain_slots_exhausted', 'domains' => ['a.com'], 'max_domains' => 1]]];
    $clearCache();
    $pdo->exec("DELETE FROM settings WHERE setting_key='license_key'");
    $v = Licensing::registerNow('SMP-FULL-KEY', 'b.com');
    lok($v['status'] === 'invalid' && $v['reason'] === 'domain_slots_exhausted', 'slots exhausted → invalid (soft)');
    lok(Licensing::sendingAllowed() === true, 'slots exhausted keeps working');
    lok($pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key='license_key'")->fetchColumn() == 0, 'failed register does not store key');

    // 8. registerNow unreachable → key stored anyway (soft), grace verdict
    $script = [];
    $clearCache();
    $v = Licensing::registerNow('SMP-OFFLINE-KEY', 'example.com');
    lok($v['status'] === 'unreachable', 'offline register → unreachable verdict');
    lok($pdo->query("SELECT setting_value FROM settings WHERE setting_key='license_key'")->fetchColumn() === 'SMP-OFFLINE-KEY', 'offline register still stores key for later validation');

    // 9. dailyCheck throttling: recent check → no HTTP; stale → HTTP
    $script = [['validate.php', ['valid' => true, 'reason' => 'ok', 'domains' => ['example.com'], 'max_domains' => 1]]];
    $fresh = Licensing::defaultVerdict('valid', 'ok');
    $fresh['checked_at'] = time();
    Licensing::storeVerdict($fresh);
    $calls = [];
    Licensing::dailyCheck();
    lok(count($calls) === 0, 'dailyCheck skips when checked recently');
    $fresh['checked_at'] = time() - 90000;
    Licensing::storeVerdict($fresh);
    Licensing::dailyCheck();
    lok(count($calls) === 1, 'dailyCheck revalidates when stale');

    // 10. releaseDomainNow
    $script = [['release.php', ['valid' => true, 'reason' => 'released', 'domains' => [], 'max_domains' => 1]]];
    $r = Licensing::releaseDomainNow('example.com');
    lok($r['ok'] === true, 'releaseDomainNow reports ok');
    $script = [];
    $r = Licensing::releaseDomainNow('example.com');
    lok($r['ok'] === false && stripos($r['message'], 'Could not reach') !== false, 'release unreachable → clear message, nothing changed');

    // 11. licensing disabled entirely → unlicensed, everything works
    $set('license_server_url', '');
    $clearCache();
    $v = Licensing::validateNow();
    lok($v['status'] === 'unlicensed' && Licensing::sendingAllowed() === true, 'no server URL → unlicensed, fully working');
    lok(Licensing::isEnabled() === false, 'isEnabled false without URL');
    $ui = Licensing::statusForUi();
    lok($ui['status'] === 'unlicensed' && $ui['sending_allowed'] === true, 'UI unlicensed state');

    echo "\n{$passed} passed, {$failed} failed\n";
    if ($failed > 0) { exit(1); }
} finally {
    Licensing::setHttpHandler(null);
    test_db_restore_env();
    try {
        exec("mysql -u root -e \"DROP DATABASE IF EXISTS licensing_test; DROP USER IF EXISTS 'licensing_test'@'%';\" 2>&1");
    } catch (\Throwable $e) { /* best effort */ }
}
