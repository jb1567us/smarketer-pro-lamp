<?php
require_once __DIR__ . '/includes/autoload.php';
require_once __DIR__ . '/includes/SimpleHarvester.php';

$h = new SimpleHarvester();
echo "Active provider: " . $h->getActiveProviderName() . "\n";
try {
    $results = $h->harvest('test', 5);
    var_dump($results);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
