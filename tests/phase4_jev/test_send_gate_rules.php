#!/usr/bin/env php
<?php
/**
 * Phase 4 JEV tests: SendGateAction rule-based compliance gates.
 *
 * Verifies the layer-1 gates (suppression, placeholder, sender identity,
 * CASL, verification cache, unsubscribe guarantee, quota) with scripted
 * PDO doubles — zero DB, zero network:
 *   1. Clean lead passes every gate (allowed, source='rules', confidence 0.0).
 *   2. Each gate failure produces the expected blocker and a denial.
 *   3. Missing lead / invalid email fail closed.
 *   4. Compliance blockers short-circuit: JEV is never consulted (the
 *      provider call count stays 0 even in shadow mode with a key).
 *   5. Live mode + no usable JEV verdict (no API key) fails closed.
 *
 * Usage: php tests/phase4_jev/test_send_gate_rules.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require p4j_repo_root() . '/includes/autoload.php';
require __DIR__ . '/fake_pdo.php';

use App\Actions\SendGateAction;

$CLEAN_SETTINGS = [
    'company_legal_name' => 'Acme Inc',
    'physical_address'   => '123 Main St, Austin TX',
    'app_base_url'       => 'https://example.test',
];

function cleanLead(array $over = []): array
{
    return array_merge([
        'id' => 1,
        'email' => 'amy@acme.com',
        'status' => 'Qualified',
        'country_code' => 'US',
        'consent_status' => 'express',
        'verification_status' => 'valid',
        'company_name' => 'Acme',
        'campaign_id' => null,
    ], $over);
}

/**
 * @param array $settings setting_key => setting_value
 */
function gatePdo(array $lead, array $settings, array $extra = []): ScriptedPdo
{
    global $CLEAN_SETTINGS;
    $settings += $CLEAN_SETTINGS;
    $scripts = [
        ['match' => 'FROM leads WHERE id', 'rows' => $lead === [] ? [] : [$lead]],
        ['match' => 'FROM suppression_list WHERE email =', 'rows' => []],
        ['match' => 'FROM settings WHERE setting_key',
         'rowFn' => fn(array $p) => isset($settings[$p[0]])
             ? [['setting_value' => $settings[$p[0]]]] : []],
        // DbThrottleStore settings preload (key-pair fetch).
        ['match' => 'SELECT setting_key, setting_value FROM settings',
         'keypair' => ['throttle_provider_daily_cap' => '0']],
    ];
    // Test-specific overrides ($extra) take precedence over the base
    // matchers — first match wins.
    return ScriptedPdo::make(array_merge($extra, $scripts));
}

function runGate(array $lead, array $settings = [], array $extra = [], array $ctx = []): array
{
    p4j_inject_tier(['enabled' => false, 'mode' => 'off'], null); // deterministic off
    $pdo = gatePdo($lead, $settings, $extra);
    /** @var SendGateAction $action */
    $action = p4j_action(SendGateAction::class, $pdo);
    return [$action->gate(1, $ctx), $pdo];
}

// --- 1. Clean lead passes every gate ---------------------------------------
[$v, $pdo] = runGate(cleanLead());
check('clean lead allowed', $v['allowed'] === true, var_export($v, true));
check('clean lead no blockers', $v['blockers'] === []);
check('clean lead source=rules', $v['source'] === 'rules');
check('clean lead confidence=0.0', $v['confidence'] === 0.0);
check('verdict has latency_ms', isset($v['latency_ms']) && is_int($v['latency_ms']));

// --- 2. Suppression gate ---------------------------------------------------
[$v] = runGate(cleanLead(), [], [
    ['match' => 'FROM suppression_list WHERE email =', 'rows' => [['1' => 1]]],
]);
check('suppressed address blocked', $v['allowed'] === false);
check('suppressed blocker named', in_array('suppressed', $v['blockers'], true));
check('blocked verdict carries note', is_string($v['note'] ?? null) && $v['note'] !== '');

// --- 3. Placeholder address gate -------------------------------------------
[$v] = runGate(cleanLead(['email' => 'pending_9f2a@placeholder.com']));
check('placeholder address blocked', $v['allowed'] === false);
check('placeholder blocker named', in_array('placeholder-address', $v['blockers'], true));

