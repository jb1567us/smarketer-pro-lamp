#!/usr/bin/env php
<?php
/**
 * Phase 1 — JEV shadow-mode agreement measurement.
 *
 *   php tests/phase0/run_shadow_agreement.php --setup   # scratch DB + seed (no key)
 *   TYPESAFE_API_KEY=... php tests/phase0/run_shadow_agreement.php --run
 *
 * --run puts DecisionTier in SHADOW mode and qualifies the 4 fictional
 * Product Insights leads through the real QualifyLeadAction. Shadow mode
 * returns the legacy (LLM) verdict UNCHANGED to the caller while also asking
 * JEV, then appends one JSONL record per decision to the shadow log with
 * jev_value / llm_value / agree / min_confidence / latency_ms.
 *
 * The script then parses the shadow log and reports the agreement rate —
 * the Phase 1 decision gate: >=90% agree -> proceed; below -> stay in
 * shadow and tune prompts.
 *
 * Zero behavior change by construction: the caller never sees JEV output
 * in shadow mode, and no email is ever sent (this script has no send step).
 *
 * The TypeSafe key is transient: env var -> scratch settings row -> purged
 * before the scratch DB is dropped. Never stored in the repo or memory.
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
$configDir = $repo . '/config';
$configFile = $configDir . '/db.php';
$hadConfig = is_file($configFile);
$backup = $hadConfig ? file_get_contents($configFile) : null;

$mode = $argv[1] ?? '';
if (!in_array($mode, ['--setup', '--run'], true)) {
    fwrite(STDERR, "usage: php tests/phase0/run_shadow_agreement.php --setup | --run\n");
    exit(2);
}

$dbName = 'phase1_shadow';
$dbUser = 'phase1_sh';
$dbPass = 'phase1_sh_pw_7a4';

function sh(string $cmd): void {
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) { throw new RuntimeException("shell failed: {$cmd}\n" . implode("\n", $out)); }
}

$exitCode = 2;
try {
    sh("mysql -u root -e \"DROP DATABASE IF EXISTS {$dbName}; CREATE DATABASE {$dbName} CHARACTER SET utf8mb4;\"");
    sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON {$dbName}.* TO '{$dbUser}'@'%'; GRANT ALL ON {$dbName}.* TO '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;\"");
    if (!is_dir($configDir)) { mkdir($configDir, 0755, true); }
    file_put_contents($configFile, "<?php\nreturn ['host' => '127.0.0.1', 'name' => '{$dbName}', 'user' => '{$dbUser}', 'pass' => '{$dbPass}'];\n");

    require $repo . '/includes/autoload.php';
    $pdo = \App\Database::getConnection();

    $fp = escapeshellarg($repo . '/schema.sql');
    sh("mysql -u {$dbUser} -p{$dbPass} {$dbName} < {$fp}");
    foreach (['2026-09-23-compliance.sql', '2026-09-23-compliance-gaps.sql'] as $mig) {
        sh("mysql -u {$dbUser} -p{$dbPass} {$dbName} < " . escapeshellarg($repo . '/migrations/' . $mig));
    }

    // Seed campaign + 4 fictional leads (same fixtures as the Phase 0 run).
    $pdo->exec("INSERT INTO campaigns (name) VALUES ('Product Insights — shadow agreement')");
    $campaignId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO leads (contact_name, email, company_name, website, campaign_id, status) VALUES (?, ?, ?, ?, ?, 'New')");
    foreach ([
        ['Maya Chen', 'maya.chen@example.com', 'HabitLoop — habit-tracking app, pre-launch', 'habitloop.example.com'],
        ['Derek Osei', 'derek@example.com', 'Osei & Co. — 2-person product-marketing consultancy', 'osei.example.com'],
        ['Priya Nair', 'priya.nair@example.com', 'DevScale — 40-person Series A dev-tools company', 'devscale.example.com'],
        ['Tom Alvarez', 'tom@example.com', 'TrendCart — dropshipping store', 'trendcart.example.com'],
    ] as $l) { $stmt->execute([$l[0], $l[1], $l[2], $l[3], $campaignId]); }

    // Shadow-mode plumbing. The legacy LLM path still needs its key for the
    // fallback verdicts (shadow returns legacy to the caller).
    $shadowLog = sys_get_temp_dir() . '/phase1_shadow.jsonl';
    @unlink($shadowLog);
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES " .
        "('jev_enabled', '1'), ('jev_mode', 'shadow'), ('jev_model', 'jev-latest'), ('jev_shadow_log', '{$shadowLog}') " .
        "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

    if ($mode === '--setup') {
        echo "shadow DB ready: {$dbName} (4 fictional leads)\n";
        echo "run with: TYPESAFE_API_KEY=... DEEPSEEK_API_KEY=... php tests/phase0/run_shadow_agreement.php --run\n";
        echo "(DB is kept for --run; config/db.php restored below.)\n";
        $exitCode = 0;
    } else {
        $jevKey = getenv('TYPESAFE_API_KEY') ?: '';
        $dsKey = getenv('DEEPSEEK_API_KEY') ?: '';
        if ($jevKey === '' || $dsKey === '') {
            fwrite(STDERR, "TYPESAFE_API_KEY and DEEPSEEK_API_KEY must both be set. Aborting before any call.\n");
            $exitCode = 3;
        } else {
            // Transient keys: scratch DB only, purged before drop.
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) " .
                "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute(['jev_api_key', $jevKey]);
            $stmt->execute(['deepseek_api_key', $dsKey]);
            $stmt->execute(['active_llm_provider', 'deepseek']);
            $stmt->execute(['deepseek_model', 'deepseek-flash']);

            \App\Jev\DecisionTier::resetForTests();
            echo "mode: " . \App\Jev\DecisionTier::mode() . "\n";

            $router = new \App\Routers\SmartLLMRouter($pdo);
            $qualify = new \App\Actions\QualifyLeadAction($pdo, $router);
            $rows = $pdo->query("SELECT id, contact_name FROM leads ORDER BY id")->fetchAll(\App\PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                try {
                    $qualify->execute((int)$r['id']);
                    $st = $pdo->query("SELECT status FROM leads WHERE id = " . (int)$r['id'])->fetchColumn();
                    echo "  {$r['contact_name']}: legacy verdict -> {$st}\n";
                } catch (\Throwable $e) {
                    echo "  {$r['contact_name']}: ERROR " . substr($e->getMessage(), 0, 100) . "\n";
                }
            }

            // --- agreement analysis -------------------------------------
            echo "\n-- shadow agreement --\n";
            $lines = is_file($shadowLog) ? file($shadowLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            $recs = array_values(array_filter(array_map(fn($l) => json_decode($l, true), $lines)));
            $n = count($recs);
            if ($n === 0) {
                echo "no shadow records — JEV calls did not complete (check key / network).\n";
                $exitCode = 4;
            } else {
                $agree = count(array_filter($recs, fn($r) => !empty($r['agree'])));
                $pct = round(100 * $agree / $n, 1);
                $confs = array_column($recs, 'min_confidence');
                echo "decisions logged: {$n}\n";
                echo "agreement: {$agree}/{$n} ({$pct}%)\n";
                echo "jev confidence: min " . round(min($confs), 2) . ", max " . round(max($confs), 2) . "\n";
                foreach ($recs as $r) {
                    if (empty($r['agree'])) {
                        echo "  DISAGREE: jev=" . json_encode($r['jev_value'] ?? null) .
                             " llm=" . json_encode($r['llm_value'] ?? null) .
                             " conf=" . round($r['min_confidence'] ?? 0, 2) . "\n";
                    }
                }
                echo $pct >= 90
                    ? "GATE: PASS (>=90%) — proceed to Phase 2.\n"
                    : "GATE: HOLD (<90%) — stay in shadow, tune prompts.\n";
                $exitCode = 0;
            }

            $pdo->exec("DELETE FROM settings WHERE setting_key IN ('jev_api_key', 'deepseek_api_key')");
        }
    }
} finally {
    if ($hadConfig) { file_put_contents($configFile, $backup); }
    elseif (is_file($configFile)) { unlink($configFile); }
    if (($argv[1] ?? '') === '--run') {
        exec("mysql -u root -e \"DROP DATABASE IF EXISTS {$dbName}; DROP USER IF EXISTS '{$dbUser}'@'%';\" 2>&1");
    }
}
exit($exitCode);
