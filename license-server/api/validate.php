<?php
/**
 * License server — POST api/validate.php
 *
 * Body (JSON): { "key": "SMP-XXXX-XXXX-XXXX", "domain": "example.com" }
 *
 * Responses (always 200 unless rate-limited / malformed):
 *   { "valid": true,  "reason": "ok", "domains": [...], "max_domains": N }
 *   { "valid": false, "reason": "unknown_key" }            (also covers wrong key)
 *   { "valid": false, "reason": "revoked" }
 *   { "valid": false, "reason": "domain_not_registered",
 *     "domains": [...], "max_domains": N }
 *
 * validate.php NEVER auto-registers a domain — registration is an explicit
 * step (api/register.php), so a typo'd domain can't burn a buyer's slot.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';

$pdo = ls_db();
ls_rate_limit($pdo, ls_client_ip(), 60, 60);

$body = ls_read_json_body();
$key = (string)($body['key'] ?? '');
$domain = ls_normalize_domain((string)($body['domain'] ?? ''));

if ($key === '' || $domain === '') {
    ls_json_response(['valid' => false, 'reason' => 'missing_fields'], 400);
}

$lic = ls_find_license($pdo, $key);
if ($lic === null) {
    // Deliberately vague: unknown key and wrong key are indistinguishable.
    ls_json_response(['valid' => false, 'reason' => 'unknown_key']);
}

if ($lic['status'] === 'revoked') {
    ls_json_response(['valid' => false, 'reason' => 'revoked']);
}

$domains = ls_license_domains($pdo, (int)$lic['id']);
if (!in_array($domain, $domains, true)) {
    ls_json_response([
        'valid' => false,
        'reason' => 'domain_not_registered',
        'domains' => $domains,
        'max_domains' => (int)$lic['max_domains'],
    ]);
}

// Touch last_validated so the seller can see the install is alive.
$stmt = $pdo->prepare(
    'UPDATE license_domains SET last_validated = NOW() WHERE license_id = ? AND domain = ?'
);
$stmt->execute([(int)$lic['id'], $domain]);

ls_json_response([
    'valid' => true,
    'reason' => 'ok',
    'domains' => $domains,
    'max_domains' => (int)$lic['max_domains'],
]);
