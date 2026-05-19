<?php
namespace App\Agents;

use App\Database;

/**
 * Email Drafting Agent
 * Generates personalized outreach based on lead data and intent signals.
 */
class EmailDraftingAgent {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function draft($leadId) {
        $stmt = $this->pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch();

        if (!$lead) return ['success' => false, 'error' => 'Lead not found'];

        // Simple template-based drafting (can be enhanced with LLM calls later)
        $company = $lead['company_name'];
        $contact = $lead['contact_name'] ?: 'there';
        $score = $lead['lead_score'];

        $subject = "Quick question regarding $company";
        
        if ($score > 70) {
            $body = "Hi $contact,\n\nI was impressed by $company's digital presence. Based on our analysis, your team seems to be prioritizing growth right now.\n\nWould you be open to a quick chat about how we can support your expansion?\n\nBest regards,\nRevenue Team";
        } else {
            $body = "Hi $contact,\n\nI'm reaching out from the Revenue Intelligence team. We've been following $company and would love to introduce our services.\n\nDo you have 5 minutes this week?\n\nBest regards,\nRevenue Team";
        }

        // Save trace
        $stmt = $this->pdo->prepare("INSERT INTO agent_traces (lead_id, persona, goal, reasoning_output) VALUES (?, 'Email Drafter', 'Draft personalized outreach', ?)");
        $stmt->execute([$leadId, "Drafted based on score $score. Subject: $subject"]);

        // Update lead status
        $stmt = $this->pdo->prepare("UPDATE leads SET status = 'Drafted', notes = CONCAT(IFNULL(notes,''), '\nEmail Drafted') WHERE id = ?");
        $stmt->execute([$leadId]);

        return [
            'success' => true,
            'subject' => $subject,
            'body' => $body
        ];
    }
}
