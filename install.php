<?php
/**
 * Smarketer Pro — first-run installer wizard.
 *
 * Upload the app files to your hosting account, then visit install.php in
 * your browser. It will:
 *   1. Run pre-flight checks (PHP version, extensions, writability).
 *   2. Collect database credentials, test the connection, and import schema.sql.
 *   3. Write config/db.php (never committed, never overwritten once installed).
 *   4. Show the exact cron command to paste into cPanel, then hand off to setup.php.
 *
 * The installer self-locks via config/installed.lock. Delete install.php
 * after setup if you want it gone entirely.
 */
require_once __DIR__ . '/includes/autoload.php';

use App\Auth;

$configDir  = __DIR__ . '/config';
$lockFile   = $configDir . '/installed.lock';
$dbFile     = $configDir . '/db.php';
$schemaFile = __DIR__ . '/schema.sql';

if (is_file($lockFile)) {
    http_response_code(403);
    die('<!DOCTYPE html><html><body style="background:#0b0f1a;color:#f8fafc;font-family:sans-serif;padding:4rem;text-align:center">'
        . '<h1>Already installed</h1>'
        . '<p><a style="color:#60a5fa" href="login.php">Log in</a> or delete <code>config/installed.lock</code> to re-run the installer.</p>'
        . '</body></html>');
}

$errors = [];
$licenseNotice = null;
$step = 'checks';

// --- Pre-flight checks -------------------------------------------------
$checks = [];
$checks['PHP 7.4 or newer'] = version_compare(PHP_VERSION, '7.4.0', '>=');
foreach (['mysqli', 'curl', 'json', 'session', 'mbstring', 'openssl'] as $ext) {
    $checks["PHP extension: {$ext}"] = extension_loaded($ext);
}
$checks['schema.sql present'] = is_file($schemaFile);
$checks['config/ writable (will be created if missing)'] =
    is_dir($configDir) ? is_writable($configDir) : is_writable(__DIR__);
$checks['logs/ writable (created if missing)'] =
    is_dir(__DIR__ . '/logs') ? is_writable(__DIR__ . '/logs') : is_writable(__DIR__);

$checksPassed = !in_array(false, $checks, true);
if ($checksPassed) {
    $step = 'database';
}

