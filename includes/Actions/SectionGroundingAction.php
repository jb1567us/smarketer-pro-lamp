<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\OutreachException;
use App\Jev\DecisionTier;
use App\Jev\JevProvider;

/**
 * SectionGroundingAction — P3 port of PI's DP2/DP6 pattern (per-section
 * grounding QA) for the LAMP draft reviewer.
 *
 * LAMP's ReviewDraftAction judges a draft whole; this action scores the
 * draft PER SECTION against the lead's evidence set (the LAMP analog of
 * PI's factsheet: company, contact, website, target persona, campaign,
 * enriched notes) and performs ONE anchored regeneration attempt on
 * failing sections only.
 *
 * Layers, in order:
 *   1. Deterministic checks (always run): section present? unreplaced
 *      placeholders? lead-fact anchors found? A placeholder is an
 *      automatic section fail — judgment never rescues it.
 *   2. JEV Score per section (P(section's claims are supported by the
 *      lead facts)) + Noul section_safe (no risky/ungrounded claims),
 *      one batched System One call, routed through DecisionTier with the
 *      same fail-closed contract as every other decision point.
 *   3. At most ONE anchored regeneration (generative LLM — prose is
 *      never-Jev, boundary #1) on failing sections only, with the anchor
 *      instruction pointing at the specific lead-fact evidence.
 *
 * The draft then goes to the HUMAN review queue regardless: this action
 * never sets status 'approved' and never feeds a send path. Its output is
 * an evidence trail (per-section scores + regen outcome) appended to the
 * draft's reviewer_notes, which the review queue surfaces to the human.
 *
 * Fail-closed (charter: JEV off by default, zero production behavior
 * change when JEV/LLM is unavailable):
 *   - off mode: deterministic checks only; draft flagged as
 *     un-regenerated; status flow untouched.
 *   - shadow mode: JEV verdict logged by DecisionTier; legacy
 *     (deterministic) verdict used; no regeneration.
 *   - live mode + JEV error/timeout/low-confidence: deterministic
 *     verdicts, sections marked unscored, no regeneration (anchored
 *     regen requires a real live JEV verdict — even a deterministic
 *     placeholder fail does not authorize it).
 *   - live mode + LLM unavailable: no regeneration attempt; the failing
 *     sections are reported as un-regenerated.
 *   - migration not applied (grounding_regen column absent): no
 *     regeneration is attempted, because the once-only marker cannot be
 *     persisted; the draft is reported as un-regenerated.
 * ReviewDraftAction wraps qaPass() in try/catch, so even a bug here can
 * never change an existing review outcome.
 */
class SectionGroundingAction extends AbstractAction
{
    /** New decision point, following the existing naming convention. */
    public const DECISION = 'draft_review.section_grounding';

    /** Hard timeout for the batched grounding call (plan 2.2: <=8s). */
    public const TIMEOUT_S = 8;

    /** Below-threshold JEV grounding score (0-100), mirroring jev_min_confidence 0.65. */
    public const SECTION_SCORE_THRESHOLD = 65.0;

    /** section_safe Noul floor. */
    public const SAFE_NOUL_THRESHOLD = 0.5;

    /** Exactly one anchored regeneration attempt per draft lineage, ever. */
    public const MAX_ANCHOR_REGENS = 1;

    /** @var bool|null Instance-cached result of the grounding_regen column probe. */
    private ?bool $regenColumn = null;

