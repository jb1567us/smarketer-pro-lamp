<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();

require_once __DIR__ . '/includes/autoload.php';

use App\Verification\TrustScorer;

echo "=== TESTING TRUST SCORER ===\n";

$scenarios = [
    [
        'name' => 'Scenario 1: Gold Standard Verification (All checkmarks matching)',
        'params' => [
            'hasLevel1' => true,
            'hasLevel2' => true,
            'hasLevel3' => true,
            'dnsReceptive' => true,
            'level1Confidence' => 1.0,
            'level2Confidence' => 1.0,
            'crossReferenceMatch' => true
        ]
    ],
    [
        'name' => 'Scenario 2: Level 1 + DNS Verification (Evidence backed)',
        'params' => [
            'hasLevel1' => true,
            'hasLevel2' => false,
            'hasLevel3' => false,
            'dnsReceptive' => true,
            'level1Confidence' => 1.0,
            'level2Confidence' => 0.0,
            'crossReferenceMatch' => false
        ]
    ],
    [
        'name' => 'Scenario 3: Lower Confidence Level 1 Crawl',
        'params' => [
            'hasLevel1' => true,
            'hasLevel2' => false,
            'hasLevel3' => false,
            'dnsReceptive' => false,
            'level1Confidence' => 0.6,
            'level2Confidence' => 0.0,
            'crossReferenceMatch' => false
        ]
    ],
    [
        'name' => 'Scenario 4: Fully Unverified Prospect',
        'params' => [
            'hasLevel1' => false,
            'hasLevel2' => false,
            'hasLevel3' => false,
            'dnsReceptive' => false,
            'level1Confidence' => 0.0,
            'level2Confidence' => 0.0,
            'crossReferenceMatch' => false
        ]
    ]
];

foreach ($scenarios as $scen) {
    echo "\n--- {$scen['name']} ---\n";
    $p = $scen['params'];
    $result = TrustScorer::computeTrustScore(
        $p['hasLevel1'],
        $p['hasLevel2'],
        $p['hasLevel3'],
        $p['dnsReceptive'],
        $p['level1Confidence'],
        $p['level2Confidence'],
        $p['crossReferenceMatch']
    );

    echo "  Trust Score: {$result['trust_score']}/100\n";
    echo "  Verification Status: {$result['verification_status']}\n";
    echo "  Trust Tier: {$result['trust_tier']}\n";
    echo "  Is Gold Standard: " . ($result['is_gold_standard'] ? 'YES' : 'NO') . "\n";
    echo "  Breakdown: " . json_encode($result['breakdown']) . "\n";
}
