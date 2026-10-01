#!/usr/bin/env php
<?php
/**
 * Phase 0 pipeline-repair tests.
 *
 * Usage: php tests/phase0/run_phase0_tests.php
 *
 * Spins up a scratch MariaDB database (phase0_test), points the DB_*
 * process environment at it (tests/support/db_env.php — no files written),
 * and exercises the repaired pipeline WITHOUT any LLM:
 * action subclasses stub out callAgent() (protected) with canned responses.
 *
 *   A. DraftOutreachAction — draft is scoped to the lead's OWN campaign
 *      (critical defect C1 regression test), fails loudly with no campaign
 *      or no templates, persists subject/body + status.
 *   B. QualifyLeadAction — enrichment notes are PRESERVED, verdict appended
 *      (critical defect C2 regression test).
 *   C. trigger_task.php (via CLI) — legacy 'Enrichment' alias maps to
 *      'Enrich' (was: inserted then instantly Failed), unknown types 400,
 *      bulk Pipeline queues Enrich→Qualify→Draft staggered per lead.
 *
 * The repo tree is left exactly as it was (scratch DB creds via env only).
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
$configDir = $repo . '/config';
require_once __DIR__ . '/../support/db_env.php';

$failures = 0; $passed = 0;
function ok(bool $cond, string $name): void {
    global $failures, $passed;
    if ($cond) { $passed++; echo "  PASS: {$name}\n"; }
    else { $failures++; echo "  FAIL: {$name}\n"; }
}
function expectThrow(callable $fn, string $needle, string $name): void {
    try { $fn(); ok(false, $name . ' (no exception thrown)'); }
    catch (\Throwable $e) { ok(stripos($e->getMessage(), $needle) !== false, $name . ' [got: ' . substr($e->getMessage(), 0, 80) . ']'); }
}

try {
    $dbUser = 'phase0_test'; $dbPass = 't_' . bin2hex(random_bytes(8));
    $sh = function (string $cmd): void {
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) { throw new RuntimeException("shell failed: {$cmd}\n" . implode("\n", $out)); }
    };
    $sh("mysql -u root -e \"DROP DATABASE IF EXISTS phase0_test; CREATE DATABASE phase0_test CHARACTER SET utf8mb4;\"");
    $sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON phase0_test.* TO '{$dbUser}'@'%'; GRANT ALL ON phase0_test.* TO '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;\"");

    if (!is_dir($configDir)) { mkdir($configDir, 0755, true); }
    test_db_use_env('127.0.0.1', 'phase0_test', $dbUser, $dbPass);

    require $repo . '/includes/autoload.php';
    $pdo = \App\Database::getConnection();

    // Minimal schema for the pipeline pieces under test.
    $pdo->exec("CREATE TABLE settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE campaigns (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE templates (id INT AUTO_INCREMENT PRIMARY KEY, campaign_id INT NOT NULL, subject VARCHAR(500), body TEXT, step_order INT DEFAULT 1) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE leads (id INT AUTO_INCREMENT PRIMARY KEY, company_name VARCHAR(255) NOT NULL, contact_name VARCHAR(255), email VARCHAR(255) UNIQUE NOT NULL, website VARCHAR(255), status VARCHAR(50) DEFAULT 'New', lead_score INT DEFAULT 0, notes TEXT, campaign_id INT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE task_queue (id INT AUTO_INCREMENT PRIMARY KEY, lead_id INT, task_type ENUM('Enrichment','EmailOutreach','SocialOutreach','Qualify','Enrich','Draft') NOT NULL, status ENUM('Pending','In Progress','Completed','Failed') DEFAULT 'Pending', scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    // Phase 2: buildDraft() persists every draft to the drafts table.
    $mig = file_get_contents(dirname(__DIR__, 2) . '/migrations/2026-09-24-draft-reviewer.sql');
    if ($mig === false) { throw new RuntimeException('draft-reviewer migration missing'); }
    $pdo->exec($mig);

    // Seed: two campaigns with DIFFERENT templates (the C1 regression trap).
    $pdo->exec("INSERT INTO campaigns (name) VALUES ('Product Insights Pilot'), ('Other Campaign')");
    $pdo->exec("INSERT INTO templates (campaign_id, subject, body, step_order) VALUES (1, 'Pilot subject', 'Pilot body template', 1), (2, 'WRONG subject', 'WRONG body template', 1)");
    $pdo->exec("INSERT INTO leads (company_name, email, campaign_id, notes) VALUES " .
        "('Acme', 'acme@example.com', 1, 'ENRICHMENT RESEARCH: 50 employees'), " .
        "('Beta', 'beta@example.com', NULL, ''), " .
        "('Gamma', 'gamma@example.com', 2, '')");
    // Campaign 3 exists but has no templates.
    $pdo->exec("INSERT INTO campaigns (name) VALUES ('Empty Campaign')");
    $pdo->exec("INSERT INTO leads (company_name, email, campaign_id) VALUES ('Delta', 'delta@example.com', 3)");

    // --- Test A: DraftOutreachAction -------------------------------------
    echo "A. DraftOutreachAction (campaign scoping):\n";

    $router = new \App\Routers\SmartLLMRouter($pdo); // constructs with no side effects
    $drafter = new class($pdo, $router) extends \App\Actions\DraftOutreachAction {
        public array $seenContext = [];
        protected function callAgent(string $persona, string $goal, string $context, ?int $leadId = null): array {
            $this->seenContext[] = $context;
            return ['email_subject' => 'Personalized subject', 'email_body' => 'Personalized body'];
        }
    };

    $draft = $drafter->buildDraft(1);
    ok($draft['subject'] === 'Personalized subject', 'returns LLM subject');
    ok($draft['campaign_id'] === 1 && $draft['campaign_name'] === 'Product Insights Pilot', 'reports the lead\'s own campaign');
    ok(str_contains($drafter->seenContext[0], 'Pilot body template'), 'LLM context used campaign A template');
    ok(!str_contains($drafter->seenContext[0], 'WRONG'), 'LLM context did NOT use the other campaign template (C1)');
    $row = $pdo->query("SELECT status, notes FROM leads WHERE id = 1")->fetch(\App\PDO::FETCH_ASSOC);
    ok($row['status'] === 'Drafted', 'lead status set to Drafted');
    ok(str_contains($row['notes'], 'ENRICHMENT RESEARCH'), 'pre-existing notes preserved by draft');
    ok(str_contains($row['notes'], 'Personalized body'), 'draft appended to notes');

    // Lead in campaign B must get campaign B's template (C1, other direction).
    $drafter2 = clone $drafter;
    $drafter2->buildDraft(3);
    ok(str_contains(end($drafter2->seenContext), 'WRONG body template'), 'campaign-B lead drafts from campaign-B template');

    expectThrow(fn() => $drafter->buildDraft(2), 'no campaign assigned', 'lead without campaign fails loudly');
    expectThrow(fn() => $drafter->buildDraft(4), 'no templates', 'campaign without templates fails loudly');
    expectThrow(fn() => $drafter->buildDraft(999), 'not found', 'missing lead fails loudly');

    // --- Test B: QualifyLeadAction (notes preservation) ------------------
    echo "B. QualifyLeadAction (notes preservation):\n";

    $qualifier = new class($pdo, $router) extends \App\Actions\QualifyLeadAction {
        protected function callAgent(string $persona, string $goal, string $context, ?int $leadId = null): array {
            return ['qualified' => true, 'score' => 85, 'reason' => 'Great ICP fit'];
        }
    };
    ok($qualifier->execute(1) === true, 'execute returns true');
    $row = $pdo->query("SELECT status, lead_score, notes FROM leads WHERE id = 1")->fetch(\App\PDO::FETCH_ASSOC);
    ok($row['status'] === 'Qualified' && (int)$row['lead_score'] === 85, 'status + score stored');
    ok(str_contains($row['notes'], 'ENRICHMENT RESEARCH: 50 employees'), 'enrichment notes NOT destroyed (C2)');
    ok(str_contains($row['notes'], '[Qualification'), 'qualification verdict appended with marker');
    ok(str_contains($row['notes'], 'Great ICP fit'), 'qualification reason recorded');

    // --- Test B2: EnrichLeadAction (notes preservation, same defect class) -----
    echo "B2. EnrichLeadAction (notes preservation):\n";

    $enricher = new class($pdo, $router) extends \App\Actions\EnrichLeadAction {
        public int $calls = 0;
        protected function callAgent(string $persona, string $goal, string $context, ?int $leadId = null): array {
            $this->calls++;
            // Second call returns arrays, as the real model sometimes does.
            if ($this->calls > 1) { return ['industry' => ['SaaS', 'DevTools'], 'pain_points' => ['churn', 'cac']]; }
            return ['industry' => 'SaaS', 'pain_points' => 'churn'];
        }
    };
    // Lead 2 starts with empty notes; give it a prior note first.
    $pdo->exec("UPDATE leads SET notes = 'PRIOR IMPORT NOTE', status = 'New' WHERE id = 2");
    ok($enricher->execute(2) === true, 'enrich execute returns true');
    $notes = $pdo->query("SELECT notes FROM leads WHERE id = 2")->fetchColumn();
    ok(str_contains($notes, 'PRIOR IMPORT NOTE'), 'pre-existing notes preserved by enrich');
    ok(str_contains($notes, '[Enrichment'), 'enrichment block appended with marker');
    // Re-running enrich must replace, not duplicate, the enrichment block.
    // (Second stubbed call returns arrays — verifies array normalization.)
    $enricher->execute(2);
    $notes2 = $pdo->query("SELECT notes FROM leads WHERE id = 2")->fetchColumn();
    ok(substr_count($notes2, '[Enrichment') === 1, 're-enrich replaces block (no duplication)');
    ok(str_contains($notes2, 'SaaS; DevTools') && str_contains($notes2, 'churn; cac'), 'array fields normalized to strings');
    ok(str_contains($notes2, 'PRIOR IMPORT NOTE'), 'prior notes survive re-enrich');
    // A qualification appended AFTER enrichment must survive a later re-enrich.
    $pdo->exec("UPDATE leads SET notes = CONCAT(notes, '\n\n[Qualification 2026-09-24]: Qualified (score 90) — x') WHERE id = 2");
    $enricher->execute(2);
    $notes3 = $pdo->query("SELECT notes FROM leads WHERE id = 2")->fetchColumn();
    ok(str_contains($notes3, '[Qualification'), 'qualification verdict survives re-enrich');
    ok(substr_count($notes3, '[Enrichment') === 1, 'still exactly one enrichment block');

    // --- Test C: trigger_task.php over real HTTP -------------------------------
    echo "C. trigger_task.php (type mapping + bulk pipeline):\n";

    // Stub auth: predefine App\Auth so the autoloader never loads the real one.
    $prepend = sys_get_temp_dir() . '/phase0_auth_stub.php';
    file_put_contents($prepend, "<?php\nnamespace App;\nclass Auth { public static function requireApiAuth(): void {} }\n");

    // Serve the repo with PHP's built-in web server (php://input is empty
    // under CLI, so real HTTP is the faithful harness here).
    $port = 18099;
    $serverCmd = sprintf(
        'php -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s >/tmp/phase0_httpd.log 2>&1 & echo $!',
        escapeshellarg($prepend), $port, escapeshellarg($repo)
    );
    $srvPid = (int)trim(shell_exec($serverCmd));
    // Wait for the socket to accept connections.
    $up = false;
    for ($i = 0; $i < 50; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
        if ($fp) { $up = true; fclose($fp); break; }
        usleep(100000);
    }
    if (!$up) { throw new RuntimeException('test HTTP server did not start; see /tmp/phase0_httpd.log'); }

    $runTrigger = function (array $payload) use ($port): array {
        $ch = curl_init("http://127.0.0.1:{$port}/api/trigger_task.php");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, json_decode((string)$body, true), (string)$body];
    };

    // Legacy alias maps to the processor-handled type (was: Failed instantly).
    [$code, $res] = $runTrigger(['lead_id' => 1, 'task_type' => 'Enrichment', 'defer' => true]);
    ok($code === 200 && ($res['success'] ?? false), 'legacy Enrichment accepted (deferred)');
    $t = $pdo->query("SELECT task_type, status FROM task_queue ORDER BY id DESC LIMIT 1")->fetch(\App\PDO::FETCH_ASSOC);
    ok($t['task_type'] === 'Enrich' && $t['status'] === 'Pending', "stored as 'Enrich'/Pending, not 'Enrichment'");

    [$code, $res] = $runTrigger(['lead_id' => 1, 'task_type' => 'Bogus', 'defer' => true]);
    ok($code === 400 && ($res['success'] ?? true) === false, 'unknown task_type rejected (HTTP 400)');

    [$code, $res] = $runTrigger(['lead_id' => 999, 'task_type' => 'Enrich', 'defer' => true]);
    ok($code === 404 && ($res['success'] ?? true) === false, 'missing lead rejected (HTTP 404)');

    // Bulk pipeline: 2 leads × 3 steps, staggered scheduled_at.
    $pdo->exec("DELETE FROM task_queue");
    [$code, $res, $raw] = $runTrigger(['lead_ids' => [1, 3], 'task_type' => 'Pipeline']);
    ok($code === 200 && ($res['success'] ?? false) && ($res['queued'] ?? 0) === 6, 'pipeline queued 6 tasks for 2 leads');
    $rows = $pdo->query("SELECT lead_id, task_type, scheduled_at FROM task_queue ORDER BY lead_id, scheduled_at")->fetchAll(\App\PDO::FETCH_ASSOC);
    $types1 = array_column(array_filter($rows, fn($r) => $r['lead_id'] == 1), 'task_type');
    ok(array_values($types1) === ['Enrich', 'Qualify', 'Draft'], 'per-lead step order Enrich→Qualify→Draft');
    $times = array_column(array_filter($rows, fn($r) => $r['lead_id'] == 1), 'scheduled_at');
    ok($times[0] <= $times[1] && $times[1] <= $times[2] && $times[0] < $times[2], 'steps staggered in scheduled_at');

    [$code, $res] = $runTrigger(['lead_ids' => [], 'task_type' => 'Pipeline']);
    ok(($res['success'] ?? true) === false, 'empty lead_ids rejected');

    @unlink($prepend);
    // Stop the test HTTP server.
    if ($srvPid > 0) { exec("kill {$srvPid} 2>/dev/null"); }

    echo "\n{$passed} passed, {$failures} failed\n";
    $exitCode = $failures > 0 ? 1 : 0;
} finally {
    // Restore the repo tree exactly.
    if (isset($srvPid) && $srvPid > 0) { exec("kill {$srvPid} 2>/dev/null"); }
    test_db_restore_env();
    exec("mysql -u root -e \"DROP DATABASE IF EXISTS phase0_test;\" 2>&1");
}
exit($exitCode ?? 2);
