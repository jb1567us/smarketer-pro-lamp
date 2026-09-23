<?php
/**
 * GDPR item-5 test suite (export / erasure / objection / lawful basis).
 *
 * Usage: php tests/compliance/GdprTest.php
 *
 * Two clearly-marked sections:
 *
 *   SECTION A — pure unit, NO database. Token forgery and input validation
 *   paths never touch the DB, so these run anywhere PHP runs.
 *
 *   SECTION B — MariaDB integration. Spins up a scratch database
 *   (gdpr_test), applies the REAL schema.sql + the REAL compliance
 *   migration verbatim via the mysql CLI, then applies the item-5 GDPR DDL
 *   inline (which also validates the DDL in the item-5 report), seeds
 *   fixture rows, and runs the assertions. Afterwards it drops the scratch
 *   database and restores the repo tree exactly as it was (config/db.php is
 *   restored or deleted).
 *
 * The coordinator runs Section B at integration time; Section A can run
 * standalone by defining GDPR_SKIP_DB=1 in the environment.
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

$failures = 0;
$passed = 0;
function ok(bool $cond, string $name): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "  PASS: {$name}\n"; }
    else { $failures++; echo "  FAIL: {$name}\n"; }
}
function expectThrow(callable $fn, string $needle, string $name): void
{
    try {
        $fn();
        ok(false, $name . ' (no exception thrown)');
    } catch (\Throwable $e) {
        ok(stripos($e->getMessage(), $needle) !== false, $name . ' [got: ' . substr($e->getMessage(), 0, 90) . ']');
    }
}

// ------------------------------------------------------------------
// SECTION A — pure unit, no database.
// ------------------------------------------------------------------
echo "A. no-DB unit checks:\n";

// Forged tokens never reach the database: format check fails first.
ok(\App\Gdpr::verifyObjectionToken('garbage') === null, 'garbage objection token rejected (no DB)');
ok(\App\Gdpr::object('subject@example.com', 'garbage') === false, 'object() with forged token returns false (no DB)');
ok(\App\Gdpr::object('subject@example.com', '') === false, 'object() with empty token returns false (no DB)');

// Input validation happens before any DB access.
expectThrow(fn() => \App\Gdpr::export('not-an-email'), 'Invalid email', 'export() rejects invalid email (no DB)');
expectThrow(fn() => \App\Gdpr::erase('not-an-email'), 'Invalid email', 'erase() rejects invalid email (no DB)');
expectThrow(fn() => \App\Gdpr::eraseReport('not-an-email'), 'Invalid email', 'eraseReport() rejects invalid email (no DB)');

// Token construction parity: objection tokens ARE unsubscribe tokens by design.
ok(method_exists(\App\Gdpr::class, 'objectionToken'), 'Gdpr::objectionToken exists');
ok(method_exists(\App\Gdpr::class, 'verifyObjectionToken'), 'Gdpr::verifyObjectionToken exists');

// Lawful-basis documentation constant.
ok(in_array('legitimate_interest', \App\Gdpr::LAWFUL_BASES, true), 'LAWFUL_BASES documents legitimate_interest');

if (getenv('GDPR_SKIP_DB') === '1') {
    echo "\n{$passed} passed, {$failures} failed (DB section skipped)\n";
    exit($failures > 0 ? 1 : 0);
}

// ------------------------------------------------------------------
// SECTION B — MariaDB integration (coordinator runs this at integration).
// Requires: local MariaDB, mysql CLI, root unix-socket auth.
// ------------------------------------------------------------------
echo "B. MariaDB integration:\n";

$configDir = $repo . '/config';
$configFile = $configDir . '/db.php';
$hadConfig = is_file($configFile);
$backup = $hadConfig ? file_get_contents($configFile) : null;

try {
    $dbUser = 'gdpr_test';
    $dbPass = 'gdpr_test_pw_9f2';
    $sh = function (string $cmd): void {
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) { throw new RuntimeException("shell failed: {$cmd}\n" . implode("\n", $out)); }
    };

    $sh("mysql -u root -e \"DROP DATABASE IF EXISTS gdpr_test; CREATE DATABASE gdpr_test CHARACTER SET utf8mb4;\"");
    $sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON gdpr_test.* TO '{$dbUser}'@'%'; FLUSH PRIVILEGES;\"");

    if (!is_dir($configDir)) { mkdir($configDir, 0755, true); }
    file_put_contents($configFile, "<?php\nreturn ['host' => '127.0.0.1', 'name' => 'gdpr_test', 'user' => '{$dbUser}', 'pass' => '{$dbPass}'];\n");

    $pdo = \App\Database::getConnection();

    // Apply the REAL schema.sql verbatim via the mysql CLI (no DELIMITER use inside).
    $sh("mysql -u {$dbUser} -p{$dbPass} gdpr_test < " . escapeshellarg($repo . '/schema.sql'));
    ok(true, 'real schema.sql applies cleanly');
    // Apply the REAL compliance migration verbatim (uses DELIMITER; PDO cannot parse it).
    $sh("mysql -u {$dbUser} -p{$dbPass} gdpr_test < " . escapeshellarg($repo . '/migrations/2026-09-23-compliance.sql'));
    ok(true, 'real compliance migration applies cleanly');

    // Apply the REAL gaps migration verbatim — it now owns the item-5 GDPR
    // DDL (email_hash, nullable suppression email, leads.lawful_basis), so
    // the old inline DDL is retired in favour of the canonical file. Twice,
    // to prove idempotency.
    $gapsMig = escapeshellarg($repo . '/migrations/2026-09-23-compliance-gaps.sql');
    foreach ([1, 2] as $run) {
        $sh("mysql -u {$dbUser} -p{$dbPass} gdpr_test < {$gapsMig}");
        ok(true, "gaps migration applies cleanly (run {$run})");
    }
    ok(\App\Gdpr::columnExists($pdo, 'leads', 'lawful_basis'), 'leads.lawful_basis exists after gaps migration');
    ok(\App\Gdpr::hashColumnExists($pdo), 'suppression_list.email_hash exists after gaps migration');

    $email = 'subject@example.com';

    // --- Seed ---------------------------------------------------------
    $pdo->exec(
        "INSERT INTO leads (company_name, contact_name, email, website, source, consent_status, lawful_basis, email_source, source_url) " .
        "VALUES ('Acme Corp', 'Jane Doe', '{$email}', 'https://acme.example', 'CSV Import', 'express', 'consent', 'harvester', 'https://acme.example/team')"
    );
    $pdo->exec(
        "INSERT INTO email_logs (lead_email, provider_id, provider_msg_id, status, metadata_json, timestamp) VALUES " .
        "('{$email}', 'resend', 'msg_1', 'sent', '{\"to\":\"{$email}\"}', 1727000000), " .
        "('{$email}', 'resend', 'msg_2', 'failed', '{}', 1727000100)"
    );
    $pdo->exec("INSERT INTO suppression_list (email, reason, source) VALUES ('other@example.com', 'bounce', 'ses')");

    // --- Export shape ---------------------------------------------------
    echo "export:\n";
    $export = \App\Gdpr::export($email, $pdo);
    foreach (['email', 'exported_at', 'leads', 'email_logs', 'suppression_list'] as $section) {
        ok(array_key_exists($section, $export), "export has section '{$section}'");
    }
    ok($export['email'] === $email, 'export is keyed to the normalized address');
    ok(count($export['leads']) === 1 && $export['leads'][0]['lawful_basis'] === 'consent', 'export lead row carries lawful_basis + provenance');
    ok($export['leads'][0]['email_source'] === 'harvester' && $export['leads'][0]['source_url'] === 'https://acme.example/team', 'export lead row carries email_source/source_url');
    ok(count($export['email_logs']) === 2, 'export includes both email_logs rows');
    ok(count($export['suppression_list']) === 0, 'export suppression empty before any opt-out');
    ok(array_key_exists('webhook_events', $export) && $export['webhook_events'] === [], 'webhook_events present but empty in export (table created by gaps migration)');

    $empty = \App\Gdpr::export('nobody-here@example.com', $pdo);
    ok($empty['leads'] === [] && $empty['email_logs'] === [] && $empty['suppression_list'] === [], 'export of unknown address returns empty sections, no error');

    // --- Objection: forged token -----------------------------------------
    echo "objection:\n";
    $tok = \App\Gdpr::objectionToken($email);
    ok(\App\Gdpr::verifyObjectionToken($tok) === $email, 'objection token round-trips');
    ok(\App\Gdpr::verifyObjectionToken($tok . 'x') === null, 'tampered objection token rejected');
    ok(\App\Gdpr::object($email, 'forged.token.here') === false, 'object() with forged token returns false');
    ok(count($pdo->query("SELECT * FROM suppression_list")->fetchAll()) === 1, 'forged objection leaves suppression_list untouched');
    ok((bool)$pdo->query("SELECT 1 FROM leads WHERE email = '{$email}' LIMIT 1")->fetch(), 'forged objection leaves lead row untouched');

    // --- Objection: valid token -------------------------------------------
    ok(\App\Gdpr::object($email, $tok) === true, 'object() with valid token returns true');
    $supRows = $pdo->query("SELECT * FROM suppression_list")->fetchAll();
    ok(count($supRows) === 2, 'exactly two suppression rows remain (other@example.com + hash-only objection row)');
    $hashRow = null;
    foreach ($supRows as $r) {
        if ($r['email'] === null || $r['email'] === '') { $hashRow = $r; }
    }
    ok($hashRow !== null, 'objection retention row carries no plaintext email');
    ok($hashRow['reason'] === 'objection', 'objection retention row has reason=objection');
    $expectedHash = \App\Gdpr::emailHash($email);
    ok($hashRow['email_hash'] === $expectedHash && strlen((string)$expectedHash) === 64, 'objection retention row stores the keyed email hash');
    ok(!(bool)$pdo->query("SELECT 1 FROM leads WHERE email = '{$email}' LIMIT 1")->fetch(), 'objection erased the lead row');
    ok(!(bool)$pdo->query("SELECT 1 FROM email_logs WHERE lead_email = '{$email}' LIMIT 1")->fetch(), 'objection erased the email_logs rows');
    // No plaintext PII for the address remains anywhere.
    $blob = '';
    foreach (['leads', 'email_logs', 'suppression_list'] as $t) {
        foreach ($pdo->query("SELECT * FROM {$t}")->fetchAll() as $r) {
            $blob .= '|' . implode('|', array_map(fn($v) => (string)$v, $r));
        }
    }
    ok(stripos($blob, $email) === false, 'no plaintext trace of the address remains in any table');
    ok(\App\Compliance::isSuppressed($email) === true, 'isSuppressed() still refuses the address after erasure (hash leg)');
    ok(\App\Compliance::isSuppressed('other@example.com') === true, 'plaintext suppression leg still works');

    // --- Erasure idempotency -----------------------------------------------
    echo "erasure:\n";
    $rep = \App\Gdpr::eraseReport($email, $pdo, 'erasure');
    $supRows2 = $pdo->query("SELECT * FROM suppression_list WHERE email_hash = '{$expectedHash}'")->fetchAll();
    ok(count($supRows2) === 1, 're-erasure keeps exactly one hash row (upsert, not duplicate)');
    ok($rep['deleted']['leads'] === 0 && $rep['deleted']['email_logs'] === 0, 'erase summary reports zero deletions on second run');
    ok(isset($rep['retained']['suppression_list']['email_hash']), 'erase summary describes the retained hash row');

    // --- Fresh erasure path (erase without prior objection) -----------------
    $email2 = 'erase-me@example.com';
    $pdo->exec("INSERT INTO leads (company_name, email, lawful_basis) VALUES ('Beta LLC', '{$email2}', 'legitimate_interest')");
    $pdo->exec("INSERT INTO email_logs (lead_email, provider_id, timestamp) VALUES ('{$email2}', 'smtp', 1727000200)");
    $pdo->exec("INSERT INTO suppression_list (email, reason) VALUES ('{$email2}', 'unsubscribe')");
    \App\Gdpr::erase($email2, $pdo);
    ok(!(bool)$pdo->query("SELECT 1 FROM leads WHERE email = '{$email2}' LIMIT 1")->fetch(), 'erase() deletes the lead row');
    ok(!(bool)$pdo->query("SELECT 1 FROM suppression_list WHERE email = '{$email2}' LIMIT 1")->fetch(), 'erase() deletes the plaintext suppression row');
    $h2 = $pdo->query("SELECT email, email_hash, reason FROM suppression_list WHERE email_hash = '" . \App\Gdpr::emailHash($email2) . "'")->fetchAll();
    ok(count($h2) === 1 && ($h2[0]['email'] === null || $h2[0]['email'] === '') && $h2[0]['reason'] === 'erasure', 'erase() retains one hash-only row with reason=erasure');
    ok(\App\Compliance::isSuppressed($email2) === true, 'erased address stays suppressed');

    // --- webhook_events graceful handling ------------------------------------
    echo "webhook_events:\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_events (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255), event_type VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $email3 = 'hook@example.com';
    $pdo->exec("INSERT INTO leads (company_name, email) VALUES ('Gamma Inc', '{$email3}')");
    $pdo->exec("INSERT INTO webhook_events (provider, email, event_type) VALUES ('test', '{$email3}', 'bounce')");
    $exp3 = \App\Gdpr::export($email3, $pdo);
    ok(array_key_exists('webhook_events', $exp3) && count($exp3['webhook_events']) === 1, 'export picks up webhook_events once the table exists');
    \App\Gdpr::erase($email3, $pdo);
    ok(!(bool)$pdo->query("SELECT 1 FROM webhook_events WHERE email = '{$email3}' LIMIT 1")->fetch(), 'erase() deletes webhook_events rows');

    // --- Plaintext suppress() dedup still works with NULLable email -----------
    echo "suppression dedup:\n";
    \App\Compliance::suppress('dup@example.com', 'unsubscribe', 'test');
    \App\Compliance::suppress('dup@example.com', 'complaint', 'test');
    $dups = $pdo->query("SELECT reason FROM suppression_list WHERE email = 'dup@example.com'")->fetchAll();
    ok(count($dups) === 1 && $dups[0]['reason'] === 'complaint', 'ON DUPLICATE KEY UPDATE dedup survives the NULLable-email key redesign');

    echo "\n{$passed} passed, {$failures} failed\n";
    if ($failures > 0) { exit(1); }
} finally {
    // Restore the repo tree exactly.
    if ($hadConfig && $backup !== null) {
        file_put_contents($configFile, $backup);
    } elseif (is_file($configFile)) {
        unlink($configFile);
        @rmdir($configDir);
    }
    try {
        exec("mysql -u root -e \"DROP DATABASE IF EXISTS gdpr_test; DROP USER IF EXISTS 'gdpr_test'@'%';\" 2>&1");
    } catch (\Throwable $e) { /* best effort */ }
}
