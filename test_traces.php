<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/autoload.php';

try {
    $pdo = \App\Database::getConnection();
    echo "Connected successfully to DB.\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM agent_traces");
    $row = $stmt->fetch();
    $count = $row['count'] ?? 0;
    echo "Total Traces in DB: $count\n\n";
    
    $stmt = $pdo->query("SELECT id, persona, goal, created_at FROM agent_traces ORDER BY id DESC LIMIT 5");
    $rows = $stmt->fetchAll(\App\PDO::FETCH_ASSOC);
    
    echo "Last 5 Traces:\n";
    foreach ($rows as $row) {
        echo "- ID: {$row['id']} | Persona: {$row['persona']} | Goal: {$row['goal']} | Created At: {$row['created_at']}\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
