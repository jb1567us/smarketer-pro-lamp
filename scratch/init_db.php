<?php
$host = '127.0.0.1';
$port = '33306';
$username = 'root';
$password = '';
$dbname = 'lookoverhere_wp947';

try {
    $pdo = new \PDO("mysql:host={$host};port={$port}", $username, $password);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database `$dbname` created or already exists.\n";
    
    $pdo->exec("USE `$dbname` ");
    $schema = file_get_contents(__DIR__ . '/../schema.sql');
    // Simple split by semicolon - usually works for basic schemas
    $queries = explode(';', $schema);
    foreach ($queries as $query) {
        $query = trim($query);
        if ($query) {
            $pdo->exec($query);
        }
    }
    echo "Schema imported successfully.\n";


} catch (\Exception $e) {
    echo "Initialization failed: " . $e->getMessage();
}
