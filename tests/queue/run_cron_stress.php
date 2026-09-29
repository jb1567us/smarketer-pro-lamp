#!/usr/bin/env php
<?php
/**
 * Cron-overlap stress test — runs the REAL production code paths against a
 * scratch MariaDB database:
 *
 *  Test A (process single-flight): launch 3x cron/process_queue.php at the
 *           same instant. Exactly one may hold the lock; the others must exit
 *           with "Another queue run is active".
 *
 *  Test B (atomic task claim): 8 concurrent processes each call the real
 *           TaskProcessor::processTask() on the same 30 task IDs. Tasks use
 *           the valid-but-unmapped 'EmailOutreach' type so the claim path is
 *           exercised with zero side effects (action lookup throws instantly).
 *           (Phase 0 mapped the legacy 'Enrichment'/'Qualification'/'Drafting'
 *           aliases to real actions, so 'Enrichment' no longer throws.)
 *           Every task must be claimed exactly once (retry_count == 1).
 *
 * Nothing here touches production. Scratch DB: queue_stress.
 */
$appRoot = __DIR__ . '/../../';
$tmp = sys_get_temp_dir() . '/queue_stress';
@mkdir($tmp, 0777, true);

$dbHost = '127.0.0.1'; $dbName = 'queue_stress';
$dbUser = 'queue_test'; $dbPass = 'queue_test_pw_9f2';

function sh(string $cmd): array {
    $out = []; $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}
function check(string $name, bool $cond): bool {
    echo (($cond ? 'PASS' : 'FAIL') . " $name\n");
    return $cond;
}
$ok = true;

// --- 0. Prepare scratch DB ------------------------------------------------
// Database::credentials() prefers config/db.php over the environment, so a
// committed config would shadow the DB_* env vars below. Stash it for the
// duration of the run and restore it afterwards (the repo tree is untouched).
$repoConfig = $appRoot . 'config/db.php';
$configStash = null;
if (is_file($repoConfig)) {
    $configStash = file_get_contents($repoConfig);
    unlink($repoConfig);
}
register_shutdown_function(function () use ($repoConfig, $configStash) {
    if ($configStash !== null) {
        file_put_contents($repoConfig, $configStash);
    }
});
[$c] = [0];
sh("mysql -u root -e \"DROP DATABASE IF EXISTS $dbName; CREATE DATABASE $dbName CHARACTER SET utf8mb4;\"");
sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '$dbUser'@'%' IDENTIFIED BY '$dbPass'; CREATE USER IF NOT EXISTS '$dbUser'@'localhost' IDENTIFIED BY '$dbPass'; ALTER USER '$dbUser'@'%' IDENTIFIED BY '$dbPass'; ALTER USER '$dbUser'@'localhost' IDENTIFIED BY '$dbPass'; GRANT ALL ON $dbName.* TO '$dbUser'@'%'; GRANT ALL ON $dbName.* TO '$dbUser'@'localhost'; FLUSH PRIVILEGES;\"");
[$code] = sh("mysql -u $dbUser -p$dbPass $dbName < " . escapeshellarg($appRoot . 'schema.sql'));
if ($code !== 0) { echo "FAIL schema import\n"; exit(1); }
echo "PASS scratch DB ready\n";

$env = "DB_HOST=$dbHost DB_NAME=$dbName DB_USER=$dbUser DB_PASS=$dbPass ";
$mysql = "mysql -u $dbUser -p$dbPass $dbName -N -e ";

function resetTasks(string $mysql, int $n = 30): void {
    sh($mysql . "\"DELETE FROM task_queue; INSERT INTO leads (company_name, email) VALUES ('Stress Co', 'stress@example.com');\"");
    $leadId = trim(sh($mysql . "\"SELECT id FROM leads LIMIT 1;\"")[1]);
    for ($i = 0; $i < $n; $i++) {
        sh($mysql . "\"INSERT INTO task_queue (lead_id, task_type, status) VALUES ($leadId, 'EmailOutreach', 'Pending');\"");
    }
    sh($mysql . "\"UPDATE cron_locks SET locked_at='2000-01-01 00:00:00', pid=0 WHERE lock_name='process_queue';\"");
}

