#!/usr/bin/env php
<?php
/**
 * License server — CLI key issuer.
 *
 * Usage:
 *   php admin/make_key.php --label="BHW buyer #42" [--email=buyer@example.com] [--max-domains=1]
 *
 * Prints the plaintext key ONCE (send it to the buyer, then forget it).
 * Only the sha256 fingerprint + password_hash are stored — the plaintext
 * can never be recovered from the database.
 *
 * Run from the license-server directory on any machine with PHP + PDO MySQL
 * (your laptop is fine; it only needs MySQL access to the license database).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';

$opts = getopt('', ['label:', 'email::', 'max-domains::']);
$label = (string)($opts['label'] ?? '');
$email = isset($opts['email']) ? (string)$opts['email'] : null;
$maxDomains = max(1, min(100, (int)($opts['max-domains'] ?? 1)));

if ($label === '') {
    fwrite(STDERR, "Usage: php admin/make_key.php --label=\"Buyer name/order\" [--email=buyer@example.com] [--max-domains=1]\n");
    exit(2);
}
if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid --email value.\n");
    exit(2);
}

$pdo = ls_db();
$key = ls_generate_key();
$norm = ls_normalize_key($key);

$stmt = $pdo->prepare(
    'INSERT INTO licenses (key_fp, key_hash, label, buyer_email, max_domains, status) ' .
    'VALUES (?, ?, ?, ?, ?, \'active\')'
);
$stmt->execute([ls_key_fingerprint($norm), password_hash($norm, PASSWORD_DEFAULT), $label, $email ?: null, $maxDomains]);

echo "License key (send to buyer, shown once):\n";
echo "  {$key}\n";
echo "Label: {$label}\n";
echo 'Buyer email: ' . ($email ?: '(none)') . "\n";
echo "Max domains: {$maxDomains}\n";
echo "Fingerprint: " . ls_key_fingerprint($norm) . "\n";
