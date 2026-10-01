<?php

declare(strict_types=1);

namespace App\Channels;

use App\Evidence\MentionValidity;
use App\ReviewQueue;

/**
 * ChannelTargetList — LAMP's deterministic channel target list (P5).
 *
 * LAMP's channel_reach dimension (or its LAMP-original equivalent) scores
 * against an ASSERTED target list. This class IS that target list for the
 * B2B outbound seller: industry × outbound-channel rows asserted by seed
 * curation, served by pure deterministic code. It is also the ONLY source
 * of channel recommendations rendered into outreach copy (the render-time
 * tripwire is mentionCheck()).
 *
 * KG principle (ported from the PI Channel KG design): the list ASSERTS
 * candidates; a decision tier JUDGES fit; deterministic code COMPUTES
 * verdicts. This class never scores fit, never generates prose, never
 * invents a channel. It is:
 *   - deterministic: same query → same list (total order: strength_num DESC,
 *     channel_key ASC), no wall-clock reads, no network, no model in the path;
 *   - versioned: TABLE_VERSION pins what a scoring run saw (pass it through
 *     to telemetry/snapshots; the P4 pinning port makes this auditable);
 *   - fail-closed: unknown industry → empty list + explicit miss (never a
 *     guess); unknown scope → invalid input (never an accidental pass);
 *   - DB-free: pure static data; safe to call from hot scoring paths and
 *     from unit tests without fixtures.
 *
 * SCOPE SEMANTICS — DELIBERATELY NOT EFFICACY CLAIMS: presence in the seed
 * asserts that a channel is a real, established route B2B sellers use to
 * reach buyers in the vertical — an existence/usage assertion, not a
 * conversion claim. `strength` bands describe usage prevalence
 * (high = primary route, medium = common secondary, low = situational),
 * never measured conversion rates. Do NOT read this table as "what
 * converts best."
 * [ASSUMPTION — owner can veto the strength semantics.]
 *
 * Scope ladder is SHARED with the P1 evidence predicate (narrow → broad):
 *   local < state < regional < national < online-global
 * A channel edge covers a target scope when its level is >= the target's:
 * national data covers a local target; a local-only channel never serves a
 * national query. Edge scope facets are OPTIONAL; absent = inherits the
 * industry's scope_default. (Ladder rule lives in MentionValidity::SCOPES /
 * scopeCovers() so there is exactly one ladder definition in the codebase.)
 *
 * NEVER-JEV boundary #5: candidate retrieval, scope filtering, aggregation,
 * thresholds, and ranking keys are deterministic code — they stay here. A
 * future JEV `channel_reach` score judges fit AGAINST this list's labels;
 * it never decides what is retrievable, in scope, or recent.
 */
class ChannelTargetList
{
    /** Seed-table version. Pin this in telemetry/snapshots; bump on any seed change. */
    public const TABLE_VERSION = '2026-10-01';

    /**
     * Closed outbound-channel vocab (stable slugs; renames keep the key).
     * Adding/removing a key is a taxonomy change (schema-migration grade —
     * owner sign-off, TABLE_VERSION bump). The CHANNEL_ALIASES table may grow
     * freely; the key set may not.
     */
    public const CHANNEL_KEYS = [
        'cold_email'            => 'Cold email',
        'email_nurture'         => 'Email nurture',
        'linkedin_outreach'     => 'LinkedIn outreach',
        'linkedin_ads'          => 'LinkedIn ads',
        'cold_call'             => 'Cold calling',
        'direct_mail'           => 'Direct mail',
        'paid_search'           => 'Paid search',
        'social_organic'        => 'Organic social',
        'webinars'              => 'Webinars',
        'events'                => 'In-person events',
        'referrals_wom'         => 'Referrals / word of mouth',
        'partnerships_affiliates' => 'Partnerships / affiliates',
        'sms'                   => 'SMS outreach',
    ];

