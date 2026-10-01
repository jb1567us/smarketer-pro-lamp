#!/usr/bin/env php
<?php
/**
 * api/icp.php endpoint contract (subject 1/4, goal_67693fcbba4c).
 *
 * Static checks — the file is read, never executed (executing it would hit
 * auth + the real DB). Zero network, zero DB:
 *   1. Auth: Auth::requireApiAuth() runs before any input handling
 *      (REQUEST_METHOD read, php://input). GET needs auth only.
 *   2. CSRF: Auth::requireCsrf() runs after the GET branch exits and before
 *      the action dispatch, so every mutating action requires a token.
 *   3. Method allowlist: GET/POST/PUT; anything else -> 405.
 *   4. Action allowlist: exactly the seven documented actions; unknown ->
 *      400 'Unknown action.'.
 *   5. save_weights routes through IcpProfile::updateWeights() with
 *      buyerSet=true (sum-to-100 enforced server-side, manual edit marks
 *      buyer_locked).
 *   6. save_thresholds validates 0 <= review < qualify <= 100.
 *   7. Hygiene: no secrets read or written; weight_history capped at 25
 *      rows; threshold settings keys match IcpProfile::thresholds().
 *   8. set_dimension_enabled: the tech_stack toggle — validates `enabled`
 *      as a boolean, requires the full weights vector, and delegates to
 *      AdjustIcpWeightsAction::setDimensionEnabled(); ICP_TARGET_SPEC
 *      accepts the tech_stack target surface (tools list).
 *
 * Usage: php tests/icp_scoring/test_api_contract.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require_once ics_repo_root() . '/includes/autoload.php';

use App\Jev\DecisionTier;

ics_assert_default_mode_live('(start)');

$api = file_get_contents(ics_repo_root() . '/api/icp.php');
if ($api === false) {
    echo "  FATAL: api/icp.php unreadable\n";
    exit(1);
}

// --- 1. auth first ------------------------------------------------------------------
echo "1. auth:\n";
$pAuth = strpos($api, 'Auth::requireApiAuth()');
$pMethod = strpos($api, "\$method = \$_SERVER['REQUEST_METHOD']");
$pInput = strpos($api, 'php://input');
check('requireApiAuth() present', $pAuth !== false);
check('requireApiAuth() runs before REQUEST_METHOD is read',
    $pAuth !== false && $pMethod !== false && $pAuth < $pMethod);
check('requireApiAuth() runs before php://input is read',
    $pAuth !== false && $pInput !== false && $pAuth < $pInput);

// --- 2. CSRF on mutations only ----------------------------------------------------------
echo "2. CSRF:\n";
$pCsrf = strpos($api, 'Auth::requireCsrf()');
$pGetExit = strpos($api, 'exit;', strpos($api, "if (\$method === 'GET')"));
$pSwitch = strpos($api, 'switch ($action)');
check('requireCsrf() present', $pCsrf !== false);
check('requireCsrf() runs after the GET branch exits (GET needs auth only)',
    $pCsrf !== false && $pGetExit !== false && $pCsrf > $pGetExit);
check('requireCsrf() runs before the action dispatch',
    $pCsrf !== false && $pSwitch !== false && $pCsrf < $pSwitch);

// --- 3. method allowlist -----------------------------------------------------------------
echo "3. method allowlist:\n";
check('non-GET/POST/PUT -> 405',
    strpos($api, "if (\$method !== 'POST' && \$method !== 'PUT')") !== false
    && strpos($api, "jsonFail(405, 'Method not allowed')") !== false);

// --- 4. action allowlist --------------------------------------------------------------------
echo "4. action allowlist:\n";
foreach (['save_hypothesis', 'save_weights', 'add_exclusion', 'delete_exclusion',
          'unlock_dimension', 'save_thresholds', 'set_dimension_enabled'] as $action) {
    check("action '{$action}' is allowlisted",
        strpos($api, "case '{$action}':") !== false);
}
check("unknown action -> 400 'Unknown action.'",
    strpos($api, "jsonFail(400, 'Unknown action.')") !== false);

// --- 5. save_weights -> updateWeights with buyerSet=true ---------------------------------------
echo "5. save_weights:\n";
check('save_weights delegates to IcpProfile::updateWeights(..., buyerSet=true, user)',
    strpos($api, "IcpProfile::updateWeights(\$profileId, \$weights, \$reason, null, true, 'user')") !== false);

// --- 6. save_thresholds validation --------------------------------------------------------------
echo "6. save_thresholds:\n";
check('save_thresholds enforces 0 <= review < qualify <= 100',
    strpos($api, 'if ($qualify < 1 || $qualify > 100 || $review < 0 || $review >= $qualify)') !== false
    && strpos($api, 'Thresholds invalid: need 0 <= review < qualify <= 100.') !== false);
check('thresholds persist under the keys IcpProfile::thresholds() reads',
    strpos($api, "'icp_threshold_qualify'") !== false
    && strpos($api, "'icp_threshold_review'") !== false);

// --- 7. hygiene --------------------------------------------------------------------------------------
echo "7. hygiene:\n";
check('no secret reads (getenv/DB_PASS/password/api_key all absent)',
    stripos($api, 'getenv(') === false
    && stripos($api, 'DB_PASS') === false
    && stripos($api, 'password') === false
    && stripos($api, 'api_key') === false);
$secretLines = [];
foreach (explode("\n", $api) as $i => $line) {
    if (stripos($line, 'secret') !== false) {
        $secretLines[] = $i + 1;
    }
}
check('the only "secret" mention is the docblock contract line',
    $secretLines === [10], json_encode($secretLines));
check('weight_history capped at 25 rows in GET',
    strpos($api, 'ORDER BY created_at DESC LIMIT 25') !== false);
check('GET exposes dimensions, exclusions, thresholds, history, and labels',
    strpos($api, "'dimensions'") !== false
    && strpos($api, "'exclusions'") !== false
    && strpos($api, "'thresholds'") !== false
    && strpos($api, "'weight_history'") !== false
    && strpos($api, "'dimension_labels'") !== false);
check('CSRF token stripped from the payload before dispatch (transport-only)',
    strpos($api, "unset(\$data['csrf_token'])") !== false);

DecisionTier::resetForTests();
ics_assert_default_mode_live('(end)');
exit(ics_summary('test_api_contract.php'));

// --- 8. set_dimension_enabled: the tech_stack toggle --------------------------------------
echo "8. set_dimension_enabled (tech_stack toggle):\n";
check('toggle delegates to AdjustIcpWeightsAction::setDimensionEnabled()',
    strpos($api, '->setDimensionEnabled($profileId, $key, $enabled, $weights, $reason)') !== false);
check('toggle validates enabled as a strict boolean',
    strpos($api, 'FILTER_VALIDATE_BOOLEAN') !== false
    && strpos($api, "enabled must be true or false.") !== false);
check('toggle requires the full weights vector',
    strpos($api, "weights must be an object of dimension => weight.") !== false);
check('ICP_TARGET_SPEC accepts the tech_stack target surface (tools list)',
    strpos($api, "'tech_stack'      => ['tools' => 'string_list']") !== false);
