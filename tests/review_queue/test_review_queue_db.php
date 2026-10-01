#!/usr/bin/env php
<?php
/**
 * Review queue DB integration tests (goal_67693fcbba4c, review workflow).
 *
 * Against a scratch MariaDB database:
 *   D1. migrations/2026-09-28-review-decisions.sql applies cleanly and is
 *       idempotent (apply twice -> no error, one table).
 *   D2. listQueue('queue') returns only needs_review leads, newest-fit
 *       first, with per-dimension scores parsed from the notes marker.
 *   D3. transition('approved'): status -> Qualified, exactly one
 *       review_decisions row (approved / decided_by / previous_status /
 *       fit_score snapshot), notes marker APPENDED (original notes kept).
 *   D4. Idempotent re-POST: approve again -> already_decided=true, no
 *       second audit row, status unchanged.
 *   D5. transition('disqualified'): status -> Unqualified + audit row.
 *   D6. Guards (fail-closed, nothing written): approve on a Qualified lead
 *       with no audit row, approve on an unknown lead id, empty reviewer,
 *       invalid decision -> all throw OutreachException; status and audit
 *       table untouched.
 *   D7. Guarded write: a lead that leaves needs_review between list and
 *       decide (simulated by flipping the status first) is rejected, not
 *       double-applied.
 *   D8. Sequence eligibility cross-check: after approve the lead's status
 *       is in SequenceManager::SENDABLE_LEAD_STATUSES; after disqualify it
 *       is not.
 *
 * Usage: php tests/review_queue/test_review_queue_db.php
 * The repo tree is left exactly as it was (scratch DB creds via env only).
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
$configDir = $repo . '/config';
require_once __DIR__ . '/../support/db_env.php';

$pass = 0;
$fail = 0;
function ok(bool $cond, string $name, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS: {$name}\n";
    } else {
        $fail++;
        echo "  FAIL: {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

try {
    $dbUser = 'review_test';
    $dbPass = 't_' . bin2hex(random_bytes(8));
    $dbName = 'review_test';
    $sh = function (string $cmd): void {
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException("shell failed: {$cmd}\n" . implode("\n", $out));
        }
    };
    $sh("mysql -u root -e \"DROP DATABASE IF EXISTS {$dbName}; CREATE DATABASE {$dbName} CHARACTER SET utf8mb4;\"");
    $sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON {$dbName}.* TO '{$dbUser}'@'%'; FLUSH PRIVILEGES;\"");
    // A fresh MariaDB ships anonymous ''@'localhost' users that shadow
    // 'user'@'%' for local TCP connections; grant the localhost host
    // explicitly so the test user always matches first.
    $sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON {$dbName}.* TO '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;\"");

    if (!is_dir($configDir)) {
        mkdir($configDir, 0755, true);
    }
    test_db_use_env('127.0.0.1', $dbName, $dbUser, $dbPass);

    require $repo . '/includes/autoload.php';
    $pdo = \App\Database::getConnection();

    $mysql = "mysql -h 127.0.0.1 -u {$dbUser} -p{$dbPass} {$dbName}";

    // --- Schema: minimal leads table, then the two migrations ---------------
    $pdo->exec("CREATE TABLE leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_name VARCHAR(255) NOT NULL,
        contact_name VARCHAR(255),
        email VARCHAR(255) UNIQUE NOT NULL,
        website VARCHAR(255),
        status ENUM('New','Enriched','Contacted','Qualified','Unqualified','Converted','Drafted') DEFAULT 'New',
        lead_score INT DEFAULT 0,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Subject 1's ENUM migration (DELIMITER-based: run through the mysql CLI).
    $sh("{$mysql} < " . escapeshellarg($repo . '/migrations/2026-09-28-needs-review-enum.sql'));
    $colType = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'status'")->fetchColumn();
    ok(str_contains((string)$colType, "'Needs Review'"), 'D0: Needs Review ENUM value present after subject-1 migration', (string)$colType);

    // D1: own migration applies cleanly and idempotently.
    ok(\App\ReviewQueue::auditTableExists($pdo) === false, 'D1: auditTableExists false before migration');
    $sh("{$mysql} < " . escapeshellarg($repo . '/migrations/2026-09-28-review-decisions.sql'));
    $sh("{$mysql} < " . escapeshellarg($repo . '/migrations/2026-09-28-review-decisions.sql')); // second apply: clean no-op
    ok(\App\ReviewQueue::auditTableExists($pdo) === true, 'D1: auditTableExists true after migration');
    $tbls = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'review_decisions'")->fetchColumn();
    ok((int)$tbls === 1, 'D1: review_decisions exists after double-apply');

    // --- Seed ----------------------------------------------------------------
    $marker = "\n\n[Qualification 2026-09-28]: Needs Review (fit 62/100, below qualify threshold 75) — Jev weighted ICP fit.\n"
        . "Dimensions: company_size=8/10, industry_fit=7/10, target_title=5/10, geography=7/10, trigger_signals=4/10.";
    $seed = $pdo->prepare('INSERT INTO leads (company_name, contact_name, email, status, lead_score, notes) VALUES (?, ?, ?, ?, ?, ?)');
    $seed->execute(['Acme Corp', 'Jane Doe', 'jane@acme.test', 'Needs Review', 62, 'Enrichment research: 200 employees, SaaS.' . $marker]);
    $leadA = (int)$pdo->lastInsertId();
    $seed->execute(['Beta LLC', 'Bob Smith', 'bob@beta.test', 'Needs Review', 55, 'Legacy-scored, no dimensions marker.']);
    $leadB = (int)$pdo->lastInsertId();
    $seed->execute(['Gamma Inc', 'Gina Ray', 'gina@gamma.test', 'Qualified', 88, 'Already qualified.']);
    $leadC = (int)$pdo->lastInsertId();
    $seed->execute(['Delta Co', 'Dan Poe', 'dan@delta.test', 'Unqualified', 20, 'Already unqualified.']);
    $leadD = (int)$pdo->lastInsertId();

    $statusOf = function (int $id) use ($pdo): string {
        $s = $pdo->prepare('SELECT status FROM leads WHERE id = ?');
        $s->execute([$id]);
        return (string)$s->fetchColumn();
    };
    $auditCount = function (int $id) use ($pdo): int {
        $s = $pdo->prepare('SELECT COUNT(*) FROM review_decisions WHERE lead_id = ?');
        $s->execute([$id]);
        return (int)$s->fetchColumn();
    };

    // --- D2: listQueue -------------------------------------------------------
    echo "D2: listQueue:\n";
    $list = \App\ReviewQueue::listQueue($pdo, 'queue', 20, 0);
    ok($list['total'] === 2, 'queue total is 2', json_encode($list['total']));
    ok(count($list['rows']) === 2, 'queue returns 2 rows');
    ok((int)$list['rows'][0]['id'] === $leadA, 'highest fit score first');
    ok(
        ($list['rows'][0]['dimensions']['company_size'] ?? null) === 8
        && count($list['rows'][0]['dimensions']) === 5,
        'per-dimension scores parsed from notes marker',
        json_encode($list['rows'][0]['dimensions'])
    );
    ok($list['rows'][1]['dimensions'] === [], 'legacy notes -> empty dimensions');
    ok(!array_key_exists('notes', $list['rows'][0]), 'raw notes not leaked in payload');
    $page = \App\ReviewQueue::listQueue($pdo, 'queue', 1, 1);
    ok($page['total'] === 2 && count($page['rows']) === 1 && (int)$page['rows'][0]['id'] === $leadB, 'pagination offset works');

    // --- D3: approve ---------------------------------------------------------
    echo "D3: approve transition:\n";
    $out = \App\ReviewQueue::transition($pdo, $leadA, 'approved', 'reviewer_jane');
    ok($out['already_decided'] === false && $out['new_status'] === 'Qualified', 'approve returns new status Qualified');
    ok($statusOf($leadA) === 'Qualified', 'lead status is Qualified in DB');
    ok($auditCount($leadA) === 1, 'exactly one audit row');
    $audit = $pdo->query("SELECT * FROM review_decisions WHERE lead_id = {$leadA}")->fetch(\App\PDO::FETCH_ASSOC);
    ok(
        $audit['decision'] === 'approved'
        && $audit['decided_by'] === 'reviewer_jane'
        && $audit['previous_status'] === 'Needs Review'
        && (int)$audit['fit_score_snapshot'] === 62,
        'audit row captures decision/by/previous/fit snapshot',
        json_encode($audit)
    );
    $notes = $pdo->query("SELECT notes FROM leads WHERE id = {$leadA}")->fetchColumn();
    ok(
        str_contains((string)$notes, 'Enrichment research: 200 employees, SaaS.')
        && str_contains((string)$notes, '[Human Review')
        && str_contains((string)$notes, 'approved by reviewer_jane'),
        'notes marker APPENDED, original notes preserved'
    );

    // --- D4: idempotent re-POST ----------------------------------------------
    echo "D4: idempotent re-POST:\n";
    $out2 = \App\ReviewQueue::transition($pdo, $leadA, 'approved', 'reviewer_jane');
    ok($out2['already_decided'] === true && $out2['new_status'] === 'Qualified', 're-POST reports already_decided');
    ok($auditCount($leadA) === 1, 'no duplicate audit row on re-POST');
    // Wrong decision on an already-decided lead is still rejected.
    $threw = false;
    try {
        \App\ReviewQueue::transition($pdo, $leadA, 'disqualified', 'reviewer_jane');
    } catch (\App\Exceptions\OutreachException $e) {
        $threw = true;
    }
    ok($threw, 'opposite decision on decided lead throws');
    ok($auditCount($leadA) === 1 && $statusOf($leadA) === 'Qualified', 'nothing written by rejected flip');

    // --- D5: disqualify -------------------------------------------------------
    echo "D5: disqualify transition:\n";
    $out3 = \App\ReviewQueue::transition($pdo, $leadB, 'disqualified', 'reviewer_jane');
    ok($out3['new_status'] === 'Unqualified' && $statusOf($leadB) === 'Unqualified', 'disqualify -> Unqualified');
    ok($auditCount($leadB) === 1, 'audit row written for disqualify');

    // --- D6: guards -----------------------------------------------------------
    echo "D6: transition guards:\n";
    $cases = [
        'approve on Qualified lead without audit row' => fn() => \App\ReviewQueue::transition($pdo, $leadC, 'approved', 'r'),
        'disqualify on Unqualified lead without audit row' => fn() => \App\ReviewQueue::transition($pdo, $leadD, 'disqualified', 'r'),
        'unknown lead id' => fn() => \App\ReviewQueue::transition($pdo, 999999, 'approved', 'r'),
        'empty reviewer' => fn() => \App\ReviewQueue::transition($pdo, $leadC, 'approved', '  '),
        'invalid decision' => fn() => \App\ReviewQueue::transition($pdo, $leadC, 'maybe', 'r'),
        'zero lead id' => fn() => \App\ReviewQueue::transition($pdo, 0, 'approved', 'r'),
    ];
    foreach ($cases as $name => $fn) {
        $threw = false;
        try {
            $fn();
        } catch (\App\Exceptions\OutreachException $e) {
            $threw = true;
        }
        ok($threw, "guard: {$name} throws");
    }
    ok(
        $statusOf($leadC) === 'Qualified' && $statusOf($leadD) === 'Unqualified'
        && $auditCount($leadC) === 0 && $auditCount($leadD) === 0,
        'guards wrote nothing (fail-closed)'
    );

    // --- D7: status changed between list and decide ---------------------------
    echo "D7: concurrent status change:\n";
    $seed->execute(['Epsilon GmbH', 'Eve Lin', 'eve@epsilon.test', 'Needs Review', 70, 'notes']);
    $leadE = (int)$pdo->lastInsertId();
    $pdo->exec("UPDATE leads SET status = 'Contacted' WHERE id = {$leadE}"); // someone/something moved it
    $threw = false;
    try {
        \App\ReviewQueue::transition($pdo, $leadE, 'approved', 'r');
    } catch (\App\Exceptions\OutreachException $e) {
        $threw = true;
    }
    ok($threw, 'transition rejected when lead left needs_review');
    ok($statusOf($leadE) === 'Contacted' && $auditCount($leadE) === 0, 'no partial write');

    // --- D8: eligibility cross-check ------------------------------------------
    echo "D8: sequence eligibility:\n";
    ok(
        in_array($statusOf($leadA), \App\SequenceManager::SENDABLE_LEAD_STATUSES, true),
        'approved lead (Qualified) is sequence-sendable'
    );
    ok(
        !in_array($statusOf($leadB), \App\SequenceManager::SENDABLE_LEAD_STATUSES, true),
        'disqualified lead (Unqualified) is not sequence-sendable'
    );

    // --- decided history -------------------------------------------------------
    echo "history: listQueue decided filter:\n";
    $hist = \App\ReviewQueue::listQueue($pdo, 'decided', 20, 0);
    ok($hist['total'] === 2, 'decided total is 2', json_encode($hist['total']));
    $byLead = [];
    foreach ($hist['rows'] as $row) {
        $byLead[(int)$row['lead_id']] = $row;
    }
    ok(
        ($byLead[$leadA]['decision'] ?? '') === 'approved'
        && ($byLead[$leadA]['decided_by'] ?? '') === 'reviewer_jane'
        && !empty($byLead[$leadA]['decided_at']),
        'decided view shows who/when for approved lead'
    );
    ok(($byLead[$leadB]['decision'] ?? '') === 'disqualified', 'decided view includes disqualified lead');
} catch (\Throwable $e) {
    echo "  ERROR: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $fail++;
} finally {
    // Drop the scratch credentials from the process environment.
    test_db_restore_env();
}

echo "  -- test_review_queue_db.php: {$pass} pass, {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
