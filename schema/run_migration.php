<?php
require_once __DIR__ . '/../includes/autoload.php';

try {
    $pdo = \App\Database::getConnection();
    echo "Connected to database successfully.\n";

    $sqlFile = __DIR__ . '/001_jobs_queue.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration SQL file not found.");
    }

    $sql = file_get_contents($sqlFile);
    // Split by semicolon and execute queries
    $queries = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($queries as $q) {
        if (!empty($q)) {
            $pdo->exec($q);
        }
    }
    echo "Migration 001 executed successfully.\n";
} catch (Exception $e) {
    echo "MIGRATION ERROR: " . $e->getMessage() . "\n";
}
