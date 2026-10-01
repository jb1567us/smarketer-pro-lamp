<?php

declare(strict_types=1);

namespace App\Evidence;

use App\ReviewQueue;

/**
 * Mention-validity predicate: "may this claim appear in outreach copy?" (D2-P1).
 *
 * Deterministic, model-free, DB-free. A claim is servable iff ALL of:
 *   1. known source      — the source is registered in the buyer's catalog
 *                          (unknown sources cannot be cited)
 *   2. authority tier ≤3 — SourceAuthority::grade() (tier 4 never servable,
 *                          canonical hard bar)
 *   3. fresh proof_of    — the evidence the claim rests on is within the
 *                          recency bound (see RECENCY_BOUND_MONTHS)
 *   4. scope covers      — the source's coverage scope covers the claim's
 *                          target scope on the shared 5-level ladder
 *
 * LAMP-CRITICAL DIFFERENCE from PI's DP7: PI drops failing mentions
 * silently (it is fully automatic). LAMP keeps human review on outbound
 * sends, so a failing claim is NEVER silently dropped: the failure output
 * routes to the human review queue via flagPayload() / notesMarker(),
 * carrying ReviewQueue::REVIEW_STATUS ('Needs Review') semantics. The human
 * — not the predicate — decides what happens to the flagged claim.
 *
 * Fail-closed: malformed mentions, unknown scope values, and unparseable
 * dates all evaluate to NOT servable (flagged), never to an accidental pass.
 *
 * The 'known_source' input is a boolean the caller supplies from its own
 * source catalog — LAMP has no KG-backed source registry yet (v1); the
 * catalog is the buyer's asserted fact set (factsheet-wins rule). The JEV
 * Noul remainder (relevance judgment on predicate-passing mentions, PI's
 * DP7 remainder) is explicitly out of scope for this round.
 */
class MentionValidity
{
    /**
     * Shared 5-level scope ladder, narrow → broad (PI backlog §12b /
     * KG shared ladder). A source covers a target when its level is
     * >= the target's: national data can back a local claim, but a local
     * newsletter cannot back a national claim. Named-geo intersection
     * (e.g. source covers "Texas", target in Austin) is a later extension —
     * v1 is the ladder only.
     */
    public const SCOPES = ['local', 'state', 'regional', 'national', 'online-global'];

    /**
     * Recency bound for proof_of freshness, in months.
     * [ASSUMPTION — owner can veto.] Chosen as 24 to match PI DP7's
     * explicit `proof_of ≤24mo` pre-render predicate. Alternatives: 12
     * (KG "fresh" bucket) or 36 (KG "not stale" bar). Anything older than
     * this is flagged as stale evidence — for human judgment, not dropped.
     */
    public const RECENCY_BOUND_MONTHS = 24;

    // Failed-check codes (stable; surfaced in flag payloads and notes).
    public const FAIL_UNKNOWN_SOURCE = 'unknown_source';
    public const FAIL_TIER_4         = 'tier_4_never_servable';
    public const FAIL_MISSING_PROOF  = 'missing_evidence';
    public const FAIL_STALE_PROOF    = 'stale_evidence';
    public const FAIL_SCOPE           = 'scope_mismatch';
    public const FAIL_INVALID_INPUT   = 'invalid_input';

    /** Review-queue item kind for flagged claims. */
    public const FLAG_KIND = 'evidence_flag';

    /**
     * Evaluate a mention for outreach-copy servability.
     *
     * $mention shape:
     *   claim        string   the proposed copy text (used in flag payloads)
     *   source_id    string   canonical source slug
     *   source       array    source attributes for SourceAuthority::explain()
     *   known_source bool     registered in the buyer's source catalog
     *   proof_of     array    ['evidence_ts' => 'YYYY-MM-DD', ...]
     *   source_scope string   one of SCOPES (the source's coverage)
     *   target_scope string   one of SCOPES (the claim's target)
     *   as_of        string   'YYYY-MM-DD' reference date (default: today;
     *                         injectable for deterministic tests)
     *
     * @return array{allowed:bool, tier:?int, tier_rule:?string,
     *              checks:array<array{check:string,passed:bool,detail:string}>,
     *              failed_checks:array<string>}
     */
    public static function evaluate(array $mention): array
    {
        try {
            return self::evaluateInner($mention);
        } catch (\Throwable $e) {
            // Fail closed: an unexpected evaluation error is never a pass.
            return [
                'allowed' => false,
                'tier' => null,
                'tier_rule' => null,
                'checks' => [
                    self::check(self::FAIL_INVALID_INPUT, false, 'evaluation error: ' . $e->getMessage()),
                ],
                'failed_checks' => [self::FAIL_INVALID_INPUT],
            ];
        }
    }

