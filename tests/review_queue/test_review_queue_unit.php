#!/usr/bin/env php
<?php
/**
 * Review queue unit tests (goal_67693fcbba4c, review workflow).
 *
 * Zero network, zero DB. Everything under test is a public static on
 * App\ReviewQueue:
 *   1. Contract constants: REVIEW_STATUS is the literal 'Needs Review' ENUM
 *      value (subject 1's migrations/2026-09-28-needs-review-enum.sql);
 *      decision -> status targets (approved -> Qualified, disqualified ->
 *      Unqualified); API action allowlist.
 *   2. targetStatus(): valid decisions map; unknown decision throws.
 *   3. parseDimensionScores(): parses the latest "Dimensions: k=v/10"
 *      marker line from leads.notes (the exact format
 *      QualifyLeadAction::notesMarker writes); ignores unknown keys;
 *      clamps to 1-10; returns [] for legacy notes without a marker;
 *      the LATEST marker wins when several are appended.
 *
 * Usage: php tests/review_queue/test_review_queue_unit.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

use App\ReviewQueue;

$pass = 0;
$fail = 0;
function rq_check(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS: {$name}\n";
    } else {
        $fail++;
        echo "  FAIL: {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

// --- 1. contract constants --------------------------------------------------
echo "1. contract constants:\n";
rq_check("REVIEW_STATUS === 'Needs Review'", ReviewQueue::REVIEW_STATUS === 'Needs Review');
rq_check('STATUS_APPROVED === Qualified', ReviewQueue::STATUS_APPROVED === 'Qualified');
rq_check('STATUS_DISQUALIFIED === Unqualified', ReviewQueue::STATUS_DISQUALIFIED === 'Unqualified');
rq_check('DECISION_APPROVED === approved', ReviewQueue::DECISION_APPROVED === 'approved');
rq_check('DECISION_DISQUALIFIED === disqualified', ReviewQueue::DECISION_DISQUALIFIED === 'disqualified');
rq_check(
    'ACTIONS allowlist is exactly [list, approve, disqualify]',
    ReviewQueue::ACTIONS === ['list', 'approve', 'disqualify'],
    json_encode(ReviewQueue::ACTIONS)
);
// 'Qualified' must stay in the sequence sendable set so approval re-admits
// the lead (subject 2's SequenceManager::SENDABLE_LEAD_STATUSES).
rq_check(
    'Qualified is sendable per SequenceManager',
    in_array(ReviewQueue::STATUS_APPROVED, \App\SequenceManager::SENDABLE_LEAD_STATUSES, true)
);
rq_check(
    'Unqualified is not sendable per SequenceManager',
    !in_array(ReviewQueue::STATUS_DISQUALIFIED, \App\SequenceManager::SENDABLE_LEAD_STATUSES, true)
);
rq_check(
    "'Needs Review' is not sendable per SequenceManager (fail-closed)",
    !in_array(ReviewQueue::REVIEW_STATUS, \App\SequenceManager::SENDABLE_LEAD_STATUSES, true)
);

// --- 2. targetStatus ---------------------------------------------------------
echo "2. targetStatus:\n";
rq_check(
    'approved -> Qualified',
    ReviewQueue::targetStatus('approved') === 'Qualified'
);
rq_check(
    'disqualified -> Unqualified',
    ReviewQueue::targetStatus('disqualified') === 'Unqualified'
);
$threw = false;
try {
    ReviewQueue::targetStatus('maybe');
} catch (\App\Exceptions\OutreachException $e) {
    $threw = true;
}
rq_check('unknown decision throws OutreachException', $threw);

// --- 3. parseDimensionScores -------------------------------------------------
echo "3. parseDimensionScores:\n";
$marker = "\n\n[Qualification 2026-09-28]: Needs Review (fit 62/100, below qualify threshold 75) — Jev weighted ICP fit 62/100.\n"
    . "Dimensions: company_size=8/10, industry_fit=7/10, tech_stack=6/10, target_title=5/10, geography=7/10, trigger_signals=4/10.";
$scores = ReviewQueue::parseDimensionScores('Some enrichment notes here.' . $marker);
rq_check(
    'parses all six dimensions',
    $scores === [
        'company_size' => 8, 'industry_fit' => 7, 'tech_stack' => 6,
        'target_title' => 5, 'geography' => 7, 'trigger_signals' => 4,
    ],
    json_encode($scores)
);

rq_check('empty notes -> []', ReviewQueue::parseDimensionScores('') === []);
rq_check(
    'legacy notes without marker -> []',
    ReviewQueue::parseDimensionScores("\n\n[Qualification 2026-09-28]: Qualified (score 88) — legacy llm.") === []
);

// Latest marker wins.
$twoMarkers = $marker . "\n\n[Qualification 2026-09-29]: Qualified (fit 81/100) — re-scored.\n"
    . "Dimensions: company_size=9/10, industry_fit=9/10, tech_stack=9/10, target_title=8/10, geography=8/10, trigger_signals=8/10.";
$latest = ReviewQueue::parseDimensionScores($twoMarkers);
rq_check('latest marker wins', ($latest['company_size'] ?? null) === 9, json_encode($latest));

// Unknown keys ignored, values clamped.
$odd = "Dimensions: company_size=14/10, bogus_key=7/10, geography=0/10.";
$clamped = ReviewQueue::parseDimensionScores($odd);
rq_check(
    'unknown keys ignored, scores clamped to 1-10',
    $clamped === ['company_size' => 10, 'geography' => 1],
    json_encode($clamped)
);

echo "  -- test_review_queue_unit.php: {$pass} pass, {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
