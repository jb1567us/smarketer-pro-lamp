#!/usr/bin/env php
<?php
/**
 * Phase 4 test: idempotent ingestion (duplicate POSTs must not double-process).
 *
 * Covers:
 *  (a) dedupeKey: message_id normalizes "<…>" / case variants to one key;
 *      without a message_id the content hash is stable and sender-insensitive
 *      to case; different bodies give different keys.
 *  (b) dedupePut/dedupeGet round-trip in a temp dir; unknown key → null;
 *      store is capped at DEDUPE_CAP entries (oldest pruned first).
 *  (c) api/ingest_reply.php checks the dedupe store BEFORE classifying and
 *      returns duplicate:true with the cached verdict (static check).
 *
 * Zero DB, zero network.
 *
 * Usage: php tests/phase4/test_ingest_idempotency.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require phase4_repo_root() . '/includes/autoload.php';

use App\ApiAuth;

// --- (a) dedupe keys ------------------------------------------------------------
$k1 = ApiAuth::dedupeKey('<ABC123@mail.example>', 'Sub', 'Body', 'a@x.com');
$k2 = ApiAuth::dedupeKey('abc123@mail.example', 'Sub', 'Body', 'a@x.com');
check('message_id <> and case variants normalize to one key', $k1 === $k2);
check('message_id key has mid: prefix', str_starts_with($k1, 'mid:'));

$h1 = ApiAuth::dedupeKey('', 'Sub', 'Body', 'A@x.com');
$h2 = ApiAuth::dedupeKey('', 'Sub', 'Body', 'a@x.com');
check('content hash key has hash: prefix', str_starts_with($h1, 'hash:'));
check('content hash stable, sender case-insensitive', $h1 === $h2);
$h3 = ApiAuth::dedupeKey('', 'Sub', 'Different body', 'a@x.com');
check('different body → different key', $h1 !== $h3);
check('message_id and content keys never collide', $k1 !== $h1);

// --- (b) store round-trip + cap ---------------------------------------------------
$tmp = phase4_tmpdir('phase4_dedupe');
try {
    $verdict = ['intent' => 'positive', 'needs_human' => true, 'urgency' => 8,
        'confidence' => 0.82, 'source' => 'heuristic', 'latency_ms' => 3, 'note' => 'x'];
    check('unknown key → null', ApiAuth::dedupeGet('mid:nope', $tmp) === null);
    ApiAuth::dedupePut($k1, $verdict, $tmp);
    $got = ApiAuth::dedupeGet($k1, $tmp);
    check('round-trip returns verdict', is_array($got) && $got['intent'] === 'positive'
        && $got['urgency'] === 8 && $got['note'] === 'x');

    // Overwrite keeps newest.
    $v2 = $verdict; $v2['intent'] = 'negative';
    ApiAuth::dedupePut($k1, $v2, $tmp);
    check('re-put overwrites', ApiAuth::dedupeGet($k1, $tmp)['intent'] === 'negative');

    // Cap: insert more than DEDUPE_CAP keys; the earliest must be pruned.
    $first = ApiAuth::dedupeKey('<first@mail>', 's', 'b', '');
    ApiAuth::dedupePut($first, $verdict, $tmp);
    for ($i = 0; $i < ApiAuth::DEDUPE_CAP + 50; $i++) {
        ApiAuth::dedupePut(ApiAuth::dedupeKey("<m{$i}@mail>", 's', 'b', ''), $verdict, $tmp);
    }
    $store = json_decode((string)file_get_contents($tmp . '/ingest_dedupe.json'), true);
    check('store capped at DEDUPE_CAP', is_array($store) && count($store) === ApiAuth::DEDUPE_CAP,
        'count=' . (is_array($store) ? count($store) : 'n/a'));
    check('oldest entry pruned', !isset($store[$first]));
    check('latest entry present',
        ApiAuth::dedupeGet(ApiAuth::dedupeKey('<m' . (ApiAuth::DEDUPE_CAP + 49) . '@mail>', 's', 'b', ''), $tmp) !== null);

    // Unwritable dir → best-effort null, never throws.
    check('dedupeGet on missing dir → null',
        ApiAuth::dedupeGet('mid:x', '/proc/phase4_nowhere') === null);
    ApiAuth::dedupePut('mid:x', $verdict, '/proc/phase4_nowhere');
    check('dedupePut on unwritable dir does not throw', true);
} finally {
    phase4_rmdir($tmp);
}

// --- (c) endpoint wiring (static) -------------------------------------------------
$api = file_get_contents(phase4_repo_root() . '/api/ingest_reply.php');
check('endpoint builds a dedupe key', strpos($api, 'ApiAuth::dedupeKey(') !== false);
$dedupePos = strpos($api, 'ApiAuth::dedupeGet(');
$classifyPos = strpos($api, 'ReplyIntake::classifyReply(');
check('dedupe check runs before classification',
    $dedupePos !== false && $classifyPos !== false && $dedupePos < $classifyPos);
check('duplicate short-circuits with exit', preg_match("/duplicate.*true/s", $api) === 1
    && substr_count($api, 'exit;') >= 3);
check('duplicate response keeps routed=false',
    preg_match("/'duplicate'\\s*=>\\s*true[\\s\\S]{0,200}'routed'\\s*=>\\s*false/", $api) === 1);
check('verdict cached after classification', strpos($api, 'ApiAuth::dedupePut(') !== false);
check('message_id validated (500-char cap)',
    strpos($api, "'Field \"message_id\" exceeds the 500-character limit'") !== false);

exit(phase4_summary('test_ingest_idempotency.php'));
