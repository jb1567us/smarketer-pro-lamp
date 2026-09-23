<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();

require_once __DIR__ . '/includes/autoload.php';

use App\Verification\DNSChecker;

echo "=== TESTING DNS CHECKER ===\n";

$testDomains = [
    'google.com',
    'github.com',
    'nonexistent-domain-xyz-12345.com'
];

foreach ($testDomains as $domain) {
    echo "\nChecking domain: {$domain}...\n";
    $result = DNSChecker::checkReceptivity($domain);
    echo "  Is Receptive: " . ($result['is_receptive'] ? 'YES' : 'NO') . "\n";
    echo "  Has MX Record: " . ($result['has_mx'] ? 'YES' : 'NO') . "\n";
    echo "  MX Records Found: " . implode(', ', $result['mx_records']) . "\n";
    echo "  Has A Record Fallback: " . ($result['has_a'] ? 'YES' : 'NO') . "\n";
    echo "  Error: " . ($result['error'] ?? 'None') . "\n";
}
