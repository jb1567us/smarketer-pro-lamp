<?php
/**
 * Agent Lab Chat Endpoint
 * Allows direct interaction with specific agent personas.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/../includes/Agents/CreativeAgent.php';
$pdo = \App\Database::getConnection();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $persona = $input['persona'] ?? '';
    $context = $input['context'] ?? '';
    $instruction = $input['instruction'] ?? '';

    if (!$persona || !$context) {
        echo json_encode(['success' => false, 'error' => 'Missing persona or context']);
        exit;
    }

    try {
        $result = null;
        $agentGoal = "Direct playground chat interaction";
        $providerName = "none";

        // De-orphan agent classes and dynamically instantiate matching subclass agents
        if ($persona === 'Social Media Strategist') {
            $agent = new \App\Agents\SocialMediaAgent($pdo);
            $result = $agent->think($context, $instruction);
            $agentGoal = "Generate viral hooks and engagement strategies for TikTok/Instagram.";
        } elseif ($persona === 'Ad Copywriter') {
            $agent = new \App\Agents\AdCopyAgent($pdo);
            $result = $agent->think($context, $instruction);
            $agentGoal = "Write high-converting ads for Google/FB.";
        } elseif ($persona === 'Brainstormer') {
            $agent = new \App\Agents\BrainstormerAgent($pdo);
            $result = $agent->think($context, $instruction);
            $agentGoal = "Brainstorm unique campaign angles and concepts.";
        } elseif ($persona === 'Video Director') {
            $agent = new \App\Agents\VideoAgent($pdo);
            $res = $agent->createVideo($context);
            if ($res['success']) {
                $response = "🎬 **Video Generation Request Queued Successfully!**\n\n";
                $response .= "- **Provider**: " . ucfirst($res['provider']) . "\n";
                $response .= "- **Job ID**: " . $res['job_id'] . "\n";
                $response .= "- **Status**: " . ucfirst($res['status']) . "\n\n";
                $response .= "### 📝 Prompt Engineered Visual Script:\n";
                $response .= $res['prompt_used'];
                
                $result = [
                    'success' => true,
                    'data' => [
                        'response' => $response,
                        'provider' => $res['provider']
                    ]
                ];
            } else {
                $promptFallback = $agent->generatePrompt($context);
                $response = "🎬 **Video Director Staged**\n\n";
                $response .= "⚠️ **Notice**: " . $res['error'] . ".\n\n";
                $response .= "To enable direct video generation, go to the main dashboard → Settings and enter your Runway/Sora API key.\n\n";
                $response .= "### 📝 Prompt Engineered Visual Script:\n";
                $response .= $promptFallback;
                
                $result = [
                    'success' => true,
                    'data' => [
                        'response' => $response,
                        'provider' => 'none'
                    ]
                ];
            }
            $agentGoal = "Engineer detailed cinematic visual prompts and simulate video jobs.";
        } elseif ($persona === 'WordPress Expert') {
            $agent = new \App\Agents\WordPressAgent($pdo);
            $wpUrl = \App\Database::getSetting('wp_site_url');
            $wpUser = \App\Database::getSetting('wp_username');
            $wpPass = \App\Database::getSetting('wp_app_password');
            
            if (!$wpUrl || !$wpUser || !$wpPass) {
                $response = "🖥️ **WordPress Agent Staged**\n\n";
                $response .= "I am ready to publish content to WordPress, but some credentials are not configured in your Settings.\n\n";
                $response .= "### 📝 Staged Post Markdown:\n";
                $response .= "**Title**: Automated B2B Outreach Insights\n\n";
                $response .= $context;
                
                $result = [
                    'success' => true,
                    'data' => [
                        'response' => $response,
                        'provider' => 'none'
                    ]
                ];
            } else {
                $postData = [
                    'title' => 'Automated Outreach Insights ' . date('Y-m-d H:i'),
                    'content' => $context,
                    'status' => 'draft'
                ];
                $res = $agent->publishPost($wpUrl, $wpUser, $wpPass, $postData);
                if ($res['success']) {
                    $response = "🚀 **Successfully Published to WordPress!**\n\n";
                    $response .= "- **Post Title**: " . $postData['title'] . "\n";
                    $response .= "- **Status**: Draft (Staged for Review)\n";
                    $response .= "- **WordPress REST API Link**: " . ($res['data']['link'] ?? $wpUrl) . "\n\n";
                    $response .= "### 📝 Content Transmitted:\n" . $context;
                    
                    $result = [
                        'success' => true,
                        'data' => [
                            'response' => $response,
                            'provider' => 'wordpress_rest'
                        ]
                    ];
                } else {
                    throw new Exception("WordPress REST API publishing failed: " . $res['error']);
                }
            }
            $agentGoal = "Format and publish lead intelligence directly to remote WordPress.";
        } elseif ($persona === 'Extraction Expert') {
            $agentGoal = "Perform high-fidelity lead contact and metadata extraction.";
            $isUrl = filter_var(trim($context), FILTER_VALIDATE_URL);
            if ($isUrl) {
                // Perform real live proxy-rotating full extraction!
                $res = \App\ExtractionEngine::fullExtract(trim($context));
                if (isset($res['emails']) || isset($res['phones']) || isset($res['socials']) || isset($res['tech_stack'])) {
                    $response = "🕸️ **Live Deep Web Lead Extraction Results (Proxy-Rotated)**\n\n";
                    $response .= "- **Target URL**: " . trim($context) . "\n";
                    $response .= "- **Emails Found**: " . (!empty($res['emails']) ? implode(', ', $res['emails']) : 'None') . "\n";
                    $response .= "- **Phones Found**: " . (!empty($res['phones']) ? implode(', ', $res['phones']) : 'None') . "\n";
                    $response .= "- **Social Profiles**: " . (!empty($res['socials']) ? implode(', ', $res['socials']) : 'None') . "\n";
                    $response .= "- **Tech Stack**: " . (!empty($res['tech_stack']) ? implode(', ', $res['tech_stack']) : 'None') . "\n\n";
                    $response .= "### 📝 Extracted Raw Metadata:\n";
                    $response .= json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    
                    $result = [
                        'success' => true,
                        'data' => [
                            'response' => $response,
                            'provider' => 'rotating_proxies'
                        ]
                    ];
                } else {
                    $response = "🕸️ **Deep Web Extraction Staged**\n\n";
                    $response .= "⚠️ **Extraction Notice**: " . ($res['error'] ?? $res['reason'] ?? 'Failed to reach host or no contacts found') . ".\n\n";
                    $response .= "To run automated crawling, ensure rotating proxies are active and safe in the Mass Harvester tab.";
                    
                    $result = [
                        'success' => true,
                        'data' => [
                            'response' => $response,
                            'provider' => 'none'
                        ]
                    ];
                }
            } else {
                // Fallback to text-based extraction via AI prompt
                $registryKey = 'Extraction Expert';
                $systemBehavior = \App\Prompts\PromptRegistry::getSystemPrompt($registryKey, 'extract lead details', $context);
                $prompt = "### ROLE: {$systemBehavior}\n\n";
                if ($instruction) {
                    $prompt .= "### YOUR SPECIFIC INSTRUCTIONS FOR THIS TASK:\n{$instruction}\n\n";
                }
                $prompt .= "### CONTEXT / DATA TO PROCESS:\n{$context}\n\n";
                $prompt .= "Please perform extraction on this raw text now:";
                $router = new \App\Routers\SmartLLMRouter($pdo);
                $result = $router->generate($prompt, 'performance', false);
            }
        } elseif ($persona === 'Intent Analyst') {
            $agentGoal = "Analyze B2B leads and score their intent triggers.";
            $extractedData = json_decode($context, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($extractedData)) {
                // Scoring logic dry-run from IntentAnalyst
                $score = 0;
                $signals = [];
                if (isset($extractedData['tech_stack'])) {
                    $tech = is_array($extractedData['tech_stack']) ? implode(', ', $extractedData['tech_stack']) : $extractedData['tech_stack'];
                    $tech = strtolower((string)$tech);
                    if (strpos($tech, 'shopify') !== false || strpos($tech, 'ecommerce') !== false) {
                        $score += 30;
                        $signals[] = "E-commerce Infrastructure Detected (+30)";
                    }
                    if (strpos($tech, 'hubspot') !== false || strpos($tech, 'marketo') !== false) {
                        $score += 20;
                        $signals[] = "Advanced Marketing Stack (+20)";
                    }
                }
                if (isset($extractedData['content'])) {
                    $text = is_array($extractedData['content']) ? implode(' ', $extractedData['content']) : $extractedData['content'];
                    $text = strtolower((string)$text);
                    $keywords = [
                        'hiring' => 15,
                        'expansion' => 15,
                        'new office' => 20,
                        'partnership' => 10,
                        'contact us' => 5
                    ];
                    foreach ($keywords as $word => $val) {
                        if (strpos($text, $word) !== false) {
                            $score += $val;
                            $signals[] = "Intent Keyword: " . ucfirst($word) . " (+$val)";
                        }
                    }
                }
                if (!empty($extractedData['email'])) {
                    $score += 15;
                    $signals[] = "Direct Contact Found (+15)";
                }
                $finalScore = min($score, 100);
                
                $response = "📊 **High-Fidelity B2B Intent Analysis Report (Dry-Run)**\n\n";
                $response .= "- **Final Score**: **" . $finalScore . " / 100**\n";
                $response .= "- **Status**: " . ($finalScore >= 70 ? "🔥 HIGH INTENT (Qualified)" : "❄️ LOW INTENT (Cold)") . "\n\n";
                $response .= "### 📡 Detected Intent Signals:\n";
                if (!empty($signals)) {
                    foreach ($signals as $sig) {
                        $response .= "- " . $sig . "\n";
                    }
                } else {
                    $response .= "- No B2B intent signals detected in the provided JSON payload.\n";
                }
                $response .= "\n### 📝 Raw Analysis Metadata:\n";
                $response .= json_encode([
                    'score' => $finalScore,
                    'signals' => $signals,
                    'input_parsed' => $extractedData
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                
                $result = [
                    'success' => true,
                    'data' => [
                        'response' => $response,
                        'provider' => 'intent_scoring_engine'
                    ]
                ];
            } else {
                // Dry-run based on raw text search
                $score = 0;
                $signals = [];
                $text = strtolower($context);
                if (strpos($text, 'shopify') !== false || strpos($text, 'woocommerce') !== false) {
                    $score += 30;
                    $signals[] = "E-commerce Infrastructure Detected (+30)";
                }
                if (strpos($text, 'hubspot') !== false || strpos($text, 'marketo') !== false) {
                    $score += 20;
                    $signals[] = "Advanced Marketing Stack (+20)";
                }
                $keywords = [
                    'hiring' => 15,
                    'expansion' => 15,
                    'new office' => 20,
                    'partnership' => 10,
                    'contact us' => 5
                ];
                foreach ($keywords as $word => $val) {
                    if (strpos($text, $word) !== false) {
                        $score += $val;
                        $signals[] = "Intent Keyword: " . ucfirst($word) . " (+$val)";
                    }
                }
                if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text)) {
                    $score += 15;
                    $signals[] = "Direct Contact Email Found (+15)";
                }
                $finalScore = min($score, 100);
                
                $response = "📊 **Raw Text B2B Intent Analysis Report (Dry-Run)**\n\n";
                $response .= "- **Calculated Score**: **" . $finalScore . " / 100**\n";
                $response .= "- **Staged Status**: " . ($finalScore >= 70 ? "🔥 HIGH INTENT (Qualified)" : "❄️ LOW INTENT (Cold)") . "\n\n";
                $response .= "### 📡 Extracted Raw Signals:\n";
                if (!empty($signals)) {
                    foreach ($signals as $sig) {
                        $response .= "- " . $sig . "\n";
                    }
                } else {
                    $response .= "- No intent triggers found in text context.\n";
                }
                
                $result = [
                    'success' => true,
                    'data' => [
                        'response' => $response,
                        'provider' => 'intent_scoring_engine'
                    ]
                ];
            }
        } else {
            // Standard persona fallback to PromptRegistry and direct SmartLLMRouter call
            $registryKey = $persona;
            if ($persona === 'Copywriter') {
                $registryKey = 'Chat Copywriter';
            } elseif ($persona === 'Researcher') {
                $registryKey = 'Chat Researcher';
            } elseif ($persona === 'Qualifier') {
                $registryKey = 'Chat Qualifier';
            }

            // Derive highly specific, context-relevant goals for each agent persona
            $defaultGoals = [
                'Researcher' => 'Deep-dive research on target company website and background',
                'Qualifier' => 'Assess Ideal Customer Profile (ICP) match and qualify lead',
                'Intent Analyst' => 'Identify semantic business intent triggers and score lead intent',
                'Influencer Scout' => 'Enrich profile data and infer tech stack or pain points',
                'Copywriter' => 'Draft a compelling personalized outreach email',
                'LinkedIn Specialist' => 'Draft a high-converting LinkedIn message and connection note',
                'Ad Copywriter' => 'Generate high-CTR ad copy headlines and description',
                'Social Media Strategist' => 'Generate organic social posts and hooks',
                'Brainstormer' => 'Generate unique outreach ideas and campaign angles',
                'WordPress Expert' => 'Format and publish lead intelligence to WordPress',
                'Video Director' => 'Create cinematic visual scene-by-scene script breakdown',
                'Extraction Expert' => 'Extract emails, phones, and social URLs from web text'
            ];
            $derivedGoal = $defaultGoals[$persona] ?? 'Direct playground chat interaction';

            $systemBehavior = \App\Prompts\PromptRegistry::getSystemPrompt($registryKey, $derivedGoal, $context);

            $prompt = "### ROLE: {$systemBehavior}\n\n";
            if ($instruction) {
                $prompt .= "### YOUR SPECIFIC INSTRUCTIONS FOR THIS TASK:\n{$instruction}\n\n";
            }
            $prompt .= "### CONTEXT / DATA TO PROCESS:\n{$context}\n\n";
            $prompt .= "Please provide your professional output now:";

            $router = new \App\Routers\SmartLLMRouter($pdo);
            $result = $router->generate($prompt, 'performance', false);
        }

        // Special handling for Media-Generating Personas inside default flow
        $mediaUrl = null;
        if (in_array($persona, ['Graphics Designer', 'Video Director', 'UI Designer'])) {
            $mediaUrl = 'assets/placeholder_designer.png';
        }

        $resPayload = isset($result['data']) ? $result['data'] : $result;
        $responseContent = $resPayload['response'] ?? '';
        $providerName = $resPayload['provider'] ?? 'none';

        // Write Audit Trace Log to database
        $mode = \App\Database::getSetting('operational_mode') ?: 'Production';
        $traceStmt = $pdo->prepare("
            INSERT INTO agent_traces (lead_id, persona, goal, context, reasoning_output, operational_mode) 
            VALUES (NULL, ?, ?, ?, ?, ?)
        ");
        $traceStmt->execute([
            $persona,
            $agentGoal,
            $context,
            $responseContent,
            $mode
        ]);

        echo json_encode([
            'success' => true,
            'data'    => array_merge($resPayload, ['media_url' => $mediaUrl]),
            'meta'    => [
                'persona'   => $persona,
                'timestamp' => date('c')
            ]
        ]);

    } catch (\App\Exceptions\OutreachException $e) {
        echo json_encode([
            'success' => true,
            'data'    => [
                'response' => "⚠️ No LLM provider is configured.\n\nTo use Agent Lab:\n1. Go to the main dashboard → Settings tab\n2. Enter at least one API key (Gemini is free at aistudio.google.com)\n3. Click Save Configuration\n4. Return here and try again.",
                'provider' => 'none'
            ],
            'meta' => ['persona' => $persona, 'timestamp' => date('c')]
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
