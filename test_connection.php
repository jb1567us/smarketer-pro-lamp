<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = getenv('DB_HOST') ?: 'db';
$db   = getenv('DB_NAME') ?: 'b2b_outreach';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'rootpassword';

echo "Testing connection to host: $host, DB: $db, User: $user\n";

try {
    $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
    $pdo = new \\App\\PDO($dsn, $user, $pass);
    echo "Connection successful!\n";
    
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(\App\PDO::FETCH_COLUMN);
    echo "Tables in database: " . implode(', ', $tables) . "\n";
} catch (Exception $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
}
?>