    /** Channel definitions (what the key MEANS — not what it earns). */
    public const CHANNEL_DEFINITIONS = [
        'cold_email'            => '1:1 outbound email sequences to bought/built prospect lists.',
        'email_nurture'         => 'Owned-list email: nurture sequences, newsletters, follow-ups.',
        'linkedin_outreach'     => 'LinkedIn direct outreach: connection requests + DM sequences.',
        'linkedin_ads'          => 'Paid placements on LinkedIn (feed, InMail, lead-gen forms).',
        'cold_call'             => 'Outbound phone outreach to business decision-makers.',
        'direct_mail'           => 'Physical mail (letters, postcards, lumpy mail) to businesses.',
        'paid_search'           => 'Search-engine PPC on explicit query intent (Google Ads, Bing).',
        'social_organic'        => 'Owned organic content and community-building (LinkedIn, X).',
        'webinars'              => 'Hosted or sponsored online events used for lead capture.',
        'events'                => 'Trade shows, conferences, local meetups, pop-ups.',
        'referrals_wom'         => 'Referral networks and word-of-mouth-driven introductions.',
        'partnerships_affiliates' => 'Co-marketing, referral programs, affiliate/partnership motions.',
        'sms'                   => 'Text-message outreach to opted-in or business contacts.',
    ];

    /**
     * Free-text alias → channel_key (owner-reviewable; grows WITHOUT a
     * taxonomy change). Ambiguous → null (fail-closed; see mentionCheck()).
     */
    public const CHANNEL_ALIASES = [
        'cold email'       => 'cold_email',
        'cold emails'      => 'cold_email',
        'cold emailing'    => 'cold_email',
        'outbound email'   => 'cold_email',
        'email outreach'   => 'cold_email',
        'nurture email'    => 'email_nurture',
        'drip email'       => 'email_nurture',
        'linkedin dms'     => 'linkedin_outreach',
        'linkedin dm'      => 'linkedin_outreach',
        'linkedin direct'  => 'linkedin_outreach',
        'inmail'           => 'linkedin_outreach',
        'linkedin advertising' => 'linkedin_ads',
        'linkedin ppc'     => 'linkedin_ads',
        'cold calls'       => 'cold_call',
        'telemarketing'    => 'cold_call',
        'phone outreach'   => 'cold_call',
        'snail mail'       => 'direct_mail',
        'direct mail'      => 'direct_mail',
        'lumpy mail'       => 'direct_mail',
        'postcards'        => 'direct_mail',
        'google ads'       => 'paid_search',
        'bing ads'         => 'paid_search',
        'ppc'              => 'paid_search',
        'search ads'       => 'paid_search',
        'organic linkedin' => 'social_organic',
        'linkedin content' => 'social_organic',
        'content marketing' => 'social_organic',
        'trade shows'      => 'events',
        'conferences'      => 'events',
        'trade show'       => 'events',
        'virtual events'   => 'webinars',
        'online events'    => 'webinars',
        'referrals'        => 'referrals_wom',
        'word of mouth'    => 'referrals_wom',
        'referral program' => 'partnerships_affiliates',
        'affiliates'       => 'partnerships_affiliates',
        'co-marketing'     => 'partnerships_affiliates',
        'texting'          => 'sms',
        'text outreach'    => 'sms',
    ];

    /**
     * Effectiveness-basis vocab (closed; required on every edge — an edge
     * without a stated *why* is refused). Descriptive qualifiers only: they
     * say WHY the channel is a route to these buyers, never HOW WELL it
     * converts.
     */
    public const BASES = [
        'buyer_reachable'  => 'buyers in this vertical are directly reachable here (email findable, phones answer, accounts identifiable).',
        'buyers_congregate' => 'buyers in this vertical demonstrably gather here.',
        'trust_driven'     => 'the channel\'s mechanism is credibility transfer.',
        'transaction_intent' => 'the channel captures active buying intent.',
        'repeat_engagement' => 'the channel retains / re-engages past contacts.',
        'local_discovery'  => 'the channel drives local findability for local buyers.',
    ];

    /** Strength → numeric (orders candidates; NEVER discounts a rubric score). */
    public const STRENGTH_NUM = ['high' => 1.0, 'medium' => 0.6, 'low' => 0.3];

    /** Default candidate filter: medium+ (mirrors the PI Channel KG min_strength 0.4 prior). */
    public const DEFAULT_MIN_STRENGTH = 0.4;

