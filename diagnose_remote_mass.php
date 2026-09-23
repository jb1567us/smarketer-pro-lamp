<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
header('Content-Type: text/plain');
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/autoload.php';

$pdo = \App\Database::getConnection();
echo "--- LATEST 20 API USAGE LOGS ---\n";
$stmt = $pdo->prepare("SELECT * FROM api_usage_logs ORDER BY id DESC LIMIT 20");
$stmt->execute();
$rows = $stmt->fetchAll(\App\PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    echo "[" . date('Y-m-d H:i:s', $row['timestamp']) . "] " . $row['service_name'] . " (" . $row['api_key_masked'] . ") -> Status: " . $row['status'] . ", Error: " . $row['error_message'] . "\n";
}
