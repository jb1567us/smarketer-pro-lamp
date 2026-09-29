#!/usr/bin/env php
<?php
/**
 * Qualification routing: the 50-75 fit-score band must route to the
 * 'Needs Review' lead status (sibling 2: decision point).
 *
 * Contract under test — QualifyLeadAction::statusForVerdict():
 *   qualified    -> 'Qualified'
 *   needs_review -> 'Needs Review'   (was 'Unqualified' before sibling 2;
 *                                     fail-closed mapping while the schema
 *                                     had no review ENUM)
 *   unqualified  -> 'Unqualified'
 *
 * Plus the notes-marker invariant: a needs_review result always carries a
 * "Needs Review" marker naming the fit score and the qualify threshold, so
 * a human reviewer sees why the lead is parked.
 *
 * If sibling 2 has not landed yet, the routing checks are SKIPPED with an
 * explicit PENDING reason; the marker check documents current behavior.
 *
 * Zero DB, zero network, zero LLM.
 *
 * Usage: php tests/needs_review/test_qualification_routing.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require nr_repo_root() . '/includes/autoload.php';

use App\Actions\QualifyLeadAction;

// --- Verdict -> status mapping -------------------------------------------------
if (!nr_sibling_decision_landed()) {
    nr_skip("statusForVerdict('needs_review') === 'Needs Review'",
        'PENDING sibling 2 (decision point): still maps to Unqualified');
    nr_skip("statusForVerdict('qualified') === 'Qualified'", 'PENDING sibling 2 (decision point)');
    nr_skip("statusForVerdict('unqualified') === 'Unqualified'", 'PENDING sibling 2 (decision point)');
    nr_skip('review band no longer collapses to Unqualified', 'PENDING sibling 2 (decision point)');
    echo "  NOTE: current mapping sends the 50-75 band to 'Unqualified' (fail-closed);\n";
    echo "  rerun this file once sibling 2's routing commit is in the tree.\n";
} else {
    nr_check("statusForVerdict('qualified') === 'Qualified'",
        QualifyLeadAction::statusForVerdict('qualified') === 'Qualified');
    nr_check("statusForVerdict('needs_review') === 'Needs Review'",
        QualifyLeadAction::statusForVerdict('needs_review') === 'Needs Review');
    nr_check("statusForVerdict('unqualified') === 'Unqualified'",
        QualifyLeadAction::statusForVerdict('unqualified') === 'Unqualified');
    nr_check('review band no longer collapses to Unqualified',
        QualifyLeadAction::statusForVerdict('needs_review') !== 'Unqualified');
    // Unknown verdicts must fail closed, never invent a status.
    nr_check("unknown verdict fails closed to 'Unqualified'",
        QualifyLeadAction::statusForVerdict('bogus') === 'Unqualified');
}

// --- Notes marker invariant (behavior that already exists) ---------------------
$marker = QualifyLeadAction::notesMarker([
    'fit_score' => 62,
    'verdict' => 'needs_review',
    'reason' => 'Borderline ICP fit.',
    'source' => 'weighted',
    'thresholds' => ['qualify' => 75, 'review' => 50],
    'dimensions' => ['company_size' => 7],
], 'Needs Review');
nr_check('needs_review marker names the fit score',
    str_contains($marker, 'Needs Review (fit 62/100'));
nr_check('needs_review marker names the qualify threshold',
    str_contains($marker, 'below qualify threshold 75'));
nr_check('needs_review marker carries the reason',
    str_contains($marker, 'Borderline ICP fit.'));

exit(nr_summary('test_qualification_routing.php'));
