<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\OutreachException;

/**
 * DraftOutreachAction — generates a personalized outreach email for a lead
 * using the FIRST template (by step_order) of the lead's OWN campaign.
 *
 * Phase 0 fix (was critical defect C1): the query previously joined
 * `campaigns c ON 1=1`, so a draft could be written against an arbitrary
 * campaign's template. Drafts are now strictly scoped to
 * `leads.campaign_id`; a lead with no campaign (or a campaign with no
 * templates) fails loudly instead of drafting from the wrong template.
 *
 * buildDraft() is the reusable core: it returns subject/body/campaign and
 * persists the draft to the lead (status 'Drafted', draft appended to notes).
 * execute() keeps the ActionInterface contract for the task queue.
 */
class DraftOutreachAction extends AbstractAction
{
    /**
     * Generate + persist a draft. Returns the draft for API/UI display.
     *
     * @return array{subject:string, body:string, campaign_id:int, campaign_name:string}
     * @throws OutreachException when the lead, its campaign, or the campaign's
     *                           templates are missing.
     */
    public function buildDraft(int $leadId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch(\App\PDO::FETCH_ASSOC);
        if (!$lead) {
            throw new OutreachException("Lead ID {$leadId} not found.");
        }

        $campaignId = (int)($lead['campaign_id'] ?? 0);
        if ($campaignId <= 0) {
            throw new OutreachException(
                "Lead ID {$leadId} has no campaign assigned. Assign a campaign before drafting."
            );
        }

        $cstmt = $this->pdo->prepare("SELECT id, name FROM campaigns WHERE id = ?");
        $cstmt->execute([$campaignId]);
        $campaign = $cstmt->fetch(\App\PDO::FETCH_ASSOC);
        if (!$campaign) {
            throw new OutreachException(
                "Lead ID {$leadId} references campaign {$campaignId}, which no longer exists."
            );
        }

        // First step of THIS campaign's sequence (step_order is 1-based; the
        // drafter always works from step 1).
        $tstmt = $this->pdo->prepare(
            "SELECT subject, body FROM templates WHERE campaign_id = ? ORDER BY step_order ASC, id ASC LIMIT 1"
        );
        $tstmt->execute([$campaignId]);
        $template = $tstmt->fetch(\App\PDO::FETCH_ASSOC);
        if (!$template) {
            throw new OutreachException(
                "Campaign '{$campaign['name']}' has no templates. Add at least one template (step 1) before drafting."
            );
        }

        $company = $lead['company_name'] ?? 'Unknown';
        $contact = $lead['contact_name'] ?? 'Unknown';
        $notes = $lead['notes'] ?? '';
        $tplSubject = $template['subject'] ?? '';
        $tplBody = $template['body'] ?? '';

        $persona = "Polymorphic Outreach Writer";
        $goal = "Draft a highly personalized outreach email using the provided template and lead details. " .
                "Return JSON with exactly two keys: email_subject and email_body.";
        $context = "Lead: {$company}\nContact: {$contact}\nNotes: {$notes}\n" .
                   "Template Subject: {$tplSubject}\nTemplate Body: {$tplBody}";

        $data = $this->callAgent($persona, $goal, $context, $leadId);

        $finalSubject = trim((string)($data['email_subject'] ?? ''));
        if ($finalSubject === '') {
            $finalSubject = $tplSubject; // LLM gave no subject — keep the template's.
        }
        $finalBody = $data['email_body'] ?? $tplBody;

        // Persist: status + draft appended to notes (COALESCE guards NULL notes).
        $draftNote = "\n\n[Draft " . date('Y-m-d') . " — {$campaign['name']}]: Subject: {$finalSubject}\n{$finalBody}";
        $ustmt = $this->pdo->prepare(
            "UPDATE leads SET status = 'Drafted', notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ?"
        );
        $ustmt->execute([$draftNote, $leadId]);

        return [
            'subject' => $finalSubject,
            'body' => $finalBody,
            'campaign_id' => $campaignId,
            'campaign_name' => $campaign['name'],
        ];
    }

    public function execute(int $leadId): bool
    {
        $this->buildDraft($leadId);
        return true;
    }
}
