#!/usr/bin/env php
<?php
/**
 * Phase 4 test: payload caps and rate limiting on the ingestion seam.
 *
 * Covers:
 *  (a) rateCheck: first RATE_LIMIT requests allowed; the next is rejected
 *      with a positive retry_after; window expiry re-allows; per-IP
 *      isolation; fail-open when the state dir is unwritable.
 *  (b) static endpoint checks: 413 on oversized Content-Length AND on
 *      oversized post-read body; 429 on rate-limit breach with Retry-After;
 *      MAX_BODY_BYTES = 256 KiB; the never-auto-route guarantee still holds.
 *
 * Zero DB, zero network.
 *
 * Usage: php tests/phase4/test_ingest_limits.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require phase4_repo_root() . '/includes/autoload.php';

use App\ApiAuth;

// --- (a) rate limiter -------------------------------------------------------------
check('MAX_BODY_BYTES is 256 KiB', ApiAuth::MAX_BODY_BYTES === 262144);
check('RATE_LIMIT is 60/min', ApiAuth::RATE_LIMIT === 60 && ApiAuth::RATE_WINDOW_SECONDS === 60);

$tmp = phase4_tmpdir('phase4_rate');
try {
    $allowed = 0;
    for ($i = 0; $i < ApiAuth::RATE_LIMIT; $i++) {
        $r = ApiAuth::rateCheck('10.0.0.1', $tmp);
        if ($r['allowed']) {
            $allowed++;
        }
    }
    check('first 60 requests allowed', $allowed === ApiAuth::RATE_LIMIT, "allowed={$allowed}");
    $r = ApiAuth::rateCheck('10.0.0.1', $tmp);
    check('61st request rejected', !$r['allowed']);
    check('rejection carries positive retry_after', $r['retry_after'] > 0);
    check('retry_after within window', $r['retry_after'] <= ApiAuth::RATE_WINDOW_SECONDS + 1);

    // Other IPs unaffected.
    $r2 = ApiAuth::rateCheck('10.0.0.2', $tmp);
    check('per-IP isolation', $r2['allowed']);

    // Window expiry: backdate the state file, then the IP is allowed again.
    $file = $tmp . '/ingest_rate.json';
    $state = json_decode((string)file_get_contents($file), true);
    $old = time() - ApiAuth::RATE_WINDOW_SECONDS - 5;
    $state['10.0.0.1'] = array_fill(0, ApiAuth::RATE_LIMIT, $old);
    file_put_contents($file, json_encode($state));
    $r3 = ApiAuth::rateCheck('10.0.0.1', $tmp);
    check('window expiry re-allows', $r3['allowed']);

    // Fail-open on unwritable dir: automation must not hard-block.
    $r4 = ApiAuth::rateCheck('10.0.0.1', '/proc/phase4_nowhere');
    check('fail-open when state dir unwritable', $r4['allowed']);
} finally {
    phase4_rmdir($tmp);
}

// --- (b) endpoint wiring (static) ---------------------------------------------------
$api = file_get_contents(phase4_repo_root() . '/api/ingest_reply.php');

check('rejects oversized declared Content-Length with 413',
    strpos($api, 'CONTENT_LENGTH') !== false
    && strpos($api, 'MAX_BODY_BYTES') !== false
    && substr_count($api, '413') >= 2);
check('re-checks body size after read (chunked bodies)',
    preg_match('/file_get_contents\(\'php:\/\/input\'\)[\s\S]{0,400}MAX_BODY_BYTES/', $api) === 1);
check('413 error message is "Payload too large"',
    strpos($api, "'Payload too large'") !== false);
check('rate limit enforced with 429 + Retry-After',
    strpos($api, 'rateCheck(') !== false
    && strpos($api, '429') !== false
    && strpos($api, 'Retry-After') !== false);
check('429 logged for ops', preg_match("/http_status.*429|429.*logIntake/s", $api) === 1
    || (strpos($api, "'http_status' => 429") !== false));

// Guarantees carried over from Phase 3.
check('never auto-routes: routed=false hard-coded',
    preg_match("/'routed'\\s*=>\\s*false/", $api) === 1);
check('never calls ReplyIntake::applyRouting', strpos($api, 'applyRouting') === false);
check('set_time_limit(90) still guards total execution',
    strpos($api, 'set_time_limit(90)') !== false);

exit(phase4_summary('test_ingest_limits.php'));
