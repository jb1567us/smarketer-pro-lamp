#!/usr/bin/env php
<?php
/**
 * Threshold routing + ICP data-model invariants (subjects 1-2/4,
 * goal_67693fcbba4c).
 *
 * Zero network. The routing half is pure statics; the data-model half uses a
 * scratch MariaDB (config/db.php swapped and restored, same pattern as
 * tests/icp/test_adjust_weights_integration.php):
 *   1. Boundary routing: fit 75 -> qualified, 74/50 -> needs_review,
 *      49 -> unqualified (single-dimension weights for exact control).
 *   2. Custom thresholds honored by normalizeJevAnswers.
 *   3. statusForVerdict: the review band maps to the real 'Needs Review'
 *      leads.status ENUM value (fail-closed: 'Needs Review' is in neither
 *      SequenceManager::ELIGIBLE_LEAD_STATUSES nor SENDABLE_LEAD_STATUSES,
 *      so review-band leads can never be enrolled or mailed until a human
 *      approves them to 'Qualified').
 *   4. IcpProfile::thresholds(): fail-closed defaults, clamping, and the
 *      review < qualify repair rule.
 *   5. IcpProfile::updateWeights(): sum-to-100 enforcement (rejects 99 and
 *      101), audit rows only for changed dims, buyer_locked on manual edit,
 *      validation errors before any write.
 *   6. addExclusion/deleteExclusion/updatePainStatement/unlockDimension
 *      validation.
 *
 * Usage: php tests/icp_scoring/test_threshold_routing.php
 * The repo tree is left exactly as it was (config/db.php restored/deleted).
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require_once ics_repo_root() . '/includes/autoload.php';

use App\Actions\QualifyLeadAction;
use App\Actions\ScoreLeadFitAction;
use App\Icp\IcpProfile;

ics_assert_default_mode_live('(start)');

$DIMS = IcpProfile::DIMENSIONS;
$TH = ['qualify' => 75, 'review' => 50];

// --- 1. boundary routing --------------------------------------------------------
echo "1. boundary routing (single-dimension weights for exact fits):\n";
$wSingle = ['company_size' => 100, 'industry_fit' => 0,
            'target_title' => 0, 'geography' => 0, 'trigger_signals' => 0];
// position -> pct: 6.75 -> 75.0, 6.66 -> 74.0, 4.5 -> 50.0, 4.41 -> 49.0
$cases = [
    [6.75, 75, 'qualified', true],
    [6.66, 74, 'needs_review', false],
    [4.5, 50, 'needs_review', false],
    [4.41, 49, 'unqualified', false],
];
foreach ($cases as [$pos, $fit, $verdict, $qualified]) {
    $n = ScoreLeadFitAction::normalizeJevAnswers(ics_dim_answers(['company_size' => $pos]), $wSingle, $TH);
    check("fit {$fit} -> verdict '{$verdict}', qualified=" . var_export($qualified, true),
        $n['fit_score'] === $fit && $n['verdict'] === $verdict && $n['qualified'] === $qualified);
}

// --- 2. custom thresholds --------------------------------------------------------
echo "2. custom thresholds:\n";
$custom = ['qualify' => 80, 'review' => 60];
// 7.11 -> 79.0, 7.2 -> 80.0
$n = ScoreLeadFitAction::normalizeJevAnswers(ics_dim_answers(['company_size' => 7.11]), $wSingle, $custom);
check('fit 79 with qualify=80 -> needs_review', $n['verdict'] === 'needs_review');
$n = ScoreLeadFitAction::normalizeJevAnswers(ics_dim_answers(['company_size' => 7.2]), $wSingle, $custom);
check('fit 80 with qualify=80 -> qualified',
    $n['verdict'] === 'qualified' && $n['qualified'] === true);
$n = ScoreLeadFitAction::normalizeJevAnswers(ics_dim_answers(['company_size' => 5.31]), $wSingle, $custom);
check('fit 59 with review=60 -> unqualified', $n['verdict'] === 'unqualified');
// 5.31 -> 59.0 exactly? 5.31/9*100 = 59.00000000000001 -> round(,1) = 59.0. Yes.

// --- 3. status mapping + review-band marker ---------------------------------------
echo "3. status mapping and review-band marker:\n";
check("qualified -> 'Qualified'",
    QualifyLeadAction::statusForVerdict('qualified') === 'Qualified');
check("needs_review -> 'Needs Review' (real status; allowlist-gated out of sequences)",
    QualifyLeadAction::statusForVerdict('needs_review') === 'Needs Review');
check("unqualified -> 'Unqualified'",
    QualifyLeadAction::statusForVerdict('unqualified') === 'Unqualified');
check("unknown verdict -> 'Unqualified' (fail-closed)",
    QualifyLeadAction::statusForVerdict('bogus') === 'Unqualified');

$date = date('Y-m-d');
$reviewMarker = QualifyLeadAction::notesMarker([
    'source' => 'jev', 'verdict' => 'needs_review', 'fit_score' => 74,
    'profile' => 'Test ICP', 'reason' => 'Jev weighted ICP fit 74/100',
    'dimensions' => array_fill_keys($DIMS, 7),
    'thresholds' => $TH,
], 'needs_review');
check('review-band marker says "Needs Review" with the qualify threshold',
    str_contains($reviewMarker, 'Needs Review (fit 74/100, below qualify threshold 75)'));
check('review-band marker carries no qualified status',
    !str_contains($reviewMarker, ']: Qualified'));

$qualMarker = QualifyLeadAction::notesMarker([
    'source' => 'jev', 'verdict' => 'qualified', 'fit_score' => 75,
    'profile' => 'Test ICP', 'reason' => 'Jev weighted ICP fit 75/100',
    'dimensions' => array_fill_keys($DIMS, 8),
    'thresholds' => $TH,
], 'Qualified');
check('boundary 75 marker reads Qualified (fit 75/100)',
    str_contains($qualMarker, ']: Qualified (fit 75/100'));

// --- 4-6. data-model tests on a scratch MariaDB -----------------------------------
echo "4. IcpProfile::thresholds() fail-closed clamping (scratch DB):\n";

$repo = ics_repo_root();
$configDir = $repo . '/config';
$configFile = $configDir . '/db.php';
$hadConfig = is_file($configFile);
$backup = $hadConfig ? file_get_contents($configFile) : null;
$dbExit = 0;

try {
    $dbUser = 'icp_scoring_test';
    $dbPass = 'icp_scoring_pw_9q2';
    $sh = function (string $cmd): void {
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException("shell failed: {$cmd}\n" . implode("\n", $out));
        }
    };
    $sh("mysql -u root -e \"DROP DATABASE IF EXISTS icp_scoring_test; CREATE DATABASE icp_scoring_test CHARACTER SET utf8mb4;\"");
    $sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON icp_scoring_test.* TO '{$dbUser}'@'%'; GRANT ALL ON icp_scoring_test.* TO '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;\"");
    if (!is_dir($configDir)) {
        mkdir($configDir, 0755, true);
    }
    file_put_contents($configFile, "<?php\nreturn ['host' => '127.0.0.1', 'name' => 'icp_scoring_test', 'user' => '{$dbUser}', 'pass' => '{$dbPass}'];\n");

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
    // The toggle migration (enabled flag + the disabled tech_stack row),
    // applied the realistic way: through the mysql CLI, like production.
    $sh("mysql -u root icp_scoring_test < " . escapeshellarg($repo . '/migrations/2026-09-29-icp-tech-stack-toggle.sql'));
    $tsRow = $pdo->query(
        "SELECT weight, buyer_locked, enabled FROM icp_dimensions
         WHERE profile_id = 1 AND dimension_key = 'tech_stack'"
    )->fetch(\App\PDO::FETCH_ASSOC);
    check('toggle migration: tech_stack row re-added disabled at weight 0',
        $tsRow !== false && (int)$tsRow['weight'] === 0
        && (int)$tsRow['buyer_locked'] === 0 && (int)$tsRow['enabled'] === 0);
    check('toggle migration: the five core dimensions are enabled',
        (int)$pdo->query(
            "SELECT COUNT(*) FROM icp_dimensions
             WHERE profile_id = 1 AND dimension_key <> 'tech_stack' AND enabled = 1"
        )->fetchColumn() === 5);

    // Defaults when rows are missing: fail-closed, never auto-qualify-all.
    $pdo->exec("DELETE FROM settings WHERE setting_key LIKE 'icp_threshold_%'");
    check('missing rows -> fail-closed defaults 75/50',
        IcpProfile::thresholds() === ['qualify' => 75, 'review' => 50]);

    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES
        ('icp_threshold_qualify', '150'), ('icp_threshold_review', '50')
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    check('qualify=150 clamps to 100',
        IcpProfile::thresholds() === ['qualify' => 100, 'review' => 50]);

    $pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'icp_threshold_qualify'");
    check('qualify=0 clamps to 1 (never auto-qualifies everything)',
        IcpProfile::thresholds()['qualify'] === 1);

    $pdo->exec("UPDATE settings SET setting_value = '75' WHERE setting_key = 'icp_threshold_qualify'");
    $pdo->exec("UPDATE settings SET setting_value = '90' WHERE setting_key = 'icp_threshold_review'");
    check('review >= qualify is repaired to qualify - 1',
        IcpProfile::thresholds() === ['qualify' => 75, 'review' => 74]);

    $pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'icp_threshold_review'");
    check('review=0 stays 0', IcpProfile::thresholds() === ['qualify' => 75, 'review' => 0]);

    echo "5. updateWeights() sum-to-100 + audit + buyer lock:\n";
    $good = ['company_size' => 30, 'industry_fit' => 30,
             'target_title' => 20, 'geography' => 10, 'trigger_signals' => 10,
             'tech_stack' => 0];
    IcpProfile::updateWeights(1, $good, 'test edit', 42, true, 'user');
    check('weights persisted', IcpProfile::weights(1) === $good);
    // Scoped to this edit: the toggle migration above also wrote one audit
    // row (created_by 'user', sample_size NULL), which must not be counted.
    $hist = $pdo->query("SELECT dimension_key, old_weight, new_weight, reason, sample_size, created_by
                         FROM icp_weight_history WHERE reason = 'test edit' ORDER BY id")
        ->fetchAll(\App\PDO::FETCH_ASSOC);
    check('one history row per CHANGED dimension (4)',
        count($hist) === 4, 'got ' . count($hist));
    $allUser = true;
    $allSized = true;
    foreach ($hist as $h) {
        $allUser = $allUser && $h['created_by'] === 'user';
        $allSized = $allSized && (int)$h['sample_size'] === 42;
    }
    check('history rows carry reason/sample_size/created_by',
        $allUser && $allSized && str_contains((string)$hist[0]['reason'], 'test edit'));
    $locks = $pdo->query("SELECT dimension_key, buyer_locked FROM icp_dimensions WHERE profile_id = 1")
        ->fetchAll(\App\PDO::FETCH_KEY_PAIR);
    check('manual edit marks touched dimensions buyer_locked',
        count(array_filter($locks)) === 6);

    $histCount = count($hist);
    IcpProfile::updateWeights(1, $good, 'no-op edit', null, true, 'user');
    $hist2 = $pdo->query(
        "SELECT COUNT(*) AS c FROM icp_weight_history WHERE reason IN ('test edit', 'no-op edit')"
    )->fetch(\App\PDO::FETCH_ASSOC);
    check('unchanged vector writes no new history rows',
        (int)$hist2['c'] === $histCount);

    $before = IcpProfile::weights(1);
    $rejected = 0;
    try {
        IcpProfile::updateWeights(1, array_merge($good, ['company_size' => 21]), 'bad');
    } catch (\InvalidArgumentException $e) {
        $rejected = str_contains($e->getMessage(), 'sum to exactly 100') ? 1 : 0;
    }
    check('sum=101 rejected with a clear message', $rejected === 1);
    try {
        IcpProfile::updateWeights(1, ['company_size' => 100], 'bad');
    } catch (\InvalidArgumentException $e) {
        $rejected++;
    }
    check('missing dimensions rejected', $rejected === 2);
    try {
        IcpProfile::updateWeights(1, array_merge($good, ['bogus_dim' => 0]), 'bad');
    } catch (\InvalidArgumentException $e) {
        $rejected++;
    }
    check('unknown dimension rejected', $rejected === 3);
    try {
        IcpProfile::updateWeights(1, array_merge($good, ['company_size' => -5, 'industry_fit' => 25]), 'bad');
    } catch (\InvalidArgumentException $e) {
        $rejected++;
    }
    check('out-of-range weight rejected', $rejected === 4);
    check('rejected updates leave weights untouched', IcpProfile::weights(1) === $before);

    IcpProfile::unlockDimension(1, 'company_size');
    $locks = $pdo->query("SELECT dimension_key, buyer_locked FROM icp_dimensions WHERE profile_id = 1")
        ->fetchAll(\App\PDO::FETCH_KEY_PAIR);
    check('unlockDimension clears one lock',
        (int)$locks['company_size'] === 0 && (int)$locks['industry_fit'] === 1);
    $threw = false;
    try {
        IcpProfile::unlockDimension(1, 'nope');
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    check('unlockDimension rejects unknown dimension', $threw);

    echo "6. exclusions + pain statement validation:\n";
    $exId = IcpProfile::addExclusion(1, 'domain', 'spamco.com', 'test');
    check('addExclusion returns an id', $exId > 0);
    $dup = false;
    try {
        IcpProfile::addExclusion(1, 'domain', 'spamco.com');
    } catch (\InvalidArgumentException $e) {
        $dup = str_contains($e->getMessage(), 'already on the list');
    }
    check('duplicate exclusion rejected cleanly', $dup);
    $badType = false;
    try {
        IcpProfile::addExclusion(1, 'planet', 'mars');
    } catch (\InvalidArgumentException $e) {
        $badType = true;
    }
    check('unknown exclusion_type rejected', $badType);
    IcpProfile::deleteExclusion(1, $exId);
    $gone = $pdo->query("SELECT COUNT(*) AS c FROM icp_exclusions WHERE id = {$exId}")
        ->fetch(\App\PDO::FETCH_ASSOC);
    check('deleteExclusion removes the row', (int)$gone['c'] === 0);
    $missing = false;
    try {
        IcpProfile::deleteExclusion(1, $exId);
    } catch (\InvalidArgumentException $e) {
        $missing = true;
    }
    check('deleteExclusion on missing id throws', $missing);

    $painBad = 0;
    try {
        IcpProfile::updatePainStatement(1, '   ');
    } catch (\InvalidArgumentException $e) {
        $painBad++;
    }
    try {
        IcpProfile::updatePainStatement(1, str_repeat('x', 501));
    } catch (\InvalidArgumentException $e) {
        $painBad++;
    }
    check('blank and over-long pain statements rejected', $painBad === 2);
    IcpProfile::updatePainStatement(1, 'Agencies losing pipeline to slow follow-up.');
    $row = IcpProfile::get(1);
    check('valid pain statement persisted',
        $row !== null && $row['pain_statement'] === 'Agencies losing pipeline to slow follow-up.');

    $sh("mysql -u root -e \"DROP DATABASE icp_scoring_test;\"");
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

ics_assert_default_mode_live('(end)');
$code = ics_summary('test_threshold_routing.php');
exit($dbExit !== 0 ? 1 : $code);
