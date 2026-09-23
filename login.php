<?php
/**
 * Login page for Smarketer Pro.
 */
require_once __DIR__ . '/includes/autoload.php';

use App\Auth;

if (!Auth::isSetup()) {
    header('Location: setup.php');
    exit;
}

if (Auth::isLoggedIn()) {
    header('Location: ' . Auth::safeNext($_GET['next'] ?? null, 'index.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf($_POST['csrf'] ?? null)) {
        $error = 'Invalid form token. Please reload and try again.';
    } elseif (Auth::attemptLogin(trim($_POST['username'] ?? ''), $_POST['password'] ?? '')) {
        header('Location: ' . Auth::safeNext($_POST['next'] ?? null, 'index.php'));
        exit;
    } else {
        // Generic message: never reveal whether the username or password was wrong,
        // nor whether the account is temporarily locked out.
        $error = 'Invalid credentials. Please try again.';
    }
}

$csrf = Auth::csrfToken();
$next = Auth::safeNext($_GET['next'] ?? null, 'index.php');
$justSetup = isset($_GET['setup']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log in — Smarketer Pro</title>
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
.ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:#86efac;border-radius:.75rem;padding:.7rem .9rem;font-size:.85rem;margin-bottom:.6rem}
</style>
</head>
<body>
<div class="card">
  <h1>🚀 Smarketer Pro</h1>
  <p class="sub">Log in to your lead hub.</p>
  <?php if ($justSetup): ?><div class="ok">Admin account created. Please log in.</div><?php endif; ?>
  <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post" action="login.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
    <label for="username">Username</label>
    <input id="username" name="username" autocomplete="username" required autofocus>
    <label for="password">Password</label>
    <input id="password" type="password" name="password" autocomplete="current-password" required>
    <button type="submit">Log in</button>
  </form>
</div>
</body>
</html>