// --- 4. Invalid email: fail closed, first -------------------------------
[$v] = runGate(cleanLead(['email' => 'not-an-email']));
check('invalid email blocked', $v['allowed'] === false);
check('invalid-email blocker first', ($v['blockers'][0] ?? '') === 'invalid-email');

// --- 5. Missing lead: fail closed ------------------------------------------
[$v] = runGate([]);
check('missing lead blocked', $v['allowed'] === false);
check('lead-not-found blocker', in_array('lead-not-found', $v['blockers'], true));

// --- 5b. Blocked lead statuses: terminal / under-review never mailed --------
[$v] = runGate(cleanLead(['status' => 'Needs Review']));
check('Needs Review lead blocked', $v['allowed'] === false);
check('blocked-lead-status blocker named', in_array('blocked-lead-status', $v['blockers'], true));
[$v] = runGate(cleanLead(['status' => 'Converted']));
check('Converted lead blocked', $v['allowed'] === false && in_array('blocked-lead-status', $v['blockers'], true));
[$v] = runGate(cleanLead(['status' => 'Unqualified']));
check('Unqualified lead blocked', $v['allowed'] === false && in_array('blocked-lead-status', $v['blockers'], true));

// --- 6. Sender identity gate -----------------------------------------------
[$v] = runGate(cleanLead(), ['company_legal_name' => '']);
check('missing legal name blocked', $v['allowed'] === false);
check('sender-identity-missing blocker', in_array('sender-identity-missing', $v['blockers'], true));
[$v] = runGate(cleanLead(), ['physical_address' => '']);
check('missing postal address blocked', $v['allowed'] === false);

// --- 7. CASL gate ----------------------------------------------------------
$caslSettings = ['verification_required' => '0'];
[$v] = runGate(cleanLead(['country_code' => 'CA', 'consent_status' => 'implied']), $caslSettings);
check('CA without express consent blocked', $v['allowed'] === false);
check('casl-ca blocker named', in_array('casl-ca-no-express-consent', $v['blockers'], true));
[$v] = runGate(cleanLead(['country_code' => 'CA', 'consent_status' => 'express']), $caslSettings);
check('CA with express consent allowed', $v['allowed'] === true, var_export($v, true));
[$v] = runGate(cleanLead(['country_code' => '', 'consent_status' => 'implied']), $caslSettings);
check('unknown country without express consent blocked', $v['allowed'] === false);
check('casl-unknown-country blocker', in_array('casl-unknown-country-no-express-consent', $v['blockers'], true));
[$v] = runGate(cleanLead(['country_code' => 'CA', 'consent_status' => 'implied']),
    $caslSettings + ['compliance_casl_ca_block' => '0']);
check('CASL master toggle off allows', $v['allowed'] === true);

// --- 8. Verification cache gate --------------------------------------------
$verSettings = ['verification_required' => '1', 'verification_api_key' => 'mv-test-key'];
[$v] = runGate(cleanLead(['verification_status' => 'invalid']), $verSettings);
check('cached invalid verdict blocked', $v['allowed'] === false);
check('verification-invalid blocker', in_array('verification-invalid', $v['blockers'], true));
[$v] = runGate(cleanLead(['verification_status' => 'risky']), $verSettings);
check('risky blocked by default risky_action', $v['allowed'] === false);
[$v] = runGate(cleanLead(['verification_status' => 'unknown']), $verSettings + ['verification_strict' => '1']);
check('unknown blocked under strict', $v['allowed'] === false);
[$v] = runGate(cleanLead(['verification_status' => 'unknown']), $verSettings);
check('unknown passes when not strict (fail-open default)', $v['allowed'] === true, var_export($v, true));
[$v] = runGate(cleanLead(['verification_status' => 'invalid']), ['verification_required' => '0']);
check('verification gate disabled -> invalid cache ignored', $v['allowed'] === true);

// --- 9. Unsubscribe guarantee ----------------------------------------------
[$v] = runGate(cleanLead(), ['app_base_url' => '']);
check('missing app_base_url blocked', $v['allowed'] === false);
check('unsubscribe-unconfigured blocker', in_array('unsubscribe-unconfigured', $v['blockers'], true));

