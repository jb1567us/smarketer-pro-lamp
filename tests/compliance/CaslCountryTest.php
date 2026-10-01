<?php
/**
 * CASL country gate tests (compliance item 8).
 *
 * Auditable recipient-country handling: the gate keys off
 * leads.country_code (ISO-3166-1 alpha-2, NULL = unknown) instead of the
 * old .ca TLD regex, and every allow/block decision is audited to
 * casl_decisions.
 *
 * Usage: php tests/compliance/CaslCountryTest.php
 *
 * The unit sections run with NO database — they exercise the pure
 * \App\Compliance::evaluateCaslGateWithSettings() decision matrix plus
 * normalizeCountryCode() and the deprecated isCanadianAddress() BC shim.
 *
 * The DB integration section is clearly marked and SKIPPED unless
 * CASL_DB_INTEGRATION=1 is set. The coordinator's integration run should
 * export DB_* env vars pointing at a scratch database with the item-8 DDL
 * applied (leads.country_code + casl_decisions) and then run:
 *   CASL_DB_INTEGRATION=1 php tests/compliance/CaslCountryTest.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

$passed = 0;
$failed = 0;
$skipped = 0;

function casl_ok(bool $cond, string $name): void
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

/** Decision via the pure function — no settings, no DB. */
function casl_decide(array $lead, bool $gateEnabled, string $unknownMode): array
{
    return \App\Compliance::evaluateCaslGateWithSettings($lead, 'probe@example.com', $gateEnabled, $unknownMode);
}

// ── normalizeCountryCode (no DB) ──────────────────────────────────────────
echo "normalizeCountryCode:\n";
casl_ok(\App\Compliance::normalizeCountryCode('CA') === 'CA', "'CA' stays 'CA'");
casl_ok(\App\Compliance::normalizeCountryCode('ca') === 'CA', "'ca' uppercases to 'CA'");
casl_ok(\App\Compliance::normalizeCountryCode(' us ') === 'US', "whitespace trimmed, uppercased");
casl_ok(\App\Compliance::normalizeCountryCode('CAN') === null, "'CAN' (3 chars) → null");
casl_ok(\App\Compliance::normalizeCountryCode('C') === null, "single char → null");
casl_ok(\App\Compliance::normalizeCountryCode('C1') === null, "alphanumeric → null");
casl_ok(\App\Compliance::normalizeCountryCode('') === null, "empty string → null");
casl_ok(\App\Compliance::normalizeCountryCode(null) === null, "null → null");
casl_ok(\App\Compliance::normalizeCountryCode(42) === null, "non-string → null");

// ── Decision matrix (no DB) ──────────────────────────────────────────────
echo "decision matrix:\n";

// CA + express → allow
$r = casl_decide(['country_code' => 'CA', 'consent_status' => 'express'], true, 'block');
casl_ok($r['decision'] === 'allow' && $r['rule'] === 'express_consent_allow' && $r['message'] === '',
    'CA + express consent → allow (express_consent_allow)');

// CA + implied → block
$r = casl_decide(['country_code' => 'CA', 'consent_status' => 'implied'], true, 'block');
casl_ok($r['decision'] === 'block' && $r['rule'] === 'ca_no_express_consent' && $r['message'] !== '',
    'CA + implied consent → block (ca_no_express_consent)');

// CA + unknown → block
$r = casl_decide(['country_code' => 'CA', 'consent_status' => 'unknown'], true, 'block');
casl_ok($r['decision'] === 'block' && $r['rule'] === 'ca_no_express_consent',
    'CA + unknown consent → block (ca_no_express_consent)');

// CA + missing consent key → treated as unknown → block
$r = casl_decide(['country_code' => 'CA'], true, 'block');
casl_ok($r['decision'] === 'block' && $r['rule'] === 'ca_no_express_consent',
    'CA + missing consent_status → block');

// lowercase 'ca' normalizes → block for implied
$r = casl_decide(['country_code' => 'ca', 'consent_status' => 'implied'], true, 'block');
casl_ok($r['decision'] === 'block' && $r['rule'] === 'ca_no_express_consent',
    "lowercase 'ca' normalizes to CA → block");

// unknown country + express → allow (existing opted-in lists keep working)
$r = casl_decide(['country_code' => null, 'consent_status' => 'express'], true, 'block');
casl_ok($r['decision'] === 'allow' && $r['rule'] === 'express_consent_allow',
    'unknown country + express consent → allow');

