<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jev\DecisionTier;
use App\Jev\JevProvider;
use App\Exceptions\OutreachException;

/**
 * EnrichSufficiencyAction — Phase 4 decision point: after enrichment, decide
 * whether the lead data is complete enough to proceed to qualification
 * (re-enrich vs proceed), using data-completeness criteria.
 *
 * Python behavior preserved (smarketer-pro: src/nodes/domain/enrichment.py
 * + src/enrichment_manager.py; LAMP: EnrichLeadAction): enrichment is a
 * scrape-and-analyze step whose usable outputs are industry and pain points
 * plus the lead's identity fields (company, website, contact). Qualification
 * consumes exactly those fields (QualifyLeadAction's context), so this gate
 * asks whether what enrichment produced is sufficient for that consumer.
 *
 * Completeness criteria (6 fields):
 *   1. An [Enrichment YYYY-MM-DD] notes block exists (EnrichLeadAction ran).
 *   2. company_name is present.
 *   3. website is present.
 *   4. contact_name is present.
 *   5. Industry was inferred (block value is not 'Unknown' / empty).
 *   6. Pain points were identified (block value is not 'None identified' / empty).
 * Fields 2-3 are hard must-haves: without company + website the qualifier
 * cannot run, so their absence forces re-enrich regardless of the score.
 * Industry and pain points are enrichment's actual deliverables: when BOTH
 * are missing, enrichment produced nothing usable and the verdict is
 * re-enrich even if the raw fraction clears the threshold.
 *
 * Re-enrich loop guard: notes carry at most MAX_REENRICH enrichment blocks
 * (each re-enrich replaces the previous block per EnrichLeadAction's
 * replace-not-duplicate rule, so the attempt count is tracked separately in
 * lead notes as "[Re-enrich N]"). After the cap, the decision proceeds with
 * a note rather than looping forever.
 *
 * Modes (same contract as every other JEV decision point):
 *   off    — rule-based verdict returned; nothing is enqueued.
 *   shadow — rule-based verdict returned; JEV runs and the pair is logged to
 *            the shadow log by DecisionTier::decide. Zero behavior change.
 *   live   — JEV verdict returned; a re_enrich decision enqueues a fresh
 *            'Enrich' task (lead returns through EnrichLeadAction) until
 *            MAX_REENRICH is reached, then it proceeds with a note.
 *
 * Fail-soft: a JEV error/timeout/low-confidence decision falls back to the
 * rule-based verdict (which is deterministic and marked low-confidence,
 * source 'rules'). The decision is advisory — it never throws.
 */
class EnrichSufficiencyAction extends AbstractAction
{
    public const DECISION = 'enrich_sufficiency.decide';

    /** Hard timeout for the batched sufficiency call (plan: <=8s). */
    public const TIMEOUT_S = 8;

    /** Completeness threshold: 4 of 6 fields present (with hard must-haves). */
    public const COMPLETENESS_THRESHOLD = 4;

    /** Max re-enrichments before proceeding anyway (loop guard). */
    public const MAX_REENRICH = 2;

    /**
     * ActionInterface contract. Evaluates sufficiency and, in live mode
     * only, enqueues a re-enrich task when the verdict says so. Always
     * returns true (the decision is advisory; failures never throw).
     */
    public function execute(int $leadId): bool
    {
        try {
            $verdict = $this->evaluate($leadId);
        } catch (\Throwable $e) {
            error_log('[EnrichSufficiencyAction] evaluate failed: ' . $e->getMessage());
            return true; // Fail-soft: a broken gate must not stall the pipeline.
        }

        $live = DecisionTier::mode() === 'live';
        if ($live && ($verdict['decision'] ?? '') === 're_enrich') {
            $this->enqueueReEnrich($leadId, (int)($verdict['attempts'] ?? 0) + 1);
        }
        // In off/shadow modes the verdict is advisory only: zero behavior
        // change vs. today (enrich -> qualify, always).
        return true;
    }

