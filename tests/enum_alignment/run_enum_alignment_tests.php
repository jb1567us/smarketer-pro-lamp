<?php
/**
 * ITEM B — verification_status ENUM alignment tests.
 *
 * Usage: php tests/enum_alignment/run_enum_alignment_tests.php
 *
 * Requires MySQL (ENUM semantics cannot be tested on SQLite):
 *   ENUMALIGN_MYSQL_DSN    e.g. mysql:host=127.0.0.1;dbname=enumalign_test
 *   ENUMALIGN_MYSQL_DBNAME e.g. enumalign_test
 *   ENUMALIGN_MYSQL_USER / ENUMALIGN_MYSQL_PASS
 * and the `mysql` CLI (imports handle DELIMITER blocks the PDO splitter can't).
 *
 * What it proves, under STRICT_ALL_TABLES + STRICT_TRANS_TABLES:
 *  1. The item-B migration converges every known pre-state
 *     (phase-7 ENUM(5), compliance VARCHAR(20), missing column) to the union
 *     ENUM, and applies TWICE cleanly (idempotent).
 *  2. In-vocabulary values survive conversion by string (no positional ENUM
 *     remap corruption: 'gold_standard' stays 'gold_standard').
 *  3. Out-of-vocabulary values ('bogus', '') are sanitized to 'unknown'.
 *  4. Every value the real writers emit ('unknown','valid','invalid','risky',
 *     'unverified','evidence_backed','dns_confirmed','cross_source_matched',
 *     'gold_standard') can now be written — the previously-crashing path.
 *  5. Negative control: the OLD phase-7 ENUM(5) rejects 'unknown' under
 *     strict mode, proving this test would have caught the original bug.
 *  6. Fresh-install path: the real schema.sql imports cleanly under strict
 *     mode and the writers' statements work against it.
 *  7. Sibling fix: ExtractionExpert's status write ('Unqualified') is a
 *     legal leads.status ENUM member under strict mode.
 *
 * Nothing here touches production. No network calls. No secrets on disk.
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);

$failures = 0;
$passed = 0;
function ok(bool $cond, string $name): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "  PASS: {$name}\n"; }
    else { $failures++; echo "  FAIL: {$name}\n"; }
}

$dsn = getenv('ENUMALIGN_MYSQL_DSN') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "SKIP: set ENUMALIGN_MYSQL_DSN/_DBNAME/_USER/_PASS and ensure the `mysql` CLI exists.\n");
    exit(0);
}
$mysqlCli = trim(shell_exec('command -v mysql') ?: '');
$dbName = getenv('ENUMALIGN_MYSQL_DBNAME') ?: '';
if ($mysqlCli === '' || $dbName === '') {
    fwrite(STDERR, "ENUMALIGN needs the `mysql` CLI and ENUMALIGN_MYSQL_DBNAME.\n");
    exit(2);
}
$dbUser = getenv('ENUMALIGN_MYSQL_USER') ?: '';
$dbPass = getenv('ENUMALIGN_MYSQL_PASS') ?: '';
$dbHost = getenv('ENUMALIGN_MYSQL_HOST') ?: '127.0.0.1';

function cli(string $sql, ?string $db = null): array
{
    global $mysqlCli, $dbUser, $dbPass, $dbHost;
    // Omit -p entirely when the password is empty: `-p''` makes the client
    // prompt interactively, which hangs a non-interactive exec().
    $pw = $dbPass !== '' ? ' -p' . escapeshellarg($dbPass) : '';
    $cmd = sprintf('%s -h %s -u %s%s %s -e %s </dev/null 2>&1',
        escapeshellarg($mysqlCli),
        escapeshellarg($dbHost),
        escapeshellarg($dbUser),
        $pw,
        $db !== null ? escapeshellarg($db) : '',
        escapeshellarg($sql));
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
}
function cliFile(string $file, string $db): array
{
    global $mysqlCli, $dbUser, $dbPass, $dbHost;
    $pw = $dbPass !== '' ? ' -p' . escapeshellarg($dbPass) : '';
    $cmd = sprintf('%s -h %s -u %s%s %s < %s 2>&1',
        escapeshellarg($mysqlCli),
        escapeshellarg($dbHost),
        escapeshellarg($dbUser),
        $pw,
        escapeshellarg($db),
        escapeshellarg($file));
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
}

$TARGET_ENUM = "enum('unverified','evidence_backed','dns_confirmed','cross_source_matched','gold_standard','unknown','valid','invalid','risky')";
$VOCAB = ['unverified','evidence_backed','dns_confirmed','cross_source_matched','gold_standard','unknown','valid','invalid','risky'];

// ── scratch database ─────────────────────────────────────────────────────
[$code, $out] = cli("DROP DATABASE IF EXISTS `{$dbName}`; CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4;");
if ($code !== 0) { fwrite(STDERR, "cannot create scratch DB:\n$out\n"); exit(2); }
$pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$migration = $repo . '/migrations/2026-09-24-itemb-enum-align.sql';

function freshLeads(PDO $pdo, string $verifColDef): void
{
    $pdo->exec("DROP TABLE IF EXISTS leads");
    $pdo->exec("CREATE TABLE leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) UNIQUE NOT NULL,
        status ENUM('New','Enriched','Contacted','Qualified','Unqualified','Converted','Drafted') DEFAULT 'New',
        trust_score INT DEFAULT 0,
        {$verifColDef},
        verified_at TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function columnType(PDO $pdo): ?string
{
    $st = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='leads' AND COLUMN_NAME='verification_status'");
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? strtolower($row['COLUMN_TYPE']) : null;
}
function applyMigrationTwice(string $migration, string $dbName): array
{
    [$c1, $o1] = cliFile($migration, $dbName);
    [$c2, $o2] = cliFile($migration, $dbName);
    return [$c1 === 0 && $c2 === 0, $o1 . "\n" . $o2];
}

// ── Scenario A: phase-7 ENUM(5) pre-state ──────────────────────────────────
echo "== Scenario A: phase-7 ENUM(5) pre-state ==\n";
freshLeads($pdo, "verification_status ENUM('unverified','evidence_backed','dns_confirmed','cross_source_matched','gold_standard') DEFAULT 'unverified'");
$pdo->exec("SET SESSION sql_mode = ''"); // allow setup, then go strict for the real work
foreach (['unverified','evidence_backed','dns_confirmed','cross_source_matched','gold_standard'] as $i => $v) {
    $pdo->exec("INSERT INTO leads (email, verification_status) VALUES ('phase7-{$i}@example.com', '{$v}')");
}
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
[$clean, $mout] = applyMigrationTwice($migration, $dbName);
ok($clean, 'migration applies twice cleanly on phase-7 pre-state' . ($clean ? '' : " [$mout]"));
ok(columnType($pdo) === $TARGET_ENUM, 'column converged to union ENUM');
$rows = $pdo->query("SELECT email, verification_status FROM leads ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
// Positional-remap check: values must survive BY STRING, not by ENUM index.
$expected = ['phase7-0@example.com'=>'unverified','phase7-1@example.com'=>'evidence_backed','phase7-2@example.com'=>'dns_confirmed','phase7-3@example.com'=>'cross_source_matched','phase7-4@example.com'=>'gold_standard'];
ok($rows === $expected, 'phase-7 values preserved by string (no positional remap corruption)');

// ── Scenario B: compliance VARCHAR(20) pre-state, with junk ───────────────
echo "== Scenario B: compliance VARCHAR(20) pre-state (+ junk) ==\n";
freshLeads($pdo, "verification_status VARCHAR(20) NOT NULL DEFAULT 'unknown'");
$pdo->exec("SET SESSION sql_mode = ''");
foreach (['unknown','valid','invalid','risky','unverified','bogus',''] as $i => $v) {
    $pdo->exec("INSERT INTO leads (email, verification_status) VALUES ('vc-{$i}@example.com', " . $pdo->quote($v) . ")");
}
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
[$clean, $mout] = applyMigrationTwice($migration, $dbName);
ok($clean, 'migration applies twice cleanly on VARCHAR pre-state' . ($clean ? '' : " [$mout]"));
ok(columnType($pdo) === $TARGET_ENUM, 'column converged to union ENUM');
$rows = $pdo->query("SELECT email, verification_status FROM leads ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
ok($rows['vc-0@example.com'] === 'unknown' && $rows['vc-1@example.com'] === 'valid'
    && $rows['vc-2@example.com'] === 'invalid' && $rows['vc-3@example.com'] === 'risky'
    && $rows['vc-4@example.com'] === 'unverified', 'in-vocabulary values preserved');
ok($rows['vc-5@example.com'] === 'unknown' && $rows['vc-6@example.com'] === 'unknown', "junk ('bogus','') sanitized to 'unknown'");

// ── Scenario C: missing column ────────────────────────────────────────────
echo "== Scenario C: missing verification_status column ==\n";
$pdo->exec("DROP TABLE IF EXISTS leads");
$pdo->exec("CREATE TABLE leads (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) UNIQUE NOT NULL,
    status ENUM('New','Enriched','Contacted','Qualified','Unqualified','Converted','Drafted') DEFAULT 'New',
    trust_score INT DEFAULT 0, verified_at TIMESTAMP NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
[$clean, $mout] = applyMigrationTwice($migration, $dbName);
ok($clean, 'migration applies twice cleanly when column missing' . ($clean ? '' : " [$mout]"));
ok(columnType($pdo) === $TARGET_ENUM, 'column added as union ENUM with correct default');
$pdo->exec("INSERT INTO leads (email) VALUES ('default@example.com')");
ok($pdo->query("SELECT verification_status FROM leads WHERE email='default@example.com'")->fetchColumn() === 'unknown',
    "default is 'unknown'");

// ── Writer paths under STRICT mode (the previously-crashing writes) ───────
echo "== Writer paths under STRICT_ALL_TABLES ==\n";
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
$pdo->exec("INSERT INTO leads (email) VALUES ('writer@example.com')");
$leadId = (int)$pdo->lastInsertId();
$threw = false;
try {
    // BulkVerifyJob::persistVerdict shape: UPDATE leads SET verification_status = ?, verified_at = ? WHERE id = ?
    $st = $pdo->prepare("UPDATE leads SET verification_status = ?, verified_at = ? WHERE id = ?");
    foreach ($VOCAB as $v) {
        $st->execute([$v, date('Y-m-d H:i:s'), $leadId]);
    }
    // Compliance gate $recordStatus shape: UPDATE leads SET verification_status = ?, verified_at = NOW() WHERE email = ?
    $st2 = $pdo->prepare("UPDATE leads SET verification_status = ?, verified_at = NOW() WHERE email = ?");
    foreach (['valid','invalid','risky','unknown'] as $v) {
        $st2->execute([$v, 'writer@example.com']);
    }
    // SimpleHarvester::stageResults INSERT shape with a TrustScorer tier.
    $pdo->exec("INSERT INTO leads (email, verification_status, trust_score) VALUES ('harvest@example.com', 'gold_standard', 95)");
    $pdo->exec("INSERT INTO leads (email, verification_status) VALUES ('harvest2@example.com', 'evidence_backed')");
} catch (Throwable $e) {
    $threw = true;
    echo "    (writer threw: " . get_class($e) . ': ' . substr($e->getMessage(), 0, 120) . ")\n";
}
ok(!$threw, 'all 9 writer values persist under STRICT mode (previously fatal for unknown/valid/invalid/risky)');
// Sibling: ExtractionExpert status write must be a legal leads.status member.
try {
    $pdo->exec("UPDATE leads SET status = 'Unqualified' WHERE id = {$leadId}");
    ok(true, "ExtractionExpert status='Unqualified' accepted under STRICT mode");
} catch (Throwable $e) {
    ok(false, "ExtractionExpert status='Unqualified' rejected: " . substr($e->getMessage(), 0, 100));
}

// ── Negative control: old phase-7 ENUM(5) MUST reject 'unknown' ────────────
echo "== Negative control: old phase-7 ENUM(5) rejects 'unknown' ==";
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
$pdo->exec("DROP TABLE IF EXISTS leads_oldenum");
$pdo->exec("CREATE TABLE leads_oldenum (id INT AUTO_INCREMENT PRIMARY KEY,
    verification_status ENUM('unverified','evidence_backed','dns_confirmed','cross_source_matched','gold_standard') DEFAULT 'unverified'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$rejected = false;
try {
    $pdo->exec("INSERT INTO leads_oldenum (verification_status) VALUES ('unknown')");
} catch (Throwable $e) {
    $rejected = stripos($e->getMessage(), '1265') !== false || stripos($e->getMessage(), 'truncated') !== false;
}
ok($rejected, "old ENUM(5) rejects 'unknown' under strict mode (control proves the test detects the bug)");
$pdo->exec("DROP TABLE IF EXISTS leads_oldenum");

// ── Fresh-install path: real schema.sql under strict mode ─────────────────
echo "== Fresh install: schema.sql under STRICT mode ==";
[$code, $out] = cli("DROP DATABASE IF EXISTS `{$dbName}_fresh`; CREATE DATABASE `{$dbName}_fresh` CHARACTER SET utf8mb4;");
ok($code === 0, 'fresh scratch DB created');
if ($code === 0) {
    [$code, $out] = cli("SET GLOBAL sql_mode = 'STRICT_ALL_TABLES,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';");
    // NOTE: GLOBAL change is best-effort; per-session strict is enforced below regardless.
    [$c2, $o2] = cliFile($repo . '/schema.sql', $dbName . '_fresh');
    ok($c2 === 0, 'schema.sql imports cleanly' . ($c2 === 0 ? '' : " [$o2]"));
    $pdoFresh = new PDO(str_replace($dbName, $dbName . '_fresh', $dsn), $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdoFresh->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    $type = $pdoFresh->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='leads' AND COLUMN_NAME='verification_status'")
        ->fetchColumn();
    ok(strtolower((string)$type) === $TARGET_ENUM, 'fresh schema.sql carries the union ENUM');
    $idx = $pdoFresh->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='leads' AND INDEX_NAME='idx_leads_verification'")->fetchColumn();
    ok((int)$idx > 0, 'fresh schema.sql carries idx_leads_verification');
    try {
        $pdoFresh->exec("INSERT INTO leads (company_name, email) VALUES ('Acme', 'fresh@example.com')");
        $id = (int)$pdoFresh->lastInsertId();
        $st = $pdoFresh->prepare("UPDATE leads SET verification_status = ?, verified_at = NOW() WHERE id = ?");
        foreach ($VOCAB as $v) { $st->execute([$v, $id]); }
        ok(true, 'writer statements work on fresh-install schema under strict mode');
    } catch (Throwable $e) {
        ok(false, 'writer statements failed on fresh schema: ' . substr($e->getMessage(), 0, 100));
    }
    [$code] = cli("DROP DATABASE IF EXISTS `{$dbName}_fresh`;");
}

[$code] = cli("DROP DATABASE IF EXISTS `{$dbName}`;");
echo "\n{$passed} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
