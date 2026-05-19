<?php
require_once __DIR__ . '/../includes/autoload.php';
try {
    $pdo = \App\Database::getConnection();
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    $settings = $stmt->fetchAll(\App\PDO::FETCH_ASSOC);
    echo json_encode($settings, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