    /**
     * Evaluate enrichment sufficiency for a lead.
     *
     * @return array{decision:string,completeness:float,missing:string[],
     *               attempts:int,confidence:float,source:string,
     *               latency_ms:int,note?:string}
     *   - decision: 'proceed' | 're_enrich'
     *   - completeness: 0.0-1.0 fraction of the 6 criteria met
     *   - missing: criterion keys that failed
     *   - attempts: prior re-enrich attempts (from [Re-enrich N] markers)
     *   - source: 'rules' | 'jev'; rules results are low-confidence (0.0)
     */
    public function evaluate(int $leadId): array
    {
        $lead = $this->loadLead($leadId);
        if ($lead === null) {
            throw new OutreachException("Lead ID {$leadId} not found.");
        }

        $check = $this->ruleCheck($lead);

        // Fallback (off mode / JEV failure): the deterministic rule verdict,
        // marked low-confidence per the Phase 4 contract.
        $fallback = fn(): array => [
            'decision'     => $check['decision'],
            'completeness' => $check['completeness'],
            'missing'      => $check['missing'],
            'attempts'     => $check['attempts'],
            'confidence'   => 0.0,
            'source'       => 'rules',
        ];

        $state = [
            'lead_id'       => $leadId,
            'company_name'  => (string)($lead['company_name'] ?? ''),
            'website'       => (string)($lead['website'] ?? ''),
            'contact_name'  => (string)($lead['contact_name'] ?? ''),
            'enrichment_block' => mb_substr($this->enrichmentBlock($lead), 0, 1500),
            'criteria_met'  => $check['present'],
            'criteria_missing' => $check['missing'],
            'reenrich_attempts' => $check['attempts'],
        ];

        $questions = [
            'sufficient' => JevProvider::noulQuestion(
                'The enriched data is complete enough to qualify this lead: ' .
                'company identity, website, contact, industry, and pain ' .
                'points are present and specific, with no placeholder or ' .
                "'Unknown' values."
            ),
            'completeness' => JevProvider::scoreQuestion(
                'Overall completeness of the enriched lead data, from empty ' .
                'to fully populated.',
                self::completenessCriteria()
            ),
        ];

        $t0 = microtime(true);
        try {
            $raw = DecisionTier::decide(
                self::DECISION,
                $state,
                $questions,
                $fallback,
                fn($mixed) => $this->extractDecision($mixed),
                fn($jv, $lv) => $jv === $lv,
                self::TIMEOUT_S
            );
        } catch (\Throwable $e) {
            error_log('[EnrichSufficiencyAction] DecisionTier::decide threw: ' . $e->getMessage());
            $raw = $fallback();
        }
        $latencyMs = (int)((microtime(true) - $t0) * 1000);

        $verdict = $this->normalize($raw, $check);
        $verdict['latency_ms'] = $latencyMs;
        return $verdict;
    }

    // ------------------------------------------------------------------
    // Rule-based completeness check (the JEV-off path)
    // ------------------------------------------------------------------

    /**
     * @return array{decision:string,completeness:float,present:string[],
     *               missing:string[],attempts:int}
     */
    public function ruleCheck(array $lead): array
    {
        $notes = (string)($lead['notes'] ?? '');
        $present = [];
        $missing = [];

        $block = $this->enrichmentBlock($lead);
        if ($block !== '') {
            $present[] = 'enrichment_block';
        } else {
            $missing[] = 'enrichment_block';
        }

        foreach (['company_name' => 'company', 'website' => 'website', 'contact_name' => 'contact'] as $field => $label) {
            $val = trim((string)($lead[$field] ?? ''));
            if ($val !== '' && strtolower($val) !== 'unknown') {
                $present[] = $label;
            } else {
                $missing[] = $label;
            }
        }

        if ($block !== '') {
            $industry = $this->blockField($block, 'Industry');
            if ($industry !== '' && strtolower($industry) !== 'unknown') {
                $present[] = 'industry';
            } else {
                $missing[] = 'industry';
            }
            $pains = $this->blockField($block, 'Pain Points');
            if ($pains !== '' && strtolower($pains) !== 'none identified') {
                $present[] = 'pain_points';
            } else {
                $missing[] = 'pain_points';
            }
        } else {
            $missing[] = 'industry';
            $missing[] = 'pain_points';
        }

        $total = count($present) + count($missing); // always 6
        $completeness = $total > 0 ? count($present) / $total : 0.0;

        $attempts = 0;
        if (preg_match_all('/\\[Re-enrich (\\d+)\\]/', $notes, $m)) {
            $attempts = (int)max($m[1]);
        }

        // Hard must-haves: company + website are the qualifier's context —
        // without them qualification cannot run, so always re-enrich.
        // Enrichment's deliverables are industry + pain points: when BOTH
        // are missing, enrichment produced nothing usable — re-enrich even
        // if the raw fraction clears the threshold.
        $hardMissing = array_intersect($missing, ['company', 'website']);
        $deliverablesMissing = !in_array('industry', $present, true)
            && !in_array('pain_points', $present, true);
        $decision = 'proceed';
        if ($hardMissing !== [] || $deliverablesMissing || count($present) < self::COMPLETENESS_THRESHOLD) {
            $decision = $attempts >= self::MAX_REENRICH ? 'proceed' : 're_enrich';
        }

        return [
            'decision'     => $decision,
            'completeness' => round($completeness, 3),
            'present'      => $present,
            'missing'      => $missing,
            'attempts'     => $attempts,
        ];
    }

    /** Latest [Enrichment YYYY-MM-DD] notes block, or '' when none. */
    private function enrichmentBlock(array $lead): string
    {
        $notes = (string)($lead['notes'] ?? '');
        if (preg_match('/\\[Enrichment \\d{4}-\\d{2}-\\d{2}\\]:(.*?)(?=\\n\\n\\[|\\z)/s', $notes, $m)) {
            return trim($m[0]);
        }
        return '';
    }

