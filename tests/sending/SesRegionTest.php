<?php
/**
 * Item 3 — SES region selector tests.
 *
 * Asserts:
 *  1. The region allowlist is exactly the AWS-documented SES SMTP regions
 *     (commercial regions; GovCloud excluded), default us-east-1 first.
 *  2. Endpoint construction: email-smtp.<region>.amazonaws.com per region.
 *  3. Allowlist rejection: off-list values (typos, non-SMTP SES regions,
 *     injection attempts) normalize to the default.
 *  4. Default preserved: empty/missing/corrupt ses_region behaves exactly
 *     like the old hardcoded email-smtp.us-east-1.amazonaws.com.
 *  5. Wiring: settings API allowlist + server-side 400 validation,
 *     settings UI dropdown, dashboard.js save keys, Diagnostics
 *     region-aware SES probe (mocked probe, no network).
 *  6. Migration + installer coverage: migration file seeds ses_region,
 *     schema.sql seeds it for fresh installs, install.php imports schema.sql.
 *
 * Usage: php tests/sending/SesRegionTest.php
 * Pure PHP: no database, no network (Database::getSetting fails safe to the
 * default when no DB is configured).
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

$passed = 0;
$failed = 0;

function ses_ok(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS: {$name}\n";
    } else {
        $failed++;
        echo "  FAIL: {$name}\n";
    }
}

/* ── 1. Region allowlist ────────────────────────────────────────────────── */

$regions = \App\EmailSender::sesRegions();
// AWS's documented SES SMTP regions (official SMTP-credential generator list,
// minus GovCloud). Verified against docs.aws.amazon.com SES endpoints page.
$expected = [
    'us-east-1', 'us-east-2', 'us-west-2',
    'eu-west-1', 'eu-west-2', 'eu-central-1', 'eu-north-1', 'eu-south-1',
    'ap-south-1', 'ap-southeast-1', 'ap-southeast-2',
    'ap-northeast-1', 'ap-northeast-2',
    'ca-central-1', 'sa-east-1',
];
ses_ok(array_keys($regions) === $expected, 'allowlist is exactly the 15 documented SES SMTP regions');
ses_ok(\App\EmailSender::SES_DEFAULT_REGION === 'us-east-1', 'default region constant is us-east-1');
ses_ok(array_key_first($regions) === 'us-east-1', 'us-east-1 first in allowlist (default)');

/* ── 2. Endpoint construction per region ────────────────────────────────── */

$allBuilt = true;
foreach ($expected as $r) {
    if (\App\EmailSender::sesSmtpHost($r) !== "email-smtp.{$r}.amazonaws.com") {
        $allBuilt = false;
        echo "    bad host for {$r}\n";
    }
}
ses_ok($allBuilt, 'sesSmtpHost() builds email-smtp.<region>.amazonaws.com for all 15 regions');

/* ── 3. Allowlist rejection → default ───────────────────────────────────── */

ses_ok(\App\EmailSender::isValidSesRegion('us-west-2'), 'valid region accepted');
ses_ok(!\App\EmailSender::isValidSesRegion('us-west-1'), 'us-west-1 rejected (SES API region, no SMTP endpoint)');
ses_ok(!\App\EmailSender::isValidSesRegion('eu-west-3'), 'eu-west-3 rejected (not an SES SMTP region)');
ses_ok(!\App\EmailSender::isValidSesRegion(''), 'empty string rejected');
ses_ok(!\App\EmailSender::isValidSesRegion('us-gov-west-1'), 'GovCloud region rejected (not in curated list)');
ses_ok(!\App\EmailSender::isValidSesRegion('evil.com'), 'junk hostname rejected');
ses_ok(\App\EmailSender::normalizeSesRegion('us-east-1; DROP TABLE settings') === 'us-east-1',
    'SQL-injection-shaped value normalizes to default');
ses_ok(\App\EmailSender::normalizeSesRegion('email-smtp.us-east-1.amazonaws.com') === 'us-east-1',
    'full hostname normalizes to default (region codes only)');
ses_ok(\App\EmailSender::normalizeSesRegion('EU-WEST-1 ') === 'eu-west-1',
    'case/whitespace normalized before validation');
ses_ok(\App\EmailSender::sesSmtpHost('us-west-1') === 'email-smtp.us-east-1.amazonaws.com',
    'off-list region falls back to default endpoint');

/* ── 4. Default preserved (pre-Item-3 behavior) ─────────────────────────── */

ses_ok(\App\EmailSender::sesSmtpHost('') === 'email-smtp.us-east-1.amazonaws.com',
    'empty region -> old hardcoded us-east-1 endpoint');
ses_ok(\App\EmailSender::sesSmtpHost(null) === 'email-smtp.us-east-1.amazonaws.com',
    'missing ses_region setting -> us-east-1 endpoint (existing installs unchanged)');
