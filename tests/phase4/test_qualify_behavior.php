<?php
// Phase 4 subject 4: qualification decision-point — behavioral checks through
// DecisionTier::decide with a scripted (fake) Jev provider. Verifies the
// exact rewritten questions travel to the provider; a clear-fit evaluation
// (noul 0.9) lands qualified >= 0.5; an ambiguous evaluation (noul 0.35,
// low confidence) lands unqualified but with the confidence surfaced —
// never a forced false. The off/shadow/live contract is re-verified.
// Zero network, zero database.
require_once __DIR__ . '/common.php';
require_once phase4_repo_root() . '/includes/autoload.php';

use App\Actions\QualifyLeadAction;
use App\Jev\DecisionTier;

$ref = new ReflectionClass(DecisionTier::class);
$cfgProp = $ref->getProperty('configCache');
$cfgProp->setAccessible(true);
$provProp = $ref->getProperty('provider');
$provProp->setAccessible(true);
$attProp = $ref->getProperty('providerAttempted');
$attProp->setAccessible(true);

function injectCfg(string $mode, float $minConf): void
{
    global $cfgProp, $attProp;
    DecisionTier::resetForTests();
    $cfgProp->setValue(null, [
        'enabled' => $mode !== 'off', 'mode' => $mode, 'min_confidence' => $minConf,
        'model' => null, 'base_url' => null, 'timeout' => 30, 'shadow_log' => null,
    ]);
    $attProp->setValue(null, true); // never hit the network
}

/** Fake provider: scripted answers; records the exact state+questions sent. */
class QualifyFakeJev extends \App\Jev\JevProvider
{
    /** @var array<int,array> queue of scripted answers arrays */
    public static array $script = [];
    public static ?array $lastCall = null;
    public function __construct() {} // skip key requirement
    public function systemOne($state, array $questions): array
    {
        self::$lastCall = ['state' => $state, 'questions' => $questions];
        return array_shift(self::$script);
    }
}

function injectFake(): void
{
    global $provProp;
    QualifyFakeJev::$script = [];
    QualifyFakeJev::$lastCall = null;
    $provProp->setValue(null, new QualifyFakeJev());
}

function toQualification($answers, array $fitLevels): array
{
    $r = new ReflectionClass(QualifyLeadAction::class);
    $m = $r->getMethod('answersToQualification');
    $m->setAccessible(true);
    return $m->invoke($r->newInstanceWithoutConstructor(), $answers, $fitLevels);
}

$built = QualifyLeadAction::buildDecisionQuestions();
$fitLevels = $built['fitLevels'];
$questions = $built['questions'];
$legacy = ['qualified' => false, 'score' => 20, 'reason' => 'legacy'];
$legacyFn = fn() => $legacy;
// Mirrors the extract/agree closures in QualifyLeadAction::decideQualification.
$extract = function ($a) use ($fitLevels) {
    if (is_array($a) && isset($a['qualified']) && is_array($a['qualified']) && isset($a['qualified']['noul'])) {
        $position = (float)($a['score']['score'] ?? 0);
        return [(float)$a['qualified']['noul'] >= 0.5, \App\Jev\JevProvider::scoreToPercent($position, count($fitLevels))];
    }
    return [(bool)($a['qualified'] ?? false), (float)($a['score'] ?? 0)];
};
$agree = fn($jv, $lv) => $jv[0] === $lv[0] && abs($jv[1] - $lv[1]) <= 15;

$clearFit = [
    'persona' => 'B2B ICP Specialist',
    'goal' => 'Determine if this company matches a High-Value Prospect profile.',
    'lead_context' => "Company: Acme Logistics\nWebsite: acme.example\nContact: Jane Ops\n" .
        "Size: 250 employees (mid-market). Industry: logistics & supply chain.\n" .
        "Tech stack: Shopify + EDI integrations adjacent to our tool.",
];
$ambiguous = [
    'persona' => 'B2B ICP Specialist',
    'goal' => 'Determine if this company matches a High-Value Prospect profile.',
    'lead_context' => "Company: Unknown Holdings\nWebsite: unknown.example\nContact: Unknown\n",
];

