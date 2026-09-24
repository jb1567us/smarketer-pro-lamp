<?php
/**
 * Compliance suite runner.
 *
 * Usage: php tests/compliance/run_compliance_tests.php
 *
 * Spins up a scratch MariaDB database (compliance_test), applies the real
 * schema.sql + the compliance migration, points a TEMPORARY config/db.php at
 * it, runs the assertions, then removes the temp config. The repo tree is
 * left exactly as it was (config/db.php is restored or deleted).
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
$configDir = $repo . '/config';
$configFile = $configDir . '/db.php';
$hadConfig = is_file($configFile);
$backup = null;
if ($hadConfig) {
    $backup = file_get_contents($configFile);
}

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

/** ITEM A: fake verification provider (scripted INVALID verdict, no network). */
class ItemABlockedFakeProvider implements \App\Verification\EmailVerificationProvider
{
    public function name(): string { return 'itema-fake'; }
    public function verify(string $email): \App\Verification\EmailVerificationResult
    {
        return new \App\Verification\EmailVerificationResult(
            \App\Verification\EmailVerificationResult::INVALID,
            $this->name(),
            new \DateTimeImmutable()
        );
    }
}

try {
    // --- Scratch database -------------------------------------------------
    // Local MariaDB root is unix-socket auth: use the mysql CLI like the
    // queue stress suite does, then connect over TCP as a scoped test user.
    $dbUser = 'compliance_test';
    $dbPass = 'compliance_test_pw_7d1';
    $sh = function (string $cmd): void {
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) { throw new RuntimeException("shell failed: {$cmd}\n" . implode("\n", $out)); }
    };
    $sh("mysql -u root -e \"DROP DATABASE IF EXISTS compliance_test; CREATE DATABASE compliance_test CHARACTER SET utf8mb4;\"");
    $sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON compliance_test.* TO '{$dbUser}'@'%'; FLUSH PRIVILEGES;\"");

    if (!is_dir($configDir)) { mkdir($configDir, 0755, true); }
    file_put_contents($configFile, "<?php\nreturn ['host' => '127.0.0.1', 'name' => 'compliance_test', 'user' => '{$dbUser}', 'pass' => '{$dbPass}'];\n");

    require $repo . '/includes/autoload.php';
    $pdo = \App\Database::getConnection();

    // Minimal tables the compliance layer needs.
    $pdo->exec("CREATE TABLE settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE leads (id INT AUTO_INCREMENT PRIMARY KEY, company_name VARCHAR(255) NOT NULL, contact_name VARCHAR(255), email VARCHAR(255) UNIQUE NOT NULL, website VARCHAR(255), status ENUM('New','Enriched','Contacted','Qualified','Unqualified','Converted') DEFAULT 'New', lead_score INT DEFAULT 0, source VARCHAR(100), notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");

    // Apply the REAL migration files verbatim via the mysql CLI (it uses
    // DELIMITER, which PDO cannot parse). Twice, to prove idempotency.
    foreach (['2026-09-23-compliance.sql', '2026-09-23-compliance-gaps.sql', '2026-09-24-verification-ui.sql'] as $mig) {
        $migFile = escapeshellarg($repo . '/migrations/' . $mig);
        foreach ([1, 2] as $run) {
            $sh("mysql -u {$dbUser} -p{$dbPass} compliance_test < {$migFile}");
            ok(true, "{$mig} applies cleanly (run {$run})");
        }
    }
    // ITEM 1: verification defaults seed OFF.
    $vreq = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'verification_required'")->fetchColumn();
    ok($vreq === '0', 'verification migration seeds verification_required=0 (gate stays OFF)');
    $vkey = (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'verification_api_key'")->fetchColumn();
    ok($vkey === 1, 'verification migration seeds verification_api_key row');
    $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='compliance_test' AND TABLE_NAME='leads'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['consent_status','consent_proof','verification_status','verified_at','is_role_based','target_persona','email_source','source_url'] as $c) {
        ok(in_array($c, $cols, true), "leads has column {$c}");
    }
    ok((bool)$pdo->query("SHOW TABLES LIKE 'suppression_list'")->fetch(), "suppression_list table exists");

    // --- Token tests ------------------------------------------------------
    echo "tokens:\n";
    $email = 'buyer@example.com';
    $tok = \App\Compliance::unsubscribeToken($email);
    ok(\App\Compliance::verifyUnsubscribeToken($tok) === $email, 'token round-trips');
    ok(\App\Compliance::verifyUnsubscribeToken('garbage') === null, 'garbage token rejected');
    ok(\App\Compliance::verifyUnsubscribeToken($tok . 'x') === null, 'tampered signature rejected');
    $other = \App\Compliance::unsubscribeToken('someone@else.com');
    ok(\App\Compliance::verifyUnsubscribeToken($other) === 'someone@else.com', 'tokens are per-email');
    ok(\App\Compliance::verifyUnsubscribeToken(explode('.', $tok)[0] . '.' . explode('.', $other)[1]) === null, 'cross-email signature rejected');
    $s1 = \App\Compliance::appSecret();
    ok(strlen($s1) >= 64 && \App\Compliance::appSecret() === $s1, 'app secret stable and long enough');

    // --- Suppression tests ------------------------------------------------
    echo "suppression:\n";
    ok(\App\Compliance::isSuppressed('nobody@example.com') === false, 'unknown address not suppressed');
    \App\Compliance::suppress('optout@example.com', 'unsubscribe', 'test');
    ok(\App\Compliance::isSuppressed('optout@example.com') === true, 'suppressed address detected');
    ok(\App\Compliance::isSuppressed('OPTOUT@example.com') === true, 'suppression is case-insensitive');

    // --- requireCompliantSend ---------------------------------------------
    echo "send gate:\n";
    expectThrow(fn() => \App\Compliance::requireCompliantSend('fine@example.com'),
        'sender identity', 'refuses when identity unconfigured');
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('company_legal_name','Test Corp LLC'), ('physical_address','123 Main St, Austin, TX 78701'), ('app_base_url','https://example.com/app')");
    // Item 8: unknown country + non-express consent blocks by default, so give
    // the test address an explicit non-CA country before expecting a pass.
    $pdo->exec("INSERT INTO leads (company_name, email, country_code, consent_status) VALUES ('Fine Co','fine@example.com','US','unknown')");
    ok((function () { \App\Compliance::requireCompliantSend('fine@example.com'); return true; })(), 'passes with identity set (explicit country)');
    expectThrow(fn() => \App\Compliance::requireCompliantSend('optout@example.com'),
        'suppression list', 'refuses suppressed address');

    // CASL country gate (item 8): explicit ISO country, auditable decisions.
    // Unknown country + non-express consent -> BLOCK by default.
    $pdo->exec("INSERT INTO leads (company_name, email, consent_status) VALUES ('Mystery Co','mystery@example.com','unknown')");
    expectThrow(fn() => \App\Compliance::requireCompliantSend('mystery@example.com'),
        'country is unknown', 'refuses unknown-country address with unknown consent');
    // Express consent -> ALLOW even with unknown country.
    $pdo->exec("UPDATE leads SET consent_status='express' WHERE email='mystery@example.com'");
    ok((function () { \App\Compliance::requireCompliantSend('mystery@example.com'); return true; })(), 'unknown country passes with express consent');
    // Explicit CA + unknown consent -> BLOCK while the master toggle is on.
    $pdo->exec("INSERT INTO leads (company_name, email, country_code, consent_status) VALUES ('CA Co','lead@shop.ca','CA','unknown')");
    expectThrow(fn() => \App\Compliance::requireCompliantSend('lead@shop.ca'),
        'CASL', 'refuses CA address with unknown consent');
    $pdo->exec("UPDATE leads SET consent_status='express' WHERE email='lead@shop.ca'");
    ok((function () { \App\Compliance::requireCompliantSend('lead@shop.ca'); return true; })(), 'CA passes with express consent');
    // Master toggle off -> the whole CASL gate is disabled (backward
    // compatible: buyers who had it off see zero behavior change).
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('compliance_casl_ca_block','0') ON DUPLICATE KEY UPDATE setting_value='0'");
    $pdo->exec("UPDATE leads SET consent_status='unknown', country_code=NULL WHERE email='lead@shop.ca'");
    ok((function () { \App\Compliance::requireCompliantSend('lead@shop.ca'); return true; })(), 'master toggle off disables the gate entirely');
    // Toggle back on + unknown-country mode = allow -> passes via the
    // explicit override (admin accepts the legal risk).
    $pdo->exec("UPDATE settings SET setting_value='1' WHERE setting_key='compliance_casl_ca_block'");
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('compliance_casl_unknown_country','allow') ON DUPLICATE KEY UPDATE setting_value='allow'");
    ok((function () { \App\Compliance::requireCompliantSend('lead@shop.ca'); return true; })(), 'passes when unknown-country handling set to allow');
    $pdo->exec("DELETE FROM settings WHERE setting_key='compliance_casl_unknown_country'");

    // --- Footer -----------------------------------------------------------
    echo "footer:\n";
    [$text, $html] = \App\Compliance::footer('fine@example.com');
    ok(strpos($text, 'Test Corp LLC') !== false && strpos($text, '123 Main St') !== false, 'text footer has identity');
    ok(strpos($text, 'https://example.com/app/unsubscribe.php?token=') !== false, 'text footer has unsubscribe URL');
    ok(strpos($html, '<a href="https://example.com/app/unsubscribe.php?token=') !== false, 'html footer has unsubscribe link');
    $tokInFooter = \App\Compliance::unsubscribeToken('fine@example.com');
    ok(strpos(urldecode($text), $tokInFooter) !== false, 'footer token verifies');
    ok(\App\Compliance::verifyUnsubscribeToken($tokInFooter) === 'fine@example.com', 'footer token is valid');

    $htmlBody = \App\Compliance::appendFooter('<p>Hello</p>', 'fine@example.com');
    ok(strpos($htmlBody, '<a href=') !== false && strpos($htmlBody, '<p>Hello</p>') === 0, 'html body gets html footer');
    $textBody = \App\Compliance::appendFooter("Hello\n", 'fine@example.com');
    ok(strpos($textBody, 'unsubscribe.php?token=') !== false && strpos($textBody, '<a href') === false, 'text body gets text footer');

    $hdrs = \App\Compliance::listUnsubscribeHeaders('fine@example.com', 'news@example.com');
    ok(count($hdrs) === 2 && strpos($hdrs[0], 'List-Unsubscribe: <https://example.com/app/unsubscribe.php') === 0, 'List-Unsubscribe + One-Click headers present');
    $pdo->exec("DELETE FROM settings WHERE setting_key='app_base_url'");
    $hdrs2 = \App\Compliance::listUnsubscribeHeaders('fine@example.com', 'news@example.com');
    ok(strpos($hdrs2[0], 'mailto:news@example.com') !== false, 'mailto fallback when app URL unset');

    // --- EmailSender choke point ------------------------------------------
    echo "sender choke point:\n";
    expectThrow(fn() => \App\EmailSender::send('optout@example.com', 's', 'b', 'resend', 'k', 'news@example.com'),
        'suppression list', 'EmailSender::send refuses suppressed (no network touched)');
    $pdo->exec("DELETE FROM settings WHERE setting_key IN ('company_legal_name','physical_address')");
    expectThrow(fn() => \App\EmailSender::send('fine@example.com', 's', 'b', 'resend', 'k', 'news@example.com'),
        'sender identity', 'EmailSender::send refuses without identity');
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('company_legal_name','Test Corp LLC'), ('physical_address','123 Main St, Austin, TX 78701')");
    expectThrow(fn() => \App\EmailSender::send('pending_abc@placeholder.com', 's', 'b', 'resend', 'k', 'news@example.com'),
        'placeholder', 'placeholder guard still fires first');

    // --- ITEM A: blocked-send counters --------------------------------------
    // Every send refusal is counted against the owning campaign and the
    // count persists (a real column, not a log scrape).
    echo "blocked counts:\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS campaigns (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, is_active TINYINT(1) DEFAULT 1) ENGINE=InnoDB");
    // The migration must apply cleanly twice (idempotency requirement).
    foreach ([1, 2] as $run) {
        $sh("mysql -u {$dbUser} -p{$dbPass} compliance_test < " . escapeshellarg($repo . '/migrations/2026-09-24-blocked-counts.sql'));
        ok(true, "2026-09-24-blocked-counts.sql applies cleanly (run {$run})");
    }
    $bcCols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='compliance_test' AND TABLE_NAME='campaigns'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['blocked_invalid_verification', 'blocked_suppression', 'blocked_compliance_pause', 'blocked_throttle', 'blocked_license_revoked', 'blocked_placeholder'] as $c) {
        ok(in_array($c, $bcCols, true), "migration adds campaigns.{$c}");
    }
    $pdo->exec("INSERT INTO campaigns (name) VALUES ('Blocked Count Test')");
    $bcId = (int)$pdo->lastInsertId();
    $bcCount = function (string $col) use ($pdo, $bcId): int {
        return (int)$pdo->query("SELECT {$col} FROM campaigns WHERE id = {$bcId}")->fetchColumn();
    };
    // suppression refusal
    expectThrow(fn() => \App\Compliance::requireCompliantSend('optout@example.com', null, $bcId),
        'suppression list', 'suppression refusal throws');
    ok($bcCount('blocked_suppression') === 1, 'suppression refusal increments blocked_suppression');
    // compliance_pause: sender identity not configured
    $pdo->exec("DELETE FROM settings WHERE setting_key IN ('company_legal_name','physical_address')");
    expectThrow(fn() => \App\Compliance::requireCompliantSend('fine@example.com', null, $bcId),
        'sender identity', 'missing identity throws');
    ok($bcCount('blocked_compliance_pause') === 1, 'identity refusal increments blocked_compliance_pause');
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('company_legal_name','Test Corp LLC'), ('physical_address','123 Main St, Austin, TX 78701')");
    // license_revoked
    \App\Licensing::setVerdictForTest(\App\Licensing::defaultVerdict('revoked', 'itema-test'));
    expectThrow(fn() => \App\Compliance::requireCompliantSend('fine@example.com', null, $bcId),
        'revoked', 'revoked key throws');
    ok($bcCount('blocked_license_revoked') === 1, 'revoked key increments blocked_license_revoked');
    \App\Licensing::setVerdictForTest(null);
    // invalid_verification (gate enabled, fake provider verdicts INVALID)
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('verification_required','1'), ('verification_api_key','test-key'), ('verification_risky_action','block'), ('verification_strict','0'), ('verification_cache_days','0') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    \App\Compliance::setVerificationProviderFactory(fn(string $k) => new ItemABlockedFakeProvider());
    \App\Compliance::setVerificationStatusPersister(function (string $s): void {});
    expectThrow(fn() => \App\Compliance::requireCompliantSend('fine@example.com', null, $bcId),
        'invalid', 'invalid verdict throws');
    ok($bcCount('blocked_invalid_verification') === 1, 'invalid verdict increments blocked_invalid_verification');
    \App\Compliance::setVerificationProviderFactory(null);
    \App\Compliance::setVerificationStatusPersister(null);
    $pdo->exec("UPDATE settings SET setting_value='0' WHERE setting_key='verification_required'");
    // placeholder refusal (EmailSender choke point)
    expectThrow(fn() => \App\EmailSender::send('pending_abc@placeholder.com', 's', 'b', 'resend', 'k', 'news@example.com', null, $bcId),
        'placeholder', 'placeholder refusal throws');
    ok($bcCount('blocked_placeholder') === 1, 'placeholder refusal increments blocked_placeholder');
    // read side: the campaigns row (as api/campaigns.php returns it) summarizes
    $bcRow = $pdo->query("SELECT * FROM campaigns WHERE id = {$bcId}")->fetch();
    $bcSummary = \App\BlockedCount::summarize($bcRow);
    ok($bcSummary['total'] === 5, 'summarize() totals the persisted refusals (5)');
    ok(count($bcSummary['breakdown']) === 5, 'summarize() lists each non-zero reason');

    // --- Verification funnel (FIX2): honest data-quality stages ------------
    echo "funnel:\n";
    $f0 = \App\FunnelStats::compute($pdo);
    $pdo->exec("INSERT INTO leads (company_name, email, verification_status) VALUES
        ('Funnel Valid A','funnel-valid-a@example.com','valid'),
        ('Funnel Valid B','funnel-valid-b@example.com','valid'),
        ('Funnel Invalid','funnel-invalid@example.com','invalid'),
        ('Funnel Risky','funnel-risky@example.com','risky'),
        ('Funnel Unknown','funnel-unknown@example.com','unknown')");
    \App\Compliance::suppress('funnel-valid-b@example.com', 'unsubscribe', 'funnel-test');
    \App\Compliance::suppress('funnel-unknown@example.com', 'bounce', 'funnel-test');
    $f1 = \App\FunnelStats::compute($pdo);
    ok($f1['harvested'] - $f0['harvested'] === 5, 'harvested counts every raw row');
    ok($f1['verified_valid'] - $f0['verified_valid'] === 2, 'verified_valid counts only valid verdicts');
    ok($f1['invalid'] - $f0['invalid'] === 1, 'invalid counted');
    ok($f1['risky'] - $f0['risky'] === 1, 'risky counted');
    ok($f1['unknown'] - $f0['unknown'] === 1, 'unknown = never actually verified');
    ok($f1['checked'] - $f0['checked'] === 4, 'checked = valid + invalid + risky (excludes unknown)');
    ok($f1['suppressed'] - $f0['suppressed'] === 2, 'suppressed counts suppression-list matches');
    ok($f1['mailable'] - $f0['mailable'] === 1, 'mailable = verified valid AND not suppressed');
    $f2 = \App\FunnelStats::compute($pdo);
    ok($f2 === $f1, 'funnel stats are deterministic across calls');

    // --- Persona/name routing (item 9; DB-independent) ---------------------
    echo "persona/name:\n";
    require_once $repo . '/tests/compliance/PersonaNameTest.php';
    persona_name_tests('ok');

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
        exec("mysql -u root -e \"DROP DATABASE IF EXISTS compliance_test; DROP USER IF EXISTS 'compliance_test'@'%';\" 2>&1");
    } catch (\Throwable $e) { /* best effort */ }
}
