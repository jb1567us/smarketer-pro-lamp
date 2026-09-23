<?php
require_once __DIR__ . '/../includes/autoload.php';
$host = '127.0.0.1';
$port = '33306';
$dbname = 'lookoverhere_wp947';
$username = 'root';
$password = '';
$dsn = "mysql:host={$host};port={$port};dbname=lookoverhere_wp947;charset=utf8mb4";
try {
    $pdo = new \PDO($dsn, $username, $password, [\PDO::ATTR_TIMEOUT => 10, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->query("DESCRIBE leads");
    print_r($stmt->fetchAll());
} catch (\Exception $e) {
    echo "Connection failed: " . $e->getMessage();
}






