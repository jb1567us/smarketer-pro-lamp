# Preservation Checklist

Ensure these behaviors are preserved after refactoring.

- [ ] **🔴 CRITICAL**: Ensure that PromptRegistry::getSystemPrompt() maintains exact matches or backward-compatible fallbacks for existing actions (DraftOutreachAction, EnrichLeadAction, QualifyLeadAction) and CreativeAgent subclasses.
  - *Source: d:\sandbox\b2b_outreach_lamp\includes\Prompts\PromptRegistry.php*
- [ ] **🔴 CRITICAL**: Ensure that api/agent_chat.php persona system prompts (Intent Analyst, Extraction Expert, Researcher, Qualifier, Copywriter) are successfully delegated to PromptRegistry::getSystemPrompt() and return the exact same system prompts for those chat personas.
  - *Source: d:\sandbox\b2b_outreach_lamp\api\agent_chat.php*
- [ ] **🔴 CRITICAL**: ExtractionExpert::processLead must return 'content' and 'tech_stack' keys so they can be parsed by IntentAnalyst downstream.
  - *Source: d:\sandbox\b2b_outreach_lamp\includes\Agents\ExtractionExpert.php*
- [ ] **🔴 CRITICAL**: CreativeAgent::think must construct a single payload string and invoke generate($prompt, 'performance') matching the signature of SmartLLMRouter::generate.
  - *Source: d:\sandbox\b2b_outreach_lamp\includes\Agents\CreativeAgent.php*
- [ ] **🔴 CRITICAL**: Every successful LLM router call inside api/agent_chat.php must insert a trace record into the agent_traces table for SaaS completeness auditing.
  - *Source: d:\sandbox\b2b_outreach_lamp\api\agent_chat.php*
- [ ] **🔴 CRITICAL**: ExtractionExpert::processLead must preserve: (1) Gemini AI intent scoring pipeline (analyzeIntent), (2) lead status update to Qualified/Cold based on email presence, (3) tech stack detection, (4) summary/score DB write. The scrapeWebsite and extractEmails methods are being replaced by ExtractionEngine::fullExtract which provides proxy rotation, subpage crawling, phone extraction, and domain blocklisting.
  - *Source: d:\sandbox\b2b_outreach_lamp\includes\Agents\ExtractionExpert.php*
- [ ] **🔴 CRITICAL**: ExtractionExpert must use SmartLLMRouter economy tier with forceJson=true to ensure high-efficiency intent scoring and proper failover. The return structure must always yield an array containing 'score' and 'summary' keys.
  - *Source: includes/Agents/ExtractionExpert.php*
- [ ] **🔴 CRITICAL**: VideoAgent must support unnested response payloads returned by SmartLLMRouter to prevent prompt refinement failures and default mocking fallbacks. It should check for both direct $result['response'] and nested $result['data']['response'] keys.
  - *Source: includes/Agents/VideoAgent.php*