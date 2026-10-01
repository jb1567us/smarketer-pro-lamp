<?php

declare(strict_types=1);

namespace App\Evidence;

/**
 * Source-authority taxonomy: LAMP's evidence-grading standard (D2-P1).
 *
 * Ported from the PI Source Authority KG design
 * (~/workspace/jev-icp-determination/SOURCE_AUTHORITY_KG_SPEC.md §3), as
 * LAMP's deterministic, model-free grader for evidence sources. Authority is
 * a *graph/record* job — never model judgment: the tier is derived from
 * source attributes by a top-down decision tree an auditor can evaluate.
 *
 * The four tiers:
 *   Tier 1 — owner's own data; official statistics (BLS, Census, ONS,
 *            Eurostat, Data.gov, SEC IAPD, IRS SOI); datasets maintained by a
 *            government body or the source's own publishing institution.
 *   Tier 2 — named editorial staff + published corrections policy + >=2
 *            years continuous publication; research firms with named
 *            analysts + methodology page; trade associations with a
 *            published industry data program; api_verified evidence.
 *   Tier 3 — aggregators with a stated review-verification process
 *            (G2, Capterra); directories with curation standards
 *            (Thomasnet); named-author blogs with a documented track record
 *            + independent citations.
 *   Tier 4 — dataset imports with NO chain-of-custody to the original
 *            publisher; unverifiable authorship or publication date;
 *            content farms / scraped mirrors / anonymous aggregation.
 *            Tier 4 is NEVER servable in outreach copy (canonical hard bar;
 *            see SERVABLE_MAX_TIER).
 *
 * Fail-closed: a source that cannot be positively placed in tiers 1–3 —
 * unknown kind, missing attributes, or no evidence of trustworthiness —
 * grades as tier 4. In LAMP that routes to human review (flag, never
 * silent drop), which is exactly where unproven sources belong.
 * [ASSUMPTION — owner can veto: "unclassifiable → tier 4" vs "tier 3".]
 *
 * Conflicting tier evidence across multiple evidence records is resolved by
 * explicit source priority (owner > curated_seed > web_evidence >
 * structured_api > harvested_directory) BEFORE grading; this class grades
 * one resolved attribute set. Contradictions fail closed to owner review —
 * never averaged, never modeled.
 *
 * Dependency-light and DB-free: pure PHP, no wall-clock reads inside the
 * tree (dates only matter to the caller / MentionValidity).
 */
class SourceAuthority
{
    /** Highest authority tier still servable in outreach copy. Tier 4 never makes it. */
    public const SERVABLE_MAX_TIER = 3;

    /** Minimum tier value. */
    public const MIN_TIER = 1;

    /** Maximum tier value. */
    public const MAX_TIER = 4;

    /** Closed source_kind vocab (KG spec §1) — a new kind is a schema-level decision. */
    public const SOURCE_KINDS = [
        'official_stats',
        'trade_publication',
        'trade_association',
        'directory',
        'aggregator',
        'research_firm',
        'news_outlet',
        'blog_newsletter',
        'dataset',
        'academic',
    ];

    /** Decision-tree rule identifiers, in evaluation order (audit trail). */
    public const RULE_OWNER_ASSERTION          = 'owner_assertion';
    public const RULE_OFFICIAL_STATS           = 'official_stats';
    public const RULE_GOVERNMENT_DATASET       = 'government_dataset';
    public const RULE_API_VERIFIED             = 'api_verified';
    public const RULE_EDITORIAL_STANDARD       = 'editorial_standard';
    public const RULE_RESEARCH_METHODOLOGY     = 'research_methodology';
    public const RULE_TRADE_ASSOCIATION_DATA   = 'trade_association_data';
    public const RULE_AGGREGATOR_VERIFICATION  = 'aggregator_verification';
    public const RULE_CURATED_DIRECTORY        = 'curated_directory';
    public const RULE_NAMED_AUTHOR_TRACK_RECORD = 'named_author_track_record';
    public const RULE_NO_CHAIN_OF_CUSTODY      = 'no_chain_of_custody';
    public const RULE_UNVERIFIABLE_PROVENANCE  = 'unverifiable_provenance';
    public const RULE_LOW_TRUST_ORIGIN         = 'low_trust_origin';
    public const RULE_FAIL_CLOSED_UNCLASSIFIABLE = 'fail_closed_unclassifiable';

    /**
     * Grade a source's authority tier (1–4) from its attributes.
     *
     * Accepted attributes (all optional except source_kind for a positive
     * placement; missing = treated as absent):
     *   evidence_type: 'owner_assertion' | 'api_verified' | 'dataset_import' | ...
     *   source_kind:   one of SOURCE_KINDS
     *   named_editorial_staff, corrections_policy: bool
     *   years_publishing: int|null
     *   named_analysts, methodology_page: bool (research_firm)
     *   published_data_program: bool (trade_association)
     *   stated_review_verification: bool (aggregator)
     *   curation_standards: bool (directory)
     *   named_author, documented_track_record, independent_citations: bool (blog)
     *   maintained_by_government, maintained_by_own_publisher: bool (dataset)
     *   chain_of_custody: bool (chain back to the original publisher)
     *   authorship_verifiable, publication_date_verifiable: bool
     *   content_farm, scraped_mirror, anonymous_aggregation: bool
     */
    public static function grade(array $attrs): int
    {
        return self::explain($attrs)['tier'];
    }

