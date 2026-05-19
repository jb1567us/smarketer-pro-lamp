<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smarketer Pro LAMP - Mission Control</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #0f172a; color: #f8fafc; }
        .glass { background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.1); }
    </style>
</head>
<body class="bg-[#0b0f1a] text-slate-200 font-sans selection:bg-blue-500/30">
    <!-- Sidebar Navigation -->
    <aside class="fixed top-0 left-0 h-full w-20 lg:w-64 glass border-r border-white/5 z-50 flex flex-col items-center lg:items-start p-4 transition-all duration-300">
        <div class="mb-10 px-2 flex items-center gap-3">
            <div class="w-10 h-10 bg-gradient-to-tr from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg shadow-blue-500/20">
                <span class="text-xl">🚀</span>
            </div>
            <h1 class="text-xl font-bold hidden lg:block bg-gradient-to-r from-white to-slate-400 bg-clip-text text-transparent">Smarketer <span class="text-blue-500">Pro</span></h1>
        </div>

        <nav class="flex-1 w-full space-y-2">
            <!-- Pipeline -->
            <a href="index.php?tab=leads" id="tab-leads-btn" class="tab-btn w-full flex items-center gap-4 px-4 py-3 rounded-xl transition group text-slate-400 hover:bg-white/5">
                <span class="text-xl group-hover:scale-110 transition">📊</span>
                <span class="hidden lg:block font-medium">Deals / Pipeline</span>
            </a>
            <!-- Campaigns -->
            <a href="index.php?tab=campaigns" id="tab-campaigns-btn" class="tab-btn w-full flex items-center gap-4 px-4 py-3 rounded-xl transition group text-slate-400 hover:bg-white/5">
                <span class="text-xl group-hover:scale-110 transition">🎯</span>
                <span class="hidden lg:block font-medium">Outreach Campaigns</span>
            </a>
            <!-- Agent Lab -->
            <a href="index.php?tab=agent" id="tab-agent-btn" class="tab-btn w-full flex items-center gap-4 px-4 py-3 rounded-xl transition group text-slate-400 hover:bg-white/5">
                <span class="text-xl group-hover:scale-110 transition">🧪</span>
                <span class="hidden lg:block font-medium">AI Copywriting Lab</span>
            </a>
            <!-- Influencer Scout -->
            <a href="index.php?tab=influencer" id="tab-influencer-btn" class="tab-btn w-full flex items-center gap-4 px-4 py-3 rounded-xl transition group text-slate-400 hover:bg-white/5">
                <span class="text-xl group-hover:scale-110 transition">🔍</span>
                <span class="hidden lg:block font-medium">Find Influencers</span>
            </a>
            <!-- Mass Harvester -->
            <a href="index.php?tab=mass" id="tab-mass-btn" class="tab-btn w-full flex items-center gap-4 px-4 py-3 rounded-xl transition group text-slate-400 hover:bg-white/5">
                <span class="text-xl group-hover:scale-110 transition">🕸️</span>
                <span class="hidden lg:block font-medium">Find Prospects</span>
            </a>
            <!-- Settings -->
            <a href="index.php?tab=settings" id="tab-settings-btn" class="tab-btn w-full flex items-center gap-4 px-4 py-3 rounded-xl transition group text-slate-400 hover:bg-white/5">
                <span class="text-xl group-hover:scale-110 transition">⚙️</span>
                <span class="hidden lg:block font-medium">Configuration</span>
            </a>
        </nav>

        <div class="w-full pt-4 border-t border-white/5">
            <div id="safety-indicator" class="hidden lg:flex items-center gap-2 px-3 py-2 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-400 text-[10px] font-bold uppercase tracking-wider">
                <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                Safety Active
            </div>
        </div>
    </aside>

    <!-- Main Content Area -->
    <main class="ml-20 lg:ml-64 p-4 lg:p-8 transition-all duration-300">
        <!-- Top Control Bar -->
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
            <div>
                <h2 class="text-2xl font-bold">Revenue Intelligence</h2>
                <p class="text-slate-400 text-sm">Real-time B2B outreach and intent monitoring</p>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="showImportLeadsModal()" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 transition border border-white/5 text-sm font-medium">Import Leads</button>
                <button onclick="showNewCampaignModal()" class="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 transition shadow-lg shadow-blue-500/20 text-sm font-bold">New Campaign</button>
            </div>
        </header>

        <!-- KPI Ribbon -->
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <div class="glass p-4 rounded-2xl border-l-4 border-blue-500">
                <div class="text-slate-500 text-[10px] uppercase font-bold tracking-widest mb-1">Entry Pool</div>
                <div id="stat-total_leads" class="text-2xl font-bold">0</div>
                <div class="text-[10px] text-blue-500 mt-1 font-medium">Total Leads</div>
            </div>
            <div class="glass p-4 rounded-2xl border-l-4 border-purple-500">
                <div class="text-slate-500 text-[10px] uppercase font-bold tracking-widest mb-1">High Intent</div>
                <div id="stat-qualified" class="text-2xl font-bold">0</div>
                <div id="conv-qualified" class="text-[10px] text-purple-500 mt-1 font-medium">Qualified</div>
            </div>
            <div class="glass p-4 rounded-2xl border-l-4 border-emerald-500">
                <div class="text-slate-500 text-[10px] uppercase font-bold tracking-widest mb-1">Active Pipeline</div>
                <div id="stat-contacted" class="text-2xl font-bold">0</div>
                <div id="conv-contacted" class="text-[10px] text-emerald-500 mt-1 font-medium">Contacted</div>
            </div>
            <div class="glass p-4 rounded-2xl border-l-4 border-amber-500">
                <div class="text-slate-500 text-[10px] uppercase font-bold tracking-widest mb-1">Conversion</div>
                <div id="stat-converted" class="text-2xl font-bold">0</div>
                <div id="conv-converted" class="text-[10px] text-amber-500 mt-1 font-medium">Wins</div>
            </div>
            <div class="glass p-4 rounded-2xl border-l-4 border-rose-500">
                <div class="text-slate-500 text-[10px] uppercase font-bold tracking-widest mb-1">Node Health</div>
                <div id="supervisor-status" class="text-lg font-bold text-emerald-400">Optimal</div>
                <div id="supervisor-last-check" class="text-[9px] text-slate-500 mt-1 truncate">All systems nominal</div>
            </div>
        </div>

        <!-- Dynamic Content -->
        <div id="leads-tab" class="tab-content grid grid-cols-1 xl:grid-cols-3 gap-8">
            <div class="xl:col-span-2 space-y-6">
                <!-- Advanced Search & Filter -->
                <div class="glass p-4 rounded-2xl flex flex-col md:flex-row gap-4">
                    <div class="relative flex-1">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500">🔍</span>
                        <input type="text" id="lead-search" onkeyup="searchLeads()" placeholder="Search deals, company, or intent signals..." class="w-full bg-slate-900/50 border border-white/5 rounded-xl pl-10 pr-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition">
                    </div>
                    <div class="flex gap-2">
                        <select class="bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 text-sm outline-none">
                            <option>All Scores</option>
                            <option>High Score (>80)</option>
                            <option>Recent Activity</option>
                        </select>
                        <button onclick="fetchLeads()" class="p-2.5 rounded-xl bg-white/5 hover:bg-white/10 transition">🔄</button>
                    </div>
                </div>

                <!-- Leads Table -->
                <div class="glass rounded-3xl overflow-hidden border border-white/5">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-white/[0.02] text-slate-500 text-[10px] uppercase tracking-[0.2em] font-bold">
                                <th class="px-6 py-5">Organization & Contact</th>
                                <th class="px-6 py-5">Pipeline Phase</th>
                                <th class="px-6 py-5">Intent Score</th>
                                <th class="px-6 py-5">Quick Actions</th>
                                <th class="px-6 py-5">Edit</th>
                            </tr>
                        </thead>
                        <tbody id="leads-body" class="divide-y divide-white/5">
                            <!-- Populated via JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Side Intelligence Panel -->
            <aside class="space-y-6">
                <div class="glass p-6 rounded-3xl border border-white/5">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="font-bold">Active Signal Queue</h3>
                        <span class="px-2 py-1 rounded bg-blue-500/10 text-blue-400 text-[10px] font-bold">Live</span>
                    </div>
                    <div id="task-list" class="space-y-4 text-sm text-slate-500 italic text-center py-10">
                        <div class="flex flex-col items-center gap-3">
                            <span class="text-4xl grayscale opacity-20">📡</span>
                            <p>Monitoring for intent signals...</p>
                        </div>
                    </div>
                </div>

                <div class="bg-gradient-to-br from-indigo-600/20 to-blue-600/20 p-6 rounded-3xl border border-blue-500/20 relative overflow-hidden group">
                    <div class="absolute -right-4 -bottom-4 text-8xl opacity-10 group-hover:scale-110 transition-transform">🤖</div>
                    <h3 class="font-bold text-white mb-2">Agent lab Insights</h3>
                    <p class="text-slate-400 text-xs mb-4 leading-relaxed">Let your Extraction Expert and Intent Analyst prioritize your morning for you.</p>
                    <button onclick="location.href='agent_lab.php'" class="w-full py-2 rounded-xl bg-blue-600 text-white text-xs font-bold hover:bg-blue-500 transition">Open Lab</button>
                </div>
            </aside>
        </div>

        <!-- Other tabs... -->
        <div id="campaigns-tab" class="tab-content hidden grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6"></div>
        
        <div id="agent-tab" class="tab-content hidden">
            <?php include 'agent_lab_content.php'; ?>
        </div>

        <div id="influencer-tab" class="tab-content hidden">
            <?php include 'influencer_scout_content.php'; ?>
        </div>

        <div id="mass-tab" class="tab-content hidden">
            <?php include 'mass_tools_content.php'; ?>
        </div>

        <div id="settings-tab" class="tab-content hidden glass p-8 rounded-3xl max-w-5xl mx-auto border border-white/5">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h2 class="text-2xl font-bold bg-gradient-to-r from-white to-slate-400 bg-clip-text text-transparent">Mission Control Settings</h2>
                    <p class="text-slate-500 text-sm">Configure your intelligence nodes and API orchestration</p>
                </div>
                <button onclick="saveSettings()" class="px-6 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 transition shadow-lg shadow-blue-500/20 text-sm font-bold">Save Changes</button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- AI Configuration -->
                <div class="space-y-6">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-blue-500">🤖</span>
                        <h3 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400">AI Logic Nodes</h3>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Active LLM Provider</label>
                            <select id="setting-active_llm_provider" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm appearance-none">
                                <option value="gemini">Google Gemini (Default)</option>
                                <option value="groq">Groq (Speed)</option>
                                <option value="openrouter">OpenRouter (Fallback)</option>
                                <option value="ollama">Ollama (Local)</option>
                            </select>
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-1.5">
                                <label class="block text-[11px] text-slate-500 uppercase font-bold tracking-wider">Gemini API Key (Primary)</label>
                                <a href="https://aistudio.google.com/" target="_blank" class="text-[10px] text-blue-400 hover:text-blue-300 transition flex items-center gap-0.5 font-medium">
                                    Get Key <span class="text-[8px]">↗</span>
                                </a>
                            </div>
                            <input type="password" id="setting-gemini_api_key" placeholder="••••••••••••••••" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm">
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-1.5">
                                <label class="block text-[11px] text-slate-500 uppercase font-bold tracking-wider">Groq API Key (Speed)</label>
                                <a href="https://console.groq.com/keys" target="_blank" class="text-[10px] text-blue-400 hover:text-blue-300 transition flex items-center gap-0.5 font-medium">
                                    Get Key <span class="text-[8px]">↗</span>
                                </a>
                            </div>
                            <input type="password" id="setting-groq_api_key" placeholder="••••••••••••••••" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm">
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-1.5">
                                <label class="block text-[11px] text-slate-500 uppercase font-bold tracking-wider">OpenRouter API (Fallback)</label>
                                <a href="https://openrouter.ai/keys" target="_blank" class="text-[10px] text-blue-400 hover:text-blue-300 transition flex items-center gap-0.5 font-medium">
                                    Get Key <span class="text-[8px]">↗</span>
                                </a>
                            </div>
                            <input type="password" id="setting-openrouter_api_key" placeholder="••••••••••••••••" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm">
                        </div>
                        <div>
                            <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Ollama Local URL</label>
                            <input type="text" id="setting-ollama_url" placeholder="http://localhost:11434" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm">
                        </div>
                    </div>
                </div>

                <!-- Scraping & OSINT -->
                <div class="space-y-6">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-indigo-500">🔍</span>
                        <h3 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400">Scraping & OSINT</h3>
                    </div>
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Active Search Provider</label>
                                <select id="setting-active_search_provider" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm appearance-none">
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
                                <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Fallback Search Provider</label>
                                <select id="setting-fallback_search_provider" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm appearance-none">
                                    <option value="ddg">DuckDuckGo (Free / Mass)</option>
                                    <option value="searxng">SearXNG (Internal Rotation)</option>
                                    <option value="tavily">Tavily AI (Optimized)</option>
                                    <option value="exa">Exa Neural (Semantic)</option>
                                    <option value="firecrawl">Firecrawl Search (Semantic)</option>
                                    <option value="scrapingant">ScrapingAnt (Proxied Scraper)</option>
                                    <option value="serper">Serper Google API (High Accuracy)</option>
                                    <option value="gemini">Gemini Search (High Intent)</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Failover Error Threshold (Attempts)</label>
                            <input type="number" id="setting-failover_threshold" min="1" max="10" placeholder="3" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm">
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <div class="flex justify-between items-center mb-1.5">
                                    <label class="block text-[11px] text-slate-500 uppercase font-bold tracking-wider">ScrapingAnt API Key</label>
                                    <a href="https://scrapingant.com/" target="_blank" class="text-[10px] text-blue-400 hover:text-blue-300 transition flex items-center gap-0.5 font-medium">
                                        Get Key <span class="text-[8px]">↗</span>
                                    </a>
                                </div>
                                <input type="password" id="setting-scrapingant_api_key" placeholder="••••••••••••••••" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm">
                            </div>
                            <div>
                                <div class="flex justify-between items-center mb-1.5">
                                    <label class="block text-[11px] text-slate-500 uppercase font-bold tracking-wider">ScrapingAnt Backup Key</label>
                                    <a href="https://scrapingant.com/" target="_blank" class="text-[10px] text-blue-400 hover:text-blue-300 transition flex items-center gap-0.5 font-medium">
                                        Get Key <span class="text-[8px]">↗</span>
                                    </a>
                                </div>
                                <input type="password" id="setting-scrapingant_api_key_backup" placeholder="•••••••••••••••• (Backup)" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <div class="flex justify-between items-center mb-1.5">
                                    <label class="block text-[11px] text-slate-500 uppercase font-bold tracking-wider">Firecrawl API Key</label>
                                    <a href="https://firecrawl.dev/" target="_blank" class="text-[10px] text-blue-400 hover:text-blue-300 transition flex items-center gap-0.5 font-medium">
                                        Get Key <span class="text-[8px]">↗</span>
                                    </a>
                                </div>
                                <input type="password" id="setting-firecrawl_api_key" placeholder="••••••••••••••••" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm">
                            </div>
                            <div>
                                <div class="flex justify-between items-center mb-1.5">
                                    <label class="block text-[11px] text-slate-500 uppercase font-bold tracking-wider">Firecrawl Backup Key</label>
                                    <a href="https://firecrawl.dev/" target="_blank" class="text-[10px] text-blue-400 hover:text-blue-300 transition flex items-center gap-0.5 font-medium">
                                        Get Key <span class="text-[8px]">↗</span>
                                    </a>
                                </div>
                                <input type="password" id="setting-firecrawl_api_key_backup" placeholder="•••••••••••••••• (Backup)" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm">
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-1.5">
                                <label class="block text-[11px] text-slate-500 uppercase font-bold tracking-wider">Serper API Key</label>
                                <a href="https://serper.dev/" target="_blank" class="text-[10px] text-blue-400 hover:text-blue-300 transition flex items-center gap-0.5 font-medium">
                                    Get Key <span class="text-[8px]">↗</span>
                                </a>
                            </div>
                            <input type="password" id="setting-serper_api_key" placeholder="••••••••••••••••" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm">
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-1.5">
                                <label class="block text-[11px] text-slate-500 uppercase font-bold tracking-wider">Exa Search Key</label>
                                <a href="https://dashboard.exa.ai/" target="_blank" class="text-[10px] text-blue-400 hover:text-blue-300 transition flex items-center gap-0.5 font-medium">
                                    Get Key <span class="text-[8px]">↗</span>
                                </a>
                            </div>
                            <input type="password" id="setting-exa_api_key" placeholder="••••••••••••••••" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm">
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-1.5">
                                <label class="block text-[11px] text-slate-500 uppercase font-bold tracking-wider">Tavily API Key</label>
                                <a href="https://dashboard.tavily.com/" target="_blank" class="text-[10px] text-blue-400 hover:text-blue-300 transition flex items-center gap-0.5 font-medium">
                                    Get Key <span class="text-[8px]">↗</span>
                                </a>
                            </div>
                            <input type="password" id="setting-tavily_api_key" placeholder="••••••••••••••••" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm">
                        </div>
                        <div>
                            <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">SearXNG URL</label>
                            <input type="text" id="setting-searxng_url" placeholder="http://localhost:8080/search" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm">
                        </div>
                    </div>
                </div>

                <!-- Connectivity -->
                <div class="space-y-6">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-emerald-500">🔌</span>
                        <h3 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400">Infrastructure & Email</h3>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Active Email Provider</label>
                            <select id="setting-active_email_provider" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm appearance-none">
                                <option value="smtp">Standard SMTP</option>
                                <option value="brevo">Brevo (Sendinblue)</option>
                                <option value="sendgrid">Twilio SendGrid</option>
                                <option value="mailgun">Mailgun</option>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">SMTP Host</label>
                                <input type="text" id="setting-smtp_host" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-sm">
                            </div>
                            <div>
                                <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">SMTP Port</label>
                                <input type="text" id="setting-smtp_port" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-sm">
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-1.5">
                                <label class="block text-[11px] text-slate-500 uppercase font-bold tracking-wider">SMTP User / API Key</label>
                                <div class="flex gap-2 text-[10px] text-blue-400 font-medium">
                                    <a href="https://dashboard.brevo.com/" target="_blank" class="hover:text-blue-300 transition flex items-center gap-0.5">Brevo <span class="text-[7px]">↗</span></a>
                                    <span class="text-slate-600">|</span>
                                    <a href="https://app.sendgrid.com/settings/api_keys" target="_blank" class="hover:text-blue-300 transition flex items-center gap-0.5">SendGrid <span class="text-[7px]">↗</span></a>
                                    <span class="text-slate-600">|</span>
                                    <a href="https://app.mailgun.com/" target="_blank" class="hover:text-blue-300 transition flex items-center gap-0.5">Mailgun <span class="text-[7px]">↗</span></a>
                                </div>
                            </div>
                            <input type="password" id="setting-smtp_user" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-sm">
                        </div>
                        <div>
                            <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">SMTP Password</label>
                            <input type="password" id="setting-smtp_pass" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-sm">
                        </div>
                        <div>
                            <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Bright Data Proxy URL</label>
                            <input type="text" id="setting-bright_data_proxy_url" placeholder="http://user:pass@brd.com:22225" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm">
                        </div>
                    </div>
                </div>

                <!-- Global Logic -->
                <div class="space-y-6">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-amber-500">🛡️</span>
                        <h3 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400">Operational Integrity</h3>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Operational Mode</label>
                            <select id="setting-operational_mode" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm appearance-none">
                                <option value="Safety">Safety First (Simulated)</option>
                                <option value="Production">Full Production (Live Outreach)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Scraper Fallback Order</label>
                            <input type="text" id="setting-scraper_priority" placeholder="firecrawl,exa,tavily,searxng" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="modal-container" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md hidden z-[100] flex items-center justify-center p-4"></div>
    <div id="drawer-container" class="fixed top-0 right-0 h-full w-full max-w-lg bg-slate-900/95 backdrop-blur-xl border-l border-white/10 shadow-2xl z-[90] transform translate-x-full transition-transform duration-300 ease-out flex flex-col"></div>

    <script src="assets/js/dashboard.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Parse initial tab from query parameter
            const urlParams = new URLSearchParams(window.location.search);
            let tab = urlParams.get('tab');
            if (!tab || !['leads', 'campaigns', 'settings', 'agent', 'influencer', 'mass'].includes(tab)) {
                tab = 'leads';
            }
            showTab(tab);

            // Intercept sidebar clicks for seamless SPA tab switching on the dashboard
            document.querySelectorAll('a.tab-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const href = btn.getAttribute('href');
                    if (href && href.startsWith('index.php?tab=')) {
                        e.preventDefault();
                        const tabName = href.split('?tab=')[1];
                        showTab(tabName);
                        history.pushState(null, '', 'index.php?tab=' + tabName);
                    }
                });
            });
        });
    </script>
</body>
</html>
