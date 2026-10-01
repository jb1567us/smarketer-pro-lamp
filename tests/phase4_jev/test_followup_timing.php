#!/usr/bin/env php
<?php
/**
 * Phase 4 JEV tests: FollowUpTimingAction cadence + JEV normalization.
 *
 * Zero DB, zero network:
 *   1. cadenceFallback() fixtures: skip reasons + delay ladder.
 *   2. decide() off-mode verdict shape (source='rules', confidence 0.0).
 *   3. normalize() of raw JEV answers: choice mapping, unknown choice ->
 *      skip, sanity clamp (JEV can never schedule into a skip state).
 *   4. execute() is a deliberate no-op returning false (like ClassifyReplyAction).
 *
 * Usage: php tests/phase4_jev/test_followup_timing.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require p4j_repo_root() . '/includes/autoload.php';
require __DIR__ . '/fake_pdo.php';

use App\Actions\FollowUpTimingAction;

$NOW = time();

function timingLead(array $over = []): array
{
    return array_merge([
        'id' => 1,
        'email' => 'amy@acme.com',
        'status' => 'Contacted',
        'lead_score' => 72,
        'contact_name' => 'Amy Owner',
        'company_name' => 'Acme Corp',
        'campaign_id' => 3,
    ], $over);
}

function activeCampaign(): array
{
    return [
        'id' => 3,
        'name' => 'Q4 push',
        'is_active' => 1,
        'status' => 'active',
        'daily_send_cap' => 50,
        'paused_reason' => null,
    ];
}

/** @return FollowUpTimingAction */
function timingAction(array $lead, ?array $campaign, int $touches, ?int $lastTouch): FollowUpTimingAction
{
    p4j_inject_tier(['enabled' => false, 'mode' => 'off'], null);
    $pdo = ScriptedPdo::make([
        ['match' => 'FROM leads WHERE id', 'rows' => [$lead]],
        ['match' => 'FROM campaigns WHERE id', 'rows' => $campaign === null ? [] : [$campaign]],
        ['match' => 'FROM email_logs', 'rows' => [[
            'n' => $touches,
            'last_ts' => $lastTouch,
        ]]],
    ]);
    return p4j_action(FollowUpTimingAction::class, $pdo);
}

function decide(array $lead, ?array $campaign, int $touches, ?int $lastTouch): array
{
    return timingAction($lead, $campaign, $touches, $lastTouch)->decide(1);
}

// --- 1. Skip reasons -------------------------------------------------------------
global $NOW;

$v = decide(timingLead(['status' => 'Converted']), activeCampaign(), 1, $NOW - 86400 * 3);
check('Converted skips', $v['next_touch'] === 'skip' && $v['delay_days'] === null);
check('Converted skip reason', str_contains((string)$v['skip_reason'], 'Converted'), var_export($v, true));

$v = decide(timingLead(['status' => 'Unqualified']), activeCampaign(), 1, $NOW - 86400 * 3);
check('Unqualified skips', $v['next_touch'] === 'skip');

$v = decide(timingLead(['status' => 'Needs Review']), activeCampaign(), 1, $NOW - 86400 * 3);
check('Needs Review skips (under review: never touch)', $v['next_touch'] === 'skip' && $v['delay_days'] === null);
check('Needs Review skip reason', str_contains((string)$v['skip_reason'], 'Needs Review'), var_export($v, true));

$v = decide(timingLead(), ['is_active' => 0, 'status' => 'paused', 'paused_reason' => 'owner halt'], 1, $NOW - 86400 * 3);
check('paused campaign skips', $v['next_touch'] === 'skip');
check('paused skip reason', str_contains((string)$v['skip_reason'], 'paused'), var_export($v, true));

$v = decide(timingLead(['campaign_id' => null]), activeCampaign(), 1, $NOW - 86400 * 3);
check('no campaign skips', $v['next_touch'] === 'skip');

$v = decide(timingLead(), activeCampaign(), 5, $NOW - 86400 * 3);
check('max touches skips', $v['next_touch'] === 'skip');
check('max touches reason', str_contains((string)$v['skip_reason'], 'max touches'), var_export($v, true));

// --- 2. Delay ladder ---------------------------------------------------------------
$v = decide(timingLead(), activeCampaign(), 0, null);
check('0 touches -> in_3_days', $v['next_touch'] === 'in_3_days' && $v['delay_days'] === 3, var_export($v, true));
$v = decide(timingLead(), activeCampaign(), 1, $NOW - 86400 * 2);
check('1 touch -> in_7_days', $v['next_touch'] === 'in_7_days' && $v['delay_days'] === 7);
$v = decide(timingLead(), activeCampaign(), 2, $NOW - 86400 * 9);
check('2 touches -> in_14_days', $v['next_touch'] === 'in_14_days' && $v['delay_days'] === 14);
$v = decide(timingLead(), activeCampaign(), 3, $NOW - 86400 * 20);
check('3 touches -> in_30_days', $v['next_touch'] === 'in_30_days' && $v['delay_days'] === 30);
$v = decide(timingLead(), activeCampaign(), 4, $NOW - 86400 * 40);
check('4 touches -> in_30_days', $v['next_touch'] === 'in_30_days' && $v['delay_days'] === 30);

