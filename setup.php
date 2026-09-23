<?php
/**
 * First-run setup — creates the admin account for Smarketer Pro.
 *
 * This page ONLY works when no admin exists yet (config/auth.php missing).
 * Once setup completes it refuses to run again. Delete this file after
 * setup if you want it gone entirely.
 */
require_once __DIR__ . '/includes/autoload.php';

use App\Auth;

if (Auth::isSetup()) {
    http_response_code(403);
    die('<!DOCTYPE html><html><body style="background:#0b0f1a;color:#f8fafc;font-family:sans-serif;padding:4rem;text-align:center">'
        . '<h1>Setup already completed</h1>'
        . '<p>An admin account already exists. <a style="color:#60a5fa" href="login.php">Log in</a></p>'
        . '</body></html>');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = $_POST['csrf'] ?? null;
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (!Auth::validateCsrf($token)) {
        $errors[] = 'Invalid form token. Please reload and try again.';
    }
    if (!preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $username)) {
        $errors[] = 'Username must be 3–64 characters: letters, numbers, dot, underscore, dash.';
    }
    if (strlen($password) < 12) {
        $errors[] = 'Password must be at least 12 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        Auth::saveCredentials($username, $password);
        header('Location: login.php?setup=1');
        exit;
    }
}

$csrf = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Setup — Smarketer Pro</title>
<style>
body{font-family:Inter,system-ui,sans-serif;background:#0b0f1a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.card{background:rgba(30,41,59,.7);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.1);border-radius:1.5rem;padding:2.5rem;width:100%;max-width:26rem}
h1{margin:0 0 .5rem;font-size:1.4rem}
p.sub{color:#94a3b8;font-size:.9rem;margin:0 0 1.5rem}
label{display:block;font-size:.8rem;color:#94a3b8;margin:1rem 0 .35rem}
input{width:100%;box-sizing:border-box;background:#0f172a;border:1px solid rgba(255,255,255,.12);border-radius:.75rem;color:#f8fafc;padding:.7rem .9rem;font-size:1rem}
input:focus{outline:none;border-color:#3b82f6}
button{width:100%;margin-top:1.5rem;background:linear-gradient(to right,#2563eb,#4f46e5);border:none;border-radius:.75rem;color:#fff;font-weight:700;padding:.8rem;font-size:1rem;cursor:pointer}
button:hover{filter:brightness(1.1)}
.err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#fca5a5;border-radius:.75rem;padding:.7rem .9rem;font-size:.85rem;margin-bottom:.6rem}
</style>
</head>
<body>
<div class="card">
  <h1>🚀 Smarketer Pro Setup</h1>
  <p class="sub">Create your admin account. This only runs once.</p>
  <?php foreach ($errors as $e): ?><div class="err"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  <form method="post" action="setup.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <label for="username">Admin username</label>
    <input id="username" name="username" autocomplete="username" required
           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
    <label for="password">Password (min 12 characters)</label>
    <input id="password" type="password" name="password" autocomplete="new-password" required>
    <label for="confirm">Confirm password</label>
    <input id="confirm" type="password" name="confirm" autocomplete="new-password" required>
    <button type="submit">Create admin account</button>
  </form>
</div>
</body>
</html>
