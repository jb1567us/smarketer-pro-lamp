<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\OutreachException;
use App\Prompts\PromptRegistry;
use App\Routers\SmartLLMRouter;

abstract class AbstractAction implements ActionInterface
{
    public function __construct(
        protected \App\PDO $pdo,
        protected SmartLLMRouter $llmRouter
    ) {}

    /**
     * Helper to call the LLM Router and enforce JSON return format.
     */
    protected function callAgent(string $persona, string $goal, string $context, ?int $leadId = null): array
    {
        $systemPrompt = PromptRegistry::getSystemPrompt($persona, $goal, $context);
        
        $fullPayload = "SYSTEM: {$systemPrompt}\n\nUSER CONTEXT:\n{$context}\n\nGOAL:\n{$goal}\n\nINSTRUCTIONS: Return valid JSON only.";

        try {
            // true = force JSON response
            return $this->llmRouter->generate($fullPayload, 'performance', true);
        } catch (\Exception $e) {
            throw new OutreachException("Failed to call agent for persona '{$persona}': " . $e->getMessage(), 0, $e);
        }
    }
}
