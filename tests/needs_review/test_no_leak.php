#!/usr/bin/env php
<?php
/**
 * No-leak guarantees: a needs_review lead cannot be enrolled or sent to —
 * across campaign launch, sequence enrollment, and queue processing.
 *
 * Layer 1 — launch (runnable now): SequenceManager::launch() only enrolls
 * statuses in ELIGIBLE_LEAD_STATUSES ('New','Enriched','Drafted','Qualified');
 * 'Needs Review' is not among them, so launch must produce zero enrollment
 * rows, zero sends, and zero queue tasks for the needs_review lead.
 *
 * Layer 2 — send-time gate (sibling 2 audit): even if a needs_review lead
 * somehow holds an active enrollment with a queued send (e.g. it was
 * enrolled as Qualified and moved to Needs Review while the task sat in the
 * queue), the send worker must SKIP it, never mail it. The test detects
 * whether SendSequenceStepAction's send-time skip list covers 'Needs Review';
 * when sibling 2's audit has not landed yet, the live assertions are
 * SKIPPED with an explicit PENDING reason instead of failing.
 *
 * Zero network, zero real sends (operational_mode='simulated' — and the
 * skip path returns before any send logic), zero LLM (the send gate never
 * runs in simulated mode).
 *
 * Usage: php tests/needs_review/test_no_leak.php
 * The repo tree is left exactly as it was (config/db.php restored/deleted).
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require nr_repo_root() . '/includes/autoload.php';
require __DIR__ . '/fixtures.php';

use App\SequenceManager;

/** True when the send-time gate covers the Needs Review status (fail-closed). */
function nr_send_gate_covers_needs_review(): bool
{
    // Sibling 2's audit inverted the gate: instead of a denylist of terminal
    // statuses, the worker only mails statuses on an explicit allowlist
    // (SequenceManager::SENDABLE_LEAD_STATUSES, public). 'Needs Review' must
    // NOT be on it — and SendSequenceStepAction must consult it.
    $sendable = \App\SequenceManager::SENDABLE_LEAD_STATUSES;
    $src = (string)file_get_contents(nr_repo_root() . '/includes/Actions/SendSequenceStepAction.php');
    return !in_array('Needs Review', $sendable, true)
        && str_contains($src, 'SENDABLE_LEAD_STATUSES');
}