    // --- Miss / failed-check codes (stable; surfaced in results & telemetry). ---
    public const MISS_UNKNOWN_INDUSTRY = 'unknown_industry';
    public const MISS_INVALID_SCOPE    = 'invalid_scope';
    public const FAIL_UNKNOWN_CHANNEL  = 'unknown_channel';
    public const FAIL_NOT_IN_TARGET_LIST = 'not_in_target_list';
    public const FAIL_STRENGTH_BELOW_MIN = 'strength_below_min';
    public const FAIL_SCOPE            = 'scope_mismatch';

    /**
     * Seed table: industry_key => [label, scope_default, channels].
     * Each channel row: strength (high|medium|low, required),
     * basis (one of BASES, required), scope (optional facet — absent =
     * inherits the industry scope_default), notes (optional, ≤280 chars).
     *
     * LAMP-relevant verticals only (B2B seller target universe) — kept
     * small and honest. Everything outside this table + the versioned
     * extension file is a deliberate MISS, not a guess.
     */
    public const INDUSTRY_SEED = [
        'saas_b2b' => [
            'label' => 'B2B SaaS / software companies',
            'scope_default' => 'online-global',
            'channels' => [
                'cold_email'        => ['strength' => 'high',   'basis' => 'buyer_reachable'],
                'linkedin_outreach' => ['strength' => 'high',   'basis' => 'buyers_congregate'],
                'cold_call'         => ['strength' => 'medium', 'basis' => 'buyer_reachable'],
                'linkedin_ads'      => ['strength' => 'medium', 'basis' => 'buyers_congregate'],
                'events'            => ['strength' => 'medium', 'basis' => 'buyers_congregate'],
                'webinars'          => ['strength' => 'medium', 'basis' => 'buyers_congregate'],
                'email_nurture'     => ['strength' => 'medium', 'basis' => 'repeat_engagement'],
                'partnerships_affiliates' => ['strength' => 'medium', 'basis' => 'trust_driven'],
                'social_organic'    => ['strength' => 'medium', 'basis' => 'buyers_congregate'],
                'direct_mail'       => ['strength' => 'low',    'basis' => 'buyer_reachable',
                                        'notes' => 'Lumpy mail breaks through to executives; situational, high cost.'],
            ],
        ],
        'agencies' => [
            'label' => 'Marketing / creative / digital agencies',
            'scope_default' => 'national',
            'channels' => [
                'cold_email'        => ['strength' => 'high',   'basis' => 'buyer_reachable'],
                'linkedin_outreach' => ['strength' => 'high',   'basis' => 'buyers_congregate'],
                'referrals_wom'     => ['strength' => 'high',   'basis' => 'trust_driven'],
                'cold_call'         => ['strength' => 'medium', 'basis' => 'buyer_reachable'],
                'social_organic'    => ['strength' => 'medium', 'basis' => 'buyers_congregate'],
                'partnerships_affiliates' => ['strength' => 'medium', 'basis' => 'trust_driven'],
                'events'            => ['strength' => 'low',    'basis' => 'buyers_congregate'],
            ],
        ],
        'local_services_home' => [
            'label' => 'Local home services (HVAC, plumbing, roofing, landscaping, cleaning)',
            'scope_default' => 'local',
            'channels' => [
                'cold_email'        => ['strength' => 'high',   'basis' => 'buyer_reachable'],
                'cold_call'         => ['strength' => 'high',   'basis' => 'buyer_reachable'],
                'direct_mail'       => ['strength' => 'medium', 'basis' => 'buyer_reachable',
                                        'scope' => 'local',
                                        'notes' => 'Local-only route: EDDM / radius mail to the shop address.'],
                'paid_search'       => ['strength' => 'medium', 'basis' => 'transaction_intent'],
                'linkedin_outreach' => ['strength' => 'medium', 'basis' => 'buyer_reachable'],
                'sms'               => ['strength' => 'low',    'basis' => 'repeat_engagement',
                                        'notes' => 'Thin assertion: compliance-heavy; situational.'],
                'events'            => ['strength' => 'low',    'basis' => 'buyers_congregate',
                                        'scope' => 'local',
                                        'notes' => 'Local chamber / trade meetups only.'],
            ],
        ],
        'legal' => [
            'label' => 'Law firms / legal practices',
            'scope_default' => 'state',
            'channels' => [
                'cold_email'        => ['strength' => 'high',   'basis' => 'buyer_reachable'],
                'referrals_wom'     => ['strength' => 'high',   'basis' => 'trust_driven'],
                'linkedin_outreach' => ['strength' => 'medium', 'basis' => 'buyer_reachable'],
                'cold_call'         => ['strength' => 'medium', 'basis' => 'buyer_reachable'],
                'direct_mail'       => ['strength' => 'medium', 'basis' => 'buyer_reachable'],
                'events'            => ['strength' => 'low',    'basis' => 'trust_driven',
                                        'notes' => 'Thin assertion: bar association events are closed circles.'],
            ],
        ],
        'finance' => [
            'label' => 'Financial advisors, CPAs, accounting firms',
            'scope_default' => 'state',
            'channels' => [
                'cold_email'        => ['strength' => 'high',   'basis' => 'buyer_reachable'],
                'linkedin_outreach' => ['strength' => 'high',   'basis' => 'buyers_congregate'],
                'referrals_wom'     => ['strength' => 'high',   'basis' => 'trust_driven'],
                'cold_call'         => ['strength' => 'medium', 'basis' => 'buyer_reachable'],
                'webinars'          => ['strength' => 'medium', 'basis' => 'trust_driven'],
                'events'            => ['strength' => 'low',    'basis' => 'buyers_congregate'],
            ],
        ],
        'real_estate' => [
            'label' => 'Real estate brokerages / agencies / property managers',
            'scope_default' => 'state',
            'channels' => [
                'cold_call'         => ['strength' => 'high',   'basis' => 'buyer_reachable',
                                        'notes' => 'Agents answer phones; phone-first vertical.'],
                'cold_email'        => ['strength' => 'high',   'basis' => 'buyer_reachable'],
                'sms'               => ['strength' => 'medium', 'basis' => 'repeat_engagement'],
                'social_organic'    => ['strength' => 'medium', 'basis' => 'buyers_congregate'],
                'linkedin_outreach' => ['strength' => 'medium', 'basis' => 'buyer_reachable'],
                'events'            => ['strength' => 'medium', 'basis' => 'trust_driven'],
                'direct_mail'       => ['strength' => 'medium', 'basis' => 'buyer_reachable'],
            ],
        ],
        'manufacturing' => [
            'label' => 'Manufacturers / industrial companies',
            'scope_default' => 'national',
            'channels' => [
                'cold_email'        => ['strength' => 'high',   'basis' => 'buyer_reachable',
                                        'scope' => 'online-global',
                                        'notes' => 'Faceted online-global: email ignores plant geography.'],
                'cold_call'         => ['strength' => 'high',   'basis' => 'buyer_reachable'],
                'linkedin_outreach' => ['strength' => 'medium', 'basis' => 'buyers_congregate'],
                'events'            => ['strength' => 'medium', 'basis' => 'buyers_congregate',
                                        'notes' => 'Trade shows remain a working route in industrial.'],
                'direct_mail'       => ['strength' => 'low',    'basis' => 'buyer_reachable',
                                        'notes' => 'Thin assertion: dimensional mail to plant managers; situational.'],
                'paid_search'       => ['strength' => 'low',    'basis' => 'transaction_intent',
                                        'notes' => 'Thin assertion: thin intent volume in industrial queries.'],
            ],
        ],
        'b2b_professional_services' => [
            'label' => 'B2B professional services (consulting, IT services, HR, payroll)',
            'scope_default' => 'national',
            'channels' => [
                'cold_email'        => ['strength' => 'high',   'basis' => 'buyer_reachable'],
                'linkedin_outreach' => ['strength' => 'high',   'basis' => 'buyers_congregate'],
                'referrals_wom'     => ['strength' => 'high',   'basis' => 'trust_driven'],
                'cold_call'         => ['strength' => 'medium', 'basis' => 'buyer_reachable'],
                'webinars'          => ['strength' => 'medium', 'basis' => 'buyers_congregate'],
                'events'            => ['strength' => 'medium', 'basis' => 'buyers_congregate'],
                'social_organic'    => ['strength' => 'medium', 'basis' => 'buyers_congregate'],
                'partnerships_affiliates' => ['strength' => 'medium', 'basis' => 'trust_driven'],
                'email_nurture'     => ['strength' => 'medium', 'basis' => 'repeat_engagement'],
            ],
        ],
        'health_practitioners' => [
            'label' => 'Health practitioners (dental, clinics, practices)',
            'scope_default' => 'local',
            'channels' => [
                'cold_email'        => ['strength' => 'high',   'basis' => 'buyer_reachable'],
                'cold_call'         => ['strength' => 'medium', 'basis' => 'buyer_reachable'],
                'direct_mail'       => ['strength' => 'medium', 'basis' => 'buyer_reachable',
                                        'scope' => 'local'],
                'paid_search'       => ['strength' => 'medium', 'basis' => 'transaction_intent'],
                'linkedin_outreach' => ['strength' => 'low',    'basis' => 'buyer_reachable',
                                        'notes' => 'Thin assertion: many practitioners are not active on LinkedIn.'],
                'events'            => ['strength' => 'low',    'basis' => 'buyers_congregate',
                                        'scope' => 'local',
                                        'notes' => 'Local practice-owner meetups only.'],
            ],
        ],
    ];

