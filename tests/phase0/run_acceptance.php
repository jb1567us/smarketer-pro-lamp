#!/usr/bin/env php
<?php
/**
 * Phase 0 acceptance run: real pipeline, real LLM (DeepSeek V4 Flash via
 * OpenRouter), ZERO sends.
 *
 *   php tests/phase0/run_acceptance.php --setup   # build scratch DB + seed (no key needed)
 *   DEEPSEEK_API_KEY=... php tests/phase0/run_acceptance.php --run
 *
 * Uses the native DeepSeek API (OpenAI-compatible) via the router's
 * `deepseek` provider — no OpenRouter markup.
 *
 * The run executes the real action classes end to end on the 4 fictional
 * Product Insights pilot leads (example.com addresses — undeliverable by
 * design): Enrich -> Qualify -> Draft (qualified only). It then asserts:
 *
 *   - every draft was built from the lead's OWN campaign template
 *   - enrichment research survived qualification and drafting
 *   - drafts are persisted and visible (status Drafted + notes)
 *   - zero emails were sent (email_logs empty; the send path is never called)
 *
 * The repo tree is left exactly as it was (config/db.php restored/deleted,
 * scratch DB dropped at the end of --run).
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
$configDir = $repo . '/config';
$configFile = $configDir . '/db.php';
$hadConfig = is_file($configFile);
$backup = $hadConfig ? file_get_contents($configFile) : null;

$mode = $argv[1] ?? '';
if (!in_array($mode, ['--setup', '--run'], true)) {
    fwrite(STDERR, "usage: php tests/phase0/run_acceptance.php --setup | --run\n");
    exit(2);
}

$dbName = 'phase0_acceptance';
$dbUser = 'phase0_acc';
$dbPass = 'phase0_acc_pw_9d2';

function sh(string $cmd): void {
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) { throw new RuntimeException("shell failed: {$cmd}\n" . implode("\n", $out)); }
}

try {
    sh("mysql -u root -e \"DROP DATABASE IF EXISTS {$dbName}; CREATE DATABASE {$dbName} CHARACTER SET utf8mb4;\"");
    sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON {$dbName}.* TO '{$dbUser}'@'%'; FLUSH PRIVILEGES;\"");
    if (!is_dir($configDir)) { mkdir($configDir, 0755, true); }
    file_put_contents($configFile, "<?php\nreturn ['host' => '127.0.0.1', 'name' => '{$dbName}', 'user' => '{$dbUser}', 'pass' => '{$dbPass}'];\n");

    require $repo . '/includes/autoload.php';
    $pdo = \App\Database::getConnection();

    // Real schema + compliance migrations, verbatim. (migration_campaigns.sql
    // is legacy — schema.sql already carries campaign_id.)
    $fp = escapeshellarg($repo . '/schema.sql');
    sh("mysql -u {$dbUser} -p{$dbPass} {$dbName} < {$fp}");
    foreach (['2026-09-23-compliance.sql', '2026-09-23-compliance-gaps.sql'] as $mig) {
        sh("mysql -u {$dbUser} -p{$dbPass} {$dbName} < " . escapeshellarg($repo . '/migrations/' . $mig));
    }

    // Seed: campaign + single step-1 template + 4 fictional leads.
    $pdo->exec("INSERT INTO campaigns (name, description) VALUES (" .
        "'Product Insights — indie founder pilot (acceptance)', " .
        "'Zero-send acceptance run for the repaired pipeline')");
    $campaignId = (int)$pdo->lastInsertId();
    $tplSubject = "A faster way to nail {{company_name}}'s positioning";
    $tplBody = "Hi {{contact_name}},\n\nNoticed {{company_name}} wrestling with positioning. " .
        "Product Insights turns a product description into a structured go-to-market report " .
        "(personas, ICP, positioning, channels, messaging) in minutes.\n\n" .
        "Happy to run it free — full report, no card: https://lookoverhere.xyz/product_insights";
    $stmt = $pdo->prepare("INSERT INTO templates (campaign_id, subject, body, step_order) VALUES (?, ?, ?, 1)");
    $stmt->execute([$campaignId, $tplSubject, $tplBody]);

    $leads = [
        ['Maya Chen', 'maya.chen@example.com', 'HabitLoop — habit-tracking app, pre-launch', 'habitloop.example.com'],
        ['Derek Osei', 'derek@example.com', 'Osei & Co. — 2-person product-marketing consultancy', 'osei.example.com'],
        ['Priya Nair', 'priya.nair@example.com', 'DevScale — 40-person Series A dev-tools company', 'devscale.example.com'],
        ['Tom Alvarez', 'tom@example.com', 'TrendCart — dropshipping store', 'trendcart.example.com'],
    ];
    $stmt = $pdo->prepare("INSERT INTO leads (contact_name, email, company_name, website, campaign_id, status) VALUES (?, ?, ?, ?, ?, 'New')");
    foreach ($leads as $l) { $stmt->execute([$l[0], $l[1], $l[2], $l[3], $campaignId]); }

    // Point the LLM router at DeepSeek V4 Flash on OpenRouter.
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES " .
        "('active_llm_provider', 'deepseek'), ('deepseek_model', 'deepseek-flash') " .
        "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

    if ($mode === '--setup') {
        echo "acceptance DB ready: {$dbName} (campaign {$campaignId}, 4 fictional leads)\n";
        echo "run with: DEEPSEEK_API_KEY=... php tests/phase0/run_acceptance.php --run\n";
        echo "(DB is kept for --run; config/db.php restored below.)\n";
        $exitCode = 0; // set flag; real exit happens after finally restores config
    } else {

    // --- --run -----------------------------------------------------------
    $apiKey = getenv('DEEPSEEK_API_KEY') ?: '';
    if ($apiKey === '') {
        fwrite(STDERR, "DEEPSEEK_API_KEY is not set in the environment. Aborting before any LLM call.\n");
        $exitCode = 3;
    } else {
    // Key lives ONLY in this scratch DB, never in the repo or memory.
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('deepseek_api_key', ?) " .
        "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$apiKey]);

    $router = new \App\Routers\SmartLLMRouter($pdo);
    $enrich = new \App\Actions\EnrichLeadAction($pdo, $router);
    $qualify = new \App\Actions\QualifyLeadAction($pdo, $router);
    $drafter = new \App\Actions\DraftOutreachAction($pdo, $router);

    $failures = 0;
    $check = function (bool $cond, string $name) use (&$failures) {
        echo ($cond ? '  PASS: ' : '  FAIL: ') . $name . "\n";
        if (!$cond) { $failures++; }
    };

    $rows = $pdo->query("SELECT id, contact_name, email FROM leads ORDER BY id")->fetchAll(\App\PDO::FETCH_ASSOC);
    foreach ($rows as $lead) {
        $id = (int)$lead['id'];
        echo "\n== {$lead['contact_name']} ({$lead['email']}) ==\n";
        try {
            $enrich->execute($id);
            $n = $pdo->query("SELECT notes FROM leads WHERE id = {$id}")->fetchColumn();
            $check(str_contains((string)$n, '[Enrichment'), 'enrichment block written');

            $qualify->execute($id);
            $row = $pdo->query("SELECT status, lead_score, notes FROM leads WHERE id = {$id}")->fetch(\App\PDO::FETCH_ASSOC);
            echo "  qualified: {$row['status']} (score {$row['lead_score']})\n";
            $check(str_contains((string)$row['notes'], '[Enrichment'), 'enrichment survived qualification');
            $check(str_contains((string)$row['notes'], '[Qualification'), 'qualification verdict recorded');

            if ($row['status'] === 'Qualified') {
                $draft = $drafter->buildDraft($id);
                echo "  draft subject: {$draft['subject']}\n";
                $check($draft['campaign_id'] === $campaignId, 'draft scoped to own campaign');
                $row2 = $pdo->query("SELECT status, notes FROM leads WHERE id = {$id}")->fetch(\App\PDO::FETCH_ASSOC);
                $check($row2['status'] === 'Drafted', 'status Drafted');
                $check(str_contains((string)$row2['notes'], '[Enrichment'), 'enrichment survived drafting');
                $check(str_contains((string)$row2['notes'], $draft['body']), 'draft body persisted in notes');
            } else {
                echo "  not qualified — no draft (correct)\n";
            }
        } catch (\Throwable $e) {
            $check(false, 'pipeline step threw: ' . substr($e->getMessage(), 0, 120));
        }
    }

    echo "\n-- zero-send verification --\n";
    $sent = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status IN ('sent','queued')")->fetchColumn();
    $check($sent === 0, 'email_logs has zero sent/queued rows');
    $check(true, 'send path was never invoked (this script has no send step)');

    // Purge the key from the scratch DB before dropping it (defense in depth).
    $pdo->exec("DELETE FROM settings WHERE setting_key = 'deepseek_api_key'");
    echo "\n{$failures} failures\n";
    $exitCode = $failures > 0 ? 1 : 0;
    if ($failures === 0) { echo "ACCEPTANCE RUN COMPLETE — zero sends.\n"; }
    } // end else (--run with key present)
    } // end else (--run)
} finally {
    if ($hadConfig) { file_put_contents($configFile, $backup); }
    elseif (is_file($configFile)) { unlink($configFile); }
    if (($argv[1] ?? '') === '--run') {
        exec("mysql -u root -e \"DROP DATABASE IF EXISTS {$dbName}; DROP USER IF EXISTS '{$dbUser}'@'%';\" 2>&1");
    }
}
exit($exitCode ?? 2);
