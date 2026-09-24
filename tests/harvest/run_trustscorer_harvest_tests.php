<?php
/**
 * Item 4 — Real TrustScorer in harvest results.
 *
 * Usage: php tests/harvest/run_trustscorer_harvest_tests.php
 *
 * Verifies:
 *  1. TrustScorer itself computes genuine, deterministic scores (known vectors).
 *  2. SimpleHarvester::scoreHarvestResults() attaches a score ONLY when one
 *     was genuinely computed (honesty: zero-evidence items get NO score key,
 *     so cards keep showing "Not scored").
 *  3. Attached scores exactly match TrustScorer::computeTrustScore() for the
 *     same evidence inputs (no fabricated/interpolated values).
 *  4. DNS probing is cached per domain, fails soft, and is kill-switchable.
 *  5. stageResults() persists trust_score/trust_breakdown/verification_status
 *     to leads, and still works on databases WITHOUT the trust columns.
 *
 * No MySQL needed: persistence tests use SQLite. DNS is stubbed via the
 * $dnsCheck callable (no real network in tests).
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require_once $repo . '/includes/autoload.php';
require_once $repo . '/includes/SimpleHarvester.php';

use App\Verification\TrustScorer;

$failures = 0;
$passed = 0;
function ok(bool $cond, string $name): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "  PASS: {$name}\n"; }
    else { $failures++; echo "  FAIL: {$name}\n"; }
}

// ---------------------------------------------------------------------------
// 1. TrustScorer known vectors (genuine, deterministic computation)
// ---------------------------------------------------------------------------
echo "== TrustScorer vectors ==\n";

$r = TrustScorer::computeTrustScore(false, false, false, false);
ok($r['trust_score'] === 0, 'all-false => score 0');
ok($r['trust_tier'] === 'unverified', 'all-false => tier unverified');
ok($r['is_gold_standard'] === false, 'all-false => not gold');
ok($r['verification_status'] === 'unverified', 'all-false => status unverified');
ok($r['breakdown'] === ['level_1' => 0, 'level_2' => 0, 'level_3' => 0, 'dns' => 0], 'all-false => zero breakdown');

$r = TrustScorer::computeTrustScore(false, false, true, true);
ok($r['trust_score'] === 20, 'level3+dns => score 20');
ok($r['trust_tier'] === 'level_3', 'level3+dns => tier level_3');
ok($r['verification_status'] === 'unverified', 'level3+dns => status unverified (no L1)');
ok($r['breakdown']['level_3'] === 10 && $r['breakdown']['dns'] === 10, 'level3+dns => breakdown 10/10');

$r = TrustScorer::computeTrustScore(false, false, true, false);
ok($r['trust_score'] === 10, 'level3 only => score 10');

$r = TrustScorer::computeTrustScore(true, false, false, true);
ok($r['trust_score'] === 60, 'L1+dns => score 60');
ok($r['trust_tier'] === 'level_1', 'L1+dns => tier level_1');
ok($r['verification_status'] === 'dns_confirmed', 'L1+dns => status dns_confirmed');

$r = TrustScorer::computeTrustScore(true, true, true, true, 1.0, 1.0, true);
ok($r['trust_score'] === 100, 'gold inputs => score capped at 100');
ok($r['is_gold_standard'] === true, 'gold inputs => is_gold_standard');
ok($r['verification_status'] === 'gold_standard', 'gold inputs => status gold_standard');

$r = TrustScorer::computeTrustScore(true, false, false, false, 0.6);
ok($r['trust_score'] === 30, 'L1 @0.6 confidence => 50*0.6 = 30');

// ---------------------------------------------------------------------------
// 2. scoreHarvestResults() — honest scoring of harvest items
// ---------------------------------------------------------------------------
echo "== scoreHarvestResults ==\n";

$dnsCalls = [];
$dnsStub = function (string $domain) use (&$dnsCalls): bool {
    $dnsCalls[] = $domain;
    return $domain === 'northwind-traders.io'; // receptive only for this one
};

$items = [
    // 0: snippet email on receptive domain => level3 + dns => 20
    ['title' => 'Northwind Traders — contact@northwind-traders.io for quotes',
     'url' => 'https://northwind-traders.io/contact',
     'content' => 'Wholesale supplier. Email contact@northwind-traders.io',
     'snippet' => ''],
    // 1: snippet email on non-receptive domain => level3 only => 10
    ['title' => 'Acme Corp',
     'url' => 'https://acme-corp.net/about',
     'content' => 'Reach sales@acme-corp.net today',
     'snippet' => ''],
    // 2: no email, website domain not probed-receptive => dns-only check fails => unscored
    ['title' => 'Globex',
     'url' => 'https://globex.example.org/',
     'content' => 'No contact info here',
     'snippet' => ''],
];

$scored = SimpleHarvester::scoreHarvestResults($items, $dnsStub);

// Item 0: genuinely computed 20
ok(isset($scored[0]['score']), 'item0 (email+receptive DNS) carries a score');
ok($scored[0]['score'] === 0.2, 'item0 score is 0.2 (20/100 as 0..1 float for the card)');
ok($scored[0]['trust_score'] === 20, 'item0 trust_score is 20');
ok($scored[0]['trust_tier'] === 'level_3', 'item0 tier level_3');
ok($scored[0]['verification_status'] === 'unverified', 'item0 status unverified');
ok($scored[0]['trust_breakdown'] === ['level_1' => 0, 'level_2' => 0, 'level_3' => 10, 'dns' => 10],
    'item0 breakdown matches TrustScorer weights');

// Item 1: genuinely computed 10 (snippet evidence, no DNS)
ok(isset($scored[1]['score']) && $scored[1]['score'] === 0.1, 'item1 (email, no DNS) scores 0.1');
ok($scored[1]['trust_score'] === 10, 'item1 trust_score is 10');

// Item 2: zero evidence => NO score key => card shows "Not scored"
ok(!array_key_exists('score', $scored[2]), 'item2 (no evidence) has NO score key — honest "Not scored"');
ok(!array_key_exists('trust_score', $scored[2]), 'item2 has no trust_score key either');

// 3. Attached scores must equal TrustScorer::computeTrustScore() exactly
foreach ([0, 1] as $i) {
    $expected = TrustScorer::computeTrustScore(false, false, true, $i === 0, 1.0, 1.0, false);
    ok($scored[$i]['trust_score'] === $expected['trust_score'], "item{$i} trust_score identical to direct TrustScorer call");
    ok($scored[$i]['trust_breakdown'] === $expected['breakdown'], "item{$i} breakdown identical to direct TrustScorer call");
}

// 4. DNS caching: 3 items, 3 distinct domains => exactly 3 probes
ok(count($dnsCalls) === 3, 'one DNS probe per unique domain (got ' . count($dnsCalls) . ')');

$dnsCalls = [];
$dupItems = [
    ['title' => 'A contact@northwind-traders.io', 'url' => 'https://northwind-traders.io/a', 'content' => '', 'snippet' => ''],
    ['title' => 'B contact@northwind-traders.io', 'url' => 'https://northwind-traders.io/b', 'content' => '', 'snippet' => ''],
];
SimpleHarvester::scoreHarvestResults($dupItems, $dnsStub);
ok(count($dnsCalls) === 1, 'same domain twice => DNS probed once (cached)');

// 5. DNS failure fails soft — snippet evidence still scores, no exception
$throwing = function (string $domain): bool { throw new RuntimeException('dns down'); };
$soft = SimpleHarvester::scoreHarvestResults([$items[0]], $throwing);
ok($soft[0]['trust_score'] === 10, 'DNS exception => degrades to level-3-only score 10, no throw');

// 6. dnsEnabled=false skips probes entirely
$dnsCalls = [];
$off = SimpleHarvester::scoreHarvestResults([$items[0]], $dnsStub, false);
ok(count($dnsCalls) === 0, 'dnsEnabled=false => zero DNS probes');
ok($off[0]['trust_score'] === 10, 'dnsEnabled=false => level-3-only score 10');

// 7. isHarvestDnsEnabled(): kill-switch behavior
echo "== isHarvestDnsEnabled ==\n";
$dbNoSettings = new PDO('sqlite::memory:');
$dbNoSettings->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ok(SimpleHarvester::isHarvestDnsEnabled($dbNoSettings) === true, 'missing settings table => default ON');

$dbSettings = new PDO('sqlite::memory:');
$dbSettings->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbSettings->exec("CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
ok(SimpleHarvester::isHarvestDnsEnabled($dbSettings) === true, 'no row => default ON');
$dbSettings->exec("INSERT INTO settings VALUES ('trustscorer_harvest_dns', '0')");
ok(SimpleHarvester::isHarvestDnsEnabled($dbSettings) === false, "'0' => disabled");
$dbSettings->exec("UPDATE settings SET setting_value='1' WHERE setting_key='trustscorer_harvest_dns'");
ok(SimpleHarvester::isHarvestDnsEnabled($dbSettings) === true, "'1' => enabled");

// ---------------------------------------------------------------------------
// 8. stageResults() persistence (SQLite, no MySQL needed)
// ---------------------------------------------------------------------------
echo "== stageResults persistence ==\n";

function makeLeadsDb(bool $withTrustCols): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $trust = $withTrustCols ? ", trust_score INTEGER DEFAULT 0, trust_breakdown TEXT DEFAULT NULL" : "";
    $db->exec("CREATE TABLE leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company_name TEXT NOT NULL, contact_name TEXT, email TEXT UNIQUE NOT NULL,
        website TEXT, source TEXT, campaign_id INTEGER NULL, notes TEXT,
        status TEXT DEFAULT 'New', target_persona TEXT NULL, email_source TEXT NULL,
        source_url TEXT NULL, is_role_based INTEGER NOT NULL DEFAULT 0,
        consent_status TEXT NOT NULL DEFAULT 'unknown',
        verification_status TEXT NOT NULL DEFAULT 'unknown'{$trust}
    )");
    return $db;
}

$db = makeLeadsDb(true);
$staged = SimpleHarvester::scoreHarvestResults($items, $dnsStub);
$res = SimpleHarvester::stageResults($db, $staged, 'test query', null, '');
ok($res['staged'] === 3, 'staged 3 leads');

$rows = $db->query("SELECT email, trust_score, trust_breakdown, verification_status FROM leads ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
ok((int)$rows[0]['trust_score'] === 20, 'lead0 persisted trust_score 20');
ok(json_decode($rows[0]['trust_breakdown'], true) === ['level_1' => 0, 'level_2' => 0, 'level_3' => 10, 'dns' => 10],
    'lead0 persisted breakdown JSON');
ok($rows[0]['verification_status'] === 'unverified', 'lead0 persisted verification_status');
ok((int)$rows[1]['trust_score'] === 10, 'lead1 persisted trust_score 10');
ok((int)$rows[2]['trust_score'] === 0, 'lead2 (unscored) persisted trust_score 0 (default)');
ok($rows[2]['trust_breakdown'] === null, 'lead2 persisted trust_breakdown NULL');
ok($rows[2]['verification_status'] === 'unknown', 'lead2 keeps legacy verification_status unknown');

// Duplicates still deduped
$res2 = SimpleHarvester::stageResults($db, $staged, 'test query', null, '');
ok($res2['staged'] === 0 && $res2['duplicates'] === 3, 're-stage => 3 duplicates, 0 staged');

// Legacy DB without trust columns: harvest must NOT fatal
echo "== stageResults without trust columns (legacy DB) ==\n";
$dbLegacy = makeLeadsDb(false);
$resLegacy = SimpleHarvester::stageResults($dbLegacy, $staged, 'test query', null, '');
ok($resLegacy['staged'] === 3, 'legacy DB stages fine without trust columns');
$legacyRows = $dbLegacy->query("SELECT email, verification_status FROM leads ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
ok($legacyRows[0]['verification_status'] === 'unknown', 'legacy DB keeps verification_status unknown (no partial writes)');

// ---------------------------------------------------------------------------
echo "\n{$passed} passed, {$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
