<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\OutreachException;
use App\Jev\DecisionTier;
use App\Jev\JevProvider;

class QualifyLeadAction extends AbstractAction
{
    /**
     * Hard per-call timeout (seconds) for the Jev qualification decision.
     * On timeout the decision tier fails over to the legacy LLM path —
     * the pipeline never hangs waiting for the decision API.
     *
     * The weighted per-dimension scoring path (ScoreLeadFitAction) applies
     * the same budget; see its JEV_TIMEOUT_SECONDS.
     */
    private const JEV_TIMEOUT_SECONDS = 20;

    public function execute(int $leadId): bool
    {
        $stmt = $this->pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch(\App\PDO::FETCH_ASSOC);

        if (!$lead) {
            throw new OutreachException("Lead ID {$leadId} not found.");
        }

        $persona = "B2B ICP Specialist";
        $goal = "Determine if this company matches a High-Value Prospect profile.";

        $company = $lead['company_name'] ?? 'Unknown';
        $website = $lead['website'] ?? 'Unknown';
        $contact = $lead['contact_name'] ?? 'Unknown';
        $context = "Company: {$company}\nWebsite: {$website}\nContact: {$contact}";

        // Weighted ICP fit scoring. The legacy single-question qualification
        // below is passed as the fallback callable, so "off" mode and every
        // fail-closed path behave exactly as before this change.
        $scorer = new ScoreLeadFitAction($this->pdo, $this->llmRouter);
        $result = $scorer->score(
            $lead,
            function () use ($persona, $goal, $context) {
                $answers = $this->legacyDecideQualification($persona, $goal, $context);
                return $this->answersToQualification(
                    $answers,
                    self::buildDecisionQuestions()['fitLevels']
                );
            }
        );

        $verdict = $result['verdict']; // qualified | needs_review | unqualified
        $status = self::statusForVerdict($verdict);
        $score = (int)$result['fit_score'];

        // Phase 0 fix (was critical defect C2): qualification used to OVERWRITE
        // leads.notes, destroying the enrichment research the drafter needs.
        // The verdict is now APPENDED with a clear marker; existing notes
        // (enrichment data, prior drafts) are preserved. The marker carries
        // the per-dimension breakdown when the weighted path ran.
        $existingNotes = (string)($lead['notes'] ?? '');
        $newNotes = $existingNotes . self::notesMarker($result, $status);

        $stmt = $this->pdo->prepare("UPDATE leads SET lead_score = ?, status = ?, notes = ? WHERE id = ?");
        $stmt->execute([$score, $status, $newNotes, $leadId]);

        return true;
    }

    /**
     * Map a scoring verdict to a leads.status ENUM value.
     *
     * The 50-75 review band maps to the real 'Needs Review' ENUM value of
     * leads.status (New/Enriched/Contacted/Qualified/Unqualified/Converted/
     * Drafted/'Needs Review'): borderline leads stay OUT of sequences
     * (fail-closed — 'Needs Review' is in neither
     * SequenceManager::ELIGIBLE_LEAD_STATUSES nor
     * SequenceManager::SENDABLE_LEAD_STATUSES) until a human reviews them.
     * Approval flips the lead to 'Qualified' — the only path to eligibility.
     * Any unrecognized verdict stays 'Unqualified' (fail-closed).
     */
    public static function statusForVerdict(string $verdict): string
    {
        return match ($verdict) {
            'qualified' => 'Qualified',
            'needs_review' => 'Needs Review',
            default => 'Unqualified',
        };
    }

