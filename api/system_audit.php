<?php
require_once __DIR__ . '/../includes/autoload.php';
\App\Auth::requireApiAuth();
require_once __DIR__ . '/../includes/Supervisor.php';

header('Content-Type: application/json');

try {
    $supervisor = new App\Supervisor();
    $auditResults = $supervisor->runFullAudit();
    echo json_encode($auditResults);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'database' => 'Error',
        'error' => $e->getMessage()
    ]);
}
