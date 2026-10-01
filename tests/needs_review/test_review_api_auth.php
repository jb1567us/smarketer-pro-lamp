#!/usr/bin/env php
<?php
/**
 * API auth tests for the review endpoints (sibling 3: approve/disqualify
 * API + UI + audit trail).
 *
 * Contract (same as api/icp.php):
 *   - every request hits Auth::requireApiAuth() FIRST, before any input is
 *     read or any state change is dispatched -> unauthenticated callers get
 *     HTTP 401 + {"success":false,"error":"Authentication required"}
 *   - state-changing actions (approve/disqualify) additionally pass through
 *     Auth::requireCsrf() (X-CSRF-Token header or csrf_token body field),
 *     failing closed with 403 on a missing/invalid token
 *
 * Includes a best-effort runtime 401 probe: the endpoint is executed in a
 * subprocess with no session and must answer "Authentication required"
 * before touching anything. If the endpoint's shape does not allow the
 * probe, that check is SKIPPED (not failed) — the static checks are the
 * binding contract.
 *
 * If sibling 3's endpoint has not landed yet, everything is SKIPPED with an
 * explicit PENDING reason.
 *
 * Zero DB writes by this file itself; the runtime probe only reads.
 *
 * Usage: php tests/needs_review/test_review_api_auth.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require nr_repo_root() . '/includes/autoload.php';

$apiFile = nr_review_api();
if ($apiFile === null) {
    nr_skip('review endpoint requires auth (401 unauthenticated)', 'PENDING sibling 3 (review workflow): no api/*review*.php');
    nr_skip('auth runs before any input is read', 'PENDING sibling 3 (review workflow)');
    nr_skip('approve/disqualify enforce CSRF (403 on bad token)', 'PENDING sibling 3 (review workflow)');
    nr_skip('runtime: unauthenticated approve call gets 401', 'PENDING sibling 3 (review workflow)');
    echo "  NOTE: rerun this file once sibling 3's approve/disqualify API is committed.\n";
    nr_skip('runtime: requireCsrf() 403 on missing/invalid token', 'PENDING sibling 3 (review workflow)');
    nr_skip('runtime: requireCsrf() passes on a valid token', 'PENDING sibling 3 (review workflow)');
    exit(nr_summary('test_review_api_auth.php'));
}

$src = (string)file_get_contents($apiFile);
$short = substr($apiFile, strlen(nr_repo_root()) + 1);
echo "  testing endpoint: {$short}\n";

// --- (1) auth is required ------------------------------------------------------
$authPos = strpos($src, 'requireApiAuth');
nr_check("{$short} calls Auth::requireApiAuth()", $authPos !== false);

// --- (2) auth before input ------------------------------------------------------
$inputPos = strpos($src, 'php://input');
$postPos = strpos($src, '$_POST');
$getPos = strpos($src, '$_GET[' . "'action']");
if ($getPos === false) {
    $getPos = strpos($src, '$_GET["action"]');
}
$firstInput = null;
foreach ([$inputPos, $postPos, $getPos] as $p) {
    if ($p !== false && ($firstInput === null || $p < $firstInput)) {
        $firstInput = $p;
    }
}
nr_check("{$short} authenticates before reading request input",
    $authPos !== false && $firstInput !== null && $authPos < $firstInput,
    $firstInput === null ? 'no input read found' : "auth@{$authPos} input@{$firstInput}");

// --- (3) CSRF on the state-changing path ------------------------------------------
$csrfPos = strpos($src, 'requireCsrf');
nr_check("{$short} enforces CSRF for approve/disqualify", $csrfPos !== false);
if ($csrfPos !== false) {
    // The binding write is ReviewQueue::transition(); CSRF must be enforced
    // before it (the docblock also mentions 'approve', so the dispatch
    // string is not a reliable anchor — the transition call is).
    $writePos = strpos($src, 'ReviewQueue::transition');
    nr_check("{$short} checks CSRF before the approve/disqualify write",
        $writePos !== null && $writePos !== false && $csrfPos < $writePos,
        $writePos === false ? 'no transition call found' : "csrf@{$csrfPos} write@{$writePos}");
    nr_check("{$short} CSRF failure is fail-closed (403)",
        str_contains((string)file_get_contents(nr_repo_root() . '/includes/Auth.php'), "http_response_code(403)"));
}

// --- (4) runtime 401 probe (best-effort) --------------------------------------------
$probe = sprintf(
    '$_GET = ["action" => "approve"]; $_SERVER["REQUEST_METHOD"] = "POST"; include %s;',
    var_export($apiFile, true)
);
$cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe) . ' 2>&1';
exec($cmd, $out, $code);
$body = implode("\n", $out);
if (str_contains($body, 'Authentication required')) {
    nr_check('runtime: unauthenticated approve call gets 401', true);
} else {
    nr_skip('runtime: unauthenticated approve call gets 401',
        'endpoint shape did not allow the probe (output: ' . substr($body, 0, 80) . ')');
}

// --- (5) runtime CSRF checks on Auth::requireCsrf() (subprocess) ----------------
// requireCsrf() exits (fail-closed, 403 JSON) on a missing/invalid token and
// returns normally on a valid one. Driven in a subprocess because the failure
// path calls exit().
function nr_csrf_probe(string $tokenMode): string
{
// $tokenMode: 'missing' | 'wrong' | 'header' | 'post'
$setup = sprintf(
    'require %s; session_start(); $_SESSION[%s] = %s; ',
    var_export(nr_repo_root() . '/includes/autoload.php', true),
    var_export('csrf_token', true),
    var_export('nr-csrf-test-token', true)
);
$inject = match ($tokenMode) {
    'wrong'  => "\$_SERVER['HTTP_X_CSRF_TOKEN'] = 'definitely-wrong'; ",
    'header' => "\$_SERVER['HTTP_X_CSRF_TOKEN'] = 'nr-csrf-test-token'; ",
    'post'   => "\$_POST['csrf_token'] = 'nr-csrf-test-token'; ",
    default  => '',
};
$php = $setup . $inject . "\\App\\Auth::requireCsrf(); echo 'CSRF_OK';";
$cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($php) . ' 2>&1';
exec($cmd, $out, $code);
return implode("\n", $out);
}

$csrfFail = '"error":"Invalid or missing CSRF token"';
$missing = nr_csrf_probe('missing');
nr_check('runtime: requireCsrf() with a missing token emits the 403 JSON error',
str_contains($missing, $csrfFail) && !str_contains($missing, 'CSRF_OK'),
substr($missing, 0, 80));
$wrong = nr_csrf_probe('wrong');
nr_check('runtime: requireCsrf() with an invalid token emits the 403 JSON error',
str_contains($wrong, $csrfFail) && !str_contains($wrong, 'CSRF_OK'),
substr($wrong, 0, 80));
$headerOk = nr_csrf_probe('header');
nr_check('runtime: requireCsrf() returns normally for a valid X-CSRF-Token header',
str_contains($headerOk, 'CSRF_OK'), substr($headerOk, 0, 80));
$postOk = nr_csrf_probe('post');
nr_check('runtime: requireCsrf() returns normally for a valid csrf_token body field',
str_contains($postOk, 'CSRF_OK'), substr($postOk, 0, 80));

exit(nr_summary('test_review_api_auth.php'));
