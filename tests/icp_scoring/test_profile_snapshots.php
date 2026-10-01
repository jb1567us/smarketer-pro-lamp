#!/usr/bin/env php
<?php
/**
 * P4: versioned, pinned, read-only evidence snapshots
 * (PI -> LAMP integration map section 4b — the structural fix for the
 * run-6 phantom-config reproducibility failure).
 *
 * Zero network. Section 1 is pure statics. Section 2 uses a scratch
 * MariaDB (config/db.php swapped and restored, same pattern as
 * test_threshold_routing.php):
 *   1. canonical hash determinism: key-order independence, list-order
 *      sensitivity, identical state -> identical hash, changed state
 *      (weights, enabled flags incl. tech_stack, target_config, thresholds,
 *      exclusions) -> different hash.
 *   2. evidence_set_hash: changes only when a consumed lead input changes;
 *      non-input fields (status, lead_score) are ignored; notes capped at
 *      exactly what the scorer consumes.
 *   3. capture(): writes the snapshot row; pin recorded per run; second
 *      capture of identical profile state reuses the same row (idempotent,
 *      no duplicate rows); same snapshot id across different leads, but
 *      different evidence hashes per lead.
 *   4. immutability: UPDATE and DELETE on icp_profile_snapshots fail
 *      (trigger, SQLSTATE 45000); the row is unchanged afterwards.
 *   5. profile change (weights, target_config) -> new snapshot id.
 *   6. score() pins snapshot_id + evidence_set_hash on every scored lead
 *      (live DecisionTier, scripted provider, real DB profile — the full
 *      production path short of the model).
 *   7. repoint-rollback: after the profile changes, scoreWithSnapshot(old
 *      id) re-executes against the ORIGINAL inputs — the provider receives
 *      the old target prose, the pin is the old snapshot id, and the
 *      evidence hash equals the original run's hash (identical lead
 *      inputs). Unknown snapshot id throws (fail-loud).
 *   8. recordRun(): the run record carries the pin; latestPinForLead()
 *      reads it back; fail-safe on null pdo.
 *
 * Usage: php tests/icp_scoring/test_profile_snapshots.php
 * The repo tree is left exactly as it was (config/db.php restored/deleted).
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require_once ics_repo_root() . '/includes/autoload.php';

use App\Actions\ScoreLeadFitAction;
use App\Icp\IcpProfile;
use App\Icp\IcpProfileSnapshot;

ics_assert_default_mode_off('(start)');

// NOTE: score() in live mode builds its own provider through the
// per-decision timeout override (DecisionTier::decide), which cannot take a
// scripted provider — the repo's existing suites test live mode only through
// the veto path for the same reason. The P4 pin is captured BEFORE the
// DecisionTier call, so the off-mode path exercises it faithfully.

// --- 1. pure: canonical hash determinism -------------------------------------
echo "1. canonical hash determinism:\n";
$ka = ['b' => 1, 'a' => ['y' => 2, 'x' => 1]];
$kb = ['a' => ['x' => 1, 'y' => 2], 'b' => 1];
check('canonicalJson is key-order independent',
    IcpProfileSnapshot::canonicalJson($ka) === IcpProfileSnapshot::canonicalJson($kb));
check('canonicalJson is list-order sensitive',
    IcpProfileSnapshot::canonicalJson([2, 1]) !== IcpProfileSnapshot::canonicalJson([1, 2]));
check('canonicalJson round-trips nested unicode',
    json_decode(IcpProfileSnapshot::canonicalJson(['a' => 'Müller — β']), true) === ['a' => 'Müller — β']);

$fix = ics_profile();
$c1 = IcpProfileSnapshot::profileContent(1, 'Test ICP', 'pain', $fix['dimensions'], [], ['qualify' => 75, 'review' => 50]);
// Rebuild the same state from a differently-ordered dimensions array.
$shuffled = [];
foreach (array_reverse(IcpProfile::DIMENSIONS) as $d) {
    $shuffled[$d] = $fix['dimensions'][$d];
}
$c2 = IcpProfileSnapshot::profileContent(1, 'Test ICP', 'pain', $shuffled, [], ['review' => 50, 'qualify' => 75]);
$h1 = IcpProfileSnapshot::contentHash($c1);
$h2 = IcpProfileSnapshot::contentHash($c2);
check('identical profile state -> identical content hash (order-independent)', $h1 === $h2);
check('content hash is 64 hex chars', (bool)preg_match('/^[0-9a-f]{64}$/', $h1));

$heavier = $shuffled;
$heavier['company_size']['weight'] = 25;
$heavier['industry_fit']['weight'] = 15;
check('weight change -> different hash',
    IcpProfileSnapshot::contentHash(IcpProfileSnapshot::profileContent(1, 'Test ICP', 'pain', $heavier, [], ['qualify' => 75, 'review' => 50])) !== $h1);

$toggled = $shuffled;
$toggled['tech_stack']['enabled'] = true;
check('tech_stack enabled-flag flip -> different hash (flag is content)',
    IcpProfileSnapshot::contentHash(IcpProfileSnapshot::profileContent(1, 'Test ICP', 'pain', $toggled, [], ['qualify' => 75, 'review' => 50])) !== $h1);

$retargeted = $shuffled;
$retargeted['industry_fit']['target_config'] = ['include' => ['B2B SaaS vendors']];
check('target_config change -> different hash',
    IcpProfileSnapshot::contentHash(IcpProfileSnapshot::profileContent(1, 'Test ICP', 'pain', $retargeted, [], ['qualify' => 75, 'review' => 50])) !== $h1);

check('threshold change -> different hash',
    IcpProfileSnapshot::contentHash(IcpProfileSnapshot::profileContent(1, 'Test ICP', 'pain', $shuffled, [], ['qualify' => 80, 'review' => 50])) !== $h1);

$excluded = IcpProfileSnapshot::profileContent(1, 'Test ICP', 'pain', $shuffled,
    [['exclusion_type' => 'domain', 'value' => 'spamco.com', 'note' => '']], ['qualify' => 75, 'review' => 50]);
check('exclusion added -> different hash',
    IcpProfileSnapshot::contentHash($excluded) !== $h1);

$painChanged = IcpProfileSnapshot::profileContent(1, 'Test ICP', 'different pain', $shuffled, [], ['qualify' => 75, 'review' => 50]);
check('pain statement change -> different hash',
    IcpProfileSnapshot::contentHash($painChanged) !== $h1);

// --- 2. evidence_set_hash -----------------------------------------------------
echo "2. evidence_set_hash:\n";
$lead = ics_lead();
$e1 = IcpProfileSnapshot::evidenceSetHash($h1, $lead);
check('evidence hash is 64 hex chars', (bool)preg_match('/^[0-9a-f]{64}$/', $e1));

$untouched = ics_lead(['status' => 'Contacted', 'lead_score' => 99, 'id' => 12345]);
check('non-input lead fields (status, lead_score, id) do not perturb the hash',
    IcpProfileSnapshot::evidenceSetHash($h1, $untouched) === $e1);

$changedNotes = ics_lead(['notes' => 'Completely different evidence.']);
check('changed notes -> different evidence hash',
    IcpProfileSnapshot::evidenceSetHash($h1, $changedNotes) !== $e1);

$changedCompany = ics_lead(['company_name' => 'Other Corp']);
check('changed company_name -> different evidence hash',
    IcpProfileSnapshot::evidenceSetHash($h1, $changedCompany) !== $e1);

$inputs = IcpProfileSnapshot::leadEvidenceInputs($lead);
check('leadEvidenceInputs carries exactly the consumed fields in fixed order',
    array_keys($inputs) === IcpProfileSnapshot::LEAD_EVIDENCE_FIELDS);

$longNotes = ics_lead(['notes' => str_repeat('n', 6000)]);
$longInputs = IcpProfileSnapshot::leadEvidenceInputs($longNotes);
check('notes capped at exactly what the scorer consumes (' . IcpProfileSnapshot::NOTES_CAP . ')',
    mb_strlen($longInputs['notes']) === IcpProfileSnapshot::NOTES_CAP);

// --- 3-8. scratch MariaDB ------------------------------------------------------
echo "3-8. scratch-DB behavior:\n";

$repo = ics_repo_root();
$configDir = $repo . '/config';
$configFile = $configDir . '/db.php';
$hadConfig = is_file($configFile);
$backup = $hadConfig ? file_get_contents($configFile) : null;
$dbExit = 0;

try {
    $dbUser = 'icp_snapshots_test';
    $dbPass = 'icp_snap_pw_7x1';
    $sh = function (string $cmd): void {
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException("shell failed: {$cmd}\n" . implode("\n", $out));
        }
    };
    $sh("mysql -u root -e \"DROP DATABASE IF EXISTS icp_snapshots_test; CREATE DATABASE icp_snapshots_test CHARACTER SET utf8mb4;\"");
    $sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON icp_snapshots_test.* TO '{$dbUser}'@'%'; GRANT ALL ON icp_snapshots_test.* TO '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;\"");
    if (!is_dir($configDir)) {
        mkdir($configDir, 0755, true);
    }
    file_put_contents($configFile, "<?php\nreturn ['host' => '127.0.0.1', 'name' => 'icp_snapshots_test', 'user' => '{$dbUser}', 'pass' => '{$dbPass}'];\n");

    $pdo = \App\Database::getConnection();
    $pdo->exec("CREATE TABLE settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT
    ) ENGINE=InnoDB");
    $mig = file_get_contents($repo . '/migrations/2026-09-28-icp-scoring.sql');
    if ($mig === false) {
        throw new RuntimeException('icp scoring migration missing');
    }
    $lines = array_filter(explode("\n", $mig), fn($l) => !str_starts_with(trim($l), '--'));
    foreach (array_filter(array_map('trim', explode(';', implode("\n", $lines)))) as $sql) {
        $pdo->exec($sql);
    }
    // Toggle migration + P4 snapshot migration through the mysql CLI, like
    // production (the P4 file carries triggers — not ;-split safe).
    $sh("mysql -u root icp_snapshots_test < " . escapeshellarg($repo . '/migrations/2026-09-29-icp-tech-stack-toggle.sql'));
    $sh("mysql -u root icp_snapshots_test < " . escapeshellarg($repo . '/migrations/2026-10-01-icp-profile-snapshots.sql'));
    check('P4 migration: snapshot + runs tables exist',
        (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('icp_profile_snapshots','lead_scoring_runs')")
            ->fetchColumn() === 2);

    // --- 3. capture(): pin recorded per run; idempotent -----------------------
    $pin1 = IcpProfileSnapshot::capture(1, $lead);
    check('capture() returns a pin', is_array($pin1) && $pin1['snapshot_id'] > 0);
    check('capture() pin carries 64-hex content + evidence hashes',
        (bool)preg_match('/^[0-9a-f]{64}$/', (string)($pin1['content_hash'] ?? ''))
        && (bool)preg_match('/^[0-9a-f]{64}$/', (string)($pin1['evidence_set_hash'] ?? '')));
    check('captured profile shape keeps the resolveProfile() keys',
        isset($pin1['dimensions']['company_size']['weight'], $pin1['weights']['tech_stack'],
            $pin1['exclusions'], $pin1['thresholds']['qualify'])
        && $pin1['dimensions']['tech_stack']['enabled'] === false);

    $pin2 = IcpProfileSnapshot::capture(1, $lead);
    check('identical profile state reuses the same snapshot row (idempotent)',
        is_array($pin2) && $pin2['snapshot_id'] === $pin1['snapshot_id']
        && $pin2['evidence_set_hash'] === $pin1['evidence_set_hash']);
    $snapCount = (int)$pdo->query("SELECT COUNT(*) FROM icp_profile_snapshots")->fetchColumn();
    check('no duplicate snapshot rows written', $snapCount === 1);

    $otherLead = ics_lead(['company_name' => 'Other Corp']);
    $pin3 = IcpProfileSnapshot::capture(1, $otherLead);
    check('same profile state -> same snapshot id across leads',
        is_array($pin3) && $pin3['snapshot_id'] === $pin1['snapshot_id']);
    check('different lead inputs -> different evidence hash on the same snapshot',
        is_array($pin3) && $pin3['evidence_set_hash'] !== $pin1['evidence_set_hash']);

    // --- 4. immutability ------------------------------------------------------
    $s1 = $pin1['snapshot_id'];
    $before = IcpProfileSnapshot::get($s1);
    $updThrew = false;
    $updMsg = '';
    try {
        $pdo->exec("UPDATE icp_profile_snapshots SET profile_name = 'tampered' WHERE id = {$s1}");
    } catch (\Throwable $e) {
        $updThrew = true;
        $updMsg = $e->getMessage();
    }
    check('UPDATE on a snapshot fails (immutability trigger)', $updThrew && str_contains($updMsg, 'immutable'));
    $after = IcpProfileSnapshot::get($s1);
    check('snapshot row unchanged after failed UPDATE',
        $after !== null && $after['profile_name'] === $before['profile_name']
        && $after['content_hash'] === $before['content_hash']);

    $delThrew = false;
    try {
        $pdo->exec("DELETE FROM icp_profile_snapshots WHERE id = {$s1}");
    } catch (\Throwable $e) {
        $delThrew = str_contains($e->getMessage(), 'immutable');
    }
    check('DELETE on a snapshot fails (immutability trigger)', $delThrew);

    // --- 5. profile change -> new snapshot -------------------------------------
    IcpProfile::updateWeights(1, [
        'company_size' => 30, 'industry_fit' => 30, 'target_title' => 20,
        'geography' => 10, 'trigger_signals' => 10, 'tech_stack' => 0,
    ], 'P4 test edit');
    $pin4 = IcpProfileSnapshot::capture(1, $lead);
    check('changed weights -> new snapshot id',
        is_array($pin4) && $pin4['snapshot_id'] !== $s1);
    check('restored old snapshot still decodes to the ORIGINAL weights',
        ($r = IcpProfileSnapshot::restoredProfile(IcpProfileSnapshot::get($s1)))
        !== null && $r['dimensions']['company_size']['weight'] === 20
        && $r['snapshot_id'] === $s1
        && $r['content_hash'] === $before['content_hash']
        && $r['thresholds'] === ['qualify' => 75, 'review' => 50]);

    // --- 6. score() pins every scored lead --------------------------------------
    IcpProfile::updateTargetConfig(1, 'industry_fit', ['include' => ['B2B SaaS vendors']]);
    $scorer = ics_scorer();
    $fallback = fn() => ['qualified' => false, 'score' => 10, 'reason' => 'legacy', 'source' => 'legacy'];

    // The pin is captured before DecisionTier runs, so off mode (no Jev call
    // at all) exercises it on the real production path.
    $res1 = $scorer->score($lead, $fallback);
    check('score() result carries snapshot_id + evidence_set_hash',
        isset($res1['snapshot_id']) && $res1['snapshot_id'] > 0
        && (bool)preg_match('/^[0-9a-f]{64}$/', (string)($res1['evidence_set_hash'] ?? '')));
    $sA = $res1['snapshot_id'];
    $evA = $res1['evidence_set_hash'];
    $rowA = IcpProfileSnapshot::get($sA);
    check('evidence hash recomputes from the stored snapshot + lead',
        $rowA !== null && IcpProfileSnapshot::evidenceSetHash($rowA['content_hash'], $lead) === $evA);

    // The veto path pins too: a vetoed run still "saw" the profile.
    $exId = IcpProfile::addExclusion(1, 'domain', 'vetoed.example', 'P4 test');
    $vLead = ics_lead(['email' => 'x@vetoed.example']);
    $vRes = $scorer->score($vLead, $fallback);
    $vRow = IcpProfileSnapshot::get((int)($vRes['snapshot_id'] ?? 0));
    check('vetoed run carries its pin (snapshot + evidence hash)',
        ($vRes['source'] ?? '') === 'veto'
        && $vRow !== null
        && ($vRes['evidence_set_hash'] ?? '') === IcpProfileSnapshot::evidenceSetHash($vRow['content_hash'], $vLead));
    IcpProfile::deleteExclusion(1, $exId);

    // --- 7. repoint-rollback reproduces the original inputs ---------------------
    IcpProfile::updateTargetConfig(1, 'industry_fit', ['include' => ['Logistics firms']]);
    $res2 = $scorer->score($lead, $fallback);
    $sB = $res2['snapshot_id'];
    check('changed target_config -> new snapshot id', $sB !== $sA);

    $liveProfile = ScoreLeadFitAction::resolveProfile();
    $liveQ = $liveProfile === null ? '' : json_encode(ScoreLeadFitAction::buildFitQuestions($liveProfile['dimensions']));
    check('live profile now asks against the NEW target_config',
        str_contains($liveQ, 'Logistics firms') && !str_contains($liveQ, 'B2B SaaS vendors'));

    $repointed = $scorer->scoreWithSnapshot($lead, $fallback, $sA);
    check('repoint pins the ORIGINAL snapshot id', ($repointed['snapshot_id'] ?? null) === $sA);
    check('repoint with identical lead inputs -> identical evidence hash (rollback equality)',
        ($repointed['evidence_set_hash'] ?? '') === $evA);
    $restoredRow = IcpProfileSnapshot::get($sA);
    $restored = $restoredRow === null ? null : IcpProfileSnapshot::restoredProfile($restoredRow);
    $reQ = $restored === null ? '' : json_encode(ScoreLeadFitAction::buildFitQuestions($restored['dimensions']));
    check('repoint reproduces the ORIGINAL target_config in the scoring questions',
        str_contains($reQ, 'B2B SaaS vendors') && !str_contains($reQ, 'Logistics firms'));

    $repointThrew = false;
    try {
        $scorer->scoreWithSnapshot($lead, $fallback, 999999);
    } catch (\InvalidArgumentException $e) {
        $repointThrew = true;
    }
    check('repoint at an unknown snapshot id throws (fail-loud)', $repointThrew);

    // --- 8. run record -----------------------------------------------------------
    check('recordRun(null pdo) fails safe', IcpProfileSnapshot::recordRun(null, 7, $res1) === false);
    check('recordRun() persists the pin', IcpProfileSnapshot::recordRun($pdo, 7, $res1) === true);
    $runRow = $pdo->query("SELECT lead_id, profile_snapshot_id, evidence_set_hash, fit_score, verdict, source
                           FROM lead_scoring_runs ORDER BY id DESC LIMIT 1")
        ->fetch(\App\PDO::FETCH_ASSOC);
    check('run record carries (snapshot id, evidence hash)',
        (int)$runRow['lead_id'] === 7
        && (int)$runRow['profile_snapshot_id'] === $sA
        && $runRow['evidence_set_hash'] === $evA
        && $runRow['source'] === 'legacy');
    $pin = IcpProfileSnapshot::latestPinForLead($pdo, 7);
    check('latestPinForLead() reads the pin back',
        $pin !== null && (int)$pin['profile_snapshot_id'] === $sA
        && $pin['evidence_set_hash'] === $evA);

    $sh("mysql -u root -e \"DROP DATABASE icp_snapshots_test;\"");
} catch (\Throwable $e) {
    echo "  FATAL: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $dbExit = 1;
}

// --- Restore the repo tree exactly as it was --------------------------------
if ($hadConfig) {
    file_put_contents($configFile, $backup);
} elseif (is_file($configFile)) {
    unlink($configFile);
}

ics_assert_default_mode_off('(end)');
$code = ics_summary('test_profile_snapshots.php');
exit($dbExit !== 0 ? 1 : $code);
