<?php
/**
 * Diagnostics unit tests — NO network, NO database.
 *
 * Exercises the pure verdict builders in \App\Diagnostics with injected
 * fakes: checkPhp(), dnsVerdictForSender() (mock DNS lookup),
 * providersVerdict() (array settings + mock HTTP/SMTP), cronVerdict(),
 * licenseVerdict(), and renderTextReport().
 *
 * Usage: php tests/diagnostics/DiagnosticsTest.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

use App\Diagnostics;

$failures = 0;
$passed = 0;
function ok(bool $cond, string $name): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "  PASS: {$name}\n"; }
    else { $failures++; echo "  FAIL: {$name}\n"; }
}

// ---------------------------------------------------------------- checkPhp
echo "checkPhp:\n";
$r = Diagnostics::checkPhp();
ok($r['status'] === 'pass', 'current runtime passes (PHP ' . PHP_VERSION . ')');
ok($r['id'] === 'php' && strpos($r['detail'], PHP_VERSION) !== false, 'detail names the PHP version');

// ------------------------------------------------------- dnsVerdictForSender
echo "dnsVerdictForSender:\n";
Diagnostics::setDnsLookup(fn(string $d): array => [
    'domain' => $d, 'summary' => 'pass',
    'spf' => ['status' => 'pass'], 'dkim' => ['status' => 'pass'],
    'dmarc' => ['status' => 'pass'], 'missing' => [],
]);
$r = Diagnostics::dnsVerdictForSender('ops@example.com');
ok($r['status'] === 'pass' && strpos($r['detail'], 'example.com') !== false, 'all records present -> pass');

Diagnostics::setDnsLookup(fn(string $d): array => [
    'domain' => $d, 'summary' => 'warn',
    'spf' => ['status' => 'pass'], 'dkim' => ['status' => 'warn'],
    'dmarc' => ['status' => 'pass'], 'missing' => ['DKIM: no TXT record containing ...'],
]);
$r = Diagnostics::dnsVerdictForSender('ops@example.com');
ok($r['status'] === 'warn' && strpos($r['fix'], 'Zone Editor') !== false, 'partial auth -> warn with plain-language fix');

Diagnostics::setDnsLookup(fn(string $d): array => [
    'domain' => $d, 'summary' => 'fail',
    'spf' => ['status' => 'fail'], 'dkim' => ['status' => 'warn'],
    'dmarc' => ['status' => 'fail'], 'missing' => ['SPF: no TXT record ...'],
]);
$r = Diagnostics::dnsVerdictForSender('ops@example.com');
ok($r['status'] === 'fail', 'no records -> fail');

$r = Diagnostics::dnsVerdictForSender('');
ok($r['status'] === 'warn' && strpos($r['fix'], 'Settings') !== false, 'no sender email configured -> warn pointing at Settings');

$r = Diagnostics::dnsVerdictForSender('not-an-email');
ok($r['status'] === 'warn', 'invalid sender email -> warn, not crash');
Diagnostics::setDnsLookup(null);

// ----------------------------------------------------------- providersVerdict
echo "providersVerdict:\n";
$settings = [
    'active_email_provider' => 'sendgrid',
    'sendgrid_api_key' => 'SG.test-key',
    'resend_api_key' => 're_test-key',
];
$gs = fn(string $k, ?string $d = null): ?string => $settings[$k] ?? $d;
Diagnostics::setHttpHandler(fn(string $url, array $headers): array => ['code' => 200, 'body' => '{}']);
$results = Diagnostics::providersVerdict($gs);
$byId = [];
foreach ($results as $row) { $byId[$row['id']] = $row; }
ok(($byId['provider_sendgrid']['status'] ?? null) === 'pass', 'active provider, key accepted (200) -> pass');
ok(($byId['provider_resend']['status'] ?? null) === 'pass', 'second configured provider also checked -> pass');
ok(strpos($byId['provider_sendgrid']['detail'], 'SG.test-key') === false, 'API key never echoed in detail');

// 401 -> fail with key-regeneration fix
Diagnostics::setHttpHandler(fn(string $url, array $headers): array => ['code' => 401, 'body' => '{"errors":[]}']);
$results = Diagnostics::providersVerdict($gs);
$byId = [];
foreach ($results as $row) { $byId[$row['id']] = $row; }
ok(($byId['provider_sendgrid']['status'] ?? null) === 'fail', 'rejected key (401) -> fail');
ok(strpos($byId['provider_sendgrid']['fix'], 'fresh key') !== false, 'fail fix tells buyer to regenerate the key');

// unreachable -> warn, never fail (host firewall is the common cause)
Diagnostics::setHttpHandler(function (string $url, array $headers): array {
    throw new RuntimeException('connection failed: Could not resolve host');
});
$results = Diagnostics::providersVerdict($gs);
$byId = [];
foreach ($results as $row) { $byId[$row['id']] = $row; }
ok(($byId['provider_sendgrid']['status'] ?? null) === 'warn', 'provider unreachable -> warn (not fail)');
ok(strpos($byId['provider_sendgrid']['fix'], '443') !== false, 'warn fix mentions outbound HTTPS');
Diagnostics::setHttpHandler(null);

// active provider with no credentials -> fail
$gs2 = fn(string $k, ?string $d = null): ?string => ['active_email_provider' => 'mailgun'][$k] ?? $d;
$results = Diagnostics::providersVerdict($gs2);
ok(count($results) === 1 && $results[0]['status'] === 'fail', 'active provider missing key -> single fail');
ok(strpos($results[0]['fix'], 'Settings') !== false, 'fail fix points at Settings');

// SMTP provider: login probe verifies credentials, sends nothing
$gs3 = fn(string $k, ?string $d = null): ?string => [
    'active_email_provider' => 'smtp',
    'smtp_host' => 'mail.example.com', 'smtp_port' => '587',
    'smtp_user' => 'ops@example.com', 'smtp_pass' => 's3cret',
    'smtp_encryption' => 'tls',
][$k] ?? $d;
$calls = [];
Diagnostics::setSmtpProbe(function (string $h, int $p, string $e, string $u, string $pw) use (&$calls): array {
    $calls[] = [$h, $p, $e, $u, $pw];
    return ['ok' => true, 'detail' => 'authenticated'];
});
$results = Diagnostics::providersVerdict($gs3);
$byId = [];
foreach ($results as $row) { $byId[$row['id']] = $row; }
// Global smtp_* settings fall back to every SMTP provider (mirrors SmartEmailRouter).
ok(count($results) === 6 && ($byId['provider_smtp']['status'] ?? null) === 'pass', 'SMTP login accepted -> pass (all SMTP providers share global creds)');
ok(strpos($byId['provider_smtp']['detail'], 'No mail was sent') !== false, 'SMTP detail states nothing was sent');
ok($calls[0][4] === 's3cret' && strpos($byId['provider_smtp']['detail'], 's3cret') === false, 'password used for probe, never echoed');
Diagnostics::setSmtpProbe(fn(string $h, int $p, string $e, string $u, string $pw): array => ['ok' => false, 'detail' => 'login rejected by server (got: 535 Authentication failed)']);
$results = Diagnostics::providersVerdict($gs3);
ok($results[0]['status'] === 'fail' && strpos($results[0]['fix'], 'SMTP') !== false, 'SMTP login rejected -> fail with SMTP fix');
Diagnostics::setSmtpProbe(null);

// ---------------------------------------------------------------- cronVerdict
echo "cronVerdict:\n";
$now = date('Y-m-d H:i:s');
$r = Diagnostics::cronVerdict(['last_run' => $now, 'pending' => 3, 'in_progress' => 0, 'stuck' => 0, 'running_now' => false]);
ok($r['status'] === 'pass' && strpos($r['detail'], '3 waiting') !== false, 'recent run, shallow queue -> pass');

$r = Diagnostics::cronVerdict(['last_run' => null, 'pending' => 0, 'in_progress' => 0, 'stuck' => 0, 'running_now' => false]);
ok($r['status'] === 'warn' && strpos($r['fix'], 'Cron Jobs') !== false, 'never ran -> warn with cPanel cron fix');

$r = Diagnostics::cronVerdict(['last_run' => date('Y-m-d H:i:s', time() - 3600), 'pending' => 12, 'in_progress' => 0, 'stuck' => 0, 'running_now' => false]);
ok($r['status'] === 'fail', 'stale run + waiting tasks -> fail');

$r = Diagnostics::cronVerdict(['last_run' => $now, 'pending' => 0, 'in_progress' => 2, 'stuck' => 2, 'running_now' => false]);
ok($r['status'] === 'fail' && strpos($r['detail'], '30 minutes') !== false, 'stuck tasks -> fail');

// ------------------------------------------------------------- licenseVerdict
echo "licenseVerdict:\n";
$r = Diagnostics::licenseVerdict(['status' => 'valid', 'label' => 'Licensed', 'detail' => '', 'sending_allowed' => true, 'grace_expired' => false]);
ok($r['status'] === 'pass', 'valid license -> pass');
$r = Diagnostics::licenseVerdict(['status' => 'unlicensed', 'label' => 'Unlicensed', 'detail' => 'No license server configured.', 'sending_allowed' => true, 'grace_expired' => false]);
ok($r['status'] === 'warn', 'unlicensed (fail-open) -> warn');
$r = Diagnostics::licenseVerdict(['status' => 'revoked', 'label' => 'Revoked', 'detail' => '', 'sending_allowed' => false, 'grace_expired' => false]);
ok($r['status'] === 'fail' && strpos($r['detail'], 'paused') !== false, 'revoked -> fail, sending paused');
$r = Diagnostics::licenseVerdict(['status' => 'unreachable', 'label' => 'Server unreachable', 'detail' => '', 'sending_allowed' => true, 'grace_expired' => false]);
ok($r['status'] === 'warn', 'unreachable -> warn (app keeps working)');

// ---------------------------------------------------------- renderTextReport
echo "renderTextReport:\n";
$checks = [
    Diagnostics::result('php', 'pass', 'PHP version and extensions', 'PHP 8.3.6, all extensions.', 'No action needed.'),
    Diagnostics::result('dns', 'warn', 'Sender domain email authentication', "'example.com' missing DKIM.", 'Add the TXT record.'),
];
$text = Diagnostics::renderTextReport($checks);
ok(strpos($text, 'Smarketer Pro — Diagnostics Report') === 0, 'report has the expected header');
ok(strpos($text, '[PASS] PHP version and extensions') !== false, 'pass line rendered');
ok(strpos($text, '[WARN] Sender domain email authentication') !== false, 'warn line rendered');
ok(strpos($text, 'Fix: Add the TXT record.') !== false, 'fix step included for non-pass');
ok(strpos($text, 'No action needed.') === false, "'No action needed' fixes are omitted from the report");
ok(strpos($text, 'Summary: 0 failing, 1 warnings out of 2 checks.') !== false, 'summary line correct');

echo "\n{$passed} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