    /**
     * Retrieve the asserted channel list for an industry at a target scope.
     *
     * Deterministic: total order (strength_num DESC, channel_key ASC), no
     * wall clock, no network, no model. Pure static data (DB-free).
     *
     * Filtering (all deterministic code):
     *   1. industry unknown → matched=false, channels=[], explicit miss
     *      (fail-closed: NO guessed list).
     *   2. scope unknown → matched=false, miss=MISS_INVALID_SCOPE.
     *   3. strength filter: strength_num >= $minStrength (default 0.4 =
     *      medium+; mirrors the PI Channel KG prior).
     *   4. scope filter: edge scope (facet, else industry scope_default)
     *      must cover the requested scope on the shared ladder
     *      (MentionValidity::scopeCovers).
     *
     * @param string $industryKey  one of the INDUSTRY_SEED keys
     * @param string $targetScope  one of MentionValidity::SCOPES
     * @param float  $minStrength  inclusive numeric floor (high=1.0, medium=0.6, low=0.3)
     *
     * @return array{
     *   industry_key:string, industry_label:?string, requested_scope:string,
     *   matched:bool, table_version:string, extension_version:string,
     *   channels:array<array{channel_key:string,label:string,strength:string,
     *     strength_num:float,basis:string,basis_definition:string,scope:string,
     *     source:string,notes:?string}>,
     *   miss:?array{code:string,detail:string},
     *   extension_conflicts:array<string>
     * }
     */
    public static function channelsFor(
        string $industryKey,
        string $targetScope,
        float $minStrength = self::DEFAULT_MIN_STRENGTH
    ): array {
        $result = [
            'industry_key' => $industryKey,
            'industry_label' => null,
            'requested_scope' => $targetScope,
            'matched' => false,
            'table_version' => self::TABLE_VERSION,
            'extension_version' => ChannelTargetListExtension::EXTENSION_VERSION,
            'channels' => [],
            'miss' => null,
            'extension_conflicts' => [],
        ];

        // Fail closed: unknown scope is invalid input, never an accidental pass.
        if (!in_array($targetScope, MentionValidity::SCOPES, true)) {
            $result['miss'] = [
                'code' => self::MISS_INVALID_SCOPE,
                'detail' => "Unknown scope '{$targetScope}'. Valid: "
                    . implode(', ', MentionValidity::SCOPES) . '.',
            ];
            return $result;
        }

        $merged = self::mergedSeed($result['extension_conflicts']);

        // Fail closed: unknown industry returns an explicit miss, never a guess.
        if (!isset($merged[$industryKey])) {
            $result['miss'] = [
                'code' => self::MISS_UNKNOWN_INDUSTRY,
                'detail' => "No asserted channel target list for industry '{$industryKey}'. "
                    . 'The list is never guessed; add the industry via the versioned '
                    . 'extension mechanism (ChannelTargetListExtension) or it stays a miss. '
                    . 'Known industries: ' . implode(', ', array_keys($merged)) . '.',
                'available_industries' => array_keys($merged),
            ];
            return $result;
        }

        $industry = $merged[$industryKey];
        $result['industry_label'] = $industry['label'];
        $result['matched'] = true;

        $channels = [];
        foreach ($industry['channels'] as $channelKey => $edge) {
            $strength = $edge['strength'] ?? null;
            $basis = $edge['basis'] ?? null;
            // Refused edges (missing strength/basis) are skipped at build —
            // see selfCheck(); defense in depth drops them here too.
            if (!isset(self::STRENGTH_NUM[$strength]) || !isset(self::BASES[$basis])) {
                continue;
            }
            $strengthNum = self::STRENGTH_NUM[$strength];
            if ($strengthNum < $minStrength) {
                continue;
            }
            $edgeScope = $edge['scope'] ?? $industry['scope_default'];
            if (!MentionValidity::scopeCovers($edgeScope, $targetScope)) {
                continue;
            }
            $channels[] = [
                'channel_key' => $channelKey,
                'label' => self::CHANNEL_KEYS[$channelKey] ?? $channelKey,
                'strength' => $strength,
                'strength_num' => $strengthNum,
                'basis' => $basis,
                'basis_definition' => self::BASES[$basis],
                'scope' => $edgeScope,
                'source' => $edge['__source'] ?? 'core',
                'notes' => $edge['notes'] ?? null,
            ];
        }

        // Total order: strength_num DESC, channel_key ASC (deterministic replay).
        usort($channels, static function (array $a, array $b): int {
            if ($a['strength_num'] !== $b['strength_num']) {
                return $b['strength_num'] <=> $a['strength_num'];
            }
            return $a['channel_key'] <=> $b['channel_key'];
        });

        $result['channels'] = $channels;
        return $result;
    }