    /**
     * Build the notes marker appended to leads.notes.
     *
     * Legacy-source results keep the exact historical format so off/shadow
     * behavior is byte-identical to before. Weighted/veto results extend it
     * with the per-dimension breakdown (or the matched exclusion).
     */
    public static function notesMarker(array $result, string $status): string
    {
        $date = date('Y-m-d');
        $score = (int)($result['fit_score'] ?? 0);
        $reason = trim((string)($result['reason'] ?? ''));

        if (($result['source'] ?? 'legacy') === 'legacy') {
            return "\n\n[Qualification {$date}]: {$status} (score {$score}) — {$reason}";
        }

        $dimensionsLine = '';
        $dims = $result['dimensions'] ?? [];
        if (is_array($dims) && $dims !== []) {
            $parts = [];
            foreach ($dims as $key => $v) {
                $parts[] = "{$key}={$v}/10";
            }
            $dimensionsLine = "\nDimensions: " . implode(', ', $parts) . '.';
        }

        $verdict = (string)($result['verdict'] ?? 'unqualified');
        if ($verdict === 'needs_review') {
            $qualifyAt = (int)(($result['thresholds'] ?? [])['qualify'] ?? 75);
            return "\n\n[Qualification {$date}]: Needs Review (fit {$score}/100, below qualify threshold {$qualifyAt})"
                . " — {$reason}{$dimensionsLine}";
        }

        if (($result['source'] ?? '') === 'veto') {
            return "\n\n[Qualification {$date}]: Unqualified (fit 0/100) — {$reason}";
        }

        $profile = (string)($result['profile'] ?? '');
        $profileBit = $profile !== '' ? ", ICP \"{$profile}\"" : '';
        return "\n\n[Qualification {$date}]: {$status} (fit {$score}/100{$profileBit}) — {$reason}{$dimensionsLine}";
    }

    /**
     * The app's actual Ideal Customer Profile must-haves.
     *
     * Sourced from the 'Chat Qualifier' prompt in includes/Prompts/PromptRegistry.php —
     * the only place in the codebase that spells out what the ICP consists of:
     * size and industry. (Tech stack was removed from ICP fit scoring entirely
     * on 2026-09-29; it survives only as lead-enrichment data, never as a
     * scoring criterion.) The 'Qualifier' / 'B2B ICP Specialist'
     * prompt references "the Ideal Customer Profile (ICP)" but never enumerates it.
     *
     * Deliberately minimal: the 2026-09-28 live-shadow re-measurement (GATE: HOLD)
     * showed the old question's "every must-have" bar referred to an undefined
     * ideal, and combined with the "answer false when evidence is thin"
     * instruction it disqualified 18/18 leads including unambiguous clear fits.
     * These dimensions must never be expanded ad hoc here; they come from the
     * app's real ICP criteria. Kept public-static so tests can assert the
     * decision question enumerates exactly these criteria.
     *
     * This is the LEGACY single-question path, preserved verbatim as the
     * off-mode behavior and the fail-closed fallback for the weighted
     * per-dimension scoring (ScoreLeadFitAction).
     *
     * @return string[]
     */
    public static function icpMustHaves(): array
    {
        return [
            'Company size: the company falls in the size range we sell to.',
            'Industry: the company operates in an industry we target.',
        ];
    }

    /**
     * Builds the qualification decision questions.
     *
     * Phase 4 bias fix (2026-09-28): the old `qualified` question demanded the
     * lead "match every must-have of the ideal customer profile" without ever
     * naming the must-haves, and told the model to "answer false when evidence
     * is thin" — a structural false-always bias. The new question:
     *   1. enumerates the ICP must-haves explicitly in the state/question;
     *   2. applies a preponderance standard — qualified = noul >= 0.5 on
     *      "is a strong ICP fit" (most must-haves evidenced, no deal-breakers);
     *   3. tells the model that thin evidence lowers CONFIDENCE, it never
     *      defaults the answer to false.
     *
     * @return array{fitLevels: string[], questions: array<string, array>}
     */
    public static function buildDecisionQuestions(): array
    {
        $mustHaves = self::icpMustHaves();
        $enumerated = [];
        foreach ($mustHaves as $i => $mustHave) {
            $enumerated[] = '(' . ($i + 1) . ') ' . $mustHave;
        }
        $mustHaveText = implode(' ', $enumerated);

        // Ordered fit levels for the score question (level 0 = worst .. level 4 = best).
        // The API returns a position on these levels; scoreToPercent() maps it to 0-100.
        $fitLevels = [
            'No fit: none of the ICP must-haves (company size, industry) are evidenced in the lead context.',
            'Weak fit: a single must-have is evidenced; major gaps or deal-breakers present.',
            'Partial fit: several must-haves evidenced, but key gaps remain.',
            'Strong fit: most must-haves evidenced; minor gaps only.',
            'Exceptional fit: both ICP must-haves strongly evidenced, no deal-breakers.',
        ];
        $questions = [
            'qualified' => JevProvider::noulQuestion(
                'This lead is a strong fit for the ideal customer profile (ICP). ' .
                'The ICP must-haves are: ' . $mustHaveText . ' ' .
                'Answer true (noul >= 0.5) when the preponderance of the lead context supports fit — ' .
                'that is, most of the must-haves above are evidenced and no deal-breaker is present. ' .
                'Do not demand that all must-haves be present; a strong fit on the balance of the evidence is enough. ' .
                'If the evidence is thin, lower your confidence — do not default the answer to false; ' .
                'report the uncertainty through the confidence value instead.'
            ),
            'score' => JevProvider::scoreQuestion(
                'Ideal-customer-profile fit for this lead, based only on evidence in the lead context.',
                $fitLevels
            ),
        ];
        return ['fitLevels' => $fitLevels, 'questions' => $questions];
    }

