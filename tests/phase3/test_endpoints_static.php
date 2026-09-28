#!/usr/bin/env php
<?php
/**
 * Phase 3 test: static endpoint contract checks.
 *
 * Covers check categories (e) auth on new endpoints and (f) verdict-shape
 * reconciliation at the seam. These checks read file contents — they run
 * with zero DB and zero network:
 *   1. reply_lab.php calls \App\Auth::requirePageAuth() and validates CSRF
 *      on every POST action; no routing happens without the explicit
 *      "Apply routing" POST (two-step separation).
 *   2. api/ingest_reply.php calls \App\Auth::requireApiAuth() before any
 *      input handling; rejects non-POST with 405; enforces the documented
 *      field limits; NEVER auto-routes ('routed' => false hard-coded).
 *   3. The JSON contract in api/ingest_reply.php's docblock matches the
 *      actual ReplyIntake::normalizeVerdict() key set.
 *
 * Usage: php tests/phase3/test_endpoints_static.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require phase3_repo_root() . '/includes/autoload.php';

$repo = phase3_repo_root();
$lab  = file_get_contents($repo . '/reply_lab.php');
$api  = file_get_contents($repo . '/api/ingest_reply.php');

// --- (e) reply_lab.php auth -------------------------------------------------
check('reply_lab.php calls requirePageAuth()',
    strpos($lab, "\\App\\Auth::requirePageAuth()") !== false);
check('reply_lab.php requirePageAuth runs before any request handling',
    strpos($lab, 'requirePageAuth()') < strpos($lab, '$_SERVER'));
check('reply_lab.php validates CSRF on POST',
    strpos($lab, "\\App\\Auth::validateCsrf(") !== false);
check('reply_lab.php embeds csrf_token in every form',
    substr_count($lab, 'name="csrf_token"') >= 3);

// --- (e) api/ingest_reply.php auth -------------------------------------------
check('ingest_reply.php calls requireApiAuth()',
    strpos($api, "\\App\\Auth::requireApiAuth()") !== false);
check('ingest_reply.php requireApiAuth runs before reading php://input',
    strpos($api, 'requireApiAuth()') < strpos($api, 'php://input'));
check('ingest_reply.php rejects non-POST with 405',
    strpos($api, '405') !== false && strpos($api, "REQUEST_METHOD") !== false);
check('ingest_reply.php 401 contract documented',
    strpos($api, '401 {"success":false,"error":"Authentication required"}') !== false);

// --- Input limits match the documented contract ------------------------------
foreach (['subject' => '500', 'body' => '20000', 'thread_context' => '20000'] as $field => $max) {
    check("ingest_reply.php enforces {$field} max {$max}",
        strpos($api, "'Field \"{$field}\" exceeds the {$max}-character limit'") !== false);
}
check('ingest_reply.php validates from as email',
    strpos($api, 'FILTER_VALIDATE_EMAIL') !== false);

// --- Routing is NEVER auto-applied -------------------------------------------
check('ingest_reply.php never routes: routed=false hard-coded',
    preg_match("/'routed'\s*=>\s*false/", $api) === 1);
check('ingest_reply.php never calls ReplyIntake::applyRouting',
    strpos($api, 'applyRouting') === false);
check('reply_lab.php routes only on explicit action=route POST',
    strpos($lab, "\$action === 'route'") !== false
    && strpos($lab, 'applyRouting') !== false);

// --- Both endpoints log every request ----------------------------------------
check('ingest_reply.php logs every request via ReplyIntake::logIntake',
    substr_count($api, 'ReplyIntake::logIntake') >= 2); // errors + success
check('reply_lab.php logs classify + route steps',
    substr_count($lab, 'ReplyIntake::logIntake') >= 2);

// --- (f) JSON contract matches ReplyIntake::normalizeVerdict() ----------------
$shape = \App\ReplyIntake::normalizeVerdict([
    'intent' => 'positive', 'needs_human' => true, 'urgency' => 8,
    'confidence' => 0.82, 'source' => 'jev', 'latency_ms' => 1234,
    'note' => null,
]);
$contractKeys = ['intent', 'needs_human', 'urgency', 'confidence', 'source', 'latency_ms', 'note'];
check('normalizeVerdict() key set is stable',
    array_keys($shape) === $contractKeys, 'keys: ' . implode(',', array_keys($shape)));
// The docblock documents the six core fields. NOTE (reported, not a test
// failure): the docblock example predates the preserved 'note' key and does
// not show it — the real JSON now carries "note" (null for JEV verdicts,
// string for heuristic ones). See README.md §"known gaps".
foreach (['intent', 'needs_human', 'urgency', 'confidence', 'source', 'latency_ms'] as $k) {
    check("ingest_reply.php docblock documents verdict field '{$k}'",
        strpos($api, '"' . $k . '"') !== false || strpos($api, "'{$k}'") !== false);
}
check('ingest_reply.php docblock shows urgency as int (1-10 contract)',
    strpos($api, '"urgency": 8,') !== false
    && strpos($api, '1-10 int from ClassifyReplyAction') !== false);

// reply_lab renders the same six display fields as the verdict shape.
foreach (['intent', 'urgency', 'needs_human', 'confidence', 'source', 'latency_ms'] as $k) {
    check("reply_lab.php verdict display covers '{$k}'",
        strpos($lab, "'{$k}'") !== false);
}

exit(phase3_summary('test_endpoints_static.php'));
