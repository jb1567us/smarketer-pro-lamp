#!/usr/bin/env php
<?php
/**
 * SourceAuthority taxonomy unit tests (D2-P1).
 *
 * Zero network, zero DB. Everything under test is a public static on
 * App\Evidence\SourceAuthority:
 *   1. Tier 1 placement: owner_assertion, official_stats, government dataset.
 *   2. Tier 2 placement: editorial-standard test (named staff + corrections
 *      policy + >=2 yrs), research-firm methodology, trade-association data
 *      program, api_verified evidence.
 *   3. Tier 3 placement: aggregator with stated verification, curated
 *      directory, named-author blog with track record + citations.
 *   4. Tier 4 placement: dataset_import with no chain-of-custody,
 *      unverifiable authorship/date, content farm / scraped mirror /
 *      anonymous aggregation.
 *   5. Top-down order: tier-1 positive rules beat tier-4 markers.
 *   6. Fail closed: empty/unknown/unclassifiable sources grade tier 4.
 *   7. isServableTier: tiers 1-3 servable, tier 4 never.
 *
 * Usage: php tests/evidence/test_source_authority_unit.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

use App\Evidence\SourceAuthority;

$pass = 0;
$fail = 0;
function sa_check(string $name, bool $cond, string $detail = ''): void
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

function explained(array $attrs): array
{
    return SourceAuthority::explain($attrs);
}

// --- 1. Tier 1 ---------------------------------------------------------------
echo "1. tier 1:\n";
$e = explained(['evidence_type' => 'owner_assertion', 'source_kind' => 'blog_newsletter']);
sa_check(
    'owner_assertion -> tier 1 even for a blog kind',
    $e['tier'] === 1 && $e['rule'] === SourceAuthority::RULE_OWNER_ASSERTION,
    json_encode($e)
);
$e = explained(['source_kind' => 'official_stats']);
sa_check(
    'official_stats -> tier 1',
    $e['tier'] === 1 && $e['rule'] === SourceAuthority::RULE_OFFICIAL_STATS,
    json_encode($e)
);
$e = explained(['source_kind' => 'dataset', 'maintained_by_government' => true]);
sa_check(
    'government-maintained dataset -> tier 1',
    $e['tier'] === 1 && $e['rule'] === SourceAuthority::RULE_GOVERNMENT_DATASET,
    json_encode($e)
);
$e = explained(['source_kind' => 'dataset', 'maintained_by_own_publisher' => true]);
sa_check(
    'publisher-maintained dataset -> tier 1',
    $e['tier'] === 1,
    json_encode($e)
);

// --- 2. Tier 2 ---------------------------------------------------------------
echo "2. tier 2:\n";
$e = explained([
    'source_kind' => 'trade_publication',
    'named_editorial_staff' => true,
    'corrections_policy' => true,
    'years_publishing' => 5,
]);
sa_check(
    'editorial standard (staff + corrections + 5 yrs) -> tier 2',
    $e['tier'] === 2 && $e['rule'] === SourceAuthority::RULE_EDITORIAL_STANDARD,
    json_encode($e)
);
$e = explained([
    'source_kind' => 'trade_publication',
    'named_editorial_staff' => true,
    'corrections_policy' => true,
    'years_publishing' => 1,
]);
sa_check(
    'editorial standard with only 1 yr publishing -> NOT tier 2',
    $e['tier'] !== 2,
    json_encode($e)
);
$e = explained([
    'source_kind' => 'trade_publication',
    'named_editorial_staff' => true,
    'corrections_policy' => false,
    'years_publishing' => 10,
]);
sa_check(
    'editorial standard without corrections policy -> NOT tier 2',
    $e['tier'] !== 2,
    json_encode($e)
);
$e = explained([
    'source_kind' => 'research_firm',
    'named_analysts' => true,
    'methodology_page' => true,
]);
sa_check(
    'research firm (named analysts + methodology) -> tier 2',
    $e['tier'] === 2 && $e['rule'] === SourceAuthority::RULE_RESEARCH_METHODOLOGY,
    json_encode($e)
);
$e = explained(['source_kind' => 'trade_association', 'published_data_program' => true]);
sa_check(
    'trade association with data program -> tier 2',
    $e['tier'] === 2 && $e['rule'] === SourceAuthority::RULE_TRADE_ASSOCIATION_DATA,
    json_encode($e)
);
$e = explained(['evidence_type' => 'api_verified', 'source_kind' => 'aggregator']);
sa_check(
    'api_verified evidence -> tier 2',
    $e['tier'] === 2 && $e['rule'] === SourceAuthority::RULE_API_VERIFIED,
    json_encode($e)
);

// --- 3. Tier 3 ---------------------------------------------------------------
echo "3. tier 3:\n";
$e = explained(['source_kind' => 'aggregator', 'stated_review_verification' => true]);
sa_check(
    'aggregator with stated verification -> tier 3',
    $e['tier'] === 3 && $e['rule'] === SourceAuthority::RULE_AGGREGATOR_VERIFICATION,
    json_encode($e)
);
$e = explained(['source_kind' => 'directory', 'curation_standards' => true]);
sa_check(
    'curated directory -> tier 3',
    $e['tier'] === 3 && $e['rule'] === SourceAuthority::RULE_CURATED_DIRECTORY,
    json_encode($e)
);
$e = explained([
    'source_kind' => 'blog_newsletter',
    'named_author' => true,
    'documented_track_record' => true,
    'independent_citations' => true,
]);
sa_check(
    'named-author blog with track record + citations -> tier 3',
    $e['tier'] === 3 && $e['rule'] === SourceAuthority::RULE_NAMED_AUTHOR_TRACK_RECORD,
    json_encode($e)
);
$e = explained([
    'source_kind' => 'blog_newsletter',
    'named_author' => true,
    'documented_track_record' => true,
    'independent_citations' => false,
]);
sa_check(
    'named-author blog WITHOUT independent citations -> NOT tier 3',
    $e['tier'] !== 3,
    json_encode($e)
);

// --- 4. Tier 4 ---------------------------------------------------------------
echo "4. tier 4:\n";
$e = explained([
    'evidence_type' => 'dataset_import',
    'source_kind' => 'dataset',
    'chain_of_custody' => false,
]);
sa_check(
    'dataset_import with no chain-of-custody -> tier 4',
    $e['tier'] === 4 && $e['rule'] === SourceAuthority::RULE_NO_CHAIN_OF_CUSTODY,
    json_encode($e)
);
$e = explained([
    'source_kind' => 'aggregator',
    'authorship_verifiable' => false,
    'publication_date_verifiable' => true,
]);
sa_check(
    'unverifiable authorship -> tier 4',
    $e['tier'] === 4 && $e['rule'] === SourceAuthority::RULE_UNVERIFIABLE_PROVENANCE,
    json_encode($e)
);
$e = explained([
    'source_kind' => 'blog_newsletter',
    'authorship_verifiable' => true,
    'publication_date_verifiable' => true,
    'content_farm' => true,
]);
sa_check(
    'content farm (verifiable authorship/date) -> tier 4 via low_trust_origin',
    $e['tier'] === 4 && $e['rule'] === SourceAuthority::RULE_LOW_TRUST_ORIGIN,
    json_encode($e)
);
$e = explained([
    'source_kind' => 'directory',
    'authorship_verifiable' => true,
    'publication_date_verifiable' => true,
    'scraped_mirror' => true,
]);
sa_check(
    'scraped mirror -> tier 4',
    $e['tier'] === 4,
    json_encode($e)
);

// --- 5. Top-down ordering ----------------------------------------------------
echo "5. top-down ordering:\n";
$e = explained([
    'evidence_type' => 'owner_assertion',
    'source_kind' => 'blog_newsletter',
    'content_farm' => true,
]);
sa_check(
    'owner_assertion beats tier-4 markers (top-down)',
    $e['tier'] === 1 && $e['rule'] === SourceAuthority::RULE_OWNER_ASSERTION,
    json_encode($e)
);
$e = explained([
    'evidence_type' => 'api_verified',
    'source_kind' => 'aggregator',
    'stated_review_verification' => true,
]);
sa_check(
    'api_verified (tier 2) beats tier-3 aggregator rule',
    $e['tier'] === 2 && $e['rule'] === SourceAuthority::RULE_API_VERIFIED,
    json_encode($e)
);

// --- 6. Fail closed ----------------------------------------------------------
echo "6. fail closed:\n";
$e = explained([]);
sa_check(
    'empty attributes -> tier 4 (fail closed; unverifiable provenance)',
    $e['tier'] === 4 && $e['rule'] === SourceAuthority::RULE_UNVERIFIABLE_PROVENANCE,
    json_encode($e)
);
$e = explained(['source_kind' => 'podcast_network']);
sa_check(
    'unknown source_kind -> tier 4',
    $e['tier'] === 4,
    json_encode($e)
);
$e = explained([
    'source_kind' => 'dataset',
    'chain_of_custody' => true,
    'authorship_verifiable' => true,
    'publication_date_verifiable' => true,
]);
sa_check(
    'dataset with no tier-1/2/3 match -> tier 4 fail-closed (not promoted)',
    $e['tier'] === 4 && $e['rule'] === SourceAuthority::RULE_FAIL_CLOSED_UNCLASSIFIABLE,
    json_encode($e)
);

// --- 7. Servability ----------------------------------------------------------
echo "7. servability:\n";
sa_check('tier 1 servable', SourceAuthority::isServableTier(1));
sa_check('tier 2 servable', SourceAuthority::isServableTier(2));
sa_check('tier 3 servable', SourceAuthority::isServableTier(3));
sa_check('tier 4 NEVER servable', !SourceAuthority::isServableTier(4));
sa_check('tier 0 not servable', !SourceAuthority::isServableTier(0));
sa_check('SERVABLE_MAX_TIER === 3', SourceAuthority::SERVABLE_MAX_TIER === 3);
sa_check(
    'SOURCE_KINDS is the 10-kind closed vocab',
    count(SourceAuthority::SOURCE_KINDS) === 10
        && in_array('official_stats', SourceAuthority::SOURCE_KINDS, true)
        && in_array('academic', SourceAuthority::SOURCE_KINDS, true)
);
sa_check(
    'tierLabel(4) mentions never servable',
    str_contains(SourceAuthority::tierLabel(4), 'never servable'),
    SourceAuthority::tierLabel(4)
);

echo "  -- test_source_authority_unit.php: {$pass} pass, {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