// unknown country + implied → block by default (CASL-safe default)
$r = casl_decide(['country_code' => null, 'consent_status' => 'implied'], true, 'block');
casl_ok($r['decision'] === 'block' && $r['rule'] === 'unknown_country_no_consent' && $r['message'] !== '',
    'unknown country + implied consent → block by default (unknown_country_no_consent)');

// unknown country + unknown consent → block by default
$r = casl_decide([], true, 'block');
casl_ok($r['decision'] === 'block' && $r['rule'] === 'unknown_country_no_consent',
    'unknown country + unknown consent → block by default');

// unknown country + implied → allow when admin override set
$r = casl_decide(['country_code' => null, 'consent_status' => 'implied'], true, 'allow');
casl_ok($r['decision'] === 'allow' && $r['rule'] === 'unknown_country_override_allow',
    "unknown country + implied consent + override 'allow' → allow (unknown_country_override_allow)");

// unknown country + unknown consent → allow when admin override set
$r = casl_decide([], true, 'ALLOW');
casl_ok($r['decision'] === 'allow' && $r['rule'] === 'unknown_country_override_allow',
    "unknown-mode normalized case-insensitively ('ALLOW' works)");

// US + implied → allow (CASL gate does not apply outside Canada)
$r = casl_decide(['country_code' => 'US', 'consent_status' => 'implied'], true, 'block');
casl_ok($r['decision'] === 'allow' && $r['rule'] === 'non_ca_country_allow',
    'US + implied consent → allow (non_ca_country_allow)');

// US + unknown → allow
$r = casl_decide(['country_code' => 'US', 'consent_status' => 'unknown'], true, 'block');
casl_ok($r['decision'] === 'allow' && $r['rule'] === 'non_ca_country_allow',
    'US + unknown consent → allow');

// invalid country code ('CAN') is treated as unknown → block default
$r = casl_decide(['country_code' => 'CAN', 'consent_status' => 'implied'], true, 'block');
casl_ok($r['decision'] === 'block' && $r['rule'] === 'unknown_country_no_consent',
    "invalid country 'CAN' treated as unknown → block");

// gate disabled → allow, logged as gate_disabled
$r = casl_decide(['country_code' => 'CA', 'consent_status' => 'implied'], false, 'block');
casl_ok($r['decision'] === 'allow' && $r['rule'] === 'gate_disabled' && $r['message'] === '',
    'master toggle off → allow (gate_disabled) even for CA + implied');

// block messages are buyer-actionable (mention System Settings)
$r = casl_decide(['country_code' => 'CA', 'consent_status' => 'implied'], true, 'block');
casl_ok(stripos($r['message'], 'System Settings') !== false, 'CA block message is buyer-actionable');
$r = casl_decide([], true, 'block');
casl_ok(stripos($r['message'], 'System Settings') !== false, 'unknown-country block message is buyer-actionable');

// ── Deprecated heuristic BC shim (no DB) ─────────────────────────────────
echo "isCanadianAddress (deprecated, BC):\n";
casl_ok(\App\Compliance::isCanadianAddress('buyer@shop.ca') === true, '.ca address still detected (BC)');
casl_ok(\App\Compliance::isCanadianAddress('buyer@shop.com') === false, 'non-.ca address not detected');
casl_ok(\App\Compliance::isCanadianAddress('  buyer@shop.CA ') === true, 'case-insensitive, trimmed');

