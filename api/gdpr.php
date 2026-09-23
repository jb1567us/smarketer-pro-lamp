<?php
/**
 * GDPR data-subject rights endpoint.
 *
 * Admin (authenticated) actions:
 *   GET  ?action=export&email=...   JSON download of everything stored about the address
 *   POST ?action=erase&email=...    Erase PII, retain hash-only suppression row; JSON summary
 *
 * Public (NO login — recipient arrives from a link in an email):
 *   GET  ?action=object&token=...   tiny confirmation page
 *   POST ?action=object (token=...) performs the objection, then a generic confirmation
 *
 * The public objection flow NEVER reveals whether an address exists in the
 * system: valid, invalid, and missing tokens all render the same generic
 * confirmation page.
 */
require_once __DIR__ . '/../includes/autoload.php';

use App\Auth;
use App\Database;
use App\Gdpr;

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';

// ------------------------------------------------------------------
// Public: one-click objection (Art. 21). No auth — token proves address control.
// ------------------------------------------------------------------
if ($action === 'object') {
    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    $token = is_string($token) ? $token : '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token !== '') {
        $email = Gdpr::verifyObjectionToken($token);
        if ($email !== null) {
            try {
                Gdpr::object($email, $token);
            } catch (\Throwable $e) {
                // Log it; the response stays generic either way.
                error_log('[gdpr] objection failed: ' . $e->getMessage());
            }
        }
        renderObjectionDone();
        exit;
    }

    renderObjectionConfirm($token);
    exit;
}

// ------------------------------------------------------------------
// Admin actions below this line.
// ------------------------------------------------------------------
Auth::requireApiAuth();
$pdo = Database::getConnection();

function gdprRequestEmail(): string
{
    $email = strtolower(trim((string)($_REQUEST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Valid email parameter required']);
        exit;
    }
    return $email;
}

if ($action === 'export') {
    $email = gdprRequestEmail();
    $data = Gdpr::export($email, $pdo);
    // Filename carries a hash, never the address (it lands in download folders/logs).
    $tag = substr(hash('sha256', $email), 0, 12);
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="gdpr-export-' . $tag . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'erase') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Method not allowed (POST required)']);
        exit;
    }
    $email = gdprRequestEmail();
    $summary = Gdpr::eraseReport($email, $pdo);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $summary]);
    exit;
}

http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Unknown action']);
exit;

// ------------------------------------------------------------------
// Public objection pages (tiny, generic — no existence leakage).
// ------------------------------------------------------------------
function renderObjectionConfirm(string $token): void
{
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Object to processing</title>
<style>
body{font-family:Inter,system-ui,sans-serif;background:#0b0f1a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1rem}
.card{background:rgba(30,41,59,.7);border:1px solid rgba(255,255,255,.1);border-radius:1.5rem;padding:2.5rem;width:100%;max-width:26rem;text-align:center}
h1{margin:0 0 .75rem;font-size:1.3rem}
p{color:#94a3b8;font-size:.95rem;line-height:1.6}
button{background:linear-gradient(to right,#2563eb,#4f46e5);border:none;border-radius:.75rem;color:#fff;font-weight:700;padding:.8rem 2rem;font-size:1rem;cursor:pointer;margin-top:1rem}
</style>
</head>
<body>
<div class="card">
  <h1>Object to data processing?</h1>
  <p>Confirming will permanently delete all personal data we hold about the address
     this link was issued for, and stop all marketing to it.</p>
  <form method="post" action="gdpr.php?action=object">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <button type="submit">Delete my data and stop contacting me</button>
  </form>
</div>
</body>
</html>
    <?php
}

function renderObjectionDone(): void
{
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Request recorded</title>
<style>
body{font-family:Inter,system-ui,sans-serif;background:#0b0f1a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1rem}
.card{background:rgba(30,41,59,.7);border:1px solid rgba(255,255,255,.1);border-radius:1.5rem;padding:2.5rem;width:100%;max-width:26rem;text-align:center}
h1{margin:0 0 .75rem;font-size:1.3rem;color:#6ee7b7}
p{color:#94a3b8;font-size:.95rem;line-height:1.6}
</style>
</head>
<body>
<div class="card">
  <h1>✓ Request recorded</h1>
  <p>If we held any data for the address this link was issued for, it has been
     deleted and the address will not be contacted again.</p>
</div>
</body>
</html>
    <?php
}