    /**
     * Injection seam for ScoreLeadFitAction / channel_reach scoring (v1: unused
     * by production — the canonical LAMP dim set is still an open owner
     * question (integration-map §4d.8), and JEV stays off/shadow).
     *
     * When a channel_reach-style dimension lands, it injects this block as
     * the deterministic `channel_target` input state — mirroring PI's DP1
     * injection (integration-map §5.1): labels go in the target config, and
     * each channel carries its *basis* so a scorer judges "explicit segment
     * presence" against the right mechanism. The scorer never invents the
     * channel list; it only sees what this block asserts.
     *
     * On miss (unknown industry), the seam returns the honest fallback
     * shape: channels=[] + miss carried through. Callers must treat a miss
     * as "no asserted target list" (unscored for that dimension, never judged
     * against a hallucinated list).
     */
    public static function targetBlock(
        string $industryKey,
        string $targetScope,
        float $minStrength = self::DEFAULT_MIN_STRENGTH
    ): array {
        $res = self::channelsFor($industryKey, $targetScope, $minStrength);
        $channels = [];
        $channelTarget = [];
        foreach ($res['channels'] as $c) {
            $channels[] = $c['label'];
            $channelTarget[] = [
                'channel_key' => $c['channel_key'],
                'label' => $c['label'],
                'basis' => $c['basis'],
            ];
        }
        return [
            'channels' => $channels,
            'channel_target' => $channelTarget,
            'table_version' => $res['table_version'],
            'extension_version' => $res['extension_version'],
            'matched' => $res['matched'],
            'miss' => $res['miss'],
        ];
    }

