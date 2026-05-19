<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\OutreachException;

class DraftOutreachAction extends AbstractAction
{
    public function execute(int $leadId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT l.*, c.name as campaign_name, t.subject, t.body 
            FROM leads l 
            JOIN campaigns c ON 1=1 -- Assume they map somehow, adjusting legacy logic which lacked a direct link
            JOIN templates t ON t.campaign_id = c.id
            WHERE l.id = ? LIMIT 1
        ");
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch(\App\PDO::FETCH_ASSOC);

        if (!$lead) {
            throw new OutreachException("Lead ID {$leadId} not found or no associated template.");
        }

        $persona = "Polymorphic Outreach Writer";
        $goal = "Draft a highly personalized outreach email using the provided template and lead details.";
        
        $company = $lead['company_name'] ?? 'Unknown';
        $contact = $lead['contact_name'] ?? 'Unknown';
        $notes = $lead['notes'] ?? '';
        $subject = $lead['subject'] ?? '';
        $body = $lead['body'] ?? '';
        
        $context = "Lead: {$company}\nContact: {$contact}\nNotes: {$notes}\nTemplate Subject: {$subject}\nTemplate Body: {$body}";

        $data = $this->callAgent($persona, $goal, $context, $leadId);
        
        $finalBody = $data['email_body'] ?? $body;
        
        $stmt = $this->pdo->prepare("UPDATE leads SET status = 'Drafted', notes = CONCAT(notes, '\n\nDraft: ', ?) WHERE id = ?");
        $stmt->execute([$finalBody, $leadId]);

        return true;
    }
}