    /**
     * Build the human-review-queue item for a FAILING verdict.
     *
     * Never silently drop: the claim stays attached to the flag so the
     * reviewer sees exactly what was proposed and why it failed. Carries
     * ReviewQueue::REVIEW_STATUS ('Needs Review') — the same status value
     * leads get from the scorer — so downstream review surfaces treat
     * evidence flags and lead flags uniformly.
     *
     * Callers (a later draft-reviewer port) append notesMarker() to the
     * relevant record's notes and/or write the payload to the review
     * surface; this class itself performs no writes (DB-free).
     *
     * @throws \InvalidArgumentException when given a passing verdict —
     *         nothing to flag means a caller integration bug; loud, not silent.
     */
    public static function flagPayload(array $verdict, array $mention): array
    {
        if (($verdict['allowed'] ?? false) === true) {
            throw new \InvalidArgumentException(
                'flagPayload() called on a passing mention-validity verdict; nothing to flag.'
            );
        }
        $failed = $verdict['failed_checks'] ?? [self::FAIL_INVALID_INPUT];
        return [
            'kind' => self::FLAG_KIND,
            'review_status' => ReviewQueue::REVIEW_STATUS,
            'claim' => (string)($mention['claim'] ?? ''),
            'source_id' => (string)($mention['source_id'] ?? ''),
            'graded_tier' => $verdict['tier'] ?? null,
            'tier_rule' => $verdict['tier_rule'] ?? null,
            'failed_checks' => array_values($failed),
            'check_details' => $verdict['checks'] ?? [],
            'explanation' => self::explainFailure($verdict, $mention),
            'suggested_action' => 'Human reviews the flagged claim and its source '
                . 'before it may appear in outreach copy; approve with a better '
                . 'source, revise the claim, or drop it explicitly.',
        ];
    }

