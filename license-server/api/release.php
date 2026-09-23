<?php
/**
 * License server — POST api/release.php
 *
 * Buyer self-service: frees one of the key's own domain slots (e.g. after
 * moving hosts). No seller involvement needed.
 *
 * Body (JSON): { "key": "SMP-XXXX-XXXX-XXXX", "domain": "example.com" }
 *
 * Responses (idempotent — releasing a non-registered domain is still ok):
 *   200 { "valid": true, "reason": "released", "domains": [...], "max_domains": N }
 *   200 { "valid": false, "reason": "unknown_key" }
 *
 * A revoked key can still release domains (the buyer may be migrating away);
 * revocation only blocks validate/register.
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

$stmt = $pdo->prepare('DELETE FROM license_domains WHERE license_id = ? AND domain = ?');
$stmt->execute([(int)$lic['id'], $domain]);

ls_json_response([
    'valid' => true,
    'reason' => 'released',
    'domains' => ls_license_domains($pdo, (int)$lic['id']),
    'max_domains' => (int)$lic['max_domains'],
]);