    /**
     * Render-time tripwire: "may this channel mention appear in copy?"
     * Deterministic predicate (never-Jev boundary #5). Passes iff:
     *   1. the mention resolves to a known channel_key (alias table; ambiguous
     *      or unmappable → fail-closed);
     *   2. an edge exists for (industry, channel) in the target list;
     *   3. its strength_num >= $minStrength;
     *   4. its scope covers the requested scope.
     *
     * LAMP difference from PI's DP7: a failing mention is NEVER silently
     * dropped — this returns the failure with a human-review routing
     * recommendation (flag, not drop). The caller routes to the review
     * queue; the human decides.
     *
     * @return array{allowed:bool, channel_key:?string, failed_checks:array<string>,
     *   channel:?array, suggested_routing:string, review_status:?string}
     */
    public static function mentionCheck(
        string $industryKey,
        string $targetScope,
        string $mentionText,
        float $minStrength = self::DEFAULT_MIN_STRENGTH
    ): array {
        $failed = [];
        $verdict = [
            'allowed' => false,
            'channel_key' => null,
            'failed_checks' => [],
            'channel' => null,
            'suggested_routing' => 'none',
            'review_status' => null,
        ];

        // 1. Resolve the mention to a closed channel key.
        $channelKey = self::resolveChannel($mentionText);
        if ($channelKey === null) {
            $failed[] = self::FAIL_UNKNOWN_CHANNEL;
            return self::flagged($verdict, $failed,
                "Mention '{$mentionText}' does not resolve to a known outbound channel "
                . '(alias table unmappable or ambiguous).');
        }
        $verdict['channel_key'] = $channelKey;

        // Industry / scope inputs are validated by channelsFor() below;
        // a miss there fails closed here too.
        $res = self::channelsFor($industryKey, $targetScope, $minStrength);
        if ($res['miss'] !== null) {
            $failed[] = $res['miss']['code'];
            return self::flagged($verdict, $failed,
                "Channel lookup failed closed: {$res['miss']['detail']}");
        }

        // 2-4. The resolved channel must be in the filtered target list.
        foreach ($res['channels'] as $c) {
            if ($c['channel_key'] === $channelKey) {
                $verdict['channel'] = $c;
                $verdict['allowed'] = true;
                return $verdict;
            }
        }

        // Not in the filtered list: determine WHY (strength vs scope vs absent).
        $unfiltered = self::channelsFor($industryKey, $targetScope, 0.0);
        $edge = null;
        foreach ($unfiltered['channels'] as $c) {
            if ($c['channel_key'] === $channelKey) {
                $edge = $c;
                break;
            }
        }
        if ($edge === null) {
            // Edge exists in seed but scope-filtered out? Check raw edge scope.
            $edgeScope = self::rawEdgeScope($industryKey, $channelKey);
            if ($edgeScope !== null && !MentionValidity::scopeCovers($edgeScope, $targetScope)) {
                $failed[] = self::FAIL_SCOPE;
                return self::flagged($verdict, $failed,
                    "Channel '{$channelKey}' is asserted for '{$industryKey}' at scope "
                    . "'{$edgeScope}', which does not cover requested scope '{$targetScope}'.");
            }
            $failed[] = self::FAIL_NOT_IN_TARGET_LIST;
            return self::flagged($verdict, $failed,
                "Channel '{$channelKey}' is not in the asserted target list for "
                . "industry '{$industryKey}'.");
        }
        // Present but below the strength floor.
        $failed[] = self::FAIL_STRENGTH_BELOW_MIN;
        return self::flagged($verdict, $failed,
            "Channel '{$channelKey}' is asserted for '{$industryKey}' but at strength "
            . "'{$edge['strength']}' ({$edge['strength_num']}), below the "
            . "min_strength {$minStrength} filter.");
    }

