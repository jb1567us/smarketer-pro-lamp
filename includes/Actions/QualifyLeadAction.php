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

        $data = $this->decideQualification($persona, $goal, $context);

        $isQualified = $data['qualified'] ?? false;
        $status = $isQualified ? 'Qualified' : 'Unqualified';
        $score = $data['score'] ?? 0;
        $reason = $data['reason'] ?? '';

        // Phase 0 fix (was critical defect C2): qualification used to OVERWRITE
        // leads.notes, destroying the enrichment research the drafter needs.
        // The verdict is now APPENDED with a clear marker; existing notes
        // (enrichment data, prior drafts) are preserved.
        $existingNotes = (string)($lead['notes'] ?? '');
        $qualNote = "\n\n[Qualification " . date('Y-m-d') . "]: {$status} (score {$score}) — {$reason}";
        $newNotes = $existingNotes . $qualNote;

        $stmt = $this->pdo->prepare("UPDATE leads SET lead_score = ?, status = ?, notes = ? WHERE id = ?");
        $stmt->execute([$score, $status, $newNotes, $leadId]);

        return true;
    }

    /**
     * The app's actual Ideal Customer Profile must-haves.
     *
     * Sourced from the 'Chat Qualifier' prompt in includes/Prompts/PromptRegistry.php —
     * the only place in the codebase that spells out what the ICP consists of:
     * size, industry, and tech stack. The 'Qualifier' / 'B2B ICP Specialist'
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
     * @return string[]
     */
    public static function icpMustHaves(): array
    {
        return [
            'Company size: the company falls in the size range we sell to.',
            'Industry: the company operates in an industry we target.',
            'Tech stack: the company\'s technology is compatible with or adjacent to what we support.',
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
            'No fit: none of the ICP must-haves (company size, industry, tech stack) are evidenced in the lead context.',
            'Weak fit: a single must-have is evidenced; major gaps or deal-breakers present.',
            'Partial fit: several must-haves evidenced, but key gaps remain.',
            'Strong fit: most must-haves evidenced; minor gaps only.',
            'Exceptional fit: all three ICP must-haves strongly evidenced, no deal-breakers.',
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
     * Lead-qualification decision routed through the Jev decision tier.
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
    private function decideQualification(string $persona, string $goal, string $context): array
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

        return $this->answersToQualification($answers, $fitLevels);
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