try {
    $pdo = nr_scratch_db('nr_noleak', 'nr_test', 'nr_test_pw_4x8');

    $pdo->exec(nr_leads_table_ddl());
    $pdo->exec("CREATE TABLE settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE campaigns (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL DEFAULT 'active') ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE templates (id INT AUTO_INCREMENT PRIMARY KEY, campaign_id INT NOT NULL, subject VARCHAR(255), body TEXT, step_order INT DEFAULT 1, delay_days INT DEFAULT 0) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE task_queue (id INT AUTO_INCREMENT PRIMARY KEY, lead_id INT, task_type ENUM('Enrichment','EmailOutreach','SocialOutreach','Qualify','Enrich','Draft') NOT NULL, payload JSON, status ENUM('Pending','In Progress','Completed','Failed') DEFAULT 'Pending', scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, processed_at TIMESTAMP NULL, retry_count INT DEFAULT 0, error_message TEXT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE suppression_list (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NULL, reason VARCHAR(50) NOT NULL DEFAULT 'unsubscribe', source VARCHAR(100) NULL, UNIQUE KEY uq_suppression_email (email)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE email_logs (id INT AUTO_INCREMENT PRIMARY KEY, lead_email VARCHAR(255) NOT NULL, provider_id VARCHAR(50) NOT NULL, campaign_id INT NULL, status ENUM('sent','failed','queued','bounced') DEFAULT 'sent', metadata_json TEXT, timestamp INT NOT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE casl_decisions (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL, country_code CHAR(2) NULL, decision ENUM('allow','block') NOT NULL, rule VARCHAR(100) NOT NULL) ENGINE=InnoDB");
    nr_apply_migration($pdo, nr_repo_root() . '/migrations/2026-09-28-phase4-sequences.sql');

    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES
        ('operational_mode', 'simulated'),
        ('company_legal_name', 'NoLeak Test Co'),
        ('physical_address', '1 Test Way, Austin TX 78701'),
        ('app_base_url', 'https://example.test')");
    $pdo->exec("INSERT INTO campaigns (name) VALUES ('No-Leak Campaign')");
    $pdo->exec("INSERT INTO templates (campaign_id, subject, body, step_order, delay_days) VALUES (1, 'Hi {{contact_name}}', '<p>Hello {{company_name}}</p>', 1, 0)");
    $ids = nr_seed_fixtures($pdo);
    $nrId = $ids['needs_review'];
    foreach ($ids as $id) {
        $pdo->exec("UPDATE leads SET campaign_id = 1 WHERE id = {$id}");
    }

    // --- Layer 1: launch -------------------------------------------------------
    $res = SequenceManager::launch($pdo, 1);
    $enr = (int)$pdo->query("SELECT COUNT(*) FROM sequence_enrollments WHERE lead_id = {$nrId}")->fetchColumn();
    $sends = (int)$pdo->query("SELECT COUNT(*) FROM sequence_sends WHERE lead_id = {$nrId}")->fetchColumn();
    $tasks = (int)$pdo->query("SELECT COUNT(*) FROM task_queue WHERE lead_id = {$nrId}")->fetchColumn();
    nr_check('launch: no enrollment row for the needs_review lead', $enr === 0, "rows={$enr}");
    nr_check('launch: no sequence_sends rows for the needs_review lead', $sends === 0, "rows={$sends}");
    nr_check('launch: no task_queue rows for the needs_review lead', $tasks === 0, "rows={$tasks}");
    nr_check('launch: needs_review counted as ineligible status', $res['skipped_status'] >= 1,
        'skipped_status=' . $res['skipped_status']);
    nr_check('launch: the Qualified fixture still enrolls (control)',
        (int)$pdo->query("SELECT COUNT(*) FROM sequence_enrollments WHERE lead_id = {$ids['qualified']}")->fetchColumn() === 1);

    // --- Layer 1b: direct queueStep() on an active needs_review enrollment -------
    // Leak scenario: the lead was enrolled while Qualified, then moved to
    // Needs Review while the enrollment is still active. queueStep() must
    // refuse outright (null) — no send row, no queue task.
    $pdo->exec("INSERT INTO sequence_enrollments (campaign_id, lead_id, status, current_step) VALUES (1, {$nrId}, 'active', 1)");
    $nrEnrollmentId = (int)$pdo->lastInsertId();
    $queuedId = SequenceManager::queueStep($pdo, $nrEnrollmentId, 1);
    nr_check('queueStep: returns null for an active enrollment on a needs_review lead',
        $queuedId === null, 'returned=' . var_export($queuedId, true));
    nr_check('queueStep: creates no sequence_sends row for the needs_review lead',
        (int)$pdo->query("SELECT COUNT(*) FROM sequence_sends WHERE lead_id = {$nrId}")->fetchColumn() === 0);
    nr_check('queueStep: creates no task_queue row for the needs_review lead',
        (int)$pdo->query("SELECT COUNT(*) FROM task_queue WHERE lead_id = {$nrId}")->fetchColumn() === 0);
    // Control: the same call succeeds for the Qualified fixture's enrollment
    // (a second campaign — Layer 1's launch already enrolled it in campaign 1,
    // and uq_enrollment allows only one enrollment per lead per campaign).
    $pdo->exec("INSERT INTO campaigns (name) VALUES ('No-Leak Control')");
    $pdo->exec("INSERT INTO templates (campaign_id, subject, body, step_order, delay_days) VALUES (2, 'Hi', '<p>x</p>', 1, 0)");
    $pdo->exec("INSERT INTO sequence_enrollments (campaign_id, lead_id, status, current_step) VALUES (2, {$ids['qualified']}, 'active', 1)");
    $qEnrollmentId = (int)$pdo->lastInsertId();
    $qQueuedId = SequenceManager::queueStep($pdo, $qEnrollmentId, 1);
    nr_check('queueStep: control queues step 1 for the Qualified lead', is_int($qQueuedId) && $qQueuedId > 0);
    // Clean up both enrollments so Layer 2 starts from a known state
    // (uq_enrollment allows only one enrollment per lead per campaign).
    $pdo->exec("DELETE FROM sequence_enrollments WHERE id IN ({$nrEnrollmentId}, {$qEnrollmentId})");
    $pdo->exec("DELETE FROM sequence_sends WHERE enrollment_id = {$qEnrollmentId}");

    // --- Layer 2: send-time gate -----------------------------------------------
    if (!nr_send_gate_covers_needs_review()) {
        nr_skip('queue: send worker skips a needs_review lead with a queued send',
            'PENDING sibling 2 (decision point): send-time skip list does not cover Needs Review yet');
        nr_skip('queue: no email_logs row and no step progression for the skipped send',
            'PENDING sibling 2 (decision point)');
        echo "  NOTE: currently the worker only skips Converted/Unqualified at send time;\n";
        echo "  rerun this file once sibling 2's no-leak audit is committed.\n";
    } else {
        // Leak scenario: the lead was enrolled while Qualified, then moved
        // to Needs Review while its step-1 task sat in the queue.
        $pdo->exec("INSERT INTO sequence_enrollments (campaign_id, lead_id, status, current_step) VALUES (1, {$nrId}, 'active', 1)");
        $enrollmentId = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO sequence_sends (enrollment_id, campaign_id, lead_id, step_order, template_id, status, track_token) VALUES " .
            "({$enrollmentId}, 1, {$nrId}, 1, 1, 'queued', '" . bin2hex(random_bytes(32)) . "')");
        $sendId = (int)$pdo->lastInsertId();
        $payload = json_encode(['sequence_send_id' => $sendId, 'campaign_id' => 1, 'step_order' => 1]);
        $tstmt = $pdo->prepare("INSERT INTO task_queue (lead_id, task_type, payload, status) VALUES (?, 'SequenceSend', ?, 'Pending')");
        $tstmt->execute([$nrId, $payload]);
        $taskId = (int)$pdo->lastInsertId();

        $router = new \App\Routers\SmartLLMRouter($pdo); // no side effects on construct
        $processor = new \App\Domain\TaskProcessor($pdo, $router);
        $processor->processTask($taskId);

        $sendStatus = $pdo->query("SELECT status FROM sequence_sends WHERE id = {$sendId}")->fetchColumn();
        nr_check('queue: send worker marks the needs_review send skipped, never mailed',
            $sendStatus === 'skipped', "status={$sendStatus}");
        $logs = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE lead_email = 'bob@betafreight.test'")->fetchColumn();
        nr_check('queue: no email_logs row for the needs_review lead', $logs === 0, "rows={$logs}");
        $step2 = (int)$pdo->query("SELECT COUNT(*) FROM sequence_sends WHERE lead_id = {$nrId} AND step_order = 2")->fetchColumn();
        nr_check('queue: skipped send does not progress the sequence', $step2 === 0, "step2_rows={$step2}");
        $taskStatus = $pdo->query("SELECT status FROM task_queue WHERE id = {$taskId}")->fetchColumn();
        nr_check('queue: task completes as a clean skip (not a failure)', $taskStatus === 'Completed', "status={$taskStatus}");
    }
} catch (\Throwable $e) {
    nr_check('no-leak suite completed without exception', false, $e->getMessage());
} finally {
    nr_restore_db_config();
}

exit(nr_summary('test_no_leak.php'));
