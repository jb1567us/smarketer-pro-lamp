<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/PDO.php';

$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'lookoverhere_wp947';
$username = getenv('DB_USER') ?: 'lookoverhere_wp947';
$password = getenv('DB_PASS') ?: ']Vg6[y)1)5SYp]0Z';

$dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
try {
    $pdo = new \App\PDO($dsn, $username, $password, []);
    $stmt = $pdo->prepare("SELECT ? AS val1, ? AS val2");
    $stmt->execute([123, 'hello']);
    $rows = $stmt->fetchAll();
    echo "SUCCESS: " . json_encode($rows);
} catch (\App\PDOException $e) {
    echo "ERROR: " . $e->getMessage();
} catch (\Throwable $e) {
    echo "FATAL: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
}