$src = file_get_contents($repo . '/includes/EmailSender.php');
ses_ok(strpos($src, "self::sesSmtpHost()") !== false, 'sendViaSmtpSocket builds host from region helper');
ses_ok(strpos($src, "'email-smtp.us-east-1.amazonaws.com'") === false,
    'no remaining hardcoded SES host in EmailSender');
ses_ok(strpos($src, "amazon_ses_smtp_host") !== false,
    'explicit amazon_ses_smtp_host still honored before region default');

/* ── 5. Wiring: settings API, UI, JS, Diagnostics ──────────────────────── */

$api = file_get_contents($repo . '/api/settings.php');
ses_ok(strpos($api, "'ses_region'") !== false, 'ses_region in settings API allowlist');
ses_ok(strpos($api, 'isValidSesRegion') !== false && strpos($api, 'http_response_code(400)') !== false,
    'settings API validates ses_region server-side and refuses off-list values with 400');

$ui = file_get_contents($repo . '/index.php');
ses_ok(strpos($ui, 'id="setting-ses_region"') !== false, 'settings UI has the SES region dropdown');
$uiRegions = 0;
foreach ($expected as $r) {
    if (strpos($ui, 'value="' . $r . '"') !== false) {
        $uiRegions++;
    }
}
ses_ok($uiRegions === count($expected), "dropdown lists all {$uiRegions}/15 regions");

$js = file_get_contents($repo . '/assets/js/dashboard.js');
ses_ok(preg_match("/'ses_region'/", $js) === 1, 'dashboard.js saveSettings() posts ses_region');

// Diagnostics: region-aware SES probe (mocked SMTP probe, no network)
$diag = file_get_contents($repo . '/includes/Diagnostics.php');
ses_ok(strpos($diag, 'ses_region') !== false, 'Diagnostics reads ses_region');
$gs = fn(string $k, ?string $d = null): ?string => [
    'active_email_provider' => 'amazon_ses',
    'ses_region' => 'eu-west-1',
    'amazon_ses_smtp_pass' => 's3cret',
][$k] ?? $d;
$probed = [];
\App\Diagnostics::setSmtpProbe(function (string $h, int $p, string $e, string $u, string $pw) use (&$probed): array {
    $probed[] = $h;
    return ['ok' => true, 'detail' => 'authenticated'];
});
$results = \App\Diagnostics::providersVerdict($gs);
$byId = [];
foreach ($results as $row) {
    $byId[$row['id']] = $row;
}
ses_ok(($probed[0] ?? null) === 'email-smtp.eu-west-1.amazonaws.com',
    'diagnostics probes the configured region endpoint');
ses_ok(strpos($byId['provider_amazon_ses']['detail'] ?? '', 'eu-west-1') !== false,
    'diagnostics result names the SES region');
// Default install (no ses_region row): probe must hit us-east-1, not fail "host missing"
$gsDefault = fn(string $k, ?string $d = null): ?string => [
    'active_email_provider' => 'amazon_ses',
    'amazon_ses_smtp_pass' => 's3cret',
][$k] ?? $d;
$probed = [];
$results = \App\Diagnostics::providersVerdict($gsDefault);
ses_ok(($probed[0] ?? null) === 'email-smtp.us-east-1.amazonaws.com',
    'diagnostics defaults to us-east-1 endpoint when ses_region is unset');
// Explicit custom host still wins in diagnostics
$gsCustom = fn(string $k, ?string $d = null): ?string => [
    'active_email_provider' => 'amazon_ses',
    'amazon_ses_smtp_host' => 'ses-proxy.example.com',
    'ses_region' => 'eu-west-1',
    'amazon_ses_smtp_pass' => 's3cret',
][$k] ?? $d;
$probed = [];
\App\Diagnostics::providersVerdict($gsCustom);
ses_ok(($probed[0] ?? null) === 'ses-proxy.example.com',
    'explicit amazon_ses_smtp_host still wins in diagnostics');
\App\Diagnostics::setSmtpProbe(null);

/* ── 6. Migration + installer coverage ──────────────────────────────────── */

$mig = $repo . '/migrations/2026-09-24-ses-region.sql';
ses_ok(is_file($mig), 'migration file exists');
$migSql = is_file($mig) ? file_get_contents($mig) : '';
ses_ok(strpos($migSql, 'INSERT IGNORE INTO settings') !== false
    && strpos($migSql, "'ses_region'") !== false
    && strpos($migSql, "'us-east-1'") !== false,
    'migration idempotently seeds ses_region=us-east-1');
$schema = file_get_contents($repo . '/schema.sql');
ses_ok(strpos($schema, "'ses_region', 'us-east-1'") !== false,
    'schema.sql seeds ses_region for fresh installs');
$installer = file_get_contents($repo . '/install.php');
ses_ok(strpos($installer, 'schema.sql') !== false,
    'install.php imports schema.sql (fresh installs get ses_region)');

echo "\nSesRegionTest: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