// --- 10. Quota gate (campaign daily cap hit) --------------------------------
$quotaExtra = [
    ['match' => 'information_schema.COLUMNS', 'rows' => [[1]]],   // columns exist
    ['match' => 'SELECT daily_send_cap FROM campaigns', 'rows' => [['daily_send_cap' => 5]]],
    ['match' => 'FROM email_logs WHERE campaign_id', 'rows' => [[5]]], // 5 sent today
];
[$v, $pdo] = runGate(cleanLead(), [], $quotaExtra, ['campaign_id' => 7, 'provider' => 'resend']);
check('cap hit blocked', $v['allowed'] === false);
check('quota blocker carries code', ($v['blockers'][0] ?? '') === 'quota:campaign_daily_cap',
    var_export($v['blockers'], true));

$quotaExtraOk = [
    ['match' => 'information_schema.COLUMNS', 'rows' => [[1]]],
    ['match' => 'SELECT daily_send_cap FROM campaigns', 'rows' => [['daily_send_cap' => 5]]],
    ['match' => 'FROM email_logs WHERE campaign_id', 'rows' => [[3]]], // 3 < 5
];
[$v] = runGate(cleanLead(), [], $quotaExtraOk, ['campaign_id' => 7, 'provider' => 'resend']);
check('under cap allowed', $v['allowed'] === true, var_export($v, true));

// --- 11. execute() fail-closed contract -------------------------------------
p4j_inject_tier(['enabled' => false, 'mode' => 'off'], null);
$pdo = gatePdo(cleanLead(), []);
$action = p4j_action(SendGateAction::class, $pdo);
check('execute() returns true for clean lead', $action->execute(1) === true);
$pdo = gatePdo(cleanLead(), [], [
    ['match' => 'FROM suppression_list WHERE email =', 'rows' => [['1' => 1]]],
]);
$action = p4j_action(SendGateAction::class, $pdo);
check('execute() returns false for suppressed lead', $action->execute(1) === false);

// --- 12. Constants: hard timeout <= 8s ---------------------------------------
check('TIMEOUT_S <= 8', SendGateAction::TIMEOUT_S <= 8, 'got ' . SendGateAction::TIMEOUT_S);
check('DECISION stable name', SendGateAction::DECISION === 'send_gate.final_decision');

// --- 13. JEV never consulted when compliance blocks --------------------------
// Proof: the shadow log is written only inside DecisionTier::decide(). A
// blocked send must leave no shadow-log line — the gates short-circuit
// before any JEV verdict could approve.
$shadowFile = sys_get_temp_dir() . '/p4j_sendgate_' . bin2hex(random_bytes(4)) . '.jsonl';
@unlink($shadowFile);
p4j_inject_tier(['enabled' => true, 'mode' => 'shadow', 'shadow_log' => $shadowFile], null);
$pdo = gatePdo(cleanLead(), [], [
    ['match' => 'FROM suppression_list WHERE email =', 'rows' => [['1' => 1]]],
]);
$action = p4j_action(SendGateAction::class, $pdo);
$v = $action->gate(1);
check('suppressed denied in shadow mode', $v['allowed'] === false);
check('denial is compliance-sourced', $v['source'] === 'rules'
    && in_array('suppressed', $v['blockers'], true));
check('no shadow log written for compliance-blocked send', !is_file($shadowFile));
@unlink($shadowFile);

// --- 14. Live mode + no usable JEV verdict fails closed ----------------------
// No API key -> providerWithTimeout fails -> decide() returns the rules
// fallback (would allow) -> gate() must still DENY in live mode.
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], null);
$pdo = gatePdo(cleanLead(), []);
$action = p4j_action(SendGateAction::class, $pdo);
$v = $action->gate(1);
check('live + no JEV verdict fails closed', $v['allowed'] === false);
check('fail-closed blocker named', in_array('no-jev-verdict', $v['blockers'], true),
    var_export($v, true));
check('execute() fail-closed in live without JEV', $action->execute(1) === false);

exit(p4j_summary('test_send_gate_rules.php'));
