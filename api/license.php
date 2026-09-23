<?php
/**
 * License API — status + buyer self-service actions.
 *
 * Security model: requires an authenticated admin session (like every other
 * api/* endpoint). The license server itself is contacted server-side with a
 * short timeout; unreachable never errors the UI.
 *
 *   GET  ?action=status    → \App\Licensing::statusForUi()
 *   POST ?action=validate   → re-validate the stored key now
 *   POST ?action=register   → {key} register this domain (activate flow)
 *   POST ?action=release    → release this domain's slot (self-service)
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';

\App\Auth::requireApiAuth();

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'status');

try {
    switch ($action) {
        case 'status':
            echo json_encode(['success' => true, 'data' => \App\Licensing::statusForUi()]);
            break;

        case 'validate':
            \App\Auth::requireCsrf();
            $v = \App\Licensing::validateNow();
            echo json_encode(['success' => true, 'data' => \App\Licensing::statusForUi(), 'verdict' => $v['status']]);
            break;

        case 'register':
            \App\Auth::requireCsrf();
            $key = trim((string)($_POST['key'] ?? ''));
            if ($key === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'License key is required.']);
                break;
            }
            $v = \App\Licensing::registerNow($key);
            echo json_encode(['success' => true, 'data' => \App\Licensing::statusForUi(), 'verdict' => $v['status'], 'detail' => $v['detail']]);
            break;

        case 'release':
            \App\Auth::requireCsrf();
            $r = \App\Licensing::releaseDomainNow();
            echo json_encode(['success' => $r['ok'], 'data' => \App\Licensing::statusForUi(), 'message' => $r['message']]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    // Never leak internals; the UI shows a generic message.
    echo json_encode(['success' => false, 'error' => 'License action failed. Please try again.']);
}