    /**
     * Resolve free text to a closed channel_key: exact key match first, then
     * the alias table. Returns null when unmappable — fail-closed.
     */
    public static function resolveChannel(string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        if (isset(self::CHANNEL_KEYS[$t])) {
            return $t;
        }
        return self::CHANNEL_ALIASES[$t] ?? null;
    }

    /**
     * Self-audit for seed-table integrity (run in tests / CI): every edge
     * carries a closed strength + closed basis + resolvable scope; every
     * channel key in every industry is in the closed enum; every scope
     * value is on the shared ladder. Returns a list of violations (empty =
     * clean). A violated edge is REFUSED (never served half-specified).
     *
     * @return array<string>
     */
    public static function selfCheck(): array
    {
        $violations = [];
        foreach (['core' => self::INDUSTRY_SEED, 'extension' => ChannelTargetListExtension::EXTENSION_ROWS] as $src => $seed) {
            foreach ($seed as $industryKey => $industry) {
                if (!is_string($industryKey) || $industryKey === '') {
                    $violations[] = "{$src}: industry key is empty/non-string.";
                    continue;
                }
                $def = $industry['scope_default'] ?? null;
                if (!in_array($def, MentionValidity::SCOPES, true)) {
                    $violations[] = "{$src}/{$industryKey}: scope_default '{$def}' not on the shared ladder.";
                }
                foreach (($industry['channels'] ?? []) as $channelKey => $edge) {
                    if (!isset(self::CHANNEL_KEYS[$channelKey])) {
                        $violations[] = "{$src}/{$industryKey}: channel_key '{$channelKey}' not in the closed enum.";
                    }
                    $strength = $edge['strength'] ?? null;
                    if (!isset(self::STRENGTH_NUM[$strength])) {
                        $violations[] = "{$src}/{$industryKey}/{$channelKey}: strength '{$strength}' not closed (high|medium|low).";
                    }
                    $basis = $edge['basis'] ?? null;
                    if (!isset(self::BASES[$basis])) {
                        $violations[] = "{$src}/{$industryKey}/{$channelKey}: basis '{$basis}' not in the closed vocab.";
                    }
                    $scope = $edge['scope'] ?? $def;
                    if (!in_array($scope, MentionValidity::SCOPES, true)) {
                        $violations[] = "{$src}/{$industryKey}/{$channelKey}: scope '{$scope}' not on the shared ladder.";
                    }
                    $notes = $edge['notes'] ?? null;
                    if ($notes !== null && mb_strlen($notes) > 280) {
                        $violations[] = "{$src}/{$industryKey}/{$channelKey}: notes exceed 280 chars.";
                    }
                }
            }
        }
        return $violations;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Core seed + versioned extension rows, merged deterministically.
     * Extension rows may ADD industries or channels; they may NOT override
     * core rows — conflicts resolve to core and are reported via
     * $conflicts (caller-visible, auditable). No ad-hoc additions: the only
     * way an industry enters the list is a committed, versioned extension
     * row (see ChannelTargetListExtension).
     *
     * @param array<string> $conflicts  out: "industry/channel" paths where extension lost to core
     * @return array<string,array>
     */
    private static function mergedSeed(array &$conflicts): array
    {
        $merged = [];
        foreach (self::INDUSTRY_SEED as $ik => $industry) {
            $channels = [];
            foreach ($industry['channels'] as $ck => $edge) {
                $edge['__source'] = 'core';
                $channels[$ck] = $edge;
            }
            $industry['channels'] = $channels;
            $merged[$ik] = $industry;
        }

        foreach (ChannelTargetListExtension::EXTENSION_ROWS as $ik => $industry) {
            if (!isset($merged[$ik])) {
                $channels = [];
                foreach (($industry['channels'] ?? []) as $ck => $edge) {
                    $edge['__source'] = 'extension';
                    $channels[$ck] = $edge;
                }
                $industry['channels'] = $channels;
                $merged[$ik] = $industry;
                continue;
            }
            foreach (($industry['channels'] ?? []) as $ck => $edge) {
                if (isset($merged[$ik]['channels'][$ck])) {
                    $conflicts[] = "{$ik}/{$ck}";
                    continue;
                }
                $edge['__source'] = 'extension';
                $merged[$ik]['channels'][$ck] = $edge;
            }
        }
        return $merged;
    }

    /**
     * Raw edge scope for a (industry, channel) pair, bypassing strength and
     * scope filters — used only to distinguish FAIL_SCOPE from
     * FAIL_NOT_IN_TARGET_LIST in mentionCheck(). Returns null when no edge.
     */
    private static function rawEdgeScope(string $industryKey, string $channelKey): ?string
    {
        $conflicts = [];
        $merged = self::mergedSeed($conflicts);
        $edge = $merged[$industryKey]['channels'][$channelKey] ?? null;
        if ($edge === null) {
            return null;
        }
        return $edge['scope'] ?? $merged[$industryKey]['scope_default'];
    }

    /**
     * Flag-not-drop completion for a failing mentionCheck: the mention is
     * never silently dropped; the failure carries the human-review routing
     * (the LAMP product rule — review lives on leads and sends).
     */
    private static function flagged(array $verdict, array $failed, string $detail): array
    {
        $verdict['allowed'] = false;
        $verdict['failed_checks'] = $failed;
        $verdict['detail'] = $detail;
        $verdict['suggested_routing'] = 'human_review';
        $verdict['review_status'] = ReviewQueue::REVIEW_STATUS;
        return $verdict;
    }
}
