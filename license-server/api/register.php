<?php
/**
 * License server — POST api/register.php
 *
 * Body (JSON): { "key": "SMP-XXXX-XXXX-XXXX", "domain": "example.com" }
 *
 * Registers a domain slot for a key. Idempotent: registering an already
 * registered domain returns ok. Enforces max_domains.
 *
 * Responses:
 *   200 { "valid": true,  "reason": "registered" | "already_registered", ... }
 *   403 { "valid": false, "reason": "revoked" }
 *   409 { "valid": false, "reason": "domain_slots_exhausted",
 *         "domains": [...], "max_domains": N }
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';

$pdo = ls_db();
ls_rate_limit($pdo, ls_client_ip(), 30, 60);

$body = ls_read_json_body();
$key = (string)($body['key'] ?? '');
$domain = ls_normalize_domain((string)($body['domain'] ?? ''));

if ($key === '' || $domain === '') {
    ls_json_response(['valid' => false, 'reason' => 'missing_fields'], 400);
}

$lic = ls_find_license($pdo, $key);
if ($lic === null) {
    ls_json_response(['valid' => false, 'reason' => 'unknown_key']);
}

if ($lic['status'] === 'revoked') {
    ls_json_response(['valid' => false, 'reason' => 'revoked'], 403);
}

$licenseId = (int)$lic['id'];
$domains = ls_license_domains($pdo, $licenseId);

if (in_array($domain, $domains, true)) {
    $stmt = $pdo->prepare(
        'UPDATE license_domains SET last_validated = NOW() WHERE license_id = ? AND domain = ?'
    );
    $stmt->execute([$licenseId, $domain]);
    ls_json_response([
        'valid' => true,
        'reason' => 'already_registered',
        'domains' => $domains,
        'max_domains' => (int)$lic['max_domains'],
    ]);
}

if (count($domains) >= (int)$lic['max_domains']) {
    ls_json_response([
        'valid' => false,
        'reason' => 'domain_slots_exhausted',
        'domains' => $domains,
        'max_domains' => (int)$lic['max_domains'],
    ], 409);
}

$stmt = $pdo->prepare(
    'INSERT INTO license_domains (license_id, domain, last_validated) VALUES (?, ?, NOW())'
);
$stmt->execute([$licenseId, $domain]);

ls_json_response([
    'valid' => true,
    'reason' => 'registered',
    'domains' => ls_license_domains($pdo, $licenseId),
    'max_domains' => (int)$lic['max_domains'],
]);