    /**
     * Grade + report which decision-tree rule fired (auditability).
     *
     * @return array{tier:int, rule:string, kind:?string}
     */
    public static function explain(array $attrs): array
    {
        $kind  = $attrs['source_kind'] ?? null;
        $etype = $attrs['evidence_type'] ?? null;
        $flag  = static fn (string $k): bool => !empty($attrs[$k]);

        // --- Tier 1: owner's data wins everything; official statistics. ---
        if ($etype === 'owner_assertion') {
            return ['tier' => 1, 'rule' => self::RULE_OWNER_ASSERTION, 'kind' => $kind];
        }
        if ($kind === 'official_stats') {
            return ['tier' => 1, 'rule' => self::RULE_OFFICIAL_STATS, 'kind' => $kind];
        }
        if ($kind === 'dataset' && ($flag('maintained_by_government') || $flag('maintained_by_own_publisher'))) {
            return ['tier' => 1, 'rule' => self::RULE_GOVERNMENT_DATASET, 'kind' => $kind];
        }

        // --- Tier 2: editorial / methodological standards. ---
        if ($etype === 'api_verified') {
            return ['tier' => 2, 'rule' => self::RULE_API_VERIFIED, 'kind' => $kind];
        }
        if (
            in_array($kind, ['trade_publication', 'news_outlet', 'blog_newsletter', 'academic'], true)
            && $flag('named_editorial_staff')
            && $flag('corrections_policy')
            && (($attrs['years_publishing'] ?? 0) >= 2)
        ) {
            return ['tier' => 2, 'rule' => self::RULE_EDITORIAL_STANDARD, 'kind' => $kind];
        }
        if ($kind === 'research_firm' && $flag('named_analysts') && $flag('methodology_page')) {
            return ['tier' => 2, 'rule' => self::RULE_RESEARCH_METHODOLOGY, 'kind' => $kind];
        }
        if ($kind === 'trade_association' && $flag('published_data_program')) {
            return ['tier' => 2, 'rule' => self::RULE_TRADE_ASSOCIATION_DATA, 'kind' => $kind];
        }

        // --- Tier 3: stated verification / curation / track record. ---
        if ($kind === 'aggregator' && $flag('stated_review_verification')) {
            return ['tier' => 3, 'rule' => self::RULE_AGGREGATOR_VERIFICATION, 'kind' => $kind];
        }
        if ($kind === 'directory' && $flag('curation_standards')) {
            return ['tier' => 3, 'rule' => self::RULE_CURATED_DIRECTORY, 'kind' => $kind];
        }
        if (
            $kind === 'blog_newsletter'
            && $flag('named_author')
            && $flag('documented_track_record')
            && $flag('independent_citations')
        ) {
            return ['tier' => 3, 'rule' => self::RULE_NAMED_AUTHOR_TRACK_RECORD, 'kind' => $kind];
        }

        // --- Tier 4: no chain-of-custody, unverifiable provenance, low-trust origins. ---
        if ($etype === 'dataset_import' && !$flag('chain_of_custody')) {
            return ['tier' => 4, 'rule' => self::RULE_NO_CHAIN_OF_CUSTODY, 'kind' => $kind];
        }
        if (!$flag('authorship_verifiable') || !$flag('publication_date_verifiable')) {
            return ['tier' => 4, 'rule' => self::RULE_UNVERIFIABLE_PROVENANCE, 'kind' => $kind];
        }
        if ($flag('content_farm') || $flag('scraped_mirror') || $flag('anonymous_aggregation')) {
            return ['tier' => 4, 'rule' => self::RULE_LOW_TRUST_ORIGIN, 'kind' => $kind];
        }

        // --- Fail closed: nothing proven about this source → tier 4. ---
        return ['tier' => 4, 'rule' => self::RULE_FAIL_CLOSED_UNCLASSIFIABLE, 'kind' => $kind];
    }

    /**
     * Whether a tier may be served in outreach copy (tier ≤ 3).
     */
    public static function isServableTier(int $tier): bool
    {
        return $tier >= self::MIN_TIER && $tier <= self::SERVABLE_MAX_TIER;
    }

    /**
     * Short human-readable tier label for review-queue surfaces.
     */
    public static function tierLabel(int $tier): string
    {
        return match ($tier) {
            1 => 'Tier 1 — owner / official statistics',
            2 => 'Tier 2 — editorial / methodological standards',
            3 => 'Tier 3 — stated verification / curation / track record',
            4 => 'Tier 4 — unverifiable / never servable',
            default => "Unknown tier {$tier}",
        };
    }
}
