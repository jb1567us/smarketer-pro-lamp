#!/usr/bin/env php
<?php
/**
 * needs_review eligibility regression tests (subject 2, goal_67693fcbba4c).
 *
 * The 50-75 ICP fit band now persists the real 'Needs Review' leads.status
 * value (per the team contract; subject 1 adds it to the ENUM). These tests
 * prove end to end, on a scratch MariaDB, that needs_review leads are
 * ineligible for sequences at EVERY stage:
 *
 *   A. Launch: a needs_review lead is never enrolled (skipped_status++),
 *      gets no sequence_enrollments row and no SequenceSend task. Qualified
 *      controls enroll normally.
 *   B. Send-time gate: a lead enrolled while Qualified, then flipped to
 *      needs_review before its queued send runs, is SKIPPED by
 *      SendSequenceStepAction — the send lands in 'skipped' and nothing is
 *      logged to email_logs (simulated mode would otherwise log a
 *      safety_simulated row). A needs_review lead enrolled by hand (defense
 *      in depth) is skipped the same way. A Contacted control lead still
 *      sends, proving the allowlist doesn't over-block in-flight sequences.
 *   C. Approval is the only path: the model-level transition
 *      needs_review -> 'Qualified' (what subject 3's approval UI/API drives)
 *      makes the lead enrollable at launch AND sendable at send time; while
 *      needs_review it is eligible nowhere.
 *   D. Post-send bookkeeping is fail-closed: SequenceManager::sendSucceeded
 *      never re-marks a needs_review lead as 'Contacted' (which would
 *      re-admit it to future sends) and never schedules its next step.
 *
 * Zero network, zero real sends (operational_mode='simulated').
 * Leads use status VARCHAR(50) — same as tests/phase4/test_sequences.php —
 * so the suite exercises the 'Needs Review' contract value without depending
 * on subject 1's ENUM migration being present in the tree.
 *
 * Usage: php tests/phase4_jev/test_needs_review_eligibility.php
 * The repo tree is left exactly as it was (config/db.php restored/deleted).
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
$configDir = $repo . '/config';
$configFile = $configDir . '/db.php';
$hadConfig = is_file($configFile);
$backup = $hadConfig ? file_get_contents($configFile) : null;

$failures = 0; $passed = 0;
function nr_ok(bool $cond, string $name): void {
    global $failures, $passed;
    if ($cond) { $passed++; echo "  PASS: {$name}\n"; }
    else { $failures++; echo "  FAIL: {$name}\n"; }
}

try {
    $dbUser = 'nr_test'; $dbPass = 'nr_test_pw_7k2';
    $sh = function (string $cmd): void {
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) { throw new RuntimeException("shell failed: {$cmd}\n" . implode("\n", $out)); }
    };
    $sh("mysql -u root -e \"DROP DATABASE IF EXISTS phase4_jev_nr_test; CREATE DATABASE phase4_jev_nr_test CHARACTER SET utf8mb4;\"");
    $sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON phase4_jev_nr_test.* TO '{$dbUser}'@'%'; GRANT ALL ON phase4_jev_nr_test.* TO '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;\"");

    if (!is_dir($configDir)) { mkdir($configDir, 0755, true); }
    file_put_contents($configFile, "<?php\nreturn ['host' => '127.0.0.1', 'name' => 'phase4_jev_nr_test', 'user' => '{$dbUser}', 'pass' => '{$dbPass}'];\n");

    require $repo . '/includes/autoload.php';
    $pdo = \App\Database::getConnection();
    \App\SequenceManager::resetReadyCache();

    // Minimal schema (same shape as tests/phase4/test_sequences.php).
    $pdo->exec("CREATE TABLE settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE campaigns (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL DEFAULT 'active') ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE templates (id INT AUTO_INCREMENT PRIMARY KEY, campaign_id INT NOT NULL, subject VARCHAR(255), body TEXT, step_order INT DEFAULT 1) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE leads (id INT AUTO_INCREMENT PRIMARY KEY, company_name VARCHAR(255) NOT NULL, contact_name VARCHAR(255), email VARCHAR(255) UNIQUE NOT NULL, status VARCHAR(50) DEFAULT 'New', campaign_id INT NULL, country_code CHAR(2) NULL, consent_status VARCHAR(20) NOT NULL DEFAULT 'unknown', notes TEXT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE task_queue (id INT AUTO_INCREMENT PRIMARY KEY, lead_id INT, task_type ENUM('Enrichment','EmailOutreach','SocialOutreach','Qualify','Enrich','Draft') NOT NULL, payload JSON, status ENUM('Pending','In Progress','Completed','Failed') DEFAULT 'Pending', scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, processed_at TIMESTAMP NULL, retry_count INT DEFAULT 0, error_message TEXT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE suppression_list (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NULL, reason VARCHAR(50) NOT NULL DEFAULT 'unsubscribe', source VARCHAR(100) NULL, UNIQUE KEY uq_suppression_email (email)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE email_logs (id INT AUTO_INCREMENT PRIMARY KEY, lead_email VARCHAR(255) NOT NULL, provider_id VARCHAR(50) NOT NULL, campaign_id INT NULL, status ENUM('sent','failed','queued','bounced') DEFAULT 'sent', metadata_json TEXT, timestamp INT NOT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE casl_decisions (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL, country_code CHAR(2) NULL, decision ENUM('allow','block') NOT NULL, rule VARCHAR(100) NOT NULL) ENGINE=InnoDB");

    // Phase-4 sequence tables (idempotent migration).
    $mig = file_get_contents($repo . '/migrations/2026-09-28-phase4-sequences.sql');
    if ($mig === false) { throw new RuntimeException('phase-4 migration missing'); }
    $lines = array_filter(explode("\n", $mig), fn($l) => !str_starts_with(trim($l), '--'));
    foreach (array_filter(array_map('trim', explode(';', implode("\n", $lines)))) as $sql) {
        $pdo->exec($sql);
    }

    // Seed.
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES
        ('operational_mode', 'simulated'),
        ('company_legal_name', 'NR Test Co'),
        ('physical_address', '1 Test Way, Austin TX 78701'),
        ('app_base_url', 'https://example.test')");
    $pdo->exec("INSERT INTO campaigns (name) VALUES ('NR Campaign')");
    $pdo->exec("INSERT INTO templates (campaign_id, subject, body, step_order, delay_days) VALUES
        (1, 'NR step 1', '<p>Hello {{contact_name}}</p>', 1, 1),
        (1, 'NR step 2', '<p>Follow-up for {{company_name}}</p>', 2, 1)");
    // Lead 1: needs_review (the 50-75 band). Lead 2: Qualified control.
    // Lead 3: enrolled while Qualified, then flipped to needs_review before
    //         its queued send runs (mid-flight re-qualification).
    // Lead 4: Contacted — an in-flight sequence must still send (control).
    $pdo->exec("INSERT INTO leads (company_name, contact_name, email, status, campaign_id, country_code, consent_status) VALUES
        ('NR Review', 'Rita Review', 'rita@nr.test', 'Needs Review', 1, 'US', 'express'),
        ('NR Qual', 'Quinn Qual', 'quinn@nr.test', 'Qualified', 1, 'US', 'express'),
        ('NR Flip', 'Felix Flip', 'felix@nr.test', 'Qualified', 1, 'US', 'express'),
        ('NR Contacted', 'Cara Contacted', 'cara@nr.test', 'Contacted', 1, 'US', 'express')");

    $router = new \App\Routers\SmartLLMRouter($pdo); // constructs with no side effects
    $processor = new \App\Domain\TaskProcessor($pdo, $router);
    $launcher = new \App\Actions\LaunchCampaignAction($pdo);

    // Static allowlist invariants (fail-closed by construction).
    echo "0. Allowlist invariants:\n";
    nr_ok(!in_array('Needs Review', \App\SequenceManager::SENDABLE_LEAD_STATUSES, true),
        "SENDABLE_LEAD_STATUSES excludes 'Needs Review'");
    $elig = (new ReflectionClass(\App\SequenceManager::class))->getConstant('ELIGIBLE_LEAD_STATUSES');
    nr_ok(is_array($elig) && !in_array('Needs Review', $elig, true),
        "ELIGIBLE_LEAD_STATUSES excludes 'Needs Review'");
    nr_ok(in_array('Contacted', \App\SequenceManager::SENDABLE_LEAD_STATUSES, true),
        "SENDABLE_LEAD_STATUSES keeps 'Contacted' (in-flight sequences still send)");

    // --- A. launch never enrolls needs_review --------------------------------
    echo "A. Launch eligibility:\n";
    $res = $launcher->launch(1);
    nr_ok($res['enrolled'] === 2, '2 eligible leads enrolled (needs_review + Contacted excluded)');
    nr_ok($res['skipped_status'] === 2, 'needs_review and Contacted leads counted as skipped_status');
    $enr = $pdo->query("SELECT COUNT(*) c FROM sequence_enrollments WHERE lead_id = 1")->fetch(\App\PDO::FETCH_ASSOC);
    nr_ok((int)$enr['c'] === 0, 'needs_review lead has no enrollment row');
    $tq = $pdo->query("SELECT COUNT(*) c FROM task_queue WHERE lead_id = 1 AND task_type = 'SequenceSend'")->fetch(\App\PDO::FETCH_ASSOC);
    nr_ok((int)$tq['c'] === 0, 'needs_review lead has no queued SequenceSend task');
    $enr2 = $pdo->query("SELECT COUNT(*) c FROM sequence_enrollments WHERE lead_id = 2 AND status = 'active'")->fetch(\App\PDO::FETCH_ASSOC);
    nr_ok((int)$enr2['c'] === 1, 'Qualified control lead enrolled');

    // Helper: hand-roll an enrollment + queued step-1 send + task row.
    $queueSend = function (int $leadId, int $campaignId = 1) use ($pdo): int {
        $row = $pdo->query(
            "SELECT id FROM sequence_enrollments WHERE campaign_id = {$campaignId} AND lead_id = {$leadId}"
        )->fetch(\App\PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->exec(
                "INSERT INTO sequence_enrollments (campaign_id, lead_id, status, current_step) " .
                "VALUES ({$campaignId}, {$leadId}, 'active', 1)"
            );
            $enrollmentId = (int)$pdo->lastInsertId();
        } else {
            $enrollmentId = (int)$row['id'];
        }
        $tpl = $pdo->query(
            "SELECT id FROM templates WHERE campaign_id = {$campaignId} AND step_order = 1"
        )->fetch(\App\PDO::FETCH_ASSOC);
        $token = bin2hex(random_bytes(32));
        $pdo->prepare(
            "INSERT INTO sequence_sends (enrollment_id, campaign_id, lead_id, template_id, step_order, " .
            "status, scheduled_at, track_token) VALUES (?, ?, ?, ?, 1, 'queued', NOW(), ?)"
        )->execute([$enrollmentId, $campaignId, $leadId, (int)$tpl['id'], $token]);
        $sendId = (int)$pdo->lastInsertId();
        $payload = json_encode([
            'sequence_send_id' => $sendId, 'campaign_id' => $campaignId,
            'lead_id' => $leadId, 'step_order' => 1,
        ]);
        $pdo->prepare(
            "INSERT INTO task_queue (lead_id, task_type, payload, status, scheduled_at) " .
            "VALUES (?, 'SequenceSend', ?, 'Pending', NOW())"
        )->execute([$leadId, $payload]);
        $taskId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE sequence_sends SET task_id = ? WHERE id = ?")->execute([$taskId, $sendId]);
        return $taskId;
    };
    $sendStatus = function (int $leadId, int $campaignId = 1) use ($pdo): array {
        return $pdo->query(
            "SELECT status, error_message FROM sequence_sends " .
            "WHERE lead_id = {$leadId} AND campaign_id = {$campaignId} AND step_order = 1 " .
            "ORDER BY id DESC LIMIT 1"
        )->fetch(\App\PDO::FETCH_ASSOC);
    };

    // --- B. send-time gate ----------------------------------------------------
    echo "B. Send-time gate:\n";
    // Felix flips to needs_review AFTER enrollment, before his queued send runs.
    $pdo->exec("UPDATE leads SET status = 'Needs Review' WHERE id = 3");
    $felixTask = (int)$pdo->query(
        "SELECT id FROM task_queue WHERE lead_id = 3 AND task_type = 'SequenceSend' AND status = 'Pending'"
    )->fetch(\App\PDO::FETCH_ASSOC)['id'];
    // Rita (needs_review) is enrolled by hand — defense in depth: even a
    // wrongly-created enrollment must never send.
    $ritaTask = $queueSend(1);
    // Cara (Contacted): in-flight sequence control — must still send.
    $caraTask = $queueSend(4);
    $processor->processTask($felixTask);
    $processor->processTask($ritaTask);
    $processor->processTask($caraTask);

    $fs = $sendStatus(3);
    nr_ok($fs['status'] === 'skipped', 'mid-flight flip to needs_review: send skipped');
    nr_ok(str_contains((string)$fs['error_message'], 'Needs Review'), 'skip reason names the needs_review status');
    $rs = $sendStatus(1);
    nr_ok($rs['status'] === 'skipped', 'hand-enrolled needs_review lead: send skipped');
    $logs = $pdo->query(
        "SELECT COUNT(*) c FROM email_logs WHERE lead_email IN ('felix@nr.test', 'rita@nr.test')"
    )->fetch(\App\PDO::FETCH_ASSOC);
    nr_ok((int)$logs['c'] === 0, 'no email_logs row for either needs_review lead (nothing sent, nothing simulated)');
    $cs = $sendStatus(4);
    nr_ok($cs['status'] === 'simulated', 'Contacted control lead still sends (simulated)');
    $clog = $pdo->query(
        "SELECT provider_id FROM email_logs WHERE lead_email = 'cara@nr.test'"
    )->fetch(\App\PDO::FETCH_ASSOC);
    nr_ok($clog && $clog['provider_id'] === 'safety_simulated', 'Contacted control logged as safety_simulated');
    $cstep2 = $pdo->query(
        "SELECT COUNT(*) c FROM sequence_sends WHERE lead_id = 4 AND step_order = 2"
    )->fetch(\App\PDO::FETCH_ASSOC);
    nr_ok((int)$cstep2['c'] === 1, 'Contacted control: step 2 still scheduled (allowlist is not over-broad)');

    // --- C. approval (needs_review -> Qualified) is the only path ----------------
    echo "C. Approval path:\n";
    // Model-level approval transition — this is what subject 3's approval
    // UI/API will drive (api/leads.php update_status to 'Qualified').
    $pdo->exec("UPDATE leads SET status = 'Qualified' WHERE id = 1");
    $pdo->exec("INSERT INTO campaigns (name) VALUES ('NR Campaign 2')");
    $pdo->exec("INSERT INTO templates (campaign_id, subject, body, step_order) VALUES (2, 'C2S1', '<p>Hi</p>', 1), (2, 'C2S2', '<p>Bye</p>', 2)");
    $pdo->exec("UPDATE leads SET campaign_id = 2 WHERE id = 1");
    $resC = $launcher->launch(2);
    nr_ok($resC['enrolled'] === 1 && $resC['skipped_status'] === 0,
        'approved (Qualified) lead enrolls on a fresh campaign');
    $taskRita2 = (int)$pdo->query(
        "SELECT id FROM task_queue WHERE lead_id = 1 AND task_type = 'SequenceSend' AND status = 'Pending' " .
        "ORDER BY id DESC LIMIT 1"
    )->fetch(\App\PDO::FETCH_ASSOC)['id'];
    $processor->processTask($taskRita2);
    $rs2 = $sendStatus(1, 2);
    nr_ok($rs2['status'] === 'simulated', 'approved lead sends after approval (simulated)');
    $rlogs = $pdo->query(
        "SELECT COUNT(*) c FROM email_logs WHERE lead_email = 'rita@nr.test'"
    )->fetch(\App\PDO::FETCH_ASSOC);
    nr_ok((int)$rlogs['c'] === 1,
        'approved lead logged exactly one simulated send (zero while needs_review)');
    // And re-qualification revokes it again: flip back to needs_review, the
    // already-queued step 2 must be skipped, never sent.
    $pdo->exec("UPDATE leads SET status = 'Needs Review' WHERE id = 1");
    $taskRita3 = (int)$pdo->query(
        "SELECT id FROM task_queue WHERE lead_id = 1 AND task_type = 'SequenceSend' AND status = 'Pending' " .
        "ORDER BY id DESC LIMIT 1"
    )->fetch(\App\PDO::FETCH_ASSOC)['id'];
    $processor->processTask($taskRita3);
    $rs3 = $pdo->query(
        "SELECT status FROM sequence_sends WHERE lead_id = 1 AND campaign_id = 2 AND step_order = 2 " .
        "ORDER BY id DESC LIMIT 1"
    )->fetch(\App\PDO::FETCH_ASSOC);
    nr_ok($rs3['status'] === 'skipped', 're-qualified needs_review lead: queued step 2 skipped');

    // --- D. post-send bookkeeping is fail-closed ---------------------------------
    echo "D. Post-send bookkeeping:\n";
    // Synthetic worst case: a send that somehow completed for a needs_review
    // lead must neither flip the lead to Contacted (which would re-admit it
    // to future sends) nor schedule its next step.
    $pdo->exec("INSERT INTO leads (company_name, contact_name, email, status, campaign_id, country_code, consent_status) VALUES
        ('NR Ghost', 'Gus Ghost', 'gus@nr.test', 'Needs Review', 1, 'US', 'express')");
    $ghostId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO sequence_enrollments (campaign_id, lead_id, status, current_step) VALUES (1, {$ghostId}, 'active', 1)");
    $ghostEnr = (int)$pdo->lastInsertId();
    $tpl1 = $pdo->query("SELECT id FROM templates WHERE campaign_id = 1 AND step_order = 1")->fetch(\App\PDO::FETCH_ASSOC);
    $pdo->prepare(
        "INSERT INTO sequence_sends (enrollment_id, campaign_id, lead_id, template_id, step_order, " .
        "status, scheduled_at, track_token) VALUES (?, 1, ?, ?, 1, 'sending', NOW(), ?)"
    )->execute([$ghostEnr, $ghostId, (int)$tpl1['id'], bin2hex(random_bytes(32))]);
    $ghostSend = (int)$pdo->lastInsertId();
    \App\SequenceManager::sendSucceeded($pdo, $ghostSend);
    $ghost = $pdo->query("SELECT status FROM leads WHERE id = {$ghostId}")->fetch(\App\PDO::FETCH_ASSOC);
    nr_ok($ghost['status'] === 'Needs Review', 'sendSucceeded never re-marks a needs_review lead Contacted');
    $ghostStep2 = $pdo->query(
        "SELECT COUNT(*) c FROM sequence_sends WHERE lead_id = {$ghostId} AND step_order = 2"
    )->fetch(\App\PDO::FETCH_ASSOC);
    nr_ok((int)$ghostStep2['c'] === 0, 'no step-2 send scheduled for a needs_review lead');
    // Positive control: a Qualified lead still gets the Contacted mark + step 2.
    $pdo->exec("INSERT INTO leads (company_name, contact_name, email, status, campaign_id, country_code, consent_status) VALUES
        ('NR Fine', 'Finn Fine', 'finn@nr.test', 'Qualified', 1, 'US', 'express')");
    $fineId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO sequence_enrollments (campaign_id, lead_id, status, current_step) VALUES (1, {$fineId}, 'active', 1)");
    $fineEnr = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO sequence_sends (enrollment_id, campaign_id, lead_id, template_id, step_order, " .
        "status, scheduled_at, track_token) VALUES (?, 1, ?, ?, 1, 'sending', NOW(), ?)"
    )->execute([$fineEnr, $fineId, (int)$tpl1['id'], bin2hex(random_bytes(32))]);
    $fineSend = (int)$pdo->lastInsertId();
    \App\SequenceManager::sendSucceeded($pdo, $fineSend);
    $fine = $pdo->query("SELECT status FROM leads WHERE id = {$fineId}")->fetch(\App\PDO::FETCH_ASSOC);
    nr_ok($fine['status'] === 'Contacted', 'Qualified lead still marked Contacted after send');
    $fineStep2 = $pdo->query(
        "SELECT COUNT(*) c FROM sequence_sends WHERE lead_id = {$fineId} AND step_order = 2"
    )->fetch(\App\PDO::FETCH_ASSOC);
    nr_ok((int)$fineStep2['c'] === 1, 'Qualified lead: step 2 still scheduled');

    echo "\nneeds_review eligibility: {$passed} passed, {$failures} failed.\n";
    if ($failures > 0) { exit(1); }
} catch (\Throwable $e) {
    echo "FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    // Restore the repo tree exactly.
    if ($hadConfig) { file_put_contents($configFile, $backup); }
    elseif (is_file($configFile)) { unlink($configFile); }
}

exit($failures === 0 ? 0 : 1);
