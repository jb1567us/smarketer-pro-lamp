#!/usr/bin/env php
<?php
/**
 * Phase 3 test: ClassifyReplyAction heuristic fixtures + off-mode marking.
 *
 * Covers check categories (b) heuristic fixtures per intent and
 * (c) off-mode marking:
 *   1. Every fixture in fixtures.php classifies to the expected
 *      intent / needs_human / urgency via heuristicFallback().
 *   2. Every heuristic result is marked source='heuristic',
 *      confidence=0.0, and carries a non-empty `note`.
 *   3. ReplyIntake::normalizeVerdict() preserves the full classifier shape
 *      (intent, needs_human, urgency as int 1-10, confidence, source,
 *      latency_ms, note).
 *   4. classify() end-to-end in off mode returns the same shape with
 *      latency_ms set (skipped when the DB-backed mode lookup is
 *      unavailable — heuristicFallback() IS the JEV-off code path, which
 *      is what (b)/(c) verify).
 *
 * Usage: php tests/phase3/test_classify_heuristic.php
 * Needs: no DB, no network. Classification runs purely on keywords.
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require phase3_repo_root() . '/includes/autoload.php';
require __DIR__ . '/fixtures.php';

use App\Actions\ClassifyReplyAction;
use App\ReplyIntake;

$ref = new ReflectionClass(ClassifyReplyAction::class);
/** @var ClassifyReplyAction $action */
$action = $ref->newInstanceWithoutConstructor(); // heuristic needs no ctor deps

// --- (b) heuristic fixtures per intent ------------------------------------
foreach ($HEURISTIC_FIXTURES as $i => $fx) {
    [$subject, $body, $expIntent, $expHuman, $expUrgency] = $fx;
    $r = $action->heuristicFallback($subject, $body);
    $label = "fixture #{$i} intent={$expIntent} [" . substr($body, 0, 42) . '…]';
    check($label . ' intent', $r['intent'] === $expIntent,
        "got '{$r['intent']}' for subject='{$subject}' body='{$body}'");
    check($label . ' needs_human', $r['needs_human'] === $expHuman,
        'got ' . var_export($r['needs_human'], true));
    check($label . ' urgency', $r['urgency'] === $expUrgency,
        'got ' . var_export($r['urgency'], true));

    // --- (c) off-mode marking on every heuristic result -------------------
    check($label . " source='heuristic'", $r['source'] === 'heuristic',
        'got ' . var_export($r['source'], true));
    check($label . ' confidence=0.0', $r['confidence'] === 0.0,
        'got ' . var_export($r['confidence'], true));
    check($label . ' note present', is_string($r['note'] ?? null) && $r['note'] !== '',
        'missing/empty note');
    check($label . ' urgency is int 1-10',
        is_int($r['urgency']) && $r['urgency'] >= 1 && $r['urgency'] <= 10,
        'got ' . var_export($r['urgency'], true));
}

// --- (f) ReplyIntake::normalizeVerdict preserves the classifier shape ------
$rawHeuristic = $action->heuristicFallback('hello', 'Please unsubscribe me');
$norm = ReplyIntake::normalizeVerdict($rawHeuristic);
check('normalizeVerdict keeps intent', $norm['intent'] === 'unsubscribe');
check('normalizeVerdict keeps needs_human=false', $norm['needs_human'] === false);
check('normalizeVerdict keeps urgency as int', $norm['urgency'] === 1 && is_int($norm['urgency']));
check('normalizeVerdict keeps source', $norm['source'] === 'heuristic');
check('normalizeVerdict keeps confidence float', $norm['confidence'] === 0.0);
check('normalizeVerdict preserves note', $norm['note'] === $rawHeuristic['note'] && $norm['note'] !== null,
    'note dropped by normalizeVerdict — mismatch with ClassifyReplyAction documented shape');

$empty = ReplyIntake::normalizeVerdict([]);
check('normalizeVerdict defaults fail toward human review',
    $empty['intent'] === 'unknown' && $empty['needs_human'] === true && $empty['confidence'] === 0.0);

// --- (c) classify() end-to-end off-mode marking ----------------------------
// classify() consults DecisionTier::mode(), which reads DB settings. When the
// DB is unreachable we cannot force 'off' from here; the run is skipped and
// heuristicFallback() above (the exact off-mode code path) remains the proof.
try {
    $pdo = \App\Database::getConnection();
    $mode = \App\Jev\DecisionTier::mode();
    if ($mode === 'off') {
        $v = $action->classify('hello', 'Please unsubscribe me');
        check("classify() off-mode source='heuristic'", $v['source'] === 'heuristic',
            'got ' . var_export($v['source'] ?? null, true));
        check('classify() off-mode confidence=0.0', ($v['confidence'] ?? null) === 0.0);
        check('classify() sets latency_ms', isset($v['latency_ms']) && is_int($v['latency_ms']));
        check('classify() off-mode matches heuristicFallback()',
            $v['intent'] === 'unsubscribe' && $v['urgency'] === 1 && $v['needs_human'] === false);
    } else {
        phase3_skip('classify() off-mode end-to-end', "JEV mode is '{$mode}', not 'off'");
    }
} catch (\Throwable $e) {
    phase3_skip('classify() off-mode end-to-end', 'no DB connection: ' . $e->getMessage());
}

exit(phase3_summary('test_classify_heuristic.php'));
