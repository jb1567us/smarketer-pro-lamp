<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/autoload.php';
$pdo = \App\Database::getConnection();

echo "=== DIAGNOSE SETTINGS ===\n";
$stmt = $pdo->query("SELECT * FROM settings");
while ($row = $stmt->fetch(\App\PDO::FETCH_ASSOC)) {
    echo "{$row['setting_key']}: {$row['setting_value']}\n";
}

echo "\n=== DIAGNOSE JOBS ===\n";
$stmt = $pdo->query("SELECT id, type, status, error_message, attempts, created_at FROM jobs ORDER BY id DESC LIMIT 10");
while ($row = $stmt->fetch(\App\PDO::FETCH_ASSOC)) {
    print_r($row);
}
