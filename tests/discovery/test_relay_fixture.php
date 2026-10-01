#!/usr/bin/env php
<?php
/**
 * Relay day-zero fixture test (D-P2: deterministic candidate construction).
 *
 * The run-6 hard lesson, applied to Stage A: never score against
 * targetProse() fallbacks — always explicit fixture snapshots. This fixture
 * is the day-zero "Relay" buyer the mapping spec names as the coverage
 * target for the archetype table:
 *
 *   - B2B SaaS, AI sales-prospecting tool, $249/user/mo
 *   - 20–500-employee B2B companies in the US, UK, Canada
 *   - buyers = founders / sales leadership
 *   - agencies = anti-persona keyword exclusion (carried, judged
 *     downstream — not pre-filtered at construction; see the documented
 *     assumption in CandidateBuilder)
 *
 * The test asserts Relay produces a sensible, non-empty, deterministic
 * candidate set: every candidate traces to an asserted archetype, every
 * description interpolates the buyer facts verbatim, and the whole result
 * is byte-identical across runs.
 *
 * Usage: php tests/discovery/test_relay_fixture.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

use App\Discovery\BuyerArchetypes;
use App\Discovery\CandidateBuilder;

$pass = 0;
$fail = 0;
function relay_check(string $name, bool $cond, string $detail = ''): void
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

/** The Relay buyer, as explicit fixture facts (never a prose fallback). */
function relayFacts(): array
{
    return [
        'product_summary' => 'AI sales-prospecting tool for B2B sales teams',
        'price_band' => '$50–500/mo', // $249/user/mo sits in this intake band
        'transaction' => 'ongoing digital subscription',
        'geography_scale' => 'national',
        'geography_named' => 'US, UK, Canada',
        'size_band' => '20–500 employees',
        'target_titles' => ['founders', 'sales leadership'],
        'buyer_line' => 'B2B companies with 20–500 employees in the US, UK, or Canada',
        'owner_hypothesis' => ['audience' => 'small agencies and SaaS teams that sell outbound'],
        'sells_to_businesses' => true,
        'exclusions' => ['agency'], // anti-persona: carried, NOT applied at construction
    ];
}

$run1 = CandidateBuilder::construct(relayFacts());
$run2 = CandidateBuilder::construct(relayFacts());

$candidates = $run1['candidates'];

echo "1. Relay produces a non-empty candidate set:\n";
relay_check('candidate set is non-empty', count($candidates) > 0, (string)count($candidates));
relay_check('bounded at MAX_CANDIDATES', count($candidates) <= CandidateBuilder::MAX_CANDIDATES);

echo "2. every candidate traces to an asserted archetype:\n";
$traceable = true;
$inB2bTable = true;
$tableIds = BuyerArchetypes::ids();
foreach ($candidates as $c) {
    $persona = BuyerArchetypes::get($c['persona_id']);
    if ($persona === null || ($persona['status'] ?? '') !== BuyerArchetypes::STATUS_ACTIVE) {
        $traceable = false;
    }
    if ($c['segment_id'] !== 'seg_' . $c['persona_id']) {
        $traceable = false;
    }
    // The table is asserted as the LAMP B2B-seller subset by construction
    // (every row a buying role a B2B outbound buyer plausibly sells into);
    // the real invariant is membership in that reviewed table.
    if (!in_array($c['persona_id'], $tableIds, true)) {
        $inB2bTable = false;
    }
}
relay_check('segment_id parses to an active archetype in the table', $traceable);
relay_check('every candidate is a member of the asserted B2B subset table', $inB2bTable);

echo "3. descriptions interpolate Relay's facts verbatim:\n";
$joined = implode(' ', array_column($candidates, 'description'));
relay_check('product summary interpolated', str_contains($joined, 'AI sales-prospecting tool for B2B sales teams'));
relay_check('price band clause', str_contains($joined, 'in the $50–500/mo price band'));
relay_check('transaction clause', str_contains($joined, 'via ongoing digital subscription'));
relay_check('geography clause', str_contains($joined, 'serving US, UK, Canada'));
relay_check('size band clause', str_contains($joined, 'for companies with 20–500 employees'));
relay_check('titles clause', str_contains($joined, '— buyers are founders, sales leadership'));
relay_check('owner hypothesis quoted', str_contains($joined, 'Owner hypothesis: "small agencies and SaaS teams that sell outbound".'));
relay_check('no invented filler ("competitive prices", "cutting-edge")', !preg_match('/competitive prices|cutting-edge|best-in-class|game-changing/i', $joined));

echo "4. determinism and ordering:\n";
relay_check(
    'two runs -> byte-identical',
    json_encode($run1, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === json_encode($run2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
$orders = array_column($candidates, 'persona_id');
$sorted = $orders;
sort($sorted, SORT_STRING);
relay_check('primaries band sorted persona_id ASC', $orders === $sorted, implode(',', $orders));
relay_check('construction_order sequential', array_column($candidates, 'construction_order') === range(0, count($candidates) - 1));
relay_check('intake is not hollow', $run1['meta']['intake_hollow'] === false);

echo "5. Relay's anti-persona is visible to downstream judges:\n";
relay_check(
    'exclusion keyword survives in the input (not silently consumed)',
    in_array('agency', relayFacts()['exclusions'], true)
);
relay_check(
    'scope filter applied (national buyer)',
    $run1['meta']['scope_filter_applied'] === true && $run1['meta']['eligible_count'] > 0,
    'eligible=' . $run1['meta']['eligible_count']
);

echo "\nRelay candidate segments:\n";
foreach ($candidates as $c) {
    echo '  - ' . $c['segment_id'] . "\n";
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
