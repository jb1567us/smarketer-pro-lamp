#!/usr/bin/env php
<?php
/**
 * Phase 2 draft-reviewer tests.
 *
 * Usage: php tests/phase0/run_reviewer_tests.php
 *
 * Spins up a scratch MariaDB database (phase2_test), applies the
 * migrations/2026-09-24-draft-reviewer.sql migration, and exercises the
 * reviewer WITHOUT any real LLM or JEV network calls:
 *   - action subclasses stub out callAgent() (protected) with canned JSON
 *   - reviewDraft() is stubbed with canned verdicts to drive the loop
 *
 *   A. Migration applies; buildDraft persists one drafts row per version
 *      (pending_review, attempt number, template_id, campaign scoping).
 *   B. Revision notes reach the LLM context (regeneration feedback path).
 *   C. Off mode: buildAndReview is behavior-identical to the old flow —
 *      one draft, escalated to human review, zero regenerations.
 *   D. Live loop: reject -> regenerate w/ feedback -> approve.
 *   E. Live loop: 3 rejections -> escalate after MAX_REVISIONS (2).
 *   F. Fail-closed: JEV error verdict -> immediate human escalation.
 *   G. legacyReview parses the LLM critique JSON (feedback writer only).
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

try {
try {
    $dbUser = 'phase2_test'; $dbPass = 't_' . bin2hex(random_bytes(8));
    $sh = function (string $cmd): void {
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) { throw new RuntimeException("shell failed: {$cmd}\n" . implode("\n", $out)); }
    };
    $sh("mysql -u root -e \"DROP DATABASE IF EXISTS phase2_test; CREATE DATABASE phase2_test CHARACTER SET utf8mb4;\"");
    $sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON phase2_test.* TO '{$dbUser}'@'%'; GRANT ALL ON phase2_test.* TO '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;\"");

    if (!is_dir($configDir)) { mkdir($configDir, 0755, true); }
    test_db_use_env('127.0.0.1', 'phase2_test', $dbUser, $dbPass);

    require $repo . '/includes/autoload.php';
    $pdo = \App\Database::getConnection();

    // Minimal schema + the Phase 2 migration (also proves the SQL applies).
    $pdo->exec("CREATE TABLE settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE campaigns (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE templates (id INT AUTO_INCREMENT PRIMARY KEY, campaign_id INT NOT NULL, subject VARCHAR(500), body TEXT, step_order INT DEFAULT 1) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE leads (id INT AUTO_INCREMENT PRIMARY KEY, company_name VARCHAR(255) NOT NULL, contact_name VARCHAR(255), email VARCHAR(255) UNIQUE NOT NULL, website VARCHAR(255), status VARCHAR(50) DEFAULT 'New', lead_score INT DEFAULT 0, notes TEXT, campaign_id INT NULL) ENGINE=InnoDB");
    $mig = file_get_contents($repo . '/migrations/2026-09-24-draft-reviewer.sql');
    if ($mig === false) { throw new RuntimeException('migration file missing'); }
    $pdo->exec($mig);
    ok(true, 'migration 2026-09-24-draft-reviewer.sql applies cleanly');

    $pdo->exec("INSERT INTO campaigns (name) VALUES ('Pilot')");
    $pdo->exec("INSERT INTO templates (campaign_id, subject, body, step_order) VALUES (1, 'T-subject', 'T-body', 1)");
    $pdo->exec("INSERT INTO leads (company_name, contact_name, email, campaign_id, notes) VALUES ('Acme', 'Ann', 'ann@acme.test', 1, 'ENRICHMENT: 50 staff')");
    // JEV explicitly OFF for the behavior-preservation tests (shipped default
    // is enabled+live since the 2026-10-01 ship call; existing stored rows
    // always win over defaults).
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('jev_enabled', '0') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    \App\Jev\DecisionTier::resetForTests();
    $router = new \App\Routers\SmartLLMRouter($pdo);

    $drafter = new class($pdo, $router) extends \App\Actions\DraftOutreachAction {
        public array $seenContext = [];
        public int $calls = 0;
        protected function callAgent(string $persona, string $goal, string $context, ?int $leadId = null): array {
            $this->calls++;
            $this->seenContext[] = $context;
            return ['email_subject' => "Subject v{$this->calls}", 'email_body' => "Body v{$this->calls}"];
        }
    };

    // --- Test A: drafts row per version ----------------------------------
    echo "A. Draft persistence:\n";
    $d1 = $drafter->buildDraft(1);
    ok($d1['draft_id'] > 0, 'buildDraft returns draft_id');
    ok($d1['template_id'] === 1, 'template_id captured');
    $rows = $pdo->query("SELECT * FROM drafts ORDER BY id")->fetchAll(\App\PDO::FETCH_ASSOC);
    ok(count($rows) === 1, 'one drafts row inserted');
    ok($rows[0]['status'] === 'pending_review', 'initial status pending_review');
    ok((int)$rows[0]['attempts'] === 1, 'attempt 1 recorded');
    ok($rows[0]['subject'] === 'Subject v1' && $rows[0]['lead_id'] == 1 && $rows[0]['campaign_id'] == 1, 'row carries lead/campaign/subject/body');

    // --- Test B: revision notes reach the LLM -----------------------------
    echo "B. Regeneration feedback:\n";
    $d2 = $drafter->buildDraft(1, 'Make the CTA clearer and cut fluff.', 2);
    ok(str_contains(end($drafter->seenContext), 'REVIEWER FEEDBACK'), 'revision notes injected into LLM context');
    ok(str_contains(end($drafter->seenContext), 'Make the CTA clearer'), 'feedback text passed through');
    $row2 = $pdo->query("SELECT attempts FROM drafts WHERE id = " . (int)$d2['draft_id'])->fetch(\App\PDO::FETCH_ASSOC);
    ok((int)$row2['attempts'] === 2, 'attempt number stored on regeneration');

    // --- Test C: off mode = old behavior ---------------------------------
    echo "C. Off-mode behavior preservation:\n";
    $callsBefore = $drafter->calls;
    $res = $drafter->buildAndReview(1); // jev_enabled explicitly '0' -> off
    ok($res['review']['outcome'] === 'needs_human', 'off mode escalates to human review');
    ok($res['review']['revisions'] === 0, 'off mode performs zero regenerations');
    ok($drafter->calls === $callsBefore + 1, 'off mode makes exactly one draft LLM call (no reviewer LLM call)');
    $n = (int)$pdo->query("SELECT COUNT(*) c FROM drafts")->fetch(\App\PDO::FETCH_ASSOC)['c'];
    ok($n === 3, 'off mode added exactly one drafts row');
    $st = $pdo->query("SELECT status FROM drafts WHERE id = " . (int)$res['draft']['draft_id'])->fetch(\App\PDO::FETCH_ASSOC)['status'];
    ok($st === 'needs_human', 'draft marked needs_human in off mode');
    $lead = $pdo->query("SELECT status FROM leads WHERE id = 1")->fetch(\App\PDO::FETCH_ASSOC);
    ok($lead['status'] === 'Drafted', 'lead status still Drafted (old flow intact)');

    // --- Canned-verdict reviewer for the loop tests ----------------------
    $cannedReviewer = function (array $verdicts) use ($pdo, $router) {
        return new class($pdo, $router, $verdicts) extends \App\Actions\ReviewDraftAction {
            public array $verdicts; public int $i = 0; public int $legacyCalls = 0;
            public function __construct($pdo, $router, array $verdicts) {
                parent::__construct($pdo, $router); $this->verdicts = $verdicts;
            }
            public function reviewDraft(int $draftId): array {
                $v = $this->verdicts[min($this->i, count($this->verdicts) - 1)];
                $this->i++;
                return $v + ['latency_ms' => 5];
            }
            public function legacyReview(string $subject, string $body, string $leadContext = ''): array {
                $this->legacyCalls++;
                return ['approved' => false, 'critique' => 'Sharpen the CTA.', 'score' => 4];
            }
        };
    };
    $jevReject = ['approved' => false, 'score' => 30.0, 'confidence' => 0.9, 'source' => 'jev'];
    $jevApprove = ['approved' => true, 'score' => 85.0, 'confidence' => 0.9, 'source' => 'jev'];
    // Force live mode for the loop tests (canned verdicts; no network).
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('jev_enabled', '1'), ('jev_mode', 'live') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    \App\Jev\DecisionTier::resetForTests();
    ok(\App\Jev\DecisionTier::mode() === 'live', 'test harness reached live mode');

    // --- Test D: reject -> regenerate -> approve --------------------------
    echo "D. Live loop (reject, reject, approve):\n";
    $drafterD = clone $drafter;
    $revD = $cannedReviewer([$jevReject, $jevReject, $jevApprove]);
    $resD = $drafterD->buildAndReview(1, $revD);
    ok($resD['review']['outcome'] === 'approved', 'final outcome approved');
    ok($resD['review']['revisions'] === 2, 'two regenerations performed');
    ok($revD->legacyCalls === 2, 'LLM feedback written once per rejection');
    $rowsD = $pdo->query("SELECT id, status, attempts FROM drafts WHERE id >= " . (int)$resD['draft']['draft_id'] . " ORDER BY id")->fetchAll(\App\PDO::FETCH_ASSOC);
    ok(count($rowsD) === 3, 'three draft versions exist');
    ok($rowsD[0]['status'] === 'superseded' && $rowsD[1]['status'] === 'superseded', 'rejected versions marked superseded');
    ok($rowsD[2]['status'] === 'approved', 'final version marked approved');
    ok((int)$rowsD[2]['attempts'] === 3, 'attempt counter reaches 3');

    // --- Test E: max revisions -> escalate --------------------------------
    echo "E. Live loop (3 rejections -> escalate):\n";
    $drafterE = clone $drafter;
    $revE = $cannedReviewer([$jevReject, $jevReject, $jevReject]);
    $resE = $drafterE->buildAndReview(1, $revE);
    ok($resE['review']['outcome'] === 'needs_human', 'escalated after max revisions');
    ok($resE['review']['revisions'] === 2, 'never exceeds MAX_REVISIONS (2)');
    ok(str_contains($resE['review']['reason'], '2 revision'), 'escalation reason cites revision count');
    $lastId = (int)$resE['review']['draft_id'];
    $lastRow = $pdo->query("SELECT status, reviewer_notes FROM drafts WHERE id = {$lastId}")->fetch(\App\PDO::FETCH_ASSOC);
    ok($lastRow['status'] === 'needs_human', 'final draft marked needs_human');
    ok(str_contains($lastRow['reviewer_notes'], 'Escalated'), 'rejection reason attached for the human');

    // --- Test F: fail-closed on JEV error ----------------------------------
    echo "F. Fail-closed:\n";
    $drafterF = clone $drafter;
    $revF = $cannedReviewer([['approved' => false, 'score' => 0.0, 'confidence' => 0.0,
        'source' => 'human-review', 'reason' => 'JEV timed out']]);
    $resF = $drafterF->buildAndReview(1, $revF);
    ok($resF['review']['outcome'] === 'needs_human', 'JEV failure escalates, never approves');
    ok($resF['review']['revisions'] === 0, 'no regeneration on fail-closed verdict');
    ok($revF->legacyCalls === 0, 'no LLM feedback call on fail-closed verdict');

    // --- Test G: legacyReview parsing --------------------------------------
    echo "G. legacyReview (feedback writer):\n";
    $revG = new class($pdo, $router) extends \App\Actions\ReviewDraftAction {
        protected function callAgent(string $persona, string $goal, string $context, ?int $leadId = null): array {
            return ['approved' => false, 'critique' => "- Cut the opener\n- Stronger CTA", 'score' => 5];
        }
    };
    $fb = $revG->legacyReview('Subj', 'Body');
    ok($fb['approved'] === false && $fb['score'] === 5, 'verdict fields parsed');
    ok(str_contains($fb['critique'], 'Stronger CTA'), 'critique text returned for regeneration');

    echo "\n{$passed} passed, {$failures} failed\n";
    $exit = $failures > 0 ? 1 : 0;
} catch (\Throwable $e) {
    echo "FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $exit = 2;
}
} finally {
    // Restore/remove temp config even when the run dies midway.
    test_db_restore_env();
    exec("mysql -u root -e \"DROP DATABASE IF EXISTS phase2_test;\" 2>&1");
}
exit($exit ?? 0);
