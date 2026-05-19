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
                            <option value="searxng">SearXNG (Internal Rotation)</option>
                            <option value="tavily">Tavily AI (Optimized)</option>
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

                <div>
                    <label class="block text-slate-500 text-[10px] uppercase font-bold tracking-[0.2em] mb-2">Target Search Queries (Batch Mode, One per line)</label>
                    <textarea id="queries" rows="6" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-4 outline-none focus:ring-2 focus:ring-blue-500/50 transition font-mono text-sm leading-relaxed" placeholder='site:linkedin.com/in "saas founder" "austin"'></textarea>
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

        <!-- Proxy Fleet Management Card -->
        <div class="glass p-8 rounded-3xl border border-white/5">
            <h3 class="text-xl font-bold mb-4 flex items-center gap-2">
                <span class="text-blue-500">🛡️</span> Proxy Fleet Manager
            </h3>
            <p class="text-slate-400 text-xs mb-6">Rotate requests across private proxy networks to avoid rate-limiting and IP blocks during high-frequency scraping.</p>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="md:col-span-2">
                    <label class="block text-slate-500 text-[10px] uppercase font-bold tracking-[0.2em] mb-2">Proxy List (One per line: host:port:user:pass)</label>
                    <textarea id="proxy_list" rows="5" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-blue-500/50 transition font-mono text-sm" placeholder="185.190.140.23:8000:proxyuser:proxypassword"></textarea>
                    <div class="mt-4 flex justify-between items-center">
                        <span class="text-[11px] text-slate-500">Active proxies are automatically validated in background rotation cycles.</span>
                        <button onclick="saveProxies()" class="px-6 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 transition shadow-lg shadow-blue-500/20 text-sm font-bold text-white">Save Proxy Fleet</button>
                    </div>
                </div>
                
                <div class="space-y-4">
                    <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                        <div class="text-[10px] text-slate-500 uppercase tracking-widest font-bold mb-1">Rotation Status</div>
                        <div id="rotation-status" class="text-sm font-bold">Loading...</div>
                    </div>
                    <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                        <div class="text-[10px] text-slate-500 uppercase tracking-widest font-bold mb-1">Fleet Statistics</div>
                        <div class="flex flex-col gap-1 mt-1" id="proxy-health-score">
                            <span class="text-xs text-slate-400">Loading fleet stats...</span>
                        </div>
                    </div>
                    <button onclick="testProxies()" class="w-full py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 transition shadow-lg shadow-indigo-500/20 text-sm font-bold text-white flex items-center justify-center gap-2">
                        <span id="test-proxy-icon">🧪</span> Test Proxy Fleet
                    </button>
                    <div id="test-proxy-results" class="hidden text-xs space-y-1 max-h-40 overflow-y-auto"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Visual Dork Generator & Strategy Guide -->
    <div class="space-y-6 text-sm">
        <!-- Visual Dork Generator -->
        <div class="glass p-6 rounded-3xl border border-white/5">
            <h4 class="font-bold mb-4 flex items-center gap-2">
                <span class="text-blue-500">🔮</span> Smart Query Builder
            </h4>
            <p class="text-xs text-slate-400 mb-4">Construct high-intent search queries automatically:</p>
            <div class="space-y-4">
                <div>
                    <label class="block text-slate-500 text-[10px] uppercase font-bold tracking-[0.2em] mb-1.5">Target Keyword</label>
                    <input type="text" id="dork-keyword" placeholder="e.g. SaaS" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 text-white text-sm transition">
                </div>
                <div>
                    <label class="block text-slate-500 text-[10px] uppercase font-bold tracking-[0.2em] mb-1.5">Target Location</label>
                    <input type="text" id="dork-location" placeholder="e.g. Austin" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 text-white text-sm transition">
                </div>
                <div>
                    <label class="block text-slate-500 text-[10px] uppercase font-bold tracking-[0.2em] mb-1.5">Target Persona Role</label>
                    <select id="dork-persona" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 text-white text-sm transition">
                        <option value="decision_maker">Decision Makers (CEO/Founder/Owner)</option>
                        <option value="social_platforms">Social Channels (LinkedIn/FB/X/IG/TikTok)</option>
                        <option value="marketing_lead">Marketing & Sales Leaders</option>
                        <option value="local_service">Local Service Businesses</option>
                        <option value="growth_companies">Fast-Growing Companies</option>
                        <option value="tech_companies">Tech-Forward Businesses</option>
                        <option value="full_sweep">Full Sweep (All Dorks)</option>
                    </select>
                </div>
                <div class="flex items-center justify-between">
                    <label class="text-xs text-slate-400">Expand locations (regional cities)</label>
                    <input type="checkbox" id="dork-expand" class="rounded bg-slate-900 border-white/10 focus:ring-blue-500/50">
                </div>
            <button onclick="generateDorks()" class="w-full py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 transition shadow-lg shadow-blue-500/20 font-bold text-sm text-white">Build Search Queries</button>
            </div>
            
            <div id="dork-results" class="mt-6 space-y-2 hidden">
                <label class="block text-slate-500 text-[10px] uppercase font-bold tracking-[0.2em] mb-2">Generated Queries</label>
                <div id="dork-list" class="space-y-2 max-h-60 overflow-y-auto pr-1"></div>
            </div>
        </div>

        <!-- Strategy Guide -->
        <div class="glass p-6 rounded-3xl border border-white/5">
            <h4 class="font-bold mb-4 flex items-center gap-2">
                <span class="text-amber-500">✨</span> Strategy Guide
            </h4>
            <div class="space-y-4">
                <div class="p-3 rounded-xl bg-white/5 border border-white/5">
                    <p class="text-xs font-bold text-blue-400 mb-1">Advanced Searching</p>
                    <p class="text-slate-500 text-[11px] leading-relaxed">Use search parameters to narrow down and target direct professional profiles.</p>
                </div>
                <div class="p-3 rounded-xl bg-white/5 border border-white/5">
                    <p class="text-xs font-bold text-blue-400 mb-1">Internal Rotation</p>
                    <p class="text-slate-500 text-[11px] leading-relaxed">Smarketer Pro handles failover across multiple search nodes automatically for reliability.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    let activePollInterval = null;

    async function loadProxySettings() {
        // Get proxy stats
        try {
            const resStats = await fetch('api/mass_tools.php?action=stats');
            const dataStats = await resStats.json();
            if (dataStats.success && dataStats.data) {
                const stats = dataStats.data;
                let summary = '';
                let totalActive = 0;
                for (const [status, count] of Object.entries(stats)) {
                    const statusLower = status.toLowerCase();
                    const color = statusLower === 'active' ? 'text-emerald-400' : 'text-slate-400';
                    if (statusLower === 'active') totalActive = parseInt(count);
                    summary += `<div class="flex justify-between text-xs"><span class="capitalize text-slate-500">${status}:</span> <span class="font-bold ${color}">${count}</span></div>`;
                }
                if (summary) {
                    document.getElementById('proxy-health-score').innerHTML = summary;
                }
                
                // Update rotation status dynamically
                const rotDiv = document.getElementById('rotation-status');
                if (totalActive > 0) {
                    rotDiv.className = 'text-emerald-400 text-sm font-bold';
                    rotDiv.innerHTML = `ACTIVE & SAFE <span class="text-emerald-300/60 text-[10px]">(Rotating ${totalActive} Proxies)</span>`;
                } else {
                    rotDiv.className = 'text-amber-400 text-sm font-bold';
                    rotDiv.innerHTML = `DIRECT <span class="text-amber-300/60 text-[10px]">(NO PROXIES CONFIGURED)</span>`;
                }
            } else {
                const rotDiv = document.getElementById('rotation-status');
                rotDiv.className = 'text-amber-400 text-sm font-bold';
                rotDiv.innerHTML = `DIRECT <span class="text-amber-300/60 text-[10px]">(NO PROXIES CONFIGURED)</span>`;
            }
        } catch(e) {
            const rotDiv = document.getElementById('rotation-status');
            rotDiv.className = 'text-rose-400 text-sm font-bold';
            rotDiv.textContent = 'ERROR';
        }

        // Load saved proxies from settings
        try {
            const resSettings = await fetch('api/settings.php');
            const dataSettings = await resSettings.json();
            if (dataSettings.success && dataSettings.data.proxies) {
                document.getElementById('proxy_list').value = dataSettings.data.proxies;
            }
        } catch(e) {}

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

    async function saveProxies() {
        const list = document.getElementById('proxy_list').value;
        const res = await fetch('api/mass_tools.php?action=add_proxies', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ proxies: list })
        });
        const data = await res.json();
        if (data.success) {
            alert(`Proxy fleet updated successfully. Loaded ${data.count} proxies.`);
        } else {
            alert('Failed to update proxy fleet.');
        }
        loadProxySettings();
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
            const list = document.getElementById('dork-list');
            list.innerHTML = data.data.map(d => `
                <div class="p-3 bg-white/[0.02] border border-white/5 rounded-xl flex flex-col gap-2">
                    <span class="text-[9px] font-bold text-slate-500 uppercase tracking-wider">${d.type} - ${d.location || 'Global'}</span>
                    <code class="text-xs text-slate-300 font-mono select-all break-all">${d.query}</code>
                    <div class="flex justify-end gap-2">
                        <button onclick="appendQuery('${d.query.replace(/'/g, "\\'")}')" class="text-[10px] text-blue-400 font-bold hover:text-blue-300 transition">Use in Harvester</button>
                    </div>
                </div>
            `).join('');
            document.getElementById('dork-results').classList.remove('hidden');
        } else {
            alert('Failed to generate dorks.');
        }
    }

    function appendQuery(q) {
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
        const queries = document.getElementById('queries').value;
        const provider = document.getElementById('search_provider').value;
        const isBackground = document.getElementById('background_mode').checked;
        const campaignId = document.getElementById('harvest-campaign-id') ? document.getElementById('harvest-campaign-id').value : '';
        const leadPersona = document.getElementById('lead_persona') ? document.getElementById('lead_persona').value : '';
        
        if (!queries.trim()) {
            alert('Please enter at least one query.');
            return;
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
                    statusDiv.innerHTML = `<div class="text-emerald-400 font-bold">Success: Found ${data.data.length} results.</div><pre class="text-left text-xs mt-4 overflow-auto max-h-64 text-slate-300">${JSON.stringify(data.data, null, 2)}</pre>`;
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
                        
                        statusDiv.innerHTML = `<div class="text-emerald-400 font-bold">Background Job Completed! Successfully found ${allResults.length} leads.</div>
                        <pre class="text-left text-xs mt-4 overflow-auto max-h-64 text-slate-300">${JSON.stringify(allResults, null, 2)}</pre>`;
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

    async function testProxies() {
        const icon = document.getElementById('test-proxy-icon');
        const resultsDiv = document.getElementById('test-proxy-results');
        icon.textContent = '⏳';
        resultsDiv.classList.remove('hidden');
        resultsDiv.innerHTML = '<div class="text-blue-400 animate-pulse">Testing proxy fleet connectivity...</div>';

        try {
            const res = await fetch('api/mass_tools.php?action=test_proxies', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({})
            });
            const data = await res.json();
            icon.textContent = '🧪';

            if (data.success) {
                if (data.tested === 0) {
                    resultsDiv.innerHTML = '<div class="text-amber-400">No active proxies to test. Add proxies above first.</div>';
                } else {
                    let html = `<div class="text-slate-300 font-bold mb-1">Tested: ${data.tested} | Passed: <span class="text-emerald-400">${data.passed}</span> | Failed: <span class="text-rose-400">${data.failed}</span></div>`;
                    data.results.forEach(r => {
                        const color = r.ok ? 'text-emerald-400' : 'text-rose-400';
                        const status = r.ok ? '✅' : '❌';
                        html += `<div class="flex justify-between items-center p-1.5 rounded bg-white/[0.02] border border-white/5">
                            <span class="font-mono text-[11px] text-slate-400 truncate max-w-[60%]">${r.proxy}</span>
                            <span class="${color} text-[10px] font-bold">${status} ${r.http_code} (${r.latency_ms}ms)</span>
                        </div>`;
                    });
                    resultsDiv.innerHTML = html;
                }
                loadProxySettings();
            } else {
                resultsDiv.innerHTML = '<div class="text-rose-400">Test failed.</div>';
            }
        } catch (e) {
            icon.textContent = '🧪';
            resultsDiv.innerHTML = '<div class="text-rose-400">Network error during proxy test.</div>';
        }
    }
</script>
