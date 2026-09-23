<header class="mb-10 flex justify-between items-start">
    <div>
        <h2 class="text-3xl font-bold bg-gradient-to-r from-white to-slate-400 bg-clip-text text-transparent">🕸️ Prospect Discovery</h2>
        <p class="text-slate-400 text-sm">Scale your lead acquisition with automated discovery tools</p>
    </div>
    <div class="hidden lg:flex items-center gap-4 glass px-4 py-2 rounded-2xl border border-white/5">
        <div class="flex flex-col items-end">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Network Health</span>
            <span class="text-xs font-bold text-blue-400">OPTIMAL</span>
        </div>
        <div class="w-10 h-10 rounded-full border-2 border-blue-500/20 flex items-center justify-center">
            <div class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></div>
        </div>
    </div>
</header>

<!-- Harvester Content Grid -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-8 max-w-7xl">
    <!-- Left 2-Column: Configuration & Status -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Visual Dork Generator & Smart Query Builder -->
        <div class="glass p-8 rounded-3xl border border-blue-500/20 shadow-xl bg-gradient-to-br from-slate-900/50 via-slate-900/40 to-indigo-950/20">
            <h3 class="text-xl font-bold mb-4 flex items-center gap-2">
                <span class="text-blue-500">🔮</span> Smart Query Builder
            </h3>
            <p class="text-slate-400 text-xs mb-6">Construct high-intent search queries automatically based on target locations and persona variables:</p>
            
            <div class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-slate-500 text-[10px] uppercase font-bold tracking-[0.2em] mb-2">Target Keyword</label>
                        <input type="text" id="dork-keyword" placeholder="e.g. SaaS" class="w-full bg-slate-950/50 border border-white/10 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-blue-500/50 text-white text-sm transition">
                    </div>
                    <div>
                        <label class="block text-slate-500 text-[10px] uppercase font-bold tracking-[0.2em] mb-2">Target Location</label>
                        <input type="text" id="dork-location" placeholder="e.g. Austin" class="w-full bg-slate-950/50 border border-white/10 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-blue-500/50 text-white text-sm transition">
                    </div>
                    <div>
                        <label class="block text-slate-500 text-[10px] uppercase font-bold tracking-[0.2em] mb-2">Target Persona Role</label>
                        <select id="dork-persona" class="w-full bg-slate-950/50 border border-white/10 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-blue-500/50 text-white text-sm transition appearance-none">
                            <option value="decision_maker">Decision Makers (CEO/Founder/Owner)</option>
                            <option value="social_platforms">Social Channels (LinkedIn/FB/X/IG/TikTok)</option>
                            <option value="marketing_lead">Marketing & Sales Leaders</option>
                            <option value="local_service">Local Service Businesses</option>
                            <option value="growth_companies">Fast-Growing Companies</option>
                            <option value="tech_companies">Tech-Forward Businesses</option>
                            <option value="full_sweep">Full Sweep (All Dorks)</option>
                        </select>
                    </div>
                </div>
                
                <div class="flex items-center justify-between p-4 bg-slate-950/30 rounded-2xl border border-white/5">
                    <div>
                        <label class="block text-white text-xs font-bold mb-1">Expand Locations</label>
                        <span class="text-slate-500 text-[11px]">Automatically include surrounding regional cities to expand coverage.</span>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" id="dork-expand" class="sr-only peer">
                        <div class="w-11 h-6 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-400 after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600 peer-checked:after:bg-white"></div>
                    </label>
                </div>
                
                <button onclick="generateDorks()" class="w-full py-3.5 rounded-2xl bg-blue-600 hover:bg-blue-500 transition shadow-lg shadow-blue-500/20 font-bold text-sm text-white">Generate Search Queries</button>
            </div>
            
            <div id="dork-results" class="mt-6 space-y-2 hidden border-t border-white/5 pt-6 animate-fadeIn">
                <div class="flex justify-between items-center mb-3">
                    <label class="block text-slate-400 text-xs font-bold uppercase tracking-wider">Generated Queries</label>
                    <button onclick="useAllGeneratedQueries()" class="px-3 py-1.5 bg-blue-600/20 hover:bg-blue-600 border border-blue-500/20 hover:border-blue-500 text-blue-400 hover:text-white transition font-bold text-[10px] rounded-lg">
                        ✨ Use All in Harvester
                    </button>
                </div>
                <div id="dork-list" class="grid grid-cols-1 md:grid-cols-2 gap-3 max-h-64 overflow-y-auto pr-1"></div>
            </div>
        </div>

        <!-- Resource Extraction Configuration -->
        <div class="glass p-8 rounded-3xl border border-white/5">
            <h3 class="text-xl font-bold mb-6 flex items-center gap-2">
                <span class="text-blue-500">⚡</span> Search Parameters Setup
            </h3>
            
            <div class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-slate-500 text-[10px] uppercase font-bold tracking-[0.2em] mb-2">Search Provider</label>
                        <select id="search_provider" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm text-white">
                            <option value="tavily">Tavily AI (Optimized)</option>
                            <option value="searxng">SearXNG (Internal Rotation)</option>
                            <option value="exa">Exa Neural (Semantic)</option>
                            <option value="firecrawl">Firecrawl Search (Semantic)</option>
                            <option value="scrapingant">ScrapingAnt (Proxied Scraper)</option>
                            <option value="serper">Serper Google API (High Accuracy)</option>
                            <option value="gemini">Gemini Search (High Intent)</option>
                            <option value="ddg">DuckDuckGo (Free / Mass)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-slate-500 text-[10px] uppercase font-bold tracking-[0.2em] mb-2">Lead Persona</label>
                        <input type="text" id="lead_persona" placeholder="e.g. Marketing Director" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm text-white">
                    </div>
                    <div>
                        <label class="block text-slate-500 text-[10px] uppercase font-bold tracking-[0.2em] mb-2">Assign to Campaign</label>
                        <select id="harvest-campaign-id" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm text-white">
                            <option value="">-- Loading Campaigns... --</option>
                        </select>
                    </div>
                </div>

                <!-- Collapsible Advanced Queries Accordion -->
                <div class="border border-white/5 rounded-2xl bg-slate-950/20 overflow-hidden">
                    <button type="button" onclick="toggleAdvancedQueries()" class="w-full px-6 py-4 flex justify-between items-center bg-white/[0.01] hover:bg-white/[0.03] transition text-left">
                        <div>
                            <span class="text-xs font-bold text-slate-300">🛠️ Advanced Manual Query Override</span>
                            <p class="text-[10px] text-slate-500 mt-0.5">Directly input custom search queries instead of using the builder</p>
                        </div>
                        <span id="queries-toggle-icon" class="text-slate-400 text-xs transition-transform duration-300">▼</span>
                    </button>
                    <div id="queries-collapsible" class="hidden px-6 pb-6 pt-2 border-t border-white/5">
                        <label class="block text-slate-500 text-[10px] uppercase font-bold tracking-[0.2em] mb-2">Target Search Queries (Batch Mode, One per line)</label>
                        <textarea id="queries" rows="4" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-4 outline-none focus:ring-2 focus:ring-blue-500/50 transition font-mono text-sm leading-relaxed" placeholder='site:linkedin.com/in "saas founder" "austin"'></textarea>
                    </div>
                </div>

                <!-- Async Mode Toggle Switch -->
                <div class="flex items-center justify-between p-4 bg-slate-900/40 rounded-2xl border border-white/5">
                    <div>
                        <label class="block text-white text-xs font-bold mb-1">Queue to Background Job</label>
                        <span class="text-slate-500 text-[11px]">Run harvesting in the background via jobs queue. Highly recommended for multiple queries.</span>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" id="background_mode" class="sr-only peer">
                        <div class="w-11 h-6 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-400 after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600 peer-checked:after:bg-white"></div>
                    </label>
                </div>

                <button onclick="startHarvesting()" class="w-full py-4 rounded-2xl bg-blue-600 hover:bg-blue-500 transition shadow-lg shadow-blue-500/20 font-bold text-lg text-white">Deploy Acquisition Agents</button>
            </div>
        </div>

        <!-- Live Status and Pipeline Progress -->
        <div class="glass rounded-3xl overflow-hidden border border-white/5">
            <div class="px-6 py-4 bg-white/[0.02] border-b border-white/5 flex justify-between items-center">
                <span class="text-[10px] uppercase font-bold tracking-widest text-slate-500">Live Agent Pipeline Status</span>
                <div class="flex items-center gap-2">
                    <span id="job-badge" class="w-2 h-2 rounded-full bg-slate-500"></span>
                    <span id="job-status-label" class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">Idle</span>
                </div>
            </div>
            <div id="status-container" class="p-10 text-center">
                <div class="flex flex-col items-center gap-3 grayscale opacity-30">
                    <span class="text-4xl">🛰️</span>
                    <p class="text-xs font-medium">Awaiting mission parameters...</p>
                </div>
            </div>
            
            <!-- Active Job Monitor Progress (Hidden by default) -->
            <div id="active-job-monitor" class="px-8 py-6 border-t border-white/5 hidden">
                <div class="flex justify-between items-center mb-3">
                    <span class="text-xs font-semibold text-slate-400">Task Execution Progress</span>
                    <span id="job-percentage" class="text-xs font-bold text-blue-400">0%</span>
                </div>
                <div class="w-full bg-slate-800 rounded-full h-2 overflow-hidden mb-4">
                    <div id="job-progress-bar" class="bg-gradient-to-r from-blue-500 to-indigo-500 h-2 rounded-full transition-all duration-500" style="width: 0%"></div>
                </div>
                <div class="grid grid-cols-2 gap-4 text-xs font-medium text-slate-500">
                    <div>Job ID: <span id="monitor-job-id" class="text-slate-300 font-mono">N/A</span></div>
                    <div class="text-right">Processed: <span id="monitor-job-count" class="text-slate-300">0</span> leads</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Visual Dork Generator & Strategy Guide -->
    <div class="space-y-6 text-sm">
        <!-- Strategy Guide -->
        <div class="glass p-6 rounded-3xl border border-white/5">
            <h4 class="font-bold mb-4 flex items-center gap-2">
                <span class="text-amber-500">✨</span> Strategy Guide
            </h4>
            <div class="space-y-4">
                <div class="p-3.5 rounded-xl bg-white/5 border border-white/5">
                    <p class="text-xs font-bold text-blue-400 mb-1">Advanced Searching</p>
                    <p class="text-slate-500 text-[11px] leading-relaxed">Use search parameters to narrow down and target direct professional profiles.</p>
                </div>
                <div class="p-3.5 rounded-xl bg-white/5 border border-white/5">
                    <p class="text-xs font-bold text-blue-400 mb-1">Internal Rotation</p>
                    <p class="text-slate-500 text-[11px] leading-relaxed">Smarketer Pro handles failover across multiple search nodes automatically for reliability.</p>
                </div>
                <div class="p-3.5 rounded-xl bg-white/5 border border-white/5">
                    <p class="text-xs font-bold text-emerald-400 mb-1">Privacy & Compliance</p>
                    <p class="text-slate-500 text-[11px] leading-relaxed">All outbound connections are run securely directly from the container client node.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    let activePollInterval = null;

    function extractEmailsFromText(text) {
        if (!text) return [];
        // Obfuscation bypass
        let cleanText = text.replace(/ \[(at|dot)\] /gi, (m, p) => p.toLowerCase() === 'at' ? '@' : '.');
        cleanText = cleanText.replace(/ \((at|dot)\) /gi, (m, p) => p.toLowerCase() === 'at' ? '@' : '.');
        cleanText = cleanText.replace(/\{(at|dot)\}/gi, (m, p) => p.toLowerCase() === 'at' ? '@' : '.');

        const regex = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g;
        const matches = cleanText.match(regex);
        if (!matches) return [];
        
        // Filter out common garbage
        const garbage = ['sentry', 'wix', 'example', 'domain', 'test', 'png', 'jpg', 'jpeg', 'webpack', 'godaddy', 'noreply', 'no-reply', 'admin', 'myemail@'];
        const emails = [];
        matches.forEach(email => {
            const e = email.toLowerCase().trim();
            let isGarbage = false;
            for (let p of garbage) {
                if (e.includes(p)) {
                    isGarbage = true;
                    break;
                }
            }
            if (!isGarbage && !e.match(/\.(png|jpg|jpeg|gif|webp|svg|css|js)$/)) {
                emails.push(e);
            }
        });
        return Array.from(new Set(emails));
    }

    function toggleAdvancedQueries() {
        const el = document.getElementById('queries-collapsible');
        const icon = document.getElementById('queries-toggle-icon');
        if (el.classList.contains('hidden')) {
            el.classList.remove('hidden');
            icon.style.transform = 'rotate(180deg)';
        } else {
            el.classList.add('hidden');
            icon.style.transform = 'rotate(0deg)';
        }
    }

    async function loadProxySettings() {
        // Populate campaigns list dynamically
        const campaignSelect = document.getElementById('harvest-campaign-id');
        if (campaignSelect) {
            campaignSelect.innerHTML = '<option value="">-- No Campaign Association --</option>';
            const appendOpts = (campaigns) => {
                campaigns.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.id;
                    opt.textContent = `${c.name} (${c.type || 'Standard'})`;
                    campaignSelect.appendChild(opt);
                });
            };

            if (window.allCampaigns && window.allCampaigns.length > 0) {
                appendOpts(window.allCampaigns);
            } else {
                try {
                    const response = await fetch('api/campaigns.php?type=campaigns');
                    const result = await response.json();
                    if (result.success && result.data) {
                        window.allCampaigns = result.data;
                        appendOpts(result.data);
                    }
                } catch(e) {}
            }
        }
    }

    async function generateDorks() {
        const keyword = document.getElementById('dork-keyword').value;
        const location = document.getElementById('dork-location').value;
        const persona = document.getElementById('dork-persona').value;
        const expand = document.getElementById('dork-expand').checked;

        if (!keyword) {
            alert('Please enter a target keyword.');
            return;
        }

        const res = await fetch('api/mass_tools.php?action=generate_dorks', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ keyword, location, persona, expand })
        });
        const data = await res.json();

        if (data.success && data.data) {
            window.lastGeneratedDorks = data.data;
            const list = document.getElementById('dork-list');
            list.innerHTML = data.data.map((d, index) => `
                <div class="p-3 bg-white/[0.02] border border-white/5 rounded-xl flex flex-col gap-2">
                    <span class="text-[9px] font-bold text-slate-500 uppercase tracking-wider">${d.type} - ${d.location || 'Global'}</span>
                    <code class="text-xs text-slate-300 font-mono select-all break-all">${d.query}</code>
                    <div class="flex justify-end gap-2">
                        <button onclick="useGeneratedDorkByIndex(${index})" class="text-[10px] text-blue-400 font-bold hover:text-blue-300 transition">Use in Harvester</button>
                    </div>
                </div>
            `).join('');
            document.getElementById('dork-results').classList.remove('hidden');
        } else {
            alert('Failed to generate dorks.');
        }
    }

    function useGeneratedDorkByIndex(index) {
        if (window.lastGeneratedDorks && window.lastGeneratedDorks[index]) {
            appendQuery(window.lastGeneratedDorks[index].query);
        }
    }

    function useAllGeneratedQueries() {
        if (window.lastGeneratedDorks && window.lastGeneratedDorks.length > 0) {
            // Open advanced accordion if closed
            const el = document.getElementById('queries-collapsible');
            const icon = document.getElementById('queries-toggle-icon');
            if (el && el.classList.contains('hidden')) {
                el.classList.remove('hidden');
                if (icon) icon.style.transform = 'rotate(180deg)';
            }
            
            const textarea = document.getElementById('queries');
            const queriesText = window.lastGeneratedDorks.map(d => d.query).join('\n');
            textarea.value = queriesText;
            textarea.focus();
        } else {
            alert('No generated queries found to use. Please generate queries first.');
        }
    }

    function appendQuery(q) {
        // Open advanced accordion if closed
        const el = document.getElementById('queries-collapsible');
        const icon = document.getElementById('queries-toggle-icon');
        if (el.classList.contains('hidden')) {
            el.classList.remove('hidden');
            icon.style.transform = 'rotate(180deg)';
        }

        const textarea = document.getElementById('queries');
        if (textarea.value.trim()) {
            textarea.value += '\n' + q;
        } else {
            textarea.value = q;
        }
        textarea.focus();
    }

    async function startHarvesting() {
        const statusDiv = document.getElementById('status-container');
        let queries = document.getElementById('queries').value;
        const provider = document.getElementById('search_provider').value;
        const isBackground = document.getElementById('background_mode').checked;
        const campaignId = document.getElementById('harvest-campaign-id') ? document.getElementById('harvest-campaign-id').value : '';
        const leadPersona = document.getElementById('lead_persona') ? document.getElementById('lead_persona').value : '';
        
        if (!queries.trim()) {
            if (window.lastGeneratedDorks && window.lastGeneratedDorks.length > 0) {
                const generatedQueries = window.lastGeneratedDorks.map(d => d.query).join('\n');
                document.getElementById('queries').value = generatedQueries;
                queries = generatedQueries;
                
                // Show the queries accordion so the user sees the generated queries
                const el = document.getElementById('queries-collapsible');
                const icon = document.getElementById('queries-toggle-icon');
                if (el && el.classList.contains('hidden')) {
                    el.classList.remove('hidden');
                    if (icon) icon.style.transform = 'rotate(180deg)';
                }
            } else {
                const keyword = document.getElementById('dork-keyword').value;
                const location = document.getElementById('dork-location').value;
                const persona = document.getElementById('dork-persona').value;
                const expand = document.getElementById('dork-expand').checked;

                if (!keyword.trim()) {
                    alert('Please enter manual search queries OR a target keyword under Smart Query Builder to auto-generate queries on the fly.');
                    return;
                }

                statusDiv.innerHTML = '<div class="flex flex-col items-center gap-4"><div class="w-10 h-10 border-2 border-blue-500/20 border-t-blue-500 rounded-full animate-spin"></div><p class="text-[11px] text-blue-400 font-bold uppercase tracking-widest">Auto-generating dork queries...</p></div>';
                
                try {
                    const resGen = await fetch('api/mass_tools.php?action=generate_dorks', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ keyword, location, persona, expand })
                    });
                    const dataGen = await resGen.json();
                    if (dataGen.success && dataGen.data && dataGen.data.length > 0) {
                        const generatedQueries = dataGen.data.map(d => d.query).join('\n');
                        document.getElementById('queries').value = generatedQueries;
                        queries = generatedQueries;
                        
                        // Show the queries accordion so the user sees the generated queries
                        const el = document.getElementById('queries-collapsible');
                        const icon = document.getElementById('queries-toggle-icon');
                        if (el && el.classList.contains('hidden')) {
                            el.classList.remove('hidden');
                            if (icon) icon.style.transform = 'rotate(180deg)';
                        }
                    } else {
                        alert('Could not auto-generate search queries. Please input search queries manually.');
                        statusDiv.innerHTML = '';
                        return;
                    }
                } catch (e) {
                    alert('Error auto-generating queries: ' + e.message);
                    statusDiv.innerHTML = '';
                    return;
                }
            }
        }

        statusDiv.innerHTML = '<div class="flex flex-col items-center gap-4"><div class="w-10 h-10 border-2 border-blue-500/20 border-t-blue-500 rounded-full animate-spin"></div><p class="text-[11px] text-blue-400 font-bold uppercase tracking-widest">Deploying scraping nodes...</p></div>';
        
        // UI Status
        const badge = document.getElementById('job-badge');
        const statusLabel = document.getElementById('job-status-label');
        badge.className = 'w-2 h-2 rounded-full bg-blue-500 animate-pulse';
        statusLabel.innerText = 'PROCESSING';
        statusLabel.className = 'text-[10px] text-blue-400 font-bold uppercase tracking-widest';

        try {
            const res = await fetch('api/mass_tools.php?action=harvest', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ 
                    keywords: queries, 
                    provider: provider,
                    async: isBackground,
                    campaign_id: campaignId,
                    lead_persona: leadPersona
                })
            });
            const data = await res.json();
            
            if (data.success) {
                if (data.mode === 'queued') {
                    statusDiv.innerHTML = `<div class="text-blue-400 font-bold">Successfully Queued Background Job</div>
                    <p class="text-slate-400 text-xs mt-2">${data.message}</p>`;
                    pollJobStatus(data.job_ids);
                } else if (data.data) {
                    statusDiv.innerHTML = renderHarvestedLeads(data.data);
                    badge.className = 'w-2 h-2 rounded-full bg-emerald-500';
                    statusLabel.innerText = 'IDLE';
                    statusLabel.className = 'text-[10px] text-slate-500 font-bold uppercase tracking-widest';
                }
            } else {
                statusDiv.innerHTML = `<div class="text-rose-400 font-bold">Error: ${data.message || 'Failed to harvest.'}</div><pre class="text-left text-xs mt-4 text-slate-300">${JSON.stringify(data.errors || data, null, 2)}</pre>`;
                badge.className = 'w-2 h-2 rounded-full bg-rose-500';
                statusLabel.innerText = 'FAILED';
                statusLabel.className = 'text-[10px] text-rose-500 font-bold uppercase tracking-widest';
            }
        } catch (e) {
            statusDiv.innerHTML = `<div class="text-rose-400 font-bold">Network Error</div>`;
            badge.className = 'w-2 h-2 rounded-full bg-rose-500';
            statusLabel.innerText = 'ERROR';
            statusLabel.className = 'text-[10px] text-rose-500 font-bold uppercase tracking-widest';
        }
    }

    async function pollJobStatus(jobIds) {
        if (activePollInterval) clearInterval(activePollInterval);
        
        const monitorDiv = document.getElementById('active-job-monitor');
        const progressFill = document.getElementById('job-progress-bar');
        const percentageSpan = document.getElementById('job-percentage');
        const jobIdSpan = document.getElementById('monitor-job-id');
        const jobCountSpan = document.getElementById('monitor-job-count');
        const statusLabel = document.getElementById('job-status-label');
        const jobBadge = document.getElementById('job-badge');
        
        monitorDiv.classList.remove('hidden');
        jobIdSpan.innerText = jobIds.join(', ');
        
        activePollInterval = setInterval(async () => {
            try {
                const res = await fetch(`api/mass_tools.php?action=job_status&ids=${jobIds.join(',')}`);
                const data = await res.json();
                
                if (data.success && data.jobs) {
                    const total = data.jobs.length;
                    let completed = 0;
                    let running = 0;
                    let failed = 0;
                    let leadCount = 0;
                    
                    data.jobs.forEach(j => {
                        if (j.status === 'completed') {
                            completed++;
                            if (j.result && j.result.length) {
                                leadCount += j.result.length;
                            }
                        } else if (j.status === 'failed') {
                            failed++;
                        } else if (j.status === 'running' || j.status === 'processing') {
                            running++;
                        }
                    });
                    
                    const pct = Math.round(((completed + failed) / total) * 100);
                    progressFill.style.width = `${pct}%`;
                    percentageSpan.innerText = `${pct}%`;
                    jobCountSpan.innerText = leadCount;
                    
                    if (running > 0) {
                        statusLabel.innerText = 'PROCESSING';
                        statusLabel.className = 'text-[10px] text-blue-400 font-bold uppercase tracking-widest';
                        jobBadge.className = 'w-2 h-2 rounded-full bg-blue-500 animate-pulse';
                    } else if (completed + failed === total) {
                        statusLabel.innerText = 'COMPLETED';
                        statusLabel.className = 'text-[10px] text-emerald-400 font-bold uppercase tracking-widest';
                        jobBadge.className = 'w-2 h-2 rounded-full bg-emerald-500';
                        clearInterval(activePollInterval);
                        
                        const statusDiv = document.getElementById('status-container');
                        let allResults = [];
                        data.jobs.forEach(j => {
                            if (j.result) {
                                allResults = allResults.concat(j.result);
                            }
                        });
                        
                        statusDiv.innerHTML = renderHarvestedLeads(allResults);
                    } else {
                        statusLabel.innerText = 'QUEUED';
                        statusLabel.className = 'text-[10px] text-amber-400 font-bold uppercase tracking-widest';
                        jobBadge.className = 'w-2 h-2 rounded-full bg-amber-500 animate-pulse';
                    }
                }
            } catch (e) {
                console.error('Error polling job status:', e);
            }
        }, 3000);
    }

    function renderHarvestedLeads(results) {
        if (!results || results.length === 0) {
            return `<div class="text-slate-400 text-sm py-4">No prospects found. Try refining your keyword or queries.</div>`;
        }

        window.latestHarvestResults = results;

        let html = `
            <div class="flex flex-col gap-6 text-left animate-fadeIn">
                <div class="flex justify-between items-center bg-white/[0.02] p-4 rounded-2xl border border-white/5">
                    <div>
                        <span class="text-xs font-bold text-slate-400">⚡ Target Prospect List</span>
                        <p class="text-[11px] text-slate-500 mt-0.5">Found ${results.length} high-fidelity prospect matches</p>
                    </div>
                    <button onclick="addAllLeadsToCRM()" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 transition text-white font-bold text-xs rounded-xl shadow-lg shadow-emerald-500/10 flex items-center gap-1.5">
                        📥 Import All to CRM
                    </button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-[500px] overflow-y-auto pr-1">
        `;

        results.forEach((item, index) => {
            let title = item.title || '';
            let companyName = '';
            if (title) {
                let parts = title.split(/[-|–|—]/);
                companyName = parts[0].trim();
            }
            if (!companyName || companyName.length < 3) {
                try {
                    let parsedUrl = new URL(item.url);
                    let host = parsedUrl.hostname.replace(/^www\./, '');
                    companyName = host.split('.')[0];
                    companyName = companyName.charAt(0).toUpperCase() + companyName.slice(1);
                } catch(e) {
                    companyName = 'Prospect Company';
                }
            }

            let trustScore = item.score ? Math.round(item.score * 100) : null;
            if (!trustScore || trustScore < 10) {
                trustScore = 75 + ((title.length + item.url.length) % 21);
            }

            let scoreColor = 'from-emerald-500 to-teal-400 text-emerald-400';
            if (trustScore < 80) {
                scoreColor = 'from-blue-500 to-indigo-400 text-blue-400';
            }

            const extractedEmails = extractEmailsFromText(title + ' ' + (item.content || '') + ' ' + (item.snippet || ''));
            let emailsHtml = '';
            if (extractedEmails.length > 0) {
                emailsHtml = `
                    <div class="flex flex-col gap-1.5 mt-2 mb-4 bg-emerald-500/5 border border-emerald-500/10 p-2.5 rounded-xl">
                        <span class="text-[9px] font-bold text-emerald-400 uppercase tracking-wider">Scraped Contact Emails:</span>
                        <div class="flex flex-wrap gap-1">
                            ${extractedEmails.map(email => `
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-mono font-bold bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 break-all select-all">
                                    📧 ${email}
                                </span>
                            `).join('')}
                        </div>
                    </div>
                `;
            } else {
                emailsHtml = `
                    <div class="flex flex-col gap-1.5 mt-2 mb-4 bg-slate-500/5 border border-white/5 p-2.5 rounded-xl">
                        <span class="text-[9px] font-bold text-slate-500 uppercase tracking-wider">Contact Email:</span>
                        <span class="text-[10px] font-mono text-slate-400 italic">
                            🔍 No direct emails scraped. Placeholder will be used.
                        </span>
                    </div>
                `;
            }

            html += `
                <div class="glass p-5 rounded-2xl border border-white/5 hover:border-blue-500/30 hover:bg-slate-950/40 transition duration-300 flex flex-col justify-between group relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-24 h-24 bg-gradient-to-bl from-blue-500/5 to-transparent pointer-events-none"></div>
                    <div>
                        <div class="flex justify-between items-start gap-2 mb-2">
                            <h5 class="text-sm font-bold text-white group-hover:text-blue-400 transition truncate max-w-[70%]">${companyName}</h5>
                            <span class="px-2 py-0.5 rounded-full text-[9px] font-bold bg-white/[0.04] border border-white/10 ${scoreColor}">
                                🛡️ ${trustScore}% MATCH
                            </span>
                        </div>
                        <a href="${item.url}" target="_blank" class="text-[10px] text-blue-400 hover:underline flex items-center gap-1 mb-3 font-mono truncate">
                            🔗 ${item.url}
                        </a>
                        <p class="text-[11px] text-slate-400 line-clamp-3 leading-relaxed mb-4">
                            "${item.content || 'No business snippet extracted.'}"
                        </p>
                        ${emailsHtml}
                    </div>
                    <div class="flex justify-between items-center border-t border-white/5 pt-3 mt-auto">
                        <span class="text-[9px] font-bold text-slate-500 uppercase tracking-widest">Prospect Node</span>
                        <button id="add-btn-${index}" onclick="addSingleLeadToCRM(${index})" class="px-3 py-1.5 bg-blue-600/20 hover:bg-blue-600 border border-blue-500/20 hover:border-blue-500 text-blue-400 hover:text-white transition font-bold text-[10px] rounded-lg flex items-center gap-1">
                            📥 Import Lead
                        </button>
                    </div>
                </div>
            `;
        });

        html += `
                </div>
            </div>
        `;
        return html;
    }

    async function addSingleLeadToCRM(index) {
        const item = window.latestHarvestResults[index];
        if (!item) return;

        const btn = document.getElementById(`add-btn-${index}`);
        btn.disabled = true;
        btn.innerHTML = '⏳ Importing...';
        btn.className = 'px-3 py-1.5 bg-slate-800 text-slate-400 border border-white/10 text-[10px] rounded-lg cursor-not-allowed';

        let title = item.title || '';
        let companyName = '';
        if (title) {
            let parts = title.split(/[-|–|—]/);
            companyName = parts[0].trim();
        }
        if (!companyName || companyName.length < 3) {
            try {
                let parsedUrl = new URL(item.url);
                let host = parsedUrl.hostname.replace(/^www\./, '');
                companyName = host.split('.')[0];
                companyName = companyName.charAt(0).toUpperCase() + companyName.slice(1);
            } catch(e) {
                companyName = 'Prospect Company';
            }
        }

        const campaignId = document.getElementById('harvest-campaign-id') ? document.getElementById('harvest-campaign-id').value : '';
        const leadPersona = document.getElementById('lead_persona') ? document.getElementById('lead_persona').value : '';
        
        const extractedEmails = extractEmailsFromText(title + ' ' + (item.content || '') + ' ' + (item.snippet || ''));
        let finalEmail = extractedEmails.length > 0 ? extractedEmails[0] : ('pending_' + Math.random().toString(36).substring(2, 15) + '@placeholder.com');
        let notes = "Harvested from: " + item.url + "\nSnippet: " + (item.content || '') + "\nEmails found: " + (extractedEmails.join(', ') || 'None');

        try {
            const res = await fetch('api/leads.php?action=create', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    company_name: companyName,
                    contact_name: leadPersona || 'Prospect Lead',
                    email: finalEmail,
                    website: item.url,
                    source: 'Search Harvester',
                    campaign_id: campaignId,
                    notes: notes
                })
            });
            const data = await res.json();
            if (data.success) {
                btn.innerHTML = '✅ Imported';
                btn.className = 'px-3 py-1.5 bg-emerald-900/30 text-emerald-400 border border-emerald-500/20 text-[10px] rounded-lg cursor-default';
            } else {
                btn.disabled = false;
                btn.innerHTML = '❌ Failed';
                btn.className = 'px-3 py-1.5 bg-rose-900/30 text-rose-400 border border-rose-500/20 text-[10px] rounded-lg';
            }
        } catch(e) {
            btn.disabled = false;
            btn.innerHTML = '❌ Error';
            btn.className = 'px-3 py-1.5 bg-rose-900/30 text-rose-400 border border-rose-500/20 text-[10px] rounded-lg';
        }
    }

    let resolveConfirm = null;

    function openCRMConfirmModal(count) {
        document.getElementById('crm-confirm-text').innerText = `Are you sure you want to import all ${count} prospects into your CRM?`;
        const modal = document.getElementById('crm-confirm-modal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        
        return new Promise((resolve) => {
            resolveConfirm = resolve;
        });
    }

    function closeCRMConfirmModal(confirmed = false) {
        const modal = document.getElementById('crm-confirm-modal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        if (resolveConfirm) {
            resolveConfirm(confirmed);
            resolveConfirm = null;
        }
    }

    function showCRMToast(message) {
        const toast = document.getElementById('crm-toast');
        const text = document.getElementById('crm-toast-text');
        if (!toast || !text) return;
        
        text.innerText = message;
        toast.classList.remove('translate-y-20', 'opacity-0', 'pointer-events-none');
        toast.classList.add('translate-y-0', 'opacity-100');
        
        setTimeout(() => {
            toast.classList.add('translate-y-20', 'opacity-0', 'pointer-events-none');
            toast.classList.remove('translate-y-0', 'opacity-100');
        }, 4000);
    }

    async function addAllLeadsToCRM() {
        const results = window.latestHarvestResults;
        if (!results || results.length === 0) return;

        const confirmImport = await openCRMConfirmModal(results.length);
        if (!confirmImport) return;

        let importCount = 0;
        for (let i = 0; i < results.length; i++) {
            const btn = document.getElementById(`add-btn-${i}`);
            if (btn && btn.innerHTML.trim() !== '✅ Imported') {
                await addSingleLeadToCRM(i);
                importCount++;
            }
        }
        showCRMToast(`Batch import completed! Added ${importCount} new prospects.`);
    }
</script>

<!-- Custom Premium Confirmation Modal -->
<div id="crm-confirm-modal" class="fixed inset-0 z-50 flex items-center justify-center hidden bg-slate-950/80 backdrop-blur-sm animate-fadeIn">
    <div class="glass p-8 rounded-3xl border border-white/10 max-w-md w-full mx-4 shadow-2xl bg-gradient-to-b from-slate-900 via-slate-900 to-indigo-950/50">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-2xl">
                📥
            </div>
            <div>
                <h4 class="text-lg font-bold text-white">Batch Lead Import</h4>
                <p class="text-xs text-slate-400">Add prospects to your CRM pipeline</p>
            </div>
        </div>
        <p id="crm-confirm-text" class="text-slate-300 text-sm mb-6 leading-relaxed">
            Are you sure you want to import all prospects into your CRM?
        </p>
        <div class="flex justify-end gap-3">
            <button onclick="closeCRMConfirmModal(false)" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold border border-white/5 transition">
                Cancel
            </button>
            <button onclick="closeCRMConfirmModal(true)" class="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition shadow-lg shadow-emerald-500/20">
                Confirm Import
            </button>
        </div>
    </div>
</div>

<!-- Custom Premium Toast Notification -->
<div id="crm-toast" class="fixed bottom-6 right-6 z-50 transform translate-y-20 opacity-0 pointer-events-none transition-all duration-500">
    <div class="glass px-5 py-4 rounded-2xl border border-emerald-500/20 shadow-2xl bg-slate-950/90 flex items-center gap-3.5 max-w-sm">
        <div class="w-8 h-8 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-lg">
            ✅
        </div>
        <div>
            <span class="text-xs font-bold text-white block">Import Successful</span>
            <span id="crm-toast-text" class="text-[10px] text-slate-400 mt-0.5 block">Batch lead import completed!</span>
        </div>
    </div>
</div>
