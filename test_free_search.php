<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
require_once __DIR__ . '/includes/autoload.php';
require_once __DIR__ . '/includes/Search/GeminiSearchProvider.php';
require_once __DIR__ . '/includes/Search/DuckDuckGoProvider.php';

$pdo = \App\Database::getConnection();
$apiKey = \App\Database::getSetting('gemini_api_key');

echo "--- Testing Gemini Search (Free) ---\n";
try {
    $gemini = new GeminiSearchProvider($apiKey);
    $results = $gemini->search("B2B lead generation", 3);
    echo "Found " . count($results) . " results.\n";
    foreach($results as $r) echo "- {$r['title']} ({$r['link']})\n";
} catch (Exception $e) {
    echo "Gemini Error: " . $e->getMessage() . "\n";
}

echo "\n--- Testing DuckDuckGo Search (Mass) ---\n";
try {
    $ddg = new DuckDuckGoProvider();
    $results = $ddg->search("SaaS companies Texas", 5);
    echo "Found " . count($results) . " results.\n";
    foreach($results as $r) echo "- {$r['title']} ({$r['link']})\n";
} catch (Exception $e) {
    echo "DDG Error: " . $e->getMessage() . "\n";
}
