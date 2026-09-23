<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
require_once __DIR__ . '/includes/autoload.php';
$pdo = \App\Database::getConnection();


$persona = "B2B ICP Specialist";
$goal = "Determine if this company matches a High-Value Prospect profile.";
$context = "Company: Golden Master Corp\nWebsite: \nContact: ";

$hash = md5($persona . $goal . $context);
echo "Target Hash: $hash\n";

$snapshotPath = __DIR__ . "/snapshots/{$hash}.json";
echo "Snapshot Path: $snapshotPath\n";
echo "File Exists: " . (file_exists($snapshotPath) ? "YES" : "NO") . "\n";

if (file_exists($snapshotPath)) {
    echo "Content: " . file_get_contents($snapshotPath) . "\n";
}

// Test callAgent directly
$processor = new \App\Domain\TaskProcessor($pdo, new \App\Routers\SmartLLMRouter($pdo));
$ref = new ReflectionClass($runner);
$method = $ref->getMethod('callAgent');
$method->setAccessible(true);

echo "Testing callAgent directly...\n";
try {
    $res = $method->invoke($runner, $persona, $goal, $context, 1);
    echo "callAgent Result: " . json_encode($res) . "\n";
} catch (Exception $e) {
    echo "callAgent Error: " . $e->getMessage() . "\n";
}

// Check traces
$stmt = $pdo->query("SELECT COUNT(*) FROM agent_traces");
echo "Total Traces: " . $stmt->fetchColumn() . "\n";
