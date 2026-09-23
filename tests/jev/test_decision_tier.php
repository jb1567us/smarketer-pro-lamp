<?php
// Test 3: DecisionTier routing — off/shadow/live, fallback, confidence
// escalation, shadow logging — with zero network and zero database.
// Config and provider are injected via reflection (resetForTests exists
// precisely for this).
require_once __DIR__ . '/../../includes/autoload.php';

$fail = 0;
function check(string $name, bool $cond): void {
    global $fail;
    echo ($cond ? "PASS" : "FAIL") . " $name\n";
    if (!$cond) $fail++;
}

$ref = new ReflectionClass('App\\Jev\\DecisionTier');
$cfgProp = $ref->getProperty('configCache'); $cfgProp->setAccessible(true);
$provProp = $ref->getProperty('provider'); $provProp->setAccessible(true);
$attProp = $ref->getProperty('providerAttempted'); $attProp->setAccessible(true);

function injectConfig(array $cfg): void {
    global $cfgProp, $attProp;
    \App\Jev\DecisionTier::resetForTests();
    $cfgProp->setValue(null, $cfg + [
        'enabled' => true, 'mode' => 'shadow', 'min_confidence' => 0.65,
        'model' => null, 'base_url' => null, 'timeout' => 30, 'shadow_log' => null,
    ]);
    $attProp->setValue(null, true); // never hit the network
}
function injectProvider($fake): void {
    global $provProp;
    $provProp->setValue(null, $fake);
}

// Fake provider: scripted answers or scripted failure.
class FakeJev extends \App\Jev\JevProvider {
    public static array $script = [];   // queue of answers-arrays or Throwables
    public static int $calls = 0;
    public function __construct() {}    // skip key requirement
    public function systemOne($state, array $questions): array {
        self::$calls++;
        $next = array_shift(self::$script);
        if ($next instanceof \Throwable) throw $next;
        return $next;
    }
}

$legacy = ['verdict' => 'qualified', 'score' => 80];
$legacyFn = fn() => $legacy;
$q = ['qualified' => ['type' => 'noul', 'prompt' => 'Qualified?']];

// --- 1. OFF mode: legacy returned byte-identical, provider never called ---
injectConfig(['enabled' => false, 'mode' => 'off']);
FakeJev::$script = []; FakeJev::$calls = 0;
injectProvider(new FakeJev());
$out = \App\Jev\DecisionTier::decide('t.off', 'state', $q, $legacyFn);
check('off: returns legacy', $out === $legacy);
check('off: provider never called', FakeJev::$calls === 0);
check('off: mode() reports off', \App\Jev\DecisionTier::mode() === 'off');

// --- 2. SHADOW mode: returns legacy, writes shadow log with agreement ---
$shadowLog = sys_get_temp_dir() . '/jev_shadow_test.jsonl';
@unlink($shadowLog);
injectConfig(['enabled' => true, 'mode' => 'shadow', 'shadow_log' => $shadowLog]);
FakeJev::$script = [[ 'qualified' => ['noul' => 0.9, 'confidence' => 0.9] ]];
FakeJev::$calls = 0;
injectProvider(new FakeJev());
$out = \App\Jev\DecisionTier::decide(
    't.shadow', 'state', $q, $legacyFn,
    fn($mixed) => is_array($mixed) && isset($mixed['qualified']) ? $mixed['qualified']['noul'] : $mixed['verdict'],
    fn($jv, $lv) => true
);
check('shadow: returns legacy', $out === $legacy);
check('shadow: provider called once', FakeJev::$calls === 1);
$lines = array_filter(explode("\n", @file_get_contents($shadowLog) ?: ''));
check('shadow: one log line', count($lines) === 1);
$rec = json_decode(reset($lines), true) ?: [];
check('shadow: agree recorded', ($rec['agree'] ?? null) === true);
check('shadow: decision name + latency', ($rec['decision'] ?? '') === 't.shadow' && isset($rec['latency_ms']));

