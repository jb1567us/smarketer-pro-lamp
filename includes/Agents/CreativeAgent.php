<?php
namespace App\Agents;

use App\Routers\SmartLLMRouter;

class CreativeAgent {
    protected $pdo;
    protected $llmRouter;
    protected $role;
    protected $goal;

    public function __construct($pdo, $role, $goal) {
        $this->pdo = $pdo;
        $this->llmRouter = new SmartLLMRouter($pdo);
        $this->role = $role;
        $this->goal = $goal;
    }

    public function think($context, $instructions = "") {
        // Construct a clean, unified, role-based visual prompt
        $prompt = "### ROLE: {$this->role}\n";
        $prompt .= "### GOAL: {$this->goal}\n\n";
        if ($instructions) {
            $prompt .= "### SPECIFIC INSTRUCTIONS:\n{$instructions}\n\n";
        }
        $prompt .= "### CONTEXT / DATA TO PROCESS:\n{$context}\n\n";
        $prompt .= "Please provide your professional output now:";
        
        // Delegate to SmartLLMRouter using the correct method signature
        return $this->llmRouter->generate($prompt, 'performance', false);
    }
}

// Specialized Factory / Wrapper Methods
class SocialMediaAgent extends CreativeAgent {
    public function __construct($pdo) {
        parent::__construct($pdo, "Social Media Strategist", "Generate viral hooks and engagement strategies for TikTok/Instagram.");
    }
}

class AdCopyAgent extends CreativeAgent {
    public function __construct($pdo) {
        parent::__construct($pdo, "Ad Copywriter", "Write high-converting ads for Google/FB.");
    }
}

class BrainstormerAgent extends CreativeAgent {
    public function __construct($pdo) {
        parent::__construct($pdo, "Brainstormer", "Brainstorm unique campaign angles and concepts.");
    }
}
