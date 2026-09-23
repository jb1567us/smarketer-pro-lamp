<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
try {
    require_once __DIR__ . '/includes/autoload.php';
    $pdo = \App\Database::getConnection();
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings");
    $stmt->execute();
    $rows = $stmt->fetchAll(\App\PDO::FETCH_ASSOC);
    echo json_encode($rows, JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString() . "\n";
}
