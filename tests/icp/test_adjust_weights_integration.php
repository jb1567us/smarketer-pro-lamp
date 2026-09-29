#!/usr/bin/env php
<?php
/**
 * Integration tests for AdjustIcpWeightsAction::run() / resetToDefaults()
 * against a scratch MariaDB database.
 *
 * Applies subject 1's migrations/2026-09-28-icp-scoring.sql for real, seeds
 * 12 leads with this install's own engagement timeline (sequence_events) and
 * per-dimension scores, then exercises the full loop:
 *
 *   F1. adjustment pass: predictive dimension nudged up, sum stays 100,
 *       icp_weight_history row with reason + sample_size + created_by.
 *   F2. buyer_locked dimension is never touched; all-zero-signal dims
 *       produce a "skipped" trace instead of churn.
 *   F3. engaged-lead sample < 10 -> skipped, weights unchanged.
 *   F4. no active ICP profile -> skipped.
 *   F5. resetToDefaults(): unlocked dims back to defaults, locked kept,
 *       history row with created_by='user'.
 *   F6. fallback path (leads with no Dimensions markers): coarse evidence flags
 *       from lead fields + target_config still drive a sane adjustment.
 *
 * NOTE on per-dimension scores: subject 2's ScoreLeadFitAction persists them
 * in leads.notes via QualifyLeadAction::notesMarker() as
 * "Dimensions: company_size=8/10, industry_fit=7/10, ..." (see
 * AdjustIcpWeightsAction::parseNotesDimensionScores). This test seeds that
 * exact format. If subject 2 changes the marker format, update the parser
 * there and the seeding here.
 *
 * Usage: php tests/icp/test_adjust_weights_integration.php
 * The repo tree is left exactly as it was (config/db.php restored/deleted).
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
$configDir = $repo . '/config';
$configFile = $configDir . '/db.php';
$hadConfig = is_file($configFile);
$backup = $hadConfig ? file_get_contents($configFile) : null;

$pass = 0;
$fail = 0;
function ok(bool $cond, string $name): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS: {$name}\n";
    } else {
        $fail++;
        echo "  FAIL: {$name}\n";
    }
}

try {
    $dbUser = 'icp_test';
    $dbPass = 'icp_test_pw_7k3';
    $sh = function (string $cmd): void {
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException("shell failed: {$cmd}\n" . implode("\n", $out));
        }
    };
    $sh("mysql -u root -e \"DROP DATABASE IF EXISTS icp_test; CREATE DATABASE icp_test CHARACTER SET utf8mb4;\"");
    $sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON icp_test.* TO '{$dbUser}'@'%'; FLUSH PRIVILEGES;\"");

    if (!is_dir($configDir)) {
        mkdir($configDir, 0755, true);
    }
    file_put_contents($configFile, "<?php\nreturn ['host' => '127.0.0.1', 'name' => 'icp_test', 'user' => '{$dbUser}', 'pass' => '{$dbPass}'];\n");

    require $repo . '/includes/autoload.php';
    $pdo = \App\Database::getConnection();

    // --- Schema -----------------------------------------------------------
    // settings first: the icp migration INSERTs threshold rows into it.
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
    $pdo->exec("CREATE TABLE leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_name VARCHAR(255) NOT NULL,
        contact_name VARCHAR(255),
        email VARCHAR(255) UNIQUE NOT NULL,
        country_code CHAR(2) NULL,
        target_persona VARCHAR(255) NULL,
        notes TEXT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE sequence_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        lead_id INT NULL,
        send_id INT NULL,
        event_type VARCHAR(48) NOT NULL,
        detail TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_evt_lead (lead_id, created_at)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE agent_traces (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NULL,
        persona VARCHAR(100),
        goal TEXT,
        context TEXT,
        reasoning_output TEXT,
        operational_mode ENUM('Production', 'Simulation') DEFAULT 'Production',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $active = \App\Icp\IcpProfile::active();
    ok($active !== null && (int)$active['id'] === 1, 'seeded default profile is active');
    $seedWeights = \App\Icp\IcpProfile::weights(1);
    ok(array_sum($seedWeights) === 100, 'seeded weights sum to 100: ' . json_encode($seedWeights));

    // --- Seed: 12 scored leads --------------------------------------------
    // company_size score correlates with engagement (9/10 -> positive reply,
    // 1/10 -> unsubscribe); every other dimension is constant (zero variance).
    // Scores use subject 2's real notes-marker format.
    $pdo->exec("INSERT INTO leads (company_name, contact_name, email) VALUES
        ('C1','N1','l1@t.test'),('C2','N2','l2@t.test'),('C3','N3','l3@t.test'),
        ('C4','N4','l4@t.test'),('C5','N5','l5@t.test'),('C6','N6','l6@t.test'),
        ('C7','N7','l7@t.test'),('C8','N8','l8@t.test'),('C9','N9','l9@t.test'),
        ('C10','N10','l10@t.test'),('C11','N11','l11@t.test'),('C12','N12','l12@t.test')");
    $sizeScore = [1 => 9, 2 => 9, 3 => 9, 4 => 9, 5 => 9, 6 => 9,
                  7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 5, 12 => 9];
    $notesStmt = $pdo->prepare("UPDATE leads SET notes = ? WHERE id = ?");
    for ($lid = 1; $lid <= 12; $lid++) {
        $s = $sizeScore[$lid];
        $notesStmt->execute([
            "\n\n[Qualification 2026-09-28]: Qualified (fit 70/100, ICP \"Default ICP\") — test seed\n" .
            "Dimensions: company_size={$s}/10, industry_fit=5/10, tech_stack=5/10, " .
            "target_title=5/10, geography=5/10, trigger_signals=5/10.",
            $lid,
        ]);
    }
    $verdict = fn(string $intent): string => 'Phase-3 verdict: ' . json_encode([
        'intent' => $intent, 'needs_human' => true, 'urgency' => 8,
        'confidence' => 0.9, 'source' => 'jev', 'latency_ms' => 100,
    ]);
    $evStmt = $pdo->prepare(
        "INSERT INTO sequence_events (campaign_id, lead_id, event_type, detail) VALUES (1, ?, ?, ?)"
    );
    for ($lid = 1; $lid <= 6; $lid++) {
        $evStmt->execute([$lid, 'replied', 'Inbound reply']);
        $evStmt->execute([$lid, 'classified', $verdict('positive')]);
    }
    for ($lid = 7; $lid <= 10; $lid++) {
        $evStmt->execute([$lid, 'replied', 'Inbound reply']);
        $evStmt->execute([$lid, 'classified', $verdict('unsubscribe')]);
    }
    $evStmt->execute([11, 'replied', 'Inbound reply']);
    $evStmt->execute([11, 'classified', $verdict('objection')]);
    $evStmt->execute([12, 'opened', 'Open tracked']);

    $action = new \App\Actions\AdjustIcpWeightsAction($pdo);

    // --- F1. adjustment pass ------------------------------------------------
    echo "F1. adjustment pass:\n";
    $res = $action->run();
    ok($res['status'] === 'adjusted', 'run() adjusted: ' . ($res['detail'] ?? ''));
    ok($res['sample_size'] === 12, 'sample_size=12, got ' . $res['sample_size']);
    ok($res['engaged_leads'] === 12, 'engaged_leads=12, got ' . $res['engaged_leads']);
    $newW = \App\Icp\IcpProfile::weights(1);
    ok($newW['company_size'] > 17, 'predictive dim nudged up: ' . json_encode($newW));
    ok(array_sum($newW) === 100, 'weights still sum to 100');
    ok($newW['company_size'] - 17 <= 5, 'nudge within +/-5 per run');
    $hist = $pdo->query(
        "SELECT dimension_key, old_weight, new_weight, reason, sample_size, created_by
         FROM icp_weight_history ORDER BY id"
    )->fetchAll(\App\PDO::FETCH_ASSOC);
    ok(count($hist) > 0, 'history rows recorded');
    $sizeRow = null;
    foreach ($hist as $h) {
        if ($h['dimension_key'] === 'company_size') {
            $sizeRow = $h;
        }
        ok($h['created_by'] === 'auto_tuner', 'history created_by=auto_tuner');
        ok((int)$h['sample_size'] === 12, 'history sample_size=12');
        ok(str_contains((string)$h['reason'], 'engagement feedback'), 'history reason recorded');
    }
    ok(
        $sizeRow !== null && (int)$sizeRow['old_weight'] === 17 && (int)$sizeRow['new_weight'] === $newW['company_size'],
        'history row reversible (old/new weights)'
    );

    // --- F2. buyer_locked respected ----------------------------------------
    echo "F2. buyer lock:\n";
    $pdo->exec("UPDATE icp_dimensions SET buyer_locked = 1 WHERE profile_id = 1 AND dimension_key = 'company_size'");
    $before = \App\Icp\IcpProfile::weights(1);
    $res = $action->run();
    ok($res['status'] === 'skipped', 'run() skipped (no unlocked signal): ' . ($res['detail'] ?? ''));
    $after = \App\Icp\IcpProfile::weights(1);
    ok($after === $before, 'weights unchanged while locked');
    ok($after['company_size'] === $before['company_size'], 'locked dim NEVER touched');
    $skipTrace = $pdo->query(
        "SELECT reasoning_output FROM agent_traces
         WHERE persona = 'AdjustIcpWeights' AND goal = 'icp_weight_skip'
         ORDER BY id DESC LIMIT 1"
    )->fetch(\App\PDO::FETCH_ASSOC);
    ok(
        $skipTrace !== false && str_starts_with((string)$skipTrace['reasoning_output'], 'skipped:'),
        'skip trace written to agent_traces'
    );

    // --- F3. sample floor ----------------------------------------------------
    echo "F3. sample floor:\n";
    $pdo->exec("UPDATE icp_dimensions SET buyer_locked = 0 WHERE profile_id = 1");
    $pdo->exec("DELETE FROM sequence_events WHERE lead_id > 4"); // 4 engaged left
    $before = \App\Icp\IcpProfile::weights(1);
    $res = $action->run();
    ok($res['status'] === 'skipped', 'run() skipped below floor: ' . ($res['detail'] ?? ''));
    ok(str_contains((string)$res['detail'], 'below floor'), 'skip reason names the floor');
    ok(\App\Icp\IcpProfile::weights(1) === $before, 'weights unchanged below floor');

    // --- F4. no active profile ----------------------------------------------
    echo "F4. no active profile:\n";
    $pdo->exec("UPDATE icp_profiles SET is_active = 0 WHERE id = 1");
    $res = $action->run();
    ok($res['status'] === 'skipped', 'run() skipped with no active profile');
    ok(str_contains((string)$res['detail'], 'no active ICP profile'), 'skip reason names the profile');
    $pdo->exec("UPDATE icp_profiles SET is_active = 1 WHERE id = 1");

    // --- F5. reset to defaults ------------------------------------------------
    echo "F5. resetToDefaults:\n";
    $pdo->exec("UPDATE icp_dimensions SET buyer_locked = 1 WHERE profile_id = 1 AND dimension_key = 'geography'");
    $geoBefore = \App\Icp\IcpProfile::weights(1)['geography'];
    $res = $action->resetToDefaults();
    ok($res['status'] === 'adjusted', 'reset adjusted: ' . ($res['detail'] ?? ''));
    $rw = \App\Icp\IcpProfile::weights(1);
    ok($rw['geography'] === $geoBefore, 'locked dim survives reset (buyer override wins)');
    ok(array_sum($rw) === 100, 'reset weights sum to 100: ' . json_encode($rw));
    $resetHist = $pdo->query(
        "SELECT created_by, reason FROM icp_weight_history ORDER BY id DESC LIMIT 1"
    )->fetch(\App\PDO::FETCH_ASSOC);
    ok(
        $resetHist !== false && $resetHist['created_by'] === 'user'
        && str_contains((string)$resetHist['reason'], 'reset to defaults'),
        'reset recorded in history as user'
    );

    // --- F6. fallback: leads with no Dimensions markers ------------------------
    echo "F6. field-derivation fallback:\n";
    $pdo->exec("UPDATE icp_dimensions SET buyer_locked = 0 WHERE profile_id = 1");
    $action->resetToDefaults(); // back to a known vector
    // Leads 13-24 carry NO qualification markers -> the field-derivation
    // fallback is the only score source. Their timeline is the only
    // engagement data left, and leads 1-12 lose their markers too so the
    // fallback is exercised in pure form.
    $pdo->exec("UPDATE leads SET notes = NULL WHERE id <= 12");
    $pdo->exec("INSERT INTO leads (company_name, contact_name, email) VALUES
        ('C13','N13','l13@t.test'),('C14','N14','l14@t.test'),('C15','N15','l15@t.test'),
        ('C16','N16','l16@t.test'),('C17','N17','l17@t.test'),('C18','N18','l18@t.test'),
        ('C19','N19','l19@t.test'),('C20','N20','l20@t.test'),('C21','N21','l21@t.test'),
        ('C22','N22','l22@t.test'),('C23','N23','l23@t.test'),('C24','N24','l24@t.test')");
    $pdo->exec("DELETE FROM sequence_events WHERE lead_id <= 12");
    for ($lid = 13; $lid <= 18; $lid++) {
        $evStmt->execute([$lid, 'replied', 'Inbound reply']);
        $evStmt->execute([$lid, 'classified', $verdict('positive')]);
    }
    for ($lid = 19; $lid <= 22; $lid++) {
        $evStmt->execute([$lid, 'replied', 'Inbound reply']);
        $evStmt->execute([$lid, 'classified', $verdict('unsubscribe')]);
    }
    $evStmt->execute([23, 'replied', 'Inbound reply']);
    $evStmt->execute([23, 'classified', $verdict('objection')]);
    $evStmt->execute([24, 'opened', 'Open tracked']);
    // Geography evidence: engaged leads are US, unsubscribed are DE.
    $pdo->exec("UPDATE leads SET country_code = 'US' WHERE id IN (13,14,15,16,17,18,23,24)");
    $pdo->exec("UPDATE leads SET country_code = 'DE' WHERE id IN (19,20,21,22)");
    $pdo->exec("UPDATE icp_dimensions SET target_config = '{\"countries\":[\"us\"],\"regions\":[]}'
                WHERE profile_id = 1 AND dimension_key = 'geography'");
    $before = \App\Icp\IcpProfile::weights(1);
    $res = $action->run();
    ok($res['status'] === 'adjusted', 'fallback run adjusted: ' . ($res['detail'] ?? ''));
    $fw = \App\Icp\IcpProfile::weights(1);
    ok($fw['geography'] > $before['geography'], 'geography nudged up from field evidence: ' . json_encode($fw));
    ok(array_sum($fw) === 100, 'fallback weights sum to 100');
    ok(
        $res['correlations']['tech_stack'] === null,
        'no-evidence dim has null correlation (no churn)'
    );

    echo "  -- test_adjust_weights_integration: {$pass} pass, {$fail} fail\n";
    $exit = $fail === 0 ? 0 : 1;
} catch (\Throwable $e) {
    echo "  FATAL: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $exit = 1;
}

// --- Restore the repo tree exactly as it was --------------------------------
if ($hadConfig) {
    file_put_contents($configFile, $backup);
} elseif (is_file($configFile)) {
    unlink($configFile);
}
exit($exit ?? 1);
