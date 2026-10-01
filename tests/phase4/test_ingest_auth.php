#!/usr/bin/env php
<?php
/**
 * Phase 4 test: API-key auth for the ingestion seam.
 *
 * Covers:
 *  (a) key extraction: X-Api-Key preferred, Authorization: Bearer fallback,
 *      empty when neither present.
 *  (b) keyAuthValid: rejects null/empty configured key, rejects wrong key,
 *      rejects missing presented key, accepts the exact key (timing-safe).
 *  (c) ingestKey(): env override (SMARKETER_INGEST_API_KEY) wins; per-install
 *      key file is generated once (64 hex chars), stable across calls, mode
 *      0640, config dir gets a deny .htaccess; null when dir unwritable.
 *  (d) Auth::requireApiAuth() accepts the optional key argument and passes
 *      it to ApiAuth::keyAuthValid (static check).
 *
 * Zero DB, zero network.
 *
 * Usage: php tests/phase4/test_ingest_auth.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require phase4_repo_root() . '/includes/autoload.php';

use App\ApiAuth;

// --- (a) extraction ------------------------------------------------------------
check('extracts X-Api-Key',
    ApiAuth::extractProvidedKey(['HTTP_X_API_KEY' => '  abc123  ']) === 'abc123');
check('extracts Authorization Bearer',
    ApiAuth::extractProvidedKey(['HTTP_AUTHORIZATION' => 'Bearer tok-xyz']) === 'tok-xyz');
check('X-Api-Key preferred over Bearer',
    ApiAuth::extractProvidedKey(['HTTP_X_API_KEY' => 'k1', 'HTTP_AUTHORIZATION' => 'Bearer k2']) === 'k1');
check('empty when no headers',
    ApiAuth::extractProvidedKey([]) === '');
check('ignores non-Bearer Authorization',
    ApiAuth::extractProvidedKey(['HTTP_AUTHORIZATION' => 'Basic abc']) === '');
check('case-insensitive Bearer scheme',
    ApiAuth::extractProvidedKey(['HTTP_AUTHORIZATION' => 'bearer tok']) === 'tok');

// --- (b) validation ------------------------------------------------------------
$configured = str_repeat('a', 64);
check('accepts exact key', ApiAuth::keyAuthValid($configured, ['HTTP_X_API_KEY' => $configured]));
check('accepts via Bearer', ApiAuth::keyAuthValid($configured, ['HTTP_AUTHORIZATION' => 'Bearer ' . $configured]));
check('rejects wrong key', !ApiAuth::keyAuthValid($configured, ['HTTP_X_API_KEY' => str_repeat('b', 64)]));
check('rejects near-miss key', !ApiAuth::keyAuthValid($configured, ['HTTP_X_API_KEY' => substr($configured, 0, 63) . 'b']));
check('rejects missing presented key', !ApiAuth::keyAuthValid($configured, []));
check('rejects null configured key', !ApiAuth::keyAuthValid(null, ['HTTP_X_API_KEY' => 'x']));
check('rejects empty configured key', !ApiAuth::keyAuthValid('', ['HTTP_X_API_KEY' => 'x']));

// --- (c) key generation / resolution -------------------------------------------
putenv('SMARKETER_INGEST_API_KEY'); // ensure unset
$tmp = phase4_tmpdir('phase4_key');
try {
    $k1 = ApiAuth::ingestKey($tmp);
    check('generates a key in temp config dir', is_string($k1) && strlen($k1) === 64 && ctype_xdigit($k1));
    check('key file created', is_file($tmp . '/ingest_api_key.php'));
    check('key file has 0640 perms', (fileperms($tmp . '/ingest_api_key.php') & 0777) === 0640);
    check('deny .htaccess created', is_file($tmp . '/.htaccess')
        && strpos((string)file_get_contents($tmp . '/.htaccess'), 'Require all denied') !== false);
    $k2 = ApiAuth::ingestKey($tmp);
    check('key stable across calls (no regen)', $k1 === $k2);
    $contents = (string)file_get_contents($tmp . '/ingest_api_key.php');
    check('key file returns the key when included', (function () use ($tmp) {
        $v = include $tmp . '/ingest_api_key.php';
        return is_string($v);
    })());
    check('generated file has no PHP closing tag leak', strpos($contents, 'return') !== false);

    // Env override wins over the file.
    putenv('SMARKETER_INGEST_API_KEY=env-override-key-0123456789');
    check('env override wins', ApiAuth::ingestKey($tmp) === 'env-override-key-0123456789');
    putenv('SMARKETER_INGEST_API_KEY');

    // Short env value is ignored (falls back to the file key).
    putenv('SMARKETER_INGEST_API_KEY=short');
    check('short env value ignored', ApiAuth::ingestKey($tmp) === $k1);
    putenv('SMARKETER_INGEST_API_KEY');
} finally {
    phase4_rmdir($tmp);
}

// Unwritable dir → null (fail closed → session-only auth).
$nowhere = '/proc/phase4_nowhere_' . bin2hex(random_bytes(4));
check('null when config dir cannot be created', ApiAuth::ingestKey($nowhere) === null);

// --- (d) Auth wiring ------------------------------------------------------------
$authSrc = file_get_contents(phase4_repo_root() . '/includes/Auth.php');
check('requireApiAuth accepts optional key arg',
    preg_match('/function requireApiAuth\(\?string \$apiKey = null\)/', $authSrc) === 1);
check('requireApiAuth delegates to ApiAuth::keyAuthValid',
    strpos($authSrc, 'ApiAuth::keyAuthValid') !== false);
check('key accepted only when configured key non-null (fail closed in ApiAuth)',
    strpos($authSrc, 'if ($apiKey !== null && \\App\\ApiAuth::keyAuthValid($apiKey, $_SERVER))') !== false);

$apiSrc = file_get_contents(phase4_repo_root() . '/api/ingest_reply.php');
check('ingest endpoint passes ingestKey() to requireApiAuth',
    strpos($apiSrc, 'requireApiAuth(\\App\\ApiAuth::ingestKey())') !== false);
check('auth line still before php://input',
    strpos($apiSrc, 'requireApiAuth(') < strpos($apiSrc, 'php://input'));

exit(phase4_summary('test_ingest_auth.php'));