// --- Database step ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['db_host'])) {
    $step = 'database';
    if (!Auth::validateCsrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Invalid form token. Please reload and try again.';
    }
    $dbHost = preg_replace('/[^a-zA-Z0-9.\-:]/', '', trim($_POST['db_host'] ?? ''));
    $dbName = preg_replace('/[^a-zA-Z0-9_$]/', '', trim($_POST['db_name'] ?? ''));
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    foreach (['db_host' => $dbHost, 'db_name' => $dbName, 'db_user' => $dbUser] as $k => $v) {
        if ($v === '') {
            $errors[] = 'All database fields except the password are required.';
            break;
        }
    }

    if (!$errors) {
        try {
            // 1. Test the connection with the supplied credentials.
            $dsn = "mysql:host={$dbHost};charset=utf8mb4";
            $pdo = new \App\PDO($dsn, $dbUser, $dbPass, [
                \App\PDO::ATTR_ERRMODE => \App\PDO::ERRMODE_EXCEPTION,
            ]);
            // 2. Create the database if the user is allowed to (cPanel users
            //    usually pre-create it; failure here is non-fatal).
            try {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (\Throwable $e) {
                // ignore — the database probably already exists
            }
            $pdo->exec("USE `{$dbName}`");

            // 3. Import the schema, statement by statement.
            // Strip full-line -- comments FIRST: the old splitter attached a
            // comment header to the following statement and then skipped the
            // whole chunk, silently dropping every commented table.
            $schema = file_get_contents($schemaFile);
            if ($schema === false) {
                throw new \RuntimeException('Could not read schema.sql.');
            }
            $lines = preg_split('/\r\n|\n|\r/', $schema);
            $code = [];
            foreach ($lines as $line) {
                if (preg_match('/^\s*--/', $line)) {
                    continue;
                }
                $code[] = $line;
            }
            $statements = array_filter(array_map('trim', preg_split('/;\s*(?:\r\n|\n|\r|$)/', implode("\n", $code))));
            $imported = 0;
            foreach ($statements as $sql) {
                if ($sql === '') {
                    continue;
                }
                $pdo->exec($sql);
                $imported++;
            }
            if ($imported === 0) {
                throw new \RuntimeException('Schema import produced zero statements — aborting install.');
            }

            // 4. Write config/db.php + deny web access to config/.
            if (!is_dir($configDir) && !mkdir($configDir, 0755, true)) {
                throw new \RuntimeException('Could not create config/ directory.');
            }
            $dbConfig = "<?php\n// Generated by install.php — do not commit.\nreturn " .
                var_export(['host' => $dbHost, 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass], true) .
                ";\n";
            file_put_contents($dbFile, $dbConfig, LOCK_EX);
            chmod($dbFile, 0600);
            // Per-install secret for unsubscribe tokens (never committed).
            $appSecret = bin2hex(random_bytes(32));
            $secretFile = $configDir . '/app_secret.php';
            file_put_contents($secretFile, "<?php\n// Generated by install.php — do not commit or share.\nreturn '" . $appSecret . "';\n", LOCK_EX);
            chmod($secretFile, 0600);
            file_put_contents($configDir . '/.htaccess', "Require all denied\n", LOCK_EX);
            file_put_contents($configDir . '/index.html', '', LOCK_EX);

            // 5. Optional license registration (SOFT — never blocks install).
            // The schema is imported by now, so the settings table exists.
            $licServerUrl = trim($_POST['license_server_url'] ?? '');
            $licKey = trim($_POST['license_key'] ?? '');
            try {
                if ($licServerUrl !== '') {
                    $licStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('license_server_url', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    $licStmt->execute([$licServerUrl]);
                }
                if ($licKey !== '') {
                    $verdict = \App\Licensing::registerNow($licKey);
                    $licenseNotice = $verdict['detail'] !== '' ? $verdict['detail'] : ('License status: ' . $verdict['status']);
                }
            } catch (\Throwable $e) {
                // Soft by design: licensing problems are notices, never errors.
                $licenseNotice = 'License check skipped (' . $e->getMessage() . ') — install continues normally.';
            }

            // 5b. Seed email-verification defaults (ITEM 1). Verification stays
            // OFF until the buyer enables it with their own MillionVerifier key.
            try {
                $verifySeed = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
                foreach ([
                    'verification_required' => '0',
                    'verification_provider' => 'millionverifier',
                    'verification_api_key' => '',
                    'verification_risky_action' => 'block',
                    'verification_strict' => '0',
                    'verification_cache_days' => '30',
                ] as $k => $v) {
                    $verifySeed->execute([$k, $v]);
                }
            } catch (\Throwable $e) {
                $errors[] = 'Could not seed verification defaults: ' . $e->getMessage();
            }

            // 6. Lock the installer.
            file_put_contents($lockFile, gmdate('c'), LOCK_EX);
            $step = 'done';
        } catch (\Throwable $e) {
            $errors[] = 'Database setup failed: ' . $e->getMessage();
        }
    }
}

$csrf = Auth::csrfToken();
$phpBinary = PHP_BINARY ?: 'php';
$appPath = __DIR__ . '/cron/process_queue.php';
$cronCommand = $phpBinary . ' ' . $appPath . ' > /dev/null 2>&1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Install — Smarketer Pro</title>
<style>
body{font-family:Inter,system-ui,sans-serif;background:#0b0f1a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:2rem 1rem}
.card{background:rgba(30,41,59,.7);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.1);border-radius:1.5rem;padding:2.5rem;width:100%;max-width:34rem}
h1{margin:0 0 .5rem;font-size:1.4rem}
p.sub{color:#94a3b8;font-size:.9rem;margin:0 0 1.5rem}
label{display:block;font-size:.8rem;color:#94a3b8;margin:1rem 0 .35rem}
input{width:100%;box-sizing:border-box;background:#0f172a;border:1px solid rgba(255,255,255,.12);border-radius:.75rem;color:#f8fafc;padding:.7rem .9rem;font-size:1rem}
input:focus{outline:none;border-color:#3b82f6}
button{width:100%;margin-top:1.5rem;background:linear-gradient(to right,#2563eb,#4f46e5);border:none;border-radius:.75rem;color:#fff;font-weight:700;padding:.8rem;font-size:1rem;cursor:pointer}
button:hover{filter:brightness(1.1)}
.err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#fca5a5;border-radius:.75rem;padding:.7rem .9rem;font-size:.85rem;margin-bottom:.6rem}
.ok{color:#6ee7b7}.bad{color:#fca5a5}
.check{display:flex;justify-content:space-between;font-size:.9rem;padding:.35rem 0;border-bottom:1px solid rgba(255,255,255,.05)}
code{background:#0f172a;padding:.2rem .5rem;border-radius:.4rem;font-size:.85rem;word-break:break-all}
pre{background:#0f172a;padding:1rem;border-radius:.75rem;font-size:.8rem;overflow-x:auto;white-space:pre-wrap;word-break:break-all}
a{color:#60a5fa}
ol{color:#94a3b8;font-size:.9rem;line-height:1.7}
</style>
</head>
<body>
<div class="card">
<?php if ($step === 'checks'): ?>
  <h1>🔧 Smarketer Pro Installer</h1>
  <p class="sub">Pre-flight checks — fix any red items, then reload.</p>
  <?php foreach ($checks as $label => $pass): ?>
    <div class="check"><span><?= htmlspecialchars($label) ?></span><span class="<?= $pass ? 'ok' : 'bad' ?>"><?= $pass ? '✓' : '✗' ?></span></div>
  <?php endforeach; ?>
  <p class="sub" style="margin-top:1.5rem">PHP <?= htmlspecialchars(PHP_VERSION) ?></p>
<?php elseif ($step === 'database'): ?>
  <h1>🗄️ Database setup</h1>
  <p class="sub">Create a MySQL database + user in cPanel first, then enter the credentials. The installer imports the schema for you.</p>
  <?php foreach ($errors as $e): ?><div class="err"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  <form method="post" action="install.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <label for="db_host">Database host</label>
    <input id="db_host" name="db_host" required value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>">
    <label for="db_name">Database name</label>
    <input id="db_name" name="db_name" required value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" placeholder="e.g. myuser_b2b">
    <label for="db_user">Database username</label>
    <input id="db_user" name="db_user" required value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" placeholder="e.g. myuser_b2b">
    <label for="db_pass">Database password</label>
    <input id="db_pass" type="password" name="db_pass" autocomplete="new-password">
    <label for="license_server_url">License server URL <span style="font-size:.75rem">(optional)</span></label>
    <input id="license_server_url" name="license_server_url" placeholder="https://license.example.com/api" value="<?= htmlspecialchars($_POST['license_server_url'] ?? '') ?>">
    <label for="license_key">License key <span style="font-size:.75rem">(optional — the app works fully without one)</span></label>
    <input id="license_key" name="license_key" placeholder="SMP-XXXX-XXXX-XXXX" value="<?= htmlspecialchars($_POST['license_key'] ?? '') ?>" autocomplete="off">
    <p class="sub" style="margin:.6rem 0 0;font-size:.8rem">No key? Skip it — you can add one later in Settings → License. A wrong or unreachable license never blocks installation.</p>
    <button type="submit">Install database</button>
  </form>
<?php else: ?>
  <h1>✅ Installed</h1>
  <p class="sub">Database is ready. Two steps left:</p>
  <?php if ($licenseNotice): ?><div class="err" style="border-color:rgba(96,165,250,.35);background:rgba(59,130,246,.08);color:#bfdbfe"><?= htmlspecialchars($licenseNotice) ?></div><?php endif; ?>
  <ol>
    <li><strong>Cron job</strong> — in cPanel → Cron Jobs, add this to run <strong>every 5 minutes</strong>:
      <pre><?= htmlspecialchars($cronCommand) ?></pre>
      Without it, queued outreach tasks never run.
    </li>
    <li><strong>Admin account</strong> — <a href="setup.php">create it here</a>.</li>
  </ol>
  <p class="sub">Tip: delete <code>install.php</code> from the server when you're done.</p>
<?php endif; ?>
</div>
</body>
</html>
