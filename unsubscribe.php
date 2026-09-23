<?php
/**
 * Public one-click unsubscribe landing page.
 *
 * No login required — recipients arrive here from the List-Unsubscribe header
 * or the footer link in a commercial email. The token is an HMAC of the
 * recipient's email, so it cannot be forged or enumerated.
 *
 * GET  ?token=...  -> confirm screen (shows the address, asks to confirm)
 * POST token=...   -> performs the opt-out, writes the suppression list
 */
require_once __DIR__ . '/includes/autoload.php';

use App\Compliance;

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$email = is_string($token) && $token !== '' ? Compliance::verifyUnsubscribeToken($token) : null;
$done = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($email === null) {
        $error = 'This unsubscribe link is invalid or has expired.';
    } else {
        try {
            Compliance::suppress($email, 'unsubscribe', 'unsubscribe.php');
            // Belt and braces: mark the lead row too, so list views reflect it.
            try {
                $pdo = \App\Database::getConnection();
                $stmt = $pdo->prepare("UPDATE leads SET consent_status = 'unknown', notes = CONCAT(COALESCE(notes, ''), '\n[Unsubscribed ', NOW(), ']') WHERE email = ?");
                $stmt->execute([$email]);
            } catch (\Throwable $e) {
                error_log('[unsubscribe] lead-row mark failed: ' . $e->getMessage());
            }
            $done = true;
        } catch (\Throwable $e) {
            error_log('[unsubscribe] suppression failed: ' . $e->getMessage());
            $error = 'Something went wrong. Please try again later.';
        }
    }
}

$legalName = '';
try {
    $legalName = (string)(\App\Database::getSetting('company_legal_name', '') ?? '');
} catch (\Throwable $e) { /* branding is optional here */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Unsubscribe<?= $legalName !== '' ? ' — ' . htmlspecialchars($legalName) : '' ?></title>
<style>
body{font-family:Inter,system-ui,sans-serif;background:#0b0f1a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1rem}
.card{background:rgba(30,41,59,.7);border:1px solid rgba(255,255,255,.1);border-radius:1.5rem;padding:2.5rem;width:100%;max-width:26rem;text-align:center}
h1{margin:0 0 .75rem;font-size:1.3rem}
p{color:#94a3b8;font-size:.95rem;line-height:1.6}
button{background:linear-gradient(to right,#2563eb,#4f46e5);border:none;border-radius:.75rem;color:#fff;font-weight:700;padding:.8rem 2rem;font-size:1rem;cursor:pointer;margin-top:1rem}
.err{color:#fca5a5}.ok{color:#6ee7b7}
.addr{color:#f8fafc;font-weight:600;word-break:break-all}
</style>
</head>
<body>
<div class="card">
<?php if ($done): ?>
  <h1 class="ok">✓ You're unsubscribed</h1>
  <p><span class="addr"><?= htmlspecialchars($email) ?></span> will not receive further marketing emails from <?= $legalName !== '' ? htmlspecialchars($legalName) : 'us' ?>.</p>
<?php elseif ($error !== null): ?>
  <h1 class="err">Link problem</h1>
  <p><?= htmlspecialchars($error) ?></p>
<?php elseif ($email === null): ?>
  <h1 class="err">Link problem</h1>
  <p>This unsubscribe link is invalid or has expired.</p>
<?php else: ?>
  <h1>Unsubscribe?</h1>
  <p>Click below to stop marketing emails to<br><span class="addr"><?= htmlspecialchars($email) ?></span></p>
  <form method="post" action="unsubscribe.php">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <button type="submit">Unsubscribe me</button>
  </form>
  <p style="font-size:.8rem;margin-top:1.5rem">One click, effective immediately. Transactional or operational mail you requested may still arrive.</p>
<?php endif; ?>
</div>
</body>
</html>
