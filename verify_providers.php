<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/autoload.php';
$pdo = \App\Database::getConnection();

require_once __DIR__ . '/includes/SimpleHarvester.php';

// Mock Config for Test
function setProvider($pdo, $name) {
    echo "\n🔄 Switching to Provider: $name...\n";
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_search_provider', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$name, $name]);
    
    // Set dummy keys if empty (for test to proceed until API call)
    if ($name === 'tavily') {
         $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('tavily_api_key', 'tvly-dummy')");
    }
    if ($name === 'vercel_bridge') {
         $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('vercel_bridge_url', 'http://localhost:3000/api')");
    }
    if ($name === 'scrapingant') {
         $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('scrapingant_api_key', 'sa-dummy')");
    }
}

$providers = ['active', 'searxng', 'direct', 'vercel_bridge', 'scrapingant', 'ddg']; // Test expanded list

foreach ($providers as $p) {
    if ($p !== 'active') setProvider($pdo, $p);
    
    try {
        $harvester = new SimpleHarvester();
        echo "   [Active: " . $harvester->getActiveProviderName() . "]\n";
        
        $results = $harvester->harvest("site:example.com test", 1);
        
        echo "   ✅ Success! Found " . count($results) . " results.\n";
        if (count($results) > 0) {
            echo "      Sample: " . $results[0]['title'] . " (" . $results[0]['url'] . ")\n";
        }
    } catch (Exception $e) {
        echo "   ❌ Error: " . $e->getMessage() . "\n";
    }
}
?>