    /**
     * The LEGACY lead-qualification decision, preserved exactly as the
     * pre-weighted behavior: off/shadow mode and every fail-closed path in
     * ScoreLeadFitAction resolve to this.
     *
     * Modes (settings jev_enabled / jev_mode):
     *   off    — legacy LLM path, exactly as before (default).
     *   shadow — legacy result returned; Jev answers + agreement logged to
     *            logs/jev_shadow.jsonl for evaluation. Zero behavior change.
     *   live   — Jev's qualified/score returned; the legacy LLM runs only on
     *            Jev error or low confidence. The reason is synthesized since
     *            Jev is decision-only and cannot write prose.
     *
     * The Jev call runs under a hard per-decision timeout; on timeout or any
     * error the legacy path takes over (fail-closed to the existing behavior).
     */
    private function legacyDecideQualification(string $persona, string $goal, string $context): array
    {
        $state = [
            'persona' => $persona,
            'goal' => $goal,
            'lead_context' => $context,
        ];
        $built = self::buildDecisionQuestions();
        $fitLevels = $built['fitLevels'];
        $questions = $built['questions'];

        $answers = DecisionTier::decide(
            'qualify_lead.decide_qualification',
            $state,
            $questions,
            fn() => $this->callAgent($persona, $goal, $context),
            function ($a) use ($fitLevels) {
                // Normalize both Jev answers and legacy results to [qualified, score].
                if (is_array($a) && isset($a['qualified']) && is_array($a['qualified']) && isset($a['qualified']['noul'])) {
                    $position = (float)($a['score']['score'] ?? 0);
                    $score100 = JevProvider::scoreToPercent($position, count($fitLevels));
                    return [(float)$a['qualified']['noul'] >= 0.5, $score100];
                }
                return [(bool)($a['qualified'] ?? false), (float)($a['score'] ?? 0)];
            },
            fn($jv, $lv) => $jv[0] === $lv[0] && abs($jv[1] - $lv[1]) <= 15,
            self::JEV_TIMEOUT_SECONDS
        );

        return $answers;
    }

    /**
     * Converts Jev answers (live mode) — or passes through the legacy result
     * (off/shadow mode) — into the qualification shape this action stores.
     */
    private function answersToQualification($answers, array $fitLevels): array
    {
        if (
            is_array($answers) && isset($answers['qualified'])
            && is_array($answers['qualified']) && isset($answers['qualified']['noul'])
        ) {
            $prob = (float)$answers['qualified']['noul'];
            $position = (float)($answers['score']['score'] ?? 0);
            $score = (int)round(JevProvider::scoreToPercent($position, count($fitLevels)));
            $verdict = $prob >= 0.5 ? 'qualified' : 'not qualified';
            return [
                'qualified' => $prob >= 0.5,
                'score' => $score,
                'reason' => sprintf(
                    'Jev decision tier: %s (p=%.2f), ICP fit score %d/100.',
                    $verdict,
                    $prob,
                    $score
                ),
                'source' => 'jev',
            ];
        }

        $legacy = is_array($answers) ? $answers : [];
        $legacy['source'] = 'llm';
        return $legacy;
    }
}
