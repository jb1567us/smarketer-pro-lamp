<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jev\DecisionTier;
use App\Jev\JevProvider;

/**
 * ReviewDraftAction — Phase 2 port of the Python ReviewerAgent's JEV path.
 *
 * Reviews a generated draft with a JEV decision (approved [Noul] + quality
 * [Score]) instead of a generative LLM critique. The generative part survives
 * only where it must: when JEV rejects a draft in live mode, the LLM writes
 * the actionable feedback that feeds the regeneration.
 *
 * Modes (same contract as every other JEV decision point):
 *   off    — no review runs; drafts go to the human queue untouched.
 *   shadow — JEV reviews and its verdict is logged, but nothing changes:
 *            every draft is escalated to human review exactly as before.
 *   live   — JEV approves -> draft marked approved; JEV rejects ->
 *            regenerate with LLM-written feedback (max 2 regenerations),
 *            then escalate to human review with the rejection reason.
 *
 * Fail-closed: any JEV error, timeout, or low-confidence verdict escalates
 * the draft to human review. The reviewer can never silently approve.
 */
class ReviewDraftAction extends AbstractAction
{
    public const DECISION = 'draft_review.review_draft';

    /** Hard timeout for the review call (plan 2.2: <=8s). */
    public const TIMEOUT_S = 8;

    /** Max regenerations after a rejection before human escalation. */
    public const MAX_REVISIONS = 2;

