<?php
/**
 * Clean-install integration test for install.php.
 *
 * Simulates a novice cPanel install over real HTTP against a PRISTINE copy
 * of the repo tree (the working tree is never touched):
 *   1. GET install.php -> preflight checks page, CSRF token present
 *   2. POST bad credentials -> clean error, no lock file, no config written
 *   3. POST good credentials -> schema imported, config/db.php (0600),
 *      config/app_secret.php (0600), config/.htaccess deny, installed.lock
 *   4. GET install.php again -> self-lock page
 *   5. Verify: all expected tables exist, leads has compliance columns,
 *      suppression_list exists, config/db.php actually connects
 *
 * Usage: php tests/install/run_install_test.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
$fail = 0; $pass = 0;
function check(string $name, bool $cond, string $detail = ''): void {
    global $fail, $pass;
    if ($cond) { $pass++; echo "  PASS: {$name}\n"; }
    else { $fail++; echo "  FAIL: {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}
function sh(string $cmd): void {
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) { throw new RuntimeException("shell failed: {$cmd}\n" . implode("\n", $out)); }
}

// --- Pristine tree -------------------------------------------------------
$pristine = sys_get_temp_dir() . '/smarketer_install_test';
sh("rm -rf " . escapeshellarg($pristine));
sh("mkdir -p " . escapeshellarg($pristine));
sh("cp -r " . escapeshellarg($repo) . "/. " . escapeshellarg($pristine) . "/");
sh("rm -rf " . escapeshellarg($pristine . '/.git'));
sh("rm -rf " . escapeshellarg($pristine . '/config'));
check('pristine tree copied', is_file($pristine . '/install.php') && !is_dir($pristine . '/config'));

// --- Scratch DB user (limited, like a cPanel account) ---------------------
$dbName = 'installtest_b2b';
$dbUser = 'installtest_u';
$dbPass = 'installtest_pw_5a2';
sh("mysql -u root -e \"DROP DATABASE IF EXISTS {$dbName}; DROP USER IF EXISTS '{$dbUser}'@'%';\"");
sh("mysql -u root -e \"CREATE USER '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; GRANT ALL PRIVILEGES ON {$dbName}.* TO '{$dbUser}'@'%'; FLUSH PRIVILEGES;\"");
// Note: no CREATE DATABASE grant on *.* — the installer must handle the
// CREATE DATABASE attempt failing and fall back to USE (it pre-creates here).

// --- HTTP server on the pristine tree -------------------------------------
$port = 18924;
$pid = (int)shell_exec("php -S 127.0.0.1:{$port} -t " . escapeshellarg($pristine) . " >/dev/null 2>&1 & echo $!");
$up = false;
for ($i = 0; $i < 50; $i++) {
    $r = @file_get_contents("http://127.0.0.1:{$port}/install.php");
    if ($r !== false && stripos($r, 'install') !== false) { $up = true; break; }
    usleep(100000);
}
check('installer serves over HTTP', $up);
if (!$up) { throw new RuntimeException('php -S did not start'); }

$jar = sys_get_temp_dir() . '/install_test_cookies.txt';
@unlink($jar);
$get = function (string $path) use ($port, $jar): string {
    $c = "curl -sS --max-time 20 -c " . escapeshellarg($jar) . " -b " . escapeshellarg($jar)
        . " " . escapeshellarg("http://127.0.0.1:{$port}{$path}");
    exec($c, $out, $code);
    return implode("\n", $out);
};
$post = function (string $path, array $fields) use ($port, $jar): string {
    $c = "curl -sS --max-time 60 -c " . escapeshellarg($jar) . " -b " . escapeshellarg($jar);
    foreach ($fields as $k => $v) { $c .= " --data-urlencode " . escapeshellarg("{$k}={$v}"); }
    $c .= " " . escapeshellarg("http://127.0.0.1:{$port}{$path}");
    exec($c, $out, $code);
    return implode("\n", $out);
};

try {
    // 1. Preflight page
    $html = $get('/install.php');
    // All checks pass in this env, so the installer goes straight to the DB form.
    check('lands on database form when checks pass', stripos($html, 'db_host') !== false);
    check('DB form explains cPanel prerequisite', stripos($html, 'cPanel') !== false);
    preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
    $csrf = $m[1] ?? '';
    check('CSRF token present in form', $csrf !== '');

    // 2. Bad credentials -> clean error, nothing written
    $bad = $post('/install.php', ['db_host' => '127.0.0.1', 'db_name' => $dbName, 'db_user' => $dbUser, 'db_pass' => 'wrong-password', 'csrf' => $csrf]);
    check('bad password shows error, not a fatal', stripos($bad, 'Database setup failed') !== false && stripos($bad, 'Fatal error') === false);
    check('no lock file after failure', !is_file($pristine . '/config/installed.lock'));
    check('no db config after failure', !is_file($pristine . '/config/db.php'));

    // Fresh CSRF (session may have rotated) then good credentials.
    $html = $get('/install.php');
    preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
    $csrf = $m[1] ?? $csrf;
    // Pre-create the DB like a cPanel user would (no global CREATE grant).
    sh("mysql -u root -e \"CREATE DATABASE {$dbName} CHARACTER SET utf8mb4;\"");
    $good = $post('/install.php', ['db_host' => '127.0.0.1', 'db_name' => $dbName, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'csrf' => $csrf]);
    check('install completes to done page', stripos($good, 'Database is ready') !== false, substr(strip_tags($good), 0, 120));
    check('done page shows cron command', stripos($good, 'process_queue.php') !== false);
    check('done page links to setup.php', stripos($good, 'setup.php') !== false);
    check('no PHP fatal on done page', stripos($good, 'Fatal error') === false && stripos($good, 'Uncaught') === false);

    // 3. Artifacts on disk
    $dbCfg = $pristine . '/config/db.php';
    check('config/db.php written', is_file($dbCfg));
    check('config/db.php is 0600', substr(sprintf('%o', fileperms($dbCfg)), -4) === '0600');
    $secretF = $pristine . '/config/app_secret.php';
    check('config/app_secret.php written', is_file($secretF));
    check('app_secret.php is 0600', substr(sprintf('%o', fileperms($secretF)), -4) === '0600');
    $secret = is_file($secretF) ? (require $secretF) : '';
    check('app secret is 64 hex chars', is_string($secret) && (bool)preg_match('/^[0-9a-f]{64}$/', $secret));
    check('installed.lock written', is_file($pristine . '/config/installed.lock'));
    $ht = @file_get_contents($pristine . '/config/.htaccess');
    check('config/.htaccess denies web access', $ht !== false && stripos($ht, 'Require all denied') !== false);

    // 4. Tables actually imported
    $tables = [];
    exec("mysql -u {$dbUser} -p{$dbPass} -N -e 'SHOW TABLES' {$dbName} 2>/dev/null", $tables);
    foreach (['leads', 'campaigns', 'settings', 'suppression_list', 'email_logs'] as $t) {
        check("table {$t} imported", in_array($t, $tables, true), implode(',', $tables));
    }
    $cols = [];
    exec("mysql -u {$dbUser} -p{$dbPass} -N -e 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=\"{$dbName}\" AND TABLE_NAME=\"leads\"' 2>/dev/null", $cols);
    foreach (['consent_status', 'verification_status', 'is_role_based', 'target_persona', 'source_url'] as $c) {
        check("leads.{$c} from fresh schema", in_array($c, $cols, true));
    }

    // 5. The written config actually connects and the app boots
    $boot = $get('/unsubscribe.php?token=bogus');
    check('app boots on installed config (unsubscribe page)', stripos($boot, 'Unsubscribe') !== false && stripos($boot, 'Fatal error') === false);

    // 6. Re-running installer shows the lock page
    $again = $get('/install.php');
    check('installer self-locks on revisit', stripos($again, 'already') !== false || stripos($again, 'installed.lock') !== false);

    echo "\n{$pass} passed, {$fail} failed\n";
    if ($fail > 0) { exit(1); }
} finally {
    exec("kill {$pid} 2>/dev/null");
    sh("rm -rf " . escapeshellarg($pristine));
    @unlink($jar);
    try { sh("mysql -u root -e \"DROP DATABASE IF EXISTS {$dbName}; DROP USER IF EXISTS '{$dbUser}'@'%';\""); }
    catch (\Throwable $e) { /* best effort */ }
}
