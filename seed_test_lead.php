<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/autoload.php';
$pdo = \App\Database::getConnection();

$sql = "INSERT INTO leads (id, company_name, website, email, source) VALUES (1, 'Test Business', 'https://example.com', 'test@example.com', 'Test') ON DUPLICATE KEY UPDATE company_name=company_name";

try {
    $pdo->exec($sql);
    echo "Seed data inserted successfully!\n";
} catch (Exception $e) {
    echo "Error inserting seed data: " . $e->getMessage() . "\n";
}
