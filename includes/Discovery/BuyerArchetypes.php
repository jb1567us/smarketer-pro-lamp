<?php

declare(strict_types=1);

namespace App\Discovery;

/**
 * LAMP buyer-archetype table (D-P2: deterministic candidate construction).
 *
 * Ported seed from the PI Persona KG design
 * (~/workspace/jev-icp-determination/PERSONA_KG_SPEC.md §4), scoped to the
 * archetypes a B2B outbound buyer plausibly sells INTO. The full 49-archetype
 * persona KG is overkill for LAMP; this subset (25 buying roles) is the
 * candidate pool the deterministic template draws from.
 *
 * Every row is an ASSERTED archetype with a stable persona_id — never
 * generated, never inferred. Adding a row is an owner-reviewable taxonomy
 * decision; removing one never reuses its id (deprecate via status).
 *
 * Conventions (mirroring the PI spec):
 *   - id: stable slug; segment ids derive as 'seg_' . id.
 *   - scope_default: one of the shared 5-level scope ladder
 *     (local < state < regional < national < online-global).
 *   - strength: 'primary' = the role the industry's businesses actually
 *     sell to; 'secondary' = plausible but not the core buyer (evaluated
 *     after all eligible primaries, before truncation).
 *   - status: 'active' | 'deprecated'. Deprecated archetypes never
 *     participate in construction.
 *   - pains: controlled snake_case tags, buyer-side, observable.
 */
class BuyerArchetypes
{
    public const TABLE_VERSION = '1.0';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DEPRECATED = 'deprecated';

    public const STRENGTH_PRIMARY = 'primary';
    public const STRENGTH_SECONDARY = 'secondary';

