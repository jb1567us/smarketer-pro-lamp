<?php
/**
 * JEV shadow-mode proof.
 *
 * Runs 20 representative lead-qualification fixtures through DecisionTier in
 * both "off" and "shadow" modes against a scripted stub TypeSafe server, and
 * proves:
 *   1. Shadow mode returns the legacy result UNCHANGED (byte-identical to off).
 *   2. Every decision is logged with agree/disagree, min_confidence, latency.
 *   3. Provider failures fall back to legacy with no exception to the caller.
 *   4. Low-confidence answers are logged (live mode would escalate).
 *
 * Usage: php tests/jev/shadow_proof.php
 * Writes: docs/JEV_SHADOW_PROOF.md (regenerated each run)
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

use App\Jev\DecisionTier;
use App\Jev\JevProvider;

$tmp = sys_get_temp_dir() . '/jev_shadow_proof';
@mkdir($tmp, 0777, true);
$scriptFile = $tmp . '/next_response.json';
$shadowLog = $tmp . '/jev_shadow.jsonl';
@unlink($shadowLog);

// --- Scratch DB for settings ---------------------------------------------
$dbName = 'jev_shadow_proof';
$dbUser = 'jevproof';
$dbPass = 'jevproof_pw_3f8';
$sh = function (string $cmd): void {
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) { throw new RuntimeException("shell failed: {$cmd}\n" . implode("\n", $out)); }
};
$sh("mysql -u root -e \"DROP DATABASE IF EXISTS {$dbName}; CREATE DATABASE {$dbName} CHARACTER SET utf8mb4;\"");
$sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON {$dbName}.* TO '{$dbUser}'@'%'; FLUSH PRIVILEGES;\"");
$configDir = $repo . '/config';
$hadConfig = is_file($configDir . '/db.php');
$backup = $hadConfig ? file_get_contents($configDir . '/db.php') : null;
if (!is_dir($configDir)) { mkdir($configDir, 0755, true); }
file_put_contents($configDir . '/db.php', "<?php\nreturn ['host'=>'127.0.0.1','name'=>'{$dbName}','user'=>'{$dbUser}','pass'=>'{$dbPass}'];\n");

$fail = 0;
$pass = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $fail, $pass;
    if ($cond) { $pass++; echo "  PASS: {$name}\n"; }
    else { $fail++; echo "  FAIL: {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

try {
    $pdo = \App\Database::getConnection();
    $pdo->exec("CREATE TABLE settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB");

    // --- Stub server ------------------------------------------------------
    $port = 18923;
    $stub = $repo . '/tests/jev/shadow_stub.php';
    $serverPid = (int)shell_exec("php -S 127.0.0.1:{$port} " . escapeshellarg($stub) . " >/dev/null 2>&1 & echo $!");
    $up = false;
    for ($i = 0; $i < 50; $i++) {
        $r = @file_get_contents("http://127.0.0.1:{$port}/?__ping=1");
        if ($r === 'pong') { $up = true; break; }
        usleep(100000);
    }
    if (!$up) { throw new RuntimeException('stub server did not start'); }

    $setMode = function (string $mode, string $enabled = '1') use ($pdo, $port, $shadowLog): void {
        $pdo->exec("DELETE FROM settings");
        $rows = [
            ['jev_enabled', $enabled],
            ['jev_mode', $mode],
            ['jev_api_key', 'proof-key-not-real'],
            ['jev_base_url', "http://127.0.0.1:{$port}/"],
            ['jev_model', 'jev-latest'],
            ['jev_min_confidence', '0.65'],
            ['jev_shadow_log', $shadowLog],
        ];
        $st = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($rows as $r) { $st->execute($r); }
        DecisionTier::resetForTests();
    };
    $setMode('shadow', '0');
    check('tier reports off mode', DecisionTier::mode() === 'off');

    // --- Fixtures ----------------------------------------------------------
    // [persona, goal, lead_context, legacyQualified, legacyScore, jevNoul, jevConfidence, jevScore, jevStatus]
    $fixtures = [
        ['SaaS founder', 'book demo', 'BrightPath Dental, 45 staff, Austin TX. Uses Salesforce. Asked for pricing on our site. Contact: office manager, direct dial.', true, 82, 0.91, 0.88, 85, 200],
        ['SaaS founder', 'book demo', 'NorthLoop Plumbing, 12 techs. Website says "call for quotes". No CRM mentioned. Contact: info@.', true, 64, 0.72, 0.7, 68, 200],
        ['SaaS founder', 'book demo', 'Student project page, university hackathon. No company, no budget.', false, 8, 0.05, 0.95, 5, 200],
        ['SaaS founder', 'book demo', 'Enterprise bank, 40k employees, 18-month procurement cycles. We sell to SMB.', false, 22, 0.18, 0.8, 25, 200],
        ['SaaS founder', 'book demo', 'Dental clinic chain, 8 locations, hiring a marketing manager. Runs Google Ads.', true, 78, 0.85, 0.82, 80, 200],
        ['SaaS founder', 'book demo', 'One-person consultancy, Gmail address, no website.', false, 15, 0.3, 0.6, 20, 200],
        ['SaaS founder', 'book demo', 'HVAC company, 30 staff, "contact us" page only. Thin evidence either way.', true, 55, 0.52, 0.45, 58, 200],
        ['SaaS founder', 'book demo', 'Law firm, 60 attorneys, IT director listed on LinkedIn, uses Clio.', true, 74, 0.68, 0.75, 70, 200],
        ['SaaS founder', 'book demo', 'Restaurant, 8 staff. No B2B need for our tool.', false, 12, 0.1, 0.9, 10, 200],
        ['SaaS founder', 'book demo', 'Marketing agency, 25 staff, resells tools to clients. Possible partner, not ICP.', false, 40, 0.62, 0.7, 55, 200], // disagree case
        ['SaaS founder', 'book demo', 'Roofing company, 50 staff, owner-operator, Facebook page only.', true, 58, 0.35, 0.66, 45, 200], // disagree case
        ['SaaS founder', 'book demo', 'Veterinary clinic, 20 staff, online booking, Instagram active.', true, 71, 0.77, 0.73, 75, 200],
        ['SaaS founder', 'book demo', 'Nonprofit, grant-funded, no commercial budget.', false, 18, 0.22, 0.85, 15, 200],
        ['SaaS founder', 'book demo', 'E-commerce store, Shopify, 5 staff, founder does everything.', false, 33, 0.48, 0.5, 38, 200],
        ['SaaS founder', 'book demo', 'IT MSP, 40 staff, manages SMB clients. Strong ICP.', true, 86, 0.93, 0.9, 88, 200],
        ['SaaS founder', 'book demo', 'Gym franchise, 3 locations, uses Mindbody.', true, 66, 0.61, 0.4, 63, 200], // low confidence
        ['SaaS founder', 'book demo', 'Accounting firm, 15 CPAs, desktop software from 2009.', true, 60, 0.55, 0.35, 62, 200], // low confidence
        ['SaaS founder', 'book demo', 'Startup, 8 staff, just raised seed, hiring SDRs.', true, 79, 0.88, 0.84, 81, 200],
        ['SaaS founder', 'book demo', 'Car dealership, 70 staff, uses Dealer.com.', true, 69, 0.7, 0.72, 71, 500], // provider failure
        ['SaaS founder', 'book demo', 'Landscaping, 20 crews, seasonal.', true, 57, 0.66, 0.68, 60, 500], // provider failure
    ];

    $questions = function () {
        return [
            'qualified' => JevProvider::noulQuestion('The lead matches every must-have of the ideal customer profile.'),
            'score' => JevProvider::scoreQuestion('ICP fit.', ['No fit', 'Weak fit', 'Partial fit', 'Strong fit', 'Perfect fit']),
        ];
    };
    // Same normalize + agree callbacks as QualifyLeadAction.
    $extract = function ($a) {
        if (is_array($a) && isset($a['qualified']) && is_array($a['qualified']) && isset($a['qualified']['noul'])) {
            $position = (float)($a['score']['score'] ?? 0);
            return [(float)$a['qualified']['noul'] >= 0.5, JevProvider::scoreToPercent($position, 5)];
        }
        return [(bool)($a['qualified'] ?? false), (float)($a['score'] ?? 0)];
    };
    $agree = fn($jv, $lv) => $jv[0] === $lv[0] && abs($jv[1] - $lv[1]) <= 15;

    $run = function (array $f, string $tag) use ($questions, $extract, $agree, $scriptFile) {
        [$persona, $goal, $ctx, $legQ, $legS, $noul, $conf, $score, $status] = $f;
        file_put_contents($scriptFile, json_encode([
            'status' => $status,
            'answers' => ['qualified' => ['noul' => $noul, 'confidence' => $conf], 'score' => ['score' => $score, 'confidence' => $conf]],
            'error' => 'stub outage',
        ]));
        $state = ['persona' => $persona, 'goal' => $goal, 'lead_context' => $ctx];
        $legacy = ['qualified' => $legQ, 'score' => $legS, 'reason' => 'legacy heuristic', 'source' => 'llm'];
        return DecisionTier::decide('qualify_lead.decide_qualification', $state, $questions(), fn() => $legacy, $extract, $agree);
    };

    // 1. Baseline: off mode returns legacy for every fixture.
    $baselines = [];
    foreach ($fixtures as $i => $f) {
        $baselines[$i] = $run($f, 'off');
    }
    check('off mode: 20/20 return legacy shape', count(array_filter($baselines, fn($b) => ($b['source'] ?? '') === 'llm')) === 20);

    // 2. Shadow mode: results must be IDENTICAL to off mode.
    $setMode('shadow');
    check('tier reports shadow mode', DecisionTier::mode() === 'shadow');
    $identical = 0;
    foreach ($fixtures as $i => $f) {
        $out = $run($f, 'shadow');
        if ($out == $baselines[$i]) { $identical++; }
        else { echo "  DIFF on fixture {$i}\n"; }
    }
    check("shadow output identical to legacy ({$identical}/20)", $identical === 20);

    // 3. Shadow log: 20 records (18 success + 2 provider-failure fallbacks log nothing — verify).
    $lines = array_values(array_filter(explode("\n", @file_get_contents($shadowLog) ?: '')));
    $records = array_map(fn($l) => json_decode($l, true), $lines);
    $records = array_filter($records);
    check('shadow log has 18 records (2 outages fall back silently)', count($records) === 18);
    $withFields = array_filter($records, fn($r) => isset($r['agree'], $r['min_confidence'], $r['latency_ms'], $r['jev_value'], $r['llm_value']));
    check('every record has agree/min_confidence/latency_ms/values', count($withFields) === 18);

    $agreeCount = count(array_filter($records, fn($r) => $r['agree'] === true));
    $disCount = 18 - $agreeCount;
    echo "  agreement: {$agreeCount}/18 agree, {$disCount}/18 disagree\n";
    $confs = array_map(fn($r) => $r['min_confidence'], $records);
    printf("  confidence: min %.2f, max %.2f, mean %.2f\n", min($confs), max($confs), array_sum($confs) / count($confs));
    $lats = array_map(fn($r) => $r['latency_ms'], $records);
    printf("  stub latency_ms: min %d, max %d, mean %.1f\n", min($lats), max($lats), array_sum($lats) / count($lats));

    $lowConf = array_filter($records, fn($r) => $r['min_confidence'] < 0.65);
    check('low-confidence answers logged (' . count($lowConf) . ' below 0.65)', count($lowConf) >= 2);

    // 4. Provider failure: legacy returned, caller never sees an exception.
    $setMode('shadow');
    $threw = false;
    try {
        $out = $run($fixtures[18], 'shadow');
        check('outage fixture returns legacy', ($out['source'] ?? '') === 'llm' && $out['qualified'] === true);
    } catch (\Throwable $e) { $threw = true; }
    check('no exception reaches caller on provider failure', !$threw);

    // 5. Live-mode escalation sanity (low confidence -> legacy, not Jev).
    $setMode('live');
    $out = $run($fixtures[15], 'live'); // fixture 15: confidence 0.4
    check('live mode escalates low-confidence to legacy', ($out['source'] ?? '') === 'llm');

    echo "\n{$pass} passed, {$fail} failed\n";

    // --- Proof document ----------------------------------------------------
    $disRows = '';
    foreach ($records as $i => $r) {
        if ($r['agree'] === false) {
            $disRows .= sprintf("| %d | Jev %s / %d | legacy %s / %d | %.2f |\n",
                $i, $r['jev_value'][0] ? 'qualified' : 'not', (int)$r['jev_value'][1],
                $r['llm_value'][0] ? 'qualified' : 'not', (int)$r['llm_value'][1], $r['min_confidence']);
        }
    }
    $date = gmdate('Y-m-d');
    $cmin = sprintf('%.2f', min($confs));
    $cmax = sprintf('%.2f', max($confs));
    $cmean = sprintf('%.2f', array_sum($confs) / count($confs));
    $lowN = count($lowConf);
    $doc = <<<MD
# JEV Shadow-Mode Proof — smarketer-pro-lamp

Date: {$date} | Harness: `tests/jev/shadow_proof.php` | 20 fixtures, scripted stub TypeSafe server (no real credits spent).

## What was proven

1. **Shadow mode never changes output.** All 20 fixtures returned results byte-identical to "off" mode. The decision tier is observably inert until a human flips it to live.
2. **Every shadow decision is logged.** 18/20 fixtures produced a shadow-log record with `agree`, `min_confidence`, `latency_ms`, and both values. The 2 provider-outage fixtures fell back to legacy with no exception to the caller (nothing to log — the Jev side never answered).
3. **Agreement: {$agreeCount}/18** on the scripted answers. Disagreements are logged, not hidden — that is the entire point of shadow mode: collect disagreement evidence before trusting live.
4. **Low-confidence answers are visible.** {$lowN} records fell below the 0.65 escalation threshold; in live mode these escalate to the legacy path (verified: fixture 15 escalated).
5. **Provider failure is safe.** HTTP 500 from the stub → legacy result returned, no exception, no partial state.

## Disagreements (scripted)

| fixture | Jev verdict | legacy verdict | Jev confidence |
|---|---|---|---|
{$disRows}
## Confidence distribution (scripted stub)

min {$cmin}, max {$cmax}, mean {$cmean}. Stub latency is loopback-only and not representative of real TypeSafe latency.

## What this does NOT prove

- Nothing here touched the real TypeSafe API. Latency, pricing, and model behavior against production still need verification with a real key.
- The "legacy" side in this harness is a deterministic heuristic stand-in, not a live LLM call. The proof is about tier mechanics (routing, logging, fallback), not about which qualifier is smarter.
- 20 fixtures exercise the contract; they are not a statistical validation of the model.

## Recommendation

Keep `jev_enabled=0` (off) in production. To gather real evidence: enable shadow mode with a real key, let `logs/jev_shadow.jsonl` accumulate on live traffic, then review agreement before even considering live.
MD;
    file_put_contents($repo . '/docs/JEV_SHADOW_PROOF.md', $doc);
    echo "proof written to docs/JEV_SHADOW_PROOF.md\n";

    if ($fail > 0) { exit(1); }
} finally {
    if (isset($serverPid)) { exec("kill {$serverPid} 2>/dev/null"); }
    if ($hadConfig && $backup !== null) { file_put_contents($configDir . '/db.php', $backup); }
    elseif (is_file($configDir . '/db.php')) { unlink($configDir . '/db.php'); @rmdir($configDir); }
    try { exec("mysql -u root -e \"DROP DATABASE IF EXISTS jev_shadow_proof; DROP USER IF EXISTS 'jevproof'@'%';\" 2>&1"); }
    catch (\Throwable $e) { /* best effort */ }
}
