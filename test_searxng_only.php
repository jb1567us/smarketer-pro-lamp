<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
header('Content-Type: text/plain');
require_once __DIR__ . '/includes/autoload.php';
require_once __DIR__ . '/includes/Search/SearXNGProvider.php';

echo "=== Direct SearXNG Diagnostics ===\n";

$provider = new SearXNGProvider();
try {
    echo "Running search query...\n";
    $results = $provider->search('Fitness gym Texas', 5);
    echo "[Success] Direct SearXNG succeeded and returned " . count($results) . " results!\n";
    if (!empty($results)) {
        echo "First result title: " . $results[0]['title'] . "\n";
        echo "First result link: " . $results[0]['url'] . "\n";
    }
} catch (Exception $e) {
    echo "[Failure] Direct SearXNG failed:\n";
    echo $e->getMessage() . "\n";
}
