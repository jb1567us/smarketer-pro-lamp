<?php
/**
 * Outreach Execution Engine
 */
require_once 'db.php';

class OutreachRunner {
    private $pdo;
    private $emailRouter;
    private $llmRouter;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        require_once __DIR__ . '/Routers/SmartEmailRouter.php';
        require_once __DIR__ . '/Routers/SmartLLMRouter.php';
        $this->emailRouter = new SmartEmailRouter($pdo);
        $this->llmRouter = new SmartLLMRouter($pdo);
    }

    /**
     * Main task processor
     */
    public function processTask($taskId) {
        $stmt = $this->pdo->prepare("SELECT * FROM task_queue WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) return false;

        $this->updateTaskStatus($taskId, 'In Progress');

        try {
            $result = false;
            switch ($task['task_type']) {
                case 'Qualify':
                    $result = $this->runQualification($task['lead_id']);
                    break;
                case 'Enrich':
                    $result = $this->runEnrichment($task['lead_id']);
                    break;
                case 'Draft':
                    $result = $this->runDrafting($task['lead_id']);
                    break;
                case 'EmailOutreach':
                    $result = $this->runEmailOutreach($task);
                    break;
                default:
                    throw new Exception("Unknown task type: " . $task['task_type']);
            }

            if ($result) {
                $this->updateTaskStatus($taskId, 'Completed');
            } else {
                $this->updateTaskStatus($taskId, 'Failed', 'Processing returned false');
            }

        } catch (Exception $e) {
            $this->updateTaskStatus($taskId, 'Failed', $e->getMessage());
        }
    }

    private function logTrace($leadId, $persona, $goal, $context, $output) {
        $mode = getSetting($this->pdo, 'operational_mode') ?: 'Production';
        $stmt = $this->pdo->prepare("INSERT INTO agent_traces (lead_id, persona, goal, context, reasoning_output, operational_mode) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$leadId, $persona, $goal, $context, json_encode($output), $mode]);
    }

    /**
     * Retrieves the specific system prompt for a given persona.
     * Ported from Python Agent classes.
     */
    private function getSystemPrompt($persona, $goal, $context) {
        switch ($persona) {
            // --- SYSTEM & ADMIN ---
            case 'Manager':
                return "You are the Manager Agent. Your goal is to oversee operations and coordinate tasks. 
                        Analyze the user's request and provide a high-level plan or delegation strategy.
                        GOAL: {$goal}";
            
            case 'Reviewer':
                return "You are a Content Quality & Safety Reviewer. 
                        Critique the provided content for tone, safety, relevance, and logic.
                        Return a JSON object with keys: 'approved' (boolean), 'critique' (bullet points), 'score' (1-10).";

            case 'Syntax Validator':
                return "You are a Code Syntax Validator. Check the provided code snippet for errors and best practices.
                        Return JSON: {'valid': bool, 'errors': [], 'fixed_code': string}";

            case 'Product Manager':
                return "You are a Product Manager. Define features, user stories, and acceptance criteria based on the idea.";

            // --- MARKETING & CONTENT ---
            case 'Copywriter':
            case 'Polymorphic Outreach Writer':
                return "You are an expert Copywriter. Draft high-converting content based on the goal: {$goal}.
                        Focus on persuasion, clarity, and engagement.";

            case 'Social Media Strategist':
                return "You are a Social Media Strategist. Create engaging posts for LinkedIn/Twitter based on the context.
                        Include hooks, value props, and hashtags.";

            case 'Ad Copywriter':
                return "You are a Direct Response Ad Copywriter. Write punchy, high-CTR ad copy (Headlines, Body) for the product.";

            case 'Graphics Designer':
                return "You are a Generative Art Director. 
                        Analyze the concept: '{$context}'.
                        Output a specialized Image Generation Prompt optimized for Stable Diffusion or Midjourney.
                        Return JSON: {'image_prompt': string, 'style_notes': string, 'suggested_aspect_ratio': string}";

            case 'Video Director':
                return "You are a Video Production Director. 
                        Create a script and scene-by-scene breakdown for a video based on the goal: {$goal}.
                        Return JSON format.";

            case 'Brainstormer':
                return "You are a Creative Brainstorming Partner. Generate 10 unique, divergent ideas for the topic.";

            // --- RESEARCH & LEADS ---
            case 'Researcher':
            case 'Research Agent':
                return "You are a deep-dive Web Researcher. Find specific, verifiable information about: {$goal}. 
                        Cite sources if possible.";

            case 'Qualifier':
            case 'B2B ICP Specialist':
                return "You are a B2B Lead Qualifier. Analyze the lead against the Ideal Customer Profile (ICP).
                        Return JSON: {'qualified': bool, 'score': 0-100, 'reason': string}";

            case 'LinkedIn Specialist':
                return "You are a LinkedIn Outreach Expert. 
                        Draft a connection request (max 300 chars) and a follow-up InMail based on the profile highlights.
                        Focus on 'what's in it for them' and peer-to-peer tone.";

            case 'Contact Form Specialist':
                return "You are a Contact Form Message Optimizer. 
                        Draft a concise message suitable for a 'Contact Us' form. 
                        Avoid HTML links if possible to bypass spam filters.";

            case 'Influencer Scout':
            case 'Data Enrichment Analyst':
                return "You are a Data Enrichment Analyst. 
                        Infer detailed metadata (Industry, Tech Stack, Pain Points) from the limited context provided.";

            // --- SEO & GROWTH ---
            case 'SEO Expert':
                return "You are a Technical SEO Expert. Audit the content for keywords, readability, and structural optimization.";

            case 'UX Designer':
                return "You are a UX/UI Designer. Critique the interface description or propose a layout for the goal: {$goal}.";

            case 'WordPress Expert':
                return "You are a WordPress Architect. Provide WP-CLI commands or PHP snippets to achieve the goal.";

            default:
                return "You are a helpful AI Assistant. Role: {$persona}. Goal: {$goal}.";
        }
    }

    /**
     * Unified Agent Caller using Smart Routers
     */
    private function callAgent($persona, $goal, $context, $leadId = null) {
        $systemPrompt = $this->getSystemPrompt($persona, $goal, $context);
        
        // Construct the full prompt payload for the router
        // The router expects a 'prompt' string, but we want to simulate a system/user split.
        // We'll combine them for the simple interface.
        $fullPayload = "SYSTEM: {$systemPrompt}\n\nUSER CONTEXT:\n{$context}\n\nGOAL:\n{$goal}\n\nINSTRUCTIONS: Return valid JSON only.";

        try {
            // Use the SmartLLMRouter instead of legacy curl
            // This handles Tiered Routing and Failover automatically
            return $this->llmRouter->generate($fullPayload, 'performance', true); // true = force JSON
        } catch (Exception $e) {
            // Fallback to legacy if router fails (or is not fully configured)
            return $this->legacyGeminiCall($persona, $goal, $context, $leadId);
        }
    }

    private function runQualification($leadId) {
        $stmt = $this->pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch();

        $persona = "B2B ICP Specialist";
        $goal = "Determine if this company matches a High-Value Prospect profile.";
        $context = "Company: {$lead['company_name']}\nWebsite: {$lead['website']}\nContact: {$lead['contact_name']}";
        
        try {
            $data = $this->callAgent($persona, $goal, $context, $leadId);
            $stmt = $this->pdo->prepare("UPDATE leads SET lead_score = ?, status = ?, notes = ? WHERE id = ?");
            $status = ($data['qualified'] ?? false) ? 'Qualified' : 'Unqualified';
            $stmt->execute([$data['score'] ?? 0, $status, $data['reason'] ?? '', $leadId]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function runEnrichment($leadId) {
        $stmt = $this->pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch();

        $persona = "Data Enrichment Analyst";
        $goal = "Analyze the provided company name and website to infer industry, typical tech stack, and likely pain points.";
        $context = "Company: {$lead['company_name']}\nWebsite: {$lead['website']}";

        try {
            $data = $this->callAgent($persona, $goal, $context, $leadId);
            $notes = "Industry: " . ($data['industry'] ?? 'Unknown') . "\nPain Points: " . ($data['pain_points'] ?? 'None identified');
            $stmt = $this->pdo->prepare("UPDATE leads SET status = 'Enriched', notes = ? WHERE id = ?");
            $stmt->execute([$notes, $leadId]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function runDrafting($leadId) {
        $stmt = $this->pdo->prepare("SELECT l.*, c.name as campaign_name, t.subject, t.body 
                                    FROM leads l 
                                    JOIN campaigns c 
                                    JOIN templates t ON t.campaign_id = c.id
                                    WHERE l.id = ? LIMIT 1");
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch();
        if (!$lead) return false;

        $persona = "Polymorphic Outreach Writer";
        $goal = "Draft a highly personalized outreach email using the provided template and lead details.";
        $context = "Lead: {$lead['company_name']}\nContact: {$lead['contact_name']}\nNotes: {$lead['notes']}\nTemplate Subject: {$lead['subject']}\nTemplate Body: {$lead['body']}";

        try {
            $data = $this->callAgent($persona, $goal, $context, $leadId);
            $finalBody = $data['email_body'] ?? $lead['body'];
            $stmt = $this->pdo->prepare("UPDATE leads SET status = 'Drafted', notes = CONCAT(notes, '\n\nDraft: ', ?) WHERE id = ?");
            $stmt->execute([$finalBody, $leadId]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function runEmailOutreach($task) {
        // Use SmartEmailRouter instead of static mail
        $stmt = $this->pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $stmt->execute([$task['lead_id']]);
        $lead = $stmt->fetch();
        
        if (!$lead || !$lead['email']) return false;
        
        $subject = "Opportunity for " . $lead['company_name']; // Placeholder, should come from drafts
        $body = $lead['notes']; // Placeholder
        
        // This leverages the new routing with limits and failover
        return $this->emailRouter->send($lead['email'], $subject, $body);
    }

    private function updateTaskStatus($id, $status, $error = null) {
        $stmt = $this->pdo->prepare("UPDATE task_queue SET status = ?, error_message = ?, processed_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $error, $id]);
    }
}
?>
