<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\OutreachException;

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
        
        $data = $this->callAgent($persona, $goal, $context, $leadId);
        
        $isQualified = $data['qualified'] ?? false;
        $status = $isQualified ? 'Qualified' : 'Unqualified';
        $score = $data['score'] ?? 0;
        $reason = $data['reason'] ?? '';

        $stmt = $this->pdo->prepare("UPDATE leads SET lead_score = ?, status = ?, notes = ? WHERE id = ?");
        $stmt->execute([$score, $status, $reason, $leadId]);

        return true;
    }
}
