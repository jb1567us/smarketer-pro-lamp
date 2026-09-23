<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
header('Content-Type: text/plain');
require_once __DIR__ . '/includes/autoload.php';
require_once __DIR__ . '/includes/SimpleHarvester.php';

echo "=== Search Diagnostics & Immediate Failover Test ===\n";

$db = \App\Database::getConnection();

// 1. Reset settings to force SearXNG as starting active provider
echo "[Test] Resetting active_search_provider to 'searxng'...\n";
$stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_search_provider', 'searxng') ON DUPLICATE KEY UPDATE setting_value = 'searxng'");
$stmt->execute();

// Reset consecutive failures
$stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('search_consecutive_failures', '0') ON DUPLICATE KEY UPDATE setting_value = '0'");
$stmt->execute();

// 2. Load harvester and verify it is SearXNG
$harvester = new SimpleHarvester();
echo "[Test] Initial Active Provider Name: " . $harvester->getActiveProviderName() . "\n";

// 3. Attempt search - should transparently pivot to DuckDuckGo/Gemini on failure and return results immediately!
echo "[Test] Running query (primary SearXNG should fail, trigger immediate in-flight failover, and return results)...\n";
try {
    $results = $harvester->harvest('Fitness gym Texas', 5);
    echo "[Success] Query completed successfully and returned " . count($results) . " results!\n";
    
    // Check active provider now
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'active_search_provider'");
    $stmt->execute();
    $active = $stmt->fetchColumn();
    echo "[Success] Active provider dynamically updated to: " . $active . "\n";
    
    if (!empty($results)) {
        echo "[Success] First result title: " . $results[0]['title'] . "\n";
        echo "[Success] First result link: " . $results[0]['link'] . "\n";
    }
} catch (Exception $e) {
    echo "[Failure] Search fully failed: " . $e->getMessage() . "\n";
}
