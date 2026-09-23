<?php
/**
 * Diagnostics API — runs the self-service health checks and returns JSON.
 *
 * Admin-only (Auth::requireApiAuth). GET only. Each check is guarded inside
 * \App\Diagnostics::runAll(), so a single broken probe can never 500 the
 * whole response. Provider checks verify credentials against read-only
 * endpoints / SMTP login only — they never send email.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
\App\Auth::requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    $checks = \App\Diagnostics::runAll();
    echo json_encode([
        'success' => true,
        'app_version' => \App\Diagnostics::APP_VERSION,
        'php_version' => PHP_VERSION,
        'generated_at' => date('Y-m-d H:i:s'),
        'report' => \App\Diagnostics::renderTextReport($checks),
        'checks' => $checks,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Diagnostics failed: ' . $e->getMessage()]);
}
