<header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
    <div>
        <h2 class="text-3xl font-bold bg-gradient-to-r from-white to-slate-400 bg-clip-text text-transparent">🧪 AI Copywriting Lab</h2>
        <p class="text-slate-400 text-sm">Draft outreach copy, create social posts, and qualify leads with specialized marketing agents.</p>
    </div>
</header>

<div class="grid grid-cols-12 gap-6">
    
    <!-- Left: Controls -->
    <div class="col-span-4 space-y-6">
        
        <div class="bg-slate-800 rounded-xl p-6 shadow-lg border border-slate-700">
            <label class="block text-sm font-bold text-slate-300 mb-2">Select Agent Persona</label>
            <select id="agent-persona" onchange="updateAgentDetails()" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 outline-none focus:ring-2 focus:ring-blue-500 mb-4 text-white">
                <optgroup label="Research & Qualification">
                    <option value="Researcher">Researcher</option>
                    <option value="Qualifier">Qualifier</option>
                    <option value="Intent Analyst">Intent Analyst</option>
                    <option value="Influencer Scout">Influencer Scout</option>
                </optgroup>
                <optgroup label="Copywriting & Outreach">
                    <option value="Copywriter">Copywriter</option>
                    <option value="LinkedIn Specialist">LinkedIn Specialist</option>
                    <option value="Ad Copywriter">Ad Copywriter</option>
                    <option value="LinkedIn Assistant">LinkedIn Assistant 🔗</option>
                    <option value="Instagram Assistant">Instagram Assistant 📸</option>
                    <option value="X (Twitter) Assistant">X (Twitter) Assistant 🐦</option>
                    <option value="Facebook Assistant">Facebook Assistant 👥</option>
                    <option value="TikTok Assistant">TikTok Assistant 🎵</option>
                    <option value="Brainstormer">Brainstormer</option>
                </optgroup>
                <optgroup label="Advanced & Publishing">
                    <option value="WordPress Expert">WordPress Expert</option>
                    <option value="Video Director">Video Director</option>
                    <option value="Extraction Expert">Extraction Expert</option>
                </optgroup>
            </select>

            <!-- Agent Info Card -->
            <div id="agent-info-card" class="bg-slate-950/60 rounded-lg p-4 border border-slate-800/80 mb-4 hidden transition-all duration-300">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span id="agent-type-badge" class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase"></span>
                    <span id="agent-workflow-badge" class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-blue-900/40 text-blue-300 border border-blue-800/40 hidden">⚙️ Workflow Agent</span>
                </div>
                <p id="agent-description" class="text-xs text-slate-400 leading-relaxed"></p>
                
                <!-- Dynamic Workflow vs Interactive Alert Banners -->
                <div id="agent-workflow-alert" class="bg-indigo-950/40 border border-indigo-800/40 rounded-lg p-3 text-xs text-indigo-300 mt-3 space-y-1 hidden">
                    <div class="flex items-center gap-1 font-bold text-indigo-200">
                        <span>⚙️ Pipeline Workflow Agent</span>
                    </div>
                    <p class="text-indigo-400/90 leading-normal">
                        This agent runs automatically in the background during campaign execution. It is not typically called upon directly by users, but is part of the automated lead processing workflow. You can manually test its prompts and behaviors here.
                    </p>
                </div>
                
                <div id="agent-interactive-alert" class="bg-emerald-950/40 border border-emerald-800/40 rounded-lg p-3 text-xs text-emerald-300 mt-3 space-y-1 hidden">
                    <div class="flex items-center gap-1 font-bold text-emerald-200">
                        <span>💬 Direct Interactive Agent</span>
                    </div>
                    <p class="text-emerald-400/90 leading-normal">
                        This agent is designed for direct user interaction. You can input custom instructions and context to generate outreach content ready to be copied into your campaign emails or messages.
                    </p>
                </div>

                <!-- Baseline System Prompt -->
                <div id="agent-system-prompt-container" class="mt-3 hidden">
                    <span class="text-[10px] font-bold tracking-wider uppercase text-slate-500 block mb-1">Baseline System Prompt</span>
                    <div id="agent-system-prompt" class="bg-slate-900/60 border border-slate-800 rounded p-2.5 font-mono text-[11px] text-slate-300 whitespace-pre-wrap leading-normal"></div>
                </div>
            </div>

            <div class="flex justify-between items-center mb-2">
                <label class="block text-sm font-bold text-slate-300">System Instructions (Optional)</label>
                <button onclick="insertSampleInstruction()" class="text-xs text-blue-400 hover:text-blue-300 font-semibold transition">
                    Use Sample
                </button>
            </div>
            <textarea id="agent-instruction" rows="3" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500 outline-none text-slate-300 mb-4" placeholder="e.g. Be extremely sarcastic. Output CSV format only."></textarea>

            <button onclick="runAgent()" id="run-btn" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 rounded-lg transition shadow-lg shadow-blue-900/20">
                Run Agent
            </button>
        </div>

        <div class="bg-slate-800/50 rounded-xl p-6 border border-slate-700/50">
            <h3 class="text-slate-400 text-xs font-bold uppercase mb-2">Mode Status</h3>
            <div id="lab-mode-indicator" class="text-sm font-mono text-amber-400">Loading...</div>
        </div>

    </div>

    <!-- Right: Interaction -->
    <div class="col-span-8 space-y-6">
        
        <!-- Input Context -->
        <div class="bg-slate-800 rounded-xl p-6 shadow-lg border border-slate-700">
            <div class="flex justify-between items-center mb-2">
                <label class="block text-sm font-bold text-slate-300">Context / Input Data</label>
                <div class="flex items-center gap-4">
                    <button onclick="insertSampleData()" class="text-xs text-blue-400 hover:text-blue-300 font-semibold transition flex items-center gap-1 bg-blue-950/40 border border-blue-900/50 px-2 py-0.5 rounded">
                        🧪 Use Sample Context
                    </button>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-slate-500 font-medium">Auto-load CRM Lead:</span>
                        <select id="lab-lead-select" onchange="loadLeadIntoLab(this.value)" class="bg-slate-900 border border-slate-700 rounded px-2.5 py-1 text-xs text-slate-300 outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">-- Manual Input --</option>
                        </select>
                    </div>
                </div>
            </div>
            <textarea id="agent-context" rows="6" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 font-mono text-sm focus:ring-2 focus:ring-blue-500 outline-none text-slate-300" placeholder="Paste company data, website text, or any context for the agent to process..."></textarea>
        </div>

        <!-- Output -->
        <div id="output-area" class="bg-slate-800 rounded-xl p-6 shadow-lg border border-slate-700 hidden">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-emerald-400">Agent Output</h3>
                <span id="output-meta" class="text-xs text-slate-500 font-mono"></span>
            </div>
            <pre id="agent-result" class="bg-slate-950 p-4 rounded-lg overflow-x-auto text-sm text-green-400 font-mono border border-slate-800 min-h-[100px] whitespace-pre-wrap"></pre>
            
            <!-- Media Result Container -->
            <div id="media-container" class="mt-6 hidden">
                <h4 class="text-xs font-bold text-slate-500 uppercase mb-2">Attached Asset</h4>
                <div class="bg-slate-900 rounded-lg border border-slate-700 p-2 overflow-hidden flex justify-center">
                    <img id="media-img" src="" class="max-w-full rounded shadow-xl" alt="Agent Generated Asset">
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    const PERSONA_METADATA = {
        "Researcher": {
            type: "Research & Data",
            isWorkflow: true,
            badgeColor: "bg-teal-900/50 text-teal-300 border border-teal-800/60",
            description: "Deep-dives into web sources, extracts direct contact info, and finds unique personalization facts. Designed for background harvesting workflows.",
            systemPrompt: "You are a deep-dive Web Researcher. Find specific, verifiable information about the target company and cite sources if possible.",
            instructionPlaceholder: "Focus on finding personal B2B email addresses and bypass common generic aliases like info@.",
            contextPlaceholder: "Target Website URL: https://www.exampleproperties.com\nOr paste scraped text highlights."
        },
        "Qualifier": {
            type: "ICP Matching",
            isWorkflow: true,
            badgeColor: "bg-indigo-900/50 text-indigo-300 border border-indigo-800/60",
            description: "Processes leads in the background to automatically grade fit against your Ideal Customer Profile. Typically invoked as an automated workflow step rather than manually.",
            systemPrompt: "You are a B2B Lead Qualifier. Determine if this company matches a standard Ideal Customer Profile (ICP) based on size, industry, location, and tech stack.",
            instructionPlaceholder: "Qualify only if the company manages vacation rentals (STR) and is based in Texas. Score 0-100.",
            contextPlaceholder: "Company: Hill Country Escapes\nModel: Short-Term Rental Property Management\nLocation: Fredericksburg, TX\nSize: 12 employees"
        },
        "Intent Analyst": {
            type: "Behavioral Intel",
            isWorkflow: true,
            badgeColor: "bg-cyan-900/50 text-cyan-300 border border-cyan-800/60",
            description: "Performs semantic business audits, parsing career pages and news snippets for intent triggers (hiring, expansion). Runs as an automated backend job.",
            systemPrompt: "You are an Elite B2B Intent Analyst. Perform a SEMANTIC BUSINESS MODEL AUDIT: (1) Category (B2B vs B2C), (2) Intent Markers (hiring, expansion, partnership), (3) Geo Relevance, (4) Intent Score (0-100), (5) Rationale.",
            instructionPlaceholder: "Audit signals for high priority indicators such as recent job openings for cleaning staff or property coordinators.",
            contextPlaceholder: "Scraped Web Text: 'We are expanding our operations to the Gulf Coast and looking for local managers...'"
        },
        "Influencer Scout": {
            type: "Enrichment",
            isWorkflow: true,
            badgeColor: "bg-fuchsia-900/50 text-fuchsia-300 border border-fuchsia-800/60",
            description: "Infers detailed metadata, pain points, and decision-maker profiles from highly fragmented web footprints. Designed to enrich incoming lead pipelines.",
            systemPrompt: "You are a Data Enrichment Analyst. Infer detailed metadata (Industry, Tech Stack, Pain Points) from limited context.",
            instructionPlaceholder: "Infer the most likely short-term rental software (PMS) they are using based on their booking widget behavior.",
            contextPlaceholder: "Lead snippet: 'Book direct with us on our integrated owner dashboard. Powered by Hostaway API.'"
        },
        "Copywriter": {
            type: "Creative Outreach",
            isWorkflow: false,
            badgeColor: "bg-emerald-900/50 text-emerald-300 border border-emerald-800/60",
            description: "Drafts highly personalized, persuasive outreach emails. Intended for direct user interaction and manual refinement.",
            systemPrompt: "You are a Persuasive Outreach Copywriter. Write short, punchy, and highly personalized outreach emails.",
            instructionPlaceholder: "Write a short, casual 3-sentence intro email asking about their current booking software pain points. Avoid salesy hype.",
            contextPlaceholder: "Recipient: Sarah Jenkins (Operations Director)\nCompany: Texas Cozy Cabins\nPain Point: Double-bookings on VRBO and Airbnb"
        },
        "LinkedIn Specialist": {
            type: "Direct Outreach",
            isWorkflow: false,
            badgeColor: "bg-blue-900/50 text-blue-300 border border-blue-800/60",
            description: "Drafts hyper-focused, professional connection requests and follow-up InMail sequences. Optimized for manual copy-pasting to LinkedIn Sales Navigator.",
            systemPrompt: "You are a LinkedIn Outreach Expert. Draft connection requests (max 300 chars) and InMail sequences with a peer-to-peer tone.",
            instructionPlaceholder: "Create a 250-character connection note highlighting our mutual connection in the vacation rental space.",
            contextPlaceholder: "Profile Summary: Johnathan Smith, Founder of Austin Luxury Stays. Formerly Director of Hospitality at Expedia."
        },
        "Ad Copywriter": {
            type: "Direct Response",
            isWorkflow: false,
            badgeColor: "bg-rose-900/50 text-rose-300 border border-rose-800/60",
            description: "Generates high-converting, high-CTR headlines and body copy for search, display, and social ads. Ready to be exported into campaign builders.",
            systemPrompt: "You are a Direct Response Ad Copywriter. Write punchy, high-CTR ad copy headlines and description.",
            instructionPlaceholder: "Draft 3 Google Search ad headline variations addressing property managers tired of paying 15% booking fees.",
            contextPlaceholder: "Product: Smarter Outreach CRM\nKey Benefit: Save $500/month per listing on channel manager commission fees"
        },
        "LinkedIn Assistant": {
            type: "Direct Creative",
            isWorkflow: false,
            badgeColor: "bg-blue-950 text-blue-300 border border-blue-800/80 shadow-[0_0_15px_rgba(59,130,246,0.15)]",
            description: "Generates high-value corporate thought leadership posts, connection requests, and structured InMail outreach messages for LinkedIn.",
            systemPrompt: "You are a specialized LinkedIn Assistant. Focus on corporate thought leadership, networking, B2B insights, and structured formatting.",
            instructionPlaceholder: "Draft a thought-leadership LinkedIn post on B2B property management efficiency with clear spacing and a strong hook.",
            contextPlaceholder: "Topic: Saving 10+ hours a week by automating guest message flows and smart lock integrations."
        },
        "Instagram Assistant": {
            type: "Direct Creative",
            isWorkflow: false,
            badgeColor: "bg-pink-950 text-pink-300 border border-pink-800/80 shadow-[0_0_15px_rgba(236,72,153,0.15)]",
            description: "Generates visual-oriented Instagram captions, product storytelling, compelling brand hooks, and curated hashtag strategies.",
            systemPrompt: "You are a specialized Instagram Assistant. Focus on visual brand identity, storytelling, aesthetics, and clear CTAs.",
            instructionPlaceholder: "Draft an engaging Instagram post showcasing vacation cabin decor with lifestyle storytelling and hashtags.",
            contextPlaceholder: "Topic: Elegant, rustic design touches that guarantee 5-star Airbnb reviews."
        },
        "X (Twitter) Assistant": {
            type: "Direct Creative",
            isWorkflow: false,
            badgeColor: "bg-slate-950 text-slate-300 border border-slate-700 shadow-[0_0_15px_rgba(255,255,255,0.05)]",
            description: "Generates highly engaging tweets, viral hook variations, and multi-tweet educational threads under 280 characters per tweet.",
            systemPrompt: "You are a specialized X (Twitter) Assistant. Focus on punchy viral hooks, concise thread nodes, and opinionated growth takes.",
            instructionPlaceholder: "Write a 5-tweet educational thread on why property managers are losing 15% booking fees to OTA channels.",
            contextPlaceholder: "Topic: Moving from Airbnb dependency to building a direct book channel."
        },
        "Facebook Assistant": {
            type: "Direct Creative",
            isWorkflow: false,
            badgeColor: "bg-indigo-950 text-indigo-300 border border-indigo-800/80 shadow-[0_0_15px_rgba(99,102,241,0.15)]",
            description: "Generates warm, community-centric Facebook posts, discussion prompts, local B2B/B2C outreach, and sharing strategies.",
            systemPrompt: "You are a specialized Facebook Assistant. Focus on conversational warmth, interactive questions, and localized group appeal.",
            instructionPlaceholder: "Write a conversational Facebook post asking vacation rental owners about their biggest cleaning horror stories.",
            contextPlaceholder: "Topic: The importance of reliable turnover clean teams in Austin vacation rentals."
        },
        "TikTok Assistant": {
            type: "Direct Creative",
            isWorkflow: false,
            badgeColor: "bg-zinc-950 text-red-300 border border-teal-500/50 shadow-[0_0_15px_rgba(20,184,166,0.15)]",
            description: "Generates high-energy TikTok video scripts including 3-second hook directions, voiceover dialogue, and trend audio suggestions.",
            systemPrompt: "You are a specialized TikTok Assistant. Focus on high-energy video hook directions, visual scene script briefs, and viral caption copy.",
            instructionPlaceholder: "Draft a 15-second TikTok script (scene action + voiceover text) showcasing a luxury Airbnb check-in experience.",
            contextPlaceholder: "Topic: How automated smart locks make guest check-in feel premium."
        },
        "Brainstormer": {
            type: "Divergent Idea",
            isWorkflow: false,
            badgeColor: "bg-purple-900/50 text-purple-300 border border-purple-800/60",
            description: "Generates unique, divergent outreach angles, hooks, and campaign structures. Designed to stimulate human creativity and break writer's block.",
            systemPrompt: "You are a Creative Brainstorming Partner. Generate unique, divergent campaign angles, outreach concepts, and hooks.",
            instructionPlaceholder: "Provide 5 distinct, out-of-the-box angles to pitch property managers who pride themselves on 'local family-owned service.'",
            contextPlaceholder: "Audience: Independent local property managers in rural vacation spots."
        },
        "WordPress Expert": {
            type: "Publishing Integration",
            isWorkflow: true,
            badgeColor: "bg-teal-900/50 text-teal-300 border border-teal-800/60",
            description: "Automatically formats, stages, and publishes qualified lead intelligence directly to your connected WordPress website. Can run as a workflow step.",
            systemPrompt: "You are a WordPress Architect. Formats and publishes qualified lead intelligence directly to connected WordPress sites.",
            instructionPlaceholder: "Stage as draft under the category 'Outreach Intel'.",
            contextPlaceholder: "Staged post content to publish..."
        },
        "Video Director": {
            type: "Media Generation",
            isWorkflow: false,
            badgeColor: "bg-fuchsia-900/50 text-fuchsia-300 border border-fuchsia-800/60",
            description: "Engineers rich, cinematic prompts for AI video generators (Runway, Sora) and scripts scene-by-scene content for campaigns.",
            systemPrompt: "You are a Video Production Director. Create cinematic scene-by-scene script breakdowns and prompt-engineered visual descriptions for AI generators.",
            instructionPlaceholder: "Generate a 15-second cinematic promo scene prompt about automated CRM efficiency.",
            contextPlaceholder: "CRM automation benefits and features list..."
        },
        "Extraction Expert": {
            type: "Data Extraction",
            isWorkflow: true,
            badgeColor: "bg-emerald-900/50 text-emerald-300 border border-emerald-800/60",
            description: "Crawls websites to discover emails, phone numbers, and social URLs. Prioritizes direct emails over generic info@ aliases. Active during search runs.",
            systemPrompt: "You are a High-Fidelity Lead Extraction Specialist. Discover and clean emails, phone numbers, and social URLs, prioritizing direct personal contacts.",
            instructionPlaceholder: "Bypass generic contact addresses and only capture executive/founder emails.",
            contextPlaceholder: "https://www.example.com\nOr raw text content of a directory page..."
        }
    };

    function updateAgentDetails() {
        const persona = document.getElementById('agent-persona').value;
        const meta = PERSONA_METADATA[persona];
        const infoCard = document.getElementById('agent-info-card');
        const typeBadge = document.getElementById('agent-type-badge');
        const workflowBadge = document.getElementById('agent-workflow-badge');
        const description = document.getElementById('agent-description');
        const instructionTextarea = document.getElementById('agent-instruction');
        const contextTextarea = document.getElementById('agent-context');
        
        const workflowAlert = document.getElementById('agent-workflow-alert');
        const interactiveAlert = document.getElementById('agent-interactive-alert');
        const systemPromptContainer = document.getElementById('agent-system-prompt-container');
        const systemPromptBox = document.getElementById('agent-system-prompt');

        if (meta) {
            description.textContent = meta.description;
            typeBadge.textContent = meta.type;
            typeBadge.className = `px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase ${meta.badgeColor}`;
            
            if (meta.isWorkflow) {
                workflowBadge.classList.remove('hidden');
                workflowAlert.classList.remove('hidden');
                interactiveAlert.classList.add('hidden');
            } else {
                workflowBadge.classList.add('hidden');
                workflowAlert.classList.add('hidden');
                interactiveAlert.classList.remove('hidden');
            }

            if (meta.systemPrompt) {
                systemPromptBox.textContent = meta.systemPrompt;
                systemPromptContainer.classList.remove('hidden');
            } else {
                systemPromptContainer.classList.add('hidden');
            }

            instructionTextarea.placeholder = `e.g. ${meta.instructionPlaceholder}`;
            
            // Only update context placeholder if user hasn't typed in it or if it is currently empty/matches a placeholder
            if (!contextTextarea.value.trim()) {
                contextTextarea.placeholder = meta.contextPlaceholder;
            }
            
            infoCard.classList.remove('hidden');
        } else {
            infoCard.classList.add('hidden');
            workflowAlert.classList.add('hidden');
            interactiveAlert.classList.add('hidden');
            systemPromptContainer.classList.add('hidden');
            instructionTextarea.placeholder = "e.g. Be extremely sarcastic. Output CSV format only.";
            contextTextarea.placeholder = "Paste company data, website text, or any context for the agent to process...";
        }
    }

    function insertSampleData() {
        const persona = document.getElementById('agent-persona').value;
        const meta = PERSONA_METADATA[persona];
        if (meta) {
            document.getElementById('agent-context').value = meta.contextPlaceholder;
        }
    }

    function insertSampleInstruction() {
        const persona = document.getElementById('agent-persona').value;
        const meta = PERSONA_METADATA[persona];
        if (meta) {
            document.getElementById('agent-instruction').value = meta.instructionPlaceholder;
        }
    }

    // Run on DOM ready or immediately if already loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', updateAgentDetails);
    } else {
        updateAgentDetails();
    }

    checkMode();
    fetchLabLeads();

    async function checkMode() {
        try {
            const res = await fetch('api/settings.php');
            const data = await res.json();
            if (data.success) {
                const mode = data.data.operational_mode || 'Production';
                const el = document.getElementById('lab-mode-indicator');
                if (mode === 'Simulation') {
                    el.innerText = '🛡️ SIMULATION (Replay Only)';
                    el.className = 'text-sm font-mono text-amber-400';
                } else {
                    el.innerText = '⚡ PRODUCTION (Live API)';
                    el.className = 'text-sm font-mono text-rose-400';
                }
            }
        } catch(e) {}
    }

    async function fetchLabLeads() {
        try {
            const res = await fetch('api/leads.php?limit=100');
            const data = await res.json();
            if (data.success) {
                window.labLeads = data.data;
                const select = document.getElementById('lab-lead-select');
                select.innerHTML = '<option value="">-- Manual Input --</option>';
                data.data.forEach(lead => {
                    const opt = document.createElement('option');
                    opt.value = lead.id;
                    opt.textContent = `${lead.company_name} (${lead.contact_name || 'No Contact'})`;
                    select.appendChild(opt);
                });
            }
        } catch(e) {
            console.warn('Failed to load leads for copywriting lab');
        }
    }

    function loadLeadIntoLab(leadId) {
        if (!leadId) {
            document.getElementById('agent-context').value = '';
            return;
        }
        if (!window.labLeads) return;
        const lead = window.labLeads.find(l => l.id == leadId);
        if (lead) {
            const leadText = `Company Name: ${lead.company_name}
Key Contact: ${lead.contact_name || 'No Key Contact'}
Email Address: ${lead.email || 'N/A'}
Website URL: ${lead.website || 'N/A'}
Lead Score: ${lead.lead_score || '0'}
Current Status: ${lead.status}
Outreach Campaign: ${lead.campaign_name || 'None Assigned'}`;
            document.getElementById('agent-context').value = leadText;
        }
    }

    async function runAgent() {
        const persona = document.getElementById('agent-persona').value;
        const context = document.getElementById('agent-context').value;
        const instruction = document.getElementById('agent-instruction').value;
        const btn = document.getElementById('run-btn');
        const resultArea = document.getElementById('agent-result');
        const outputDiv = document.getElementById('output-area');
        const metaSpan = document.getElementById('output-meta');

        if (!context.trim()) {
            alert('Please provide some context!');
            return;
        }

        // UI State: Loading
        btn.disabled = true;
        btn.innerHTML = `<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Thinking...`;
        
        outputDiv.classList.add('hidden');

        try {
            const response = await fetch('api/agent_chat.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ persona, context, instruction })
            });

            const data = await response.json();

            if (data.success) {
                const output = data.data;
                if (output && typeof output.response === 'string') {
                    resultArea.textContent = output.response;
                } else {
                    resultArea.textContent = JSON.stringify(output, null, 2);
                }

                // Handle Media
                const mediaContainer = document.getElementById('media-container');
                const mediaImg = document.getElementById('media-img');
                if (output && output.media_url) {
                    mediaImg.src = output.media_url;
                    mediaContainer.classList.remove('hidden');
                } else {
                    mediaContainer.classList.add('hidden');
                }

                const providerBadge = (output && output.provider) ? ` [${output.provider}]` : '';
                metaSpan.textContent = `Generated by ${data.meta.persona}${providerBadge} at ${new Date(data.meta.timestamp).toLocaleTimeString()}`;
                outputDiv.classList.remove('hidden');
            } else {
                alert('Agent Error: ' + (data.error || 'Unknown error'));
            }

        } catch (e) {
            alert('Network Error: ' + e.message);
        } finally {
            btn.disabled = false;
            btn.innerText = "Run Agent";
        }
    }
</script>