    /**
     * ActionInterface contract: review the lead's newest pending draft,
     * running the full refinement loop (a DraftOutreachAction handles any
     * regenerations). Returns false when there is no pending draft.
     */
    public function execute(int $leadId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, attempts FROM drafts WHERE lead_id = ? AND status = 'pending_review' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$leadId]);
        $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $drafter = new DraftOutreachAction($this->pdo, $this->llmRouter);
        $attempt = (int)($row['attempts'] ?? 1);
        $this->reviewWithRefinement(
            (int)$row['id'],
            function (string $feedback) use ($leadId, $drafter, &$attempt): int {
                $attempt++;
                return $drafter->buildDraft($leadId, $feedback, $attempt)['draft_id'];
            }
        );
        return true;
    }

    /**
     * Run one review pass over a draft. Returns a normalized verdict:
     * ['approved'=>bool,'score'=>float 0-100,'confidence'=>float,
     *  'source'=>'jev'|'human-review','latency_ms'=>int,'reason'=>?string]
     */
    public function reviewDraft(int $draftId): array
    {
        $draft = $this->loadDraft($draftId);

        $state = [
            'draft_subject' => $draft['subject'],
            'draft_body'    => mb_substr($draft['body'], 0, 4000),
            'lead_company'  => $draft['company_name'] ?? '',
            'lead_contact'  => $draft['contact_name'] ?? '',
            'campaign'      => $draft['campaign_name'] ?? '',
            // Enrichment/qualification context, truncated — the reviewer
            // judges relevance against what we know about the lead.
            'lead_context'  => mb_substr($draft['notes'] ?? '', 0, 2000),
        ];

        $questions = [
            'approved' => JevProvider::noulQuestion(
                'The email draft is professional yet engaging, has no ' .
                'hallucinations or risky claims, and addresses the ' .
                "recipient's likely needs."
            ),
            'score' => JevProvider::scoreQuestion(
                'Overall quality of this cold-outreach email draft.',
                ['Unusable', 'Poor', 'Acceptable', 'Strong', 'Excellent']
            ),
        ];

        // Fail-closed fallback: never approve without a JEV verdict.
        // Used in off mode and whenever the JEV call fails or is unsure.
        $fallback = static fn(): array => [
            'approved'   => false,
            'score'      => 0.0,
            'confidence' => 0.0,
            'source'     => 'human-review',
            'reason'     => 'JEV review unavailable or disabled; escalated to human review.',
        ];

        $t0 = microtime(true);
        $raw = DecisionTier::decide(
            self::DECISION,
            $state,
            $questions,
            $fallback,
            null,
            null,
            self::TIMEOUT_S
        );
        $latencyMs = (int)((microtime(true) - $t0) * 1000);

        $verdict = $this->normalizeVerdict($raw);
        $verdict['latency_ms'] = $latencyMs;
        return $verdict;
    }

    /**
     * Full review loop for a freshly built draft.
     *
     * $regenerate is called as $regenerate(string $feedback): int and must
     * build a revised draft, returning the new draft id.
     *
     * Returns ['outcome'=>'approved'|'needs_human','draft_id'=>int,
     *          'revisions'=>int,'reason'=>?string].
     */
    public function reviewWithRefinement(int $draftId, callable $regenerate): array
    {
        $revisions = 0;
        $currentId = $draftId;

        while (true) {
            $verdict = $this->reviewDraft($currentId);
            $live = DecisionTier::mode() === 'live';

            // Not live (off/shadow) or fail-closed: exactly one pass, then
            // the human queue — zero behavior change vs. today.
            if (!$live || $verdict['source'] !== 'jev') {
                $note = $verdict['source'] === 'jev'
                    ? sprintf(
                        'JEV shadow review: %s (score %.0f, confidence %.2f, %dms). Awaiting human review.',
                        $verdict['approved'] ? 'would APPROVE' : 'would REJECT',
                        $verdict['score'],
                        $verdict['confidence'],
                        $verdict['latency_ms']
                    )
                    : ($verdict['reason'] ?? 'Awaiting human review.');
                $this->setDraftStatus($currentId, 'needs_human', $note);
                return $this->withSectionGrounding($currentId, [
                    'outcome'   => 'needs_human',
                    'draft_id'  => $currentId,
                    'revisions' => $revisions,
                    'reason'    => $note,
                ]);
            }

            if ($verdict['approved']) {
                $note = sprintf(
                    'JEV approved (score %.0f, confidence %.2f, %dms).',
                    $verdict['score'],
                    $verdict['confidence'],
                    $verdict['latency_ms']
                );
                $this->setDraftStatus($currentId, 'approved', $note);
                return $this->withSectionGrounding($currentId, [
                    'outcome'   => 'approved',
                    'draft_id'  => $currentId,
                    'revisions' => $revisions,
                    'reason'    => $note,
                ]);
            }

            // Live-mode rejection: regenerate with LLM-written feedback,
            // up to MAX_REVISIONS, then escalate with the reason attached.
            if ($revisions >= self::MAX_REVISIONS) {
                $note = sprintf(
                    'JEV rejected after %d revision(s) (last score %.0f, confidence %.2f). Escalated to human review.',
                    $revisions,
                    $verdict['score'],
                    $verdict['confidence']
                );
                $this->setDraftStatus($currentId, 'needs_human', $note);
                return $this->withSectionGrounding($currentId, [
                    'outcome'   => 'needs_human',
                    'draft_id'  => $currentId,
                    'revisions' => $revisions,
                    'reason'    => $note,
                ]);
            }

            $feedback = $this->legacyReview(
                $this->loadDraft($currentId)['subject'],
                $this->loadDraft($currentId)['body'],
                $this->loadDraft($currentId)['notes'] ?? ''
            );
            $critique = $feedback['critique'] ?? 'Improve tone, relevance, and clarity.';
            $this->setDraftStatus(
                $currentId,
                'superseded',
                sprintf('Superseded by revision %d. JEV rejection feedback: %s', $revisions + 1, mb_substr($critique, 0, 500))
            );
            $currentId = (int)$regenerate($critique);
            $revisions++;
        }
    }

    /**
     * P3: per-section grounding QA (PI DP2/DP6 pattern) as machine QA
     * feeding the human queue. Runs the SectionGroundingAction pass over
     * the final draft: deterministic + JEV per-section scores, at most
     * one anchored regeneration of failing sections (live mode only,
     * never on approved drafts), and the evidence trail appended to the
     * draft's reviewer_notes.
     *
     * This wrapper is fail-closed: any throw inside the QA pass leaves
     * the review outcome untouched — the QA can never change an existing
     * verdict, status, or draft id.
     */
    private function withSectionGrounding(int $draftId, array $result): array
    {
        try {
            $qa = new SectionGroundingAction($this->pdo, $this->llmRouter);
            return $qa->qaPass($draftId, $result);
        } catch (\Throwable $e) {
            error_log('[ReviewDraftAction] Section-grounding QA failed; keeping review outcome: ' . $e->getMessage());
            return $result;
        }
    }

    /**
     * Generative LLM review — the Python _think_legacy port. Used ONLY to
     * write actionable feedback after a live-mode JEV rejection; it never
     * decides anything on its own.
     *
     * @return array{approved:bool,critique:string,score:int}
     */
    public function legacyReview(string $subject, string $body, string $leadContext = ''): array
    {
        $context = "Subject: {$subject}\n\nBody:\n{$body}\n\nLead context:\n" . mb_substr($leadContext, 0, 2000);
        $goal = "Review the email draft for: 1. Tone: professional yet engaging. " .
                "2. Safety: no hallucinations or risky claims. " .
                "3. Relevance: addresses the recipient's likely needs. " .
                "Return JSON with keys: 'approved' (boolean), 'critique' (string, " .
                "bullet points of specific actionable changes), 'score' (1-10).";

        try {
            $data = $this->callAgent('Content Quality & Safety Reviewer', $goal, $context);
        } catch (\Throwable $e) {
            return ['approved' => false, 'critique' => 'Reviewer LLM unavailable: ' . $e->getMessage(), 'score' => 0];
        }

        return [
            'approved' => (bool)($data['approved'] ?? false),
            'critique' => (string)($data['critique'] ?? $data['feedback'] ?? ''),
            'score'    => (int)($data['score'] ?? 0),
        ];
    }

    public function setDraftStatus(int $draftId, string $status, ?string $notes = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE drafts SET status = ?, reviewer_notes = ? WHERE id = ?'
        );
        $stmt->execute([$status, $notes, $draftId]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Normalize either raw JEV answers or an already-normalized verdict
     * (the fail-closed fallback) into one verdict shape.
     */
    private function normalizeVerdict($raw): array
    {
        if (is_array($raw) && array_key_exists('approved', $raw)
            && is_bool($raw['approved']) && isset($raw['source'])) {
            return $raw + ['latency_ms' => 0];
        }
        $a = is_array($raw['approved'] ?? null) ? $raw['approved'] : [];
        $s = is_array($raw['score'] ?? null) ? $raw['score'] : [];
        return [
            'approved'   => ((float)($a['noul'] ?? 0)) >= 0.5,
            'score'      => (float)($s['score'] ?? 0), // 0-100 via scoreToPercent
            'confidence' => (float)($a['confidence'] ?? 0),
            'source'     => 'jev',
        ];
    }

    /**
     * @return array{id:int,subject:string,body:string,notes:?string,
     *               company_name:?string,contact_name:?string,campaign_name:?string}
     * @throws \App\Exceptions\OutreachException when the draft is missing.
     */
    private function loadDraft(int $draftId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.subject, d.body, l.notes, l.company_name, l.contact_name, c.name AS campaign_name
             FROM drafts d
             JOIN leads l ON l.id = d.lead_id
             LEFT JOIN campaigns c ON c.id = d.campaign_id
             WHERE d.id = ?'
        );
        $stmt->execute([$draftId]);
        $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \App\Exceptions\OutreachException("Draft ID {$draftId} not found.");
        }
        return $row;
    }
}
