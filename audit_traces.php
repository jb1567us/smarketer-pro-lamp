<?php
require_once __DIR__ . '/includes/autoload.php';
$pdo = \App\Database::getConnection();

echo "--- AGENT REASONING TRACES ---\n";
try {
    $stmt = $pdo->query("SELECT * FROM agent_traces ORDER BY created_at DESC LIMIT 5");
    $traces = $stmt->fetchAll();
    
    if (empty($traces)) {
        echo "No traces found.\n";
    }

    foreach ($traces as $t) {
        echo "[ID: {$t['id']}] [Persona: {$t['persona']}] [Mode: {$t['operational_mode']}]\n";
        echo "GOAL: " . substr($t['goal'], 0, 50) . "...\n";
        echo "OUTPUT: " . substr($t['reasoning_output'], 0, 100) . "...\n";
        echo "-----------------------------\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
