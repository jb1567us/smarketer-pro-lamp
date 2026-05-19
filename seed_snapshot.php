<?php
$persona = "B2B ICP Specialist";
$goal = "Process the provided context and return a structured JSON response.";
$context = "Company: Golden Master Corp\nWebsite: \nContact: ";

$snapshotHash = md5($persona . $goal . $context);
$snapshotPath = __DIR__ . "/snapshots/{$snapshotHash}.json";

$data = [
    'score' => 95,
    'qualified' => true,
    'reason' => 'High-growth sector matching ICP.'
];

if (!is_dir(__DIR__ . '/snapshots')) mkdir(__DIR__ . '/snapshots');
file_put_contents($snapshotPath, json_encode($data));
echo "Snapshot created: snapshots/{$snapshotHash}.json\n";
