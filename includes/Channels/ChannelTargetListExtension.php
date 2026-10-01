<?php

declare(strict_types=1);

namespace App\Channels;

/**
 * ChannelTargetListExtension — the EXPLICIT extension mechanism for the
 * P5 channel target list.
 *
 * The core seed (ChannelTargetList::INDUSTRY_SEED) covers the LAMP-relevant
 * verticals only. Everything outside it — and outside THIS file — is a
 * deliberate miss (fail-closed), not a guess. There is intentionally NO
 * runtime registration API: an industry enters the target list only via a
 * committed, versioned row here, which makes every addition owner-reviewable
 * and auditable (the "lazy-fill" contract: the rest of the vertical
 * universe fills lazily, by human assertion, never by inference).
 *
 * HOW TO EXTEND (owner/maintainer):
 *   1. Add a row to EXTENSION_ROWS in the exact seed shape:
 *        'industry_slug' => [
 *            'label' => 'Human-readable industry label',
 *            'scope_default' => 'local|state|regional|national|online-global',
 *            'channels' => [
 *                'cold_email' => [
 *                    'strength' => 'high|medium|low',
 *                    'basis'    => one of ChannelTargetList::BASES keys (required),
 *                    'scope'    => optional facet (absent = inherits scope_default),
 *                    'notes'    => optional, <=280 chars,
 *                ],
 *            ],
 *        ],
 *   2. Bump EXTENSION_VERSION (date of the change). The version rides every
 *      retrieval result next to the core TABLE_VERSION, so any consumer can
 *      pin exactly what it saw.
 *   3. Run tests/channels/run_channel_tests.php — selfCheck() refuses
 *      half-specified edges (missing strength/basis, unknown scope, unknown
 *      channel key, notes >280 chars).
 *
 * CONFLICT RULE (deterministic, auditable): extension rows may ADD
 * industries and ADD channels to existing industries, but never OVERRIDE a
 * core row. A colliding (industry, channel) pair resolves to CORE and is
 * reported in the retrieval result's `extension_conflicts` list — loud, not
 * silent. To change a core edge, edit the core seed (schema-migration-grade
 * decision, owner sign-off).
 *
 * Out of scope for v1 (deliberately): runtime/uploaded extensions, DB-backed
 * industry tables, staleness lifecycle (12mo degrade / 24mo expire is a
 * v2 owner decision), and JEV ranking of channels (built only on measured
 * ordering pain, per the PI Channel KG pattern).
 */
final class ChannelTargetListExtension
{
    /** Extension-table version (date of last committed change). */
    public const EXTENSION_VERSION = '2026-10-01';

    /**
     * Extension rows in ChannelTargetList::INDUSTRY_SEED shape.
     * Ships EMPTY in v1 — the honest signal is "not asserted yet" (a miss),
     * not a thinly-sourced guess.
     *
     * @var array<string,array{label:string,scope_default:string,channels:array<string,array<string,mixed>>}>
     */
    public const EXTENSION_ROWS = [
        // Example (commented out — do not enable without owner sign-off):
        // 'dental_suppliers' => [
        //     'label' => 'Dental equipment suppliers',
        //     'scope_default' => 'national',
        //     'channels' => [
        //         'cold_email' => ['strength' => 'high', 'basis' => 'buyer_reachable'],
        //     ],
        // ],
    ];
}
