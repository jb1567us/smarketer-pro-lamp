#!/usr/bin/env php
<?php
/**
 * Phase 4 JEV tests: off/shadow/live contract for the three new decision
 * points, at the DecisionTier level (shadow logging is DecisionTier's job;
 * the actions' timeout-override path builds its own provider, so a scripted
 * provider is exercised here without the override).
 *
 * Zero network, zero DB:
 *   1. off: legacy returned byte-identical, provider never called.
 *   2. shadow: legacy returned; one shadow-log line per decision name with
 *      jev_answers + agreement fields.
 *   3. live: JEV answers returned; low confidence escalates to legacy.
 *   4. JEV error in live: legacy returned (actions treat this as
 *      no-verdict; the send gate fails closed on it).
 *   5. api/send_email.php wires the gate shadow-first (static checks).
 *
 * Usage: php tests/phase4_jev/test_decision_modes.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require p4j_repo_root() . '/includes/autoload.php';

use App\Jev\DecisionTier;

/** Scripted provider: answers queues or Throwables, like tests/jev. */
class P4JFakeJev extends \App\Jev\JevProvider
{
    public static array $script = [];
    public static int $calls = 0;
    public function __construct() {}
    public function systemOne($state, array $questions): array
    {
        self::$calls++;
        $next = array_shift(self::$script);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }
}

$Q = ['q' => ['type' => 'noul', 'instructions' => 'ok?']];
$legacy = ['allowed' => true, 'source' => 'rules'];
$legacyFn = fn() => $legacy;
$extract = fn($mixed) => is_array($mixed) && isset($mixed['allowed']) && !is_array($mixed['allowed'])
    ? (bool)$mixed['allowed']
    : (float)($mixed['q']['noul'] ?? 0) >= 0.5;

// --- 1. OFF: legacy byte-identical, provider never called --------------------
foreach (['send_gate.final_decision', 'enrich_sufficiency.decide', 'follow_up_timing.decide'] as $name) {
    p4j_inject_tier(['enabled' => false, 'mode' => 'off'], new P4JFakeJev());
    P4JFakeJev::$script = [];
    P4JFakeJev::$calls = 0;
    $out = DecisionTier::decide($name, [], $Q, $legacyFn, $extract, fn($j, $l) => $j === $l);
    check("off: {$name} returns legacy byte-identical", $out === $legacy);
    check("off: {$name} provider never called", P4JFakeJev::$calls === 0);
}

// --- 2. SHADOW: legacy returned + one log line per decision name ------------
$shadowLog = sys_get_temp_dir() . '/p4j_modes_' . bin2hex(random_bytes(4)) . '.jsonl';
@unlink($shadowLog);
$names = ['send_gate.final_decision', 'enrich_sufficiency.decide', 'follow_up_timing.decide'];
foreach ($names as $name) {
    p4j_inject_tier(['enabled' => true, 'mode' => 'shadow', 'shadow_log' => $shadowLog], new P4JFakeJev());
    P4JFakeJev::$script = [['q' => ['noul' => 0.9, 'confidence' => 0.9]]];
    P4JFakeJev::$calls = 0;
    $out = DecisionTier::decide($name, [], $Q, $legacyFn, $extract, fn($j, $l) => $j === $l);
    check("shadow: {$name} returns legacy", $out === $legacy);
    check("shadow: {$name} provider called once", P4JFakeJev::$calls === 1);
}
$lines = array_values(array_filter(explode("\n", (string)@file_get_contents($shadowLog))));
check('shadow: one log line per decision', count($lines) === count($names), 'got ' . count($lines));
$loggedNames = [];
foreach ($lines as $line) {
    $rec = json_decode($line, true);
    $loggedNames[] = $rec['decision'] ?? null;
    check("shadow log has jev_answers for {$rec['decision']}", isset($rec['jev_answers']));
    check("shadow log has agree for {$rec['decision']}", array_key_exists('agree', $rec));
    check("shadow log has latency_ms for {$rec['decision']}", isset($rec['latency_ms']));
    check("shadow log has min_confidence for {$rec['decision']}", isset($rec['min_confidence']));
}
sort($loggedNames);
$sorted = $names;
sort($sorted);
check('shadow log carries all three decision names', $loggedNames === $sorted, var_export($loggedNames, true));
@unlink($shadowLog);

// --- 3. LIVE: JEV answers returned; low confidence escalates ----------------
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], new P4JFakeJev());
P4JFakeJev::$script = [['q' => ['noul' => 0.95, 'confidence' => 0.9]]];
$out = DecisionTier::decide('send_gate.final_decision', [], $Q, $legacyFn, $extract, fn($j, $l) => $j === $l);
check('live: JEV answers returned', is_array($out) && isset($out['q']['noul']) && $out['q']['noul'] === 0.95);

p4j_inject_tier(['enabled' => true, 'mode' => 'live'], new P4JFakeJev());
P4JFakeJev::$script = [['q' => ['noul' => 0.95, 'confidence' => 0.1]]]; // below 0.65
$out = DecisionTier::decide('send_gate.final_decision', [], $Q, $legacyFn, $extract, fn($j, $l) => $j === $l);
check('live: low confidence escalates to legacy', $out === $legacy);

// --- 4. LIVE + JEV error: legacy returned (actions see no JEV verdict) ------
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], new P4JFakeJev());
P4JFakeJev::$script = [new RuntimeException('boom')];
$out = DecisionTier::decide('send_gate.final_decision', [], $Q, $legacyFn, $extract, fn($j, $l) => $j === $l);
check('live: JEV error returns legacy', $out === $legacy);

// --- 5. api/send_email.php wires the gate shadow-first (static) ------------
$repo = p4j_repo_root();
$api = file_get_contents($repo . '/api/send_email.php');
check('send_email.php instantiates SendGateAction',
    strpos($api, 'new \\App\\Actions\\SendGateAction(') !== false);
check('send_email.php calls gate() before sending',
    strpos($api, 'SendGateAction($pdo') !== false
    && strpos($api, '->gate($leadId') !== false
    && strpos($api, '->gate($leadId') < strpos($api, '\\App\\EmailSender::send('));
check('gate block is live-mode-only',
    strpos($api, "DecisionTier::mode() === 'live'") !== false);
check('gate never throws out of the endpoint',
    preg_match('/\\/\\/ Phase 4: send gate.*?catch \\(\\\\Throwable/s', $api) === 1);
check('live-mode denial returns 403 with blockers',
    strpos($api, 'http_response_code(403)') !== false
    && strpos($api, "'Send gate denied: '") !== false);
check('gate context carries subject/body/provider/campaign',
    strpos($api, "'subject' => \$subject") !== false
    && strpos($api, "'campaign_id' => \$campaignId") !== false);

exit(p4j_summary('test_decision_modes.php'));