    /**
     * The asserted archetype table. Fields per row:
     *   id, label, definition, pains (array), buying_context, scope_default,
     *   strength, status, notes (optional).
     */
    public const ARCHETYPES = [
        [
            'id' => 'bootstrapped_saas_founder',
            'label' => 'Bootstrapped SaaS founder',
            'definition' => 'Founder-owner of a sub-$1M ARR B2B SaaS still personally doing sales, support, and product.',
            'pains' => ['time_scarcity', 'churn', 'inconsistent_pipeline'],
            'buying_context' => "Removes a hat they're wearing; self-serve or low-touch, monthly, cancels fast; decides alone.",
            'scope_default' => 'online-global',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'lean_growth_lead',
            'label' => 'Lean growth lead',
            'definition' => 'First marketing/growth hire (or fractional) at a 5–30 person SaaS; owns a small budget and a conversion number.',
            'pains' => ['attribution_blindness', 'low_trial_conversion', 'founder_bottleneck'],
            'buying_context' => 'Free trial + clear ROI math; needs founder sign-off above ~$500/mo.',
            'scope_default' => 'online-global',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'technical_cofounder',
            'label' => 'Technical co-founder',
            'definition' => 'Engineering-led co-founder buying dev/infra/security tooling.',
            'pains' => ['toil', 'reliability_risk', 'integration_fragility'],
            'buying_context' => 'Docs-first, API-first; allergic to sales calls; decides on technical merit.',
            'scope_default' => 'online-global',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'agency_principal',
            'label' => 'Agency principal',
            'definition' => "Owner of a 5–30 person service agency selling delivery they can't always staff.",
            'pains' => ['fulfillment_bottleneck', 'feast_famine_pipeline', 'margin_compression'],
            'buying_context' => 'White-label capacity and lead flow; decides fast when the pipeline is thin.',
            'scope_default' => 'national',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'solo_consultant',
            'label' => 'Solo consultant',
            'definition' => 'Independent consultant selling expertise by the hour or project.',
            'pains' => ['pipeline_volatility', 'scope_creep', 'admin_overhead'],
            'buying_context' => 'Anything that productizes hours; price-sensitive, buys from peers.',
            'scope_default' => 'national',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'agency_ops_manager',
            'label' => 'Agency ops manager',
            'definition' => 'Ops hire at a 15+ person agency buying process.',
            'pains' => ['utilization_tracking', 'tool_sprawl', 'onboarding_drag'],
            'buying_context' => 'Systems; slow consensus decisions.',
            'scope_default' => 'national',
            'strength' => 'primary',
            'status' => 'active',
            'notes' => '[thin] asserted as thin coverage in the PI seed; kept, never padded.',
        ],
        [
            'id' => 'dtc_brand_owner',
            'label' => 'DTC brand owner',
            'definition' => 'Founder-operator of a 1–10 SKU Shopify/Woo brand doing $200K–$5M/yr.',
            'pains' => ['cac_inflation', 'inventory_cash_trap', 'platform_dependence'],
            'buying_context' => 'Growth levers that pay back in one quarter; decides alone or with a partner.',
            'scope_default' => 'online-global',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'course_creator',
            'label' => 'Course creator',
            'definition' => 'Sells cohort or evergreen courses ($200–$5K).',
            'pains' => ['launch_dependency', 'completion_rates', 'refund_rates'],
            'buying_context' => 'Launch and evergreen systems; buys from people they follow.',
            'scope_default' => 'online-global',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'business_coach',
            'label' => 'Business coach',
            'definition' => '1:1/group coach selling transformation packages.',
            'pains' => ['inconsistent_enrollment', 'delivery_time_trap', 'positioning_blur'],
            'buying_context' => 'Enrollment systems and authority assets; emotional buyer, fast when convinced.',
            'scope_default' => 'online-global',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'trade_business_owner',
            'label' => 'Trade business owner',
            'definition' => 'HVAC/plumbing/electrical/landscaping owner-operator with 2–15 techs.',
            'pains' => ['empty_schedule_gaps', 'no_show_techs', 'review_vulnerability', 'seasonality'],
            'buying_context' => 'Jobs — anything that fills the board this week; decides on the phone, distrusts contracts.',
            'scope_default' => 'local',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'trade_office_manager',
            'label' => 'Trade office manager',
            'definition' => 'The dispatcher/office manager who actually runs scheduling, invoicing, and follow-up.',
            'pains' => ['double_booking', 'missed_callbacks', 'paper_chaos'],
            'buying_context' => 'Software that survives tech turnover; influences the owner, rarely signs alone.',
            'scope_default' => 'local',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'solo_agent',
            'label' => 'Solo agent',
            'definition' => 'Individual buy/sell agent doing 8–30 deals/yr.',
            'pains' => ['lead_feast_famine', 'commission_compression', 'follow_up_fatigue'],
            'buying_context' => 'Lead flow and follow-up automation; buys emotionally, churns fast.',
            'scope_default' => 'state',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'team_lead',
            'label' => 'Team lead',
            'definition' => 'Runs a 5–25 agent team under a brokerage.',
            'pains' => ['agent_churn', 'lead_routing_fights', 'accountability_gap'],
            'buying_context' => 'Systems that make agents productive; decides quarterly.',
            'scope_default' => 'state',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'solo_practitioner',
            'label' => 'Solo practitioner',
            'definition' => 'Solo attorney (PI, family, criminal, estate).',
            'pains' => ['client_intake_leakage', 'admin_burden', 'feast_famine_cases'],
            'buying_context' => 'Intake and admin relief; risk-averse, slow to adopt.',
            'scope_default' => 'state',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'small_firm_partner',
            'label' => 'Small-firm partner',
            'definition' => 'Managing partner of a 2–10 attorney firm.',
            'pains' => ['associate_turnover', 'origination_imbalance', 'tech_debt'],
            'buying_context' => 'Origination and efficiency; decides with partners.',
            'scope_default' => 'state',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'solo_ria',
            'label' => 'Solo RIA',
            'definition' => 'Independent advisor/CFP with $20–150M AUM.',
            'pains' => ['client_acquisition_cost', 'compliance_burden', 'succession'],
            'buying_context' => 'Qualified prospects and compliance-safe marketing; deliberate, reference-driven.',
            'scope_default' => 'state',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'cpa_practice_owner',
            'label' => 'CPA practice owner',
            'definition' => 'Owner of a 2–15 person tax/accounting practice.',
            'pains' => ['tax_season_crunch', 'staff_shortage', 'commoditization'],
            'buying_context' => 'Capacity and advisory upsell; buys Q2–Q3, never in tax season.',
            'scope_default' => 'state',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'bookkeeping_firm_owner',
            'label' => 'Bookkeeping firm owner',
            'definition' => 'Owner of a bookkeeping/payroll practice serving SMBs.',
            'pains' => ['client_churn', 'low_arpu', 'tech_fragmentation'],
            'buying_context' => 'Efficiency and retention; price-sensitive, high volume.',
            'scope_default' => 'state',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'dentist_owner',
            'label' => 'Dentist-owner',
            'definition' => 'Owner-dentist of a 1–3 location practice.',
            'pains' => ['hygiene_chair_fill', 'case_acceptance', 'staff_turnover', 'insurance_squeeze'],
            'buying_context' => 'New-patient flow and case acceptance; decides alone, ROI-demanding.',
            'scope_default' => 'local',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'plant_owner',
            'label' => 'Plant owner',
            'definition' => 'Owner of a 10–100 person job shop or small manufacturer.',
            'pains' => ['quoting_bottlenecks', 'skilled_labor_shortage', 'customer_concentration'],
            'buying_context' => 'Capacity and quote velocity; relationship-driven, slow.',
            'scope_default' => 'national',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'mfg_ops_manager',
            'label' => 'Manufacturing ops manager',
            'definition' => 'Operations/plant manager (not the owner).',
            'pains' => ['downtime', 'quality_escapes', 'scheduling_chaos'],
            'buying_context' => 'Reliability tooling; needs owner/CFO sign-off for capex.',
            'scope_default' => 'national',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'fractional_exec',
            'label' => 'Fractional executive',
            'definition' => 'Fractional CFO/CMO/COO selling 1–3 day/week engagements.',
            'pains' => ['pipeline_lumpiness', 'positioning', 'proof_of_value'],
            'buying_context' => 'Authority and pipeline; buys from peers.',
            'scope_default' => 'national',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'independent_consultant',
            'label' => 'Independent consultant',
            'definition' => 'Solo consultant selling projects and retainers (advisory, not agency delivery).',
            'pains' => ['feast_famine', 'scope_creep', 'differentiation'],
            'buying_context' => 'Leverage and positioning; price-aware.',
            'scope_default' => 'national',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'boutique_partner',
            'label' => 'Boutique partner',
            'definition' => 'Partner at a 5–25 person consultancy.',
            'pains' => ['utilization', 'partner_origination_imbalance', 'talent_retention'],
            'buying_context' => 'Origination systems; consensus decisions.',
            'scope_default' => 'national',
            'strength' => 'primary',
            'status' => 'active',
        ],
        [
            'id' => 'nonprofit_ed',
            'label' => 'Nonprofit ED',
            'definition' => 'Executive director of a 2–20 staff nonprofit.',
            'pains' => ['donor_fatigue', 'grant_dependence', 'board_dynamics', 'staff_burnout'],
            'buying_context' => 'Fundraising capacity; board-influenced, slow, mission-filtered.',
            'scope_default' => 'national',
            'strength' => 'secondary',
            'status' => 'active',
            'notes' => 'Secondary: plausible B2B buyer but not the core outbound segment.',
        ],
    ];

    /**
     * Look up one archetype by persona_id. Returns null when unknown.
     */
    public static function get(string $personaId): ?array
    {
        foreach (self::ARCHETYPES as $row) {
            if (($row['id'] ?? null) === $personaId) {
                return $row;
            }
        }
        return null;
    }

    /**
     * All active archetypes, in definition order.
     *
     * @return array<int, array>
     */
    public static function active(): array
    {
        return array_values(array_filter(
            self::ARCHETYPES,
            static fn (array $row): bool => ($row['status'] ?? '') === self::STATUS_ACTIVE
        ));
    }

    /**
     * Stable persona ids, ascending — the canonical tiebreak order.
     *
     * @return array<int, string>
     */
    public static function ids(): array
    {
        $ids = [];
        foreach (self::ARCHETYPES as $row) {
            $ids[] = $row['id'];
        }
        sort($ids, SORT_STRING);
        return $ids;
    }
}