    /**
     * Append-style notes marker for the flagged claim, mirroring
     * QualifyLeadAction::notesMarker() conventions (markers are only ever
     * appended; the review surface reads the latest).
     */
    public static function notesMarker(array $verdict, array $mention, ?string $date = null): string
    {
        $date ??= date('Y-m-d');
        $payload = self::flagPayload($verdict, $mention);
        $tier = $payload['graded_tier'];
        $tierText = $tier === null ? 'ungraded' : 'tier ' . $tier;
        $lines = [
            '',
            '',
            "[EvidenceFlag {$date}]: claim flagged — NOT servable in outreach copy ({$tierText}).",
            'Failed: ' . implode(', ', $payload['failed_checks']),
            'Review: ' . ReviewQueue::REVIEW_STATUS,
        ];
        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private static function evaluateInner(array $mention): array
    {
        $checks = [];
        $ok = true;

        $push = static function (string $code, bool $passed, string $detail) use (&$checks, &$ok): void {
            $checks[] = self::check($code, $passed, $detail);
            if (!$passed) {
                $ok = false;
            }
        };

        // Required structural fields; missing = invalid input (fail closed).
        $sourceId = $mention['source_id'] ?? null;
        $sourceAttrs = $mention['source'] ?? null;
        $proofOf = $mention['proof_of'] ?? null;
        $sourceScope = $mention['source_scope'] ?? null;
        $targetScope = $mention['target_scope'] ?? null;
        if (
            !is_string($sourceId) || $sourceId === ''
            || !is_array($sourceAttrs)
            || !is_array($proofOf)
            || !is_string($sourceScope) || !is_string($targetScope)
        ) {
            $push(self::FAIL_INVALID_INPUT, false, 'mention is missing required fields '
                . '(source_id, source, proof_of, source_scope, target_scope).');
            return self::verdict(false, null, null, $checks);
        }

        // 1. Known source.
        $known = ($mention['known_source'] ?? false) === true;
        $push(
            self::FAIL_UNKNOWN_SOURCE,
            $known,
            $known
                ? "source '{$sourceId}' is registered in the catalog."
                : "source '{$sourceId}' is not registered; unregistered sources cannot be cited."
        );

        // 2. Authority tier ≤ 3.
        $explained = SourceAuthority::explain($sourceAttrs);
        $tier = $explained['tier'];
        $servable = SourceAuthority::isServableTier($tier);
        $push(
            self::FAIL_TIER_4,
            $servable,
            $servable
                ? "graded {$tier} (" . SourceAuthority::tierLabel($tier) . '; rule: ' . $explained['rule'] . ').'
                : "graded tier {$tier} via rule '{$explained['rule']}' — "
                    . SourceAuthority::tierLabel(4) . '; never servable.'
        );

        // 3. Fresh proof_of: present + parseable + within the recency bound.
        $evidenceTs = $proofOf['evidence_ts'] ?? null;
        $asOf = $mention['as_of'] ?? date('Y-m-d');
        $monthsOld = self::monthsOld($evidenceTs, $asOf);
        if ($monthsOld === null) {
            $push(
                self::FAIL_MISSING_PROOF,
                false,
                'proof_of carries no usable evidence_ts (missing, unparseable, or future-dated); '
                    . 'a claim with no dated evidence never ships.'
            );
        } else {
            $fresh = $monthsOld <= self::RECENCY_BOUND_MONTHS;
            $push(
                self::FAIL_STALE_PROOF,
                $fresh,
                $fresh
                    ? "evidence_ts {$evidenceTs} is {$monthsOld}mo old (bound: " . self::RECENCY_BOUND_MONTHS . 'mo).'
                    : "evidence_ts {$evidenceTs} is {$monthsOld}mo old — older than the "
                        . self::RECENCY_BOUND_MONTHS . 'mo bound; flagged for human judgment.'
            );
        }

        // 4. Scope covers the target.
        $covers = self::scopeCovers($sourceScope, $targetScope);
        $push(
            self::FAIL_SCOPE,
            $covers,
            $covers
                ? "source scope '{$sourceScope}' covers target scope '{$targetScope}'."
                : "source scope '{$sourceScope}' does not cover target scope '{$targetScope}' "
                    . '(coverage requires source scope >= target scope on the ladder: '
                    . implode(' < ', self::SCOPES) . ').'
        );

        return self::verdict($ok, $tier, $explained['rule'], $checks);
    }

    private static function check(string $code, bool $passed, string $detail): array
    {
        return ['check' => $code, 'passed' => $passed, 'detail' => $detail];
    }

    private static function verdict(bool $allowed, ?int $tier, ?string $rule, array $checks): array
    {
        $failed = [];
        foreach ($checks as $c) {
            if (!$c['passed']) {
                $failed[] = $c['check'];
            }
        }
        return [
            'allowed' => $allowed,
            'tier' => $tier,
            'tier_rule' => $rule,
            'checks' => $checks,
            'failed_checks' => $failed,
        ];
    }

    /**
     * Whether $sourceScope covers $targetScope (source level >= target level).
     * Unknown scope values fail closed (do not cover).
     */
    public static function scopeCovers(string $sourceScope, string $targetScope): bool
    {
        $sourceLevel = array_search($sourceScope, self::SCOPES, true);
        $targetLevel = array_search($targetScope, self::SCOPES, true);
        if ($sourceLevel === false || $targetLevel === false) {
            return false;
        }
        return $sourceLevel >= $targetLevel;
    }

    /**
     * Whole months between $evidenceTs and $asOf (both 'YYYY-MM-DD').
     * Returns null for missing/unparseable dates or evidence_ts in the
     * future — a claim with no usable evidence date never passes.
     */
    public static function monthsOld(mixed $evidenceTs, string $asOf): ?int
    {
        if (!is_string($evidenceTs) || $evidenceTs === '') {
            return null;
        }
        try {
            $ev = new \DateTimeImmutable($evidenceTs);
            $ref = new \DateTimeImmutable($asOf);
        } catch (\Throwable) {
            return null;
        }
        if ($ev > $ref) {
            return null;
        }
        $months = ((int)$ref->format('Y') - (int)$ev->format('Y')) * 12
            + ((int)$ref->format('m') - (int)$ev->format('m'));
        if ((int)$ref->format('d') < (int)$ev->format('d')) {
            $months--;
        }
        return max(0, $months);
    }

    /**
     * One-paragraph human explanation of a failing verdict (review-queue surface).
     */
    private static function explainFailure(array $verdict, array $mention): string
    {
        $claim = trim((string)($mention['claim'] ?? ''));
        $sourceId = (string)($mention['source_id'] ?? 'unknown source');
        $parts = [];
        foreach ($verdict['checks'] ?? [] as $c) {
            if (!($c['passed'] ?? true)) {
                $parts[] = $c['detail'] ?? $c['check'];
            }
        }
        $why = $parts !== [] ? implode(' ', $parts) : 'failing check details unavailable.';
        $quoted = $claim !== '' ? " Proposed claim: \"{$claim}\"" : '';
        return "Claim citing '{$sourceId}' is not servable in outreach copy: {$why}{$quoted}";
    }
}
