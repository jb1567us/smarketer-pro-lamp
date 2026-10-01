<?php

declare(strict_types=1);

/**
 * Choice-(b) gate fixture: explicit day-zero buyer ICP for the LAMP ICP
 * fit-scoring calibration re-measurement (2026-09-29).
 *
 * THE BUYER (plain words): "Relay" — a B2B SaaS company selling an AI
 * sales-prospecting tool (lead data + AI-personalized outreach sequences)
 * at $249/user/month. They sell to B2B companies that run outbound sales
 * motions in-house: 20–500 employees (big enough for a sales team, small
 * enough to lack an entrenched enterprise stack), in the US/UK/Canada
 * (English-language sales motion, shared business hours), buying through
 * founders or sales leadership. Buying triggers are sales-team hiring
 * (SDR/AE postings), fresh funding (new GTM budget), expansion, sales
 * leadership changes, or a new outbound initiative. They do NOT sell to
 * consumer businesses, nonprofits/education, or to agencies whose business
 * is selling outbound lead-gen services (channel conflict — those firms
 * are the anti-persona, enforced by the keyword exclusion below).
 *
 * WHY THIS BUYER: chosen FIRST as a natural, realistic day-zero buyer of a
 * B2B lead-gen/outreach product — deliberately NOT engineered to fit the
 * 28 holdout companies. It overlaps the previous agency-to-SaaS test ICP
 * on several axes (both sell into B2B SaaS), so most frozen labels are
 * expected to be stable; the label table below records the honest deltas.
 *
 * HOW TO USE (run-6 hard rule): pass this array as $profileSnapshot to
 * ScoreLeadFitAction::score($lead, $legacyFallback, $profileSnapshot).
 * NEVER score the gate against targetProse() fallbacks and NEVER
 * reconstruct the profile from observed behavior — the run-6 phantom
 * failure mode. This fixture is the single source of truth for the gate
 * target.
 *
 * MECHANICS (matches the as-built toggle, commit f167d33):
 * - Five core dimensions at 20/20/20/20/20, all enabled.
 * - tech_stack present with enabled=false, weight=0 (default-off): it gets
 *   no scoring-prompt question (zero tokens) and is excluded from
 *   aggregation, so the five enabled weights renormalize to sum 100.
 * - The keyword exclusion fires the hard veto (fit=0, no API call) on the
 *   anti-persona, mirroring the CIENCE veto test from run 4.
 * - Thresholds 75/50 (unchanged).
 *
 * DO NOT COMMIT MODIFICATIONS WITHOUT OWNER REVIEW: this fixture defines
 * the calibration gate target. Changing it invalidates the gate.
 */

return [
    'id' => 0,
    'key' => 'day_zero_buyer_fixture',
    'dimensions' => [
        'company_size' => [
            'weight' => 20,
            'buyer_locked' => false,
            'enabled' => true,
            'target_config' => [
                'min_employees' => 20,
                'max_employees' => 500,
            ],
        ],
        'industry_fit' => [
            'weight' => 20,
            'buyer_locked' => false,
            'enabled' => true,
            'target_config' => [
                'include' => [
                    'B2B SaaS',
                    'sales technology',
                    'marketing technology',
                ],
                'exclude' => [
                    'marketing agencies',
                    'lead generation agencies',
                    'outsourced SDR services',
                    'consumer retail',
                    'consumer food service',
                    'nonprofit',
                    'higher education',
                    'financial services',
                    'aviation',
                ],
            ],
        ],
        'target_title' => [
            'weight' => 20,
            'buyer_locked' => false,
            'enabled' => true,
            'target_config' => [
                'titles' => [
                    'Founder',
                    'CEO',
                    'VP Sales',
                    'Head of Sales',
                    'Sales Director',
                    'CRO',
                    'VP Revenue',
                    'Chief Revenue Officer',
                ],
            ],
        ],
        'geography' => [
            'weight' => 20,
            'buyer_locked' => false,
            'enabled' => true,
            'target_config' => [
                'countries' => [
                    'United States',
                    'United Kingdom',
                    'Canada',
                ],
                'regions' => [],
            ],
        ],
        'trigger_signals' => [
            'weight' => 20,
            'buyer_locked' => false,
            'enabled' => true,
            'target_config' => [
                'signals' => [
                    'sales team hiring (SDR/AE job postings)',
                    'recent funding round',
                    'new office or market expansion',
                    'sales leadership change',
                    'new outbound sales initiative',
                ],
            ],
        ],
        'tech_stack' => [
            'weight' => 0,
            'buyer_locked' => false,
            'enabled' => false,
            'target_config' => [
                'tools' => [],
            ],
        ],
    ],
    'weights' => [
        'company_size' => 20,
        'industry_fit' => 20,
        'target_title' => 20,
        'geography' => 20,
        'trigger_signals' => 20,
        'tech_stack' => 0,
    ],
    'exclusions' => [
        [
            'exclusion_type' => 'keyword',
            'value' => 'lead generation agency',
            'note' => 'anti-persona: outsourced SDR/appointment-setting services (channel conflict)',
        ],
    ],
    'thresholds' => [
        'qualify' => 75,
        'review' => 50,
    ],
];