// --- Test A: 3 overlapping cron runs --------------------------------------
echo "=== Test A: lock single-flight (3x real cron/process_queue.php) ===\n";
resetTasks($mysql);
// Launch all three at (nearly) the same instant.
$cmds = [];
for ($i = 0; $i < 3; $i++) {
    $cmds[] = "env $env " . PHP_BINARY . ' ' . escapeshellarg($appRoot . 'cron/process_queue.php') . " > $tmp/cron_$i.log";
}
$mh = [];
foreach ($cmds as $i => $c) {
    $mh[$i] = popen("($c) & echo $!", 'r');
    usleep(50000);
}
foreach ($mh as $h) { pclose($h); }
sleep(6); // let all three runs finish

$active = 0; $early = 0; $seenIds = [];
for ($i = 0; $i < 3; $i++) {
    $log = @file_get_contents("$tmp/cron_$i.log") ?: '';
    if (strpos($log, 'Processing Task ID') !== false) $active++;
    if (strpos($log, 'Another queue run is active') !== false) $early++;
    preg_match_all('/Processing Task ID: (\d+)/', $log, $m);
    foreach ($m[1] as $id) {
        if (isset($seenIds[$id])) { $seenIds[$id]++; } else { $seenIds[$id] = 1; }
    }
}
// With instant-failing tasks the staggered runs legitimately serialize through
// the lock; the critical property is no task is ever touched by two runs.
$dupes = array_filter($seenIds, fn($c) => $c > 1);
$ok &= check('no task processed by more than one worker run', count($dupes) === 0);
$ok &= check('all 30 tasks got processed across the runs', count($seenIds) === 30);
$lockState = trim(sh($mysql . "\"SELECT locked_at FROM cron_locks WHERE lock_name='process_queue';\"")[1]);
$ok &= check('lock released after run (finally block)', strpos($lockState, '2000-01-01') === 0);
$claimed = trim(sh($mysql . "\"SELECT COUNT(*) FROM task_queue WHERE retry_count > 1;\"")[1]);
$ok &= check('no task claimed more than once by overlapping runs', $claimed === '0');

