<?php
namespace App\Agents;

use App\Database;

/**
 * Intent Analyst Agent
 * Scores leads based on extracted website data, tech stack, and intent signals.
 */
class IntentAnalyst {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function analyze($leadId, $extractedData) {
        $score = 0;
        $signals = [];

        // Logic 1: Tech Stack Signals (High value)
        if (isset($extractedData['tech_stack'])) {
            $tech = is_array($extractedData['tech_stack']) ? implode(', ', $extractedData['tech_stack']) : $extractedData['tech_stack'];
            $tech = strtolower((string)$tech);
            if (strpos($tech, 'shopify') !== false || strpos($tech, 'ecommerce') !== false) {
                $score += 30;
                $signals[] = "E-commerce Infrastructure Detected";
            }
            if (strpos($tech, 'hubspot') !== false || strpos($tech, 'marketo') !== false) {
                $score += 20;
                $signals[] = "Advanced Marketing Stack";
            }
        }

        // Logic 2: Content Signals
        if (isset($extractedData['content'])) {
            $content = is_array($extractedData['content']) ? implode(' ', $extractedData['content']) : $extractedData['content'];
            $content = strtolower((string)$content);
            $keywords = [
                'hiring' => 15,
                'expansion' => 15,
                'new office' => 20,
                'partnership' => 10,
                'contact us' => 5
            ];
            foreach ($keywords as $word => $val) {
                if (strpos($content, $word) !== false) {
                    $score += $val;
                    $signals[] = "Intent Keyword: " . ucfirst($word);
                }
            }
        }

        // Logic 3: Contact Clarity
        if (!empty($extractedData['email'])) {
            $score += 15;
            $signals[] = "Direct Contact Found";
        }

        $finalScore = min($score, 100);
        $notes = "Automated Intent Analysis: " . implode(", ", $signals);

        $stmt = $this->pdo->prepare("UPDATE leads SET lead_score = ?, notes = CONCAT(IFNULL(notes,''), '\n', ?) WHERE id = ?");
        $stmt->execute([$finalScore, $notes, $leadId]);

        return [
            'success' => true,
            'score' => $finalScore,
            'signals' => $signals
        ];
    }
}