// --- 3. SHADOW disagreement recorded ---
@unlink($shadowLog);
injectConfig(['enabled' => true, 'mode' => 'shadow', 'shadow_log' => $shadowLog]);
FakeJev::$script = [[ 'qualified' => ['noul' => 0.05, 'confidence' => 0.95] ]];
injectProvider(new FakeJev());
\App\Jev\DecisionTier::decide('t.shadow2', 'state', $q, $legacyFn, fn($m) => 'x', fn($jv, $lv) => false);
$rec = json_decode(trim(@file_get_contents($shadowLog) ?: ''), true) ?: [];
check('shadow: disagreement recorded', ($rec['agree'] ?? null) === false);

// --- 4. LIVE high confidence: returns answers ---
injectConfig(['enabled' => true, 'mode' => 'live']);
FakeJev::$script = [[ 'qualified' => ['noul' => 0.9, 'confidence' => 0.9] ]];
injectProvider(new FakeJev());
$out = \App\Jev\DecisionTier::decide('t.live', 'state', $q, $legacyFn);
check('live: returns answers on high confidence', ($out['qualified']['noul'] ?? null) === 0.9);

// --- 5. LIVE low confidence: escalates to legacy ---
injectConfig(['enabled' => true, 'mode' => 'live', 'min_confidence' => 0.65]);
FakeJev::$script = [[ 'qualified' => ['noul' => 0.4, 'confidence' => 0.1] ]];
injectProvider(new FakeJev());
$out = \App\Jev\DecisionTier::decide('t.live2', 'state', $q, $legacyFn);
check('live: low confidence escalates to legacy', $out === $legacy);

// --- 6. Provider failure (any mode): legacy, no exception escapes ---
injectConfig(['enabled' => true, 'mode' => 'live']);
FakeJev::$script = [new \App\Jev\JevException('network down')];
injectProvider(new FakeJev());
$out = \App\Jev\DecisionTier::decide('t.fail', 'state', $q, $legacyFn);
check('live: provider failure falls back to legacy', $out === $legacy);

// --- 7. Missing provider (null) in shadow: legacy, no crash ---
\App\Jev\DecisionTier::resetForTests();
injectConfig(['enabled' => true, 'mode' => 'shadow']);
$provProp->setValue(null, null); // getProvider() not consulted; simulate null
$ref2 = new ReflectionMethod('App\\Jev\\DecisionTier', 'decide');
// decide() calls self::getProvider() when mode != off — with providerAttempted
// already true and provider null, it returns null -> legacy. Verify:
$out = \App\Jev\DecisionTier::decide('t.noprov', 'state', $q, $legacyFn);
check('shadow: null provider falls back to legacy', $out === $legacy);

// --- 8. Answer helpers ---
check('minConfidence picks lowest', \App\Jev\DecisionTier::minConfidence([
    'a' => ['noul' => 1, 'confidence' => 0.9], 'b' => ['noul' => 1, 'confidence' => 0.2]]) === 0.2);
check('minConfidence defaults 1.0', \App\Jev\DecisionTier::minConfidence(['a' => ['noul' => 0.5]]) === 1.0);
check('answersToBool threshold', \App\Jev\DecisionTier::answersToBool(['k' => ['noul' => 0.7]], 'k') === true);
check('answersToBool below threshold', \App\Jev\DecisionTier::answersToBool(['k' => ['noul' => 0.3]], 'k') === false);
check('answersToChoice', \App\Jev\DecisionTier::answersToChoice(['k' => ['choice' => 'b']], 'k') === 'b');
check('answersToChoice missing -> null', \App\Jev\DecisionTier::answersToChoice([], 'k') === null);
check('answersToScore', \App\Jev\DecisionTier::answersToScore(['k' => ['score' => 0.77]], 'k') === 0.77);

@unlink($shadowLog);
\App\Jev\DecisionTier::resetForTests();
exit($fail === 0 ? 0 : 1);
