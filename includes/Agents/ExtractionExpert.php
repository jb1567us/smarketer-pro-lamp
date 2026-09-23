<?php
namespace App\Agents;

use App\PDO;
use Exception;

class ExtractionExpert {
    private $pdo;
    private $geminiKey;
    private $firecrawlKey;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadSettings();
    }

    private function loadSettings() {
        $stmt = $this->pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('gemini_api_key', 'firecrawl_api_key')");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $this->geminiKey = $settings['gemini_api_key'] ?? '';
        $this->firecrawlKey = $settings['firecrawl_api_key'] ?? '';
    }

    /**
     * Processes a single lead: crawls website, extracts email, and analyzes intent.
     */
    public function processLead($leadId) {
        $stmt = $this->pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch();

        if (!$lead || empty($lead['website'])) {
            return ['success' => false, 'error' => 'Invalid lead or missing website.'];
        }

        try {
            // 1. Fetch & extract everything using the high-fidelity ExtractionEngine
            $extResult = \App\ExtractionEngine::fullExtract($lead['website']);
            
            if (isset($extResult['error']) || isset($extResult['blocked'])) {
                $errorMsg = $extResult['error'] ?? $extResult['reason'] ?? 'Extraction failed';
                return ['success' => false, 'error' => $errorMsg];
            }
            
            $emails = $extResult['emails'] ?? [];
            $primaryEmail = !empty($emails) ? $emails[0] : null;
            $phones = $extResult['phones'] ?? [];
            $socials = $extResult['socials'] ?? [];
            $techStack = $extResult['tech_stack'] ?? [];
            $htmlContent = $extResult['html'] ?? '';

            // 2. Analyze intent and score via Gemini
            $analysis = $this->analyzeIntent($lead, $htmlContent);
            
            // 3. Phase 7 Native Verification & Trust Scoring
            $dnsReceptive = false;
            $mxRecordsList = [];
            if ($primaryEmail) {
                $emailParts = explode('@', $primaryEmail);
                $emailDomain = end($emailParts);
                $dnsResult = \App\Verification\DNSChecker::checkReceptivity($emailDomain);
                $dnsReceptive = $dnsResult['is_receptive'] ?? false;
                $mxRecordsList = $dnsResult['mx_records'] ?? [];
            }

            // Map weighted variables
            $hasLevel1 = !empty($primaryEmail);
            $hasLevel2 = !empty($techStack);
            $hasLevel3 = !empty($phones) || !empty($socials);
            $crossReferenceMatch = ($analysis['score'] >= 70);

            $scoringResult = \App\Verification\TrustScorer::computeTrustScore(
                $hasLevel1,
                $hasLevel2,
                $hasLevel3,
                $dnsReceptive,
                1.0,
                $hasLevel2 ? 1.0 : 0.0,
                $crossReferenceMatch
            );

            $trustScore = $scoringResult['trust_score'] ?? 0;
            $trustBreakdown = json_encode($scoringResult['breakdown'] ?? []);
            $verificationStatus = $scoringResult['verification_status'] ?? 'unverified';

            // 4. Construct detailed metadata notes
            $newNotes = [];
            if (!empty($phones)) {
                $newNotes[] = "Phone Numbers: " . implode(', ', $phones);
            }
            if (!empty($socials)) {
                $socialList = [];
                foreach ($socials as $platform => $handle) {
                    $socialList[] = "$platform: $handle";
                }
                $newNotes[] = "Social Profiles: " . implode(' | ', $socialList);
            }
            if (!empty($techStack)) {
                $newNotes[] = "Tech Stack: " . implode(', ', $techStack);
            }
            $newNotes[] = "Verification Status: " . strtoupper($verificationStatus) . " (Trust Score: $trustScore/100)";
            
            $existingNotes = $lead['notes'] ? trim($lead['notes']) . "\n\n" : "";
            $notesText = $existingNotes . implode("\n", $newNotes);

            // 5. Update Database with Phase 7 Multi-tenant verification columns
            $updateStmt = $this->pdo->prepare("
                UPDATE leads 
                SET email = ?, 
                    lead_score = ?, 
                    status = ?, 
                    summary = ?,
                    notes = ?,
                    trust_score = ?,
                    trust_breakdown = ?,
                    verification_status = ?,
                    mx_records = ?,
                    tech_stack = ?
                WHERE id = ?
            ");
            
            $status = $primaryEmail ? 'Qualified' : 'Cold';
            $updateStmt->execute([
                $primaryEmail ?: $lead['email'],
                $analysis['score'] ?? $lead['lead_score'],
                $status,
                $analysis['summary'] ?? $lead['summary'],
                $notesText,
                $trustScore,
                $trustBreakdown,
                $verificationStatus,
                implode(', ', $mxRecordsList),
                implode(', ', $techStack),
                $leadId
            ]);

            return [
                'success' => true, 
                'email_found' => $primaryEmail, 
                'email' => $primaryEmail,
                'score' => $analysis['score'],
                'summary' => $analysis['summary'],
                'content' => $htmlContent,
                'tech_stack' => implode(', ', $techStack),
                'trust_score' => $trustScore,
                'verification_status' => $verificationStatus
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }


    private function analyzeIntent($lead, $content) {
        $prompt = "Analyze the following B2B lead and website content. 
        Company: {$lead['company_name']}
        Website: {$lead['website']}
        Content: " . substr($content, 0, 5000) . "
        
        Return a JSON object with:
        1. 'score': (int 0-100) probability they need B2B services.
        2. 'summary': (string) 1-sentence summary of what they do and why they are a good lead.
        
        JSON only.";

        try {
            $router = new \App\Routers\SmartLLMRouter($this->pdo);
            $result = $router->generate($prompt, 'economy', true);
            
            // Decoded JSON is returned directly when forceJson is true.
            // If it failed to parse model response text as JSON, it returns ['raw' => $text]
            if (isset($result['raw'])) {
                $jsonText = preg_replace('/```json|```/', '', $result['raw']);
                $parsed = json_decode(trim($jsonText), true);
            } else {
                $parsed = $result;
            }
            
            if (is_array($parsed) && isset($parsed['score']) && isset($parsed['summary'])) {
                return [
                    'score' => (int)$parsed['score'],
                    'summary' => (string)$parsed['summary']
                ];
            }
        } catch (\Exception $e) {
            error_log("ExtractionExpert AI Analysis failed: " . $e->getMessage());
        }

        return ['score' => 50, 'summary' => 'Failed to parse AI analysis or all providers cooled down.'];
    }
}
