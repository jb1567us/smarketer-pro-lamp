#!/usr/bin/env php
<?php
/**
 * State-transition tests for the 'Needs Review' qualification state.
 *
 * Part A — transition EFFECTS (DB + SequenceManager): a needs_review lead
 * is NOT sequence-eligible; flipping it to 'Qualified' (what an approval
 * does) makes it eligible; flipping it to 'Unqualified' (what a
 * disqualification does) keeps it out.
 *
 * Part B — transition ENFORCEMENT by the review workflow
 * (App\ReviewQueue::transition, sibling 3): approve/disqualify only fire
 * from 'Needs Review'; invalid transitions (approve/disqualify from any
 * other status, unknown decisions, unknown leads, empty reviewer identity)
 * throw and change nothing; every decision writes exactly one audit-trail
 * row transactionally with the status change; re-POSTs are idempotent;
 * and approval is the only path back to sequence eligibility.
 *
 * Zero network, zero real sends (no send worker runs here), zero LLM.
 *
 * Usage: php tests/needs_review/test_state_transitions.php
 * The repo tree is left exactly as it was (config/db.php restored/deleted).
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require nr_repo_root() . '/includes/autoload.php';
require __DIR__ . '/fixtures.php';

use App\ReviewQueue;
use App\SequenceManager;

function nr_transition_schema(\App\PDO $pdo): void
{
    $pdo->exec(nr_leads_table_ddl());
    $pdo->exec("CREATE TABLE settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE campaigns (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL DEFAULT 'active') ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE templates (id INT AUTO_INCREMENT PRIMARY KEY, campaign_id INT NOT NULL, subject VARCHAR(255), body TEXT, step_order INT DEFAULT 1, delay_days INT DEFAULT 0) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE task_queue (id INT AUTO_INCREMENT PRIMARY KEY, lead_id INT, task_type ENUM('Enrichment','EmailOutreach','SocialOutreach','Qualify','Enrich','Draft') NOT NULL, payload JSON, status ENUM('Pending','In Progress','Completed','Failed') DEFAULT 'Pending', scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, processed_at TIMESTAMP NULL, retry_count INT DEFAULT 0, error_message TEXT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE suppression_list (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NULL, reason VARCHAR(50) NOT NULL DEFAULT 'unsubscribe', source VARCHAR(100) NULL, UNIQUE KEY uq_suppression_email (email)) ENGINE=InnoDB");
    nr_apply_migration($pdo, nr_repo_root() . '/migrations/2026-09-28-phase4-sequences.sql');
    nr_apply_migration($pdo, nr_repo_root() . '/migrations/2026-09-28-review-decisions.sql');
    $pdo->exec("INSERT INTO campaigns (name) VALUES ('Review Transitions')");
    $pdo->exec("INSERT INTO templates (campaign_id, subject, body, step_order, delay_days) VALUES (1, 'Hi {{contact_name}}', '<p>Hello {{company_name}}</p>', 1, 0)");
}

function nr_enrolled(\App\PDO $pdo, int $leadId, int $campaignId = 1): bool
{
    $s = $pdo->prepare('SELECT 1 FROM sequence_enrollments WHERE lead_id = ? AND campaign_id = ? LIMIT 1');
    $s->execute([$leadId, $campaignId]);
    return (bool)$s->fetchColumn();
}

function nr_queued_sends(\App\PDO $pdo, int $leadId): int
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM sequence_sends WHERE lead_id = ?');
    $s->execute([$leadId]);
    return (int)$s->fetchColumn();
}

function nr_expect_review_throw(callable $fn, string $needle, string $name): void
{
    try {
        $fn();
        nr_check($name . ' (no exception thrown)', false);
    } catch (\App\Exceptions\OutreachException $e) {
        nr_check($name, stripos($e->getMessage(), $needle) !== false,
            'got: ' . substr($e->getMessage(), 0, 100));
    } catch (\Throwable $e) {
        nr_check($name . ' (wrong exception: ' . get_class($e) . ')', false);
    }
}

try {
    $pdo = nr_scratch_db('nr_transitions', 'nr_test', 'nr_test_pw_4x8');
    nr_transition_schema($pdo);
    $ids = nr_seed_fixtures($pdo);
    // Assign all three fixtures to the campaign.
    foreach ($ids as $id) {
        $pdo->exec("UPDATE leads SET campaign_id = 1 WHERE id = {$id}");
    }

    // --- A1. Launch: qualified enrolls, needs_review does not ------------------
    $r1 = SequenceManager::launch($pdo, 1);
    nr_check('launch enrolls the Qualified lead', nr_enrolled($pdo, $ids['qualified']));
    nr_check('launch queues step 1 for the Qualified lead', nr_queued_sends($pdo, $ids['qualified']) === 1);
    nr_check('launch does NOT enroll the needs_review lead', !nr_enrolled($pdo, $ids['needs_review']));
    nr_check('launch queues nothing for the needs_review lead', nr_queued_sends($pdo, $ids['needs_review']) === 0);
    nr_check('launch does NOT enroll the Unqualified lead', !nr_enrolled($pdo, $ids['unqualified']));
    nr_check('launch counts ineligible statuses', $r1['skipped_status'] >= 2,
        'skipped_status=' . $r1['skipped_status']);

    // --- A2. Approval effect: needs_review -> Qualified becomes eligible --------
    // (status-level simulation of what ReviewQueue::transition(..., 'approved')
    // does; the real API path is exercised in Part B7 below)
    $pdo->exec("UPDATE leads SET status = 'Qualified' WHERE id = {$ids['needs_review']}");
    $r2 = SequenceManager::launch($pdo, 1);
    nr_check('approved lead (now Qualified) enrolls on next launch', nr_enrolled($pdo, $ids['needs_review']));
    nr_check('approved lead gets step 1 queued', nr_queued_sends($pdo, $ids['needs_review']) === 1);
    nr_check('relaunch is idempotent for the already-enrolled Qualified lead',
        $r2['skipped_enrolled'] >= 1, 'skipped_enrolled=' . $r2['skipped_enrolled']);

    // --- A3. Disqualification effect: Qualified -> Unqualified stays out -------
    $pdo->exec("UPDATE leads SET status = 'Unqualified' WHERE id = {$ids['qualified']}");
    // A second campaign isolates the disqualification check from A1's enrollment.
    $pdo->exec("INSERT INTO campaigns (name) VALUES ('Review Transitions 2')");
    $pdo->exec("INSERT INTO templates (campaign_id, subject, body, step_order, delay_days) VALUES (2, 'Hi', '<p>x</p>', 1, 0)");
    $pdo->exec("UPDATE leads SET campaign_id = 2 WHERE id = {$ids['qualified']}");
    $r3 = SequenceManager::launch($pdo, 2);
    nr_check('disqualified lead (now Unqualified) is not enrolled', !nr_enrolled($pdo, $ids['qualified'], 2));
    nr_check('disqualified lead queues nothing',
        (int)$pdo->query("SELECT COUNT(*) FROM sequence_sends WHERE lead_id = {$ids['qualified']} AND campaign_id = 2")->fetchColumn() === 0);
    nr_check('launch reports the disqualification as ineligible status', $r3['skipped_status'] >= 1);
} catch (\Throwable $e) {
    nr_check('state-transition suite completed without exception', false, $e->getMessage());
} finally {
    nr_restore_db_config();
}

// --- Part B: review-workflow enforcement (App\ReviewQueue) --------------------
try {
    $pdoB = nr_scratch_db('nr_review_api', 'nr_test', 'nr_test_pw_4x8');
    $pdoB->exec(nr_leads_table_ddl());
    nr_apply_migration($pdoB, nr_repo_root() . '/migrations/2026-09-28-review-decisions.sql');
    $idsB = nr_seed_fixtures($pdoB);
    $nrIdB = $idsB['needs_review'];
    // A second needs_review lead for the disqualify path.
    $pdoB->exec("INSERT INTO leads (company_name, contact_name, email, status, lead_score, notes, country_code, consent_status) VALUES " .
        "('Delta Freight', 'Dana Doe', 'dana@deltafreight.test', 'Needs Review', 58, 'second review lead', 'US', 'express')");
    $nrId2 = (int)$pdoB->lastInsertId();

    // --- B1. approve: needs_review -> Qualified + audit row --------------------
    $out = ReviewQueue::transition($pdoB, $nrIdB, ReviewQueue::DECISION_APPROVED, 'tester');
    nr_check('approve: returns the transition outcome',
        $out['already_decided'] === false && $out['new_status'] === 'Qualified' && $out['lead_id'] === $nrIdB);
    $st = $pdoB->query("SELECT status FROM leads WHERE id = {$nrIdB}")->fetchColumn();
    nr_check("approve: lead status is now 'Qualified'", $st === 'Qualified', "got: {$st}");
    $audit = $pdoB->query("SELECT decision, decided_by, previous_status, fit_score_snapshot FROM review_decisions WHERE lead_id = {$nrIdB}")
        ->fetch(\App\PDO::FETCH_ASSOC);
    nr_check('approve: exactly one audit row written',
        $audit !== false && (int)$pdoB->query("SELECT COUNT(*) FROM review_decisions WHERE lead_id = {$nrIdB}")->fetchColumn() === 1);
    nr_check('approve: audit row captures decision, reviewer, previous status, fit snapshot',
        $audit['decision'] === 'approved' && $audit['decided_by'] === 'tester'
        && $audit['previous_status'] === 'Needs Review' && (int)$audit['fit_score_snapshot'] === 62);
    $notes = $pdoB->query("SELECT notes FROM leads WHERE id = {$nrIdB}")->fetchColumn();
    nr_check('approve: human-review marker appended (notes preserved)',
        str_contains((string)$notes, '[Human Review') && str_contains((string)$notes, 'Needs Review (fit 62/100'));

    // --- B2. disqualify: needs_review -> Unqualified + audit row ----------------
    $out2 = ReviewQueue::transition($pdoB, $nrId2, ReviewQueue::DECISION_DISQUALIFIED, 'tester');
    nr_check('disqualify: returns the transition outcome',
        $out2['already_decided'] === false && $out2['new_status'] === 'Unqualified');
    $st2 = $pdoB->query("SELECT status FROM leads WHERE id = {$nrId2}")->fetchColumn();
    nr_check("disqualify: lead status is now 'Unqualified'", $st2 === 'Unqualified', "got: {$st2}");

    // --- B3. invalid transitions rejected (fail-closed) -------------------------
    // NOTE: re-approving the lead approved in B1 is NOT an invalid transition —
    // it is the idempotent re-POST case (covered in B4). A genuine invalid
    // approve uses the Qualified *fixture* lead, which was never review-decided.
    nr_expect_review_throw(
        fn() => ReviewQueue::transition($pdoB, $idsB['qualified'], 'approved', 'tester'),
        'not awaiting review', 'approve on a Qualified lead (no prior decision) is rejected');
    nr_expect_review_throw(
        fn() => ReviewQueue::transition($pdoB, $idsB['unqualified'], 'approved', 'tester'),
        'not awaiting review', 'approve on an Unqualified lead is rejected');
    nr_expect_review_throw(
        fn() => ReviewQueue::transition($pdoB, $nrIdB, 'disqualified', 'tester'),
        'not awaiting review', 'disqualify on a Qualified lead is rejected');
    nr_expect_review_throw(
        fn() => ReviewQueue::transition($pdoB, $nrId2, 'maybe', 'tester'),
        'Invalid review decision', 'unknown decision string is rejected');
    nr_expect_review_throw(
        fn() => ReviewQueue::transition($pdoB, 999999, 'approved', 'tester'),
        'not found', 'unknown lead id is rejected');
    nr_expect_review_throw(
        fn() => ReviewQueue::transition($pdoB, $idsB['qualified'], 'approved', ''),
        'Reviewer identity is required', 'empty reviewer identity is rejected');

    // --- B4. idempotent re-POST: no duplicate audit row -------------------------
    $again = ReviewQueue::transition($pdoB, $nrIdB, 'approved', 'tester');
    nr_check('re-POST of the recorded decision reports already_decided',
        $again['already_decided'] === true && $again['new_status'] === 'Qualified');
    nr_check('re-POST does not duplicate the audit row',
        (int)$pdoB->query("SELECT COUNT(*) FROM review_decisions WHERE lead_id = {$nrIdB}")->fetchColumn() === 1);

    // --- B5. nothing written on rejection (atomicity) ---------------------------
    $cntBefore = (int)$pdoB->query('SELECT COUNT(*) FROM review_decisions')->fetchColumn();
    try {
        ReviewQueue::transition($pdoB, $idsB['qualified'], 'disqualified', 'tester');
    } catch (\App\Exceptions\OutreachException) {
    }
    nr_check('rejected transition writes no audit row',
        (int)$pdoB->query('SELECT COUNT(*) FROM review_decisions')->fetchColumn() === $cntBefore);
    nr_check('rejected transition leaves the lead status untouched',
        $pdoB->query("SELECT status FROM leads WHERE id = {$idsB['qualified']}")->fetchColumn() === 'Qualified');

    // --- B6. queue listing -------------------------------------------------------
    $pdoB->exec("INSERT INTO leads (company_name, email, status, lead_score, country_code, consent_status) VALUES " .
        "('Echo Co', 'echo@echo.test', 'Needs Review', 55, 'US', 'express')");
    $queue = ReviewQueue::listQueue($pdoB, ReviewQueue::FILTER_QUEUE, 20, 0);
    nr_check('listQueue(queue) returns the still-awaiting leads',
        $queue['total'] === 1 && count($queue['rows']) === 1 && (int)$queue['rows'][0]['lead_score'] === 55);
    $decided = ReviewQueue::listQueue($pdoB, ReviewQueue::FILTER_DECIDED, 20, 0);
    nr_check('listQueue(decided) returns the decided history with who/when',
        $decided['total'] === 2 && $decided['rows'][0]['decided_by'] === 'tester');

    // --- B7. approval is the only path back to sequence eligibility -------------
    $pdoB->exec("CREATE TABLE campaigns (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL DEFAULT 'active') ENGINE=InnoDB");
    $pdoB->exec("CREATE TABLE templates (id INT AUTO_INCREMENT PRIMARY KEY, campaign_id INT NOT NULL, subject VARCHAR(255), body TEXT, step_order INT DEFAULT 1, delay_days INT DEFAULT 0) ENGINE=InnoDB");
    $pdoB->exec("CREATE TABLE task_queue (id INT AUTO_INCREMENT PRIMARY KEY, lead_id INT, task_type ENUM('Enrichment','EmailOutreach','SocialOutreach','Qualify','Enrich','Draft') NOT NULL, payload JSON, status ENUM('Pending','In Progress','Completed','Failed') DEFAULT 'Pending', scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, processed_at TIMESTAMP NULL, retry_count INT DEFAULT 0, error_message TEXT) ENGINE=InnoDB");
    $pdoB->exec("CREATE TABLE suppression_list (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NULL, reason VARCHAR(50) NOT NULL DEFAULT 'unsubscribe', source VARCHAR(100) NULL, UNIQUE KEY uq_suppression_email (email)) ENGINE=InnoDB");
    // sequence tables needed for launch
    nr_apply_migration($pdoB, nr_repo_root() . '/migrations/2026-09-28-phase4-sequences.sql');
    $pdoB->exec("INSERT INTO campaigns (name) VALUES ('Post-Review')");
    $pdoB->exec("INSERT INTO templates (campaign_id, subject, body, step_order, delay_days) VALUES (1, 'Hi', '<p>x</p>', 1, 0)");
    $pdoB->exec("UPDATE leads SET campaign_id = 1 WHERE id IN ({$nrIdB}, {$nrId2})");
    $rB = SequenceManager::launch($pdoB, 1);
    nr_check('approved lead enrolls on launch (sequence-eligible)',
        (int)$pdoB->query("SELECT COUNT(*) FROM sequence_enrollments WHERE lead_id = {$nrIdB}")->fetchColumn() === 1);
    nr_check('disqualified lead does not enroll',
        (int)$pdoB->query("SELECT COUNT(*) FROM sequence_enrollments WHERE lead_id = {$nrId2}")->fetchColumn() === 0);
    nr_check('launch counts the disqualified lead as ineligible', $rB['skipped_status'] >= 1);
} catch (\Throwable $e) {
    nr_check('review-workflow suite completed without exception', false, get_class($e) . ': ' . substr($e->getMessage(), 0, 160));
} finally {
    nr_restore_db_config();
}

exit(nr_summary('test_state_transitions.php'));
