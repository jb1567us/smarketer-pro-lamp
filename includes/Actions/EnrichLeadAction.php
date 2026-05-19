<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\OutreachException;

class EnrichLeadAction extends AbstractAction
{
    public function execute(int $leadId): bool
    {
        $stmt = $this->pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch(\App\PDO::FETCH_ASSOC);

        if (!$lead) {
            throw new OutreachException("Lead ID {$leadId} not found.");
        }

        $persona = "Data Enrichment Analyst";
        $goal = "Analyze the provided company name and website to infer industry, typical tech stack, and likely pain points.";
        
        $company = $lead['company_name'] ?? 'Unknown';
        $website = $lead['website'] ?? 'Unknown';
        $context = "Company: {$company}\nWebsite: {$website}";

        $data = $this->callAgent($persona, $goal, $context, $leadId);
        
        $industry = $data['industry'] ?? 'Unknown';
        $painPoints = $data['pain_points'] ?? 'None identified';
        $notes = "Industry: {$industry}\nPain Points: {$painPoints}";
        
        $stmt = $this->pdo->prepare("UPDATE leads SET status = 'Enriched', notes = ? WHERE id = ?");
        $stmt->execute([$notes, $leadId]);

        return true;
    }
}
