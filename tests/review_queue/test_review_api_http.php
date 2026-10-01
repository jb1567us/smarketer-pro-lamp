#!/usr/bin/env php
<?php
/**
 * Review API HTTP tests (goal_67693fcbba4c, review workflow).
 *
 * Spins up PHP's built-in web server against the repo tree with a scratch
 * MariaDB (credentials via DB_* env vars) + temporary config/auth.php, then
 * exercises api/review.php over real HTTP (via the curl CLI):
 *
 *   H1. unauthenticated GET list            -> 401 (auth before input)
 *   H2. unauthenticated GET bogus action    -> 401 (auth before allowlist)
 *   H3. unauthenticated POST approve        -> 401 (auth before CSRF)
 *   H4. login works; review_queue.php renders 200 with a CSRF meta tag
 *   H5. authenticated GET list              -> 200, queue row + dimensions
 *   H6. authenticated GET unknown action    -> 400 (action allowlist)
 *   H7. POST approve, no CSRF token         -> 403
 *   H8. POST approve, wrong CSRF token      -> 403
 *   H9. POST approve, invalid lead_id       -> 400
 *  H10. POST approve, valid                 -> 200, Qualified + audit row
 *       (decided_by = logged-in user)
 *  H11. POST approve again                  -> 200 already_decided, no dup
 *  H12. POST disqualify on Qualified lead   -> 409 (transition guard)
 *  H13. GET list filter=decided             -> 200, who/when present
 *  H14. logout                              -> API is 401 again afterwards
 *
 * Usage: php tests/review_queue/test_review_api_http.php
 * The repo tree is left exactly as it was (scratch DB creds dropped from
 * env; config/auth.php restored/removed; scratch DB dropped).
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
$configDir = $repo . '/config';
$authFile = $configDir . '/auth.php';
require_once __DIR__ . '/../support/db_env.php';
$hadAuth = is_file($authFile);
$authBackup = $hadAuth ? file_get_contents($authFile) : null;
// saveCredentials() also writes config/.htaccess, and login attempts write
// config/throttle.json — track those so cleanup leaves the tree untouched.
$htaccessFile = $configDir . '/.htaccess';
$hadHtaccess = is_file($htaccessFile);
$throttleFile = $configDir . '/throttle.json';
$hadThrottle = is_file($throttleFile);

$pass = 0;
$fail = 0;
function hok(bool $cond, string $name, string $detail = ''): void
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

$server = null;
$jar = tempnam(sys_get_temp_dir(), 'rqjar');
try {
    $dbUser = 'review_http';
    $dbPass = 't_' . bin2hex(random_bytes(8));
    $dbName = 'review_http_test';
    $sh = function (string $cmd): string {
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException("shell failed: {$cmd}\n" . implode("\n", $out));
        }
        return implode("\n", $out);
    };
    $sh("mysql -u root -e \"DROP DATABASE IF EXISTS {$dbName}; CREATE DATABASE {$dbName} CHARACTER SET utf8mb4;\"");
    $sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON {$dbName}.* TO '{$dbUser}'@'%';\"");
    $sh("mysql -u root -e \"CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; ALTER USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'; GRANT ALL ON {$dbName}.* TO '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;\"");

    test_db_use_env('127.0.0.1', $dbName, $dbUser, $dbPass);

    require $repo . '/includes/autoload.php';
    $pdo = \App\Database::getConnection();
    $mysql = "mysql -h 127.0.0.1 -u {$dbUser} -p{$dbPass} {$dbName}";

    // Minimal leads table + both migrations (ENUM first, then audit table).
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
    $sh("{$mysql} < " . escapeshellarg($repo . '/migrations/2026-09-28-needs-review-enum.sql'));
    $sh("{$mysql} < " . escapeshellarg($repo . '/migrations/2026-09-28-review-decisions.sql'));

    $marker = "\n\n[Qualification 2026-09-28]: Needs Review (fit 62/100, below qualify threshold 75) — Jev weighted ICP fit.\n"
        . "Dimensions: company_size=8/10, industry_fit=7/10, target_title=5/10, geography=7/10, trigger_signals=4/10.";
    $seed = $pdo->prepare('INSERT INTO leads (company_name, contact_name, email, status, lead_score, notes) VALUES (?, ?, ?, ?, ?, ?)');
    $seed->execute(['Acme Corp', 'Jane Doe', 'jane@acme.test', 'Needs Review', 62, 'enrichment' . $marker]);
    $leadId = (int)$pdo->lastInsertId();
    $seed->execute(['Gamma Inc', 'Gina Ray', 'gina@gamma.test', 'Qualified', 88, 'already qualified']);
    $qualifiedId = (int)$pdo->lastInsertId();

    // Temporary login for the HTTP session.
    \App\Auth::saveCredentials('reviewer', 's3cret-pass');

    // --- web server ----------------------------------------------------------
    $port = 8099;
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $repo],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    if (!is_resource($server)) {
        throw new RuntimeException('could not start php -S');
    }
    $base = "http://127.0.0.1:{$port}";

    /**
     * @return array{0:int, 1:array|null, 2:string} [http code, decoded JSON, raw body]
     */
    $req = function (string $method, string $path, $body = null, array $headers = [], bool $auth = false) use ($base, $jar, $sh): array {
        $args = ['curl', '-s', '--max-time', '10', '-X', $method];
        if ($auth) {
            $args[] = '-c';
            $args[] = $jar;
            $args[] = '-b';
            $args[] = $jar;
        }
        foreach ($headers as $h) {
            $args[] = '-H';
            $args[] = $h;
        }
        if ($body !== null) {
            $payload = is_string($body) ? $body : json_encode($body);
            $args[] = '--data-binary';
            $args[] = '@-';
            if (!is_string($body)) {
                $args[] = '-H';
                $args[] = 'Content-Type: application/json';
            }
            $stdinFile = tempnam(sys_get_temp_dir(), 'rqbody');
            file_put_contents($stdinFile, $payload);
            $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' -w ' . escapeshellarg("\n%{http_code}") . ' ' . escapeshellarg($base . $path) . ' < ' . escapeshellarg($stdinFile);
        } else {
            $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' -w ' . escapeshellarg("\n%{http_code}") . ' ' . escapeshellarg($base . $path);
        }
        $descriptors = [0 => ['file', $stdinFile ?? '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        $stdout = '';
        $procCode = 1;
        if (is_resource($proc)) {
            $stdout = (string)stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $procCode = proc_close($proc);
        }
        if (isset($stdinFile)) {
            unlink($stdinFile);
            unset($stdinFile);
        }
        if ($procCode !== 0 && trim($stdout) === '') {
            // Connection-level failure (e.g. server not up yet): surface as code 0.
            return [0, null, ''];
        }
        $lines = explode("\n", rtrim($stdout, "\n"));
        $httpCode = (int)trim((string)array_pop($lines));
        $raw = implode("\n", $lines);
        return [$httpCode, json_decode($raw, true), $raw];
    };

    // Wait for the server.
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        [$code] = $req('GET', '/api/review.php');
        if ($code === 401 || $code === 200) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('web server did not become ready');
    }

    // --- H1/H2/H3: auth before everything ------------------------------------
    echo "H1-H3: auth before input:\n";
    [$code] = $req('GET', '/api/review.php?action=list');
    hok($code === 401, 'H1: unauthenticated GET list -> 401', "got {$code}");
    [$code] = $req('GET', '/api/review.php?action=bogus');
    hok($code === 401, 'H2: unauthenticated GET bogus action -> 401 (auth before allowlist)', "got {$code}");
    [$code] = $req('POST', '/api/review.php?action=approve', ['lead_id' => $leadId]);
    hok($code === 401, 'H3: unauthenticated POST approve -> 401 (auth before CSRF)', "got {$code}");

    // --- H4: login + page render ----------------------------------------------
    echo "H4: login + page:\n";
    [$loginPageCode, , $loginPage] = $req('GET', '/login.php', null, [], true);
    $loginCsrf = null;
    if (preg_match('/name="csrf"[^>]*value="([a-f0-9]{64})"/', $loginPage, $lm)) {
        $loginCsrf = $lm[1];
    } elseif (preg_match('/value="([a-f0-9]{64})"[^>]*name="csrf"/', $loginPage, $lm)) {
        $loginCsrf = $lm[1];
    }
    hok($loginPageCode === 200 && $loginCsrf !== null, 'H4: login form served with CSRF token', "got {$loginPageCode}");
    $loginBody = http_build_query(['username' => 'reviewer', 'password' => 's3cret-pass', 'csrf' => $loginCsrf ?? '']);
    [$loginCode] = $req('POST', '/login.php', $loginBody, ['Content-Type: application/x-www-form-urlencoded'], true);
    hok(in_array($loginCode, [302, 303], true), 'H4: login POST redirects (success)', "got {$loginCode}");
    [$pageCode, , $page] = $req('GET', '/review_queue.php', null, [], true);
    hok($pageCode === 200 && str_contains($page, 'Review Queue'), 'H4: review_queue.php renders 200 when logged in', "got {$pageCode}");
    $csrf = null;
    if (preg_match('/<meta name="csrf-token" content="([a-f0-9]{64})"/', $page, $m)) {
        $csrf = $m[1];
    }
    hok($csrf !== null, 'H4: page embeds CSRF token meta tag');

    // --- H5/H6: authenticated reads --------------------------------------------
    echo "H5-H6: authenticated reads:\n";
    [$code, $json] = $req('GET', '/api/review.php?action=list&filter=queue', null, [], true);
    hok(
        $code === 200 && ($json['success'] ?? false) && ($json['total'] ?? 0) === 1
        && (int)($json['rows'][0]['id'] ?? 0) === $leadId,
        'H5: authenticated GET list -> 200 with the Needs Review lead',
        "code {$code} total=" . json_encode($json['total'] ?? null)
    );
    hok(
        ($json['rows'][0]['dimensions']['company_size'] ?? null) === 8,
        'H5: per-dimension scores present in list payload'
    );
    [$code] = $req('GET', '/api/review.php?action=drop_table', null, [], true);
    hok($code === 400, 'H6: unknown action -> 400 (allowlist)', "got {$code}");

    // --- H7/H8/H9: CSRF + input validation -------------------------------------
    echo "H7-H9: CSRF + input validation:\n";
    [$code] = $req('POST', '/api/review.php?action=approve', ['lead_id' => $leadId], [], true);
    hok($code === 403, 'H7: POST approve without CSRF -> 403', "got {$code}");
    [$code] = $req('POST', '/api/review.php?action=approve', ['lead_id' => $leadId], ['X-CSRF-Token: wrong'], true);
    hok($code === 403, 'H8: POST approve with wrong CSRF -> 403', "got {$code}");
    [$code] = $req('POST', '/api/review.php?action=approve', ['lead_id' => 'abc'], ["X-CSRF-Token: {$csrf}"], true);
    hok($code === 400, 'H9: POST approve with invalid lead_id -> 400', "got {$code}");

    // --- H10/H11/H12: transitions ----------------------------------------------
    echo "H10-H12: transitions:\n";
    [$code, $json] = $req('POST', '/api/review.php?action=approve', ['lead_id' => $leadId], ["X-CSRF-Token: {$csrf}"], true);
    hok(
        $code === 200 && ($json['success'] ?? false) && ($json['new_status'] ?? '') === 'Qualified'
        && ($json['already_decided'] ?? true) === false,
        'H10: POST approve -> 200, Qualified',
        "code {$code} " . json_encode($json)
    );
    $st = $pdo->query("SELECT status FROM leads WHERE id = {$leadId}")->fetchColumn();
    $decidedBy = $pdo->query("SELECT decided_by FROM review_decisions WHERE lead_id = {$leadId}")->fetchColumn();
    hok($st === 'Qualified' && $decidedBy === 'reviewer', 'H10: DB status + audit row name the logged-in user');

    [$code, $json] = $req('POST', '/api/review.php?action=approve', ['lead_id' => $leadId], ["X-CSRF-Token: {$csrf}"], true);
    hok(
        $code === 200 && ($json['already_decided'] ?? false) === true,
        'H11: re-POST approve -> already_decided, no duplicate'
    );
    $n = (int)$pdo->query("SELECT COUNT(*) FROM review_decisions WHERE lead_id = {$leadId}")->fetchColumn();
    hok($n === 1, 'H11: still exactly one audit row');

    [$code, $json] = $req('POST', '/api/review.php?action=disqualify', ['lead_id' => $qualifiedId], ["X-CSRF-Token: {$csrf}"], true);
    hok($code === 409, 'H12: disqualify on non-review lead -> 409', "got {$code}: " . ($json['error'] ?? ''));

    // --- H13: decided history ----------------------------------------------------
    echo "H13: decided history:\n";
    [$code, $json] = $req('GET', '/api/review.php?action=list&filter=decided', null, [], true);
    hok(
        $code === 200 && ($json['total'] ?? 0) === 1
        && ($json['rows'][0]['decided_by'] ?? '') === 'reviewer'
        && !empty($json['rows'][0]['decided_at']),
        'H13: decided filter shows who/when',
        "code {$code}"
    );

    // --- H14: logout closes the session -------------------------------------------
    echo "H14: logout:\n";
    [$logoutCode] = $req(
        'POST',
        '/logout.php',
        http_build_query(['csrf_token' => $csrf]),
        ['Content-Type: application/x-www-form-urlencoded'],
        true
    );
    hok(in_array($logoutCode, [302, 303], true), 'H14: POST logout redirects', "got {$logoutCode}");
    [$code] = $req('GET', '/api/review.php?action=list', null, [], true);
    hok($code === 401, 'H14: API is 401 again after logout', "got {$code}");
} catch (\Throwable $e) {
    echo "  ERROR: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $fail++;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (is_file($jar)) {
        unlink($jar);
    }
    test_db_restore_env();
    if ($hadAuth) {
        file_put_contents($authFile, $authBackup);
    } elseif (is_file($authFile)) {
        unlink($authFile);
    }
    if (!$hadHtaccess && is_file($htaccessFile)) {
        unlink($htaccessFile);
    }
    if (!$hadThrottle && is_file($throttleFile)) {
        unlink($throttleFile);
    }
    try {
        @exec("mysql -u root -e \"DROP DATABASE IF EXISTS review_http_test;\" 2>&1");
    } catch (\Throwable $ignored) {
    }
}

echo "  -- test_review_api_http.php: {$pass} pass, {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
