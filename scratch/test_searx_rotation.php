<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/../includes/Search/SearXNGProvider.php';

class TestSearXNGProvider extends SearXNGProvider {
    public function testGetInstances() {
        $reflection = new ReflectionClass('SearXNGProvider');
        $method = $reflection->getMethod('getDynamicPublicInstances');
        $method->setAccessible(true);
        return $method->invoke($this);
    }
    
    public function testPerformSearch($url, $query) {
        $reflection = new ReflectionClass('SearXNGProvider');
        $method = $reflection->getMethod('performSearch');
        $method->setAccessible(true);
        return $method->invoke($this, $url, $query, 5);
    }
}

echo "=== SEARXNG INSTANCE ROTATION TEST ===\n";

$provider = new TestSearXNGProvider();
try {
    echo "Fetching dynamic instances from searx.space...\n";
    $instances = $provider->testGetInstances();
    echo "Found " . count($instances) . " secure, healthy public instances!\n\n";
    
    $limit = min(15, count($instances));
    echo "Top $limit instances in shuffled pool:\n";
    for ($i = 0; $i < $limit; $i++) {
        echo "[" . ($i + 1) . "] " . $instances[$i] . "\n";
    }
    
    echo "\nTesting connectivity and search on the top 5 instances...\n";
    for ($i = 0; $i < min(5, count($instances)); $i++) {
        $url = $instances[$i];
        echo "Testing $url ... ";
        try {
            $results = $provider->testPerformSearch($url, 'Real Estate Austin');
            echo "SUCCESS! Returned " . count($results) . " results.\n";
            if (!empty($results)) {
                echo "   -> Sample: " . $results[0]['title'] . "\n";
            }
        } catch (Exception $e) {
            echo "FAILED: " . $e->getMessage() . "\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
