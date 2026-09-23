<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
header('Content-Type: text/plain');
require_once __DIR__ . '/includes/autoload.php';
require_once __DIR__ . '/includes/Search/SerperProvider.php';

$pdo = \App\Database::getConnection();
$apiKey = \App\Database::getSetting('serper_api_key');

if (!$apiKey) {
    echo "Error: serper_api_key is empty in database.\n";
    exit(1);
}

echo "=== Testing Serper Search ===\n";
try {
    $serper = new SerperProvider($apiKey);
    $results = $serper->search("Fitness gym Texas", 5);
    echo "Success! Found " . count($results) . " results:\n";
    foreach ($results as $r) {
        echo "- {$r['title']} ({$r['link']})\n";
    }
} catch (Exception $e) {
    echo "Serper Error: " . $e->getMessage() . "\n";
}
