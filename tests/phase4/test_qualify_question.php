<?php
// Phase 4 subject 4: qualification decision-point bias fix — question-text
// checks. Zero network, zero database: asserts the rewritten `qualified`
// noul question enumerates the app's real ICP must-haves, applies a
// preponderance standard (noul >= 0.5), and no longer defaults thin
// evidence to false.
require_once __DIR__ . '/common.php';
require_once phase4_repo_root() . '/includes/autoload.php';

use App\Actions\QualifyLeadAction;

$built = QualifyLeadAction::buildDecisionQuestions();
$fitLevels = $built['fitLevels'];
$questions = $built['questions'];
$instr = (string)($questions['qualified']['instructions'] ?? '');

// --- 1. The ICP must-haves come from the app's real ICP definition --------
// 'Chat Qualifier' prompt (includes/Prompts/PromptRegistry.php): size,
// industry, tech stack. Nothing invented beyond that.
$mustHaves = QualifyLeadAction::icpMustHaves();
check('icpMustHaves() returns exactly 3 criteria', count($mustHaves) === 3);
check('must-haves include company size', (bool)array_filter($mustHaves, fn($m) => stripos($m, 'size') !== false));
check('must-haves include industry', (bool)array_filter($mustHaves, fn($m) => stripos($m, 'industry') !== false));
check('must-haves include tech stack', (bool)array_filter($mustHaves, fn($m) => stripos($m, 'tech stack') !== false));

// --- 2. The rewritten question enumerates them explicitly ------------------
foreach ($mustHaves as $i => $mh) {
    check('qualified question enumerates must-have ' . ($i + 1), stripos($instr, $mh) !== false);
}

// --- 3. The old structural-bias bars are gone -------------------------------
check("qualified question no longer demands 'every must-have'", stripos($instr, 'every must-have') === false);
check("qualified question no longer says 'answer false when evidence is thin'",
    stripos($instr, 'answer false when evidence is thin') === false);

// --- 4. Preponderance standard ---------------------------------------------
check('qualified question applies a preponderance standard', stripos($instr, 'preponderance') !== false);
check('qualified question states the >= 0.5 threshold', stripos($instr, '0.5') !== false);

// --- 5. Thin evidence lowers confidence, never defaults to false ------------
check('thin evidence routes to confidence, not forced false',
    stripos($instr, 'confidence') !== false && stripos($instr, 'do not default the answer to false') !== false);

// --- 6. Question types remain valid for the tier ----------------------------
check('qualified question is a noul question', ($questions['qualified']['type'] ?? '') === 'noul');
check('score levels valid for JevProvider::scoreQuestion (2-10)',
    count($fitLevels) >= 2 && count($fitLevels) <= 10);

// --- 7. The 0.5 threshold is what the code applies --------------------------
$ref = new ReflectionClass(QualifyLeadAction::class);
$method = $ref->getMethod('answersToQualification');
$method->setAccessible(true);
$inst = $ref->newInstanceWithoutConstructor();
$yes = $method->invoke($inst, ['qualified' => ['noul' => 0.5], 'score' => ['score' => 3.0]], $fitLevels);
$no = $method->invoke($inst, ['qualified' => ['noul' => 0.49], 'score' => ['score' => 3.0]], $fitLevels);
check('noul 0.5 qualifies (preponderance threshold)', ($yes['qualified'] ?? false) === true && ($yes['source'] ?? '') === 'jev');
check('noul 0.49 does not qualify', ($no['qualified'] ?? true) === false);
check('score position 3.0 of 5 levels maps to 75/100', ($yes['score'] ?? null) === 75);

// --- 8. Hard per-decision timeout is wired -----------------------------------
$timeout = $ref->getConstant('JEV_TIMEOUT_SECONDS');
check('JEV_TIMEOUT_SECONDS is a sane hard timeout', is_int($timeout) && $timeout > 0 && $timeout <= 30,
    'got ' . var_export($timeout, true));

// --- 9. Legacy passthrough contract unchanged -------------------------------
$legacy = $method->invoke($inst, ['qualified' => true, 'score' => 80, 'reason' => 'x'], $fitLevels);
check('legacy result still passes through with source llm',
    ($legacy['qualified'] ?? null) === true && ($legacy['source'] ?? '') === 'llm');

exit(phase4_summary('test_qualify_question.php'));