// --- 1. LIVE + clear-fit evaluation: qualified >= 0.5 -----------------------
injectCfg('live', 0.65);
injectFake();
QualifyFakeJev::$script = [[
    'qualified' => ['noul' => 0.9, 'confidence' => 0.85],
    'score' => ['score' => 3.2, 'confidence' => 0.8],
]];
$answers = DecisionTier::decide('qualify_lead.decide_qualification', $clearFit, $questions, $legacyFn, $extract, $agree, null);
check('live: clear-fit uses Jev answers, not the legacy fallback',
    is_array($answers) && isset($answers['qualified']['noul']));
$q = toQualification($answers, $fitLevels);
check('live: clear-fit scores qualified (noul 0.9 >= 0.5)', ($q['qualified'] ?? false) === true);
check('live: clear-fit score maps to 80/100', ($q['score'] ?? null) === 80);
check('live: clear-fit verdict sourced from jev', ($q['source'] ?? '') === 'jev');
check('live: legacy fallback was NOT returned', $answers !== $legacy);

// The exact question the provider received enumerates the ICP must-haves.
$sentInstr = (string)(QualifyFakeJev::$lastCall['questions']['qualified']['instructions'] ?? '');
check('provider receives the enumerated must-haves',
    stripos($sentInstr, 'Company size') !== false
    && stripos($sentInstr, 'Industry') !== false
    && stripos($sentInstr, 'Tech stack') !== false);
check('provider receives no thin-evidence false-default',
    stripos($sentInstr, 'answer false when evidence is thin') === false);

// --- 2. LIVE + ambiguous evaluation: unqualified, confidence surfaced -------
injectCfg('live', 0.2); // allow low-confidence answers through (min 0.25)
injectFake();
QualifyFakeJev::$script = [[
    'qualified' => ['noul' => 0.35, 'confidence' => 0.3],
    'score' => ['score' => 1.0, 'confidence' => 0.25],
]];
$answers = DecisionTier::decide('qualify_lead.decide_qualification', $ambiguous, $questions, $legacyFn, $extract, $agree, null);
$q = toQualification($answers, $fitLevels);
check('live: ambiguous is not qualified (noul 0.35 < 0.5)', ($q['qualified'] ?? true) === false);
check('live: ambiguous surfaces its confidence, not a forced false',
    stripos($q['reason'] ?? '', 'p=0.35') !== false);
check('live: ambiguous score maps to 25/100', ($q['score'] ?? null) === 25);

// --- 3. OFF contract: legacy result returned byte-identical ------------------
injectCfg('off', 0.65);
$answers = DecisionTier::decide('qualify_lead.decide_qualification', $clearFit, $questions, $legacyFn, $extract, $agree, null);
check('off: legacy result returned unchanged', $answers === $legacy);
check('off: mode() reports off', DecisionTier::mode() === 'off');

// --- 4. SHADOW contract: legacy returned, verdicts logged -------------------
$shadowLog = phase4_tmpdir('qualify_shadow') . '/jev_shadow.jsonl';
injectCfg('shadow', 0.65);
$cfgProp->setValue(null, array_merge($cfgProp->getValue(), ['shadow_log' => $shadowLog]));
injectFake();
QualifyFakeJev::$script = [[
    'qualified' => ['noul' => 0.9, 'confidence' => 0.85],
    'score' => ['score' => 3.2, 'confidence' => 0.8],
]];
$answers = DecisionTier::decide('qualify_lead.decide_qualification', $clearFit, $questions, $legacyFn, $extract, $agree, null);
check('shadow: legacy result returned (zero behavior change)', $answers === $legacy);
$log = @file_get_contents($shadowLog);
check('shadow: verdict logged for evaluation', $log !== false && stripos($log, 'qualify_lead.decide_qualification') !== false);
phase4_rmdir(dirname($shadowLog));

exit(phase4_summary('test_qualify_behavior.php'));
