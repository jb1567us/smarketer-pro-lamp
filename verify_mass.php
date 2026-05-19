<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/autoload.php';
$pdo = \App\Database::getConnection();
require_once 'includes/ProxyManager.php';
require_once 'includes/SimpleHarvester.php';

echo "--- MASS TOOLS VERIFICATION ---\n";

try {
    // 1. Test Proxy Manager
    echo "[1] Testing ProxyManager...\n";
    $pm = new ProxyManager($pdo);
    
    // Clear first to keep test clean
    $pdo->exec("DELETE FROM proxies");
    
    $count = $pm->addProxies(['1.2.3.4:8080:user:pass', '5.6.7.8:3128']);
    echo "Added $count proxies.\n";
    
    // Clear proxies table to test direct connection fallback
    $pdo->exec("DELETE FROM proxies");
    
    $proxy = $pm->getProxy();
    echo "Retrieved Proxy (Should be empty): " . ($proxy ?: "None") . "\n";

    // 2. Test Harvester with DuckDuckGo
    echo "\n[2] Testing SimpleHarvester with DuckDuckGo...\n";
    
    // Force active search provider to ddg in settings for this test
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_search_provider', 'ddg') ON DUPLICATE KEY UPDATE setting_value = 'ddg'");
    $stmt->execute();
    
    $harvester = new SimpleHarvester();
    echo "Active Provider: " . $harvester->getActiveProviderName() . "\n";
    
    echo "Running harvest for query 'python automation'...\n";
    $results = $harvester->harvest('python automation', 3);
    echo "Harvest Results:\n";
    print_r($results);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
