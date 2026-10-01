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
        // The model sometimes returns a JSON array for these fields — normalize.
        if (is_array($industry)) { $industry = implode('; ', $industry); }
        if (is_array($painPoints)) { $painPoints = implode('; ', $painPoints); }

        // Same defect class as the old qualification bug (C2): never wipe
        // notes. Enrichment is stored as a marked block; re-running enrich
        // REPLACES the previous enrichment block instead of duplicating it,
        // and never touches qualification verdicts or drafts appended later.
        $block = "[Enrichment " . date('Y-m-d') . "]:\nIndustry: {$industry}\nPain Points: {$painPoints}";
        $existing = (string)($lead['notes'] ?? '');
        if (preg_match('/\\[Enrichment \\d{4}-\\d{2}-\\d{2}\\]:.*?(?=\\n\\n\\[|\\z)/s', $existing)) {
            $newNotes = preg_replace('/\\[Enrichment \\d{4}-\\d{2}-\\d{2}\\]:.*?(?=\\n\\n\\[|\\z)/s', $block, $existing);
        } else {
            $newNotes = $existing . ($existing !== '' ? "\n\n" : '') . $block;
        }

        $stmt = $this->pdo->prepare("UPDATE leads SET status = 'Enriched', notes = ? WHERE id = ?");
        $stmt->execute([$newNotes, $leadId]);

        return true;
    }
}
