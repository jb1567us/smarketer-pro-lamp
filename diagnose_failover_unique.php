<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
header('Content-Type: text/plain');
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/autoload.php';
require_once __DIR__ . '/includes/SimpleHarvester.php';

// Set active provider in settings database to 'ddg' to trigger our test
$pdo = \App\Database::getConnection();
$stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_search_provider', 'ddg') ON DUPLICATE KEY UPDATE setting_value = 'ddg'");
$stmt->execute();

echo "Active search provider has been forced to 'ddg' in the database.\n\n";

// Instantiate harvester
$harvester = new \SimpleHarvester();

echo "Running harvest for query 'site:linkedin.com \"Austin\" \"CEO\"'...\n";
try {
    $results = $harvester->harvest('site:linkedin.com "Austin" "CEO"', 3);
    echo "SUCCESSFULLY HARVESTED! Total results: " . count($results) . "\n";
    foreach ($results as $idx => $res) {
        echo ($idx + 1) . ". [" . $res['source'] . "] " . $res['title'] . " -> " . $res['url'] . "\n";
    }
} catch (\Exception $e) {
    echo "HARVEST FAILED WITH EXCEPTION:\n" . $e->getMessage() . "\n";
}

// Fetch active provider from DB to verify if it rotated
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'active_search_provider'");
$stmt->execute();
$row = $stmt->fetch();
$active = $row ? $row['setting_value'] : 'unknown';
echo "\nActive search provider in DB now: " . $active . "\n";
