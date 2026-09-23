<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
ini_set('display_errors', 1);
ini_set('html_errors', 0);
error_reporting(E_ALL);

function custom_error_handler($errno, $errstr, $errfile, $errline) {
    echo "PHP ERROR [{$errno}]: {$errstr} in {$errfile} on line {$errline}\n";
    exit(1);
}
set_error_handler('custom_error_handler');

require_once __DIR__ . '/includes/autoload.php';
require_once __DIR__ . '/includes/SimpleHarvester.php';

try {
    $db = \App\Database::getConnection();
    echo "=== Starting Prospect Discovery E2E Verification ===\n";

    // 1. Ensure test campaign exists
    $db->exec("DELETE FROM campaigns WHERE name = 'E2E Test Campaign'");
    $db->exec("INSERT INTO campaigns (name, description, is_active) VALUES ('E2E Test Campaign', 'Test Campaign Description', 1)");
    $campaignId = $db->lastInsertId();
    echo "✔ Created Test Campaign #{$campaignId}\n";

    // 2. Clear previous test leads
    $db->exec("DELETE FROM leads WHERE campaign_id = {$campaignId}");
    echo "✔ Cleared old test leads\n";

    // 3. Define mock search results
    $mockResults = [
        [
            'url' => 'https://alpha-analytics.io',
            'title' => 'Alpha Analytics - Custom Data Strategy',
            'content' => 'Premium business intelligence and custom dashboard solutions for enterprise operations.'
        ],
        [
            'url' => 'https://beta-consulting.com/services',
            'title' => 'Beta Consulting | Growth Advisory Services',
            'content' => 'Strategic advisory and fractional management services for fast-growing companies.'
        ]
    ];

    $query = "B2B consulting companies";
    $persona = "Growth Marketing Director";

    echo "Staging results for the first time...\n";
    $stats = \SimpleHarvester::stageResults($db, $mockResults, $query, $campaignId, $persona);

    echo "✔ Staged: {$stats['staged']} leads\n";
    echo "✔ Duplicates detected: {$stats['duplicates']} leads\n";

    // Validate insertions
    $stmt = $db->prepare("SELECT * FROM leads WHERE campaign_id = ? ORDER BY id ASC");
    $stmt->execute([$campaignId]);
    $leads = $stmt->fetchAll(2); // 2 = FETCH_ASSOC

    if (count($leads) !== 2) {
        throw new Exception("Expected 2 staged leads, got " . count($leads));
    }

    echo "✔ Lead 1 Validation:\n";
    echo "  Company Name: {$leads[0]['company_name']}\n";
    echo "  Contact Name (Persona): {$leads[0]['contact_name']}\n";
    echo "  Email (Placeholder): {$leads[0]['email']}\n";
    echo "  Website: {$leads[0]['website']}\n";
    echo "  Source: {$leads[0]['source']}\n";
    echo "  Status: {$leads[0]['status']}\n";

    // 4. Test Deduplication
    echo "Staging same results again (Deduplication Check)...\n";
    $dupStats = \SimpleHarvester::stageResults($db, $mockResults, $query, $campaignId, $persona);

    echo "✔ Staged: {$dupStats['staged']} leads\n";
    echo "✔ Duplicates detected: {$dupStats['duplicates']} leads\n";

    if ($dupStats['staged'] !== 0 || $dupStats['duplicates'] !== 2) {
        throw new Exception("Deduplication check failed! Expected 0 staged and 2 duplicates.");
    }

    echo "✔ Deduplication successfully prevented staging duplicates!\n";

    // Clean up test data
    $db->exec("DELETE FROM leads WHERE campaign_id = {$campaignId}");
    $db->exec("DELETE FROM campaigns WHERE id = {$campaignId}");
    echo "✔ E2E Verification Completed with 100% SUCCESS!\n";

} catch (Throwable $e) {
    echo "✖ E2E Verification Failed: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n";
    exit(1);
}