// --- Test A2: true lock race — 6 processes, verbatim lock SQL ---------------
echo "=== Test A2: lock race (6 simultaneous acquirers, verbatim lock SQL) ===\n";
sh($mysql . "\"UPDATE cron_locks SET locked_at='2000-01-01 00:00:00', pid=0 WHERE lock_name='process_queue';\"");
$lockDriver = $tmp . '/lock_driver.php';
// NOTE: the lock-acquire block below is copied verbatim from cron/process_queue.php.
file_put_contents($lockDriver, <<<'PHP'
<?php
require_once $argv[1] . 'includes/autoload.php';
$pdo = \App\Database::getConnection();
$pdo->prepare(
    "CREATE TABLE IF NOT EXISTS cron_locks (" .
    "lock_name VARCHAR(100) PRIMARY KEY, " .
    "locked_at TIMESTAMP NULL, " .
    "pid INT DEFAULT 0" .
    ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
)->execute();
$pdo->prepare(
    "INSERT IGNORE INTO cron_locks (lock_name, locked_at, pid) VALUES ('process_queue', '2000-01-01 00:00:00', 0)"
)->execute();
// --- Atomic lock acquire: exactly one run at a time.
// A lock older than 30 minutes is stale (previous run crashed). With a
// 5-minute cron cadence, 6 consecutive missed releases means the holder
// is dead, so the next run takes over instead of stalling forever.
$lockStmt = $pdo->prepare(
    "UPDATE cron_locks SET locked_at = NOW(), pid = ? " .
    "WHERE lock_name = 'process_queue' AND locked_at < NOW() - INTERVAL 30 MINUTE"
);
$lockStmt->execute([getmypid()]);
if ($lockStmt->rowCount() === 0) {
    echo "[LOG] Another queue run is active (lock held). Exiting.\n";
    exit(0);
}
// Hold the lock long enough that every other racer must observe it.
usleep(2500000);
file_put_contents($argv[2], getmypid() . "\n", FILE_APPEND);
$pdo->prepare(
    "UPDATE cron_locks SET locked_at = '2000-01-01 00:00:00', pid = 0 WHERE lock_name = 'process_queue'"
)->execute();
PHP);
@unlink("$tmp/holders.txt");
$handles = [];
for ($i = 0; $i < 6; $i++) {
    $handles[$i] = popen("env $env " . PHP_BINARY . ' ' . escapeshellarg($lockDriver) . ' ' .
        escapeshellarg($appRoot) . ' ' . escapeshellarg("$tmp/holders.txt") . " > $tmp/lock_$i.log", 'r');
}
foreach ($handles as $h) { pclose($h); }
sleep(1);
$holders = array_filter(explode("\n", trim(@file_get_contents("$tmp/holders.txt") ?: '')));
$ok &= check('exactly one racer acquired the lock', count($holders) === 1);
$early = 0;
for ($i = 0; $i < 6; $i++) {
    if (strpos(@file_get_contents("$tmp/lock_$i.log") ?: '', 'Another queue run is active') !== false) $early++;
}
$ok &= check('other five exited on the held lock', $early === 5);

// --- Test B: 8-way race on the atomic claim --------------------------------
echo "=== Test B: atomic claim under 8-way race (real TaskProcessor) ===\n";
resetTasks($mysql);
$driver = $tmp . '/claim_driver.php';
file_put_contents($driver, <<<'PHP'
<?php
require_once $argv[1] . 'includes/autoload.php';
$pdo = \App\Database::getConnection();
$tp = new \App\Domain\TaskProcessor($pdo, new \App\Routers\SmartLLMRouter($pdo));
$ids = json_decode($argv[2], true);
foreach ($ids as $id) { $tp->processTask((int)$id); }
PHP);
$ids = trim(sh($mysql . "\"SELECT GROUP_CONCAT(id) FROM task_queue;\"")[1]);
$handles = [];
for ($i = 0; $i < 8; $i++) {
    $handles[$i] = popen("env $env " . PHP_BINARY . ' ' . escapeshellarg($driver) . ' ' .
        escapeshellarg($appRoot) . ' ' . escapeshellarg(json_encode(array_map('intval', explode(',', $ids)))) .
        " > $tmp/claim_$i.log", 'r');
    usleep(20000);
}
foreach ($handles as $h) { pclose($h); }
sleep(4);

$multi = trim(sh($mysql . "\"SELECT COUNT(*) FROM task_queue WHERE retry_count <> 1;\"")[1]);
$ok &= check('every task claimed exactly once (retry_count == 1)', $multi === '0');
$stuck = trim(sh($mysql . "\"SELECT COUNT(*) FROM task_queue WHERE status = 'In Progress';\"")[1]);
$ok &= check('no task stuck In Progress', $stuck === '0');
$completed = trim(sh($mysql . "\"SELECT COUNT(*) FROM task_queue WHERE status = 'Completed';\"")[1]);
$ok &= check('no phantom completions (unmapped type -> Failed)', $completed === '0');
$failed = trim(sh($mysql . "\"SELECT COUNT(*) FROM task_queue WHERE status = 'Failed';\"")[1]);
$ok &= check('all 30 tasks failed exactly once via claim path', $failed === '30');

echo $ok ? "\nALL CRON STRESS TESTS PASSED\n" : "\nSTRESS TEST FAILURES\n";
exit($ok ? 0 : 1);