    /**
     * ActionInterface contract: run the QA pass over the lead's newest
     * reviewable draft (pending_review or needs_human). Returns false
     * when there is no such draft. The draft is human-queue bound, so
     * the one-shot anchored regeneration rules apply exactly as in
     * qaPass().
     */
    public function execute(int $leadId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM drafts WHERE lead_id = ? AND status IN ('pending_review','needs_human') ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$leadId]);
        $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $draftId = (int)$row['id'];
        $this->qaPass($draftId, ['outcome' => 'needs_human', 'draft_id' => $draftId]);
        return true;
    }

    /**
     * Full QA pass for one draft. Runs deterministic checks, the batched
     * JEV grounding call (when the tier allows), and at most one anchored
     * regeneration of failing sections (live mode only, never on approved
     * drafts). Appends the evidence trail to the final draft's
     * reviewer_notes and returns the (possibly updated) review result.
     *
     * @param int   $draftId      The draft to QA.
     * @param array $reviewResult The ReviewDraftAction result array
     *                            (['outcome'=>..., 'draft_id'=>..., ...]).
     * @return array The review result, with draft_id pointed at the
     *               regenerated draft when one was created, plus
     *               ['section_grounding' => <report>].
     */
    public function qaPass(int $draftId, array $reviewResult = []): array
    {
        $draft = $this->loadDraft($draftId);
        $evidence = $this->buildEvidence($draft);
        $sections = $this->splitSections($draft['subject'], $draft['body']);
        $scored = array_values(array_filter(
            $sections,
            static fn(array $s): bool => trim($s['text']) !== ''
        ));

        if ($scored === []) {
            $report = [
                'draft_id' => $draftId,
                'mode' => DecisionTier::mode(),
                'source' => 'deterministic',
                'sections' => [],
                'regen' => ['attempted' => false, 'new_draft_id' => null, 'reason' => 'no-scorable-sections'],
            ];
            $this->appendEvidenceTrail($draftId, $report);
            $result = $reviewResult;
            $result['draft_id'] = $draftId;
            $result['section_grounding'] = $report;
            return $result;
        }

        $state = [
            'lead_facts' => $evidence,
            'sections' => array_map(
                static fn(int $i, array $s): array => [
                    'index' => $i,
                    'key' => $s['key'],
                    'text' => mb_substr($s['text'], 0, 1500),
                ],
                array_keys($scored),
                $scored
            ),
        ];
        $questions = $this->groundingQuestions($scored);
        $fallback = static fn(): array => [
            '__fallback' => true,
            'sections' => self::deterministicVerdicts($scored, $evidence),
        ];

        $raw = $this->scoreSectionsWithJev($state, $questions, $fallback);
        $report = $this->buildReport($draftId, $scored, $evidence, $raw);
        $report['mode'] = DecisionTier::mode();

        $regen = $this->maybeAnchorRegen($draft, $scored, $evidence, $report, $reviewResult);
        $report['regen'] = $regen;

        $finalDraftId = $regen['new_draft_id'] ?? $draftId;
        $report['final_draft_id'] = $finalDraftId;
        $this->appendEvidenceTrail($finalDraftId, $report);

        $result = $reviewResult;
        $result['draft_id'] = $finalDraftId;
        $result['section_grounding'] = $report;
        return $result;
    }

    /**
     * Seam for the batched JEV call. Tests subclass this to script
     * answers without network.
     */
    protected function scoreSectionsWithJev(array $state, array $questions, callable $fallback): array
    {
        return DecisionTier::decide(
            self::DECISION,
            $state,
            $questions,
            $fallback,
            null,
            null,
            self::TIMEOUT_S
        );
    }

    /**
     * Seam for the generative anchored rewrite. Prose generation is
     * generative-LLM only (never-Jev boundary #1); JEV never writes copy.
     * Returns the rewritten section text, or null when the LLM is
     * unavailable/fails (fail-closed: no regen, flag un-regenerated).
     */
    protected function rewriteSectionWithLlm(
        string $sectionKey,
        string $sectionText,
        array $evidence,
        string $failureReason
    ): ?string {
        $facts = $this->factsForPrompt($evidence);
        $goal = 'Rewrite ONLY the given draft section so every factual claim ' .
            'is grounded in the provided lead facts. Anchor instruction: use ' .
            "these facts and these facts only — {$facts}. If a claim cannot " .
            'be grounded in them, drop the claim rather than inventing one. ' .
            "Keep the section's role ({$sectionKey}), tone, and rough length. " .
            "Return JSON with exactly one key: 'section_text'.";
        $context = "Section ({$sectionKey}):\n" . mb_substr($sectionText, 0, 1500) .
            "\n\nFailure reason: {$failureReason}";
        try {
            $data = $this->callAgent('Anchored Section Rewriter', $goal, $context);
        } catch (\Throwable $e) {
            error_log('[SectionGroundingAction] Anchored rewrite failed: ' . $e->getMessage());
            return null;
        }
        $text = trim((string)($data['section_text'] ?? ''));
        return $text === '' ? null : $text;
    }

    /** LLM availability probe. False when the router was never injected. */
    protected function llmAvailable(): bool
    {
        return isset($this->llmRouter);
    }

    // ------------------------------------------------------------------
    // Deterministic layer (always runs)
    // ------------------------------------------------------------------

    /**
     * Split a draft into scorable sections. Subject is its own section;
     * the body is segmented deterministically by block structure:
     * greeting / intro / body / cta / signoff (body may repeat as
     * body2, body3, ...). Each section carries byte offsets into the
     * original body so regenerations splice back exactly.
     *
     * @return list<array{key:string,text:string,start:?int,end:?int}>
     */
    public function splitSections(string $subject, string $body): array
    {
        $sections = [];
        $sections[] = ['key' => 'subject', 'text' => trim($subject), 'start' => null, 'end' => null];

        $blocks = $this->bodyBlocks($body);
        $n = count($blocks);
        $used = array_fill(0, $n, false);

        $isGreeting = static fn(string $t): bool =>
            (bool)preg_match('/\A(hi|hey|hello|dear|good\s+(morning|afternoon|evening)|greetings)\b/i', $t);
        $isSignoff = static fn(string $t): bool =>
            (bool)preg_match('/\A(best|thanks|thank\s+you|cheers|regards|warm\s+(regards|ly)?|sincerely|all\s+the\s+best|take\s+care|talk\s+soon|looking\s+forward|kind\s+regards)\b/i', $t);
        $isSignatureLike = static fn(string $t): bool =>
            mb_strlen($t) <= 80 && !str_contains($t, '?');
        $isCta = static fn(string $t): bool =>
            str_contains($t, '?') || (bool)preg_match(
                '/\b(call|schedule|book|grab|hop\s+on|quick\s+chat|15-?min|demo|trial|worth\s+(a\s+|your\s+)?(quick\s+)?chat|open\s+to|reply|let\s+me\s+know|interested|sounds?\s+(good|interesting)|calendar|calendly|https?:\/\/)\b/i',
                $t
            );

        // Greeting: first block only.
        if ($n > 0 && $isGreeting($blocks[0]['text'])) {
            $sections[] = $this->blockSection('greeting', $blocks[0]);
            $used[0] = true;
        }

        // Signoff: last signoff-matching block, extending over trailing
        // signature-like blocks only (avoids eating body copy when the
        // match is a mid-email false positive like "Thanks for reading").
        $signoffAt = -1;
        for ($i = $n - 1; $i >= 0; $i--) {
            if (!$used[$i] && $isSignoff($blocks[$i]['text'])) {
                $signoffAt = $i;
                break;
            }
        }
        if ($signoffAt >= 0) {
            $extends = true;
            for ($j = $signoffAt + 1; $j < $n; $j++) {
                if ($used[$j] || !$isSignatureLike($blocks[$j]['text']) || $isCta($blocks[$j]['text'])) {
                    $extends = false;
                    break;
                }
            }
            if ($extends) {
                $text = implode("\n\n", array_column(array_slice($blocks, $signoffAt), 'text'));
                $sections[] = [
                    'key' => 'signoff',
                    'text' => $text,
                    'start' => $blocks[$signoffAt]['start'],
                    'end' => $blocks[$n - 1]['end'],
                ];
                for ($j = $signoffAt; $j < $n; $j++) {
                    $used[$j] = true;
                }
            }
        }

        // CTA blocks: each becomes its own section.
        $ctaN = 0;
        for ($i = 0; $i < $n; $i++) {
            if (!$used[$i] && $isCta($blocks[$i]['text'])) {
                $ctaN++;
                $sections[] = $this->blockSection($ctaN === 1 ? 'cta' : 'cta' . $ctaN, $blocks[$i]);
                $used[$i] = true;
            }
        }

        // Intro: first remaining block. Body: the rest, in order.
        $remaining = [];
        for ($i = 0; $i < $n; $i++) {
            if (!$used[$i]) {
                $remaining[] = $i;
            }
        }
        if ($remaining !== []) {
            $sections[] = $this->blockSection('intro', $blocks[$remaining[0]]);
            $bodyN = 0;
            foreach (array_slice($remaining, 1) as $i) {
                $bodyN++;
                $sections[] = $this->blockSection($bodyN === 1 ? 'body' : 'body' . $bodyN, $blocks[$i]);
            }
        }

        return $sections;
    }

    /**
     * The evidence set — the LAMP analog of PI's factsheet. LAMP has no
     * factsheet table; the grounding evidence is the lead's own
     * buyer/lead inputs plus the campaign context.
     */
    public function buildEvidence(array $draft): array
    {
        return [
            'company' => (string)($draft['company_name'] ?? ''),
            'contact' => (string)($draft['contact_name'] ?? ''),
            'website' => (string)($draft['website'] ?? ''),
            'persona' => (string)($draft['target_persona'] ?? ''),
            'campaign' => (string)($draft['campaign_name'] ?? ''),
            'notes' => mb_substr((string)($draft['notes'] ?? ''), 0, 2000),
        ];
    }

    /**
     * Which lead facts appear verbatim in the section text. Pure string
     * matching — this measures anchoring, never truth.
     *
     * @return array<string,string> label => matched needle
     */
    public static function anchorHits(string $text, array $evidence): array
    {
        $hits = [];
        $add = static function (string $label, ?string $needle) use (&$hits, $text): void {
            $needle = trim((string)$needle);
            if (mb_strlen($needle) < 3) {
                return;
            }
            if (mb_stripos($text, $needle) !== false) {
                $hits[$label] = $needle;
            }
        };
        $add('company', $evidence['company'] ?? '');
        $add('contact', $evidence['contact'] ?? '');
        $first = preg_split('/\s+/', trim((string)($evidence['contact'] ?? '')))[0] ?? '';
        $add('contact_first', $first);
        $add('website', self::hostOf((string)($evidence['website'] ?? '')));
        foreach (preg_split('/\s+/', (string)($evidence['persona'] ?? '')) ?: [] as $w) {
            $add('persona:' . $w, $w);
        }
        return $hits;
    }

    /**
     * Deterministic per-section check. Placeholders are an automatic
     * fail; everything else is inconclusive pending JEV judgment.
     *
     * @return array{verdict:'skipped'|'fail'|'inconclusive',reason:string,anchor_hits:list<string>}
     */
    public static function deterministicCheck(array $section, array $evidence): array
    {
        if (trim($section['text']) === '') {
            return ['verdict' => 'skipped', 'reason' => 'empty', 'anchor_hits' => []];
        }
        if (self::hasPlaceholder($section['text'])) {
            return ['verdict' => 'fail', 'reason' => 'unreplaced-placeholder', 'anchor_hits' => []];
        }
        return [
            'verdict' => 'inconclusive',
            'reason' => 'needs-judgment',
            'anchor_hits' => array_keys(self::anchorHits($section['text'], $evidence)),
        ];
    }

    // ------------------------------------------------------------------
    // JEV layer
    // ------------------------------------------------------------------

    /**
     * One batched System One call: Score (grounding) + Noul (section_safe)
     * per section. Mirrors the DP2/DP6 shape.
     */
    private function groundingQuestions(array $sections): array
    {
        $levels = [
            'Ungrounded — the section makes claims the provided facts do not support',
            'Mostly ungrounded — one or two supported claims, the rest unsupported',
            'Mixed — some claims supported, some not',
            'Mostly grounded — supported, with minor unsupported color',
            'Fully grounded — every claim in the section traces to the provided facts',
        ];
        $questions = [];
        foreach ($sections as $i => $sec) {
            $questions["sec_{$i}_grounded"] = JevProvider::scoreQuestion(
                'P(each factual claim in this draft section is supported by ' .
                'the provided lead facts). Never-invent-evidence: claims ' .
                'about the lead or company that appear nowhere in the facts ' .
                'are ungrounded. Generic outreach framing ("quick question", ' .
                '"love what you are doing") is neutral, not ungrounded.',
                $levels
            );
            $questions["sec_{$i}_safe"] = JevProvider::noulQuestion(
                'This draft section contains no risky claims: no fabricated ' .
                'statistics, no false personal familiarity, no ' .
                'misrepresentation of the sender, and no promises the facts ' .
                'do not support.'
            );
        }
        return $questions;
    }

    /**
     * Normalize either raw JEV answers (live mode with a usable verdict)
     * or the fail-closed fallback into one per-section report shape.
     */
    private function buildReport(int $draftId, array $sections, array $evidence, $raw): array
    {
        $mode = DecisionTier::mode();
        if (is_array($raw) && ($raw['__fallback'] ?? false) === true) {
            $source = $mode === 'off' ? 'deterministic'
                : ($mode === 'live' ? 'jev-unavailable' : 'shadow');
            $per = [];
            foreach ($sections as $i => $sec) {
                $det = $raw['sections'][$i] ?? ['verdict' => 'unscored', 'reason' => 'unknown', 'anchor_hits' => []];
                $per[] = [
                    'index' => $i,
                    'key' => $sec['key'],
                    'verdict' => $det['verdict'] === 'inconclusive' ? 'unscored' : $det['verdict'],
                    'reason' => $det['reason'],
                    'anchor_hits' => $det['anchor_hits'] ?? [],
                    'score' => null,
                    'safe' => null,
                    'confidence' => null,
                    'source' => $source,
                ];
            }
            return [
                'draft_id' => $draftId,
                'source' => $source,
                'sections' => $per,
                'note' => $this->fallbackNote($mode),
            ];
        }

        try {
            $per = [];
            foreach ($sections as $i => $sec) {
                $per[] = $this->jevSectionVerdict($sec, $evidence, $raw, $i);
            }
            return ['draft_id' => $draftId, 'source' => 'jev', 'sections' => $per];
        } catch (\Throwable $e) {
            // Malformed JEV answers: fail closed to deterministic verdicts.
            error_log('[SectionGroundingAction] Unusable JEV answers; failing closed: ' . $e->getMessage());
            $per = [];
            foreach ($sections as $i => $sec) {
                $det = self::deterministicCheck($sec, $evidence);
                $per[] = [
                    'index' => $i,
                    'key' => $sec['key'],
                    'verdict' => $det['verdict'] === 'inconclusive' ? 'unscored' : $det['verdict'],
                    'reason' => $det['reason'],
                    'anchor_hits' => $det['anchor_hits'],
                    'score' => null,
                    'safe' => null,
                    'confidence' => null,
                    'source' => 'jev-malformed',
                ];
            }
            return ['draft_id' => $draftId, 'source' => 'jev-malformed', 'sections' => $per];
        }
    }

    /**
     * @throws \RuntimeException when the expected answers are absent.
     */
    private function jevSectionVerdict(array $section, array $evidence, array $answers, int $i): array
    {
        $det = self::deterministicCheck($section, $evidence);
        $g = $answers["sec_{$i}_grounded"] ?? null;
        $s = $answers["sec_{$i}_safe"] ?? null;
        if (!is_array($g) || !is_array($s)) {
            throw new \RuntimeException("Missing JEV answers for section index {$i}.");
        }
        $score = JevProvider::scoreToPercent((float)($g['score'] ?? 0), 5);
        $safe = (float)($s['noul'] ?? 0) >= self::SAFE_NOUL_THRESHOLD;
        $confidence = min((float)($g['confidence'] ?? 1.0), (float)($s['confidence'] ?? 1.0));

        if ($det['verdict'] === 'fail') {
            // Deterministic flag wins: judgment never rescues a placeholder.
            $verdict = 'fail';
            $reason = $det['reason'];
        } else {
            $verdict = ($score >= self::SECTION_SCORE_THRESHOLD && $safe) ? 'pass' : 'fail';
            $reason = $verdict === 'pass'
                ? 'grounded'
                : ($score < self::SECTION_SCORE_THRESHOLD
                    ? sprintf('jev-score %.1f below %.0f', $score, self::SECTION_SCORE_THRESHOLD)
                    : 'unsafe-claims');
        }

        return [
            'index' => $i,
            'key' => $section['key'],
            'verdict' => $verdict,
            'reason' => $reason,
            'anchor_hits' => $det['anchor_hits'],
            'score' => $score,
            'safe' => $safe,
            'confidence' => $confidence,
            'source' => 'jev',
        ];
    }

    private function fallbackNote(string $mode): string
    {
        return match ($mode) {
            'off' => 'JEV disabled: deterministic checks only. Draft flagged as un-regenerated.',
            'shadow' => 'Shadow mode: JEV verdict logged for calibration; deterministic verdict used; no regeneration.',
            default => 'JEV verdict unavailable (error/timeout/low confidence): escalated. Draft flagged as un-regenerated.',
        };
    }

    // ------------------------------------------------------------------
    // One-shot anchored regeneration (live mode only, never on approval)
    // ------------------------------------------------------------------

    /**
     * At most ONE anchored regeneration attempt, and only when the final
     * draft is headed to the human queue. Creates a new draft row with the
     * rewritten sections spliced in, marks both rows grounding_regen=1,
     * sets the new row to needs_human, and leaves the original row's
     * status untouched.
     */
    private function maybeAnchorRegen(
        array $draft,
        array $sections,
        array $evidence,
        array $report,
        array $reviewResult
    ): array {
        $out = ['attempted' => false, 'new_draft_id' => null, 'reason' => null, 'recheck' => null];
        $mode = DecisionTier::mode();
        if ($mode !== 'live') {
            $out['reason'] = 'not-live';
            return $out;
        }
        if (($reviewResult['outcome'] ?? 'needs_human') === 'approved') {
            // An approved draft is never rewritten: the whole-draft
            // verdict stands; QA is evidence only.
            $out['reason'] = 'approved-kept';
            return $out;
        }
        $failing = array_values(array_filter(
            $report['sections'],
            static fn(array $s): bool => $s['verdict'] === 'fail'
        ));
        if ($failing === []) {
            $out['reason'] = 'no-failing-sections';
            return $out;
        }
        if (($report['source'] ?? '') !== 'jev') {
            // Fail-closed: anchored regen requires a real live JEV
            // verdict — deterministic-only, shadow, jev-unavailable, or
            // malformed answers never authorize a rewrite, even when a
            // section failed deterministically (the human sees the flag).
            $out['reason'] = 'no-jev-verdict';
            return $out;
        }
        if ($this->regenAlreadyAttempted((int)$draft['id'])) {
            $out['reason'] = 'already-attempted';
            return $out;
        }
        if (!$this->llmAvailable()) {
            $out['reason'] = 'llm-unavailable';
            return $out;
        }

        $rewrites = [];
        foreach ($failing as $f) {
            $sec = $sections[$f['index']];
            try {
                $text = $this->rewriteSectionWithLlm($sec['key'], $sec['text'], $evidence, (string)$f['reason']);
            } catch (\Throwable $e) {
                error_log('[SectionGroundingAction] Anchored rewrite threw: ' . $e->getMessage());
                $text = null;
            }
            if (is_string($text) && trim($text) !== '') {
                $rewrites[$f['index']] = trim($text);
            }
        }
        if ($rewrites === []) {
            $out['reason'] = 'llm-unavailable';
            return $out;
        }

        $newId = $this->persistRegenDraft($draft, $sections, $rewrites);
        $this->markRegenAttempted((int)$draft['id']);
        $this->markRegenAttempted($newId);
        $this->setDraftStatus($newId, 'needs_human');
        $this->appendNote(
            (int)$draft['id'],
            'Section-grounding QA: failing sections failed grounding; one anchored ' .
            "regeneration attempted → new draft #{$newId} (status needs_human)."
        );

        // Deterministic re-check of the regenerated sections (no second
        // JEV call — a second judgment on the same evidence would be
        // pure cost, per the DP11 rationale).
        $rechecked = [];
        $newSections = $this->applyRewrites($sections, $rewrites);
        foreach (self::deterministicVerdicts($newSections, $evidence) as $i => $det) {
            $rechecked[] = [
                'index' => $i,
                'key' => $newSections[$i]['key'],
                'verdict' => $det['verdict'],
                'reason' => $det['reason'],
                'anchor_hits' => $det['anchor_hits'],
            ];
        }

        $out['attempted'] = true;
        $out['new_draft_id'] = $newId;
        $out['regenerated_sections'] = array_map(
            static fn(array $f): string => (string)$f['key'],
            $failing
        );
        $out['recheck'] = $rechecked;
        return $out;
    }

    /**
     * Fail-closed: when the once-only marker cannot be persisted (column
     * absent) or read, report "already attempted" so no regeneration runs
     * that it could not deduplicate.
     */
    private function regenAlreadyAttempted(int $draftId): bool
    {
        if (!$this->groundingRegenColumnExists()) {
            error_log('[SectionGroundingAction] grounding_regen column absent; skipping anchored regen (un-regenerated).');
            return true;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT grounding_regen FROM drafts WHERE id = ?');
            $stmt->execute([$draftId]);
            $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
            return ((int)($row['grounding_regen'] ?? 0)) === 1;
        } catch (\Throwable $e) {
            error_log('[SectionGroundingAction] Could not read grounding_regen: ' . $e->getMessage());
            return true;
        }
    }

    private function markRegenAttempted(int $draftId): void
    {
        if (!$this->groundingRegenColumnExists()) {
            return;
        }
        try {
            $stmt = $this->pdo->prepare('UPDATE drafts SET grounding_regen = 1 WHERE id = ?');
            $stmt->execute([$draftId]);
        } catch (\Throwable $e) {
            error_log('[SectionGroundingAction] Could not mark grounding_regen: ' . $e->getMessage());
        }
    }

    /** Instance-cached probe for the migration column. */
    private function groundingRegenColumnExists(): bool
    {
        if ($this->regenColumn !== null) {
            return $this->regenColumn;
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS c FROM information_schema.COLUMNS " .
                "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drafts' " .
                "AND COLUMN_NAME = 'grounding_regen'"
            );
            $stmt->execute();
            $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
            $this->regenColumn = ((int)($row['c'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            error_log('[SectionGroundingAction] Column probe failed: ' . $e->getMessage());
            $this->regenColumn = false;
        }
        return $this->regenColumn;
    }

    /**
     * Persist the anchor-regenerated draft as a NEW row (the original is
     * kept for the evidence trail). Returns the new draft id.
     */
    private function persistRegenDraft(array $draft, array $sections, array $rewrites): int
    {
        $newBody = $this->rebuildBody((string)$draft['body'], $sections, $rewrites);
        $newSubject = (string)$draft['subject'];
        foreach ($sections as $i => $sec) {
            if ($sec['key'] === 'subject' && isset($rewrites[$i])) {
                $newSubject = $rewrites[$i];
            }
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO drafts (lead_id, campaign_id, template_id, subject, body, status, attempts, grounding_regen)
             VALUES (?, ?, ?, ?, ?, \'pending_review\', ?, 1)'
        );
        $stmt->execute([
            (int)$draft['lead_id'],
            (int)$draft['campaign_id'],
            $draft['template_id'] ?? null,
            $newSubject,
            $newBody,
            ((int)($draft['attempts'] ?? 1)) + 1,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Evidence trail
    // ------------------------------------------------------------------

    /** Append (never overwrite) the QA report to reviewer_notes. */
    private function appendEvidenceTrail(int $draftId, array $report): void
    {
        $this->appendNote($draftId, $this->formatEvidenceTrail($report));
    }

    private function appendNote(int $draftId, string $note): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE drafts SET reviewer_notes = CONCAT(COALESCE(reviewer_notes, \'\'), ?) WHERE id = ?'
        );
        $stmt->execute(["\n\n" . $note, $draftId]);
    }

    private function setDraftStatus(int $draftId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE drafts SET status = ? WHERE id = ?');
        $stmt->execute([$status, $draftId]);
    }

    private function formatEvidenceTrail(array $report): string
    {
        $lines = [];
        $ts = gmdate('Y-m-d\TH:i:s\Z');
        $lines[] = "[SectionGrounding QA — {$ts} — mode: {$report['mode']} — source: {$report['source']}]";
        foreach ($report['sections'] as $s) {
            $bits = [];
            $bits[] = $s['key'];
            $bits[] = strtoupper((string)$s['verdict']);
            if ($s['score'] !== null) {
                $bits[] = sprintf('score %.1f', (float)$s['score']);
            }
            if ($s['safe'] !== null) {
                $bits[] = $s['safe'] ? 'safe' : 'unsafe';
            }
            if ($s['confidence'] !== null) {
                $bits[] = sprintf('conf %.2f', (float)$s['confidence']);
            }
            if ($s['reason'] !== '') {
                $bits[] = (string)$s['reason'];
            }
            if (!empty($s['anchor_hits'])) {
                $bits[] = 'anchors: ' . implode(',', $s['anchor_hits']);
            }
            $lines[] = '  - ' . implode(' | ', $bits);
        }
        $r = $report['regen'] ?? [];
        if (!empty($r['attempted'])) {
            $lines[] = sprintf(
                '  Anchored regen: attempted once → new draft #%d (sections: %s).',
                (int)($r['new_draft_id'] ?? 0),
                implode(',', $r['regenerated_sections'] ?? [])
            );
            foreach ($r['recheck'] ?? [] as $rc) {
                $lines[] = sprintf(
                    '  Re-check %s: %s (%s)',
                    $rc['key'],
                    strtoupper((string)$rc['verdict']),
                    $rc['reason']
                );
            }
        } else {
            $lines[] = '  Anchored regen: not attempted (' . ($r['reason'] ?? 'n/a') . ').';
        }
        if (isset($report['note'])) {
            $lines[] = '  ' . $report['note'];
        }
        // Machine-readable tail for telemetry.
        $lines[] = '[SectionGrounding JSON] ' . json_encode([
            'draft_id' => $report['draft_id'] ?? null,
            'final_draft_id' => $report['final_draft_id'] ?? null,
            'mode' => $report['mode'] ?? null,
            'source' => $report['source'] ?? null,
            'sections' => array_map(static fn(array $s): array => [
                'key' => $s['key'],
                'verdict' => $s['verdict'],
                'reason' => $s['reason'],
                'score' => $s['score'] ?? null,
                'safe' => $s['safe'] ?? null,
            ], $report['sections'] ?? []),
            'regen_attempted' => !empty($r['attempted']),
            'regen_reason' => $r['reason'] ?? null,
            'new_draft_id' => $r['new_draft_id'] ?? null,
        ]);
        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array{id:int,lead_id:int,campaign_id:int,template_id:?int,subject:string,body:string,
     *               attempts:int,status:string,company_name:?string,contact_name:?string,website:?string,
     *               target_persona:?string,notes:?string,campaign_name:?string}
     * @throws OutreachException when the draft is missing or unreadable.
     */
    private function loadDraft(int $draftId): array
    {
        // website/target_persona are optional evidence: minimal/older
        // leads schemas (e.g. the phase0 scratch DB) lack them, so the
        // full shape is retried once in the minimal shape before failing.
        $shapes = [
            'l.company_name, l.contact_name, l.website, l.target_persona, l.notes,',
            'l.company_name, l.contact_name, l.notes,',
        ];
        $last = null;
        foreach ($shapes as $leadCols) {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT d.id, d.lead_id, d.campaign_id, d.template_id, d.subject, d.body, d.attempts, d.status,
                            {$leadCols}
                            c.name AS campaign_name
                     FROM drafts d
                     JOIN leads l ON l.id = d.lead_id
                     LEFT JOIN campaigns c ON c.id = d.campaign_id
                     WHERE d.id = ?"
                );
                $stmt->execute([$draftId]);
                $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new OutreachException("Draft ID {$draftId} not found.");
                }
                return $row + ['website' => null, 'target_persona' => null];
            } catch (OutreachException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $last = $e; // schema mismatch — try the minimal shape
            }
        }
        throw new OutreachException(
            "Draft ID {$draftId} could not be loaded: " . ($last ? $last->getMessage() : 'unknown error')
        );
    }

    /** @return list<array{text:string,start:int,end:int}> */
    private function bodyBlocks(string $body): array
    {
        $blocks = [];
        if (preg_match_all(
            '/[^\r\n]+(?:(?:\r\n|\r|\n)(?=[^\r\n])[^\r\n]*)*/',
            $body,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($m[0] as [$raw, $start]) {
                $ltrimmed = ltrim($raw);
                $lead = strlen($raw) - strlen($ltrimmed);
                $text = rtrim($ltrimmed);
                if ($text === '') {
                    continue;
                }
                $blocks[] = [
                    'text' => $text,
                    'start' => $start + $lead,
                    'end' => $start + $lead + strlen($text),
                ];
            }
        }
        return $blocks;
    }

    private function blockSection(string $key, array $block): array
    {
        return [
            'key' => $key,
            'text' => $block['text'],
            'start' => $block['start'],
            'end' => $block['end'],
        ];
    }

    /** Replace rewritten sections at their recorded byte offsets. */
    private function rebuildBody(string $body, array $sections, array $rewrites): string
    {
        $spans = [];
        foreach ($sections as $i => $sec) {
            if (isset($rewrites[$i]) && $sec['start'] !== null && $sec['end'] !== null) {
                $spans[] = [(int)$sec['start'], (int)$sec['end'], $rewrites[$i]];
            }
        }
        usort($spans, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $out = '';
        $pos = 0;
        foreach ($spans as [$s, $e, $text]) {
            $out .= substr($body, $pos, $s - $pos) . $text;
            $pos = $e;
        }
        $out .= substr($body, $pos);
        return $out;
    }

    /** @return list<array> sections with rewrite texts applied. */
    private function applyRewrites(array $sections, array $rewrites): array
    {
        $out = $sections;
        foreach ($rewrites as $i => $text) {
            $out[$i]['text'] = $text;
        }
        return $out;
    }

    private static function deterministicVerdicts(array $sections, array $evidence): array
    {
        $out = [];
        foreach ($sections as $sec) {
            $out[] = self::deterministicCheck($sec, $evidence);
        }
        return $out;
    }

    private static function hasPlaceholder(string $text): bool
    {
        return (bool)preg_match(
            '/\{\{[^}]+\}\}|\[[A-Za-z][A-Za-z .\'-]{1,40}\]|\bXXX+\b|%%[^%]+%%/',
            $text
        );
    }

    private static function hostOf(string $website): string
    {
        $w = trim($website);
        if ($w === '') {
            return '';
        }
        if (!str_contains($w, '://')) {
            $w = 'https://' . $w;
        }
        $host = (string)parse_url($w, PHP_URL_HOST);
        return preg_replace('/\Awww\./', '', $host) ?? '';
    }

    private function factsForPrompt(array $evidence): string
    {
        $bits = [];
        foreach (['company' => 'company', 'contact' => 'contact', 'website' => 'website',
                  'persona' => 'target persona', 'campaign' => 'campaign'] as $k => $label) {
            $v = trim((string)($evidence[$k] ?? ''));
            if ($v !== '') {
                $bits[] = "{$label}='{$v}'";
            }
        }
        $notes = trim((string)($evidence['notes'] ?? ''));
        if ($notes !== '') {
            $bits[] = 'lead notes: ' . mb_substr($notes, 0, 800);
        }
        return implode('; ', $bits);
    }
}
