#!/usr/bin/env php
<?php
/**
 * Phase 4 sequence tests: campaign launch + multi-step progression +
 * open/reply tracking + stats wiring.
 *
 * Usage (standalone): php tests/phase4/test_sequences.php
 * (Also picked up by tests/phase4/run_phase4_tests.php's orchestrator.)
 *
 * Spins up a scratch MariaDB database (phase4_test), points a TEMPORARY
 * config/db.php at it, applies migrations/2026-09-28-phase4-sequences.sql,
 * and exercises the sequence engine WITHOUT any real sends
 * (operational_mode='simulated') and WITHOUT any LLM (SendGateAction is
 * never reached in simulated mode; classify() is never called — reply
 * verdicts are fed to SequenceManager::recordReply directly).
 *
 *   A. Launch enrolls eligible leads + queues step 1 honoring step_order.
 *   B. Launch is idempotent (relaunch skips already-enrolled leads).
 *   C. Launch skips suppressed emails and ineligible lead statuses.
 *   D. Launch refuses inactive / paused / templateless campaigns.
 *   E. Simulated step-1 send: send marked simulated, safety_simulated log
 *      row, task Completed, step 2 queued per delay_days cadence, pixel
 *      embedded, personalization rendered.
 *   F. Compliance gate still enforced on the send path (suppressed lead ->
 *      permanent failure, send failed, task Failed).
 *   G. Final step completes the enrollment (no step N+1 queued).
 *   H. Reply tracking: 'unsubscribe' verdict suppresses + stops the
 *      sequence and cancels queued sends/tasks.
 *   I. 'out_of_office' verdict does NOT stop the sequence.
 *   J. Open tracking: valid token increments, bad tokens return false.
 *   L. Manual campaign stop halts enrollments and cancels queued work.
 *   M. Transient-failure classifier: timeouts retryable, compliance
 *      refusals permanent.
 *   N. Endpoint wiring (static): auth on campaigns launch/stop, tokenized
 *      pixel without auth, throttle gate + factory mapping for SequenceSend,
 *      ingest_reply attaches verdicts.
 *   O. Migration is idempotent (applies twice cleanly).
 *   S. campaignStats() aggregates.
 *
 * The repo tree is left exactly as it was (config/db.php restored/deleted).
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
$configDir = $repo . '/config';
$configFile = $configDir . '/db.php';
$hadConfig = is_file($configFile);
$backup = $hadConfig ? file_get_contents($configFile) : null;

$failures = 0; $passed = 0;
function ok(bool $cond, string $name): void {
    global $failures, $passed;
    if ($cond) { $passed++; echo "  PASS: {$name}\n"; }
    else { $failures++; echo "  FAIL: {$name}\n"; }
}
function expectThrow(callable $fn, string $needle, string $name): void {
    try { $fn(); ok(false, $name . ' (no exception thrown)'); }
    catch (\Throwable $e) { ok(stripos($e->getMessage(), $needle) !== false, $name . ' [got: ' . substr($e->getMessage(), 0, 100) . ']'); }
}

try {
    $dbUser = 'phase4_test'; $dbPass = 'phase4_test_pw_9d2';
    $sh = function (string $cmd): void {
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) { throw new RuntimeException("shell failed: {$cmd}\n" . implode("\n", $out)); }
    };
    $sh("mysql -u root -e \"DROP DATABASE IF EXISTS phase4_test; CREATE DATABASE phase4_test CHARACTER SET utf8mb4;\"");
    $sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON phase4_test.* TO '{$dbUser}'@'%'; GRANT ALL ON phase4_test.* TO '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;\"");

    if (!is_dir($configDir)) { mkdir($configDir, 0755, true); }
    file_put_contents($configFile, "<?php\nreturn ['host' => '127.0.0.1', 'name' => 'phase4_test', 'user' => '{$dbUser}', 'pass' => '{$dbPass}'];\n");

    require $repo . '/includes/autoload.php';
    $pdo = \App\Database::getConnection();
    \App\SequenceManager::resetReadyCache();

    // Minimal schema for the pieces under test.
    $pdo->exec("CREATE TABLE settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE campaigns (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL DEFAULT 'active') ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE templates (id INT AUTO_INCREMENT PRIMARY KEY, campaign_id INT NOT NULL, subject VARCHAR(255), body TEXT, step_order INT DEFAULT 1) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE leads (id INT AUTO_INCREMENT PRIMARY KEY, company_name VARCHAR(255) NOT NULL, contact_name VARCHAR(255), email VARCHAR(255) UNIQUE NOT NULL, status VARCHAR(50) DEFAULT 'New', campaign_id INT NULL, country_code CHAR(2) NULL, consent_status VARCHAR(20) NOT NULL DEFAULT 'unknown', notes TEXT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE task_queue (id INT AUTO_INCREMENT PRIMARY KEY, lead_id INT, task_type ENUM('Enrichment','EmailOutreach','SocialOutreach','Qualify','Enrich','Draft') NOT NULL, payload JSON, status ENUM('Pending','In Progress','Completed','Failed') DEFAULT 'Pending', scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, processed_at TIMESTAMP NULL, retry_count INT DEFAULT 0, error_message TEXT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE suppression_list (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NULL, reason VARCHAR(50) NOT NULL DEFAULT 'unsubscribe', source VARCHAR(100) NULL, UNIQUE KEY uq_suppression_email (email)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE email_logs (id INT AUTO_INCREMENT PRIMARY KEY, lead_email VARCHAR(255) NOT NULL, provider_id VARCHAR(50) NOT NULL, campaign_id INT NULL, status ENUM('sent','failed','queued','bounced') DEFAULT 'sent', metadata_json TEXT, timestamp INT NOT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE casl_decisions (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL, country_code CHAR(2) NULL, decision ENUM('allow','block') NOT NULL, rule VARCHAR(100) NOT NULL) ENGINE=InnoDB");

    $migFile = $repo . '/migrations/2026-09-28-phase4-sequences.sql';
    $mig = file_get_contents($migFile);
    if ($mig === false) { throw new RuntimeException('phase-4 migration missing'); }

    // --- Test O: migration applies cleanly, twice (idempotent) --------------
    echo "O. Migration idempotency:\n";
    $apply = function () use ($pdo, $mig): void {
        // Strip full-line SQL comments BEFORE splitting: the header block
        // itself contains semicolons, which would otherwise split chunks
        // mid-comment and feed comment text to the server.
        $lines = array_filter(explode("\n", $mig), fn($l) => !str_starts_with(trim($l), '--'));
        $stripped = implode("\n", $lines);
        foreach (array_filter(array_map('trim', explode(';', $stripped))) as $sql) {
            $pdo->exec($sql);
        }
    };
    $apply();
    ok(true, 'migration applies on fresh tables');
    try { $apply(); ok(true, 'migration re-applies cleanly (idempotent)'); }
    catch (\Throwable $e) { ok(false, 'migration re-applies cleanly [got: ' . substr($e->getMessage(), 0, 80) . ']'); }
    $cols = $pdo->query("SHOW COLUMNS FROM task_queue LIKE 'task_type'")->fetch(\App\PDO::FETCH_ASSOC);
    ok(str_contains((string)$cols['Type'], 'SequenceSend'), 'task_queue ENUM gained SequenceSend');
    $dcol = $pdo->query("SHOW COLUMNS FROM templates LIKE 'delay_days'")->fetch(\App\PDO::FETCH_ASSOC);
    ok($dcol !== false, 'templates gained delay_days');

    // --- Seed ----------------------------------------------------------------
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES
        ('operational_mode', 'simulated'),
        ('company_legal_name', 'Phase4 Test Co'),
        ('physical_address', '1 Test Way, Austin TX 78701'),
        ('app_base_url', 'https://example.test')");
    // Campaign 1: 3 steps, inserted OUT OF ORDER to prove step_order is honored.
    $pdo->exec("INSERT INTO campaigns (name) VALUES ('Seq Campaign')");
    $pdo->exec("INSERT INTO templates (campaign_id, subject, body, step_order, delay_days) VALUES
        (1, 'Step 2 subject', '<p>Hi {{first_name}}, step 2 for {{company_name}}</p>', 2, 2),
        (1, 'Step 3 subject', '<p>Final nudge for {{company_name}}</p>', 3, 5),
        (1, 'Step 1 subject', '<p>Hello {{contact_name}} at {{company_name}}</p>', 1, 3)");
    $pdo->exec("INSERT INTO leads (company_name, contact_name, email, status, campaign_id, country_code, consent_status) VALUES
        ('Acme', 'Alice Adams', 'alice@acme.test', 'New', 1, 'US', 'express'),
        ('Beta', 'Bob Brown', 'bob@beta.test', 'Enriched', 1, 'US', 'express'),
        ('Gamma', 'Gus Green', 'gus@gamma.test', 'Converted', 1, 'US', 'express'),
        ('Delta', 'Dan Doe', 'dan@delta.test', 'New', NULL, 'US', 'express')");
    // Campaign 2: single step (completion test).
    $pdo->exec("INSERT INTO campaigns (name) VALUES ('One-Shot')");
    $pdo->exec("INSERT INTO templates (campaign_id, subject, body, step_order) VALUES (2, 'Only step', '<p>Solo</p>', 1)");
    $pdo->exec("INSERT INTO leads (company_name, contact_name, email, status, campaign_id, country_code, consent_status) VALUES
        ('Epsilon', 'Eve Evans', 'eve@epsilon.test', 'Qualified', 2, 'US', 'express')");
    // Campaign 3: inactive. Campaign 4: paused. Campaign 5: templateless.
    $pdo->exec("INSERT INTO campaigns (name, is_active) VALUES ('Inactive', 0)");
    $pdo->exec("INSERT INTO campaigns (name, status) VALUES ('Paused', 'paused')");
    $pdo->exec("INSERT INTO campaigns (name) VALUES ('Templateless')");
    $pdo->exec("INSERT INTO leads (company_name, email, status, campaign_id, country_code, consent_status) VALUES ('Zeta', 'zeta@z.test', 'New', 5, 'US', 'express')");

    $router = new \App\Routers\SmartLLMRouter($pdo); // constructs with no side effects
    $processor = new \App\Domain\TaskProcessor($pdo, $router);
    $launcher = new \App\Actions\LaunchCampaignAction($pdo);

    // --- Test A: launch -------------------------------------------------------
    echo "A. Launch enrolls + queues step 1:\n";
    $res = $launcher->launch(1);
    ok($res['enrolled'] === 2, '2 eligible leads enrolled (Converted skipped, unassigned skipped)');
    ok($res['queued'] === 2, '2 step-1 sends queued');
    ok($res['skipped_status'] === 1, 'Converted lead counted as skipped_status');
    ok($res['templates'] === 3, '3 templates reported');
    $sends = $pdo->query("SELECT step_order, template_id FROM sequence_sends WHERE campaign_id = 1")->fetchAll(\App\PDO::FETCH_ASSOC);
    ok(count($sends) === 2 && (int)$sends[0]['step_order'] === 1 && (int)$sends[1]['step_order'] === 1, 'only step_order=1 queued');
    $tpl1 = $pdo->query("SELECT id FROM templates WHERE campaign_id = 1 AND step_order = 1")->fetch(\App\PDO::FETCH_ASSOC);
    ok((int)$sends[0]['template_id'] === (int)$tpl1['id'], 'step-1 send uses the step_order=1 template (not insertion order)');
    $tasks = $pdo->query("SELECT COUNT(*) c FROM task_queue WHERE task_type = 'SequenceSend' AND status = 'Pending'")->fetch(\App\PDO::FETCH_ASSOC);
    ok((int)$tasks['c'] === 2, '2 Pending SequenceSend tasks in the queue');
    $tok = $pdo->query("SELECT track_token FROM sequence_sends LIMIT 1")->fetch(\App\PDO::FETCH_ASSOC);
    ok((bool)preg_match('/^[0-9a-f]{64}$/', (string)$tok['track_token']), 'track_token is 64-hex unguessable');
    $ev = $pdo->query("SELECT COUNT(*) c FROM sequence_events WHERE campaign_id = 1 AND event_type = 'launch'")->fetch(\App\PDO::FETCH_ASSOC);
    ok((int)$ev['c'] === 1, 'launch timeline event recorded');

    // --- Test B: idempotent relaunch ------------------------------------------
    echo "B. Launch idempotency:\n";
    $res2 = $launcher->launch(1);
    ok($res2['enrolled'] === 0 && $res2['queued'] === 0, 'relaunch enrolls/queues nothing new');
    ok($res2['skipped_enrolled'] === 2, 'relaunch reports 2 already-enrolled');

    // --- Test C: suppression / status skips -----------------------------------
    echo "C. Launch skips suppressed:\n";
    \App\Compliance::suppress('bob@beta.test', 'unsubscribe', 'phase4-test');
    $pdo->exec("INSERT INTO campaigns (name) VALUES ('Supp Campaign')");
    $pdo->exec("INSERT INTO templates (campaign_id, subject, body, step_order) VALUES (6, 'S1', 'B1', 1)");
    // Bob's address is suppressed; re-point his lead row at campaign 6.
    $pdo->exec("UPDATE leads SET campaign_id = 6, status = 'New' WHERE email = 'bob@beta.test'");
    $res3 = $launcher->launch(6);
    ok($res3['enrolled'] === 0 && $res3['skipped_suppressed'] === 1, 'suppressed email not enrolled');

    // --- Test D: launch refusals ----------------------------------------------
    echo "D. Launch refusals:\n";
    expectThrow(fn() => $launcher->launch(3), 'not active', 'inactive campaign refused');
    expectThrow(fn() => $launcher->launch(4), 'paused', 'paused campaign refused');
    expectThrow(fn() => $launcher->launch(5), 'no templates', 'templateless campaign refused');
    expectThrow(fn() => $launcher->launch(999), 'not found', 'missing campaign refused');

    // --- Test E: simulated send + progression ----------------------------------
    echo "E. Simulated send + step progression:\n";
    $task = $pdo->query("SELECT id FROM task_queue WHERE task_type = 'SequenceSend' AND lead_id = 1 AND status = 'Pending'")->fetch(\App\PDO::FETCH_ASSOC);
    $processor->processTask((int)$task['id']);
    $send = $pdo->query("SELECT status FROM sequence_sends WHERE campaign_id = 1 AND lead_id = 1 AND step_order = 1")->fetch(\App\PDO::FETCH_ASSOC);
    ok($send['status'] === 'simulated', 'step-1 send marked simulated (no real send)');
    $trow = $pdo->query("SELECT status FROM task_queue WHERE id = " . (int)$task['id'])->fetch(\App\PDO::FETCH_ASSOC);
    ok($trow['status'] === 'Completed', 'task marked Completed');
    $log = $pdo->query("SELECT provider_id, status FROM email_logs WHERE lead_email = 'alice@acme.test'")->fetch(\App\PDO::FETCH_ASSOC);
    ok($log && $log['provider_id'] === 'safety_simulated', 'simulated send logged as safety_simulated');
    $lead = $pdo->query("SELECT status FROM leads WHERE id = 1")->fetch(\App\PDO::FETCH_ASSOC);
    ok($lead['status'] === 'Contacted', 'lead marked Contacted');
    $step2 = $pdo->query("SELECT scheduled_at, status FROM sequence_sends WHERE campaign_id = 1 AND lead_id = 1 AND step_order = 2")->fetch(\App\PDO::FETCH_ASSOC);
    ok($step2 && $step2['status'] === 'queued', 'step 2 queued after step 1');
    ok($step2 && strtotime($step2['scheduled_at']) > time() + 2 * 86400, 'step 2 scheduled per delay_days cadence (~3d)');
    $body = $pdo->query("SELECT body, track_token FROM sequence_sends WHERE campaign_id = 1 AND lead_id = 1 AND step_order = 1")->fetch(\App\PDO::FETCH_ASSOC);
    ok(str_contains((string)$body['body'], 'Alice Adams'), 'personalization token {{contact_name}} rendered');
    ok(str_contains((string)$body['body'], 'https://example.test/api/track_open.php?t=' . $body['track_token']), 'open-tracking pixel embedded with the send token');

    // --- Test F: compliance gate on the send path ------------------------------
    echo "F. Compliance gate enforced:\n";
    $pdo->exec("INSERT INTO campaigns (name) VALUES ('Gate Campaign')");
    $pdo->exec("INSERT INTO templates (campaign_id, subject, body, step_order) VALUES (7, 'G1', 'GB1', 1)");
    $pdo->exec("INSERT INTO leads (company_name, email, status, campaign_id, country_code, consent_status) VALUES ('Gate', 'gate@g.test', 'New', 7, 'US', 'express')");
    $launcher->launch(7);
    \App\Compliance::suppress('gate@g.test', 'unsubscribe', 'phase4-test');
    $gtask = $pdo->query("SELECT id FROM task_queue WHERE task_type = 'SequenceSend' AND status = 'Pending' ORDER BY id DESC LIMIT 1")->fetch(\App\PDO::FETCH_ASSOC);
    $processor->processTask((int)$gtask['id']);
    $gsend = $pdo->query("SELECT status FROM sequence_sends WHERE campaign_id = 7")->fetch(\App\PDO::FETCH_ASSOC);
    ok($gsend['status'] === 'failed', 'suppressed lead: send marked failed');
    $grow = $pdo->query("SELECT status, error_message FROM task_queue WHERE id = " . (int)$gtask['id'])->fetch(\App\PDO::FETCH_ASSOC);
    ok($grow['status'] === 'Failed' && str_contains((string)$grow['error_message'], 'suppression'), 'task Failed with suppression message');

    // --- Test G: final step completes ------------------------------------------
    echo "G. Sequence completion:\n";
    $launcher->launch(2);
    $cand = $pdo->query("SELECT id, payload FROM task_queue WHERE task_type = 'SequenceSend' AND status = 'Pending'")->fetchAll(\App\PDO::FETCH_ASSOC);
    $found = null;
    foreach ($cand as $t) {
        $p = json_decode((string)$t['payload'], true);
        if ((int)($p['campaign_id'] ?? 0) === 2) { $found = $t; break; }
    }
    ok($found !== null, 'one-shot task found');
    $processor->processTask((int)$found['id']);
    $enr = $pdo->query("SELECT status FROM sequence_enrollments WHERE campaign_id = 2")->fetch(\App\PDO::FETCH_ASSOC);
    ok($enr['status'] === 'completed', 'enrollment completed after final step');
    $n2 = $pdo->query("SELECT COUNT(*) c FROM sequence_sends WHERE campaign_id = 2 AND step_order = 2")->fetch(\App\PDO::FETCH_ASSOC);
    ok((int)$n2['c'] === 0, 'no step 2 queued for a one-step campaign');

    // --- Test H: reply tracking stops the sequence -----------------------------
    echo "H. Reply tracking (unsubscribe):\n";
    $pdo->exec("DELETE FROM suppression_list WHERE email = 'bob@beta.test'");
    $btask = $pdo->query("SELECT id FROM task_queue WHERE task_type = 'SequenceSend' AND lead_id = 2 AND status = 'Pending'")->fetch(\App\PDO::FETCH_ASSOC);
    ok($btask !== false, 'bob step-1 task still pending');
    $res8 = \App\SequenceManager::recordReply($pdo, 2, 1, 'bob@beta.test',
        ['intent' => 'unsubscribe', 'needs_human' => false, 'urgency' => 3, 'confidence' => 0.9, 'source' => 'heuristic', 'latency_ms' => 5],
        'Please remove me');
    ok($res8['attached'] === true && $res8['stopped'] === 'stopped_unsubscribe', 'unsubscribe verdict attached + sequence stopped');
    $benr = $pdo->query("SELECT status FROM sequence_enrollments WHERE campaign_id = 1 AND lead_id = 2")->fetch(\App\PDO::FETCH_ASSOC);
    ok($benr['status'] === 'stopped_unsubscribe', 'enrollment status stopped_unsubscribe');
    $bsend = $pdo->query("SELECT status FROM sequence_sends WHERE campaign_id = 1 AND lead_id = 2 AND step_order = 1")->fetch(\App\PDO::FETCH_ASSOC);
    ok($bsend['status'] === 'cancelled', 'queued step-1 send cancelled');
    $btaskrow = $pdo->query("SELECT COUNT(*) c FROM task_queue WHERE id = " . (int)$btask['id'])->fetch(\App\PDO::FETCH_ASSOC);
    ok((int)$btaskrow['c'] === 0, 'pending task row deleted (not failed)');
    ok(\App\Compliance::isSuppressed('bob@beta.test'), 'reply-unsubscribe also wrote the suppression list');
    $cev = $pdo->query("SELECT COUNT(*) c FROM sequence_events WHERE campaign_id = 1 AND lead_id = 2 AND event_type IN ('replied','classified')")->fetch(\App\PDO::FETCH_ASSOC);
    ok((int)$cev['c'] === 2, 'replied + classified timeline events recorded');

    // --- Test I: out-of-office does not stop -----------------------------------
    echo "I. Out-of-office keeps the sequence:\n";
    $res9 = \App\SequenceManager::recordReply($pdo, 1, 1, 'alice@acme.test',
        ['intent' => 'out_of_office', 'needs_human' => false, 'urgency' => 1, 'confidence' => 0.95, 'source' => 'heuristic', 'latency_ms' => 5],
        'Out of office');
    ok($res9['attached'] === true && $res9['stopped'] === null, 'ooo attached, sequence NOT stopped');
    $aenr = $pdo->query("SELECT status FROM sequence_enrollments WHERE campaign_id = 1 AND lead_id = 1")->fetch(\App\PDO::FETCH_ASSOC);
    ok($aenr['status'] === 'active', 'enrollment still active after ooo');

    // --- Test J: open tracking --------------------------------------------------
    echo "J. Open tracking:\n";
    $tokRow = $pdo->query("SELECT id, track_token, open_count FROM sequence_sends WHERE campaign_id = 1 AND lead_id = 1 AND step_order = 1")->fetch(\App\PDO::FETCH_ASSOC);
    ok(\App\SequenceManager::recordOpen($pdo, (string)$tokRow['track_token'], '10.0.0.1', 'TestUA/1.0') === true, 'valid token attributed');
    $oc = $pdo->query("SELECT open_count, first_opened_at FROM sequence_sends WHERE id = " . (int)$tokRow['id'])->fetch(\App\PDO::FETCH_ASSOC);
    ok((int)$oc['open_count'] === (int)$tokRow['open_count'] + 1 && $oc['first_opened_at'] !== null, 'open_count incremented, first_opened_at set');
    ok(\App\SequenceManager::recordOpen($pdo, str_repeat('0', 64), null, null) === false, 'unknown token returns false');
    ok(\App\SequenceManager::recordOpen($pdo, 'not-a-token', null, null) === false, 'malformed token returns false');
    $oev = $pdo->query("SELECT COUNT(*) c FROM sequence_events WHERE send_id = " . (int)$tokRow['id'] . " AND event_type = 'opened'")->fetch(\App\PDO::FETCH_ASSOC);
    ok((int)$oev['c'] === 1, 'opened timeline event recorded');

    // --- Test L: manual campaign stop --------------------------------------------
    echo "L. Manual campaign stop:\n";
    $stopped = $launcher->stop(1, 'phase4 test halt');
    ok($stopped >= 1, 'active enrollments halted');
    $senr = $pdo->query("SELECT status FROM sequence_enrollments WHERE campaign_id = 1 AND lead_id = 1")->fetch(\App\PDO::FETCH_ASSOC);
    ok($senr['status'] === 'stopped_manual', 'enrollment stopped_manual');
    $ssend = $pdo->query("SELECT status FROM sequence_sends WHERE campaign_id = 1 AND lead_id = 1 AND step_order = 2")->fetch(\App\PDO::FETCH_ASSOC);
    ok($ssend['status'] === 'cancelled', 'queued step-2 send cancelled');

    // --- Test M: transient classifier ----------------------------------------------
    echo "M. Transient failure classification:\n";
    $action = new \App\Actions\SendSequenceStepAction($pdo, $router);
    $ref = new ReflectionMethod($action, 'isTransient');
    $ref->setAccessible(true);
    ok($ref->invoke($action, new \RuntimeException('Connection timed out after 15000 ms')) === true, 'timeout is transient');
    ok($ref->invoke($action, new \RuntimeException('HTTP 503 from provider')) === true, '5xx is transient');
    ok($ref->invoke($action, new \RuntimeException('429 rate limit exceeded')) === true, '429 is transient');
    ok($ref->invoke($action, new \App\Exceptions\OutreachException('Refusing to send: suppressed')) === false, 'compliance refusal is permanent');

    // --- Test N: endpoint wiring (static) -------------------------------------------
    echo "N. Endpoint wiring:\n";
    $camps = file_get_contents($repo . '/api/campaigns.php');
    ok(str_contains($camps, "requireApiAuth"), 'campaigns.php requires auth');
    ok(str_contains($camps, "'launch'") && str_contains($camps, "'stop'"), 'campaigns.php has launch/stop actions');
    $pixel = file_get_contents($repo . '/api/track_open.php');
    ok(!str_contains($pixel, 'requireApiAuth'), 'track_open.php has no login auth (tokenized)');
    ok(str_contains($pixel, 'recordOpen'), 'track_open.php attributes opens via SequenceManager');
    $cron = file_get_contents($repo . '/cron/process_queue.php');
    ok(str_contains($cron, "'SequenceSend'"), 'cron throttle gate covers SequenceSend');
    $tp = file_get_contents($repo . '/includes/Domain/TaskProcessor.php');
    ok(str_contains($tp, "'SequenceSend'") && str_contains($tp, 'SendSequenceStepAction'), 'TaskProcessor maps SequenceSend');
    $ing = file_get_contents($repo . '/api/ingest_reply.php');
    ok(str_contains($ing, 'SequenceManager::recordReply'), 'ingest_reply attaches verdicts to the timeline');
    ok(str_contains($ing, "'sequence'"), 'ingest_reply response carries the sequence attachment outcome');
    $unsub = file_get_contents($repo . '/unsubscribe.php');
    ok(str_contains($unsub, "stopForEmail"), 'unsubscribe.php halts sequences');
    $bounce = file_get_contents($repo . '/includes/Webhooks/BounceHandler.php');
    ok(str_contains($bounce, "stopSequences"), 'BounceHandler halts sequences on hard bounce');
    $stats = file_get_contents($repo . '/api/stats.php');
    ok(str_contains($stats, 'campaign_sequences'), 'stats.php surfaces campaign sequence stats');

    // --- Test S: campaignStats ---------------------------------------------------------
    echo "S. campaignStats():\n";
    $cs = \App\SequenceManager::campaignStats($pdo);
    $c1 = null;
    foreach ($cs as $row) { if ((int)$row['id'] === 1) { $c1 = $row; } }
    ok($c1 !== null && $c1['enrolled'] === 2, 'campaign 1 enrolled=2');
    ok($c1 !== null && $c1['sent'] >= 1, 'campaign 1 sent>=1');
    ok($c1 !== null && $c1['opens'] >= 1, 'campaign 1 opens>=1');
    ok($c1 !== null && $c1['replies'] >= 2, 'campaign 1 replies>=2 (unsub + ooo)');
    ok($c1 !== null && $c1['stopped'] >= 1, 'campaign 1 stopped>=1');
    ok($c1 !== null && $c1['open_rate'] > 0 && $c1['reply_rate'] > 0, 'open_rate/reply_rate computed');

    // --- Test T: transient requeue is not clobbered by the worker ------------
    echo "T. Transient requeue preservation:\n";
    $pdo->exec("INSERT INTO campaigns (name) VALUES ('Retry Campaign')");
    $pdo->exec("INSERT INTO templates (campaign_id, subject, body, step_order) VALUES (8, 'R1', 'RB1', 1)");
    $pdo->exec("INSERT INTO leads (company_name, email, status, campaign_id, country_code, consent_status) VALUES ('Retry', 'retry@r.test', 'New', 8, 'US', 'express')");
    $launcher->launch(8);
    $rtask = $pdo->query("SELECT id FROM task_queue WHERE task_type = 'SequenceSend' AND status = 'Pending' ORDER BY id DESC LIMIT 1")->fetch(\App\PDO::FETCH_ASSOC);
    // Simulate a transient provider failure inside the action: requeue to
    // Pending, then return true like a completed execution would.
    $pdo->prepare("UPDATE task_queue SET status = 'In Progress' WHERE id = ?")->execute([(int)$rtask['id']]);
    $action = new \App\Actions\SendSequenceStepAction($pdo, $router);
    $rm = new ReflectionMethod($action, 'requeueTransient');
    $rm->setAccessible(true);
    $sendRow = $pdo->query("SELECT id FROM sequence_sends WHERE campaign_id = 8 AND step_order = 1")->fetch(\App\PDO::FETCH_ASSOC);
    $rm->invoke($action, (int)$rtask['id'], (int)$sendRow['id'], 0, 'Connection timed out after 15000 ms');
    $mid = $pdo->query("SELECT status, scheduled_at, error_message FROM task_queue WHERE id = " . (int)$rtask['id'])->fetch(\App\PDO::FETCH_ASSOC);
    ok($mid['status'] === 'Pending', 'transient requeue returns the task to Pending');
    ok(strtotime($mid['scheduled_at']) > time() + 10 * 60, 'requeue backs off ~15 minutes');
    // Now the worker completes its bookkeeping: the guarded write must NOT
    // overwrite the Pending requeue with Completed.
    $wp = new \App\Domain\TaskProcessor($pdo, $router);
    $wrm = new ReflectionMethod($wp, 'updateTaskStatus');
    $wrm->setAccessible(true);
    $wrm->invoke($wp, (int)$rtask['id'], 'Completed');
    $after = $pdo->query("SELECT status FROM task_queue WHERE id = " . (int)$rtask['id'])->fetch(\App\PDO::FETCH_ASSOC);
    ok($after['status'] === 'Pending', 'worker Completed write does not clobber the Pending requeue');
    // A normally-completed task (still In Progress) IS marked Completed.
    $pdo->exec("UPDATE task_queue SET status = 'In Progress' WHERE id = " . (int)$rtask['id']);
    $wrm->invoke($wp, (int)$rtask['id'], 'Completed');
    $after2 = $pdo->query("SELECT status FROM task_queue WHERE id = " . (int)$rtask['id'])->fetch(\App\PDO::FETCH_ASSOC);
    ok($after2['status'] === 'Completed', 'guarded write still completes a genuinely In Progress task');

    echo "\nPhase 4 sequences: {$passed} passed, {$failures} failed.\n";
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