// --- 3. Off-mode verdict shape ------------------------------------------------------
$v = decide(timingLead(), activeCampaign(), 1, $NOW - 86400 * 2);
check('off-mode source=rules', $v['source'] === 'rules');
check('off-mode confidence=0.0', $v['confidence'] === 0.0);
check('off-mode carries touches_so_far', $v['touches_so_far'] === 1);
check('off-mode days_since_last_touch computed', $v['days_since_last_touch'] === 2, var_export($v, true));
check('off-mode next_touch_at is a date', (bool)preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', (string)$v['next_touch_at']));
check('off-mode has latency_ms', is_int($v['latency_ms']));
check('off-mode note present', is_string($v['note'] ?? null) && $v['note'] !== '');

$v = decide(timingLead(), activeCampaign(), 0, null);
check('never touched -> days_since null', $v['days_since_last_touch'] === null);

// --- 4. normalize() of raw JEV answers (reflection) -----------------------------------
$ref = new ReflectionClass(FollowUpTimingAction::class);
$norm = $ref->getMethod('normalize');
$norm->setAccessible(true);
$inst = timingAction(timingLead(), activeCampaign(), 1, $NOW - 86400 * 2);
$state = $inst->loadState(1);

$jev = ['next_touch' => ['choice' => 'in_14_days', 'confidence' => 0.88]];
$n = $norm->invoke($inst, $jev, $state);
check('JEV choice in_14_days mapped', $n['next_touch'] === 'in_14_days' && $n['delay_days'] === 14,
    var_export($n, true));
check('JEV verdict source=jev', $n['source'] === 'jev');
check('JEV verdict confidence from answer', $n['confidence'] === 0.88);

$jevSkip = ['next_touch' => ['choice' => 'skip', 'confidence' => 0.7]];
$n = $norm->invoke($inst, $jevSkip, $state);
check('JEV skip mapped', $n['next_touch'] === 'skip' && $n['delay_days'] === null);

$jevBogus = ['next_touch' => ['choice' => 'in_99_days', 'confidence' => 0.9]];
$n = $norm->invoke($inst, $jevBogus, $state);
check('unknown JEV choice -> skip (safest)', $n['next_touch'] === 'skip', var_export($n, true));

// Sanity clamp: JEV schedules into a Converted lead -> clamped to skip.
$instConv = timingAction(timingLead(['status' => 'Converted']), activeCampaign(), 1, $NOW - 86400 * 2);
$stateConv = $instConv->loadState(1);
$jevSchedule = ['next_touch' => ['choice' => 'in_3_days', 'confidence' => 0.95]];
$n = $norm->invoke($instConv, $jevSchedule, $stateConv);
check('JEV schedule into terminal lead clamped to skip', $n['next_touch'] === 'skip');
check('clamp note explains', str_contains((string)$n['note'], 'clamped'), var_export($n, true));

// --- 5. extractChoice ------------------------------------------------------------------
$ext = $ref->getMethod('extractChoice');
$ext->setAccessible(true);
check('extractChoice from JEV answer', $ext->invoke($inst, $jev) === 'in_14_days');
check('extractChoice unknown -> skip', $ext->invoke($inst, $jevBogus) === 'skip');
check('extractChoice from legacy shape', $ext->invoke($inst, ['next_touch' => 'in_7_days']) === 'in_7_days');

// --- 6. loadState wiring -----------------------------------------------------------------
$s = timingAction(timingLead(), activeCampaign(), 2, $NOW - 86400 * 9)->loadState(1);
check('loadState lead_id', $s['lead_id'] === 1);
check('loadState campaign_active', $s['campaign_active'] === true);
check('loadState touches', $s['touches_so_far'] === 2);
check('loadState campaign_cap', $s['campaign_cap'] === 50);

// --- 7. execute() deliberate no-op ---------------------------------------------------------
check('execute() returns false (stateless)', timingAction(timingLead(), activeCampaign(), 1, null)->execute(1) === false);

// --- 8. Constants ------------------------------------------------------------------------------
check('TIMEOUT_S <= 8', FollowUpTimingAction::TIMEOUT_S <= 8);
check('DECISION stable name', FollowUpTimingAction::DECISION === 'follow_up_timing.decide');
check('MAX_TOUCHES sane', FollowUpTimingAction::MAX_TOUCHES === 5);

exit(p4j_summary('test_followup_timing.php'));
