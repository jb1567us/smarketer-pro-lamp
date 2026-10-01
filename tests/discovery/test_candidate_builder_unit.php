#!/usr/bin/env php
<?php
/**
 * CandidateBuilder unit tests (D-P2: deterministic candidate construction).
 *
 * Zero network, zero DB, zero LLM. Everything under test is a public static
 * on App\Discovery\CandidateBuilder / App\Discovery\BuyerArchetypes:
 *   1. Table integrity: unique ids, required fields, valid scopes/strengths.
 *   2. Determinism: same buyer facts -> byte-identical result, run twice.
 *   3. No model in the construction path: source grep for provider/HTTP
 *      tokens (code inspection, not just trust).
 *   4. Template rules: S1-S5 sentences, verbatim interpolation, absent
 *      fields omitted (never filler), 800-char cap with truncation marker.
 *   5. Eligibility: scope-match rule, missing/unknown scale widens,
 *      primaries-before-secondaries ordering, M=3 truncation.
 *   6. Honest absence: non-B2B seller -> zero candidates + zero_reason.
 *   7. Exclusions are carried, not applied (documented assumption).
 *
 * Usage: php tests/discovery/test_candidate_builder_unit.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

use App\Discovery\BuyerArchetypes;
use App\Discovery\CandidateBuilder;

$pass = 0;
$fail = 0;
function dc_check(string $name, bool $cond, string $detail = ''): void
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

function stableEncode(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function fullFacts(array $over = []): array
{
    return array_merge([
        'product_summary' => 'AI sales-prospecting tool for B2B teams',
        'price_band' => '$50–500/mo',
        'transaction' => 'ongoing digital subscription',
        'geography_scale' => 'national',
        'geography_named' => 'US, UK, Canada',
        'size_band' => '20–500 employees',
        'target_titles' => ['founders', 'sales leadership'],
        'buyer_line' => 'B2B companies with 20–500 employees in the US, UK, or Canada',
        'owner_hypothesis' => ['audience' => 'small agencies and SaaS teams that sell outbound'],
        'sells_to_businesses' => true,
        'exclusions' => ['agency'],
    ], $over);
}

// --- 1. Table integrity ------------------------------------------------------
echo "1. archetype table integrity:\n";
$rows = BuyerArchetypes::ARCHETYPES;
dc_check('table is non-empty', count($rows) > 0, (string)count($rows));
$ids = [];
$fieldsOk = true;
$scopesOk = true;
$strengthsOk = true;
foreach ($rows as $r) {
    $ids[] = $r['id'] ?? null;
    foreach (['id', 'label', 'definition', 'pains', 'buying_context', 'scope_default', 'strength', 'status'] as $f) {
        if (!array_key_exists($f, $r)) {
            $fieldsOk = false;
        }
    }
    if (CandidateBuilder::scopeLevel($r['scope_default'] ?? null) === null) {
        $scopesOk = false;
    }
    if (!in_array($r['strength'] ?? null, [BuyerArchetypes::STRENGTH_PRIMARY, BuyerArchetypes::STRENGTH_SECONDARY], true)) {
        $strengthsOk = false;
    }
}
dc_check('all ids unique', count($ids) === count(array_unique($ids)));
dc_check('every row has all required fields', $fieldsOk);
dc_check('every scope_default is on the shared ladder', $scopesOk);
dc_check('every strength is primary|secondary', $strengthsOk);
dc_check('at least one secondary exists (ordering band is real)', in_array(BuyerArchetypes::STRENGTH_SECONDARY, array_column($rows, 'strength'), true));
dc_check('ids() is sorted ascending', BuyerArchetypes::ids() === (function () use ($ids) { $s = $ids; sort($s, SORT_STRING); return $s; })());

// --- 2. Determinism ----------------------------------------------------------
echo "2. determinism:\n";
$facts = fullFacts();
$r1 = CandidateBuilder::construct($facts);
$r2 = CandidateBuilder::construct($facts);
$e1 = stableEncode($r1);
$e2 = stableEncode($r2);
dc_check('same inputs -> byte-identical result (run twice)', $e1 === $e2, substr($e1, 0, 120));
$r3 = CandidateBuilder::construct(fullFacts()); // rebuilt facts array, same values
dc_check('rebuilt facts array -> identical bytes', stableEncode($r3) === $e1);

// --- 3. No model in the construction path ------------------------------------
echo "3. no model / provider in construction path (source inspection):\n";
$banned = [
    'JevProvider', 'DecisionTier', 'JevClient', 'JevException',
    'curl_', 'curl_init', 'file_get_contents(', 'stream_context_create',
    'HttpClient', 'api.deepseek', 'api.openai', 'openrouter',
    'API_KEY', 'getenv(', '$_ENV', '$_SERVER',
];
$clean = true;
$hits = [];
foreach ([$repo . '/includes/Discovery/CandidateBuilder.php', $repo . '/includes/Discovery/BuyerArchetypes.php'] as $f) {
    $src = file_get_contents($f);
    foreach ($banned as $token) {
        if (strpos($src, $token) !== false) {
            $clean = false;
            $hits[] = basename($f) . ':' . $token;
        }
    }
}
dc_check('no provider/HTTP/env tokens in construction sources', $clean, implode(', ', $hits));

// --- 4. Template rules -------------------------------------------------------
echo "4. template rules:\n";
$cands = $r1['candidates'];
dc_check('full facts -> exactly MAX_CANDIDATES', count($cands) === CandidateBuilder::MAX_CANDIDATES, (string)count($cands));
$first = $cands[0];
$persona = BuyerArchetypes::get($first['persona_id']);
dc_check('segment_id = seg_<persona_id>', $first['segment_id'] === 'seg_' . $first['persona_id'], $first['segment_id']);
dc_check('description opens with S1 label:definition', str_starts_with($first['description'], $persona['label'] . ': ' . $persona['definition']));
dc_check('S2 pains clause present', str_contains($first['description'], 'Typical pains: ' . implode(', ', $persona['pains']) . '.'));
dc_check('S3 buying context present', str_contains($first['description'], $persona['buying_context']));
dc_check('S4 interpolates product summary verbatim', str_contains($first['description'], 'They buy AI sales-prospecting tool for B2B teams'));
dc_check('S4 price clause verbatim', str_contains($first['description'], 'in the $50–500/mo price band'));
dc_check('S4 transaction clause verbatim', str_contains($first['description'], 'via ongoing digital subscription'));
dc_check('S4 geo prefers named over scale', str_contains($first['description'], 'serving US, UK, Canada') && !str_contains($first['description'], 'at national scale'));
dc_check('S4 size clause verbatim', str_contains($first['description'], 'for companies with 20–500 employees'));
dc_check('S4 titles clause verbatim', str_contains($first['description'], '— buyers are founders, sales leadership'));
dc_check('S4b buyer line quoted verbatim', str_contains($first['description'], 'Their buyer line reads: "B2B companies with 20–500 employees in the US, UK, or Canada".'));
dc_check('S5 owner hypothesis quoted verbatim', str_contains($first['description'], 'Owner hypothesis: "small agencies and SaaS teams that sell outbound".'));
dc_check('no description exceeds 800 chars', array_reduce($cands, fn ($ok, $c) => $ok && mb_strlen($c['description'], 'UTF-8') <= 800, true));
dc_check('construction_order sequential from 0', array_column($cands, 'construction_order') === range(0, count($cands) - 1));

// Absent fields -> omitted, never filler.
$hollow = CandidateBuilder::construct(fullFacts(['product_summary' => '   ', 'price_band' => null, 'transaction' => null]));
$hd = $hollow['candidates'][0]['description'];
dc_check('hollow intake: no S4 sentence', !str_contains($hd, 'They buy'));
dc_check('hollow intake: intake_hollow=1 on candidate and meta', $hollow['candidates'][0]['intake_hollow'] === 1 && $hollow['meta']['intake_hollow'] === true);
dc_check('hollow intake: S1-S3 persona sentences survive', str_starts_with($hd, $persona['label'] . ': ') && str_contains($hd, 'Typical pains:'));
$noPrice = CandidateBuilder::construct(fullFacts(['price_band' => null, 'product_summary' => 'widget']));
dc_check('missing price_band: no price clause, no filler', !str_contains($noPrice['candidates'][0]['description'], 'price band'));
$noNamed = CandidateBuilder::construct(fullFacts(['geography_named' => null]));
dc_check('missing geography_named: falls back to scale clause', str_contains($noNamed['candidates'][0]['description'], 'at national scale'));
$noHyp = CandidateBuilder::construct(fullFacts(['owner_hypothesis' => null]));
dc_check('missing owner hypothesis: S5 omitted', !str_contains($noHyp['candidates'][0]['description'], 'Owner hypothesis:'));

// Truncation cap.
$long = CandidateBuilder::construct(fullFacts([
    'product_summary' => str_repeat('very long product description token ', 40),
    'buyer_line' => str_repeat('word ', 100),
]));
$ld = $long['candidates'][0]['description'];
dc_check('over-800 description capped with marker', mb_strlen($ld, 'UTF-8') === 800 && str_ends_with($ld, CandidateBuilder::TRUNCATION_MARKER));

// --- 5. Eligibility & ordering -----------------------------------------------
echo "5. eligibility and expansion order:\n";
dc_check(
    'national scale -> first 3 primaries by id ASC',
    array_column($cands, 'persona_id') === ['agency_ops_manager', 'agency_principal', 'bootstrapped_saas_founder'],
    implode(',', array_column($cands, 'persona_id'))
);
$global = CandidateBuilder::construct(fullFacts(['geography_scale' => 'online-global']));
$gscopes = [];
foreach ($global['candidates'] as $c) {
    $gscopes[] = BuyerArchetypes::get($c['persona_id'])['scope_default'];
}
dc_check('online-global buyer: only online-global archetypes eligible', $gscopes === ['online-global', 'online-global', 'online-global'], implode(',', $gscopes));
dc_check('online-global eligible_count is 6 (truncated to 3)', $global['meta']['eligible_count'] === 6 && count($global['candidates']) === 3);
$local = CandidateBuilder::construct(fullFacts(['geography_scale' => 'local']));
dc_check('local buyer: every archetype scope covers local', $local['meta']['eligible_count'] === count(BuyerArchetypes::active()));
$missing = CandidateBuilder::construct(fullFacts(['geography_scale' => null]));
dc_check(
    'missing scale: widened (no scope filter), eligible = all active',
    $missing['meta']['scope_widened'] === true && $missing['meta']['scope_filter_applied'] === false
        && $missing['meta']['eligible_count'] === count(BuyerArchetypes::active())
);
$unknown = CandidateBuilder::construct(fullFacts(['geography_scale' => 'planetary']));
dc_check('unknown scale value: widened, identical to missing', stableEncode($unknown) === stableEncode($missing));

// --- 6. Honest absence -------------------------------------------------------
echo "6. honest absence:\n";
$nonb2b = CandidateBuilder::construct(fullFacts(['sells_to_businesses' => false]));
dc_check(
    'non-B2B seller -> zero candidates + zero_reason',
    $nonb2b['candidates'] === [] && $nonb2b['meta']['zero_reason'] === 'non_b2b_seller',
    json_encode($nonb2b['meta'])
);

// --- 7. Exclusions carried, not applied --------------------------------------
echo "7. exclusions carried, not applied:\n";
dc_check(
    "exclusion keyword 'agency' does not remove agency candidates (judged downstream)",
    in_array('agency_principal', array_column($cands, 'persona_id'), true),
    implode(',', array_column($cands, 'persona_id'))
);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
