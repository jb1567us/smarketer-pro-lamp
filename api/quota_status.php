<?php
/**
 * Quota Status API Endpoint
 *
 * Powers the "Provider Quota & Health Monitor" card in System Settings
 * (assets/js/dashboard.js -> fetchQuotaUsage()). Returns per-service daily
 * usage against free-tier limits, derived from the api_usage_logs table
 * maintained by \App\Routers\SmartRotationManager.
 *
 *   GET api/quota_status.php
 *     -> {"success": true, "data": [{"name","type","status","used","limit"}, ...]}
 *
 * status is one of: active | cooldown | limited | unconfigured
 *
 * All requests require auth via \App\Auth::requireApiAuth().
 * Never exposes API keys — only service names and aggregate counts.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
\App\Auth::requireApiAuth();

function quota_error(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

try {
    $pdo = \App\Database::getConnection();

    // api_usage_logs predates schema.sql on older installs; create idempotently
    // (prepare/execute is used instead of exec() for PDO-shim compatibility).
    $pdo->prepare(
        "CREATE TABLE IF NOT EXISTS api_usage_logs (" .
        "id INT AUTO_INCREMENT PRIMARY KEY, " .
        "service_name VARCHAR(100) NOT NULL, " .
        "api_key_masked VARCHAR(64) NOT NULL, " .
        "status VARCHAR(20) NOT NULL, " .
        "error_message TEXT, " .
        "timestamp INT NOT NULL, " .
        "INDEX idx_service_time (service_name, timestamp)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )->execute();

    $manager = new \App\Routers\SmartRotationManager($pdo);
    $limits = \App\Routers\SmartRotationManager::getFreeTierDailyLimits();

    // Node type shown under each provider name in the dashboard.
    $types = [
        'tavily' => 'search', 'exa' => 'search', 'serper' => 'search',
        'scrapingant' => 'scrape', 'firecrawl' => 'scrape',
        'gemini' => 'llm', 'groq' => 'llm',
        'brevo' => 'email', 'resend' => 'email', 'sendgrid' => 'email',
        'mailjet' => 'email', 'mailersend' => 'email',
    ];

    $data = [];
    foreach ($limits as $service => $perKeyLimit) {
        $rawKeys = \App\Database::getSetting($service . '_api_key', '');
        $keyCount = count($manager->getKeys($rawKeys));

        if ($keyCount === 0) {
            $status = 'unconfigured';
            $used = 0;
        } else {
            $used = $manager->getServiceTotalDailyUsage($service, $rawKeys);
            if ($manager->isServiceRateLimited($service, $rawKeys)) {
                $status = 'limited';
            } elseif ($manager->isAnyKeyCoolingDown($service, $rawKeys)) {
                $status = 'cooldown';
            } else {
                $status = 'active';
            }
        }

        $data[] = [
            'name' => $service,
            'type' => $types[$service] ?? 'service',
            'status' => $status,
            'used' => $used,
            // Limit scales with the number of configured keys (each key gets the free tier).
            'limit' => $perKeyLimit * max(1, $keyCount),
        ];
    }

    echo json_encode(['success' => true, 'data' => $data]);
} catch (\Throwable $e) {
    error_log('[quota_status] ' . $e->getMessage());
    quota_error(500, 'Could not load quota metrics.');
}
