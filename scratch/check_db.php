<?php
require_once __DIR__ . '/../includes/autoload.php';

try {
    $pdo = \App\Database::getConnection();
    $stmt = $pdo->query('SHOW TABLES');
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Active tables in the database:\n";
    foreach ($tables as $t) {
        echo " - $t\n";
    }
} catch (Exception $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}