// ══════════════════════════════════════════════════════════════════════════
// DB INTEGRATION — everything below touches MariaDB.
// Skipped unless CASL_DB_INTEGRATION=1. The coordinator's integration run
// must export DB_* env vars pointing at a SCRATCH database with the item-8
// DDL applied (leads.country_code CHAR(2) NULL + casl_decisions table), then:
//   CASL_DB_INTEGRATION=1 php tests/compliance/CaslCountryTest.php
// ══════════════════════════════════════════════════════════════════════════
echo "db integration:\n";
if (getenv('CASL_DB_INTEGRATION') !== '1') {
    $skipped++;
    echo "  SKIP: set CASL_DB_INTEGRATION=1 with DB_* env vars pointed at a scratch\n";
    echo "        DB carrying the item-8 DDL to run the audit + send-path checks.\n";
} else {
    $pdo = \App\Database::getConnection();

    // Audit table (idempotent — same shape as the reported item-8 DDL).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS casl_decisions (" .
        "id INT AUTO_INCREMENT PRIMARY KEY, " .
        "email VARCHAR(255) NOT NULL, " .
        "country_code CHAR(2) NULL, " .
        "decision ENUM('allow','block') NOT NULL, " .
        "rule VARCHAR(100) NOT NULL, " .
        "created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, " .
        "INDEX idx_casl_email (email), " .
        "INDEX idx_casl_created (created_at)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $testEmails = [
        'casltest-ca-block@example.com',
        'casltest-ca-allow@example.com',
        'casltest-unknown-block@example.com',
        'casltest-unknown-allow@example.com',
        'casltest-unknown-express@example.com',
        'casltest-norow@example.com',
    ];

    // Remember prior settings so we can restore them afterwards.
    $prior = [];
    foreach (['company_legal_name', 'physical_address', 'compliance_casl_ca_block', 'compliance_casl_unknown_country'] as $k) {
        $prior[$k] = \App\Database::getSetting($k);
    }
    $setSetting = function (string $k, string $v) use ($pdo): void {
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) " .
            "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([$k, $v]);
    };
    $setSetting('company_legal_name', 'CASL Test LLC');
    $setSetting('physical_address', '1 Test Way, Austin, TX 78701');
    $setSetting('compliance_casl_ca_block', '1');
    $setSetting('compliance_casl_unknown_country', 'block');

    $mkLead = function (string $email, ?string $cc, string $consent) use ($pdo): void {
        $stmt = $pdo->prepare(
            "INSERT INTO leads (company_name, email, country_code, consent_status) VALUES (?, ?, ?, ?) " .
            "ON DUPLICATE KEY UPDATE country_code = VALUES(country_code), consent_status = VALUES(consent_status)"
        );
        $stmt->execute(['CASL Test Co', $email, $cc, $consent]);
    };
    $mkLead('casltest-ca-block@example.com', 'CA', 'implied');
    $mkLead('casltest-ca-allow@example.com', 'CA', 'express');
    $mkLead('casltest-unknown-block@example.com', null, 'implied');
    $mkLead('casltest-unknown-allow@example.com', null, 'implied');
    $mkLead('casltest-unknown-express@example.com', null, 'express');

    $lastAudit = function (string $email) use ($pdo): ?array {
        $stmt = $pdo->prepare("SELECT decision, rule, country_code FROM casl_decisions WHERE email = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([strtolower($email)]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    };
    $expectBlock = function (callable $fn, string $needle, string $name): void {
        try {
            $fn();
            casl_ok(false, $name . ' (no exception thrown)');
        } catch (\Throwable $e) {
            casl_ok(stripos($e->getMessage(), $needle) !== false, $name);
        }
    };

    try {
        // 1. CA + implied, lead passed explicitly → block + audited
        $lead = ['country_code' => 'CA', 'consent_status' => 'implied'];
        $expectBlock(
            fn() => \App\Compliance::requireCompliantSend('casltest-ca-block@example.com', $lead),
            'CASL', 'requireCompliantSend blocks CA + implied'
        );
        $a = $lastAudit('casltest-ca-block@example.com');
        casl_ok($a !== null && $a['decision'] === 'block' && $a['rule'] === 'ca_no_express_consent' && $a['country_code'] === 'CA',
            'block audited to casl_decisions (ca_no_express_consent, CA)');

        // 2. CA + express → allow + audited
        casl_ok((function () {
            \App\Compliance::requireCompliantSend('casltest-ca-allow@example.com', ['country_code' => 'CA', 'consent_status' => 'express']);
            return true;
        })(), 'requireCompliantSend allows CA + express');
        $a = $lastAudit('casltest-ca-allow@example.com');
        casl_ok($a !== null && $a['decision'] === 'allow' && $a['rule'] === 'express_consent_allow',
            'allow audited to casl_decisions (express_consent_allow)');

        // 3. Unknown country + implied → block by default + audited
        $expectBlock(
            fn() => \App\Compliance::requireCompliantSend('casltest-unknown-block@example.com', ['country_code' => null, 'consent_status' => 'implied']),
            'CASL', 'requireCompliantSend blocks unknown-country + implied by default'
        );
        $a = $lastAudit('casltest-unknown-block@example.com');
        casl_ok($a !== null && $a['decision'] === 'block' && $a['rule'] === 'unknown_country_no_consent' && $a['country_code'] === null,
            'block audited (unknown_country_no_consent, NULL country)');

        // 4. Unknown country + implied + admin override 'allow' → allow + audited
        $setSetting('compliance_casl_unknown_country', 'allow');
        casl_ok((function () {
            \App\Compliance::requireCompliantSend('casltest-unknown-allow@example.com', ['country_code' => null, 'consent_status' => 'implied']);
            return true;
        })(), 'unknown-country + implied + override allow → passes');
        $a = $lastAudit('casltest-unknown-allow@example.com');
        casl_ok($a !== null && $a['decision'] === 'allow' && $a['rule'] === 'unknown_country_override_allow',
            'override allow audited (unknown_country_override_allow)');
        $setSetting('compliance_casl_unknown_country', 'block');

        // 5. Unknown country + express → allow
        casl_ok((function () {
            \App\Compliance::requireCompliantSend('casltest-unknown-express@example.com', ['country_code' => null, 'consent_status' => 'express']);
            return true;
        })(), 'unknown-country + express → allow');

        // 6. Lead-row SELECT path: no lead array passed → gate fetches
        //    consent_status + country_code itself.
        $expectBlock(
            fn() => \App\Compliance::requireCompliantSend('casltest-ca-block@example.com'),
            'CASL', 'lead-row SELECT path blocks CA + implied'
        );
        $a = $lastAudit('casltest-ca-block@example.com');
        casl_ok($a !== null && $a['decision'] === 'block' && $a['rule'] === 'ca_no_express_consent',
            'SELECT path decision audited');

        // 7. No lead row at all → unknown/unknown → block by default
        $expectBlock(
            fn() => \App\Compliance::requireCompliantSend('casltest-norow@example.com'),
            'CASL', 'missing lead row → unknown country/consent → block'
        );

        // 8. Master toggle off → allow, logged as gate_disabled
        $setSetting('compliance_casl_ca_block', '0');
        casl_ok((function () {
            \App\Compliance::requireCompliantSend('casltest-ca-block@example.com', ['country_code' => 'CA', 'consent_status' => 'implied']);
            return true;
        })(), 'master toggle off → CA + implied passes');
        $a = $lastAudit('casltest-ca-block@example.com');
        casl_ok($a !== null && $a['decision'] === 'allow' && $a['rule'] === 'gate_disabled',
            'disabled gate audited (gate_disabled)');
        $setSetting('compliance_casl_ca_block', '1');

        // 9. Earlier gate (suppression) refusing → NO casl_decisions row.
        \App\Compliance::suppress('casltest-ca-block@example.com', 'unsubscribe', 'casl-test');
        $before = (int)$pdo->query("SELECT COUNT(*) FROM casl_decisions WHERE email = 'casltest-ca-block@example.com'")->fetchColumn();
        $expectBlock(
            fn() => \App\Compliance::requireCompliantSend('casltest-ca-block@example.com', ['country_code' => 'CA', 'consent_status' => 'express']),
            'suppression', 'suppressed address refused before CASL'
        );
        $after = (int)$pdo->query("SELECT COUNT(*) FROM casl_decisions WHERE email = 'casltest-ca-block@example.com'")->fetchColumn();
        casl_ok($after === $before, 'no CASL audit row when suppression gate refuses first');
    } finally {
        // Restore prior settings and remove test rows.
        foreach ($prior as $k => $v) {
            if ($v === null) {
                $pdo->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([$k]);
            } else {
                $setSetting($k, $v);
            }
        }
        $placeholders = implode(',', array_fill(0, count($testEmails), '?'));
        $pdo->prepare("DELETE FROM leads WHERE email IN ({$placeholders})")->execute($testEmails);
        $pdo->prepare("DELETE FROM suppression_list WHERE email IN ({$placeholders})")->execute($testEmails);
        $pdo->prepare("DELETE FROM casl_decisions WHERE email IN ({$placeholders})")->execute($testEmails);
    }
}

echo "\n{$passed} passed, {$failed} failed, {$skipped} skipped\n";
exit($failed > 0 ? 1 : 0);