    /** Pull a "Label: value" line out of an enrichment block. */
    private function blockField(string $block, string $label): string
    {
        if (preg_match('/^' . preg_quote($label, '/') . ':\\s*(.+)$/mi', $block, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    // ------------------------------------------------------------------
    // JEV normalization
    // ------------------------------------------------------------------

    private static function completenessCriteria(): array
    {
        return [
            'Empty: no enrichment data at all; every criterion missing.',
            'Sparse: one or two criteria met; hard must-haves missing.',
            'Partial: about half the criteria met; usable but thin.',
            'Solid: most criteria met; minor gaps only.',
            'Complete: every criterion met with specific, usable values.',
        ];
    }

    /** Extract the comparable decision from either a JEV answer map or a normalized verdict. */
    private function extractDecision($mixed): string
    {
        if (is_array($mixed) && isset($mixed['sufficient']) && is_array($mixed['sufficient'])) {
            return ((float)($mixed['sufficient']['noul'] ?? 0)) >= 0.5 ? 'proceed' : 're_enrich';
        }
        return (string)($mixed['decision'] ?? 'proceed');
    }

    /**
     * Normalize either raw JEV answers or an already-normalized verdict
     * (off/shadow modes, JEV errors) into one verdict shape. The re-enrich
     * loop guard applies to BOTH sources: a JEV re_enrich verdict past
     * MAX_REENRICH becomes proceed-with-note (no infinite loops).
     */
    private function normalize($raw, array $check): array
    {
        if (is_array($raw) && array_key_exists('decision', $raw)
            && !is_array($raw['decision'] ?? null)) {
            return $raw + ['note' => $this->ruleNote($raw)];
        }

        $sufficient = is_array($raw['sufficient'] ?? null) ? $raw['sufficient'] : [];
        $score = is_array($raw['completeness'] ?? null) ? $raw['completeness'] : [];
        $decision = ((float)($sufficient['noul'] ?? 0)) >= 0.5 ? 'proceed' : 're_enrich';
        if ($decision === 're_enrich' && $check['attempts'] >= self::MAX_REENRICH) {
            $decision = 'proceed'; // loop guard applies to JEV verdicts too
        }
        $pct = JevProvider::scoreToPercent((float)($score['score'] ?? 0), count(self::completenessCriteria()));
        return [
            'decision'     => $decision,
            'completeness' => round($pct / 100, 3),
            'missing'      => $check['missing'],
            'attempts'     => $check['attempts'],
            'confidence'   => (float)($sufficient['confidence'] ?? 0),
            'source'       => 'jev',
            'note'         => sprintf(
                'JEV %s (confidence %.2f, completeness %.0f/100, %d missing: %s).',
                $decision === 'proceed' ? 'would PROCEED' : 'would RE-ENRICH',
                (float)($sufficient['confidence'] ?? 0),
                $pct,
                count($check['missing']),
                implode(', ', $check['missing']) ?: 'none'
            ),
        ];
    }

    private function ruleNote(array $verdict): string
    {
        $src = $verdict['source'] ?? 'rules';
        return sprintf(
            'Rule-based verdict: %s (completeness %.0f%%, missing: %s, attempts %d; source=%s, confidence 0.0).',
            strtoupper((string)($verdict['decision'] ?? 'proceed')),
            (float)($verdict['completeness'] ?? 0) * 100,
            implode(', ', $verdict['missing'] ?? []) ?: 'none',
            (int)($verdict['attempts'] ?? 0),
            $src
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** Enqueue a follow-up 'Enrich' task (live mode only); stamps [Re-enrich N] on the notes. */
    private function enqueueReEnrich(int $leadId, int $attempt): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO task_queue (lead_id, task_type, status, payload, scheduled_at) " .
                "VALUES (?, 'Enrich', 'Pending', ?, NOW())"
            );
            $stmt->execute([$leadId, json_encode(['reason' => 'enrichment-insufficient', 'attempt' => $attempt])]);

            $lead = $this->loadLead($leadId);
            $notes = (string)($lead['notes'] ?? '');
            $notes .= ($notes !== '' ? "\n\n" : '') . "[Re-enrich {$attempt}]: enrichment insufficient; re-running enrichment.";
            $upd = $this->pdo->prepare("UPDATE leads SET notes = ? WHERE id = ?");
            $upd->execute([$notes, $leadId]);
        } catch (\Throwable $e) {
            error_log('[EnrichSufficiencyAction] enqueueReEnrich failed: ' . $e->getMessage());
        }
    }

    private function loadLead(int $leadId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM leads WHERE id = ? LIMIT 1");
        $stmt->execute([$leadId]);
        $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
        return $row === false || $row === null ? null : $row;
    }
}
