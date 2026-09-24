<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\OutreachException;
use App\Jev\DecisionTier;
use App\Jev\JevProvider;

class QualifyLeadAction extends AbstractAction
{
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
     * Lead-qualification decision routed through the Jev decision tier.
     *
     * Modes (settings jev_enabled / jev_mode):
     *   off    — legacy LLM path, exactly as before (default).
     *   shadow — legacy result returned; Jev answers + agreement logged to
     *            logs/jev_shadow.jsonl for evaluation. Zero behavior change.
     *   live   — Jev's qualified/score returned; the legacy LLM runs only on
     *            Jev error or low confidence. The reason is synthesized since
     *            Jev is decision-only and cannot write prose.
     */
    private function decideQualification(string $persona, string $goal, string $context): array
    {
        $state = [
            'persona' => $persona,
            'goal' => $goal,
            'lead_context' => $context,
        ];
        // Ordered fit levels for the score question (level 0 = worst .. level 4 = best).
        // The API returns a position on these levels; scoreToPercent() maps it to 0-100.
        $fitLevels = [
            'No fit: none of the ideal-customer must-haves are evidenced in the lead context.',
            'Weak fit: a single must-have is evidenced; major gaps or deal-breakers present.',
            'Partial fit: several must-haves evidenced, but key gaps remain.',
            'Strong fit: most must-haves evidenced; minor gaps only.',
            'Perfect fit: every must-have evidenced and no deal-breakers.',
        ];
        $questions = [
            'qualified' => JevProvider::noulQuestion(
                'The lead matches every must-have of the ideal customer profile and has no deal-breakers. ' .
                'Answer true only if the lead context contains supporting evidence; answer false when evidence is thin.'
            ),
            'score' => JevProvider::scoreQuestion(
                'Ideal-customer-profile fit for this lead, based only on evidence in the lead context.',
                $fitLevels
            ),
        ];

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
            fn($jv, $lv) => $jv[0] === $lv[0] && abs($jv[1] - $lv[1]) <= 15
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
